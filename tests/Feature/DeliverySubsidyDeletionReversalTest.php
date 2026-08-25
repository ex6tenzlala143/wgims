<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySubsidyDeletionReversalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'del_rev_admin_' . $i,
            'name'     => 'Del Rev Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(
        Warehouse $wh,
        string $description,
        float $cost = 10.0,
        float $qty = 0,
        ?string $expiry = null,
        ?float $engas = null
    ): Item {
        return Item::create([
            'stock_number'    => null,
            'description'     => $description,
            'unit'            => 'piece',
            'category'        => 'food',
            'account_code'    => '1040202000-01',
            'warehouse_id'    => $wh->id,
            'unit_cost'       => $cost,
            'engas_unit_cost' => $engas,
            'quantity'        => $qty,
            'expiration_date' => $expiry,
            'is_active'       => true,
        ]);
    }

    private function createSubsidy(array $lines, string $ris): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'description'     => $line['description'],
                'unit'            => 'piece',
                'category'        => 'food',
                'quantity'        => $line['quantity'],
                'expiration_date' => $line['expiration_date'] ?? '2027-01-01',
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => $items,
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        return DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
    }

    private function dispatch(DeliverySubsidy $ds, string $dr, array $lines): void
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'ds_item_id'         => $line['ds_item_id'],
                'warehouse_id'       => $line['warehouse_id'],
                'quantity_delivered' => $line['quantity_delivered'],
                'unit_cost'          => $line['unit_cost'],
                'engas_unit_cost'    => $line['engas_unit_cost'] ?? $line['unit_cost'],
                'expiration_date'    => $line['expiration_date'] ?? '2027-01-01',
                'dr_number'          => $line['dr_number'],
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => $dr,
                'condition_status'   => 'good',
                'quantity_delivered' => array_sum(array_column($lines, 'quantity_delivered')),
                'items'              => $items,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_deleting_one_subsidy_reverts_only_its_own_unit_cost_variant(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse A', 'WHA');
        $wh2 = $this->makeWarehouse('Warehouse B', 'WHB');

        // Design principle: items from different subsidies are ALWAYS separate records,
        // even when description and unit cost match. findOrCreateByUnitCost includes
        // source_subsidy_id in the identity key, so each subsidy gets its own item slot.
        // Pre-existing unlinked stock (source_subsidy_id=null) is never merged with
        // subsidy-delivered stock.

        // Create two subsidies delivering the same item description at different costs
        // to different warehouses.
        $ds1 = $this->createSubsidy([
            ['description' => 'Food Pack', 'quantity' => 50, 'expiration_date' => '2026-12-31'],
        ], 'RIS-SUB-001');
        $ds2 = $this->createSubsidy([
            ['description' => 'Food Pack', 'quantity' => 30, 'expiration_date' => '2027-06-30'],
        ], 'RIS-SUB-002');

        $line1 = $ds1->items()->firstOrFail();
        $line2 = $ds2->items()->firstOrFail();

        // SUB-001 delivers 50 units @ ₱700 to Warehouse A.
        // SUB-002 delivers 30 units @ ₱800 to Warehouse B.
        $this->dispatch($ds1, 'DR-SUB-001', [[
            'ds_item_id' => $line1->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 50, 'unit_cost' => 700,
            'expiration_date' => '2026-12-31', 'dr_number' => 'DR-SUB-001-A',
        ]]);
        $this->dispatch($ds2, 'DR-SUB-002', [[
            'ds_item_id' => $line2->id, 'warehouse_id' => $wh2->id,
            'quantity_delivered' => 30, 'unit_cost' => 800,
            'expiration_date' => '2027-06-30', 'dr_number' => 'DR-SUB-002-A',
        ]]);

        // Each delivery creates a new item record linked to its subsidy.
        // Look up by the subsidy's ID, not just unit_cost (design intent).
        $variant700 = Item::where('warehouse_id', $wh1->id)
            ->where('description', 'Food Pack')
            ->where('source_subsidy_id', $ds1->id)
            ->firstOrFail();
        $variant800 = Item::where('warehouse_id', $wh2->id)
            ->where('description', 'Food Pack')
            ->where('source_subsidy_id', $ds2->id)
            ->firstOrFail();

        // After both deliveries each subsidy-linked record holds exactly what was delivered.
        $this->assertEquals(50, (float) $variant700->fresh()->quantity);
        $this->assertEquals(30, (float) $variant800->fresh()->quantity);

        // Delete only SUB-001.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds1))
            ->assertRedirect(route('delivery_subsidies.index'));

        // The SUB-001 item is reversed back to 0 (or deleted if orphaned)…
        $this->assertEquals(0, (float) ($variant700->fresh()?->quantity ?? 0));
        // …and the SUB-002 item is completely untouched.
        $this->assertEquals(30, (float) $variant800->fresh()->quantity);

        // Stock cards: only the deleted subsidy's delivery entry is gone.
        $this->assertEquals(0, StockCardEntry::where('item_id', $variant700->id)
            ->where('reference', 'DR-SUB-001-A')->count());
        $this->assertEquals(1, StockCardEntry::where('item_id', $variant800->id)
            ->where('reference', 'DR-SUB-002-A')->count());
    }

    public function test_deleting_one_subsidy_does_not_reduce_shared_item_below_other_subsidy_stock(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse A', 'WHA');

        // Two subsidies delivering the same item at the same unit cost to the same warehouse.
        // Each creates its own item record (source_subsidy_id separates them).
        $ds1 = $this->createSubsidy([
            ['description' => 'Rice', 'quantity' => 40],
        ], 'RIS-SHARE-1');
        $ds2 = $this->createSubsidy([
            ['description' => 'Rice', 'quantity' => 20],
        ], 'RIS-SHARE-2');

        $line1 = $ds1->items()->firstOrFail();
        $line2 = $ds2->items()->firstOrFail();

        $this->dispatch($ds1, 'DR-SHARE-1', [[
            'ds_item_id' => $line1->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 40, 'unit_cost' => 50, 'dr_number' => 'DR-SHARE-1-A',
        ]]);
        $this->dispatch($ds2, 'DR-SHARE-2', [[
            'ds_item_id' => $line2->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 20, 'unit_cost' => 50, 'dr_number' => 'DR-SHARE-2-A',
        ]]);

        // Lookup by source_subsidy_id — the authoritative identity.
        $item1 = Item::where('warehouse_id', $wh1->id)->where('description', 'Rice')
            ->where('source_subsidy_id', $ds1->id)->firstOrFail();
        $item2 = Item::where('warehouse_id', $wh1->id)->where('description', 'Rice')
            ->where('source_subsidy_id', $ds2->id)->firstOrFail();

        $this->assertEquals(40, (float) $item1->fresh()->quantity);
        $this->assertEquals(20, (float) $item2->fresh()->quantity);

        // Delete the FIRST subsidy only — the second subsidy's 20 must remain.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds1))
            ->assertRedirect(route('delivery_subsidies.index'));

        // ds1's item reversed to 0 (or deleted if orphaned).
        $this->assertEquals(0, (float) ($item1->fresh()?->quantity ?? 0));
        // ds2's item completely untouched.
        $this->assertEquals(20, (float) $item2->fresh()->quantity);

        $this->assertEquals(0, StockCardEntry::where('item_id', $item1->id)
            ->where('reference', 'DR-SHARE-1-A')->count());
        $this->assertEquals(20, StockCardEntry::where('item_id', $item2->id)
            ->where('reference', 'DR-SHARE-2-A')->sum('receipt_qty'));
    }

    public function test_deleting_subsidy_reverts_stock_in_each_dispatched_warehouse(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        // One subsidy, two deliveries to different warehouses.
        // Each delivery creates/finds the item for that warehouse.
        $ds = $this->createSubsidy([
            ['description' => 'Coffee', 'quantity' => 15],
        ], 'RIS-MULTI-WH');

        $line = $ds->items()->firstOrFail();

        // 10 to WH-A, 5 to WH-B.
        $this->dispatch($ds, 'DR-MULTI-WH-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $whA->id,
            'quantity_delivered' => 10, 'unit_cost' => 300, 'dr_number' => 'DR-MULTI-WH-1-A',
        ]]);
        $this->dispatch($ds, 'DR-MULTI-WH-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $whB->id,
            'quantity_delivered' => 5, 'unit_cost' => 300, 'dr_number' => 'DR-MULTI-WH-2-A',
        ]]);

        $itemA = Item::where('warehouse_id', $whA->id)->where('description', 'Coffee')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $itemB = Item::where('warehouse_id', $whB->id)->where('description', 'Coffee')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(10, (float) $itemA->fresh()->quantity);
        $this->assertEquals(5, (float) $itemB->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Both items were created only for this subsidy → both removed.
        $this->assertNull($itemA->fresh());
        $this->assertDatabaseMissing('items', ['id' => $itemA->id]);
        $this->assertNull($itemB->fresh());
        $this->assertDatabaseMissing('items', ['id' => $itemB->id]);

        $this->assertEquals(0, StockCardEntry::where('item_id', $itemA->id)
            ->where('reference_type', 'delivery')->count());
        $this->assertEquals(0, StockCardEntry::where('item_id', $itemB->id)
            ->where('reference_type', 'delivery')->count());
    }

    public function test_deleting_subsidy_respects_expiry_variants(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');

        // Two subsidies: same item, same cost, same warehouse, different expiry dates.
        // Each creates its own item record (expiration_date is part of the identity).
        $ds1 = $this->createSubsidy([
            ['description' => 'Milk', 'quantity' => 20, 'expiration_date' => '2026-12-31'],
        ], 'RIS-EXP-1');
        $ds2 = $this->createSubsidy([
            ['description' => 'Milk', 'quantity' => 10, 'expiration_date' => '2027-12-31'],
        ], 'RIS-EXP-2');

        $line1 = $ds1->items()->firstOrFail();
        $line2 = $ds2->items()->firstOrFail();

        $this->dispatch($ds1, 'DR-EXP-1', [[
            'ds_item_id' => $line1->id, 'warehouse_id' => $whA->id,
            'quantity_delivered' => 20, 'unit_cost' => 60,
            'expiration_date' => '2026-12-31', 'dr_number' => 'DR-EXP-1-A',
        ]]);
        $this->dispatch($ds2, 'DR-EXP-2', [[
            'ds_item_id' => $line2->id, 'warehouse_id' => $whA->id,
            'quantity_delivered' => 10, 'unit_cost' => 60,
            'expiration_date' => '2027-12-31', 'dr_number' => 'DR-EXP-2-A',
        ]]);

        // Look up by subsidy id — the authoritative identity per-delivery.
        $milk2026 = Item::where('warehouse_id', $whA->id)->where('description', 'Milk')
            ->where('source_subsidy_id', $ds1->id)->whereDate('expiration_date', '2026-12-31')->firstOrFail();
        $milk2027 = Item::where('warehouse_id', $whA->id)->where('description', 'Milk')
            ->where('source_subsidy_id', $ds2->id)->whereDate('expiration_date', '2027-12-31')->firstOrFail();

        $this->assertEquals(20, (float) $milk2026->fresh()->quantity);
        $this->assertEquals(10, (float) $milk2027->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds1))
            ->assertRedirect(route('delivery_subsidies.index'));

        // ds1's item reversed to 0 (or deleted).
        $this->assertEquals(0, (float) ($milk2026->fresh()?->quantity ?? 0));
        // ds2's item untouched.
        $this->assertEquals(10, (float) $milk2027->fresh()->quantity);
    }

    public function test_stock_card_running_balances_are_correct_after_subsidy_deletion(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');

        // Catalog-based subsidy line (no item_id) — delivery creates the item from scratch.
        $ds = $this->createSubsidy([
            ['description' => 'Sugar', 'quantity' => 25],
        ], 'RIS-BALANCE');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-BALANCE', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $whA->id,
            'quantity_delivered' => 25, 'unit_cost' => 40, 'dr_number' => 'DR-BALANCE-A',
        ]]);

        $item = Item::where('warehouse_id', $whA->id)->where('description', 'Sugar')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(25, (float) $item->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // No orphan stock-card rows remain.
        $this->assertEquals(0, StockCardEntry::where('item_id', $item->id)->count());
        // The item existed only for this subsidy → it is removed entirely.
        $this->assertNull($item->fresh());
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_second_delete_does_not_double_reverse(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');

        // Catalog-based subsidy — item created by the delivery, not pre-existing.
        $ds = $this->createSubsidy([
            ['description' => 'Flour', 'quantity' => 30],
        ], 'RIS-DOUBLE-DEL');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-DOUBLE-DEL', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $whA->id,
            'quantity_delivered' => 30, 'unit_cost' => 25, 'dr_number' => 'DR-DOUBLE-DEL-A',
        ]]);

        $item = Item::where('warehouse_id', $whA->id)->where('description', 'Flour')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(30, (float) $item->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));
        // Item deleted (no other references).
        $this->assertNull($item->fresh());

        // Second delete hits a missing record → 404, no second reversal.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds->id))
            ->assertNotFound();

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_deleting_subsidy_removes_item_created_solely_for_it(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        // Catalog-based line (no item_id) → no Item record exists before delivery.
        $ds = $this->createSubsidy([
            ['description' => 'Emergency Ration Pack', 'quantity' => 60],
        ], 'RIS-ORPHAN-1');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-ORPHAN-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 60, 'unit_cost' => 250,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-ORPHAN-1-A',
        ]]);

        $item = Item::where('warehouse_id', $wh->id)->where('description', 'Emergency Ration Pack')->firstOrFail();
        $this->assertEquals(60, (float) $item->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // The item was created only by this subsidy → it is hard-deleted.
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
        $this->assertDatabaseMissing('delivery_items', ['item_id' => $item->id]);
        $this->assertDatabaseMissing('delivery_subsidy_items', ['item_id' => $item->id]);
        $this->assertDatabaseMissing('stock_card_entries', ['item_id' => $item->id]);
    }

    public function test_deleting_subsidy_keeps_item_referenced_by_a_requisition(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        $ds = $this->createSubsidy([
            ['description' => 'Emergency Ration Pack', 'quantity' => 60],
        ], 'RIS-ORPHAN-2');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-ORPHAN-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 60, 'unit_cost' => 250,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-ORPHAN-2-A',
        ]]);

        $item = Item::where('warehouse_id', $wh->id)->where('description', 'Emergency Ration Pack')->firstOrFail();

        // A requisition line also references the same item.
        $requisition = Requisition::create([
            'ris_number'     => 'RIS-REQ-9001',
            'dr_number'      => '',
            'warehouse_id'   => $wh->id,
            'created_by'     => $this->admin()->id,
            'purpose'        => 'Test',
            'date_requested' => '2026-08-15',
            'status'         => 'pending',
        ]);
        RequisitionItem::create([
            'requisition_id'     => $requisition->id,
            'item_id'            => $item->id,
            'description'        => 'Emergency Ration Pack',
            'unit'               => 'piece',
            'account_code'       => '1040202000-01',
            'warehouse_id'       => $wh->id,
            'quantity_requested' => 5,
            'quantity_issued'    => 0,
            'unit_cost'          => 250,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Referenced by a requisition → the item must survive the deletion.
        $this->assertDatabaseHas('items', ['id' => $item->id]);
        $this->assertEquals(0, (float) $item->fresh()->quantity);
    }

    public function test_deleting_subsidy_keeps_item_referenced_by_a_stock_transfer(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB  = $this->makeWarehouse('Warehouse B', 'WHB');
        $source = $this->makeItem($whB, 'Ration Source', 100, 10);

        $ds = $this->createSubsidy([
            ['description' => 'Emergency Ration Pack', 'quantity' => 60],
        ], 'RIS-ORPHAN-3');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-ORPHAN-3', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 60, 'unit_cost' => 250,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-ORPHAN-3-A',
        ]]);

        $item = Item::where('warehouse_id', $wh->id)->where('description', 'Emergency Ration Pack')->firstOrFail();

        // The delivered item becomes the destination of a stock transfer.
        $transfer = StockTransfer::create([
            'transfer_number'    => 'TRF-2026-9001',
            'from_warehouse_id'  => $whB->id,
            'to_warehouse_id'    => $wh->id,
            'transfer_date'      => '2026-08-05',
            'transferred_by'     => $this->admin()->id,
            'status'             => 'pending',
        ]);
        StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $source->id,
            'destination_item_id' => $item->id,
            'quantity'            => 5,
            'quantity_requested'  => 5,
            'unit_cost'           => 250,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Referenced as a transfer destination → the item must survive.
        $this->assertDatabaseHas('items', ['id' => $item->id]);
        $this->assertEquals(0, (float) $item->fresh()->quantity);
    }

    private function deliveryId(string $dr): ?int
    {
        return Delivery::where('dr_number', $dr)->value('id');
    }
}
