<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    protected $fillable = [
        'delivery_subsidy_id',
        'dr_number',          // this shipment's Delivery Receipt number
        'received_by',
        'delivery_date',
        'batch_number',
        'condition_status',
        'quantity_delivered', // how much arrived in this shipment
        'remarks',
    ];

    protected $casts = [
        'delivery_date'      => 'date',
        'quantity_delivered' => 'float',
    ];

    public function deliverySubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
