<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Reservation extends Model
{
    protected $fillable = [
        'warehouse_id',
        'item_id',
        'reserved_quantity',
        'allocated_quantity',
        'status',
        'purpose',
        'intended_requisition_id',
        'created_by',
        'approved_by',
        'notes',
        'expires_at',
    ];

    protected $casts = [
        'reserved_quantity' => 'decimal:4',
        'allocated_quantity' => 'decimal:4',
        'expires_at' => 'datetime',
    ];

    const STATUS_PENDING = 'PENDING';
    const STATUS_RESERVED = 'RESERVED';
    const STATUS_READY = 'READY_FOR_REQUISITION';
    const STATUS_ALLOCATED = 'ALLOCATED';
    const STATUS_FULFILLED = 'FULFILLED';
    const STATUS_CANCELLED = 'CANCELLED';
    const STATUS_EXPIRED = 'EXPIRED';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESERVED,
        self::STATUS_READY,
        self::STATUS_ALLOCATED,
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function intendedRequisition()
    {
        return $this->belongsTo(Requisition::class, 'intended_requisition_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getRemainingQuantityAttribute()
    {
        return $this->reserved_quantity - $this->allocated_quantity;
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public static function reservedQuantityForItem(int $itemId): float
    {
        return (float) self::where('item_id', $itemId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->sum('reserved_quantity');
    }

    public static function availableQuantityForItem(Item $item): float
    {
        $reserved = self::reservedQuantityForItem($item->id);
        return max(0, $item->quantity - $reserved);
    }

    public function approve(int $userId): bool
    {
        return $this->update([
            'status' => self::STATUS_RESERVED,
            'approved_by' => $userId,
        ]);
    }

    public function markReady(): bool
    {
        return $this->update(['status' => self::STATUS_READY]);
    }

    public function allocate(float $quantity): bool
    {
        $newAllocated = $this->allocated_quantity + $quantity;
        if ($newAllocated > $this->reserved_quantity) {
            return false;
        }
        $status = $newAllocated >= $this->reserved_quantity
            ? self::STATUS_FULFILLED
            : self::STATUS_ALLOCATED;
        return $this->update([
            'allocated_quantity' => $newAllocated,
            'status' => $status,
        ]);
    }

    public function cancel(): bool
    {
        return $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public function expire(): bool
    {
        if ($this->status === self::STATUS_FULFILLED || $this->status === self::STATUS_CANCELLED) {
            return false;
        }
        return $this->update(['status' => self::STATUS_EXPIRED]);
    }
}
