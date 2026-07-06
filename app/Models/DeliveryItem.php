<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryItem extends Model
{
    protected $fillable = [
        'delivery_id',
        'delivery_subsidy_item_id',
        'item_id',
        'quantity_delivered', // per-item qty for stock card purposes
        'unit_cost',
        'condition',
    ];

    protected $casts = [
        'quantity_delivered' => 'float',
        'unit_cost'          => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Default quantity_delivered to the parent Delivery's quantity_delivered
         * when it is not explicitly set (or is zero).
         * This covers the single-line-item case where the per-item qty should
         * automatically match the shipment-level qty.
         */
        static::creating(function (DeliveryItem $item): void {
            if (empty($item->quantity_delivered) || $item->quantity_delivered <= 0) {
                $delivery = $item->delivery
                    ?? ($item->delivery_id ? Delivery::find($item->delivery_id) : null);

                if ($delivery && $delivery->quantity_delivered > 0) {
                    $item->quantity_delivered = $delivery->quantity_delivered;
                }
            }
        });
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function deliverySubsidyItem()
    {
        return $this->belongsTo(DeliverySubsidyItem::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
