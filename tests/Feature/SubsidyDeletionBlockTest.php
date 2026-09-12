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

// Hard rule: a subsidy with downstream transactions (RIS augmentation,
// executed transfers, active reservation locks) can never be deleted.
class SubsidyDeletionBlockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'block_admin_' . $i,
            'name'     => 'Block Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function flow(): array
    {
        $wh = Warehouse::create(['name' => 'WHB', 'code' => 'WHB', 'place' => null, 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'blockfood', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Block Packs', 'account_code' => '1', 'is_active' => true]);

        $this->actingAs($this->admin())->post(route('delivery_subsidies.store'), [
            'ris_number' => 'RIS-BLOCK-1', 'supplier_id' => $supplier->id, 'date' => '2026-08-01',
            'items' => [[
                'item_id' => null, 'description' => 'Block Packs', 'unit' => 'pack',
                'category' => 'blockfood', 'quantity' => 60, 'expiration_date' => '2027-01-01',
            ]],
        ])->assertRedirect(route('delivery_subsidies.index'));
        $ds = DeliverySubsidy::where('ris_number', 'RIS-BLOCK-1')->firstOrFail();

        $line = $ds->items()->firstOrFail();
        $this->actingAs($this->admin())->post(route('delivery_subsidies.store_delivery', $ds), [
            'delivery_date' => '2026-08-10', 'dr_number' => 'DR-BLOCK-1', 'condition_status' => 'good',
            'quantity_delivered' => 60,
            'items' => [[
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id, 'quantity_delivered' => 60,
                'unit_cost' => 100, 'engas_unit_cost' => 100,
                'expiration_date' => '2027-01-01', 'dr_number' => 'DR-BLOCK-1-A',
            ]],
        ])->assertSessionHasNoErrors();

        $item = Item::where('warehouse_id', $wh->id)->where('description', 'Block Packs')->firstOrFail();

        return compact('wh', 'ds', 'catalog', 'item');
    }

    public function test_subsidy_with_augmented_stock_cannot_be_deleted(): void
    {
        ['wh' => $wh, 'ds' => $ds, 'catalog' => $catalog, 'item' => $item] = $this->flow();

        // Augment (issue) part of the subsidy's stock through an RIS.
        $this->actingAs($this->admin())->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 50]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $this->actingAs($this->admin())->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$ris->items()->firstOrFail()->id => [
                'warehouse_id' => $wh->id, 'item_id' => $item->id,
                'quantity_issued' => 10, 'dr_number' => 'DR-AUG-1',
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted')
                && str_contains($msg, 'issued through RIS')
                && str_contains($msg, $ris->ris_number));

        // Refused: subsidy, RIS, and remaining stock all intact.
        $this->assertDatabaseHas('delivery_subsidies', ['id' => $ds->id]);
        $this->assertEquals(50, (float) $item->fresh()->quantity);
    }

    public function test_subsidy_with_active_reservation_cannot_be_deleted(): void
    {
        ['wh' => $wh, 'ds' => $ds, 'item' => $item] = $this->flow();

        $this->actingAs($this->admin())->post(route('reservations.store'), [
            'purpose' => 't',
            'items' => [['warehouse_id' => $wh->id, 'item_id' => $item->id, 'reserved_quantity' => 10]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted')
                && str_contains($msg, 'active reservation'));

        $this->assertDatabaseHas('delivery_subsidies', ['id' => $ds->id]);
        $this->assertEquals(60, (float) $item->fresh()->quantity);
    }

    public function test_subsidy_without_downstream_use_still_deletes(): void
    {
        ['ds' => $ds, 'item' => $item] = $this->flow();

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertDatabaseMissing('delivery_subsidies', ['id' => $ds->id]);
        $this->assertNull($item->fresh());
    }
}
