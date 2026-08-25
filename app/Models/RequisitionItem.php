<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    // Stock-specific fields (unit_cost, engas_unit_cost, expiration_date, dr_number)
    // are stored ONLY on requisition_dispatch_items — never on requisition_items.
    // A requisition item represents a REQUEST, not a dispatch allocation.
    protected $fillable = ['requisition_id', 'catalog_item_id', 'item_id', 'description', 'unit', 'account_code', 'warehouse_id', 'quantity_requested', 'quantity_issued', 'stock_available', 'remarks'];
    protected $casts = ['quantity_requested' => 'float', 'quantity_issued' => 'float', 'stock_available' => 'boolean'];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(ItemCatalogItem::class, 'catalog_item_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function dispatchItems()
    {
        return $this->hasMany(RequisitionDispatchItem::class);
    }
}
