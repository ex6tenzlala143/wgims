<?php

namespace App\Services;

use App\Models\DeliverySubsidyAuditLog;
use App\Models\Item;
use App\Models\RequisitionItem;
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
     * Cascade a unit-cost change for a single DeliverySubsidyItem line.
     *
     * Touches:
     *   - source item.unit_cost
     *   - delivery stock card entries (receipt_unit_cost, balance_unit_cost)
     *   - all items in the transfer chain (unit_cost)
     *   - transfer_in / transfer_out stock card entries in the chain
     *   - RequisitionItems tied to any item in the chain
     *
     * @param  Item   $sourceItem   The item directly linked to the DSI
     * @param  float  $newCost
     * @param  array  &$summary     Accumulates affected record counts for audit
     */
    public function cascadeUnitCost(Item $sourceItem, float $newCost, array &$summary): void
    {
        // 1. Update the source item
        $sourceItem->update(['unit_cost' => $newCost]);
        $summary['items_updated'][] = $sourceItem->id;

        // 2. Delivery stock card entries for the source item
        $updated = StockCardEntry::where('item_id', $sourceItem->id)
            ->where('reference_type', 'delivery')
            ->update([
                'receipt_unit_cost' => $newCost,
                'balance_unit_cost' => $newCost,
            ]);
        $summary['stock_card_delivery_rows'] = ($summary['stock_card_delivery_rows'] ?? 0) + $updated;

        // 3. Requisition items tied to source item
        $updated = RequisitionItem::where('item_id', $sourceItem->id)
            ->update(['unit_cost' => $newCost]);
        $summary['requisition_rows'] = ($summary['requisition_rows'] ?? 0) + $updated;

        // 4. Walk the full transfer chain recursively
        $this->walkTransferChain($sourceItem->id, $newCost, $summary, []);
    }

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

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recursively walk the transfer chain from $sourceItemId and apply the
     * new unit cost to every destination item and its stock card entries.
     *
     * @param  int    $sourceItemId   Current node in the chain
     * @param  float  $newCost
     * @param  array  &$summary
     * @param  array  $visited        Guard against cycles (should not occur in practice)
     */
    private function walkTransferChain(int $sourceItemId, float $newCost, array &$summary, array $visited): void
    {
        if (in_array($sourceItemId, $visited, true)) {
            return; // cycle guard
        }
        $visited[] = $sourceItemId;

        // Find all transfer items where this item is the source
        $transferItems = StockTransferItem::with(['destinationItem', 'transfer'])
            ->where('item_id', $sourceItemId)
            ->get();

        foreach ($transferItems as $sti) {
            $destItem = $sti->destinationItem;
            if (! $destItem) {
                continue;
            }

            // Update destination item unit cost
            $destItem->update(['unit_cost' => $newCost]);
            $summary['items_updated'][] = $destItem->id;

            // Update StockTransferItem unit_cost
            $sti->update(['unit_cost' => $newCost]);

            // Update transfer_out entry at source warehouse
            $outUpdated = StockCardEntry::where('reference_type', 'transfer_out')
                ->where('reference_id', $sti->stock_transfer_id)
                ->where('item_id', $sourceItemId)
                ->update([
                    'balance_unit_cost'  => $newCost,
                    'balance_total_cost' => DB::raw('balance_qty * ' . (float) $newCost),
                ]);
            $summary['stock_card_transfer_rows'] = ($summary['stock_card_transfer_rows'] ?? 0) + $outUpdated;

            // Update transfer_in entry at destination warehouse
            $inUpdated = StockCardEntry::where('reference_type', 'transfer_in')
                ->where('reference_id', $sti->stock_transfer_id)
                ->where('item_id', $destItem->id)
                ->update([
                    'receipt_unit_cost'  => $newCost,
                    'receipt_total_cost' => DB::raw('receipt_qty * ' . (float) $newCost),
                    'balance_unit_cost'  => $newCost,
                    'balance_total_cost' => DB::raw('balance_qty * ' . (float) $newCost),
                ]);
            $summary['stock_card_transfer_rows'] = ($summary['stock_card_transfer_rows'] ?? 0) + $inUpdated;

            // Update requisition items at destination
            $rqUpdated = RequisitionItem::where('item_id', $destItem->id)
                ->update(['unit_cost' => $newCost]);
            $summary['requisition_rows'] = ($summary['requisition_rows'] ?? 0) + $rqUpdated;

            // Recurse: destination item may itself have been transferred further
            $this->walkTransferChain($destItem->id, $newCost, $summary, $visited);
        }
    }

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
     */
    public function recordAudit(int $deliverySubsidyId, array $changedFields, array $cascadeSummary): void
    {
        if (empty($changedFields)) {
            return;
        }

        DeliverySubsidyAuditLog::create([
            'delivery_subsidy_id' => $deliverySubsidyId,
            'user_id'             => Auth::user()->id,
            'action'              => 'update',
            'changed_fields'      => $changedFields,
            'cascade_summary'     => $cascadeSummary ?: null,
        ]);
    }
}
