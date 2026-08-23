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
        'engas_unit_cost',
        'engas_total_cost',
        'condition',
        'warehouse_id',      // the warehouse this dispatch delivered stock into
        'dr_number',         // this dispatched item's own Delivery Receipt number
    ];

    protected $casts = [
        'quantity_delivered' => 'integer',
        'unit_cost'          => 'float',
        'engas_unit_cost'    => 'float',
        'engas_total_cost'   => 'float',
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

    /**
     * ENGAS total for THIS dispatch, always derived from its own delivered
     * quantity × its own ENGAS unit cost.
     *
     * The stored `engas_total_cost` column is a write-time snapshot; legacy
     * rows may hold NULL or stale values (e.g. after older edits). Deriving
     * here keeps every shipment row, per-item cumulative total and shipment
     * grand total consistent without rewriting historical records.
     */
    public function getEngasTotalValueAttribute(): ?float
    {
        if ($this->engas_unit_cost === null) {
            return null;
        }

        return round((float) $this->quantity_delivered * (float) $this->engas_unit_cost, 2);
    }

    public function deliverySubsidyItem()
    {
        return $this->belongsTo(DeliverySubsidyItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
