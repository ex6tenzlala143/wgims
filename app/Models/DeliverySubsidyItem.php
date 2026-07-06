<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySubsidyItem extends Model
{
    protected $fillable = ['delivery_subsidy_id', 'item_id', 'quantity', 'unit_cost', 'amount', 'qty_delivered'];
    protected $casts = ['quantity' => 'float', 'unit_cost' => 'float', 'amount' => 'float', 'qty_delivered' => 'float'];

    public function deliverySubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function deliveryItems()
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
