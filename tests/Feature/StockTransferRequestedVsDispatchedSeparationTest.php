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

    private static int $counter = 0;

    private function makeAdmin(): User
    {
        self::$counter++;
        return User::create([
            'username'  => 'sep_admin_' . self::$counter,
            'name'      => 'Sep Admin ' . self::$counter,
            'password'  => bcrypt('secret'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name): Warehouse
    {
        self::$counter++;
        return Warehouse::create([
            'name'      => $name,
            'code'      => 'SEP' . self::$counter,
            'place'     => null,
            'is_active' => true,
        ]);
    }

    private function makeItem(Warehouse $wh, string $desc, float $qty, float $cost = 100.0, string $category = 'food'): Item
    {
        return Item::create([
            'description'    => $desc,
            'unit'           => 'pack',
            'category'       => $category,
            'account_code'   => '1040202000-01',
            'warehouse_id'   => $wh->id,
            'unit_cost'      => $cost,
            'quantity'       => $qty,
            'is_active'      => true,
        ]);
    }

    private function makeTransfer(Warehouse $from, Warehouse $to, string $status = 'partial'): StockTransfer
    {
        self::$counter++;
        return StockTransfer::create([
            'transfer_number'  => 'TRF-TEST-' . self::$counter,
            'from_warehouse_id'=> $from->id,
            'to_warehouse_id'  => $to->id,
            'transfer_date'    => now()->toDateString(),
            'transferred_by'   => $this->makeAdmin()->id,
            'status'           => $status,
        ]);
    }

    private function addStockCardEntries(StockTransfer $t, Item $src, Item $dst, int $qty): void
    {
        StockCardEntry::create([
            'item_id'        => $src->id,
            'entry_date'     => now(),
            'reference'      => $t->transfer_number,
            'reference_type' => 'transfer_out',
            'reference_id'   => $t->id,
            'issue_qty'      => $qty,
            'balance_qty'    => $src->quantity,
            'from_to'        => $t->toWarehouse?->name ?? 'Dest',
        ]);
        StockCardEntry::create([
            'item_id'        => $dst->id,
            'entry_date'     => now(),
            'reference'      => $t->transfer_number,
            'reference_type' => 'transfer_in',
            'reference_id'   => $t->id,
            'receipt_qty'    => $qty,
            'balance_qty'    => $dst->quantity,
            'from_to'        => $t->fromWarehouse?->name ?? 'Source',
        ]);
    }

    /**
     * Test Case 1: Admin corrects dispatched qty from 50 → 100
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_increasing_dispatched_qty(): void
    {
        $admin    = $this->makeAdmin();
        $sourceWh = $this->makeWarehouse('Source WH A');
        $destWh   = $this->makeWarehouse('Dest WH A');

        // Create source item with 100 units (150 - 50 already dispatched)
        $sourceItem = $this->makeItem($sourceWh, 'Family Food Pack', 100, 500.0);
        // Create destination item with 50 units (already received)
        $destItem   = $this->makeItem($destWh, 'Family Food Pack', 50, 500.0);

        $transfer = $this->makeTransfer($sourceWh, $destWh, 'partial');

        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,   // ORIGINAL DEMAND
            'quantity'            => 50,    // DISPATCHED so far
            'unit_cost'           => 500.0,
        ]);

        $this->addStockCardEntries($transfer, $sourceItem, $destItem, 50);

        // ── Execute: Admin corrects dispatched from 50 → 100 ───────────────
        $this->actingAs($admin)
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
                'remarks'       => null,
                'items'         => [[
                    'sti_id'             => $sti->id,
                    'quantity_requested' => 100,
                    'quantity'           => 100,
                    'unit_cost'          => 500.0,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer));

        // CRITICAL: Requested quantity MUST remain 100 (unchanged)
        $sti->refresh();
        $this->assertEquals(100, (float) $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(100, (float) $sti->quantity, 'Dispatched qty must be 100');

        // Inventory: source loses 50 more (delta=+50), dest gains 50 more
        $this->assertEquals(50,  (float) $sourceItem->fresh()->quantity, 'Source: 100 - 50 = 50');
        $this->assertEquals(100, (float) $destItem->fresh()->quantity, 'Dest: 50 + 50 = 100');
    }

    /**
     * Test Case 2: Admin corrects dispatched qty from 50 → 80
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_partially_increasing_dispatched_qty(): void
    {
        $admin    = $this->makeAdmin();
        $sourceWh = $this->makeWarehouse('Source WH B');
        $destWh   = $this->makeWarehouse('Dest WH B');

        $sourceItem = $this->makeItem($sourceWh, 'Hygiene Kit', 100, 300.0); // 150-50 dispatched
        $destItem   = $this->makeItem($destWh,   'Hygiene Kit', 50,  300.0); // 50 received

        $transfer = $this->makeTransfer($sourceWh, $destWh, 'partial');
        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,
            'quantity'            => 50,
            'unit_cost'           => 300.0,
        ]);
        $this->addStockCardEntries($transfer, $sourceItem, $destItem, 50);

        $this->actingAs($admin)
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
                'remarks'       => null,
                'items'         => [[
                    'sti_id'             => $sti->id,
                    'quantity_requested' => 100,
                    'quantity'           => 80,
                    'unit_cost'          => 300.0,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer));

        $sti->refresh();
        $this->assertEquals(100, (float) $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(80,  (float) $sti->quantity, 'Dispatched qty must be 80');

        // Additional 30 dispatched: source loses 30, dest gains 30
        $this->assertEquals(70, (float) $sourceItem->fresh()->quantity, 'Source: 100 - 30 = 70');
        $this->assertEquals(80, (float) $destItem->fresh()->quantity,   'Dest: 50 + 30 = 80');
    }

    /**
     * Test Case 3: Admin corrects dispatched qty from 100 → 75
     * while requested qty remains 100.
     */
    public function test_requested_qty_unchanged_when_decreasing_dispatched_qty(): void
    {
        $admin    = $this->makeAdmin();
        $sourceWh = $this->makeWarehouse('Source WH C');
        $destWh   = $this->makeWarehouse('Dest WH C');

        $sourceItem = $this->makeItem($sourceWh, 'Ready to Eat Foods', 50,  200.0); // 150-100 dispatched
        $destItem   = $this->makeItem($destWh,   'Ready to Eat Foods', 100, 200.0); // 100 received

        $transfer = $this->makeTransfer($sourceWh, $destWh, 'completed');
        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $sourceItem->id,
            'destination_item_id' => $destItem->id,
            'quantity_requested'  => 100,
            'quantity'            => 100,
            'unit_cost'           => 200.0,
        ]);
        $this->addStockCardEntries($transfer, $sourceItem, $destItem, 100);

        $this->actingAs($admin)
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
                'remarks'       => null,
                'items'         => [[
                    'sti_id'             => $sti->id,
                    'quantity_requested' => 100,
                    'quantity'           => 75,
                    'unit_cost'          => 200.0,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer));

        $sti->refresh();
        $this->assertEquals(100, (float) $sti->quantity_requested, 'Requested qty must remain 100');
        $this->assertEquals(75,  (float) $sti->quantity, 'Dispatched qty must be 75');

        // 25 units returned: source gains 25, dest loses 25
        $this->assertEquals(75, (float) $sourceItem->fresh()->quantity, 'Source: 50 + 25 = 75');
        $this->assertEquals(75, (float) $destItem->fresh()->quantity,   'Dest: 100 - 25 = 75');
    }

    /**
     * Test Case 4: Multiple items — each with different requested vs dispatched.
     */
    public function test_requested_qty_unchanged_for_multiple_items(): void
    {
        $admin    = $this->makeAdmin();
        $sourceWh = $this->makeWarehouse('Source WH D');
        $destWh   = $this->makeWarehouse('Dest WH D');

        // Item 1: requested=50, dispatched=30 → correct to 50
        $src1 = $this->makeItem($sourceWh, 'Item A', 70,  100.0); // 100-30
        $dst1 = $this->makeItem($destWh,   'Item A', 30,  100.0);
        // Item 2: requested=80, dispatched=60 → correct to 70
        $src2 = $this->makeItem($sourceWh, 'Item B', 40,  150.0); // 100-60
        $dst2 = $this->makeItem($destWh,   'Item B', 60,  150.0);
        // Item 3: requested=100, dispatched=100 → correct to 90
        $src3 = $this->makeItem($sourceWh, 'Item C', 0,   200.0); // 100-100
        $dst3 = $this->makeItem($destWh,   'Item C', 100, 200.0);

        $transfer = $this->makeTransfer($sourceWh, $destWh, 'partial');

        $sti1 = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $src1->id,
            'destination_item_id' => $dst1->id,
            'quantity_requested'  => 50,
            'quantity'            => 30,
            'unit_cost'           => 100.0,
        ]);
        $sti2 = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $src2->id,
            'destination_item_id' => $dst2->id,
            'quantity_requested'  => 80,
            'quantity'            => 60,
            'unit_cost'           => 150.0,
        ]);
        $sti3 = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $src3->id,
            'destination_item_id' => $dst3->id,
            'quantity_requested'  => 100,
            'quantity'            => 100,
            'unit_cost'           => 200.0,
        ]);

        $this->addStockCardEntries($transfer, $src1, $dst1, 30);
        $this->addStockCardEntries($transfer, $src2, $dst2, 60);
        $this->addStockCardEntries($transfer, $src3, $dst3, 100);

        $this->actingAs($admin)
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => $transfer->transfer_date->format('Y-m-d'),
                'remarks'       => null,
                'items'         => [
                    ['sti_id' => $sti1->id, 'quantity_requested' => 50, 'quantity' => 50, 'unit_cost' => 100.0],
                    ['sti_id' => $sti2->id, 'quantity_requested' => 80, 'quantity' => 70, 'unit_cost' => 150.0],
                    ['sti_id' => $sti3->id, 'quantity_requested' => 100, 'quantity' => 90, 'unit_cost' => 200.0],
                ],
            ])
            ->assertRedirect(route('transfers.show', $transfer));

        // Item 1: requested stays 50, dispatched corrected to 50
        $this->assertEquals(50, (float) $sti1->fresh()->quantity_requested);
        $this->assertEquals(50, (float) $sti1->fresh()->quantity);
        $this->assertEquals(50, (float) $src1->fresh()->quantity);  // 70 - 20
        $this->assertEquals(50, (float) $dst1->fresh()->quantity);  // 30 + 20

        // Item 2: requested stays 80, dispatched corrected to 70
        $this->assertEquals(80, (float) $sti2->fresh()->quantity_requested);
        $this->assertEquals(70, (float) $sti2->fresh()->quantity);
        $this->assertEquals(30, (float) $src2->fresh()->quantity);  // 40 - 10
        $this->assertEquals(70, (float) $dst2->fresh()->quantity);  // 60 + 10

        // Item 3: requested stays 100, dispatched corrected to 90
        $this->assertEquals(100, (float) $sti3->fresh()->quantity_requested);
        $this->assertEquals(90,  (float) $sti3->fresh()->quantity);
        $this->assertEquals(10,  (float) $src3->fresh()->quantity);  // 0 + 10
        $this->assertEquals(90,  (float) $dst3->fresh()->quantity);  // 100 - 10
    }
}
