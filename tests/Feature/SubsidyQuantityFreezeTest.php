<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Once a subsidy line's stock has downstream usage (RIS issuance, stock
// transfer movement, or an active reservation lock), its requested quantity
// is fully frozen — it cannot be changed in either direction.
class SubsidyQuantityFreezeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username'   => 'sqf_admin_' . $i,
            'name'       => 'SQF Admin ' . $i,
            'password'   => bcrypt('secret'),
            'role'       => User::ROLE_ADMIN,
            'is_active'  => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeCatalogItem(string $name): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'sqf_' . mb_strtolower(str_replace(' ', '_', $name))],
            ['label' => $name, 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
        );

        return ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name'             => $name,
            'account_code'     => '50101010',
            'is_active'        => true,
        ]);
    }

    /**
     * Subsidy requesting 100, fully delivered 100. Returns [$wh, $stockItem, $ds].
     * The delivered stock lands on a fresh stock record (not the placeholder).
     */
    private function deliveredSubsidy(string $ris, string $itemName): array
    {
        $admin = $this->admin();
        $wh = $this->makeWarehouse('SQF WH ' . $ris, 'SQF' . substr(md5($ris), 0, 4));
        $catalog = $this->makeCatalogItem($itemName);
        $supplier = Supplier::create(['name' => 'SQF Supplier ' . $ris, 'is_active' => true]);

        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number'  => $ris,
            'supplier_id' => $supplier->id,
            'date'        => '2026-08-01',
            'items'       => [[
                'catalog_item_id' => $catalog->id,
                'description'     => $itemName,
                'unit'            => 'piece',
                'category'        => 'food',
                'quantity'        => 100,
                'expiration_date' => '2027-01-01',
            ]],
        ])->assertSessionHasNoErrors();
        $ds = DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), [
            'delivery_date'      => '2026-08-10',
            'condition_status'   => 'good',
            'quantity_delivered' => 100,
            'items'              => [[
                'ds_item_id'         => $line->id,
                'warehouse_id'       => $wh->id,
                'quantity_delivered' => 100,
                'unit_cost'          => 10,
                'engas_unit_cost'    => 10,
                'expiration_date'    => '2027-01-01',
                'dr_number'          => 'DR-' . $ris,
                'condition'          => 'good',
            ]],
        ])->assertSessionHasNoErrors();

        $stockItem = Item::where('warehouse_id', $wh->id)
            ->where('description', $itemName)
            ->whereNotNull('stock_number')
            ->firstOrFail();

        return [$admin, $wh, $stockItem, $catalog, $ds];
    }

    private function editPayload(DeliverySubsidy $ds, float $newQty): array
    {
        $line = $ds->items()->firstOrFail();

        return [
            'ris_number'        => $ds->ris_number,
            'supplier_id'       => $ds->supplier_id,
            'date'              => '2026-08-01',
            'place_of_delivery' => $ds->place_of_delivery,
            'remarks'           => $ds->remarks,
            'items'             => [[
                'dsi_id'          => $line->id,
                'item_id'         => $line->item_id,
                'catalog_item_id' => $line->catalog_item_id,
                'account_code'    => $line->account_code,
                'description'     => $line->item?->description ?? $line->description,
                'unit'            => $line->item?->unit ?? $line->unit,
                'category'        => $line->item?->category ?? $line->category,
                'quantity'        => $newQty,
                'expiration_date' => $line->expiration_date?->format('Y-m-d'),
            ]],
        ];
    }

    public function test_ris_issued_line_rejects_quantity_change(): void
    {
        [$admin, $wh, $stockItem, $catalog, $ds] = $this->deliveredSubsidy('RIS-SQF-1', 'Freeze Packs A');

        // Issue part of the stock through a RIS
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 50]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $risLine = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$risLine => ['warehouse_id' => $wh->id, 'item_id' => $stockItem->id, 'quantity_issued' => 20, 'dr_number' => 'DR-SQF-RIS']],
        ])->assertSessionHasNoErrors();

        // Increase above delivered quantity: passes the old floor check but
        // must be refused by the downstream freeze.
        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, 120))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(100, (float) $ds->items()->firstOrFail()->quantity);
    }

    public function test_transfer_moved_line_rejects_quantity_change(): void
    {
        [$admin, $wh, $stockItem, $catalog, $ds] = $this->deliveredSubsidy('RIS-SQF-2', 'Freeze Packs B');
        $wh2 = $this->makeWarehouse('SQF Dest', 'SQFD2');

        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $stockItem->id, 'quantity' => 30, 'unit_cost' => 10]],
        ])->assertSessionHasNoErrors();
        $trf = StockTransfer::firstOrFail();
        $sti = $trf->items()->firstOrFail();
        $this->actingAs($admin)->post(route('transfers.process_dispatch', $trf), [
            'dispatch_date' => '2026-09-02',
            'items' => [['sti_id' => $sti->id, 'quantity' => 30]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, 120))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(100, (float) $ds->items()->firstOrFail()->quantity);
    }

    public function test_reservation_locked_line_rejects_quantity_change(): void
    {
        [$admin, $wh, $stockItem, $catalog, $ds] = $this->deliveredSubsidy('RIS-SQF-3', 'Freeze Packs C');

        $this->actingAs($admin)->post(route('reservations.store'), [
            'purpose' => 't',
            'items' => [['warehouse_id' => $wh->id, 'item_id' => $stockItem->id, 'reserved_quantity' => 40]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, 120))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(100, (float) $ds->items()->firstOrFail()->quantity);
    }

    public function test_delivered_but_unused_line_still_allows_increase(): void
    {
        // QUANTITY LOCK (supersedes old increase-allowed rule): even a
        // delivered-but-unused line can no longer be increased — 100 stays 100.
        [$admin, $wh, $stockItem, $catalog, $ds] = $this->deliveredSubsidy('RIS-SQF-4', 'Freeze Packs D');

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, 120))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(100, (float) $ds->items()->firstOrFail()->quantity);
        $this->assertEquals(100, (float) $ds->fresh()->quantity_requested);
    }

    public function test_edit_data_flags_downstream_use(): void
    {
        [$admin, $wh, $stockItem, $catalog, $ds] = $this->deliveredSubsidy('RIS-SQF-5', 'Freeze Packs E');

        // No downstream use yet → flag empty
        $res = $this->actingAs($admin)->getJson(route('delivery_subsidies.edit_data', $ds->id));
        $res->assertOk();
        $this->assertSame([], $res->json('items.0.downstream_use'));

        // Reserve part of the stock → flag present
        $this->actingAs($admin)->post(route('reservations.store'), [
            'purpose' => 't',
            'items' => [['warehouse_id' => $wh->id, 'item_id' => $stockItem->id, 'reserved_quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $res = $this->actingAs($admin)->getJson(route('delivery_subsidies.edit_data', $ds->id));
        $res->assertOk();
        $this->assertNotEmpty($res->json('items.0.downstream_use'));
    }
}
