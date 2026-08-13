<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Item extends Model
{
    protected $fillable = [
        'stock_number', 'description', 'ris_number', 'unit', 'category',
        'account_code', 'warehouse_id', 'unit_cost', 'engas_unit_cost', 'quantity',
        'quantity_per_item', 'reorder_point', 'expiration_date', 'is_active',
        'source_subsidy_id', 'source_subsidy_ris', 'source_subsidy_dr', 'source_subsidy_status',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'quantity'           => 'float',
        'unit_cost'          => 'float',
        'engas_unit_cost'    => 'float',
        'quantity_per_item'  => 'integer',
        'expiration_date'    => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Automatically manage is_active based on quantity:
         *  - quantity reaches 0 → deactivate (hidden from dropdowns and stock lists)
         *  - quantity rises above 0 → reactivate (visible again)
         *
         * This preserves all history (stock cards, delivery lines, requisition lines)
         * while keeping zero-stock items out of active workflows.
         *
         * Only applies when quantity is explicitly being saved (not on first creation
         * before a delivery, when quantity starts at 0 intentionally as a placeholder).
         */
        static::saving(function (Item $item): void {
            // Only auto-manage is_active if the item already has a stock_number
            // (placeholders created at delivery time have no stock_number yet and start at 0 legitimately)
            if (! $item->stock_number) {
                return;
            }

            if ((float) $item->quantity <= 0) {
                $item->is_active = false;
            } elseif ((float) $item->quantity > 0 && ! $item->is_active) {
                // Stock has returned — reactivate
                $item->is_active = true;
            }
        });
    }

    // Category => Account Code mapping (kept as fallback / cache miss safety)
    const CATEGORIES = [
        'food'     => ['label' => 'Welfare Goods for Distribution (FOOD)',     'account_code' => '1040202000-01'],
        'non-food' => ['label' => 'Welfare Goods for Distribution (Non-Food)', 'account_code' => '1040202000-02'],
    ];

    /**
     * Dynamic category list from the database (replaces the CATEGORIES constant).
     * Falls back to the constant if the table doesn't exist yet (e.g. during migration).
     *
     * @return array  ['key' => ['label' => '...', 'account_code' => '...']]
     */
    public static function getCategories(): array
    {
        try {
            return \App\Models\ItemCategory::asArray() ?: static::CATEGORIES;
        } catch (\Throwable) {
            return static::CATEGORIES;
        }
    }

    const UNITS = [
        'piece' => 'Piece (pc)',
        'box' => 'Box (bx)',
        'ream' => 'Ream (rm)',
        'pack' => 'Pack (pk)',
        'bottle' => 'Bottle (btl)',
        'can' => 'Can',
        'set' => 'Set',
        'pair' => 'Pair (pr)',
        'roll' => 'Roll (rl)',
        'bag' => 'Bag (bg)',
        'sack' => 'Sack (sk)',
        'liter' => 'Liter (L)',
        'gallon' => 'Gallon (gal)',
        'meter' => 'Meter (m)',
        'kilogram' => 'Kilogram (kg)',
        'gram' => 'Gram (g)',
        'tablet' => 'Tablet (tab)',
        'capsule' => 'Capsule (cap)',
        'ampule' => 'Ampule (amp)',
        'vial' => 'Vial',
        'tube' => 'Tube',
        'unit' => 'Unit',
        'lot' => 'Lot',
        'dozen' => 'Dozen (doz)',
        'bundle' => 'Bundle (bdl)',
        'pad' => 'Pad',
        'jar' => 'Jar',
        'pouch' => 'Pouch',
        'strip' => 'Strip',
        'sheet' => 'Sheet',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockCardEntries()
    {
        return $this->hasMany(StockCardEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    public function deliverySubsidyItems()
    {
        return $this->hasMany(DeliverySubsidyItem::class);
    }

    public function requisitionItems()
    {
        return $this->hasMany(RequisitionItem::class);
    }

    public function requisitionDispatchItems()
    {
        return $this->hasMany(RequisitionDispatchItem::class);
    }

    public function sourceSubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class, 'source_subsidy_id');
    }

    /**
     * Whether this item's source Subsidy has been deleted or archived. The
     * subsidy row may be gone; the snapshot columns keep the trail.
     */
    public function isRelatedToDeletedSubsidy(): bool
    {
        return in_array($this->source_subsidy_status, ['deleted', 'archived'], true);
    }

    /** Human label for the source subsidy state ('Deleted' / 'Archived'), or null. */
    public function sourceSubsidyStatusLabel(): ?string
    {
        return match ($this->source_subsidy_status) {
            'deleted'  => 'Deleted',
            'archived' => 'Archived',
            default    => null,
        };
    }

    /** The original Subsidy/RIS reference this item was delivered from. */
    public function sourceSubsidyReference(): ?string
    {
        if ($this->sourceSubsidy) {
            return $this->sourceSubsidy->ris_number;
        }
        return $this->source_subsidy_ris;
    }

    /** The original Subsidy DR reference this item was delivered from. */
    public function sourceDrReference(): ?string
    {
        if ($this->sourceSubsidy) {
            return $this->sourceSubsidy->dr_number;
        }
        return $this->source_subsidy_dr;
    }

    /**
     * Persist the source-subsidy snapshot. Uses a direct query update so the
     * model's saving hook (which auto-manages is_active from quantity) is never
     * triggered by snapshot bookkeeping. The subsidy id may be null when the
     * subsidy row is already gone (nullOnDelete) — the RIS/DR/status snapshots
     * still carry the trail.
     */
    public function applySubsidySnapshot(?int $subsidyId, ?string $ris, ?string $dr, string $status): void
    {
        static::whereKey($this->id)->update([
            'source_subsidy_id'     => $subsidyId,
            'source_subsidy_ris'    => $ris ?: $this->source_subsidy_ris,
            'source_subsidy_dr'     => $dr ?: $this->source_subsidy_dr,
            'source_subsidy_status' => $status,
        ]);

        $this->forceFill([
            'source_subsidy_id'     => $subsidyId,
            'source_subsidy_ris'    => $ris ?: $this->source_subsidy_ris,
            'source_subsidy_dr'     => $dr ?: $this->source_subsidy_dr,
            'source_subsidy_status' => $status,
        ]);
    }

    public function getCategoryLabel(): string
    {
        $cats = static::getCategories();
        return $cats[$this->category]['label'] ?? ucfirst($this->category);
    }

    public static function getAccountCodeForCategory(string $category): string
    {
        $cats = static::getCategories();
        return $cats[$category]['account_code'] ?? '';
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    /** Filter by warehouse. */
    public function scopeForWarehouse($query, ?int $warehouseId)
    {
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        return $query;
    }

    /** Filter by category key. */
    public function scopeForCategory($query, ?string $category)
    {
        if ($category) {
            $query->where('category', $category);
        }
        return $query;
    }

    /** Filter by item ID (single item). */
    public function scopeForItem($query, ?int $itemId)
    {
        if ($itemId) {
            $query->where('id', $itemId);
        }
        return $query;
    }

    /**
     * On delivery: find the existing item matching description+unit+category+center,
     * assign a stock number if it doesn't have one yet (or find the matching cost variant),
     * then update its unit_cost. Never creates a duplicate item record.
     */
    public static function findOrCreateByUnitCost(
        int $warehouseId,
        string $description,
        string $unit,
        string $category,
        float $unitCost,
        ?string $risNumber = null,
        ?string $expirationDate = null,
        ?float $engasUnitCost = null,
        ?string $accountCode = null
    ): self {
        $unitCost = round($unitCost, 2);

        $query = static::where('warehouse_id', $warehouseId)
            ->where('description', $description)
            ->where('unit', $unit)
            ->where('category', $category)
            ->whereBetween('unit_cost', [$unitCost - 0.001, $unitCost + 0.001])
            ->whereNotNull('stock_number');

        if ($expirationDate) {
            $query->whereDate('expiration_date', $expirationDate);
        }

        $existing = $query->first();

        if ($existing) {
            // Sync engas_unit_cost if the source provides it and the existing item doesn't have one
            if ($engasUnitCost !== null && $existing->engas_unit_cost === null) {
                $existing->update(['engas_unit_cost' => $engasUnitCost]);
            }
            // Keep the configured account code in sync (admin-configured codes win)
            if ($accountCode && $existing->account_code !== $accountCode) {
                $existing->update(['account_code' => $accountCode]);
            }
            return $existing->fresh();
        }

        $baseQuery = static::where('warehouse_id', $warehouseId)
            ->where('description', $description)
            ->where('unit', $unit)
            ->where('category', $category)
            ->whereNull('stock_number');

        $base = $baseQuery->first();

        $warehouse   = \App\Models\Warehouse::find($warehouseId);
        $stockNumber = static::generateStockNumber($warehouse->code ?? 'XX', $category);

        if ($base) {
            $base->update([
                'stock_number'    => $stockNumber,
                'unit_cost'       => $unitCost,
                'account_code'    => $accountCode ?: ($base->account_code ?: static::getAccountCodeForCategory($category)),
                'expiration_date' => $expirationDate ?? $base->expiration_date,
                'engas_unit_cost' => $engasUnitCost ?? $base->engas_unit_cost,
                'is_active'       => true,
            ]);
            return $base->fresh();
        }

        return static::create([
            'stock_number'    => $stockNumber,
            'description'     => $description,
            'ris_number'      => $risNumber,
            'unit'            => $unit,
            'category'        => $category,
            'account_code'    => $accountCode ?: static::getAccountCodeForCategory($category),
            'warehouse_id'    => $warehouseId,
            'unit_cost'       => $unitCost,
            'engas_unit_cost' => $engasUnitCost,
            'quantity'        => 0,
            'expiration_date' => $expirationDate,
            'is_active'       => true,
        ]);
    }

    public static function generateStockNumber(string $warehouseCode, string $category): string
    {
        $prefix = strtoupper($warehouseCode) . '-' . strtoupper(substr($category, 0, 3));

        // Use a DB-level advisory lock to prevent two concurrent deliveries
        // from generating the same stock number for the same warehouse+category.
        return DB::transaction(function () use ($prefix) {
            // Lock the prefix range so no other transaction can read/write it simultaneously
            $last = static::withoutGlobalScopes()
                ->where('stock_number', 'like', $prefix . '-%')
                ->lockForUpdate()
                ->count();

            $candidate = $prefix . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);

            // Increment until we find a genuinely unused number
            $attempts = 0;
            while (static::where('stock_number', $candidate)->exists() && $attempts < 50) {
                $last++;
                $attempts++;
                $candidate = $prefix . '-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
            }

            return $candidate;
        });
    }
}
