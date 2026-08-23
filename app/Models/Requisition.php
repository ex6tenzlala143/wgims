<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Requisition extends Model
{
    protected $fillable = [
        'ris_code', 'ris_number', 'dr_number', 'warehouse_id', 'created_by', 'approved_by',
        'entity_name', 'fund_cluster', 'office', 'division', 'province', 'municipality',
        'responsibility_center_code', 'purpose', 'date_requested', 'date_approved', 'status',
        'requested_by_name', 'requested_by_designation',
        'approved_by_name', 'approved_by_designation',
        'issued_by_name', 'issued_by_designation',
        'received_by_name', 'received_by_designation',
    ];

    protected $casts = ['date_requested' => 'date', 'date_approved' => 'date'];

    protected static function boot(): void
    {
        parent::boot();

        // Permanent system RIS ID (RIS-000001) derived from the auto-increment id.
        // Never reused, never user-editable — mirrors DeliverySubsidy subsidy_code.
        static::created(function (Requisition $requisition): void {
            if (! $requisition->ris_code) {
                $code = 'RIS-' . str_pad((string) $requisition->id, 6, '0', STR_PAD_LEFT);
                static::whereKey($requisition->id)->update(['ris_code' => $code]);
                $requisition->ris_code = $code;
            }
        });
    }

    /**
     * Alias so $requisition->ris_id works as the system-generated RIS ID.
     * Database column is ris_code (consistent with subsidy_code).
     */
    public function getRisIdAttribute(): ?string
    {
        return $this->attributes['ris_code'] ?? null;
    }

    public function setRisIdAttribute(?string $value): void
    {
        $this->attributes['ris_code'] = $value;
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(RequisitionAuditLog::class)->latest();
    }

    /**
     * Human-readable list of the warehouses this requisition draws from.
     * Includes the warehouse of every dispatch, plus any line-level warehouse.
     * Falls back to the legacy single warehouse when nothing else is set.
     */
    public function getWarehouseNamesAttribute(): string
    {
        $names = collect();

        foreach ($this->items as $ri) {
            $names->push($ri->warehouse?->name);

            // A dispatch's warehouse is derived from its exact stock record
            foreach ($ri->dispatchItems as $di) {
                $names->push($di->item?->warehouse?->name);
            }
        }

        $names = $names->filter()->unique();

        if ($names->isEmpty() && $this->warehouse) {
            return $this->warehouse->name;
        }

        return $names->implode(', ') ?: '-';
    }

    /**
     * Unique warehouse IDs involved in this requisition (from its dispatches
     * and line items). Falls back to the legacy single warehouse_id column.
     */
    public function getWarehouseIdsAttribute(): array
    {
        $ids = collect();

        foreach ($this->items as $ri) {
            $ids->push((int) $ri->warehouse_id);
            // A dispatch's warehouse is derived from its exact stock record
            $ids->push(...$ri->dispatchItems->map(fn ($di) => (int) $di->item?->warehouse_id));
        }

        $ids = $ids->filter()->unique()->values()->toArray();

        if (empty($ids) && $this->warehouse_id) {
            $ids = [(int) $this->warehouse_id];
        }

        return $ids;
    }

    /**
     * Whether this requisition draws from any item whose source Subsidy has
     * been deleted or archived (checked on both requested lines and their
     * dispatch records).
     */
    public function isRelatedToDeletedSubsidy(): bool
    {
        return $this->deletedSubsidySnapshot() !== null;
    }

    /**
     * First source-subsidy snapshot found across this requisition's requested
     * lines and dispatch records, or null when none is flagged.
     *
     * @return array{status: string, ris: ?string, dr: ?string}|null
     */
    public function deletedSubsidySnapshot(): ?array
    {
        foreach ($this->items as $ri) {
            if ($ri->item && in_array($ri->item->source_subsidy_status, ['deleted', 'archived'], true)) {
                return [
                    'status' => $ri->item->source_subsidy_status,
                    'ris'    => $ri->item->sourceSubsidyReference(),
                    'dr'     => $ri->item->sourceDrReference(),
                ];
            }

            foreach ($ri->dispatchItems as $di) {
                if ($di->item && in_array($di->item->source_subsidy_status, ['deleted', 'archived'], true)) {
                    return [
                        'status' => $di->item->source_subsidy_status,
                        'ris'    => $di->item->sourceSubsidyReference(),
                        'dr'     => $di->item->sourceDrReference(),
                    ];
                }
            }
        }

        return null;
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            'pending'            => 'badge-warning',
            'approved'           => 'badge-success',
            'partially_approved' => 'badge-info',
            'cancelled'          => 'badge-danger',
            default              => 'badge-secondary',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending'            => 'Pending',
            'approved'           => 'Approved',
            'partially_approved' => 'Partially Fulfilled',
            'cancelled'          => 'Cancelled',
            default              => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /** Total quantity requested across all line items. */
    public function totalRequested(): float
    {
        return (float) $this->items->sum('quantity_requested');
    }

    /** Total quantity issued across all line items. */
    public function totalIssued(): float
    {
        return (float) $this->items->sum('quantity_issued');
    }

    /** Total quantity still outstanding. */
    public function totalRemaining(): float
    {
        return max(0, $this->totalRequested() - $this->totalIssued());
    }

    /**
     * Recalculate and persist the RIS status based on issued vs requested quantities.
     *   0 issued              → pending
     *   all lines fulfilled   → approved
     *   some issued, not all  → partially_approved
     */
    public function updateFulfilmentStatus(): void
    {
        $this->loadMissing('items');

        $allFulfilled = true;
        $anyIssued    = false;

        foreach ($this->items as $ri) {
            if ($ri->quantity_issued > 0) {
                $anyIssued = true;
            }
            if ($ri->quantity_issued < $ri->quantity_requested - 0.0001) {
                $allFulfilled = false;
            }
        }

        $status = $allFulfilled && $anyIssued
            ? 'approved'
            : ($anyIssued ? 'partially_approved' : 'pending');

        static::where('id', $this->id)->update(['status' => $status]);
        $this->status = $status;
    }

    public static function generateRisNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        $base = static::whereYear('created_at', $year)->whereMonth('created_at', $month)->count();

        $candidate = 'RIS-'.$year.$month.'-'.str_pad($base + 1, 4, '0', STR_PAD_LEFT);

        // Retry if the candidate already exists (race condition guard)
        $attempts = 0;
        while (static::where('ris_number', $candidate)->exists() && $attempts < 20) {
            $base++;
            $attempts++;
            $candidate = 'RIS-'.$year.$month.'-'.str_pad($base + 1, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }
}
