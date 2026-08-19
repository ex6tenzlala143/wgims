<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\Requisition;
use App\Models\RequisitionAuditLog;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RequisitionController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'creator', 'items.warehouse', 'items.item', 'items.dispatchItems.item.warehouse'])
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
        if ($request->related_to_deleted_subsidy === 'yes') {
            $query->where(function ($q) {
                $q->whereHas('items.item', fn ($i) => $i->whereIn('source_subsidy_status', ['deleted', 'archived']))
                  ->orWhereHas('items.dispatchItems.item', fn ($i) => $i->whereIn('source_subsidy_status', ['deleted', 'archived']));
            });
        } elseif ($request->related_to_deleted_subsidy === 'no') {
            $query->where(function ($q) {
                $q->whereDoesntHave('items.item', fn ($i) => $i->whereIn('source_subsidy_status', ['deleted', 'archived']))
                  ->whereDoesntHave('items.dispatchItems.item', fn ($i) => $i->whereIn('source_subsidy_status', ['deleted', 'archived']));
            });
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

        try {
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RIS creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

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

        try {
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RIS update failed', [
                'requisition_id' => $requisition->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition updated successfully.');
    }

    public function destroy(Requisition $requisition)
    {
        // Only full administrators can delete requisitions
        abort_unless(Auth::user()->isAdmin(), 403, 'Only administrators can delete requisitions.');

        $risNumber = $requisition->ris_number;
        $requisition->load('items.dispatchItems.item');

        try {
            DB::transaction(function () use ($requisition) {
            $affectedItemIds = [];

            // Reverse each dispatch independently — each one may have come from a
            // different warehouse/stock record.
            foreach ($requisition->items as $ri) {
                foreach ($ri->dispatchItems as $di) {
                    if ($di->quantity_issued > 0 && $di->item) {
                        $di->item->increment('quantity', $di->quantity_issued);
                        $affectedItemIds[$di->item->id] = true;
                    }
                }
            }

            // Delete all issuance stock card entries for this RIS
            StockCardEntry::where('reference_type', 'issuance')
                ->where('reference_id', $requisition->id)
                ->delete();

            $requisition->items()->delete();
            $requisition->delete();

            // Rebuild running balances so later stock-card entries stay
            // consistent after their upstream issuance rows were removed.
            foreach (array_keys($affectedItemIds) as $itemId) {
                StockCardEntry::recalculateBalancesForItem($itemId);
            }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RIS deletion failed', [
                'requisition_id' => $requisition->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('requisitions.index')
            ->with('success', "RIS #{$risNumber} deleted and any issued stock has been reversed.");
    }

    /**
     * API: data for the "Correct RIS" modal. Returns the header fields and every
     * line item (with its issued quantity and whether it is locked), plus the
     * description-level catalog so undispatched lines can be re-pointed.
     * GET /requisitions/{requisition}/correction-data
     */
    public function correctionData(Request $request, Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($user->canWrite(), 403);
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $requisition->load(['items.item', 'items.dispatchItems']);

        $items = $requisition->items->map(function ($ri) {
            return [
                'id'                 => $ri->id,
                'catalog_item_id'    => $ri->catalog_item_id,
                'description'        => $ri->description ?? $ri->item?->description,
                'unit'               => $ri->unit ?? $ri->item?->unit ?? '',
                'account_code'       => $ri->account_code ?? '',
                'quantity_requested' => (float) $ri->quantity_requested,
                'quantity_issued'    => (float) $ri->quantity_issued,
                'locked'             => $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty(),
            ];
        });

        return response()->json([
            'ris_number'    => $requisition->ris_number,
            'status'        => $requisition->status,
            'is_completed'  => $requisition->status === 'approved',
            'entity_name'   => $requisition->entity_name,
            'fund_cluster'  => $requisition->fund_cluster,
            'office'        => $requisition->office,
            'division'      => $requisition->division,
            'province'      => $requisition->province,
            'municipality'  => $requisition->municipality,
            'responsibility_center_code' => $requisition->responsibility_center_code,
            'purpose'       => $requisition->purpose,
            'date_requested'=> $requisition->date_requested?->format('Y-m-d'),
            'requested_by_name'        => $requisition->requested_by_name,
            'requested_by_designation' => $requisition->requested_by_designation,
            'items'         => $items->values(),
            'catalog_items' => $items->contains(fn ($i) => ! $i['locked'])
                ? $this->catalogItemsForCorrection()
                : [],
            'totals' => [
                'requested' => $requisition->totalRequested(),
                'issued'    => $requisition->totalIssued(),
                'remaining' => $requisition->totalRemaining(),
            ],
        ]);
    }

    /**
     * Apply a correction to an issued/completed RIS. Only the request itself is
     * changed: header fields and per-line requested quantities (plus the item on
     * lines that have never been dispatched). Dispatches, stock cards, DR numbers
     * and inventory balances are NEVER touched here — resolving an over-issuance
     * is done through the separate "Edit Dispatch" correction instead.
     *
     * The requested→issued→outstanding figures and the RIS status are recomputed,
     * and every change is written to the correction audit log.
     * PUT /requisitions/{requisition}/correct
     */
    public function correct(Request $request, Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($user->canWrite(), 403);
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $request->validate([
            'entity_name'    => 'nullable|string|max:255',
            'fund_cluster'   => 'nullable|string|max:255',
            'office'         => 'nullable|string|max:255',
            'division'       => 'nullable|string|max:255',
            'province'       => 'nullable|string|max:255',
            'municipality'   => 'nullable|string|max:255',
            'responsibility_center_code' => 'nullable|string|max:255',
            'purpose'        => 'required|string',
            'date_requested' => 'required|date',
            'requested_by_name'        => 'nullable|string|max:255',
            'requested_by_designation' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.id'               => 'required|integer|exists:requisition_items,id',
            'items.*.catalog_item_id'  => 'nullable|exists:item_catalog_items,id',
            'items.*.quantity_requested' => 'required|numeric|min:0.01',
        ]);

        $existingItems = $requisition->items()->with('dispatchItems')->get()->keyBy('id');

        // Every submitted line must belong to this requisition.
        foreach ($request->items as $idx => $line) {
            if (! $existingItems->has((int) $line['id'])) {
                throw ValidationException::withMessages([
                    "items.{$idx}.id" => 'Invalid line item for this RIS.',
                ]);
            }
        }

        $oldStatus = $requisition->status;
        $oldTotal  = $requisition->totalRequested();
        $changes   = [];

        try {
            DB::transaction(function () use ($request, $requisition, $existingItems, $user, &$changes, &$oldTotal, &$oldStatus) {
            // ── Header ──────────────────────────────────────────────────────
            $headerMap = [
                'entity_name', 'fund_cluster', 'office', 'division', 'province',
                'municipality', 'responsibility_center_code', 'purpose',
                'date_requested', 'requested_by_name', 'requested_by_designation',
            ];

            $headerData = [];
            foreach ($headerMap as $field) {
                $oldVal = $requisition->{$field} instanceof \DateTimeInterface
                    ? $requisition->{$field}->format('Y-m-d')
                    : $requisition->{$field};
                $newVal = $request->{$field};
                $headerData[$field] = $newVal;
                if ((string) $oldVal !== (string) $newVal) {
                    $changes[$field] = ['old' => $oldVal, 'new' => $newVal];
                }
            }

            // ── Lines: requested quantity (item change only when undispatched) ─
            $catalogItems = ItemCatalogItem::with('category')
                ->whereIn('id', collect($request->items)->pluck('catalog_item_id')->filter()->unique())
                ->get()
                ->keyBy('id');

            $representatives = [];
            foreach ($catalogItems as $catalog) {
                $representatives[$catalog->id] = Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first();
            }

            foreach ($request->items as $idx => $line) {
                $ri     = $existingItems->get((int) $line['id']);
                $oldQty = (float) $ri->quantity_requested;
                $newQty = round((float) $line['quantity_requested'], 4);
                $locked = $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty();

                // Inventory protection: the request can never drop below what is
                // already issued. Resolve an over-issuance via "Edit Dispatch".
                if ($newQty + 0.0001 < $ri->quantity_issued) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity_requested" =>
                            'Requested quantity ('.number_format($newQty, 2).') cannot be less than the '
                            .number_format($ri->quantity_issued, 2).' already issued. '
                            .'Correct the issued dispatch(s) first, then fix the request.',
                    ]);
                }

                $newCatalogId = $line['catalog_item_id'] ?? null;
                if ($locked && $newCatalogId && (int) $newCatalogId !== (int) $ri->catalog_item_id) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.catalog_item_id" =>
                            'The item cannot be changed on a line that has already been dispatched.',
                    ]);
                }

                $data = ['quantity_requested' => $newQty];
                $oldDesc = $ri->description;

                if (! $locked && $newCatalogId) {
                    $catalog = $catalogItems->get((int) $newCatalogId);
                    if ($catalog && $catalog->is_active) {
                        $rep = $representatives[$catalog->id] ?? null;
                        $data += [
                            'catalog_item_id' => $catalog->id,
                            'item_id'         => $rep?->id,
                            'description'     => $catalog->name,
                            'unit'            => $rep?->unit,
                            'account_code'    => $catalog->account_code ?: $catalog->category?->account_code,
                        ];
                    }
                }

                $ri->update($data);

                if (abs($newQty - $oldQty) > 0.0001) {
                    $changes["items.{$ri->id}.quantity_requested"] = ['old' => $oldQty, 'new' => $newQty];
                }
                if (isset($data['description']) && $data['description'] !== $oldDesc) {
                    $changes["items.{$ri->id}.item"] = ['old' => $oldDesc, 'new' => $data['description']];
                }
            }

            $requisition->update($headerData);

            // Reload the lines from the DB: totalRequested()/updateFulfilmentStatus()
            // would otherwise reuse the relation captured before the updates above.
            $requisition->load('items');

            // Recompute requested → issued → outstanding → status. Dispatches and
            // inventory records are untouched — only the request is reclassified.
            $requisition->updateFulfilmentStatus();

            $newTotal = (float) $existingItems->sum('quantity_requested');
            if (abs($newTotal - $oldTotal) > 0.0001) {
                $changes['total_requested'] = ['old' => $oldTotal, 'new' => round($newTotal, 4)];
            }
            if ($requisition->status !== $oldStatus) {
                $changes['status'] = ['old' => $oldStatus, 'new' => $requisition->status];
            }

            if (! empty($changes)) {
                RequisitionAuditLog::create([
                    'requisition_id' => $requisition->id,
                    'user_id'        => $user->id,
                    'action'         => 'correction',
                    'changed_fields' => $changes,
                ]);
            }
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RIS correction failed', [
                'requisition_id' => $requisition->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => ['general' => ['The transaction could not be completed. No changes were made. Please try again.']],
            ], 500);
        }

        return response()->json(['redirect' => route('requisitions.show', $requisition->id)]);
    }

    /** Description-level item list for the correction modal (same data as the create form). */
    private function catalogItemsForCorrection(): array
    {
        $catalogItems = ItemCatalogItem::with('category')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $stock = Item::where('is_active', true)
            ->where('quantity', '>', 0)
            ->get(['description', 'unit', 'quantity'])
            ->groupBy(fn ($i) => mb_strtolower(trim($i->description)));

        return $catalogItems->map(function ($catalog) use ($stock) {
            $records = $stock->get(mb_strtolower(trim($catalog->name)), collect());

            return [
                'id'           => $catalog->id,
                'description'  => $catalog->name,
                'name'         => $catalog->name,
                'account_code' => $catalog->account_code ?: $catalog->category?->account_code,
                'unit'         => $records->first()?->unit ?? '',
                'total_stock'  => (float) $records->sum('quantity'),
            ];
        })->values()->all();
    }

    /**
     * Audit log view: who corrected the RIS, when, and every field that changed.
     * GET /requisitions/{requisition}/audit-log
     */
    public function auditLog(Requisition $requisition)
    {
        $user = Auth::user();
        abort_unless($user->canWrite(), 403);
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $requisition->load(['items', 'warehouse']);
        $logs = $requisition->auditLogs()->with('user')->paginate(20);

        return view('requisitions.audit_log', compact('requisition', 'logs'));
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

        try {
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
                $dispatch = RequisitionDispatchItem::create([
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
                    'dispatch_item_id'   => $dispatch->id,
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('RIS dispatch failed', [
                'requisition_id' => $requisition->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('requisitions.show', $requisition)
            ->with('success', 'Requisition processed successfully.');
    }

    /**
     * API: data for the "Edit Issued Item" modal. Returns the dispatch's current
     * values, the warehouses the user may dispatch from, and the stock records
     * available in the dispatch's current warehouse (including the record it
     * currently references, even if it is now out of stock).
     */
    public function dispatchEditData(Request $request, RequisitionDispatchItem $dispatch)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }

        $dispatch->load(['requisitionItem.requisition', 'item.warehouse']);
        $user          = Auth::user();
        $allowedIds    = $this->getUserWarehouseIds($user);
        $currentWhId   = (int) ($dispatch->item?->warehouse_id ?? 0);

        if ($currentWhId <= 0 || ($allowedIds !== null && ! in_array($currentWhId, $allowedIds, true))) {
            abort(403);
        }

        $warehouses = $user->hasAdminAccess()
            ? Warehouse::where('is_active', true)->orderBy('name')->get()
            : $user->warehouses()->where('is_active', true)->orderBy('name')->get();

        // Same selection rule as getItemsByWarehouse, but the dispatch's current
        // record is always included so the existing selection can be restored.
        $stockRecords = Item::where('warehouse_id', $currentWhId)
            ->where('is_active', true)
            ->where(function ($q) use ($dispatch) {
                $q->where(function ($inner) {
                    $inner->where('quantity', '>', 0)->whereNotNull('stock_number');
                })->orWhere('id', $dispatch->item_id);
            })
            ->orderBy('description')
            ->orderBy('unit_cost')
            ->get(['id', 'description', 'unit', 'quantity', 'stock_number',
                   'expiration_date', 'unit_cost', 'engas_unit_cost']);

        return response()->json([
            'id'                  => $dispatch->id,
            'requisition_item_id' => $dispatch->requisition_item_id,
            'requisition_id'      => $dispatch->requisitionItem->requisition_id,
            'ris_number'          => $dispatch->requisitionItem->requisition->ris_number,
            'description'         => $dispatch->item?->description,
            'item_id'             => $dispatch->item_id,
            'warehouse_id'        => $currentWhId,
            'quantity_issued'     => $dispatch->quantity_issued,
            'unit_cost'           => $dispatch->unit_cost,
            'engas_unit_cost'     => $dispatch->engas_unit_cost,
            'expiration_date'     => $dispatch->expiration_date?->format('Y-m-d'),
            'dr_number'           => $dispatch->dr_number,
            'warehouses'          => $warehouses->map(fn ($w) => [
                'id'   => $w->id,
                'name' => $w->name,
                'code' => $w->code,
            ])->values(),
            'stock_records'       => $stockRecords->map(fn ($i) => [
                'id'              => $i->id,
                'description'     => $i->description,
                'unit'            => $i->unit,
                'quantity'        => $i->quantity,
                'stock_number'    => $i->stock_number,
                'expiry_date'     => $i->expiration_date?->format('Y-m-d'),
                'unit_cost'       => $i->unit_cost,
                'engas_unit_cost' => $i->engas_unit_cost,
            ])->values(),
        ]);
    }

    /**
     * Apply edits to a single dispatched item and correct every connected
     * inventory record without double-counting or losing stock.
     *
     * The old deduction is reversed from the OLD exact stock record and the new
     * deduction is applied to the NEW exact record — the delta never borrows
     * from another unit-cost/FIFO record. Stock cards, the line cache, the RIS
     * totals and the fulfilment status are all refreshed in the same transaction.
     */
    public function updateDispatch(Request $request, RequisitionDispatchItem $dispatch)
    {
        if (! Auth::user()->canApprove()) {
            abort(403);
        }

        $request->validate([
            'warehouse_id'    => 'required|integer|exists:warehouses,id',
            'item_id'         => 'required|integer|exists:items,id',
            'quantity_issued' => 'required|numeric|min:0.0001',
            'unit_cost'       => 'required|numeric|min:0',
            'engas_unit_cost' => 'nullable|numeric|min:0',
            'expiration_date' => 'nullable|date',
            'dr_number'       => 'required|string|max:100',
        ]);

        $dispatch->load(['requisitionItem', 'requisitionItem.requisition', 'item']);
        $requisitionId = $dispatch->requisitionItem->requisition_id;

        try {
            DB::transaction(function () use ($request, $dispatch, &$requisitionId) {
            $user        = Auth::user();
            $ri          = $dispatch->requisitionItem;
            $requisition = $ri->requisition;

            $oldItemId = (int) $dispatch->item_id;
            $oldQty    = (float) $dispatch->quantity_issued;

            $warehouseId = (int) $request->warehouse_id;
            $newItemId   = (int) $request->item_id;
            $newQty      = round((float) $request->quantity_issued, 4);
            $newUnitCost = round((float) $request->unit_cost, 2);
            $newEngas    = ($request->engas_unit_cost !== null && $request->engas_unit_cost !== '')
                ? round((float) $request->engas_unit_cost, 2)
                : null;

            // A non-admin dispatcher can only issue from their own warehouses.
            $allowedIds = $this->getUserWarehouseIds($user);
            if ($allowedIds !== null && ! in_array($warehouseId, $allowedIds, true)) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'You are not assigned to this warehouse.',
                ]);
            }

            // Lock and re-fetch the EXACT stock record the dispatch must point to.
            $newItem = Item::whereKey($newItemId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (! $newItem) {
                throw ValidationException::withMessages([
                    'item_id' => 'The selected stock record does not belong to the selected warehouse.',
                ]);
            }

            // The line can never exceed its requested quantity. The old dispatch's
            // quantity is credited back before the new value is counted.
            $lineTotalAfter = $ri->quantity_issued - $oldQty + $newQty;
            if ($lineTotalAfter > $ri->quantity_requested + 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_issued' =>
                        'Cannot issue more than the outstanding quantity '
                        .'('.number_format(max(0, $ri->quantity_requested - ($ri->quantity_issued - $oldQty)), 2)
                        .') for "'.$newItem->description.'".',
                ]);
            }

            // Stock must exist on the exact new record — when it is the same
            // record, the old quantity is credited back first.
            $availableOnRecord = (float) $newItem->quantity
                + ($oldItemId === $newItemId ? $oldQty : 0.0);

            if ($newQty > $availableOnRecord + 0.0001) {
                throw ValidationException::withMessages([
                    'quantity_issued' =>
                        'Insufficient stock on the selected record "'.$newItem->description.'"'
                        .' ('.$newItem->stock_number.' · ₱'.number_format($newItem->unit_cost, 2).'): '
                        .'only '.number_format($availableOnRecord, 2).' available. '
                        .'No other unit-cost record will be used.',
                ]);
            }

            $oldItem = $oldItemId === $newItemId
                ? $newItem
                : Item::whereKey($oldItemId)->lockForUpdate()->first();

            // ── Reverse the old deduction, apply the new one ────────────────
            if ($oldItemId === $newItemId) {
                $newItem->update(['quantity' => round((float) $newItem->quantity + $oldQty - $newQty, 4)]);
            } else {
                $oldItem->update(['quantity' => round((float) $oldItem->quantity + $oldQty, 4)]);
                $newItem->update(['quantity' => round((float) $newItem->quantity - $newQty, 4)]);
            }

            // ── Update the dispatch row (warehouse follows the stock record) ─
            $dispatch->update([
                'item_id'         => $newItemId,
                'quantity_issued' => $newQty,
                'unit_cost'       => $newUnitCost,
                'engas_unit_cost' => $newEngas,
                'expiration_date' => $request->expiration_date ?: null,
                'dr_number'       => $request->dr_number,
            ]);

            // ── Stock-card entries: update in place on the same record, move
            //    them when the record changes. Never double-count or drop an
            //    entry for another dispatch of the same item. ───────────────
            $entry = StockCardEntry::where('dispatch_item_id', $dispatch->id)->first();

            if ($oldItemId === $newItemId) {
                if ($entry) {
                    $entry->update(['issue_qty' => $newQty]);
                } else {
                    // Legacy safety net: entries created before dispatch linking
                    $entry = StockCardEntry::where('reference_type', 'issuance')
                        ->where('reference_id', $requisition->id)
                        ->where('item_id', $oldItemId)
                        ->first();
                    if ($entry) {
                        $entry->update([
                            'dispatch_item_id' => $dispatch->id,
                            'issue_qty'        => $newQty,
                        ]);
                    }
                }
            } else {
                if ($entry) {
                    $entry->delete();
                } else {
                    // Legacy safety net: delete the matching unlinked issuance
                    StockCardEntry::where('reference_type', 'issuance')
                        ->where('reference_id', $requisition->id)
                        ->where('item_id', $oldItemId)
                        ->first()
                        ?->delete();
                }

                StockCardEntry::create([
                    'item_id'            => $newItemId,
                    'entry_date'         => now()->toDateString(),
                    'reference'          => $requisition->ris_number,
                    'reference_type'     => 'issuance',
                    'reference_id'       => $requisition->id,
                    'dispatch_item_id'   => $dispatch->id,
                    'receipt_qty'        => 0,
                    'receipt_unit_cost'  => 0,
                    'receipt_total_cost' => 0,
                    'issue_qty'          => $newQty,
                    'balance_qty'        => 0,
                    'balance_unit_cost'  => $newItem->unit_cost,
                    'balance_total_cost' => 0,
                    'from_to'            => $requisition->office ?? $newItem->warehouse->name ?? '',
                ]);
            }

            StockCardEntry::recalculateBalancesForItem($newItemId);
            if ($oldItemId !== $newItemId) {
                StockCardEntry::recalculateBalancesForItem($oldItemId);
            }

            // ── Refresh the line cache (keeps totals + breakdowns consistent) ─
            $ri->update([
                'quantity_issued' => round($lineTotalAfter, 4),
                'stock_available' => (float) $newItem->quantity >= $ri->quantity_requested,
                'dr_number'       => $request->dr_number ?: $ri->dr_number,
                'engas_unit_cost' => $newEngas ?? $ri->engas_unit_cost,
                'expiration_date' => $request->expiration_date ?: $ri->expiration_date,
                'unit_cost'       => $newUnitCost,
            ]);

            $requisition->load('items');
            $requisition->updateFulfilmentStatus();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Dispatch edit failed', [
                'dispatch_id' => $dispatch->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => ['general' => ['The transaction could not be completed. No changes were made. Please try again.']],
            ], 500);
        }

        return response()->json(['redirect' => route('requisitions.show', $requisitionId)]);
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
