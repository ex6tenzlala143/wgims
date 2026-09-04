<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Reservation extends Model
{
    protected $fillable = [
        'reservation_number',
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
        'reserved_quantity'  => 'float',
        'allocated_quantity' => 'float',
        'expires_at'         => 'datetime',
    ];

    // ── Overall header statuses ───────────────────────────────────────────────
    const STATUS_PENDING             = 'PENDING';
    const STATUS_RESERVED            = 'RESERVED';
    const STATUS_READY               = 'READY_FOR_REQUISITION';
    const STATUS_PARTIALLY_DEPLOYED  = 'PARTIALLY_DEPLOYED';
    const STATUS_DEPLOYED            = 'DEPLOYED';
    const STATUS_CANCELLED           = 'CANCELLED';
    const STATUS_EXPIRED             = 'EXPIRED';

    // Legacy aliases kept for backward compatibility
    const STATUS_ALLOCATED = 'PARTIALLY_DEPLOYED';
    const STATUS_FULFILLED = 'DEPLOYED';

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESERVED,
        self::STATUS_READY,
        self::STATUS_PARTIALLY_DEPLOYED,
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function items()
    {
        return $this->hasMany(ReservationItem::class);
    }

    public function activeItems()
    {
        return $this->hasMany(ReservationItem::class)
            ->whereIn('status', ReservationItem::ACTIVE_STATUSES);
    }

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

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, (float)($this->reserved_quantity ?? 0) - (float)($this->allocated_quantity ?? 0));
    }

    public function getTotalReservedAttribute(): float
    {
        return (float) $this->items()->sum('reserved_quantity');
    }

    public function getTotalDeployedAttribute(): float
    {
        return (float) $this->items()->sum('deployed_quantity');
    }

    public function getTotalRemainingAttribute(): float
    {
        return max(0, $this->total_reserved - $this->total_deployed);
    }

    public function getItemCountAttribute(): int
    {
        return $this->items()->count();
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING            => 'badge-warning',
            self::STATUS_RESERVED           => 'badge-info',
            self::STATUS_READY              => 'badge-primary',
            self::STATUS_PARTIALLY_DEPLOYED => 'badge-warning',
            self::STATUS_DEPLOYED           => 'badge-success',
            self::STATUS_CANCELLED          => 'badge-danger',
            self::STATUS_EXPIRED            => 'badge-danger',
            default                         => 'badge-secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING            => 'Pending',
            self::STATUS_RESERVED           => 'Reserved',
            self::STATUS_READY              => 'Ready for Requisition',
            self::STATUS_PARTIALLY_DEPLOYED => 'Partially Deployed',
            self::STATUS_DEPLOYED           => 'Deployed',
            self::STATUS_CANCELLED          => 'Cancelled',
            self::STATUS_EXPIRED            => 'Expired',
            default                         => ucwords(strtolower(str_replace('_', ' ', $this->status))),
        };
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * Total quantity currently reserved (soft-locked) for a specific item
     * across all active reservations. Queries reservation_items — the
     * authoritative source for multi-item reservations.
     */
    public static function reservedQuantityForItem(int $itemId): float
    {
        return ReservationItem::reservedQuantityForItem($itemId);
    }

    public static function availableQuantityForItem(Item $item): float
    {
        $reserved = self::reservedQuantityForItem($item->id);
        return max(0, $item->quantity - $reserved);
    }

    /**
     * Generate a unique reservation number (RES-000001) using an advisory
     * lock — same pattern as RIS and transfer number generation.
     */
    public static function generateReservationNumber(): string
    {
        $lockName = 'reservation_number_gen';
        try {
            DB::select("SELECT GET_LOCK(?, 10)", [$lockName]);

            $maxId    = static::max('id') ?? 0;
            $candidate = 'RES-' . str_pad($maxId + 1, 6, '0', STR_PAD_LEFT);

            $attempts = 0;
            while (static::where('reservation_number', $candidate)->exists() && $attempts < 50) {
                $maxId++;
                $attempts++;
                $candidate = 'RES-' . str_pad($maxId + 1, 6, '0', STR_PAD_LEFT);
            }

            return $candidate;
        } finally {
            DB::select("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function approve(int $userId): bool
    {
        return $this->update([
            'status'      => self::STATUS_RESERVED,
            'approved_by' => $userId ?: null,
        ]);
    }

    public function markReady(): bool
    {
        return $this->update(['status' => self::STATUS_READY]);
    }

    public function cancel(): bool
    {
        DB::transaction(function () {
            // Cancel all active reservation_items so their quantities are released
            $this->items()
                ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
                ->update(['status' => ReservationItem::STATUS_CANCELLED]);

            $this->update(['status' => self::STATUS_CANCELLED]);
        });

        return true;
    }

    public function expire(): bool
    {
        if (in_array($this->status, [self::STATUS_DEPLOYED, self::STATUS_CANCELLED])) {
            return false;
        }

        DB::transaction(function () {
            $this->items()
                ->whereIn('status', ReservationItem::ACTIVE_STATUSES)
                ->update(['status' => ReservationItem::STATUS_CANCELLED]);

            $this->update(['status' => self::STATUS_EXPIRED]);
        });

        return true;
    }

    /**
     * Recompute and persist the reservation's overall status from its items.
     * Called after every dispatch that touches a reservation_item.
     */
    public function updateOverallStatus(): void
    {
        $items = $this->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allDeployed   = $items->every(fn ($i) => $i->status === ReservationItem::STATUS_DEPLOYED);
        $allCancelled  = $items->every(fn ($i) => $i->status === ReservationItem::STATUS_CANCELLED);
        $anyDeployed   = $items->some(fn ($i) => in_array($i->status, [
            ReservationItem::STATUS_DEPLOYED,
            ReservationItem::STATUS_PARTIALLY_DEPLOYED,
        ]));

        $newStatus = match (true) {
            $allDeployed  => self::STATUS_DEPLOYED,
            $allCancelled => self::STATUS_CANCELLED,
            $anyDeployed  => self::STATUS_PARTIALLY_DEPLOYED,
            default       => $this->status, // keep current (RESERVED / READY / etc.)
        };

        if ($newStatus !== $this->status) {
            static::whereKey($this->id)->update(['status' => $newStatus]);
            $this->status = $newStatus;
        }
    }

    // Legacy compat shims (the old model had these — kept so existing code
    // that calls allocate() does not break with a fatal error)
    public function allocate(float $quantity): bool
    {
        return false; // superseded by per-item deployment in ReservationItem
    }
}
