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
}
