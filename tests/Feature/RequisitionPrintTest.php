<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The printed RIS (Appendix 63) must render a fully-bordered table:
// the group header row must span all 8 columns (Remarks spans both rows).
class RequisitionPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_renders_fully_bordered_table(): void
    {
        $admin = User::create(['username' => 'printadmin', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh = Warehouse::create(['name' => 'WHP', 'code' => 'WHP', 'place' => null, 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'printfood', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Print Packs', 'account_code' => '1', 'is_active' => true]);
        Item::create(['stock_number' => 'WHP-FOO-0001', 'description' => 'Print Packs', 'unit' => 'pack', 'category' => 'printfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);

        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 'Print test', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 20]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();

        $html = $this->actingAs($admin)->get(route('requisitions.print', $ris))
            ->assertOk()
            ->getContent();

        // Group header covers all 8 columns: 3 + 2 + 2 + Remarks rowspan.
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertEquals(1, substr_count($html, '>Remarks<'));
        // Every body row (1 item + 19 fillers for the 20-row minimum) has 8 cells.
        $this->assertEquals(20, substr_count($html, '<tr class="item-row">') + substr_count($html, '<tr class="empty-row">'));
        // Border rules that draw the grid are present.
        $this->assertStringContainsString('table.ris-table th,', $html);
        $this->assertStringContainsString('border: 1px solid #000;', $html);
        $this->assertStringContainsString('RIS No.', $html);
    }

    public function test_line_issued_from_two_piles_prints_two_unmerged_rows(): void
    {
        $admin = User::create(['username' => 'printadmin2', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh = Warehouse::create(['name' => 'WHP2', 'code' => 'WHP2', 'place' => null, 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'printfood2', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Split Packs', 'account_code' => '1', 'is_active' => true]);
        Item::create(['stock_number' => 'WHP2-FOO-0001', 'description' => 'Split Packs', 'unit' => 'pack', 'category' => 'printfood2', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);
        Item::create(['stock_number' => 'WHP2-FOO-0002', 'description' => 'Split Packs', 'unit' => 'pack', 'category' => 'printfood2', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 20, 'quantity' => 100, 'is_active' => true]);

        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 'Split test', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 100]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $lineId = $ris->items()->firstOrFail()->id;
        $piles = Item::where('warehouse_id', $wh->id)->where('description', 'Split Packs')->orderBy('id')->get();
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$lineId => ['warehouse_id' => $wh->id, 'item_id' => $piles[0]->id, 'quantity_issued' => 60, 'dr_number' => 'DR-S1']],
        ])->assertSessionHasNoErrors();
        // Second partial issuance of the SAME line from the other pile.
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$lineId => ['warehouse_id' => $wh->id, 'item_id' => $piles[1]->id, 'quantity_issued' => 40, 'dr_number' => 'DR-S2']],
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($admin)->get(route('requisitions.print', $ris))
            ->assertOk()
            ->getContent();

        // Two dispatch rows (plus 18 fillers), each with its own stock number —
        // never comma-merged into one cell.
        $this->assertEquals(2, substr_count($html, '<tr class="item-row">'));
        $this->assertStringContainsString('WHP2-FOO-0001', $html);
        $this->assertStringContainsString('WHP2-FOO-0002', $html);
        $this->assertStringNotContainsString('WHP2-FOO-0001, WHP2-FOO-0002', $html);
        // Both piles still hold stock → each dispatch row carries its own Yes.
        $this->assertEquals(2, substr_count($html, '<td>✓</td>'));
    }

    public function test_yes_no_reflects_live_stock_not_dispatch_time_snapshot(): void
    {
        $admin = User::create(['username' => 'printadmin3', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh = Warehouse::create(['name' => 'WHP3', 'code' => 'WHP3', 'place' => null, 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'printfood3', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Live Packs', 'account_code' => '1', 'is_active' => true]);

        // Subsidy expects 100; first arrival brings 30.
        $supplier = Supplier::create(['name' => 'Live Supplier', 'is_active' => true]);
        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number' => 'RIS-LIVE-1', 'supplier_id' => $supplier->id, 'date' => '2026-09-05',
            'items' => [[
                'item_id' => null, 'description' => 'Live Packs', 'unit' => 'pack',
                'category' => 'printfood3', 'quantity' => 100, 'expiration_date' => null,
            ]],
        ])->assertRedirect(route('delivery_subsidies.index'));
        $ds = DeliverySubsidy::where('ris_number', 'RIS-LIVE-1')->firstOrFail();
        $deliveryPayload = function (int $qty, string $dr) use ($ds, $wh) {
            return [
                'delivery_date' => '2026-09-06', 'dr_number' => $dr, 'condition_status' => 'good',
                'quantity_delivered' => $qty,
                'items' => [[
                    'ds_item_id' => $ds->items()->firstOrFail()->id, 'warehouse_id' => $wh->id,
                    'quantity_delivered' => $qty, 'unit_cost' => 10, 'engas_unit_cost' => 10,
                    'expiration_date' => null, 'dr_number' => $dr . '-A',
                ]],
            ];
        };
        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), $deliveryPayload(30, 'DR-LIVE-1'))
            ->assertSessionHasNoErrors();
        $item = Item::where('warehouse_id', $wh->id)->where('description', 'Live Packs')->firstOrFail();

        // Request 100, issue the 30 on hand. The frozen flag records
        // stock_available = false (30 < 100 at dispatch time).
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 'Live test', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 100]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$ris->items()->firstOrFail()->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 30, 'dr_number' => 'DR-L1']],
        ])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $ris->items()->firstOrFail()->stock_available);

        // Second arrival (same subsidy) lands on the SAME pile afterwards.
        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), $deliveryPayload(70, 'DR-LIVE-2'))
            ->assertSessionHasNoErrors();
        $this->assertEquals(70, (float) $item->fresh()->quantity);

        // The frozen flag still says false — but the printout must say Yes.
        $this->assertFalse((bool) $ris->items()->firstOrFail()->fresh()->stock_available);
        $html = $this->actingAs($admin)->get(route('requisitions.print', $ris))
            ->assertOk()
            ->getContent();
        $this->assertEquals(1, substr_count($html, '<td>✓</td>'));
    }
}
