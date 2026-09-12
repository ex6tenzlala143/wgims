<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionDispatchItem;
use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Delivery updater role: per-line delivery confirmation + admin/WM notifications.
class DeliveryConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $admin = User::create(['username' => 'dcadmin', 'name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wm = User::create(['username' => 'dcwm', 'name' => 'Manager', 'password' => bcrypt('secret'), 'role' => 'warehouse_manager', 'is_active' => true]);
        $updater = User::create(['username' => 'dcupdater', 'name' => 'Updater', 'password' => bcrypt('secret'), 'role' => 'delivery_updater', 'is_active' => true]);
        $staff = User::create(['username' => 'dcstaff', 'name' => 'Staff', 'password' => bcrypt('secret'), 'role' => 'center_staff', 'is_active' => true]);
        $wh = Warehouse::create(['name' => 'WHD', 'code' => 'WHD', 'place' => null, 'is_active' => true]);
        $cat = ItemCategory::create(['key' => 'dcfood', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Confirm Packs', 'account_code' => '1', 'is_active' => true]);
        $catalog2 = ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Second Packs', 'account_code' => '1', 'is_active' => true]);
        $item = Item::create(['stock_number' => 'WHD-FOO-0001', 'description' => 'Confirm Packs', 'unit' => 'pack', 'category' => 'dcfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);
        $item2 = Item::create(['stock_number' => 'WHD-FOO-0002', 'description' => 'Second Packs', 'unit' => 'pack', 'category' => 'dcfood', 'account_code' => '1', 'warehouse_id' => $wh->id, 'unit_cost' => 5, 'quantity' => 100, 'is_active' => true]);
        return [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2];
    }

    private function makeDispatchedRis($admin, $wh, $catalog, $item, int $qty = 20): Requisition
    {
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => $qty]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$ris->items()->firstOrFail()->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => $qty, 'dr_number' => 'DR-C1']],
        ])->assertSessionHasNoErrors();
        return $ris->fresh();
    }

    public function test_role_helpers_and_label(): void
    {
        [$admin, $wm, $updater, $staff] = $this->world();

        $this->assertTrue($updater->isDeliveryUpdater());
        $this->assertTrue($updater->canConfirmDelivery());
        $this->assertTrue($admin->canConfirmDelivery());
        $this->assertTrue($wm->canConfirmDelivery());
        $this->assertFalse($staff->canConfirmDelivery());
        // Updater gains NO admin powers.
        $this->assertFalse($updater->hasAdminAccess());
        $this->assertFalse($updater->canWrite());
        $this->assertFalse($updater->canCreate());
        $this->assertFalse($updater->canApprove());
        $this->assertEquals('Delivery Updater', $updater->getRoleLabel());
    }

    public function test_updater_can_confirm_and_parties_are_notified(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();
        $ris = $this->makeDispatchedRis($admin, $wh, $catalog, $item);
        $dispatch = $ris->items()->firstOrFail()->dispatchItems()->firstOrFail();

        $this->actingAs($updater)->post(route('requisitions.dispatch_confirm_delivery', $dispatch), [
            'delivered_date' => '2026-09-12', 'delivery_notes' => 'Received in good condition',
        ])->assertSessionHas('success');

        $fresh = $dispatch->fresh();
        $this->assertNotNull($fresh->delivered_at);
        $this->assertEquals('2026-09-12', $fresh->delivered_at->format('Y-m-d'));
        $this->assertEquals($updater->id, $fresh->delivered_by);
        $this->assertEquals('Received in good condition', $fresh->delivery_notes);
        $this->assertTrue($fresh->isDelivered());
        // Confirmation never moves stock.
        $this->assertEquals(80, $item->fresh()->quantity);

        // Admin + WM notified; the actor is excluded.
        $this->assertEquals(1, SystemNotification::where('user_id', $admin->id)->where('title', 'RIS Fully Delivered')->count());
        $this->assertEquals(1, SystemNotification::where('user_id', $wm->id)->where('title', 'RIS Fully Delivered')->count());
        $this->assertEquals(0, SystemNotification::where('user_id', $updater->id)->count());
        // Single-line RIS is fully delivered after one confirmation.
        $this->assertTrue($ris->fresh()->isDeliveryConfirmed());
    }

    public function test_partial_confirmation_sends_info_and_final_sends_fully_delivered(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();

        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-01',
            'items' => [
                ['catalog_item_id' => $catalog->id, 'quantity_requested' => 20],
                ['catalog_item_id' => $catalog2->id, 'quantity_requested' => 10],
            ],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $lines = $ris->items()->orderBy('id')->get();
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [
                $lines[0]->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 20, 'dr_number' => 'DR-C1'],
                $lines[1]->id => ['warehouse_id' => $wh->id, 'item_id' => $item2->id, 'quantity_issued' => 10, 'dr_number' => 'DR-C2'],
            ],
        ])->assertSessionHasNoErrors();

        $dispatches = RequisitionDispatchItem::whereIn('requisition_item_id', $lines->pluck('id'))->orderBy('id')->get();
        $this->assertEquals(2, $dispatches->count());

        $this->actingAs($updater)->post(route('requisitions.dispatch_confirm_delivery', $dispatches[0]))
            ->assertSessionHas('success');
        $this->assertFalse($ris->fresh()->isDeliveryConfirmed());
        $this->assertEquals(1, SystemNotification::where('user_id', $admin->id)->where('title', 'Delivery Confirmed')->count());

        $this->actingAs($updater)->post(route('requisitions.dispatch_confirm_delivery', $dispatches[1]))
            ->assertSessionHas('success');
        $this->assertTrue($ris->fresh()->isDeliveryConfirmed());
        $this->assertEquals(1, SystemNotification::where('user_id', $admin->id)->where('title', 'RIS Fully Delivered')->count());
    }

    public function test_unauthorized_roles_get_403(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();
        $ris = $this->makeDispatchedRis($admin, $wh, $catalog, $item);
        $dispatch = $ris->items()->firstOrFail()->dispatchItems()->firstOrFail();

        $this->actingAs($staff)->post(route('requisitions.dispatch_confirm_delivery', $dispatch))->assertForbidden();
        $this->actingAs($staff)->post(route('requisitions.dispatch_unconfirm_delivery', $dispatch))->assertForbidden();
        $this->assertNull($dispatch->fresh()->delivered_at);
    }

    public function test_warehouse_manager_can_also_confirm(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();
        $ris = $this->makeDispatchedRis($admin, $wh, $catalog, $item);
        $dispatch = $ris->items()->firstOrFail()->dispatchItems()->firstOrFail();

        $this->actingAs($wm)->post(route('requisitions.dispatch_confirm_delivery', $dispatch))
            ->assertSessionHas('success');
        $this->assertEquals($wm->id, $dispatch->fresh()->delivered_by);
    }

    public function test_double_confirm_warns_and_unconfirm_clears(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();
        $ris = $this->makeDispatchedRis($admin, $wh, $catalog, $item);
        $dispatch = $ris->items()->firstOrFail()->dispatchItems()->firstOrFail();

        $this->actingAs($updater)->post(route('requisitions.dispatch_confirm_delivery', $dispatch))
            ->assertSessionHas('success');
        $this->actingAs($updater)->post(route('requisitions.dispatch_confirm_delivery', $dispatch))
            ->assertSessionHas('warning');

        $this->actingAs($updater)->post(route('requisitions.dispatch_unconfirm_delivery', $dispatch))
            ->assertSessionHas('success');
        $fresh = $dispatch->fresh();
        $this->assertNull($fresh->delivered_at);
        $this->assertNull($fresh->delivered_by);
        $this->assertNull($fresh->delivery_notes);
        $this->assertFalse($ris->fresh()->isDeliveryConfirmed());
        // Stock untouched throughout.
        $this->assertEquals(80, $item->fresh()->quantity);
    }

    public function test_updater_has_all_warehouse_read_access(): void
    {
        [$admin, $wm, $updater, $staff, $wh, $catalog, $catalog2, $item, $item2] = $this->world();
        $ris = $this->makeDispatchedRis($admin, $wh, $catalog, $item);

        $this->actingAs($updater)->get(route('dashboard'))->assertOk();
        $this->actingAs($updater)->get(route('requisitions.index'))->assertOk();
        $this->actingAs($updater)->get(route('requisitions.show', $ris))->assertOk()
            ->assertSee('Delivery Confirmation');
        // …but cannot dispatch, edit, or delete.
        $this->actingAs($updater)->get(route('requisitions.approve', $ris))->assertForbidden();
        $this->actingAs($updater)->delete(route('requisitions.destroy', $ris))->assertForbidden();
    }
}
