<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test that Requested Quantity and Dispatched Quantity are completely
 * independent when Admin edits a Stock Transfer.
 *
 * Critical requirement: Editing the dispatched quantity must NEVER modify
 * the requested quantity (original demand). They must use separate fields,
 * separate logic, and remain independent.
 */
class StockTransferRequestedVsDispatchedSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Test Case 1: Admin corrects dispatched qty from 50 → 100
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_increasing_dispatched_qty(): void
    {
        // ── Setup ───────────────────────────────────────────────────────────
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        $sourceWh = Warehouse::factory()->create(['name' => 'Source WH']);
        $destWh   = Warehouse::factory()->create(['name' => 'Dest WH']);

        // Create source item with 150 units (so we can dispatch up to 100)
        $sourceItem = Item::factory()->create([
            'warehouse_id' => $sourceWh->id,
            'description'  => 'Family Food Pack',
            'unit'         => 'pack',
            'category'     => 'Relief',
            'quantity'     => 150,
            'unit_cost'    => 500.00,
        ]);

        // Create destination item (initially 0)
        $destItem = Item::factory()->create([
            'warehouse_id' => $destWh->id,
            'description'  => 'Family Food Pack',
            'unit'         => 'pack',
            'category'     => 'Relief',
            'quantity'     => 0,
            'unit_cost'    => 500.00,
        ]);

        // Create transfer with requested=100, dispatched=50
        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $sourceWh->id,
            'to_warehouse_id'   => $destWh->id,
            'status'            => 'partial',
        ]);

        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,   // ORIGINAL DEMAND
            'quantity'            => 50,    // DISPATCHED
            'unit_cost'           => 500.00,
        ]);

        // Simulate the dispatch: move 50 units from source to dest
        $sourceItem->update(['quantity' => 100]);  // 150 - 50
        $destItem->update(['quantity' => 50]);     // 0 + 50

        // Create stock card entries
        StockCardEntry::create([
            'item_id'        => $sourceItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_out',
            'reference_id'   => $transfer->id,
            'issue_qty'      => 50,
            'balance_qty'    => 100,
            'from_to'        => $destWh->name,
        ]);

        StockCardEntry::create([
            'item_id'        => $destItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_in',
            'reference_id'   => $transfer->id,
            'receipt_qty'    => 50,
            'balance_qty'    => 50,
            'from_to'        => $sourceWh->name,
        ]);

        // ── Execute: Admin corrects dispatched from 50 → 100 ───────────────
        $response = $this->put(route('transfers.update', $transfer), [
            'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
            'remarks'       => $transfer->remarks,
            'items'         => [
                [
                    'sti_id'    => $sti->id,
                    'quantity'  => 100,    // NEW DISPATCHED QTY
                    'unit_cost' => 500.00,
                ],
            ],
        ]);

        // ── Assert ──────────────────────────────────────────────────────────
        $response->assertRedirect(route('transfers.show', $transfer));
        $response->assertSessionHas('success');

        // CRITICAL: Requested quantity MUST remain 100 (unchanged)
        $sti->refresh();
        $this->assertEquals(100, $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(100, $sti->quantity, 'Dispatched qty must be 100');

        // Inventory reconciliation: source loses 50 more, dest gains 50 more
        $this->assertEquals(50, $sourceItem->fresh()->quantity, 'Source: 100 - 50 = 50');
        $this->assertEquals(100, $destItem->fresh()->quantity, 'Dest: 50 + 50 = 100');
    }

    /**
     * Test Case 2: Admin corrects dispatched qty from 50 → 80
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_partially_increasing_dispatched_qty(): void
    {
        // ── Setup ───────────────────────────────────────────────────────────
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        $sourceWh = Warehouse::factory()->create(['name' => 'Source WH']);
        $destWh   = Warehouse::factory()->create(['name' => 'Dest WH']);

        $sourceItem = Item::factory()->create([
            'warehouse_id' => $sourceWh->id,
            'description'  => 'Hygiene Kit',
            'quantity'     => 100,  // 150 - 50 already dispatched
            'unit_cost'    => 300.00,
        ]);

        $destItem = Item::factory()->create([
            'warehouse_id' => $destWh->id,
            'description'  => 'Hygiene Kit',
            'quantity'     => 50,   // already received 50
            'unit_cost'    => 300.00,
        ]);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $sourceWh->id,
            'to_warehouse_id'   => $destWh->id,
            'status'            => 'partial',
        ]);

        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,   // ORIGINAL DEMAND
            'quantity'            => 50,    // DISPATCHED
            'unit_cost'           => 300.00,
        ]);

        StockCardEntry::create([
            'item_id'        => $sourceItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_out',
            'reference_id'   => $transfer->id,
            'issue_qty'      => 50,
            'balance_qty'    => 100,
            'from_to'        => $destWh->name,
        ]);

        StockCardEntry::create([
            'item_id'        => $destItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_in',
            'reference_id'   => $transfer->id,
            'receipt_qty'    => 50,
            'balance_qty'    => 50,
            'from_to'        => $sourceWh->name,
        ]);

        // ── Execute: Admin corrects dispatched from 50 → 80 ────────────────
        $response = $this->put(route('transfers.update', $transfer), [
            'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
            'remarks'       => $transfer->remarks,
            'items'         => [
                [
                    'sti_id'    => $sti->id,
                    'quantity'  => 80,    // NEW DISPATCHED QTY
                    'unit_cost' => 300.00,
                ],
            ],
        ]);

        // ── Assert ──────────────────────────────────────────────────────────
        $response->assertRedirect(route('transfers.show', $transfer));

        $sti->refresh();
        $this->assertEquals(100, $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(80, $sti->quantity, 'Dispatched qty must be 80');

        // Additional 30 units transferred: source loses 30, dest gains 30
        $this->assertEquals(70, $sourceItem->fresh()->quantity, 'Source: 100 - 30 = 70');
        $this->assertEquals(80, $destItem->fresh()->quantity, 'Dest: 50 + 30 = 80');
    }

    /**
     * Test Case 3: Admin corrects dispatched qty from 100 → 75
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_decreasing_dispatched_qty(): void
    {
        // ── Setup ───────────────────────────────────────────────────────────
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        $sourceWh = Warehouse::factory()->create(['name' => 'Source WH']);
        $destWh   = Warehouse::factory()->create(['name' => 'Dest WH']);

        $sourceItem = Item::factory()->create([
            'warehouse_id' => $sourceWh->id,
            'description'  => 'Ready to Eat Foods',
            'quantity'     => 50,   // 150 - 100 already dispatched
            'unit_cost'    => 200.00,
        ]);

        $destItem = Item::factory()->create([
            'warehouse_id' => $destWh->id,
            'description'  => 'Ready to Eat Foods',
            'quantity'     => 100,  // already received 100
            'unit_cost'    => 200.00,
        ]);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $sourceWh->id,
            'to_warehouse_id'   => $destWh->id,
            'status'            => 'completed',
        ]);

        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,   // ORIGINAL DEMAND
            'quantity'            => 100,   // FULLY DISPATCHED
            'unit_cost'           => 200.00,
        ]);

        StockCardEntry::create([
            'item_id'        => $sourceItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_out',
            'reference_id'   => $transfer->id,
            'issue_qty'      => 100,
            'balance_qty'    => 50,
            'from_to'        => $destWh->name,
        ]);

        StockCardEntry::create([
            'item_id'        => $destItem->id,
            'entry_date'     => now(),
            'reference'      => $transfer->transfer_number,
            'reference_type' => 'transfer_in',
            'reference_id'   => $transfer->id,
            'receipt_qty'    => 100,
            'balance_qty'    => 100,
            'from_to'        => $sourceWh->name,
        ]);

        // ── Execute: Admin corrects dispatched from 100 → 75 ───────────────
        $response = $this->put(route('transfers.update', $transfer), [
            'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
            'remarks'       => $transfer->remarks,
            'items'         => [
                [
                    'sti_id'    => $sti->id,
                    'quantity'  => 75,    // NEW DISPATCHED QTY
                    'unit_cost' => 200.00,
                ],
            ],
        ]);

        // ── Assert ──────────────────────────────────────────────────────────
        $response->assertRedirect(route('transfers.show', $transfer));

        $sti->refresh();
        $this->assertEquals(100, $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(75, $sti->quantity, 'Dispatched qty must be 75');

        // 25 units returned: source gains 25, dest loses 25
        $this->assertEquals(75, $sourceItem->fresh()->quantity, 'Source: 50 + 25 = 75');
        $this->assertEquals(75, $destItem->fresh()->quantity, 'Dest: 100 - 25 = 75');
    }

    /**
     * Test Case 4: Multiple items — each with different requested vs dispatched.
     */
    public function test_requested_qty_unchanged_for_multiple_items(): void
    {
        // ── Setup ───────────────────────────────────────────────────────────
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        $sourceWh = Warehouse::factory()->create(['name' => 'Source WH']);
        $destWh   = Warehouse::factory()->create(['name' => 'Dest WH']);

        $items = [
            // Item 1: requested=50, dispatched=30 → correct to 50
            [
                'source' => Item::factory()->create([
                    'warehouse_id' => $sourceWh->id,
                    'description'  => 'Item A',
                    'quantity'     => 70,  // 100 - 30
                    'unit_cost'    => 100.00,
                ]),
                'dest' => Item::factory()->create([
                    'warehouse_id' => $destWh->id,
                    'description'  => 'Item A',
                    'quantity'     => 30,
                    'unit_cost'    => 100.00,
                ]),
                'requested'     => 50,
                'old_dispatched'=> 30,
                'new_dispatched'=> 50,
            ],
            // Item 2: requested=80, dispatched=60 → correct to 70
            [
                'source' => Item::factory()->create([
                    'warehouse_id' => $sourceWh->id,
                    'description'  => 'Item B',
                    'quantity'     => 40,  // 100 - 60
                    'unit_cost'    => 150.00,
                ]),
                'dest' => Item::factory()->create([
                    'warehouse_id' => $destWh->id,
                    'description'  => 'Item B',
                    'quantity'     => 60,
                    'unit_cost'    => 150.00,
                ]),
                'requested'     => 80,
                'old_dispatched'=> 60,
                'new_dispatched'=> 70,
            ],
            // Item 3: requested=100, dispatched=100 → correct to 90
            [
                'source' => Item::factory()->create([
                    'warehouse_id' => $sourceWh->id,
                    'description'  => 'Item C',
                    'quantity'     => 0,   // 100 - 100
                    'unit_cost'    => 200.00,
                ]),
                'dest' => Item::factory()->create([
                    'warehouse_id' => $destWh->id,
                    'description'  => 'Item C',
                    'quantity'     => 100,
                    'unit_cost'    => 200.00,
                ]),
                'requested'     => 100,
                'old_dispatched'=> 100,
                'new_dispatched'=> 90,
            ],
        ];

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => $sourceWh->id,
            'to_warehouse_id'   => $destWh->id,
            'status'            => 'partial',
        ]);

        $stiData = [];
        foreach ($items as $item) {
            $sti = StockTransferItem::create([
                'stock_transfer_id'   => $transfer->id,
                'item_id'             => $item['source']->id,
                'destination_item_id' => $item['dest']->id,
                'quantity_requested'  => $item['requested'],
                'quantity'            => $item['old_dispatched'],
                'unit_cost'           => $item['source']->unit_cost,
            ]);

            StockCardEntry::create([
                'item_id'        => $item['source']->id,
                'entry_date'     => now(),
                'reference'      => $transfer->transfer_number,
                'reference_type' => 'transfer_out',
                'reference_id'   => $transfer->id,
                'issue_qty'      => $item['old_dispatched'],
                'balance_qty'    => $item['source']->quantity,
                'from_to'        => $destWh->name,
            ]);

            StockCardEntry::create([
                'item_id'        => $item['dest']->id,
                'entry_date'     => now(),
                'reference'      => $transfer->transfer_number,
                'reference_type' => 'transfer_in',
                'reference_id'   => $transfer->id,
                'receipt_qty'    => $item['old_dispatched'],
                'balance_qty'    => $item['dest']->quantity,
                'from_to'        => $sourceWh->name,
            ]);

            $stiData[] = [
                'sti_id'    => $sti->id,
                'quantity'  => $item['new_dispatched'],
                'unit_cost' => $item['source']->unit_cost,
            ];
        }

        // ── Execute: Admin corrects all items ──────────────────────────────
        $response = $this->put(route('transfers.update', $transfer), [
            'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
            'remarks'       => $transfer->remarks,
            'items'         => $stiData,
        ]);

        // ── Assert ──────────────────────────────────────────────────────────
        $response->assertRedirect(route('transfers.show', $transfer));

        $transfer->refresh()->load('items');

        // Item 1: requested stays 50, dispatched corrected to 50
        $this->assertEquals(50, $transfer->items[0]->quantity_requested);
        $this->assertEquals(50, $transfer->items[0]->quantity);
        $this->assertEquals(50, $items[0]['source']->fresh()->quantity);  // 70 - 20
        $this->assertEquals(50, $items[0]['dest']->fresh()->quantity);    // 30 + 20

        // Item 2: requested stays 80, dispatched corrected to 70
        $this->assertEquals(80, $transfer->items[1]->quantity_requested);
        $this->assertEquals(70, $transfer->items[1]->quantity);
        $this->assertEquals(30, $items[1]['source']->fresh()->quantity);  // 40 - 10
        $this->assertEquals(70, $items[1]['dest']->fresh()->quantity);    // 60 + 10

        // Item 3: requested stays 100, dispatched corrected to 90
        $this->assertEquals(100, $transfer->items[2]->quantity_requested);
        $this->assertEquals(90, $transfer->items[2]->quantity);
        $this->assertEquals(10, $items[2]['source']->fresh()->quantity);  // 0 + 10
        $this->assertEquals(90, $items[2]['dest']->fresh()->quantity);    // 100 - 10
    }
}

