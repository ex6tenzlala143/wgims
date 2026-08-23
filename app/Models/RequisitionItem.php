<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    protected $fillable = ['requisition_id', 'catalog_item_id', 'item_id', 'description', 'unit', 'account_code', 'warehouse_id', 'quantity_requested', 'quantity_issued', 'stock_available', 'remarks', 'unit_cost', 'engas_unit_cost', 'expiration_date', 'dr_number'];
    protected $casts = ['quantity_requested' => 'integer', 'quantity_issued' => 'integer', 'stock_available' => 'boolean', 'unit_cost' => 'float', 'engas_unit_cost' => 'float', 'expiration_date' => 'date'];

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
