<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'item_id',
        'destination_item_id',
        'quantity',
        'quantity_requested',
        'unit_cost',
    ];

    protected $casts = [
        'quantity'           => 'float',
        'quantity_requested' => 'float',
        'unit_cost'          => 'float',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function destinationItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'destination_item_id');
    }
}
