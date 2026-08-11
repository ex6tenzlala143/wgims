<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCardEntry extends Model
{
    protected $fillable = [
        'item_id', 'entry_date', 'reference', 'reference_type', 'reference_id',
        'receipt_qty', 'receipt_unit_cost', 'receipt_total_cost',
        'issue_qty', 'balance_qty', 'balance_unit_cost', 'balance_total_cost',
        'no_of_days_to_consume', 'from_to',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'receipt_qty' => 'float', 'receipt_unit_cost' => 'float', 'receipt_total_cost' => 'float',
        'issue_qty' => 'float', 'balance_qty' => 'float', 'balance_unit_cost' => 'float', 'balance_total_cost' => 'float',
    ];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Recompute the running balance columns for every stock-card entry of one
     * item, in chronological order. Receipts add stock and set the running unit
     * cost; issues subtract stock. Stored balances are refreshed so that editing
     * or deleting an older entry never leaves later entries stale.
     */
    public static function recalculateBalancesForItem(int $itemId): void
    {
        $entries = static::where('item_id', $itemId)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $runningQty      = 0.0;
        $runningUnitCost = 0.0;

        foreach ($entries as $entry) {
            $runningQty += (float) $entry->receipt_qty - (float) $entry->issue_qty;

            if ((float) $entry->receipt_qty > 0 && (float) $entry->receipt_unit_cost > 0) {
                $runningUnitCost = (float) $entry->receipt_unit_cost;
            }

            $entry->update([
                'balance_qty'        => round($runningQty, 4),
                'balance_unit_cost'  => $runningUnitCost,
                'balance_total_cost' => round($runningQty * $runningUnitCost, 2),
            ]);
        }
    }
}
