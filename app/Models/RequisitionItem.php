<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionItem extends Model
{
    protected $fillable = ['requisition_id', 'item_id', 'quantity_requested', 'quantity_issued', 'stock_available', 'remarks', 'unit_cost', 'expiration_date'];
    protected $casts = ['quantity_requested' => 'float', 'quantity_issued' => 'float', 'stock_available' => 'boolean', 'unit_cost' => 'float', 'expiration_date' => 'date'];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
