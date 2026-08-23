<?php

namespace Tests\Feature;

use App\Models\DeliveryItem;
use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryBreakdownExpirationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'exp_admin_' . $i,
            'name'     => 'Exp Admin ' . $i,
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
            'unit'            => 'sack',
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
        $supplier = Supplier::create(['name' => 'Exp Supplier ' . $ris, 'is_active' => true]);

        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'description'     => $line['description'],
                'unit'            => 'sack',
                'category'        => 'food',
                'quantity'        => $line['quantity'],
                'expiration_date' => $line['expiration_date'] ?? null,
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

    /**
     * Record a shipment. Supports several batch lines for the SAME ds_item_id
     * inside one shipment (the addBatch flow).
     */
    private function dispatch(DeliverySubsidy $ds, string $dr, array $lines): void
    {
        $items = [];
        foreach ($lines as $key => $line) {
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

    /** @return string the "Partial Delivery Breakdown by Item" card HTML */
    private function breakdownSection(string $html): string
    {
        $start = strpos($html, 'Partial Delivery Breakdown by Item');
        $end   = strpos($html, 'Shipment Records');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * Rows of an HTML table keyed by a needle (e.g. the DR number) so we can
     * assert what EACH row displays instead of just the whole section.
     *
     * @return array<int, string>
     */
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
     * The user's exact example: Rice under one Subsidy delivered in TWO batches
     * with DIFFERENT expiration dates (100 @ 2026-12-31, 200 @ 2027-06-30).
     * Each breakdown row must show ITS OWN batch expiration — never one shared
     * date — and the batches must stay separate stock records.
     */
    public function test_two_batches_same_item_show_their_own_expiration_dates(): void
    {
        $wh  = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Rice', 900);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 300],
        ], 'RIS-EXPRICE');

        $line = $ds->items()->firstOrFail();

        // ONE shipment, TWO batch lines of the same ordered item.
        $this->dispatch($ds, 'DR-EXPRICE-1', [
            [
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 100, 'unit_cost' => 900,
                'expiration_date' => '2026-12-31', 'dr_number' => 'DR-EXPRICE-1-A',
            ],
            [
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 200, 'unit_cost' => 900,
                'expiration_date' => '2027-06-30', 'dr_number' => 'DR-EXPRICE-1-B',
            ],
        ]);

        // The two batches are distinct stock records, each with its own expiry…
        $batches = Item::where('description', 'Rice')->whereNotNull('stock_number')->get();
        $this->assertCount(2, $batches);
        $this->assertEquals(
            ['2026-12-31', '2027-06-30'],
            $batches->sortBy('expiration_date')->map(fn ($b) => $b->expiration_date->format('Y-m-d'))->values()->all()
        );
        // …and quantities were not disturbed by the display fix.
        $this->assertEquals(100, $batches->first(fn ($b) => $b->expiration_date->format('Y-m-d') === '2026-12-31')->quantity);
        $this->assertEquals(200, $batches->first(fn ($b) => $b->expiration_date->format('Y-m-d') === '2027-06-30')->quantity);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        // Row A → Dec 31, 2026 only; Row B → Jun 30, 2027 only.
        $rowA = $this->rowsContaining($section, 'DR-EXPRICE-1-A');
        $rowB = $this->rowsContaining($section, 'DR-EXPRICE-1-B');
        $this->assertCount(1, $rowA);
        $this->assertCount(1, $rowB);
        $this->assertStringContainsString('Dec 31, 2026', $rowA[0]);
        $this->assertStringNotContainsString('Jun 30, 2027', $rowA[0]);
        $this->assertStringContainsString('Jun 30, 2027', $rowB[0]);
        $this->assertStringNotContainsString('Dec 31, 2026', $rowB[0]);
    }

    /**
     * Multiple shipments, multiple items, mixed warehouses: every dispatch row
     * keeps its own expiration in BOTH the per-item breakdown and the full
     * Shipment Records table.
     */
    public function test_shipments_and_items_keep_distinct_expirations_in_both_tables(): void
    {
        $wh1 = $this->makeWarehouse('GAMC1', 'GAMC1');
        $wh2 = $this->makeWarehouse('GAMC2', 'GAMC2');
        $rice    = $this->makeItem($wh1, 'Rice', 900);
        $canned  = $this->makeItem($wh2, 'Canned Goods', 45);

        $ds = $this->createSubsidy([
            ['item_id' => $rice->id,   'description' => 'Rice',         'quantity' => 150],
            ['item_id' => $canned->id, 'description' => 'Canned Goods', 'quantity' => 80],
        ], 'RIS-EXPMIX');

        $riceLine    = $ds->items()->where('description', 'Rice')->firstOrFail();
        $cannedLine  = $ds->items()->where('description', 'Canned Goods')->firstOrFail();

        // Shipment 1: Rice (exp A) + Canned Goods (exp B) together, different warehouses.
        $this->dispatch($ds, 'DR-EXPMIX-1', [
            [
                'ds_item_id' => $riceLine->id, 'warehouse_id' => $wh1->id,
                'quantity_delivered' => 50, 'unit_cost' => 900,
                'expiration_date' => '2026-12-31', 'dr_number' => 'DR-EXPMIX-1-A',
            ],
            [
                'ds_item_id' => $cannedLine->id, 'warehouse_id' => $wh2->id,
                'quantity_delivered' => 30, 'unit_cost' => 45,
                'expiration_date' => '2028-02-14', 'dr_number' => 'DR-EXPMIX-1-B',
            ],
        ]);

        // Shipment 2: Rice again with a DIFFERENT expiration than shipment 1.
        $this->dispatch($ds, 'DR-EXPMIX-2', [
            [
                'ds_item_id' => $riceLine->id, 'warehouse_id' => $wh1->id,
                'quantity_delivered' => 100, 'unit_cost' => 900,
                'expiration_date' => '2027-06-30', 'dr_number' => 'DR-EXPMIX-2-A',
            ],
        ]);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();

        // ── Per-item partial breakdown ──
        $section = $this->breakdownSection($html);
        $riceRows = $this->rowsContaining($section, 'DR-EXPMIX-1-A');
        $this->assertCount(1, $riceRows);
        $this->assertStringContainsString('Dec 31, 2026', $riceRows[0]);
        $this->assertStringContainsString('GAMC1', $riceRows[0]);

        $riceRows2 = $this->rowsContaining($section, 'DR-EXPMIX-2-A');
        $this->assertCount(1, $riceRows2);
        $this->assertStringContainsString('Jun 30, 2027', $riceRows2[0]);
        $this->assertStringNotContainsString('Dec 31, 2026', $riceRows2[0]);

        $cannedRows = $this->rowsContaining($section, 'DR-EXPMIX-1-B');
        $this->assertCount(1, $cannedRows);
        $this->assertStringContainsString('Feb 14, 2028', $cannedRows[0]);
        $this->assertStringContainsString('GAMC2', $cannedRows[0]);

        // ── Full delivery breakdown (Shipment Records) ──
        $shipStart = strpos($html, 'Shipment Records');
        $shipEnd   = strpos($html, '@include', $shipStart) ?: strlen($html);
        $shipments = substr($html, $shipStart, $shipEnd - $shipStart);

        $shipRowA = $this->rowsContaining($shipments, 'DR-EXPMIX-1-A');
        $this->assertCount(1, $shipRowA);
        $this->assertStringContainsString('Dec 31, 2026', $shipRowA[0]);

        $shipRowB = $this->rowsContaining($shipments, 'DR-EXPMIX-1-B');
        $this->assertCount(1, $shipRowB);
        $this->assertStringContainsString('Feb 14, 2028', $shipRowB[0]);

        $shipRow2 = $this->rowsContaining($shipments, 'DR-EXPMIX-2-A');
        $this->assertCount(1, $shipRow2);
        $this->assertStringContainsString('Jun 30, 2027', $shipRow2[0]);
    }

    /**
     * Items dispatched WITHOUT an expiration date must render the em-dash
     * placeholder — never a borrowed or guessed date.
     */
    public function test_item_without_expiration_shows_placeholder_not_a_borrowed_date(): void
    {
        $wh   = $this->makeWarehouse('GAMC1', 'GAMC1');
        $tarp = $this->makeItem($wh, 'Tarpaulin', 250);   // non-perishable, no expiry
        $rice = $this->makeItem($wh, 'Rice', 900);

        $ds = $this->createSubsidy([
            ['item_id' => $tarp->id, 'description' => 'Tarpaulin', 'quantity' => 20],
            ['item_id' => $rice->id, 'description' => 'Rice',      'quantity' => 40],
        ], 'RIS-EXPNULL');

        $tarpLine = $ds->items()->where('description', 'Tarpaulin')->firstOrFail();

        $this->dispatch($ds, 'DR-EXPNULL-1', [
            [
                'ds_item_id' => $tarpLine->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 20, 'unit_cost' => 250,
                'expiration_date' => null, 'dr_number' => 'DR-EXPNULL-1-A',
            ],
        ]);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        $rows = $this->rowsContaining($section, 'DR-EXPNULL-1-A');
        $this->assertCount(1, $rows);
        // The only date in the row is the shipment date — no expiration date.
        preg_match_all('/[A-Z][a-z]{2} \d{2}, \d{4}/', strip_tags($rows[0]), $dates);
        $this->assertSame(['Aug 10, 2026'], $dates[0]);
    }

    /**
     * The same item name arriving under DIFFERENT originating Subsidies keeps
     * its own dates per Subsidy — one Subsidy's breakdown never leaks the
     * other's expiration.
     */
    public function test_different_originating_subsidies_keep_their_own_expirations(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Rice', 900);

        $subA = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 100],
        ], 'RIS-EXPSUBA');
        $subB = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 100],
        ], 'RIS-EXPSUBB');

        $lineA = $subA->items()->firstOrFail();
        $lineB = $subB->items()->firstOrFail();

        $this->dispatch($subA, 'DR-EXPSUBA-1', [[
            'ds_item_id' => $lineA->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 100, 'unit_cost' => 900,
            'expiration_date' => '2026-12-31', 'dr_number' => 'DR-EXPSUBA-1-A',
        ]]);
        $this->dispatch($subB, 'DR-EXPSUBB-1', [[
            'ds_item_id' => $lineB->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 100, 'unit_cost' => 900,
            'expiration_date' => '2027-06-30', 'dr_number' => 'DR-EXPSUBB-1-A',
        ]]);

        $htmlA = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $subA))->getContent();
        $secA  = $this->breakdownSection($htmlA);
        $this->assertStringContainsString('Dec 31, 2026', $secA);
        $this->assertStringNotContainsString('Jun 30, 2027', $secA);

        $htmlB = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $subB))->getContent();
        $secB  = $this->breakdownSection($htmlB);
        $this->assertStringContainsString('Jun 30, 2027', $secB);
        $this->assertStringNotContainsString('Dec 31, 2026', $secB);
    }

    /**
     * The display fix must not disturb any recorded quantities: subsidy line
     * totals, stock record quantities and stock-card receipts all stay intact.
     */
    public function test_display_fix_does_not_change_recorded_quantities(): void
    {
        $wh  = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Rice', 900);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 300],
        ], 'RIS-EXPQTY');

        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-EXPQTY-1', [
            [
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 100, 'unit_cost' => 900,
                'expiration_date' => '2026-12-31', 'dr_number' => 'DR-EXPQTY-1-A',
            ],
            [
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 50, 'unit_cost' => 900,
                'expiration_date' => '2027-06-30', 'dr_number' => 'DR-EXPQTY-1-B',
            ],
        ]);

        $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->assertOk();

        $line->refresh();
        $this->assertEquals(300, $line->quantity);
        $this->assertEquals(150, $line->qty_delivered);

        $batchA = Item::whereDate('expiration_date', '2026-12-31')->where('description', 'Rice')->firstOrFail();
        $batchB = Item::whereDate('expiration_date', '2027-06-30')->where('description', 'Rice')->firstOrFail();
        $this->assertEquals(100, $batchA->quantity);
        $this->assertEquals(50, $batchB->quantity);

        $this->assertSame(100.0, DeliveryItem::where('dr_number', 'DR-EXPQTY-1-A')->sole()->quantity_delivered);
        $this->assertSame(50.0, DeliveryItem::where('dr_number', 'DR-EXPQTY-1-B')->sole()->quantity_delivered);
    }
}
