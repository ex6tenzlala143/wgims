<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\Requisition;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'creator', 'items.warehouse', 'items.dispatchItems.item.warehouse'])
            ->withSum('items as total_requested', 'quantity_requested')
            ->withSum('items as total_issued', 'quantity_issued');

        // A requisition may draw items from multiple warehouses, so scoping is
        // done at the line-item / dispatch level. A dispatch's warehouse is
        // derived from its exact stock record (dispatch item -> items.warehouse_id).
        $ids      = $this->getUserWarehouseIds($user);
        $filterWh = $request->warehouse_id ? (int) $request->warehouse_id : null;

        if ($ids !== null) {
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($ids) {
                    $q->whereHas('items', fn ($i) => $i->whereIn('warehouse_id', $ids))
                      ->orWhereHas('items.dispatchItems.item', fn ($i) => $i->whereIn('warehouse_id', $ids))
                      ->orWhereIn('requisitions.warehouse_id', $ids);
                });
            }
        } elseif ($filterWh) {
            $query->where(function ($q) use ($filterWh) {
                $q->whereHas('items', fn ($i) => $i->where('warehouse_id', $filterWh))
                  ->orWhereHas('items.dispatchItems.item', fn ($i) => $i->where('warehouse_id', $filterWh))
                  ->orWhere('requisitions.warehouse_id', $filterWh);
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('ris_number', 'like', "%{$search}%")
                  ->orWhere('dr_number', 'like', "%{$search}%")
                  ->orWhereHas('items', fn ($i) => $i->where('dr_number', 'like', "%{$search}%"))
                  ->orWhereHas('items.dispatchItems', fn ($d) => $d->where('dr_number', 'like', "%{$search}%"))
                  ->orWhere('office', 'like', "%{$search}%")
                  ->orWhere('purpose', 'like', "%{$search}%");
            });
        }
        $requisitions = $query->orderByDesc('date_requested')->paginate(20)->withQueryString();

        return view('requisitions.index', compact('requisitions'));
    }

    public function create()
    {
        return view('requisitions.create');
    }

    /**
     * API: description-level list of item names available to request, sourced
     * from the Item Categories catalog (item_catalog_items). Each entry shows
     * the catalog item's name + account code, plus — when any active stock
     * record with that description exists — the aggregated available quantity
     * and unit. No warehouse is pinned here; the exact warehouse and stock
     * record are chosen by the dispatcher when issuing.
     * GET /api/requisition-description-items
     */
    public function getAvailableItems(Request $request)
    {
        $catalogItems = ItemCatalogItem::with('category')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($catalogItems->isEmpty()) {
            return response()->json([]);
        }

        // Aggregate availability per item name (across all warehouses) purely
        // for display — it never fixes which record will be issued later.
        $stock = Item::where('is_active', true)
            ->where('quantity', '>', 0)
            ->get(['description', 'unit', 'quantity'])
            ->groupBy(fn ($i) => mb_strtolower(trim($i->description)));

        return response()->json($catalogItems->map(function ($catalog) use ($stock) {
            $records = $stock->get(mb_strtolower(trim($catalog->name)), collect());

            return [
                'id'           => $catalog->id,
                'description'  => $catalog->name,
                'name'         => $catalog->name,
                'account_code' => $catalog->account_code ?: $catalog->category?->account_code,
                'category'     => $catalog->category?->key,
                'unit'         => $records->first()?->unit ?? '',
                'total_stock'  => (float) $records->sum('quantity'),
                'record_count' => $records->count(),
            ];
        })->values());
    }

    /**
     * API: return items with stock > 0 for a given warehouse.
     * Used by the RIS dispatch/approve form to filter the item dropdowns.
     * GET /api/requisition-items?warehouse_id=X
     */
    public function getItemsByWarehouse(Request $request)
    {
        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id']);

        $user        = Auth::user();
        $warehouseId = (int) $request->warehouse_id;

        // Non-admin users can only query warehouses they are assigned to
        if (! $user->hasAdminAccess() && ! $user->hasWarehouse($warehouseId)) {
            abort(403);
        }

        // Same-description items (e.g. one at ₱700 and one at ₱800) are distinct
        // stock records. Sort deterministically by description, then unit cost,
        // so each record always appears in the same stable position in the form.
        $items = Item::where('warehouse_id', $request->warehouse_id)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotNull('stock_number')
            ->orderBy('description')
            ->orderBy('unit_cost')
            ->get(['id', 'description', 'unit', 'quantity', 'stock_number',
                   'expiration_date', 'category', 'unit_cost', 'engas_unit_cost']);

        return response()->json($items->map(fn ($i) => [
            'id'             => $i->id,
            'description'    => $i->description,
            'unit'           => $i->unit,
            'quantity'       => $i->quantity,
            'stock_number'   => $i->stock_number,
            'expiry_date'    => $i->expiration_date?->format('Y-m-d'),
            'category'       => $i->category,
            'unit_cost'      => $i->unit_cost,
            'engas_unit_cost' => $i->engas_unit_cost,
        ]));
    }

    public function store(Request $request)
    {
        $request->validate([
            'purpose' => 'required|string',
            'date_requested' => 'required|date',
            'province' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'required|exists:item_catalog_items,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        $user = Auth::user();

        // A requisition line is description-level: it references an Item
        // Categories catalog item. The warehouse and exact stock record are
        // chosen by the dispatcher at dispatch time — never at creation.
        $catalogItems = ItemCatalogItem::with('category')
            ->whereIn('id', collect($request->items)->pluck('catalog_item_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $lineErrors = [];
        foreach ($request->items as $idx => $line) {
            $catalog = $catalogItems->get((int) $line['catalog_item_id']);
            if (! $catalog || ! $catalog->is_active) {
                $lineErrors["items.{$idx}.catalog_item_id"] =
                    'The selected item is no longer available.';
            }
        }

        if (! empty($lineErrors)) {
            return back()->withErrors($lineErrors)->withInput();
        }

        DB::transaction(function () use ($request, $user) {
            $catalogItems = ItemCatalogItem::with('category')
                ->whereIn('id', collect($request->items)->pluck('catalog_item_id')->filter()->unique())
                ->get()
                ->keyBy('id');

            // Representative stock record per catalog item — used only for
            // display (unit, cost); it never pins the warehouse or record.
            $representatives = [];
            foreach ($catalogItems as $catalog) {
                $representatives[$catalog->id] = Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first();
            }

            $ris = Requisition::create([
                'ris_number' => Requisition::generateRisNumber(),
                'dr_number' => null, // DR number is now stored per dispatch
                'warehouse_id' => null, // warehouse is chosen per dispatch
                'created_by' => $user->id,
                'entity_name' => $request->entity_name,
                'fund_cluster' => $request->fund_cluster,
                'office' => $request->office,
                'division' => $request->division,
                'province' => $request->province,
                'municipality' => $request->municipality,
                'responsibility_center_code' => $request->responsibility_center_code,
                'purpose' => $request->purpose,
                'date_requested' => $request->date_requested,
                'requested_by_name' => $request->requested_by_name,
                'requested_by_designation' => $request->requested_by_designation,
            ]);

            foreach ($request->items as $line) {
                $catalog = $catalogItems->get((int) $line['catalog_item_id']);
                if (! $catalog) {
                    continue;
                }

                $rep = $representatives[$catalog->id] ?? null;

                RequisitionItem::create([
                    'requisition_id'     => $ris->id,
                    'catalog_item_id'    => $catalog->id,
                    'item_id'            => $rep?->id,
                    'description'        => $catalog->name,
                    'unit'               => $rep?->unit,
                    'account_code'       => $catalog->account_code ?: $catalog->category?->account_code,
                    'warehouse_id'       => null,
                    'quantity_requested' => $line['quantity_requested'],
                    'stock_available'    => $rep ? $rep->quantity > 0 : false,
                    'unit_cost'          => $rep?->unit_cost ?? 0,
                ]);
            }

            // No warehouse is known at creation time, so notify every approver.
            $approvers = User::where('role', 'admin')
                ->orWhereIn('role', ['center_head', 'supply_custodian'])
                ->get();

            foreach ($approvers as $approver) {
                SystemNotification::create([
                    'user_id' => $approver->id,
                    'title' => 'New RIS Submitted',
                    'message' => "RIS #{$ris->ris_number} requires approval.",
                    'type' => 'warning',
                    'link' => route('requisitions.show', $ris->id),
                ]);
            }
        });

        return redirect()->route('requisitions.index')->with('success', 'Requisition created successfully.');
    }

    public function show(Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);
        $requisition->load(['warehouse', 'creator', 'approver', 'items.item', 'items.warehouse', 'items.dispatchItems.item.warehouse']);

        return view('requisitions.show', compact('requisition'));
    }

    public function edit(Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $requisition->load(['items.item', 'items.warehouse', 'items.dispatchItems']);

        return view('requisitions.edit', compact('requisition'));
    }

    public function update(Request $request, Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'entity_name' => 'nullable|string|max:255',
            'fund_cluster' => 'nullable|string|max:255',
            'office' => 'nullable|string|max:255',
            'division' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'responsibility_center_code' => 'nullable|string|max:255',
            'purpose' => 'required|string',
            'date_requested' => 'required|date',
            'status' => 'required|string|in:pending,approved,partially_approved,cancelled',
            'requested_by_name' => 'nullable|string|max:255',
            'requested_by_designation' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer|exists:requisition_items,id',
            'items.*.catalog_item_id' => 'required|exists:item_catalog_items,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        $existingItems = $requisition->items()->withCount('dispatchItems')->get()->keyBy('id');

        // Lines already dispatched cannot have their request reduced below what
        // has already been issued, and their catalog item is locked.
        $lineErrors = [];
        foreach ($request->items as $idx => $line) {
            if (empty($line['id'])) continue;

            $ri = $existingItems->get((int) $line['id']);
            if (! $ri) {
                $lineErrors["items.{$idx}.id"] = 'Invalid line item.';
                continue;
            }

            if ((float) $line['quantity_requested'] + 0.0001 < $ri->quantity_issued) {
                $lineErrors["items.{$idx}.quantity_requested"] =
                    "Quantity requested cannot be less than the already issued quantity ({$ri->quantity_issued}).";
            }

            if ($ri->quantity_issued > 0 || $ri->dispatch_items_count > 0) {
                $currentCatalogId = $ri->catalog_item_id;
                if ($currentCatalogId === null && $ri->description) {
                    $currentCatalogId = ItemCatalogItem::where('is_active', true)
                        ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($ri->description))])
                        ->value('id');
                }

                if ($currentCatalogId !== null && (int) $line['catalog_item_id'] !== (int) $currentCatalogId) {
                    $lineErrors["items.{$idx}.catalog_item_id"] =
                        'The item cannot be changed on a line that has already been dispatched.';
                }
            }
        }
        if (! empty($lineErrors)) {
            return back()->withErrors($lineErrors)->withInput();
        }

        DB::transaction(function () use ($request, $requisition, $existingItems) {
            $requisition->update([
                'entity_name' => $request->entity_name,
                'fund_cluster' => $request->fund_cluster,
                'dr_number' => null, // DR number is now stored per dispatch
                'warehouse_id' => null, // warehouse is chosen per dispatch
                'office' => $request->office,
                'division' => $request->division,
                'province' => $request->province,
                'municipality' => $request->municipality,
                'responsibility_center_code' => $request->responsibility_center_code,
                'purpose' => $request->purpose,
                'date_requested' => $request->date_requested,
                'requested_by_name' => $request->requested_by_name,
                'requested_by_designation' => $request->requested_by_designation,
                'status' => $request->status,
            ]);

            // Remove lines dropped from the form — only those never dispatched.
            $keptIds = collect($request->items)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            foreach ($existingItems as $ri) {
                if ($keptIds->contains($ri->id)) continue;
                if ($ri->quantity_issued <= 0 && $ri->dispatch_items_count === 0) {
                    $ri->delete();
                }
            }

            $catalogItems = ItemCatalogItem::with('category')
                ->whereIn('id', collect($request->items)->pluck('catalog_item_id')->filter()->unique())
                ->get()
                ->keyBy('id');

            // Representative stock record per catalog item — display only.
            $representatives = [];
            foreach ($catalogItems as $catalog) {
                $representatives[$catalog->id] = Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first();
            }

            foreach ($request->items as $line) {
                $catalog = $catalogItems->get((int) $line['catalog_item_id']);
                if (! $catalog) continue;

                $rep = $representatives[$catalog->id] ?? null;
                $ri  = $line['id'] ? $existingItems->get((int) $line['id']) : null;

                if ($ri) {
                    $locked = $ri->quantity_issued > 0 || $ri->dispatch_items_count > 0;

                    $ri->update([
                        // Issued lines keep their catalog item and snapshots.
                        'catalog_item_id'    => $locked ? $ri->catalog_item_id : $catalog->id,
                        'item_id'            => $locked ? $ri->item_id : $rep?->id,
                        'description'        => $locked ? $ri->description : $catalog->name,
                        'unit'               => $locked ? $ri->unit : $rep?->unit,
                        'account_code'       => $locked ? $ri->account_code : ($catalog->account_code ?: $catalog->category?->account_code),
                        'quantity_requested' => $locked
                            ? max((float) $line['quantity_requested'], $ri->quantity_issued)
                            : $line['quantity_requested'],
                    ]);
                } else {
                    RequisitionItem::create([
                        'requisition_id'     => $requisition->id,
                        'catalog_item_id'    => $catalog->id,
                        'item_id'            => $rep?->id,
                        'description'        => $catalog->name,
                        'unit'               => $rep?->unit,
                        'account_code'       => $catalog->account_code ?: $catalog->category?->account_code,
                        'warehouse_id'       => null,
                        'quantity_requested' => $line['quantity_requested'],
                        'stock_available'    => $rep ? $rep->quantity > 0 : false,
                        'unit_cost'          => $rep?->unit_cost ?? 0,
                    ]);
                }
            }
        });

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition updated successfully.');
    }

    public function destroy(Requisition $requisition)
    {
        // Only full administrators can delete requisitions
        abort_unless(Auth::user()->isAdmin(), 403, 'Only administrators can delete requisitions.');

        $risNumber = $requisition->ris_number;
        $requisition->load('items.dispatchItems.item');

        DB::transaction(function () use ($requisition) {
            // Reverse each dispatch independently — each one may have come from a
            // different warehouse/stock record.
            foreach ($requisition->items as $ri) {
                foreach ($ri->dispatchItems as $di) {
                    if ($di->quantity_issued > 0 && $di->item) {
                        $di->item->increment('quantity', $di->quantity_issued);
                    }
                }
            }

            // Delete all issuance stock card entries for this RIS
            StockCardEntry::where('reference_type', 'issuance')
                ->where('reference_id', $requisition->id)
                ->delete();

            $requisition->items()->delete();
            $requisition->delete();
        });

        return redirect()->route('requisitions.index')
            ->with('success', "RIS #{$risNumber} deleted and any issued stock has been reversed.");
    }

    public function approve(Requisition $requisition)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }

        $requisition->load(['items.item', 'items.warehouse', 'items.dispatchItems.item.warehouse']);

        // Warehouses the dispatcher may issue from (their assigned ones, or all
        // active warehouses for admins).
        $user = Auth::user();
        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        } else {
            $warehouses = $user->warehouses()->where('is_active', true)->orderBy('name')->get();
        }

        return view('requisitions.approve', compact('requisition', 'warehouses'));
    }

    public function processApproval(Request $request, Requisition $requisition)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }

        $rules = [
            'items' => 'required|array',
            'items.*.warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'items.*.item_id' => 'nullable|integer|exists:items,id',
            'items.*.quantity_issued' => 'required|numeric|min:0',
            'items.*.dr_number' => 'nullable|string|max:100',
            'items.*.engas_unit_cost' => 'nullable|numeric|min:0',
            'items.*.expiration_date' => 'nullable|date',
            'approved_by_name' => 'required|string',
            'issued_by_name' => 'required|string',
        ];

        // Every dispatch needs its own warehouse, exact stock record and DR number.
        foreach ($request->input('items', []) as $key => $data) {
            if ((float) ($data['quantity_issued'] ?? 0) > 0) {
                $rules["items.{$key}.warehouse_id"][] = 'required';
                $rules["items.{$key}.item_id"][]      = 'required';
                $rules["items.{$key}.dr_number"][]    = 'required';
            }
        }

        $request->validate($rules);

        $requisition->load(['items.item', 'items.warehouse', 'items.dispatchItems']);

        DB::transaction(function () use ($request, $requisition) {
            $user       = Auth::user();
            $anyIssued  = false;
            $allowedIds = $user->hasAdminAccess()
                ? null
                : $this->getUserWarehouseIds($user);

            foreach ($request->items as $riItemId => $data) {
                $riItem = RequisitionItem::where('id', $riItemId)
                    ->where('requisition_id', $requisition->id)
                    ->firstOrFail();

                $wanted = (float) $data['quantity_issued'];
                if ($wanted <= 0) {
                    continue;
                }

                $warehouseId = (int) $data['warehouse_id'];
                $itemId      = (int) $data['item_id'];

                // A non-admin dispatcher can only issue from their own warehouses.
                if ($allowedIds !== null && ! in_array($warehouseId, $allowedIds, true)) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.warehouse_id" => 'You are not assigned to this warehouse.',
                    ]);
                }

                // Lock and re-fetch the EXACT stock record chosen by the dispatcher
                // (Item + Warehouse). The deduction always targets this record.
                $item = Item::whereKey($itemId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.item_id" =>
                            'The selected item does not belong to the selected warehouse.',
                    ]);
                }

                // How much is still outstanding for this line
                $stillNeeded = max(0, $riItem->quantity_requested - $riItem->quantity_issued);

                // Reject instead of silently capping or borrowing from another record
                if ($wanted > $item->quantity + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.quantity_issued" =>
                            'Insufficient stock on the selected record "'.$item->description.'"'
                            .' ('.$item->stock_number.' · ₱'.number_format($item->unit_cost, 2).'): '
                            .'only '.number_format($item->quantity, 2).' available. '
                            .'No other unit-cost record will be used.',
                    ]);
                }

                if ($wanted > $stillNeeded + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.quantity_issued" =>
                            'Cannot issue more than the outstanding quantity '
                            .'('.number_format($stillNeeded, 2).') for "'.$item->description.'".',
                    ]);
                }

                $anyIssued   = true;
                $newItemQty  = $item->quantity - $wanted;

                // Record this dispatch. The exact stock record (item_id) pins the
                // warehouse, unit cost and other stock info — no warehouse stored.
                RequisitionDispatchItem::create([
                    'requisition_item_id' => $riItem->id,
                    'item_id'             => $item->id,
                    'quantity_issued'     => $wanted,
                    'unit_cost'           => (float) ($data['unit_cost'] ?? $item->unit_cost ?? 0),
                    'engas_unit_cost'     => $data['engas_unit_cost'] ?? $item->engas_unit_cost ?? null,
                    'expiration_date'     => $data['expiration_date'] ?? $item->expiration_date,
                    'dr_number'           => $data['dr_number'] ?? null,
                    'created_by'          => $user->id,
                ]);

                $item->update(['quantity' => $newItemQty]);

                StockCardEntry::create([
                    'item_id'            => $item->id,
                    'entry_date'         => now()->toDateString(),
                    'reference'          => $requisition->ris_number,
                    'reference_type'     => 'issuance',
                    'reference_id'       => $requisition->id,
                    'receipt_qty'        => 0,
                    'receipt_unit_cost'  => 0,
                    'receipt_total_cost' => 0,
                    'issue_qty'          => $wanted,
                    'balance_qty'        => $newItemQty,
                    'balance_unit_cost'  => $item->unit_cost,
                    'balance_total_cost' => $newItemQty * $item->unit_cost,
                    'from_to'            => $requisition->office ?? $item->warehouse->name ?? '',
                ]);

                // Accumulate the cached quantity_issued and keep the DR# synced to
                // the most recent dispatch.
                $riItem->update([
                    'quantity_issued' => $riItem->quantity_issued + $wanted,
                    'stock_available' => $item->quantity >= $riItem->quantity_requested,
                    'dr_number'       => $data['dr_number'] ?? $riItem->dr_number,
                    'engas_unit_cost' => $data['engas_unit_cost'] ?? $riItem->engas_unit_cost,
                    'expiration_date' => $data['expiration_date'] ?? $riItem->expiration_date,
                    'unit_cost'       => $data['unit_cost'] ?? $riItem->unit_cost,
                ]);
            }

            // Refresh items to get updated quantity_issued values, then recalculate status
            $requisition->load('items');
            $requisition->updateFulfilmentStatus();

            // Update signatories and approval metadata
            $requisition->update([
                'approved_by'              => $user->id,
                'date_approved'            => now()->toDateString(),
                'approved_by_name'         => $request->approved_by_name,
                'approved_by_designation'  => $request->approved_by_designation,
                'issued_by_name'           => $request->issued_by_name,
                'issued_by_designation'    => $request->issued_by_designation,
                'received_by_name'         => $request->received_by_name,
                'received_by_designation'  => $request->received_by_designation,
            ]);

            $status = $requisition->fresh()->status;

            SystemNotification::create([
                'user_id' => $requisition->created_by,
                'title'   => 'RIS ' . match($status) {
                    'approved'           => 'Fully Fulfilled',
                    'partially_approved' => 'Partially Fulfilled',
                    default              => 'Updated',
                },
                'message' => match($status) {
                    'approved'           => "Your RIS #{$requisition->ris_number} has been fully fulfilled.",
                    'partially_approved' => "Your RIS #{$requisition->ris_number} has been partially fulfilled. Some items are still outstanding.",
                    default              => "Your RIS #{$requisition->ris_number} has been updated.",
                },
                'type' => $status === 'approved' ? 'success' : 'info',
                'link' => route('requisitions.show', $requisition->id),
            ]);
        });

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition processed successfully.');
    }

    public function signatories(Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        return view('requisitions.signatories', compact('requisition'));
    }

    public function updateSignatories(Request $request, Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);
        $requisition->update($request->only([
            'requested_by_name', 'requested_by_designation',
            'approved_by_name',  'approved_by_designation',
            'issued_by_name',    'issued_by_designation',
            'received_by_name',  'received_by_designation',
        ]));

        return redirect()->route('requisitions.show', $requisition)->with('success', 'Signatories updated.');
    }

    public function printRis(Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);
        $requisition->load(['warehouse', 'items.item', 'items.warehouse', 'items.dispatchItems.item.warehouse', 'creator', 'approver']);

        return view('requisitions.print', compact('requisition'));
    }

    /**
     * A non-admin user may access a requisition when any of its line items or
     * dispatches belongs to one of their assigned warehouses (falling back to
     * the legacy requisition-level warehouse_id).
     */
    private function userCanAccessRequisition(User $user, Requisition $requisition): bool
    {
        if ($user->hasAdminAccess()) {
            return true;
        }

        $ids = $this->getUserWarehouseIds($user);
        if (empty($ids)) {
            return false;
        }

        if ($requisition->items()->whereIn('warehouse_id', $ids)->exists()) {
            return true;
        }

        if ($requisition->items()->whereHas('dispatchItems.item', fn ($i) => $i->whereIn('warehouse_id', $ids))->exists()) {
            return true;
        }

        return $requisition->warehouse_id !== null
            && in_array((int) $requisition->warehouse_id, $ids, true);
    }
}
