<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Requisition extends Model
{
    protected $fillable = [
        'ris_number', 'dr_number', 'warehouse_id', 'created_by', 'approved_by',
        'entity_name', 'fund_cluster', 'office', 'division',
        'responsibility_center_code', 'purpose', 'date_requested', 'date_approved', 'status',
        'requested_by_name', 'requested_by_designation',
        'approved_by_name', 'approved_by_designation',
        'issued_by_name', 'issued_by_designation',
        'received_by_name', 'received_by_designation',
    ];

    protected $casts = ['date_requested' => 'date', 'date_approved' => 'date'];

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
