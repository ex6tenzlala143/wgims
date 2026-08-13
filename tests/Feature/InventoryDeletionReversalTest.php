<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDeletionReversalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'del_admin_' . $i,
            'name'     => 'Del Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description, float $qty, float $cost = 10.0): Item
    {
        return Item::create([
            'stock_number' => null,
            'description'  => $description,
            'unit'         => 'piece',
            'category'     => 'food',
            'account_code' => '1040202000-01',
            'warehouse_id' => $wh->id,
            'unit_cost'    => $cost,
            'quantity'     => $qty,
            'is_active'    => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Stock Transfer deletion
    // ──────────────────────────────────────────────────────────────────────────

    private function createTransfer(Warehouse $from, Warehouse $to, array $lines): StockTransfer
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'   => $line['item_id'],
                'quantity'  => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('transfers.store'), [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id'   => $to->id,
                'transfer_date'     => '2026-08-01',
                'items'             => $items,
            ])
            ->assertSessionHasNoErrors();

        return StockTransfer::where('from_warehouse_id', $from->id)
            ->where('to_warehouse_id', $to->id)
            ->firstOrFail();
    }

    private function dispatchTransfer(StockTransfer $transfer, array $lines): void
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = ['sti_id' => $line['sti_id'], 'quantity' => $line['quantity']];
        }

        $this->actingAs($this->admin())
            ->post(route('transfers.process_dispatch', $transfer), [
                'dispatch_date' => '2026-08-02',
                'items'         => $items,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transfers.show', $transfer));
    }

    public function test_delete_transfer_reverses_dispatched_stock_per_item(): void
    {
        $whA   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB   = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Bond Paper', 50, 10);
        $itemB = $this->makeItem($whA, 'Ballpen', 40, 15);

        $transfer = $this->createTransfer($whA, $whB, [
            ['item_id' => $itemA->id, 'quantity' => 30, 'unit_cost' => 10],
            ['item_id' => $itemB->id, 'quantity' => 20, 'unit_cost' => 15],
        ]);

        $stiA = StockTransferItem::where('stock_transfer_id', $transfer->id)->where('item_id', $itemA->id)->firstOrFail();
        $stiB = StockTransferItem::where('stock_transfer_id', $transfer->id)->where('item_id', $itemB->id)->firstOrFail();

        // Dispatch both lines fully
        $this->dispatchTransfer($transfer, [
            ['sti_id' => $stiA->id, 'quantity' => 30],
            ['sti_id' => $stiB->id, 'quantity' => 20],
        ]);

        // Stock moved: source down, destination up
        $this->assertEquals(20, (float) $itemA->fresh()->quantity);
        $this->assertEquals(20, (float) $itemB->fresh()->quantity);

        $destA = Item::where('warehouse_id', $whB->id)->where('description', 'Bond Paper')->firstOrFail();
        $destB = Item::where('warehouse_id', $whB->id)->where('description', 'Ballpen')->firstOrFail();
        $this->assertEquals(30, (float) $destA->quantity);
        $this->assertEquals(20, (float) $destB->quantity);

        // Stock cards recorded the movement
        $this->assertEquals(30, StockCardEntry::where('reference_type', 'transfer_out')->where('reference_id', $transfer->id)->where('item_id', $itemA->id)->sum('issue_qty'));
        $this->assertEquals(30, StockCardEntry::where('reference_type', 'transfer_in')->where('reference_id', $transfer->id)->where('item_id', $destA->id)->sum('receipt_qty'));

        // Delete the transfer → exact reversal
        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        $this->assertDatabaseMissing('stock_transfers', ['id' => $transfer->id]);
        $this->assertDatabaseMissing('stock_transfer_items', ['stock_transfer_id' => $transfer->id]);

        $this->assertEquals(50, (float) $itemA->fresh()->quantity);
        $this->assertEquals(40, (float) $itemB->fresh()->quantity);
        $this->assertEquals(0, (float) $destA->fresh()->quantity);
        $this->assertEquals(0, (float) $destB->fresh()->quantity);

        // No stock-card movement is left behind for the deleted transfer
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_out')->where('reference_id', $transfer->id)->count());
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_in')->where('reference_id', $transfer->id)->count());
    }

    public function test_delete_partially_dispatched_transfer_only_reverses_actual_movement(): void
    {
        $whA   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB   = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Rice', 50, 25);

        $transfer = $this->createTransfer($whA, $whB, [
            ['item_id' => $itemA->id, 'quantity' => 30, 'unit_cost' => 25],
        ]);

        $stiA = StockTransferItem::where('stock_transfer_id', $transfer->id)->where('item_id', $itemA->id)->firstOrFail();

        // Only 10 of the planned 30 were actually dispatched
        $this->dispatchTransfer($transfer, [
            ['sti_id' => $stiA->id, 'quantity' => 10],
        ]);

        $destA = Item::where('warehouse_id', $whB->id)->where('description', 'Rice')->firstOrFail();
        $this->assertEquals(40, (float) $itemA->fresh()->quantity);
        $this->assertEquals(10, (float) $destA->quantity);

        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        // Only the 10 actually moved are reversed
        $this->assertEquals(50, (float) $itemA->fresh()->quantity);
        $this->assertEquals(0, (float) $destA->fresh()->quantity);
    }

    public function test_delete_pending_transfer_does_not_touch_stock(): void
    {
        $whA   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB   = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Sugar', 60, 30);

        $transfer = $this->createTransfer($whA, $whB, [
            ['item_id' => $itemA->id, 'quantity' => 20, 'unit_cost' => 30],
        ]);

        // No dispatch happened
        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        $this->assertEquals(60, (float) $itemA->fresh()->quantity);
        $this->assertEquals(0, StockCardEntry::where('reference_id', $transfer->id)->count());
    }

    public function test_delete_transfer_recomputes_remaining_stock_card_balances(): void
    {
        $whA   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB   = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Bond Paper', 0, 10);

        // Pre-existing receipt of 100 for this item (an earlier delivery)
        StockCardEntry::create([
            'item_id'            => $itemA->id,
            'entry_date'         => '2026-07-01',
            'reference'          => 'DR-OLD-1',
            'reference_type'     => 'delivery',
            'reference_id'       => 999,
            'receipt_qty'        => 100,
            'receipt_unit_cost'  => 10,
            'receipt_total_cost' => 1000,
            'issue_qty'          => 0,
            'balance_qty'        => 100,
            'balance_unit_cost'  => 10,
            'balance_total_cost' => 1000,
        ]);
        $itemA->update(['quantity' => 100]);

        $transfer = $this->createTransfer($whA, $whB, [
            ['item_id' => $itemA->id, 'quantity' => 30, 'unit_cost' => 10],
        ]);

        $stiA = StockTransferItem::where('stock_transfer_id', $transfer->id)->where('item_id', $itemA->id)->firstOrFail();
        $this->dispatchTransfer($transfer, [
            ['sti_id' => $stiA->id, 'quantity' => 30],
        ]);

        $this->assertEquals(70, (float) $itemA->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        $this->assertEquals(100, (float) $itemA->fresh()->quantity);

        // The remaining (pre-existing) entry keeps a consistent running balance
        $this->assertEquals(1, StockCardEntry::where('item_id', $itemA->id)->count());
        $entry = StockCardEntry::where('item_id', $itemA->id)->first();
        $this->assertEquals(100, (float) $entry->balance_qty);
        $this->assertEquals(1000, (float) $entry->balance_total_cost);
    }

    public function test_second_delete_of_same_transfer_is_not_possible(): void
    {
        $whA   = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB   = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Salt', 20, 5);

        $transfer = $this->createTransfer($whA, $whB, [
            ['item_id' => $itemA->id, 'quantity' => 5, 'unit_cost' => 5],
        ]);

        $stiA = StockTransferItem::where('stock_transfer_id', $transfer->id)->where('item_id', $itemA->id)->firstOrFail();
        $this->dispatchTransfer($transfer, [
            ['sti_id' => $stiA->id, 'quantity' => 5],
        ]);

        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        // Second delete → 404, reversal never runs twice
        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer->id))
            ->assertNotFound();

        $this->assertEquals(20, (float) $itemA->fresh()->quantity);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Delivery / Subsidy deletion
    // ──────────────────────────────────────────────────────────────────────────

    private function createSubsidy(array $lines, string $ris): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $items = [];
        foreach ($lines as $i => $line) {
            $items[] = [
                'item_id'         => $line['item_id'],
                'description'     => $line['description'],
                'unit'            => 'piece',
                'category'        => 'food',
                'quantity'        => $line['quantity'],
                'expiration_date' => '2027-01-01',
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => $items,
            ])
            ->assertSessionHasNoErrors();

        return DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
    }

    public function test_delete_subsidy_reverses_delivered_stock_per_item_and_warehouse(): void
    {
        $wh1   = $this->makeWarehouse('WH One', 'WH1');
        $wh2   = $this->makeWarehouse('WH Two', 'WH2');
        $wh3   = $this->makeWarehouse('WH Three', 'WH3');
        $itemA = $this->makeItem($wh1, 'Item A', 100, 100);
        $itemB = $this->makeItem($wh2, 'Item B', 0, 200);
        $itemC = $this->makeItem($wh3, 'Item C', 0, 50);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 20],
            ['item_id' => $itemB->id, 'description' => 'Item B', 'quantity' => 10],
            ['item_id' => $itemC->id, 'description' => 'Item C', 'quantity' => 7],
        ], 'RIS-DEL-1');

        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();
        $lineC = $ds->items()->where('item_id', $itemC->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-DEL-1',
                'condition_status'   => 'good',
                'quantity_delivered' => 37,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 20,
                        'unit_cost'          => 100,
                        'engas_unit_cost'    => 100,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-DEL-1-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh2->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 200,
                        'engas_unit_cost'    => 200,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-DEL-1-B',
                    ],
                    [
                        'ds_item_id'         => $lineC->id,
                        'warehouse_id'       => $wh3->id,
                        'quantity_delivered' => 7,
                        'unit_cost'          => 50,
                        'engas_unit_cost'    => 50,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-DEL-1-C',
                    ],
                ],
            ])->assertSessionHasNoErrors();

        // Dispatch added stock: A 100→120, B 0→10, C 0→7
        $this->assertEquals(120, (float) $itemA->fresh()->quantity);
        $this->assertEquals(10, (float) $itemB->fresh()->quantity);
        $this->assertEquals(7, (float) $itemC->fresh()->quantity);

        $delivery = Delivery::where('dr_number', 'DR-DEL-1')->firstOrFail();
        $this->assertEquals(3, StockCardEntry::where('reference_type', 'delivery')->where('reference_id', $delivery->id)->count());

        // Delete the subsidy → every item reverts, per item and per warehouse
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertDatabaseMissing('delivery_subsidies', ['id' => $ds->id]);
        $this->assertDatabaseMissing('deliveries', ['id' => $delivery->id]);

        $this->assertEquals(100, (float) $itemA->fresh()->quantity);
        // Items B and C existed only for this subsidy (0 stock before) → removed entirely.
        $this->assertNull($itemB->fresh());
        $this->assertNull($itemC->fresh());
        $this->assertDatabaseMissing('items', ['id' => $itemB->id]);
        $this->assertDatabaseMissing('items', ['id' => $itemC->id]);

        $this->assertEquals(0, StockCardEntry::where('reference_type', 'delivery')->where('reference_id', $delivery->id)->count());
    }

    public function test_delete_subsidy_without_deliveries_does_not_touch_stock(): void
    {
        $wh1   = $this->makeWarehouse('WH One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 50, 10);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 20],
        ], 'RIS-DEL-2');

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertEquals(50, (float) $itemA->fresh()->quantity);
        $this->assertEquals(0, StockCardEntry::where('item_id', $itemA->id)->count());
    }

    public function test_delete_subsidy_recomputes_remaining_stock_card_balances(): void
    {
        $wh1   = $this->makeWarehouse('WH One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Rice', 0, 30);

        // Pre-existing receipt of 50 (an older delivery that must stay intact)
        StockCardEntry::create([
            'item_id'            => $itemA->id,
            'entry_date'         => '2026-07-01',
            'reference'          => 'DR-OLD-2',
            'reference_type'     => 'delivery',
            'reference_id'       => 888,
            'receipt_qty'        => 50,
            'receipt_unit_cost'  => 30,
            'receipt_total_cost' => 1500,
            'issue_qty'          => 0,
            'balance_qty'        => 50,
            'balance_unit_cost'  => 30,
            'balance_total_cost' => 1500,
        ]);
        $itemA->update(['quantity' => 50]);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Rice', 'quantity' => 10],
        ], 'RIS-DEL-3');

        $lineA = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-DEL-3',
                'condition_status'   => 'good',
                'quantity_delivered' => 10,
                'items'              => [[
                    'ds_item_id'         => $lineA->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 10,
                    'unit_cost'          => 30,
                    'engas_unit_cost'    => 30,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-DEL-3',
                ]],
            ])->assertSessionHasNoErrors();

        $this->assertEquals(60, (float) $itemA->fresh()->quantity);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertEquals(50, (float) $itemA->fresh()->quantity);

        $this->assertEquals(1, StockCardEntry::where('item_id', $itemA->id)->count());
        $entry = StockCardEntry::where('item_id', $itemA->id)->first();
        $this->assertEquals(50, (float) $entry->balance_qty);
        $this->assertEquals(1500, (float) $entry->balance_total_cost);
    }
}
