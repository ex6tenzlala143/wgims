<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationItem extends Model
{
    protected $fillable = [
        'reservation_id',
        'item_id',
        'warehouse_id',
        'reserved_quantity',
        'deployed_quantity',
        'status',
        'unit_cost',
        'engas_unit_cost',
        'expiration_date',
        'notes',
    ];

    protected $casts = [
        'reserved_quantity' => 'float',
        'deployed_quantity' => 'float',
        'unit_cost'         => 'float',
        'engas_unit_cost'   => 'float',
        'expiration_date'   => 'date',
    ];

    const STATUS_ACTIVE             = 'ACTIVE';
    const STATUS_PARTIALLY_DEPLOYED = 'PARTIALLY_DEPLOYED';
    const STATUS_DEPLOYED           = 'DEPLOYED';
    const STATUS_CANCELLED          = 'CANCELLED';

    const ACTIVE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PARTIALLY_DEPLOYED,
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function dispatchItems()
    {
        return $this->hasMany(RequisitionDispatchItem::class);
    }

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, $this->reserved_quantity - $this->deployed_quantity);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE             => 'badge-info',
            self::STATUS_PARTIALLY_DEPLOYED => 'badge-warning',
            self::STATUS_DEPLOYED           => 'badge-success',
            self::STATUS_CANCELLED          => 'badge-danger',
            default                         => 'badge-secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE             => 'Active',
            self::STATUS_PARTIALLY_DEPLOYED => 'Partially Deployed',
            self::STATUS_DEPLOYED           => 'Deployed',
            self::STATUS_CANCELLED          => 'Cancelled',
            default                         => ucwords(strtolower(str_replace('_', ' ', $this->status))),
        };
    }

    public static function reservedQuantityForItem(int $itemId): float
    {
        return (float) static::where('item_id', $itemId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->sum('reserved_quantity');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
