<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySubsidyItem extends Model
{
    protected $fillable = [
        'delivery_subsidy_id', 'item_id', 'warehouse_id', 'quantity',
        'unit_cost', 'amount', 'qty_delivered',
        'description', 'unit', 'category', 'catalog_item_id', 'account_code',
        'expiration_date',
    ];
    protected $casts = [
        'quantity' => 'float', 'unit_cost' => 'float', 'amount' => 'float',
        'qty_delivered' => 'float', 'expiration_date' => 'date',
    ];

    public function deliverySubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    /**
     * The warehouse this line item is destined for. Each item in a delivery
     * may be assigned to a different warehouse.
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(ItemCatalogItem::class);
    }

    public function deliveryItems()
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
