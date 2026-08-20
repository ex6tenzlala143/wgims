<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySubsidyOrderedItemsWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'ordwh_admin_' . $i,
            'name'     => 'Ord Wh Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description, float $cost = 10.0): Item
    {
        return Item::create([
            'stock_number'    => null,
            'description'     => $description,
            'unit'            => 'piece',
            'category'        => 'food',
            'account_code'    => '1040202000-01',
            'warehouse_id'    => $wh->id,
            'unit_cost'       => $cost,
            'engas_unit_cost' => null,
            'quantity'        => 0,
            'expiration_date' => null,
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

    public function test_ordered_item_lists_all_dispatch_warehouses_deduplicated(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $wh2 = $this->makeWarehouse('GAMC2', 'GAMC2');
        $item = $this->makeItem($wh1, 'Family Food Packs', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Family Food Packs', 'quantity' => 60],
        ], 'RIS-ORDWH-1');

        $line = $ds->items()->firstOrFail();

        // 25 → GAMC1 @ ₱700/₱710, 25 → GAMC2 @ ₱600/₱600, then 10 more → GAMC1 @ ₱700/₱710.
        $this->dispatch($ds, 'DR-ORDWH-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 25, 'unit_cost' => 700, 'engas_unit_cost' => 710, 'dr_number' => 'DR-ORDWH-1-A',
        ]]);
        $this->dispatch($ds, 'DR-ORDWH-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh2->id,
            'quantity_delivered' => 25, 'unit_cost' => 600, 'engas_unit_cost' => 600, 'dr_number' => 'DR-ORDWH-2-A',
        ]]);
        $this->dispatch($ds, 'DR-ORDWH-3', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 10, 'unit_cost' => 700, 'engas_unit_cost' => 710, 'dr_number' => 'DR-ORDWH-3-A',
        ]]);

        $ds->load(['items.deliveryItems.warehouse']);
        $poi = $ds->items->first();

        // GAMC1 appears twice across shipments but must be listed only once.
        $this->assertEquals(['GAMC1', 'GAMC2'], $poi->dispatch_warehouses->pluck('name')->values()->all());

        // Every warehouse keeps ITS OWN unit cost / ENGAS cost, never merged.
        $summary = $poi->dispatch_summary;
        $this->assertCount(2, $summary);
        $rowGamc1 = $summary->firstWhere('warehouse_name', 'GAMC1');
        $rowGamc2 = $summary->firstWhere('warehouse_name', 'GAMC2');
        $this->assertEquals(35, $rowGamc1['quantity']);
        $this->assertEquals(700.0, $rowGamc1['unit_cost']);
        $this->assertEquals(710.0, $rowGamc1['engas_unit_cost']);
        $this->assertEquals(25, $rowGamc2['quantity']);
        $this->assertEquals(600.0, $rowGamc2['unit_cost']);
        $this->assertEquals(600.0, $rowGamc2['engas_unit_cost']);

        // The show page's Ordered Items section must show BOTH warehouses and
        // BOTH sets of costs.
        $response = $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.show', $ds));
        $html      = $response->getContent();
        $start     = strpos($html, 'Ordered Items');
        $end       = strpos($html, 'Partial Delivery Breakdown by Item');
        $orderedSection = substr($html, $start, $end - $start);
        $this->assertStringContainsString('GAMC1', $orderedSection);
        $this->assertStringContainsString('GAMC2', $orderedSection);
        $this->assertStringContainsString('₱700.00', $orderedSection);
        $this->assertStringContainsString('₱600.00', $orderedSection);
        $this->assertStringContainsString('₱710.00', $orderedSection);
    }

    public function test_ordered_item_keeps_different_costs_for_the_same_warehouse(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh1, 'Family Food Packs', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Family Food Packs', 'quantity' => 60],
        ], 'RIS-ORDWH-SW');

        $line = $ds->items()->firstOrFail();

        // Two shipments to the SAME warehouse but at DIFFERENT costs.
        $this->dispatch($ds, 'DR-ORDWH-SW-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 25, 'unit_cost' => 700, 'engas_unit_cost' => 710, 'dr_number' => 'DR-ORDWH-SW-1-A',
        ]]);
        $this->dispatch($ds, 'DR-ORDWH-SW-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 25, 'unit_cost' => 600, 'engas_unit_cost' => 600, 'dr_number' => 'DR-ORDWH-SW-2-A',
        ]]);

        $ds->load(['items.deliveryItems.warehouse']);
        $poi = $ds->items->first();

        // Two summary rows for the same warehouse, each keeping its own cost —
        // the two shipments are NOT merged and no cost overwrites the other.
        $summary = $poi->dispatch_summary;
        $this->assertCount(2, $summary);
        $this->assertSame(['GAMC1', 'GAMC1'], $summary->pluck('warehouse_name')->all());
        $this->assertSame([700.0, 600.0], $summary->pluck('unit_cost')->all());
        $this->assertSame([710.0, 600.0], $summary->pluck('engas_unit_cost')->all());

        $this->assertEquals(['GAMC1'], $poi->dispatch_warehouses->pluck('name')->values()->all());
    }

    public function test_ordered_item_with_single_dispatch_shows_one_warehouse(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh1, 'Rice', 50);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 20],
        ], 'RIS-ORDWH-2');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-ORDWH-S', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 20, 'unit_cost' => 50, 'dr_number' => 'DR-ORDWH-S-A',
        ]]);

        $ds->load(['items.deliveryItems.warehouse']);
        $this->assertEquals(['GAMC1'], $ds->items->first()->dispatch_warehouses->pluck('name')->values()->all());
    }

    public function test_ordered_items_with_different_warehouses_per_item(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $wh2 = $this->makeWarehouse('GAMC2', 'GAMC2');
        $itemA = $this->makeItem($wh1, 'Food Pack A', 10);
        $itemB = $this->makeItem($wh2, 'Food Pack B', 20);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Food Pack A', 'quantity' => 10],
            ['item_id' => $itemB->id, 'description' => 'Food Pack B', 'quantity' => 10],
        ], 'RIS-ORDWH-3');

        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();

        $this->dispatch($ds, 'DR-ORDWH-A', [[
            'ds_item_id' => $lineA->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 10, 'unit_cost' => 10, 'dr_number' => 'DR-ORDWH-A-1',
        ]]);
        $this->dispatch($ds, 'DR-ORDWH-B', [[
            'ds_item_id' => $lineB->id, 'warehouse_id' => $wh2->id,
            'quantity_delivered' => 10, 'unit_cost' => 20, 'dr_number' => 'DR-ORDWH-B-1',
        ]]);

        $ds->load(['items.deliveryItems.warehouse']);
        $byItem = $ds->items->keyBy('id');
        $this->assertEquals(['GAMC1'], $byItem[$lineA->id]->dispatch_warehouses->pluck('name')->values()->all());
        $this->assertEquals(['GAMC2'], $byItem[$lineB->id]->dispatch_warehouses->pluck('name')->values()->all());
    }

    public function test_ordered_item_without_dispatches_has_no_warehouses(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh1, 'Coffee', 300);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Coffee', 'quantity' => 15],
        ], 'RIS-ORDWH-4');

        $ds->load(['items.deliveryItems.warehouse']);
        $this->assertTrue($ds->items->first()->dispatch_warehouses->isEmpty());
    }
}
