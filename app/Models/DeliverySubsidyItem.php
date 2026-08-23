<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DeliverySubsidyItem extends Model
{
    protected $fillable = [
        'delivery_subsidy_id', 'item_id', 'warehouse_id', 'quantity',
        'unit_cost', 'amount', 'qty_delivered',
        'description', 'unit', 'category', 'catalog_item_id', 'account_code',
        'expiration_date',
    ];
    protected $casts = [
        'quantity' => 'integer', 'unit_cost' => 'float', 'amount' => 'float',
        'qty_delivered' => 'integer', 'expiration_date' => 'date',
    ];

    public function deliverySubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    /**
     * The warehouse this line item is destined for. Each item in a delivery
     * may be assigned to a different warehouse.
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(ItemCatalogItem::class);
    }

    public function deliveryItems()
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * The distinct warehouses actually used by this ordered item's dispatches.
     *
     * An order line may be fulfilled from several warehouses — every dispatch
     * (delivery_items row) records the warehouse it delivered into — so this
     * reflects all of them, deduplicated by warehouse, instead of the single
     * planned destination stored on the order line itself.
     *
     * Eager-load `deliveryItems.warehouse` (e.g. from the show route) to avoid
     * per-row queries.
     */
    public function getDispatchWarehousesAttribute(): Collection
    {
        return $this->deliveryItems
            ->map(fn ($di) => $di->warehouse)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * All distinct stock records (Items) that actually hold the delivered
     * quantity for this ordered line. When the same Subsidy item is delivered
     * in several shipments with different unit cost / ENGAS / expiration /
     * warehouse, each shipment resolves to a different Item row; this gathers
     * all of them, deduplicated, so the Ordered Items table can show every
     * stock card — not just $this->item (which only points to the last one).
     *
     * Eager-load `deliveryItems.item` from the show route to avoid N+1.
     *
     * @return Collection<int, Item>
     */
    public function getAssignedStockCardsAttribute(): Collection
    {
        return $this->deliveryItems
            ->map(fn ($di) => $di->item)
            ->filter(fn ($i) => $i && $i->stock_number)
            ->unique('id')
            ->values();
    }

    /**
     * Per-dispatch summary rows for this ordered item.
     *
     * One row per distinct (warehouse, unit cost, ENGAS unit cost) combination
     * actually delivered, with the quantity aggregated. This way every
     * warehouse is shown alongside ITS OWN unit cost and ENGAS cost — a line
     * fulfilled from gamc1 @ ₱700 and gamc2 @ ₱600 yields two rows instead of
     * one overwritten cost — while shipments sharing the same warehouse+costs
     * collapse into a single row.
     *
     * Eager-load `deliveryItems.warehouse` to avoid per-row queries.
     *
     * @return Collection<int, array{warehouse_id:int, warehouse_name:string, quantity:int, unit_cost:?float, engas_unit_cost:?float}>
     */
    public function getDispatchSummaryAttribute(): Collection
    {
        return $this->deliveryItems
            ->map(fn ($di) => [
                'warehouse_id'    => $di->warehouse_id,
                'warehouse_name'  => $di->warehouse?->name,
                'quantity'        => (float) $di->quantity_delivered,
                'unit_cost'       => $di->unit_cost !== null ? (float) $di->unit_cost : null,
                'engas_unit_cost' => $di->engas_unit_cost !== null ? (float) $di->engas_unit_cost : null,
            ])
            ->filter(fn ($row) => $row['warehouse_name'] !== null)
            ->groupBy(fn ($row) => $row['warehouse_id'] . '|' . $row['unit_cost'] . '|' . $row['engas_unit_cost'])
            ->map(fn ($group) => [
                'warehouse_id'    => $group->first()['warehouse_id'],
                'warehouse_name'  => $group->first()['warehouse_name'],
                'quantity'        => (int) $group->sum('quantity'),
                'unit_cost'       => $group->first()['unit_cost'],
                'engas_unit_cost' => $group->first()['engas_unit_cost'],
            ])
            ->values();
    }
}
