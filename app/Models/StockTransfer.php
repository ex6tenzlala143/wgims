<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
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
     * Generate a unique transfer number in the format TRF-YYYY-NNNN.
     * Race-condition guarded (same pattern as Requisition::generateRisNumber).
     */
    public static function generateTransferNumber(): string
    {
        $year = date('Y');
        $base = static::whereYear('created_at', $year)->count();

        $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);

        $attempts = 0;
        while (static::where('transfer_number', $candidate)->exists() && $attempts < 20) {
            $base++;
            $attempts++;
            $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }
}
