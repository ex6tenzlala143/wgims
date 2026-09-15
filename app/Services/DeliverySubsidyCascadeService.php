<?php

namespace App\Services;

use App\Models\DeliverySubsidyAuditLog;
use App\Models\Item;
use App\Models\RequisitionDispatchItem;
use App\Models\ReservationItem;
use App\Models\StockCardEntry;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Handles all cascading updates when a Delivery-Subsidy record is edited.
 *
 * Transfer-chain traversal:
 *   A source item (from a delivery) may have been transferred to a destination
 *   warehouse.  That destination item may itself have been transferred again.
 *   This service walks the full chain recursively to ensure every downstream
 *   item and stock-card entry is kept in sync.
 */
class DeliverySubsidyCascadeService
{
    /**
     * Cascade a RIS-number change to an item and the full transfer chain.
     *
     * @param  Item    $sourceItem
     * @param  string  $newRisNumber
     * @param  array   &$summary
     */
    public function cascadeRisNumber(Item $sourceItem, string $newRisNumber, array &$summary): void
    {
        $sourceItem->update(['ris_number' => $newRisNumber]);
        $summary['ris_items_updated'][] = $sourceItem->id;

        $this->walkTransferChainRis($sourceItem->id, $newRisNumber, $summary, []);
    }

    /**
     * Cascade a supplier name change to all delivery stock card entries tied to
     * the given delivery IDs, and to any transfer stock card entries for items
     * in those transfers.
     *
     * @param  \Illuminate\Support\Collection  $deliveryIds
     * @param  string                          $newSupplierName
     * @param  array                           &$summary
     */
    public function cascadeSupplierName(Collection $deliveryIds, string $newSupplierName, array &$summary): void
    {
        if ($deliveryIds->isEmpty()) {
            return;
        }

        $updated = StockCardEntry::whereIn('reference_id', $deliveryIds)
            ->where('reference_type', 'delivery')
            ->update(['from_to' => $newSupplierName]);
        $summary['supplier_stock_card_rows'] = ($summary['supplier_stock_card_rows'] ?? 0) + $updated;
    }

    /**
     * Cascade a unit-cost / ENGAS change to every snapshot that references the
     * given item, then walk the full transfer chain so downstream items stay in
     * sync. The source item itself is NOT touched here — the caller already
     * updated it.
     *
     * Touches:
     *   - RequisitionDispatchItems tied to the item (unit_cost, engas_unit_cost)
     *     (requisition_items has no cost columns since 2026_08_24_210730)
     *   - StockTransferItems where the item is the source (unit_cost)
     *   - ReservationItems locking the item (unit_cost, engas_unit_cost when
     *     provided) so reservation displays never show stale costs
     *   - every destination item in the transfer chain (unit_cost,
     *     engas_unit_cost when provided) plus their snapshot rows, recursively
     *
     * @param  Item         $sourceItem  The item that now holds the stock
     * @param  float        $newCost
     * @param  float|null   $newEngas    null leaves existing ENGAS values untouched
     * @param  array        &$summary    Accumulates affected record counts for audit
     */
    public function cascadeItemCost(Item $sourceItem, float $newCost, ?float $newEngas, array &$summary): void
    {
        // Wrap the full cascade in a transaction so a partial failure (e.g. during
        // a multi-hop transfer chain walk) does not leave costs inconsistent across
        // requisition_items, dispatch_items, transfer_items and downstream item records.
        DB::transaction(function () use ($sourceItem, $newCost, $newEngas, &$summary) {
            $this->applyCostToItemSnapshots($sourceItem, $newCost, $newEngas, $summary);
            $this->walkTransferChainCost($sourceItem->id, $newCost, $newEngas, $summary, []);
        });
    }

    /**
     * Apply a cost change to the requisition / dispatch / transfer snapshot rows
     * that reference one item (the item record itself is not touched).
     *
     * Note: requisition_items no longer stores unit_cost / engas_unit_cost
     * (those columns were dropped in migration 2026_08_24_210730). Cost data
     * lives exclusively on requisition_dispatch_items.
     */
    private function applyCostToItemSnapshots(Item $item, float $newCost, ?float $newEngas, array &$summary): void
    {
        $snapshotUpdate = ['unit_cost' => $newCost];
        if ($newEngas !== null) {
            $snapshotUpdate['engas_unit_cost'] = $newEngas;
        }

        // requisition_items: no cost columns since migration 2026_08_24_210730.
        // Cost data lives exclusively on requisition_dispatch_items.
        $summary['requisition_rows'] = ($summary['requisition_rows'] ?? 0);

        $updated = RequisitionDispatchItem::where('item_id', $item->id)->update($snapshotUpdate);
        $summary['requisition_dispatch_rows'] = ($summary['requisition_dispatch_rows'] ?? 0) + $updated;

        $updated = StockTransferItem::where('item_id', $item->id)->update(['unit_cost' => $newCost]);
        $summary['transfer_rows'] = ($summary['transfer_rows'] ?? 0) + $updated;

        // Reservations hold cost snapshots of the exact locked stock record.
        // A corrected subsidy cost must flow here too (qty locks untouched).
        $updated = ReservationItem::where('item_id', $item->id)->update($snapshotUpdate);
        $summary['reservation_rows'] = ($summary['reservation_rows'] ?? 0) + $updated;
    }

    /**
     * Recursively walk the transfer chain from $sourceItemId and apply the new
     * unit cost / ENGAS to every destination item and its snapshot rows.
     *
     * @param  int     $sourceItemId   Current node in the chain
     * @param  float   $newCost
     * @param  float|null  $newEngas
     * @param  array   &$summary
     * @param  array   $visited        Guard against cycles (should not occur in practice)
     */
    private function walkTransferChainCost(int $sourceItemId, float $newCost, ?float $newEngas, array &$summary, array $visited): void
    {
        if (in_array($sourceItemId, $visited, true)) {
            return; // cycle guard
        }
        $visited[] = $sourceItemId;

        $transferItems = StockTransferItem::with(['destinationItem', 'transfer'])
            ->where('item_id', $sourceItemId)
            ->get();

        foreach ($transferItems as $sti) {
            $destItem = $sti->destinationItem;
            if (! $destItem) {
                continue;
            }

            $destUpdate = ['unit_cost' => $newCost];
            if ($newEngas !== null) {
                $destUpdate['engas_unit_cost'] = $newEngas;
            }
            $destItem->update($destUpdate);
            $summary['items_updated'][] = $destItem->id;

            $sti->update(['unit_cost' => $newCost]);

            $this->applyCostToItemSnapshots($destItem, $newCost, $newEngas, $summary);

            // Recurse: destination item may itself have been transferred further
            $this->walkTransferChainCost($destItem->id, $newCost, $newEngas, $summary, $visited);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recursively walk the transfer chain and cascade a RIS number update.
     */
    private function walkTransferChainRis(int $sourceItemId, string $newRisNumber, array &$summary, array $visited): void
    {
        if (in_array($sourceItemId, $visited, true)) {
            return;
        }
        $visited[] = $sourceItemId;

        $transferItems = StockTransferItem::with('destinationItem')
            ->where('item_id', $sourceItemId)
            ->get();

        foreach ($transferItems as $sti) {
            $destItem = $sti->destinationItem;
            if (! $destItem) {
                continue;
            }

            $destItem->update(['ris_number' => $newRisNumber]);
            $summary['ris_items_updated'][] = $destItem->id;

            // Recurse
            $this->walkTransferChainRis($destItem->id, $newRisNumber, $summary, $visited);
        }
    }

    /**
     * Record an audit log entry for a delivery-subsidy edit.
     *
     * @param  int    $deliverySubsidyId
     * @param  array  $changedFields   [ 'field_name' => ['old' => x, 'new' => y], ... ]
     * @param  array  $cascadeSummary  Counts of downstream rows touched
     * @param  string $action          Audit action label (default 'update')
     */
    public function recordAudit(int $deliverySubsidyId, array $changedFields, array $cascadeSummary, string $action = 'update'): void
    {
        if (empty($changedFields)) {
            return;
        }

        DeliverySubsidyAuditLog::create([
            'delivery_subsidy_id' => $deliverySubsidyId,
            'user_id'             => Auth::user()->id,
            'action'              => $action,
            'changed_fields'      => $changedFields,
            'cascade_summary'     => $cascadeSummary ?: null,
        ]);
    }
}
