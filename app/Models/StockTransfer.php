<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
        'delivery_subsidy_id',
        'source_ris_number',
        'source_dr_number',
        'source_subsidy_code',
        'source_subsidy_status',
        'from_warehouse_id',
        'to_warehouse_id',
        'transfer_date',
        'transferred_by',
        'status',
        'remarks',
    ];

    protected $casts = [
        'transfer_date' => 'date',
    ];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function deliverySubsidy(): BelongsTo
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(StockTransferAuditLog::class, 'stock_transfer_id')->latest();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /** Total quantity requested across all line items. */
    public function totalRequested(): float
    {
        return (float) $this->items->sum('quantity_requested');
    }

    /** Total quantity actually dispatched (transferred) across all line items. */
    public function totalTransferred(): float
    {
        return (float) $this->items->sum('quantity');
    }

    /** Total quantity still outstanding. */
    public function totalRemaining(): float
    {
        return max(0, $this->totalRequested() - $this->totalTransferred());
    }

    /**
     * Recalculate and persist the transfer status.
     *   0 transferred              → pending
     *   all items fully dispatched → completed
     *   some transferred, not all  → partial
     */
    public function updateTransferStatus(): void
    {
        $this->loadMissing('items');

        $allDone   = true;
        $anyMoved  = false;

        foreach ($this->items as $line) {
            if ($line->quantity > 0) {
                $anyMoved = true;
            }
            if ($line->quantity < $line->quantity_requested - 0.0001) {
                $allDone = false;
            }
        }

        $status = ($allDone && $anyMoved) ? 'completed' : ($anyMoved ? 'partial' : 'pending');

        static::where('id', $this->id)->update(['status' => $status]);
        $this->status = $status;
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            'completed' => 'badge-success',
            'partial'   => 'badge-info',
            'pending'   => 'badge-warning',
            default     => 'badge-secondary',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'completed' => 'Completed',
            'partial'   => 'Partially Dispatched',
            'pending'   => 'Pending Dispatch',
            default     => ucfirst($this->status),
        };
    }

    /**
     * Whether this transfer traces back to a Subsidy that has been deleted or
     * archived. The subsidy row may be gone; the snapshot columns keep the trail.
     */
    public function isRelatedToDeletedSubsidy(): bool
    {
        return in_array($this->source_subsidy_status, ['deleted', 'archived'], true);
    }

    /**
     * Human label for the source subsidy state ('Deleted' / 'Archived'), or null.
     */
    public function sourceSubsidyStatusLabel(): ?string
    {
        return match ($this->source_subsidy_status) {
            'deleted'  => 'Deleted',
            'archived' => 'Archived',
            default    => null,
        };
    }

    /**
     * The original Subsidy/RIS reference this transfer was sourced from.
     * Prefers the live subsidy when it still exists, otherwise the snapshot.
     */
    public function sourceSubsidyReference(): ?string
    {
        if ($this->deliverySubsidy) {
            return $this->deliverySubsidy->ris_number;
        }
        return $this->source_ris_number;
    }

    /**
     * The original Subsidy DR number this transfer was sourced from.
     * Prefers the live subsidy when it still exists, otherwise the snapshot.
     */
    public function sourceDrReference(): ?string
    {
        if ($this->deliverySubsidy) {
            return $this->deliverySubsidy->dr_number;
        }
        return $this->source_dr_number;
    }

    /**
     * The permanent Subsidy ID (SUB-000001) this transfer's stock originated
     * from. Prefers the live subsidy, otherwise the snapshot.
     */
    public function sourceSubsidyCode(): ?string
    {
        if ($this->deliverySubsidy) {
            return $this->deliverySubsidy->subsidy_code;
        }
        return $this->source_subsidy_code;
    }

    /**
     * Generate a unique transfer number in the format TRF-YYYY-NNNN.
     *
     * Uses an advisory lock (GET_LOCK / RELEASE_LOCK) so concurrent requests
     * cannot generate the same candidate number.  This mirrors the same pattern
     * used in Item::generateStockNumber().
     */
    public static function generateTransferNumber(): string
    {
        $year   = date('Y');
        $lockName = 'trf_number_' . $year;

        try {
            \Illuminate\Support\Facades\DB::select("SELECT GET_LOCK(?, 10)", [$lockName]);

            $base      = static::whereYear('created_at', $year)->count();
            $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);

            $attempts = 0;
            while (static::where('transfer_number', $candidate)->exists() && $attempts < 50) {
                $base++;
                $attempts++;
                $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);
            }

            return $candidate;
        } finally {
            \Illuminate\Support\Facades\DB::select("SELECT RELEASE_LOCK(?)", [$lockName]);
        }
    }
}
