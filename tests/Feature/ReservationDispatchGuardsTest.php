<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Guards around reservation-linked dispatching and RIS status integrity.
class ReservationDispatchGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $admin = User::create(['username' => 'gdadmin', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh = Warehouse::create(['name' => 'WHG', 'code' => 'WHG', 'place' => null, 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'gdfood', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Guard Packs', 'account_code' => '1', 'is_active' => true]);
        $catalog2 = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Other Packs', 'account_code' => '1', 'is_active' => true]);
        $item = Item::create(['stock_number' => 'WHG-FOO-0001', 'description' => 'Guard Packs', 'unit' => 'pack', 'category' => 'gdfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);
        $other = Item::create(['stock_number' => 'WHG-FOO-0002', 'description' => 'Other Packs', 'unit' => 'pack', 'category' => 'gdfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 5, 'quantity' => 100, 'is_active' => true]);
        return [$admin, $wh, $catalog, $catalog2, $item, $other];
    }

    private function reserve($admin, $wh, $item, int $qty): Reservation
    {
        $this->actingAs($admin)->post(route('reservations.store'), [
            'purpose' => 't', 'items' => [['warehouse_id' => $wh->id, 'item_id' => $item->id, 'reserved_quantity' => $qty]],
        ])->assertSessionHasNoErrors();
        return Reservation::latest('id')->firstOrFail();
    }

    private function makeRis($admin, $catalog, int $qty = 50): Requisition
    {
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => $qty]],
        ])->assertSessionHasNoErrors();
        return Requisition::latest('id')->firstOrFail();
    }

    public function test_dead_reservation_cannot_be_consumed(): void
    {
        [$admin, $wh, $catalog, $catalog2, $item, $other] = $this->world();
        $res = $this->reserve($admin, $wh, $item, 10);
        $riId = $res->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('reservations.cancel', $res))->assertSessionHasNoErrors();

        $ris = $this->makeRis($admin, $catalog);
        $line = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$line => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 5, 'dr_number' => 'DR-1', 'reservation_item_id' => $riId]],
        ])->assertSessionHasErrors('items.' . $line . '.reservation_item_id');
        $this->assertEquals('CANCELLED', $res->fresh()->status);
        $this->assertEquals(100, $item->fresh()->quantity);
    }

    public function test_over_deploy_beyond_remaining_rejected(): void
    {
        [$admin, $wh, $catalog, $catalog2, $item, $other] = $this->world();
        $res = $this->reserve($admin, $wh, $item, 10);
        $riId = $res->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('reservations.approve', $res))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('reservations.ready', $res))->assertSessionHasNoErrors();

        $ris = $this->makeRis($admin, $catalog);
        $line = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$line => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 8, 'dr_number' => 'DR-1', 'reservation_item_id' => $riId]],
        ])->assertSessionHasNoErrors();

        $ris2 = $this->makeRis($admin, $catalog);
        $line2 = $ris2->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris2), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$line2 => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 5, 'dr_number' => 'DR-2', 'reservation_item_id' => $riId]],
        ])->assertSessionHasErrors('items.' . $line2 . '.quantity_issued');
        $this->assertEquals(8, $res->items()->firstOrFail()->deployed_quantity);
    }

    public function test_wrong_description_stock_rejected(): void
    {
        [$admin, $wh, $catalog, $catalog2, $item, $other] = $this->world();
        $ris = $this->makeRis($admin, $catalog);
        $line = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$line => ['warehouse_id' => $wh->id, 'item_id' => $other->id, 'quantity_issued' => 10, 'dr_number' => 'DR-1']],
        ])->assertSessionHasErrors('items.' . $line . '.item_id');
        $this->assertEquals(100, $other->fresh()->quantity);
        $this->assertEquals(0, $ris->items()->firstOrFail()->quantity_issued);
    }

    public function test_update_ignores_caller_status_and_recomputes(): void
    {
        [$admin, $wh, $catalog, $catalog2, $item, $other] = $this->world();
        $ris = $this->makeRis($admin, $catalog);
        $this->actingAs($admin)->put(route('requisitions.update', $ris), [
            'purpose' => 't', 'date_requested' => '2026-09-01', 'status' => 'approved',
            'items' => [['id' => $ris->items()->firstOrFail()->id, 'catalog_item_id' => $catalog->id, 'quantity_requested' => 50]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals('pending', $ris->fresh()->status);
    }

    public function test_destroy_item_recomputes_header_and_completion_gated(): void
    {
        [$admin, $wh, $catalog, $catalog2, $item, $other] = $this->world();
        $item2 = Item::create(['stock_number' => 'WHG-FOO-0009', 'description' => 'Guard Packs', 'unit' => 'pack', 'category' => 'gdfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);
        $this->actingAs($admin)->post(route('reservations.store'), [
            'purpose' => 't', 'items' => [['warehouse_id' => $wh->id, 'item_id' => $item2->id, 'reserved_quantity' => 10]],
        ])->assertSessionHasNoErrors();
        $res = Reservation::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('reservations.approve', $res))->assertSessionHasNoErrors();

        $ris = $this->makeRis($admin, $catalog, 100);
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$ris->items()->firstOrFail()->id => ['warehouse_id' => $wh->id, 'item_id' => $item2->id, 'quantity_issued' => 40, 'dr_number' => 'DR-R1']],
        ])->assertSessionHasNoErrors();
        $this->assertEquals('partially_approved', $ris->fresh()->status);
        $this->assertNull($ris->fresh()->completion_date);
    }
}
