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

class DeliveryStockCardDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;
        return User::create([
            'username' => 'sc_admin_' . $i,
            'name'     => 'SC Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description): Item
    {
        return Item::create([
            'stock_number'    => null,
            'description'     => $description,
            'unit'            => 'sack',
            'category'        => 'food',
            'account_code'    => '1040202000-01',
            'warehouse_id'    => $wh->id,
            'unit_cost'       => 10.0,
            'engas_unit_cost' => null,
            'quantity'        => 0,
            'expiration_date' => null,
            'is_active'       => true,
        ]);
    }

    private function createSubsidy(array $lines, string $ris): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'SC Supplier ' . $ris, 'is_active' => true]);
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'description'     => $line['description'],
                'unit'            => 'sack',
                'category'        => 'food',
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
            ])->assertRedirect(route('delivery_subsidies.index'));
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
            ])->assertSessionHasNoErrors();
    }

    private function orderedSection(string $html): string
    {
        $start = strpos($html, 'Ordered Items');
        $this->assertNotFalse($start);
        $end = strpos($html, 'Partial Delivery Breakdown by Item');
        if ($end === false) $end = strpos($html, 'No deliveries recorded yet');
        $this->assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    private function breakdownSection(string $html): string
    {
        $start = strpos($html, 'Partial Delivery Breakdown by Item');
        $end   = strpos($html, 'Shipment Records');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    private function rowsContaining(string $html, string $needle): array
    {
        $rows = [];
        foreach (preg_split('/<tr[^>]*>/', $html) as $row) {
            if (str_contains($row, $needle)) $rows[] = $row;
        }
        return $rows;
    }

    public function test_ordered_item_shows_all_stock_cards_when_delivered_in_multiple_shipments(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $rice = $this->makeItem($wh, 'Rice');

        $ds = $this->createSubsidy([
            ['item_id' => $rice->id, 'description' => 'Rice', 'quantity' => 100],
        ], 'RIS-SC-1');
        $line = $ds->items()->firstOrFail();

        // Three shipments at DIFFERENT unit/ENGAS/expiration → three distinct stock records.
        $this->dispatch($ds, 'DR-SC-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 30,
            'unit_cost' => 700, 'engas_unit_cost' => 710, 'expiration_date' => '2027-01-01', 'dr_number' => 'DR-SC-1-A',
        ]]);
        $this->dispatch($ds, 'DR-SC-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 30,
            'unit_cost' => 800, 'engas_unit_cost' => 820, 'expiration_date' => '2027-06-01', 'dr_number' => 'DR-SC-2-A',
        ]]);
        $this->dispatch($ds, 'DR-SC-3', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 40,
            'unit_cost' => 900, 'engas_unit_cost' => 930, 'expiration_date' => '2027-12-01', 'dr_number' => 'DR-SC-3-A',
        ]]);

        $cards = $line->fresh()->assigned_stock_cards;
        $this->assertCount(3, $cards, 'Same ordered item at three different cost/expiry combos must yield three stock records');
        foreach ($cards as $c) $this->assertNotNull($c->stock_number);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $ordered = $this->orderedSection($html);

        // Ordered Items must list ALL three stock cards — clickable links to stock_cards.item_history
        foreach ($cards as $sc) {
            $this->assertStringContainsString($sc->stock_number, $ordered);
            $this->assertStringContainsString(route('stock_cards.item_history', $sc->id), $ordered);
        }

        // It must NOT show Pending delivery for a fulfilled line with cards
        $this->assertStringNotContainsString('Pending delivery', $ordered);

        // Quantities and stock balances must remain untouched
        $this->assertEquals(100, $line->fresh()->qty_delivered);
        foreach ($cards as $sc) {
            $this->assertGreaterThan(0, $sc->fresh()->quantity);
        }
    }

    public function test_partial_breakdown_each_shipment_shows_its_own_stock_card(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $rice = $this->makeItem($wh, 'Rice');

        $ds = $this->createSubsidy([['item_id' => $rice->id, 'description' => 'Rice', 'quantity' => 60]], 'RIS-SC-2');
        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-SC2-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 25,
            'unit_cost' => 700, 'engas_unit_cost' => 710, 'dr_number' => 'DR-SC2-1-A',
        ]]);
        $this->dispatch($ds, 'DR-SC2-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 25,
            'unit_cost' => 800, 'engas_unit_cost' => 820, 'dr_number' => 'DR-SC2-2-A',
        ]]);

        $diA = DeliveryItem::where('dr_number', 'DR-SC2-1-A')->sole();
        $diB = DeliveryItem::where('dr_number', 'DR-SC2-2-A')->sole();
        $this->assertNotEquals($diA->item_id, $diB->item_id);
        $cardA = $diA->item->stock_number;
        $cardB = $diB->item->stock_number;
        $this->assertNotEquals($cardA, $cardB);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $section = $this->breakdownSection($html);

        $rowA = $this->rowsContaining($section, 'DR-SC2-1-A');
        $rowB = $this->rowsContaining($section, 'DR-SC2-2-A');
        $this->assertCount(1, $rowA);
        $this->assertCount(1, $rowB);
        // Each shipment row shows ITS OWN stock card (and not the other's)
        $this->assertStringContainsString($cardA, $rowA[0]);
        $this->assertStringNotContainsString($cardB, $rowA[0]);
        $this->assertStringContainsString($cardB, $rowB[0]);
        $this->assertStringNotContainsString($cardA, $rowB[0]);
        // Both cards also linked to stock_cards.item_history
        $this->assertStringContainsString(route('stock_cards.item_history', $diA->item_id), $rowA[0]);
        $this->assertStringContainsString(route('stock_cards.item_history', $diB->item_id), $rowB[0]);
        // Item header also lists both cards
        $this->assertStringContainsString($cardA, $section);
        $this->assertStringContainsString($cardB, $section);
    }

    public function test_engas_total_correct_when_item_has_multiple_stock_cards_different_costs(): void
    {
        // Family Tents scenario: 167 @ ₱20,357.99 + 16 @ ₱19,050.00 → three stock cards not, two — cumulative must be Σ per-shipment ENGAS
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $item = $this->makeItem($wh, 'Family Tents');

        $ds = $this->createSubsidy([['item_id' => $item->id, 'description' => 'Family Tents', 'quantity' => 200]], 'RIS-SC-ENGAS');
        $line = $ds->items()->firstOrFail();

        $this->dispatch($ds, 'DR-SCE-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 167,
            'unit_cost' => 20357.99, 'engas_unit_cost' => 20357.99, 'dr_number' => 'DR-SCE-1-A',
        ]]);
        $this->dispatch($ds, 'DR-SCE-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 16,
            'unit_cost' => 19050.00, 'engas_unit_cost' => 19050.00, 'dr_number' => 'DR-SCE-2-A',
        ]]);

        $rowA = DeliveryItem::where('dr_number', 'DR-SCE-1-A')->sole();
        $rowB = DeliveryItem::where('dr_number', 'DR-SCE-2-A')->sole();
        $this->assertEquals(3399784.33, $rowA->engas_total_value);
        $this->assertEquals(304800.00, $rowB->engas_total_value);

        $html = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $ordered = $this->orderedSection($html);
        $section = $this->breakdownSection($html);

        // Stock cards: two distinct cards in Ordered Items
        $cards = $line->fresh()->assigned_stock_cards;
        $this->assertCount(2, $cards);
        foreach ($cards as $c) $this->assertStringContainsString($c->stock_number, $ordered);

        // Per-shipment ENGAS correctness
        $rowAHtml = $this->rowsContaining($section, 'DR-SCE-1-A');
        $rowBHtml = $this->rowsContaining($section, 'DR-SCE-2-A');
        $this->assertStringContainsString('3,399,784.33', $rowAHtml[0]);
        $this->assertStringContainsString('304,800.00', $rowBHtml[0]);
        // Cumulative = 3,704,584.33 (Σ per-shipment ENGAS)
        $this->assertStringContainsString('3,704,584.33', $section);
        // Never total×single-cost
        $this->assertStringNotContainsString('3,486,150.00', $section);
        $this->assertStringNotContainsString('3,725,511.17', $section);
    }

    public function test_single_stock_card_still_displays_and_pending_pending_when_no_delivery(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $rice = $this->makeItem($wh, 'Rice');
        $ds = $this->createSubsidy([['item_id' => $rice->id, 'description' => 'Rice', 'quantity' => 50]], 'RIS-SC-PEND');

        $htmlBefore = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $orderedBefore = $this->orderedSection($htmlBefore);
        $this->assertStringContainsString('Pending delivery', $orderedBefore);

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PEND-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 50,
            'unit_cost' => 700, 'engas_unit_cost' => 710, 'dr_number' => 'DR-PEND-1-A',
        ]]);

        $htmlAfter = $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->getContent();
        $orderedAfter = $this->orderedSection($htmlAfter);
        $this->assertStringNotContainsString('Pending delivery', $orderedAfter);
        $card = $line->fresh()->assigned_stock_cards->sole();
        $this->assertStringContainsString($card->stock_number, $orderedAfter);
    }

    public function test_fix_does_not_create_duplicate_stock_cards(): void
    {
        $wh = $this->makeWarehouse('GAMC1', 'GAMC1');
        $rice = $this->makeItem($wh, 'Rice');
        $ds = $this->createSubsidy([['item_id' => $rice->id, 'description' => 'Rice', 'quantity' => 60]], 'RIS-SC-DEDUP');
        $line = $ds->items()->firstOrFail();

        // Two dispatches DIFFERENT cost/expiration → two cards
        $this->dispatch($ds, 'DR-DEDUP-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 30,
            'unit_cost' => 700, 'engas_unit_cost' => 710, 'expiration_date' => '2027-01-01', 'dr_number' => 'DR-DEDUP-1-A',
        ]]);
        $this->dispatch($ds, 'DR-DEDUP-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 30,
            'unit_cost' => 800, 'engas_unit_cost' => 820, 'expiration_date' => '2027-06-01', 'dr_number' => 'DR-DEDUP-2-A',
        ]]);

        $itemsBefore = Item::where('description', 'Rice')->whereNotNull('stock_number')->count();
        $cards = $line->fresh()->assigned_stock_cards;
        $this->assertEquals(2, $cards->count());
        $this->assertEquals($itemsBefore, $cards->count());

        // Viewing the page must NOT create more stock records
        $this->actingAs($this->admin())->get(route('delivery_subsidies.show', $ds))->assertOk();
        $this->assertEquals($itemsBefore, Item::where('description', 'Rice')->whereNotNull('stock_number')->count());
    }
}
