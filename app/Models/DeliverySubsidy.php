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
     * Recalculate and persist the delivery/subsidy status based on:
     *   quantity_requested  (on this record — the total target)
     *   SUM(deliveries.quantity_delivered)  (all shipments so far)
     *
     * Rules:
     *   0 delivered               → pending
     *   delivered >= requested    → fully_delivered
     *   0 < delivered < requested → partial
     *
     * Always call this after recording or editing a delivery.
     * It queries the DB fresh so it is never affected by stale in-memory state.
     */
    public function updateDeliveryStatus(): void
    {
        $requested = (float) $this->quantity_requested;
        $delivered = $this->totalDelivered(); // always a fresh DB query
        $epsilon   = 0.0001;

        if ($delivered <= 0) {
            $status = 'pending';
        } elseif ($delivered >= $requested - $epsilon) {
            $status = 'fully_delivered';
        } else {
            $status = 'partial';
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
