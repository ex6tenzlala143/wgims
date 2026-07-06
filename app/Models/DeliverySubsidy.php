<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySubsidy extends Model
{
    protected $fillable = [
        'dr_number', 'supplier_id', 'warehouse_id', 'created_by', 'date',
        'ris_number', 'place_of_delivery', 'date_of_delivery',
        'date_of_expiration', 'total_amount', 'quantity_requested', 'status', 'remarks',
    ];

    protected $casts = [
        'date'               => 'date',
        'date_of_delivery'   => 'date',
        'total_amount'       => 'float',
        'quantity_requested' => 'float',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(DeliverySubsidyItem::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(\App\Models\DeliverySubsidyAuditLog::class)->latest();
    }

    /**
     * Sum of quantity_delivered across all shipments for this request.
     */
    public function totalDelivered(): float
    {
        return (float) $this->deliveries()->sum('quantity_delivered');
    }

    /**
     * Recalculate and persist the delivery/subsidy status based on
     * per-line-item completion, not just the aggregate total.
     *
     * Rules:
     *   No items have any qty_delivered                    → pending
     *   Every line has qty_delivered >= quantity           → fully_delivered
     *   At least one line has qty_delivered > 0 but not
     *   every line is fully delivered                      → partial
     *
     * Using per-item checks prevents a situation where one item type
     * (e.g. food packs) is fully delivered while another (e.g. sleeping kits)
     * is still pending — which would incorrectly mark the whole RIS complete
     * when only the aggregate totals are compared.
     *
     * Always call this after recording or editing a delivery.
     * It queries the DB fresh so it is never affected by stale in-memory state.
     */
    public function updateDeliveryStatus(): void
    {
        $epsilon = 0.0001;

        // Fresh per-line data — never rely on loaded relations
        $lines = $this->items()->get(['quantity', 'qty_delivered']);

        if ($lines->isEmpty()) {
            $status = 'pending';
        } else {
            $anyDelivered  = $lines->contains(fn ($l) => (float) $l->qty_delivered > $epsilon);
            $allFulfilled  = $lines->every(
                fn ($l) => (float) $l->qty_delivered >= (float) $l->quantity - $epsilon
            );

            if (! $anyDelivered) {
                $status = 'pending';
            } elseif ($allFulfilled) {
                $status = 'fully_delivered';
            } else {
                $status = 'partial';
            }
        }

        // Use query builder to avoid triggering the saving hook recursively
        static::where('id', $this->id)->update(['status' => $status]);
        $this->status = $status;
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            'pending'         => 'badge-warning',
            'partial'         => 'badge-info',
            'fully_delivered' => 'badge-success',
            'cancelled'       => 'badge-danger',
            default           => 'badge-secondary',
        };
    }
}
