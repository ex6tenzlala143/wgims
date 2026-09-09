<?php

namespace Tests\Feature;

use App\Models\DeliveryItem;
use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EngasTotalBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'engas_admin_' . $i,
            'name'     => 'Engas Admin ' . $i,
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
            'unit'            => 'unit',
            'category'        => 'non-food',
            'account_code'    => '1040202000-02',
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
        $supplier = Supplier::create(['name' => 'Engas Supplier ' . $ris, 'is_active' => true]);

        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'description'     => $line['description'],
                'unit'            => 'unit',
                'category'        => 'non-food',
                'quantity'        => $line['quantity'],
                'expiration_date' => null,
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
                'expiration_date'    => $line['expiration_date'] ?? null,
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

    private function breakdownSection(string $html): string
    {
        $start = strpos($html, 'Partial Delivery Breakdown by Item');
        // The Shipment Records section was removed; the admin edit modal
        // now directly follows the breakdown card.
        $end   = strpos($html, 'id="editModal"');
        $this->assertNotFalse($start);
        if ($end === false) {
            return substr($html, $start);
        }

        return substr($html, $start, $end - $start);
    }

    private function rowsContaining(string $html, string $needle): array
    {
        $rows = [];
        foreach (preg_split('/<tr[^>]*>/', $html) as $row) {
            if (str_contains($row, $needle)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * The reported Family Tents case: two shipments at DIFFERENT unit costs.
     *   Shipment 1: 167 × ₱20,357.99 = ₱3,399,784.33
     *   Shipment 2:  16 × ₱19,050.00 = ₱304,800.00
     * Cumulative ENGAS must be ₱3,704,584.33 — NOT total qty × one cost.
     */
    public function test_cumulative_engas_sums_each_shipment_qty_times_its_own_engas_cost(): void
    {
        $wh   = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Family Tents', 20357.99);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Family Tents', 'quantity' => 200],
        ], 'RIS-ENGAS-TENT');

        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-TENT-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 167, 'unit_cost' => 20357.99, 'engas_unit_cost' => 20357.99,
            'dr_number' => 'DR-TENT-1-A',
        ]]);
        $this->dispatch($ds, 'DR-TENT-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 16, 'unit_cost' => 19050.00, 'engas_unit_cost' => 19050.00,
            'dr_number' => 'DR-TENT-2-A',
        ]]);

        // Per-row accessor: each shipment's own qty × its own ENGAS unit cost.
        $rowA = DeliveryItem::where('dr_number', 'DR-TENT-1-A')->sole();
        $rowB = DeliveryItem::where('dr_number', 'DR-TENT-2-A')->sole();
        $this->assertEquals(3399784.33, $rowA->engas_total_value);
        $this->assertEquals(304800.00, $rowB->engas_total_value);

        $html    = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        // Each shipment row shows its own ENGAS total…
        $rowAHtml = $this->rowsContaining($section, 'DR-TENT-1-A');
        $rowBHtml = $this->rowsContaining($section, 'DR-TENT-2-A');
        $this->assertCount(1, $rowAHtml);
        $this->assertCount(1, $rowBHtml);
        $this->assertStringContainsString('3,399,784.33', $rowAHtml[0]);
        $this->assertStringContainsString('304,800.00', $rowBHtml[0]);

        // …and the item's cumulative ENGAS total is the SUM of the shipments.
        $this->assertStringContainsString('3,704,584.33', $section);

        // It must NEVER be total delivered qty × one ENGAS cost.
        $this->assertStringNotContainsString('3,486,150.00', $section);  // 183 × 19,050.00
        $this->assertStringNotContainsString('3,725,511.17', $section);  // 183 × 20,357.99

        // The Shipment Records section was removed; the per-item breakdown
        // above remains the source of these ENGAS totals.
        $this->assertStringNotContainsString('Shipment Records', $html);
        $this->assertStringContainsString('3,399,784.33', $section);
        $this->assertStringContainsString('304,800.00', $section);
    }

    /**
     * When ENGAS differs from the purchase unit cost, totals must follow the
     * ENGAS cost of each shipment — never the unit cost column.
     */
    public function test_engas_totals_use_each_shipments_engas_cost_not_unit_cost(): void
    {
        $wh   = $this->makeWarehouse('GAMC2', 'GAMC2');
        $item = $this->makeItem($wh, 'Relief Goods', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Relief Goods', 'quantity' => 100],
        ], 'RIS-ENGAS-DIFF');

        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-DIFF-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 10, 'unit_cost' => 100.00, 'engas_unit_cost' => 60.00,
            'dr_number' => 'DR-DIFF-1-A',
        ]]);
        $this->dispatch($ds, 'DR-DIFF-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 5, 'unit_cost' => 200.00, 'engas_unit_cost' => 90.00,
            'dr_number' => 'DR-DIFF-2-A',
        ]]);

        $html    = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        $rowA = $this->rowsContaining($section, 'DR-DIFF-1-A')[0];
        $rowB = $this->rowsContaining($section, 'DR-DIFF-2-A')[0];
        // ENGAS totals: 10×60=600 and 5×90=450 (NOT 10×100 / 5×200).
        // (The Value column correctly still shows qty × UNIT cost.)
        $this->assertStringContainsString('600.00', $rowA);
        $this->assertStringContainsString('450.00', $rowB);

        // Cumulative ENGAS = 600 + 450 = 1,050 (never 15 × one single cost).
        $this->assertStringContainsString('1,050.00', $section);
        $this->assertStringNotContainsString('1,350.00', $section); // 15 × 90
        $this->assertStringNotContainsString('900.00', $section);   // 15 × 60
    }

    /**
     * Legacy rows whose stored engas_total_cost snapshot is NULL or stale must
     * still display qty × their own ENGAS unit cost — without rewriting the
     * historical record.
     */
    public function test_legacy_rows_with_missing_or_stale_stored_engas_total_display_correctly(): void
    {
        $wh   = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Family Tents', 20357.99);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Family Tents', 'quantity' => 200],
        ], 'RIS-ENGAS-LEGACY');

        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-LEG-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 167, 'unit_cost' => 20357.99, 'engas_unit_cost' => 20357.99,
            'dr_number' => 'DR-LEG-1-A',
        ]]);
        $this->dispatch($ds, 'DR-LEG-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 16, 'unit_cost' => 19050.00, 'engas_unit_cost' => 19050.00,
            'dr_number' => 'DR-LEG-2-A',
        ]]);

        // Simulate pre-migration / drifted history: NULL on one row, stale on the other.
        DB::table('delivery_items')->where('dr_number', 'DR-LEG-1-A')->update(['engas_total_cost' => null]);
        DB::table('delivery_items')->where('dr_number', 'DR-LEG-2-A')->update(['engas_total_cost' => 12345.67]);

        $html    = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        $rowA = $this->rowsContaining($section, 'DR-LEG-1-A')[0];
        $rowB = $this->rowsContaining($section, 'DR-LEG-2-A')[0];
        $this->assertStringContainsString('3,399,784.33', $rowA); // derived, not NULL-dash
        $this->assertStringContainsString('304,800.00', $rowB);   // derived, not the stale 12,345.67
        $this->assertStringNotContainsString('12,345.67', $section);

        // Cumulative still equals Σ (qty × own ENGAS).
        $this->assertStringContainsString('3,704,584.33', $section);
    }

    /**
     * Multiple items across multiple subsidies: every line's cumulative ENGAS
     * only ever sums ITS OWN shipments — no cross-item or cross-subsidy bleed.
     */
    public function test_engas_totals_stay_independent_per_item_and_subsidy(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $tents = $this->makeItem($wh, 'Family Tents', 20357.99);
        $goods = $this->makeItem($wh, 'Relief Goods', 500);

        $subA = $this->createSubsidy([
            ['item_id' => $tents->id, 'description' => 'Family Tents', 'quantity' => 200],
            ['item_id' => $goods->id, 'description' => 'Relief Goods', 'quantity' => 30],
        ], 'RIS-ENGAS-MIX');
        $subB = $this->createSubsidy([
            ['item_id' => $tents->id, 'description' => 'Family Tents', 'quantity' => 10],
        ], 'RIS-ENGAS-MIXB');

        $tentsLine = $subA->items()->where('description', 'Family Tents')->firstOrFail();
        $goodsLine = $subA->items()->where('description', 'Relief Goods')->firstOrFail();
        $bLine     = $subB->items()->firstOrFail();

        $this->dispatch($subA, 'DR-MIX-1', [
            [
                'ds_item_id' => $tentsLine->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 167, 'unit_cost' => 20357.99, 'engas_unit_cost' => 20357.99,
                'dr_number' => 'DR-MIX-1-A',
            ],
            [
                'ds_item_id' => $goodsLine->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 30, 'unit_cost' => 500.00, 'engas_unit_cost' => 400.00,
                'dr_number' => 'DR-MIX-1-B',
            ],
        ]);
        $this->dispatch($subA, 'DR-MIX-2', [[
            'ds_item_id' => $tentsLine->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 16, 'unit_cost' => 19050.00, 'engas_unit_cost' => 19050.00,
            'dr_number' => 'DR-MIX-2-A',
        ]]);
        $this->dispatch($subB, 'DR-MIXB-1', [[
            'ds_item_id' => $bLine->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 10, 'unit_cost' => 21000.00, 'engas_unit_cost' => 21000.00,
            'dr_number' => 'DR-MIXB-1-A',
        ]]);

        $htmlA   = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $subA))->getContent();
        $secA    = $this->breakdownSection($htmlA);

        // Split the section into per-item blocks (header-to-header).
        $tentsPos = strpos($secA, 'Family Tents');
        $goodsPos = strpos($secA, 'Relief Goods');
        $this->assertNotFalse($tentsPos);
        $this->assertNotFalse($goodsPos);
        $tentsBlock = substr($secA, $tentsPos, $goodsPos - $tentsPos);
        $goodsBlock = substr($secA, $goodsPos);

        // Tents block: cumulative 3,704,584.33 only from its OWN two shipments.
        $this->assertStringContainsString('3,704,584.33', $tentsBlock);
        // Goods block: 30 × 400 = 12,000 — tents' numbers never leak into it.
        $this->assertStringContainsString('12,000.00', $goodsBlock);
        $this->assertStringNotContainsString('3,704,584.33', $goodsBlock);

        // Subsidy B shows ONLY its own shipment's ENGAS (10 × 21,000 = 210,000),
        // never Subsidy A's 3,704,584.33.
        $htmlB = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $subB))->getContent();
        $secB  = $this->breakdownSection($htmlB);
        $this->assertStringContainsString('210,000.00', $secB);
        $this->assertStringNotContainsString('3,704,584.33', $secB);
    }
}
