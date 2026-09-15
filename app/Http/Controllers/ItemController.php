<?php

namespace App\Http\Controllers;

use App\Models\DeliveryItem;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\StockCardEntry;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $user  = Auth::user();

        // Admins can see all items including inactive (out-of-stock) ones.
        // Non-admins only see active items.
        // Eager-load sourceSubsidy: the index shows its RIS/DR/code per row.
        $query = Item::with(['warehouse', 'sourceSubsidy']);

        if ($user->hasAdminAccess() || $user->isDeliveryUpdater()) {
            // No is_active filter for admins/managers — they see everything including out-of-stock
        } else {
            $query->where('is_active', true);

            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id);

            if ($user->warehouse_id && ! $assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }

            $assignedIds = $assignedIds->unique()->values();

            $warehouses = Warehouse::whereIn('id', $assignedIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            if ($assignedIds->isEmpty()) {
                $items = $query->whereRaw('1 = 0')->paginate(20);
                return view('items.index', compact('items', 'warehouses'));
            }

            $query->whereIn('warehouse_id', $assignedIds);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('description', 'like', '%'.$request->search.'%')
                    ->orWhere('stock_number', 'like', '%'.$request->search.'%')
                    ->orWhere('ris_number', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->category) {
            $query->where('category', $request->category);
        }

        if ($request->warehouse_id) {
            if ($user->hasAdminAccess() || $user->isDeliveryUpdater() || $user->hasWarehouse((int) $request->warehouse_id)) {
                $query->where('warehouse_id', $request->warehouse_id);
            }
        }

        // Stock status filter
        switch ($request->stock_status) {
            case 'out_of_stock':
                $query->where('quantity', '<=', 0);
                break;
            case 'in_stock':
                $query->where('quantity', '>', 0)->where('is_active', true);
                break;
            case 'low_stock':
                $query->where('quantity', '>', 0)
                      ->whereColumn('quantity', '<=', 'reorder_point')
                      ->where('reorder_point', '>', 0);
                break;
        }

        // Source subsidy filter (deleted / active)
        if (in_array($request->source_subsidy_status, ['deleted', 'active'], true)) {
            $query->where('source_subsidy_status', $request->source_subsidy_status);
        }

        // Fetch all items individually — no merging
        $items = $query->orderBy('is_active', 'desc')
                          ->orderBy('quantity', 'desc')
                          ->orderBy('description')
                          ->paginate(20);

        // One grouped query for the per-row reserved badges (same
        // remaining-based semantics as ReservationItem::reservedQuantityForItem).
        $reservedMap = ReservationItem::whereIn('item_id', $items->pluck('id')->all())
            ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(GREATEST(reserved_quantity - deployed_quantity, 0)) as locked')
            ->pluck('locked', 'item_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        if (! isset($warehouses)) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        }
        return view('items.index', compact('items', 'warehouses', 'reservedMap'));
    }

    public function show(Item $item)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess() && ! $user->isDeliveryUpdater() && ! $user->hasWarehouse((int) $item->warehouse_id)) {
            abort(403);
        }

        $item->load('warehouse', 'stockCardEntries');

        return view('items.show', compact('item'));
    }

    /**
     * Controlled cleanup of an orphaned inventory record.
     *
     * NORMAL RULE: items are view-only — deletion is NOT allowed.
     * EXCEPTION: an ADMIN may delete an item ONLY when its originating
     * subsidy (traced via the stock-lineage Subsidy ID, never by name /
     * description / quantity / stock number / RIS No.) has already been
     * deleted AND the item has no remaining transaction dependencies.
     */
    public function destroy(Item $item)
    {
        // Layer 1 — role: admin only (route middleware `admin.write` is the
        // first layer; this is the backend enforcement for direct API calls).
        abort_unless(Auth::user()->isAdmin(), 403);

        // Layer 2 — origin trail: the item must carry an identifiable
        // originating-subsidy marker left by the subsidy-deletion flow.
        if (! $item->isRelatedToDeletedSubsidy()) {
            return $this->rejectItemDelete($item, 'This item cannot be deleted because its originating subsidy still exists. Items are view-only; only orphaned records from an already-deleted subsidy may be removed by an administrator.');
        }

        // Layer 3 — origin gone: the subsidy row itself must no longer exist.
        // The FK is nullOnDelete, so verify by ID when present, otherwise by
        // the permanent subsidy code (SUB-######, unique and never reused).
        if (! $this->originatingSubsidyIsGone($item)) {
            return $this->rejectItemDelete($item, 'This item cannot be deleted because its originating subsidy still exists. Items are view-only; only orphaned records from an already-deleted subsidy may be removed by an administrator.');
        }

        // Layer 4 — dependency safety: never erase history still required by
        // another active transaction.
        $blockers = $this->itemDeletionBlockers($item);
        if (! empty($blockers)) {
            return $this->rejectItemDelete($item, 'This orphaned item cannot be deleted because it is still referenced by: ' . implode('; ', array_unique($blockers)) . '. Resolve those records first.');
        }

        try {
            DB::transaction(function () use ($item) {
                // Delete ONLY this orphaned item row. All dependency checks
                // above passed, so no cascade can pull in other transactions:
                // FKs to deliveries, subsidy lines, requisitions, dispatches,
                // transfers, reservations and stock cards were all verified
                // empty for this item id.
                $item->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Orphaned item deletion failed', [
                'item_id' => $item->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->rejectItemDelete($item, 'The item could not be deleted. No changes were made. Please try again.');
        }

        if (request()->expectsJson()) {
            return response()->json(['success' => 'Orphaned item deleted.']);
        }

        return redirect()->route('items.index')->with('success', "Orphaned item '{$item->description}' was permanently deleted. No other inventory was touched.");
    }

    /**
     * True when the item's originating subsidy row is verifiably gone.
     * Uses the Subsidy ID when still present, otherwise the permanent
     * subsidy-code snapshot (unique, never re-issued to another subsidy).
     */
    protected function originatingSubsidyIsGone(Item $item): bool
    {
        if ($item->source_subsidy_id) {
            return ! DeliverySubsidy::whereKey($item->source_subsidy_id)->exists();
        }

        $code = trim((string) ($item->source_subsidy_code ?? ''));
        if ($code === '') {
            // No identifiable originating Subsidy ID — cannot prove deletion.
            return false;
        }

        return ! DeliverySubsidy::where('subsidy_code', $code)->exists();
    }

    /**
     * Active transaction dependencies that forbid deleting the item.
     * Mirrors the reference sweep used by the subsidy-deletion flow so the
     * manual cleanup can never erase history the automatic flow preserves.
     * Returns human-readable blocker notes (empty = safe to delete).
     */
    protected function itemDeletionBlockers(Item $item): array
    {
        $blockers = [];
        $id = $item->id;

        if (DeliveryItem::where('item_id', $id)->exists()) {
            $blockers[] = 'delivery shipment lines';
        }
        if (DeliverySubsidyItem::where('item_id', $id)->exists()) {
            $blockers[] = 'subsidy order lines';
        }
        if (RequisitionItem::where('item_id', $id)->exists()) {
            $blockers[] = 'requisition (RIS) request lines';
        }
        if (RequisitionDispatchItem::where('item_id', $id)->exists()) {
            $blockers[] = 'RIS issuance (dispatch) records';
        }
        if (StockTransferItem::where('item_id', $id)->orWhere('destination_item_id', $id)->exists()) {
            $blockers[] = 'stock transfer (augmentation) records';
        }
        if (ReservationItem::where('item_id', $id)->exists()
            || (\Illuminate\Support\Facades\Schema::hasColumn('reservations', 'item_id')
                && Reservation::where('item_id', $id)->exists())) {
            $blockers[] = 'reservation records';
        }
        if (StockCardEntry::where('item_id', $id)->exists()) {
            $blockers[] = 'stock card entries';
        }

        return $blockers;
    }

    protected function rejectItemDelete(Item $item, string $message)
    {
        if (request()->expectsJson()) {
            return response()->json(['errors' => ['item' => [$message]]], 422);
        }

        return back()->with('error', $message);
    }
}
