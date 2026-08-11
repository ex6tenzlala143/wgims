<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionDispatchItem extends Model
{
    protected $fillable = [
        'requisition_item_id', 'item_id', 'quantity_issued',
        'unit_cost', 'engas_unit_cost', 'expiration_date', 'dr_number', 'created_by',
    ];

    protected $casts = [
        'quantity_issued'  => 'float',
        'unit_cost'        => 'float',
        'engas_unit_cost'  => 'float',
        'expiration_date'  => 'date',
    ];

    public function requisitionItem()
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    /**
     * The exact warehouse-specific stock record this dispatch came from.
     * Its warehouse (items.warehouse_id) determines the dispatch's warehouse.
     */
    public function item()
    {
        return $this->belongsTo(Item::class)->with('warehouse');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
