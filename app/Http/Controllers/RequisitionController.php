<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\Requisition;
use App\Models\RequisitionAuditLog;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\ReservationItem;
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
                $q->whereHas('items.item', fn ($i) => $i->where('source_subsidy_status', 'deleted'))
                  ->orWhereHas('items.dispatchItems.item', fn ($i) => $i->where('source_subsidy_status', 'deleted'));
            });
        } elseif ($request->related_to_deleted_subsidy === 'no') {
            $query->where(function ($q) {
                $q->whereDoesntHave('items.item', fn ($i) => $i->where('source_subsidy_status', 'deleted'))
                  ->whereDoesntHave('items.dispatchItems.item', fn ($i) => $i->where('source_subsidy_status', 'deleted'));
            });
        }
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('ris_number', 'like', "%{$search}%")
                  ->orWhere('ris_code', 'like', "%{$search}%")
                  ->orWhere('dr_number', 'like', "%{$search}%")
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
        $query = Item::where('warehouse_id', $request->warehouse_id)
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotNull('stock_number')
            ->orderBy('description')
            ->orderBy('unit_cost');

        // If the caller passes a description (from the RIS line item), restrict
        // results to that item only so the dispatcher only sees relevant stock.
        if ($request->filled('description')) {
            $query->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($request->description))]);
        }

        $items = $query->get(['id', 'description', 'unit', 'quantity', 'stock_number',
                   'expiration_date', 'category', 'unit_cost', 'engas_unit_cost']);

        return response()->json($items->map(function ($i) {
            $reserved        = \App\Models\ReservationItem::reservedQuantityForItem($i->id);
            $availableQty    = max(0, $i->quantity - $reserved);
            $expiryFormatted = $i->expiration_date ? $i->expiration_date->format('M d, Y') : '—';
            $engasDisplay    = $i->engas_unit_cost !== null
                ? '₱' . number_format($i->engas_unit_cost, 2)
                : '—';

            // Build display text showing AVAILABLE quantity prominently so the
            // dispatcher is never misled by the physical on-hand figure.
            $reservedNote = $reserved > 0
                ? ' · 🔒 ' . number_format($reserved) . ' reserved'
                : '';

            $displayText = sprintf(
                "%s\nAvail: %s%s · ₱%s · ENGAS %s · Exp: %s",
                $i->description,
                number_format($availableQty, 0),
                $reservedNote,
                number_format($i->unit_cost, 2),
                $engasDisplay,
                $expiryFormatted
            );

            return [
                'id'                  => $i->id,
                'description'         => $i->description,
                'display_text'        => $displayText,
                'unit'                => $i->unit,
                'quantity'            => $i->quantity,        // physical on-hand
                'available_qty'       => $availableQty,       // what can actually be dispatched
                'reserved_qty'        => $reserved,
                'stock_number'        => $i->stock_number,
                'expiry_date'         => $i->expiration_date?->format('Y-m-d'),
                'expiry_formatted'    => $expiryFormatted,
                'category'            => $i->category,
                'unit_cost'           => $i->unit_cost,
                'unit_cost_formatted' => '₱' . number_format($i->unit_cost, 2),
                'engas_unit_cost'     => $i->engas_unit_cost,
                'engas_formatted'     => $engasDisplay,
            ];
        })->filter(fn ($i) => $i['available_qty'] > 0)->values());
    }

    public function store(Request $request)
    {
        $request->validate([
            'ris_number' => 'nullable|string|max:255|unique:requisitions,ris_number',
            'purpose' => 'required|string',
            'date_requested' => 'required|date',
            'province' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'required|exists:item_catalog_items,id',
            'items.*.quantity_requested' => 'required|integer|min:1',
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

            // The unit of issue is a request-descriptive attribute and the
            // catalog has no unit column, so it is read from any active stock
            // record of the same description. NO stock record is linked, costed
            // or reserved at creation — allocation happens at issuance.
            $representatives = [];
            foreach ($catalogItems as $catalog) {
                $representatives[$catalog->id] = Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first(['id', 'unit']);
            }

            $risNumber = $request->filled('ris_number') ? $request->ris_number : Requisition::generateRisNumber();
            $ris = Requisition::create([
                'ris_number' => $risNumber,
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
            // ris_code (RIS ID) is auto-generated in the model boot hook (RIS-000001)

            foreach ($request->items as $line) {
                $catalog = $catalogItems->get((int) $line['catalog_item_id']);
                if (! $catalog) {
                    continue;
                }

                $rep = $representatives[$catalog->id] ?? null;

                RequisitionItem::create([
                    'requisition_id'     => $ris->id,
                    'catalog_item_id'    => $catalog->id,
                    'description'        => $catalog->name,
                    'unit'               => $rep?->unit,
                    'account_code'       => $catalog->account_code ?: $catalog->category?->account_code,
                    'warehouse_id'       => null,
                    'quantity_requested' => $line['quantity_requested'],
                    // Issuance-specific values (stock record, warehouse, costs,
                    // ENGAS, expiry, DR, availability) are intentionally NOT set
                    // here — they are populated only when a dispatch is recorded.
                ]);
            }

            // No warehouse is known at creation time, so notify every approver.
            $approvers = User::where('role', 'admin')
                ->orWhereIn('role', ['center_head', 'supply_custodian'])
                ->get();

            $now = now();
            $notifRows = $approvers->map(fn ($approver) => [
                'user_id'    => $approver->id,
                'title'      => 'New RIS Submitted',
                'message'    => "RIS #{$ris->ris_number} requires approval.",
                'type'       => 'warning',
                'link'       => route('requisitions.show', $ris->id),
                'is_read'    => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            if (! empty($notifRows)) {
                \App\Models\SystemNotification::insert($notifRows);
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

        // Available stock per dispatched stock record, in ONE grouped query
        // (avoids N+1 from the per-item reserved_quantity accessor). Used by
        // the Partial Delivery Breakdown table's "Available Stocks" column:
        // available = on-hand quantity − active reservation lock.
        $dispatchItemIds = $requisition->items
            ->flatMap(fn ($ri) => $ri->dispatchItems->pluck('item_id'))
            ->filter()->unique()->values();
        $reservedMap = $dispatchItemIds->isNotEmpty()
            ? ReservationItem::whereIn('item_id', $dispatchItemIds)
                ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(reserved_quantity) as total_reserved')
                ->pluck('total_reserved', 'item_id')
            : collect();
        $stockAvailability = [];
        foreach ($requisition->items as $ri) {
            foreach ($ri->dispatchItems as $di) {
                if ($di->item && ! isset($stockAvailability[$di->item->id])) {
                    $stockAvailability[$di->item->id] = max(0, (float) $di->item->quantity - (float) ($reservedMap[$di->item->id] ?? 0));
                }
            }
        }

        return view('requisitions.show', compact('requisition', 'stockAvailability'));
    }

    public function edit(Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $requisition->load(['items.item', 'items.warehouse', 'items.dispatchItems.item.warehouse']);

        return view('requisitions.edit', compact('requisition'));
    }

    public function update(Request $request, Requisition $requisition)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'ris_number' => 'nullable|string|max:255|unique:requisitions,ris_number,' . $requisition->id,
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
            'items.*.quantity_requested' => 'required|integer|min:1',
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
            $requisition->update(array_filter([
                'ris_number' => $request->filled('ris_number') ? $request->ris_number : $requisition->ris_number,
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
            ], fn($v) => $v !== null));

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

            // The unit of issue is read for display only — no stock record is
            // linked or reserved at edit time. Allocation happens at issuance.
            $representatives = [];
            foreach ($catalogItems as $catalog) {
                $representatives[$catalog->id] = Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->orderByDesc('quantity')
                    ->orderBy('id')
                    ->first(['id', 'unit']);
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
                        // Undispatched lines keep NO stock linkage: the exact
                        // record is chosen only when issuance happens.
                        'catalog_item_id'    => $locked ? $ri->catalog_item_id : $catalog->id,
                        'item_id'            => $locked ? $ri->item_id : null,
                        'description'        => $locked ? $ri->description : $catalog->name,
                        'unit'               => $locked ? $ri->unit : $rep?->unit,
                        'account_code'       => $locked ? $ri->account_code : ($catalog->account_code ?: $catalog->category?->account_code),
                        'quantity_requested' => $locked
                            ? max((float) $line['quantity_requested'], $ri->quantity_issued)
                            : $line['quantity_requested'],
                    ] + ($locked ? [] : [
                        // Normalize stale pre-dispatch caches left by older versions.
                        'warehouse_id'       => null,
                        'stock_available'    => false,
                    ]));
                } else {
                    RequisitionItem::create([
                        'requisition_id'     => $requisition->id,
                        'catalog_item_id'    => $catalog->id,
                        'description'        => $catalog->name,
                        'unit'               => $rep?->unit,
                        'account_code'       => $catalog->account_code ?: $catalog->category?->account_code,
                        'warehouse_id'       => null,
                        'quantity_requested' => $line['quantity_requested'],
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
        $requisition->load('items.dispatchItems.item', 'items.dispatchItems.reservationItem.reservation');

        try {
            DB::transaction(function () use ($requisition) {
            $affectedItemIds       = [];
            $affectedReservItemIds = []; // reservation_items that need deployed_qty reversed

            // Reverse each dispatch independently — each one may have come from a
            // different warehouse/stock record.
            foreach ($requisition->items as $ri) {
                foreach ($ri->dispatchItems as $di) {
                    if ($di->quantity_issued > 0 && $di->item) {
                        // Use update() not increment() so the Item::saving hook
                        // can reactivate the record if quantity rises above 0.
                        $di->item->update(['quantity' => (int) round((float) $di->item->quantity + $di->quantity_issued)]);
                        $affectedItemIds[$di->item->id] = true;
                    }
                    // Track reservation_item links so we can reverse deployed_quantity
                    if ($di->reservation_item_id) {
                        $affectedReservItemIds[$di->reservation_item_id] =
                            ($affectedReservItemIds[$di->reservation_item_id] ?? 0)
                            + (float) $di->quantity_issued;
                    }
                }
            }

            // Delete all issuance stock card entries for this RIS
            StockCardEntry::where('reference_type', 'issuance')
                ->where('reference_id', $requisition->id)
                ->delete();

            // Delete dispatch items first (FK: requisition_dispatch_items → requisition_items)
            foreach ($requisition->items as $ri) {
                $ri->dispatchItems()->delete();
            }

            $requisition->items()->delete();
            $requisition->delete();

            // Rebuild running balances so later stock-card entries stay
            // consistent after their upstream issuance rows were removed.
            foreach (array_keys($affectedItemIds) as $itemId) {
                StockCardEntry::recalculateBalancesForItem($itemId);
            }

            // Reverse deployed_quantity on any reservation_items that were
            // consumed by dispatches in this RIS, then recompute the
            // reservation's overall status so it no longer shows as DEPLOYED.
            foreach ($affectedReservItemIds as $resItemId => $reversedQty) {
                $resItem = \App\Models\ReservationItem::find($resItemId);
                if (! $resItem) continue;

                $newDeployed = max(0, (float) $resItem->deployed_quantity - $reversedQty);

                // Revert status based on the corrected deployed quantity
                $newStatus = match (true) {
                    $newDeployed <= 0       => \App\Models\ReservationItem::STATUS_ACTIVE,
                    $newDeployed < (float) $resItem->reserved_quantity - 0.0001
                                            => \App\Models\ReservationItem::STATUS_PARTIALLY_DEPLOYED,
                    default                 => \App\Models\ReservationItem::STATUS_DEPLOYED,
                };

                $resItem->update([
                    'deployed_quantity' => $newDeployed,
                    'status'            => $newStatus,
                ]);

                // Recompute the parent reservation's overall status
                $resItem->reservation?->updateOverallStatus();
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
            'ris_code'      => $requisition->ris_code,
            'ris_number'    => $requisition->ris_number,
            'ris_id'        => $requisition->ris_code,
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
            'ris_number'     => 'required|string|max:255|unique:requisitions,ris_number,' . $requisition->id,
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
            'items.*.quantity_requested' => 'required|integer|min:1',
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
                'ris_number', 'entity_name', 'fund_cluster', 'office', 'division', 'province',
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
                    ->first(['id', 'unit']);
            }

            foreach ($request->items as $idx => $line) {
                $ri     = $existingItems->get((int) $line['id']);
                $oldQty = (float) $ri->quantity_requested;
                $newQty = (int) round((float) $line['quantity_requested']);
                $locked = $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty();

                // Inventory protection: the request can never drop below what is
                // already issued. Resolve an over-issuance via "Edit Dispatch".
                if ($newQty + 0.0001 < $ri->quantity_issued) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity_requested" =>
                            'Requested quantity ('.number_format($newQty).') cannot be less than the '
                            .number_format($ri->quantity_issued).' already issued. '
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
                            // No stock linkage at request stage: allocation
                            // happens only when a dispatch is recorded.
                            'item_id'         => null,
                            'description'     => $catalog->name,
                            'unit'            => $rep?->unit,
                            'account_code'    => $catalog->account_code ?: $catalog->category?->account_code,
                            // Clear any stale issuance caches from older versions
                            'warehouse_id'    => null,
                            'stock_available' => false,
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

            $newTotal = (int) $existingItems->sum('quantity_requested');
            if (abs($newTotal - $oldTotal) > 0.0001) {
                $changes['total_requested'] = ['old' => $oldTotal, 'new' => $newTotal];
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
        $user = Auth::user();

        if (! $user->canApprove()) {
            abort(403);
        }

        // Non-admin approvers can only approve requisitions for their warehouses
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $requisition->load(['items.item', 'items.warehouse', 'items.dispatchItems.item.warehouse']);

        // Warehouses the dispatcher may issue from (their assigned ones, or all
        // active warehouses for admins).
        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        } else {
            $warehouses = $user->warehouses()->where('is_active', true)->orderBy('name')->get();
        }

        return view('requisitions.approve', compact('requisition', 'warehouses'));
    }

    public function processApproval(Request $request, Requisition $requisition)
    {
        $user = Auth::user();

        if (! $user->canApprove()) {
            abort(403);
        }

        // Non-admin approvers can only approve requisitions for their warehouses
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $rules = [
            'items' => 'required|array',
            'items.*.warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'items.*.item_id' => 'nullable|integer|exists:items,id',
            'items.*.quantity_issued' => 'required|integer|min:0',
            'items.*.dr_number' => 'nullable|string|max:100',
            'items.*.engas_unit_cost' => 'nullable|numeric|min:0',
            'items.*.expiration_date' => 'nullable|date',
            'items.*.reservation_item_id' => 'nullable|integer|exists:reservation_items,id',
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

                $wanted = (int) $data['quantity_issued'];
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

                // Check available quantity (physical - reserved).
                // When this dispatch is linked to a specific reservation_item,
                // that reservation's quantity counts as available for this RIS
                // (it was reserved for exactly this purpose). Subtract all OTHER
                // active reservations on this item but add back the reservation
                // being consumed so it is not double-blocked.
                $reservationItemId = ! empty($data['reservation_item_id'])
                    ? (int) $data['reservation_item_id']
                    : null;

                $totalReserved = \App\Models\Reservation::reservedQuantityForItem($item->id);

                if ($reservationItemId) {
                    // Credit back the reserved quantity of the specific reservation
                    // being consumed — it is available to this RIS.
                    $thisReservation = \App\Models\ReservationItem::whereKey($reservationItemId)->first();
                    $creditBack = $thisReservation ? (float) $thisReservation->reserved_quantity : 0;
                    $availableQty = $item->quantity - max(0, $totalReserved - $creditBack);
                } else {
                    $availableQty = $item->quantity - $totalReserved;
                }

                $availableQty = max(0, $availableQty);

                // Reject instead of silently capping or borrowing from another record
                if ($wanted > $availableQty + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.quantity_issued" =>
                            'Insufficient available stock on the selected record "'.$item->description.'"'
                            .' ('.$item->stock_number.' · ₱'.number_format($item->unit_cost, 2).'): '
                            .'physical '.number_format($item->quantity).', '
                            .'reserved '.number_format($totalReserved).', '
                            .'available '.number_format($availableQty).'. '
                            .'No other unit-cost record will be used.',
                    ]);
                }

                if ($wanted > $stillNeeded + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$riItemId}.quantity_issued" =>
                            'Cannot issue more than the outstanding quantity '
                            .'('.number_format($stillNeeded).') for "'.$item->description.'".',
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
                    'reservation_item_id' => ! empty($data['reservation_item_id'])
                                                ? (int) $data['reservation_item_id']
                                                : null,
                ]);

                $item->update(['quantity' => $newItemQty]);

                // If this dispatch is linked to a reservation item, update its
                // deployed quantity and recompute the reservation's overall status.
                if ($dispatch->reservation_item_id) {
                    $resItem = \App\Models\ReservationItem::whereKey($dispatch->reservation_item_id)
                        ->lockForUpdate()
                        ->first();

                    if ($resItem) {
                        $newDeployed = $resItem->deployed_quantity + $wanted;
                        $newResItemStatus = $newDeployed >= $resItem->reserved_quantity - 0.0001
                            ? \App\Models\ReservationItem::STATUS_DEPLOYED
                            : \App\Models\ReservationItem::STATUS_PARTIALLY_DEPLOYED;

                        $resItem->update([
                            'deployed_quantity' => $newDeployed,
                            'status'            => $newResItemStatus,
                        ]);

                        $resItem->reservation->updateOverallStatus();
                    }
                }

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

                // Accumulate the cached quantity_issued and link to the exact
                // stock record. Cost data (unit_cost, engas, expiry, DR) lives
                // exclusively on the dispatch row — never on the RI.
                $riItem->update([
                    'quantity_issued' => $riItem->quantity_issued + $wanted,
                    'item_id'         => $item->id,
                    'stock_available' => $item->quantity >= $riItem->quantity_requested,
                ]);
            }

            // Rebuild running stock-card balances for every item that was issued.
            // processApproval creates entries with inline-computed balances, but if
            // the same item is dispatched in multiple passes (partial fulfilment) the
            // later entries need to account for all previous ones.
            $affectedItemIds = [];
            foreach ($request->items as $riItemId => $data) {
                if ((int) ($data['quantity_issued'] ?? 0) > 0 && ! empty($data['item_id'])) {
                    $affectedItemIds[(int) $data['item_id']] = true;
                }
            }
            foreach (array_keys($affectedItemIds) as $affectedId) {
                StockCardEntry::recalculateBalancesForItem($affectedId);
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
            'stock_records'       => $stockRecords->map(function ($i) use ($dispatch) {
                $reserved     = \App\Models\ReservationItem::reservedQuantityForItem($i->id);
                // When editing an existing dispatch, the quantity already issued
                // by THIS dispatch is temporarily "returned" before comparing —
                // so the current record is always selectable at its current qty.
                $creditBack   = ($i->id === $dispatch->item_id) ? (float) $dispatch->quantity_issued : 0;
                $availableQty = max(0, $i->quantity - $reserved + $creditBack);
                return [
                    'id'              => $i->id,
                    'description'     => $i->description,
                    'unit'            => $i->unit,
                    'quantity'        => $i->quantity,
                    'available_qty'   => $availableQty,
                    'reserved_qty'    => $reserved,
                    'stock_number'    => $i->stock_number,
                    'expiry_date'     => $i->expiration_date?->format('Y-m-d'),
                    'unit_cost'       => $i->unit_cost,
                    'engas_unit_cost' => $i->engas_unit_cost,
                ];
            })->values(),
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
            'quantity_issued' => 'required|integer|min:1',
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
            $newQty      = (int) round((float) $request->quantity_issued);
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
                        .'('.number_format(max(0, $ri->quantity_requested - ($ri->quantity_issued - $oldQty)))
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
                        .'only '.number_format($availableOnRecord).' available. '
                        .'No other unit-cost record will be used.',
                ]);
            }

            $oldItem = $oldItemId === $newItemId
                ? $newItem
                : Item::whereKey($oldItemId)->lockForUpdate()->first();

            // ── Reverse the old deduction, apply the new one ────────────────
            if ($oldItemId === $newItemId) {
                $newItem->update(['quantity' => (int) round((float) $newItem->quantity + $oldQty - $newQty)]);
            } else {
                $oldItem->update(['quantity' => (int) round((float) $oldItem->quantity + $oldQty)]);
                $newItem->update(['quantity' => (int) round((float) $newItem->quantity - $newQty)]);
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
            // Cost data stays exclusively on the dispatch row.
            $ri->update([
                'quantity_issued' => (int) round($lineTotalAfter),
                'item_id'         => $newItemId,
                'stock_available' => (float) $newItem->quantity >= $ri->quantity_requested,
            ]);

            // ── Reverse/adjust deployed_quantity on any linked reservation_item ─
            // If the quantity changed or the item record changed, the reservation's
            // deployed_quantity must reflect the new dispatched amount.
            if ($dispatch->reservation_item_id) {
                $resItem = \App\Models\ReservationItem::whereKey($dispatch->reservation_item_id)
                    ->lockForUpdate()->first();

                if ($resItem) {
                    // delta = newQty - oldQty: positive means more deployed, negative means less
                    $delta       = $newQty - $oldQty;
                    $newDeployed = max(0, (float) $resItem->deployed_quantity + $delta);

                    $newResStatus = match (true) {
                        $newDeployed <= 0                                                 => \App\Models\ReservationItem::STATUS_ACTIVE,
                        $newDeployed < (float) $resItem->reserved_quantity - 0.0001      => \App\Models\ReservationItem::STATUS_PARTIALLY_DEPLOYED,
                        default                                                           => \App\Models\ReservationItem::STATUS_DEPLOYED,
                    };

                    $resItem->update([
                        'deployed_quantity' => $newDeployed,
                        'status'            => $newResStatus,
                    ]);

                    $resItem->reservation?->updateOverallStatus();
                }
            }

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

    /**
     * Admin-only: delete a single dispatched RIS item and reverse exactly the
     * inventory movement that dispatch caused. The quantity returns to the
     * EXACT stock record that was deducted (never a same-name substitute),
     * the linked issuance stock-card entries are removed and running balances
     * are replayed, and the RIS line cache + fulfilment status are recomputed
     * from the remaining dispatches. Requested quantities are never touched.
     */
    public function destroyDispatch(RequisitionDispatchItem $dispatch)
    {
        abort_unless(Auth::user()->canWrite(), 403, 'Only administrators can delete dispatched items.');

        $dispatch->load(['requisitionItem.requisition', 'item']);

        $requisitionItem = $dispatch->requisitionItem;
        abort_unless($requisitionItem && $requisitionItem->requisition, 404, 'The dispatch is not linked to a valid requisition.');

        $requisition = $requisitionItem->requisition;
        $itemId      = $dispatch->item_id;
        $qty         = (float) $dispatch->quantity_issued;

        // Snapshot for the audit trail BEFORE anything is deleted.
        $audit = [
            'dispatch_id'     => $dispatch->id,
            'requisition_item_id' => $requisitionItem->id,
            'item_id'         => $itemId,
            'description'     => $dispatch->item->description ?? ($requisitionItem->description ?? '—'),
            'stock_number'    => $dispatch->item->stock_number ?? null,
            'warehouse'       => $dispatch->item?->warehouse?->name,
            'quantity_issued' => $qty,
            'unit_cost'       => round((float) $dispatch->unit_cost, 2),
            'engas_unit_cost' => $dispatch->engas_unit_cost !== null ? round((float) $dispatch->engas_unit_cost, 2) : null,
            'expiration_date' => $dispatch->expiration_date?->toDateString(),
            'dr_number'       => $dispatch->dr_number,
            'restored_to_stock_record' => true,
        ];

        try {
            DB::transaction(function () use ($dispatch, $requisition, $itemId, $qty, $audit) {

                // 1) Restore the quantity to the EXACT stock record that was
                //    deducted. Explicit update() — not increment() — so the
                //    Item saving hook can re-activate a zeroed-out record.
                if ($itemId && ($item = Item::whereKey($itemId)->lockForUpdate()->first())) {
                    $item->update(['quantity' => (int) round((float) $item->quantity + $qty)]);
                }

                // 2) Remove THIS dispatch's issuance entries from the stock card
                //    BEFORE deleting the dispatch row (the FK keeps orphans
                //    otherwise), then replay the running balances of the
                //    affected record so later rows stay consistent. Includes a
                //    legacy fallback for entries created before dispatch linking.
                $deleted = StockCardEntry::where('dispatch_item_id', $dispatch->id)->delete();
                if ($deleted === 0 && $itemId) {
                    StockCardEntry::where('reference_type', 'issuance')
                        ->where('reference_id', $requisition->id)
                        ->where('item_id', $itemId)
                        ->whereNull('dispatch_item_id')
                        ->delete();
                }
                if ($itemId) {
                    StockCardEntry::recalculateBalancesForItem((int) $itemId);
                }

                // 3) Audit trail written before the row disappears.
                RequisitionAuditLog::create([
                    'requisition_id' => $requisition->id,
                    'user_id'        => Auth::user()->id,
                    'action'         => 'dispatch_deleted',
                    'changed_fields' => $audit,
                ]);

                // 4) Delete the dispatched item itself.
                $dispatch->delete();

                // 5) If this dispatch was linked to a reservation item, reverse
                //    the deployed_quantity and recompute the reservation status
                //    so it no longer incorrectly shows as DEPLOYED.
                if ($dispatch->reservation_item_id) {
                    $resItem = \App\Models\ReservationItem::find($dispatch->reservation_item_id);
                    if ($resItem) {
                        $newDeployed = max(0, (float) $resItem->deployed_quantity - $qty);

                        $newStatus = match (true) {
                            $newDeployed <= 0 => \App\Models\ReservationItem::STATUS_ACTIVE,
                            $newDeployed < (float) $resItem->reserved_quantity - 0.0001
                                              => \App\Models\ReservationItem::STATUS_PARTIALLY_DEPLOYED,
                            default           => \App\Models\ReservationItem::STATUS_DEPLOYED,
                        };

                        $resItem->update([
                            'deployed_quantity' => $newDeployed,
                            'status'            => $newStatus,
                        ]);

                        $resItem->reservation?->updateOverallStatus();
                    }
                }

                // 6) Recompute the line caches + fulfilment status from the
                //    REMAINING dispatch sums only. Requested quantities and
                //    every other stock record stay untouched.
                $requisition->load('items');
                $requisition->updateFulfilmentStatus();
            });
        } catch (\Throwable $e) {
            Log::error('Dispatch deletion failed', [
                'dispatch_id' => $dispatch->id,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => ['general' => ['The transaction could not be completed. No changes were made. Please try again.']],
            ], 500);
        }

        return response()->json(['redirect' => route('requisitions.show', $requisition->id)]);
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
        // Signatories may only be updated by users who can approve (admin, warehouse
        // manager, center_head, supply_custodian). center_staff are explicitly excluded.
        abort_unless($user->canApprove(), 403, 'You do not have permission to update signatories.');
        abort_unless($this->userCanAccessRequisition($user, $requisition), 403);

        $request->validate([
            'requested_by_name'        => 'nullable|string|max:255',
            'requested_by_designation' => 'nullable|string|max:255',
            'approved_by_name'         => 'nullable|string|max:255',
            'approved_by_designation'  => 'nullable|string|max:255',
            'issued_by_name'           => 'nullable|string|max:255',
            'issued_by_designation'    => 'nullable|string|max:255',
            'received_by_name'         => 'nullable|string|max:255',
            'received_by_designation'  => 'nullable|string|max:255',
        ]);

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
