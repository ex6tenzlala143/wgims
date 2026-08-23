<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCardEntry extends Model
{
    protected $fillable = [
        'item_id', 'entry_date', 'reference', 'reference_type', 'reference_id',
        'dispatch_item_id',
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
     *
     * Wrapped in a DB transaction so an interruption mid-way never leaves
     * partially-updated balances. Uses a bulk update per entry rather than
     * individual ->update() calls to reduce query overhead on long histories.
     */
    public static function recalculateBalancesForItem(int $itemId): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($itemId) {
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

                // Use a direct query update to avoid the model's event overhead
                // and ensure the balance is written atomically within the transaction.
                static::whereKey($entry->id)->update([
                    'balance_qty'        => round($runningQty, 4),
                    'balance_unit_cost'  => $runningUnitCost,
                    'balance_total_cost' => round($runningQty * $runningUnitCost, 2),
                ]);
            }
        });
    }
}
