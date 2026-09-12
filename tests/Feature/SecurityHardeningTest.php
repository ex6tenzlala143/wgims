<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Regression tests for authorization hardening:
// warehouse-scoped APIs, snapshot write gates, admin-only helpers,
// and delivery-updater read coherence across all warehouses.
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $username, string $role): User
    {
        return User::create([
            'username' => $username, 'name' => $username,
            'password' => bcrypt('secret'), 'role' => $role, 'is_active' => true,
        ]);
    }

    private function world(): array
    {
        $admin = $this->makeUser('secadmin', 'admin');
        $wm = $this->makeUser('secwm', 'warehouse_manager');
        $updater = $this->makeUser('secupdater', 'delivery_updater');
        $staff = $this->makeUser('secstaff', 'center_staff');
        $assigned = $this->makeUser('secassigned', 'center_staff');
        $whA = Warehouse::create(['name' => 'Sec A', 'code' => 'SECA', 'place' => null, 'is_active' => true]);
        $whB = Warehouse::create(['name' => 'Sec B', 'code' => 'SECB', 'place' => null, 'is_active' => true]);
        $assigned->warehouses()->sync([$whA->id]);

        $supplier = Supplier::create(['name' => 'Sec Supplier', 'is_active' => true]);
        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number' => 'RIS-SEC-1', 'supplier_id' => $supplier->id, 'date' => '2026-08-01',
            'items' => [[
                'item_id' => null, 'description' => 'Sec Packs', 'unit' => 'pack',
                'category' => 'food', 'quantity' => 50, 'expiration_date' => '2027-01-01',
            ]],
        ])->assertRedirect(route('delivery_subsidies.index'));
        $ds = DeliverySubsidy::where('ris_number', 'RIS-SEC-1')->firstOrFail();
        $line = $ds->items()->firstOrFail();
        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), [
            'delivery_date' => '2026-08-10', 'dr_number' => 'DR-SEC-1', 'condition_status' => 'good',
            'quantity_delivered' => 50,
            'items' => [[
                'ds_item_id' => $line->id, 'warehouse_id' => $whA->id, 'quantity_delivered' => 50,
                'unit_cost' => 100, 'engas_unit_cost' => 100,
                'expiration_date' => '2027-01-01', 'dr_number' => 'DR-SEC-1-A',
            ]],
        ])->assertSessionHasNoErrors();

        $item = Item::where('warehouse_id', $whA->id)->where('description', 'Sec Packs')->firstOrFail();
        $transfer = StockTransfer::create([
            'transfer_number' => 'TRF-2026-SEC1', 'from_warehouse_id' => $whA->id,
            'to_warehouse_id' => $whB->id, 'transfer_date' => '2026-08-12',
            'transferred_by' => $admin->id, 'status' => 'pending',
        ]);

        return compact('admin', 'wm', 'updater', 'staff', 'assigned', 'whA', 'whB', 'ds', 'item', 'transfer');
    }

    public function test_transfer_items_api_enforces_warehouse_access(): void
    {
        ['admin' => $admin, 'staff' => $staff, 'assigned' => $assigned, 'whA' => $whA, 'whB' => $whB] = $this->world();

        // Unassigned staff cannot enumerate another warehouse's stock.
        $this->actingAs($staff)->getJson(route('transfers.items_for_warehouse', ['warehouse_id' => $whA->id]))
            ->assertForbidden();
        // Assigned staff can query their own warehouse…
        $this->actingAs($assigned)->getJson(route('transfers.items_for_warehouse', ['warehouse_id' => $whA->id]))
            ->assertOk();
        // …but not someone else's.
        $this->actingAs($assigned)->getJson(route('transfers.items_for_warehouse', ['warehouse_id' => $whB->id]))
            ->assertForbidden();
        // Admin sees all.
        $this->actingAs($admin)->getJson(route('transfers.items_for_warehouse', ['warehouse_id' => $whB->id]))
            ->assertOk();
    }

    public function test_snapshot_saving_requires_create_rights(): void
    {
        ['admin' => $admin, 'staff' => $staff, 'updater' => $updater] = $this->world();

        foreach ([$staff, $updater] as $user) {
            $this->actingAs($user)->post(route('rpci_report.snapshot'), ['period_month' => '2026-09'])
                ->assertForbidden();
            $this->actingAs($user)->post(route('rsmi_report.snapshot'), ['period_month' => '2026-09'])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('report_snapshots', 0);

        $this->actingAs($admin)->post(route('rpci_report.snapshot'), ['period_month' => '2026-09'])
            ->assertRedirect();
        $this->assertDatabaseHas('report_snapshots', ['report_type' => 'rpci', 'period_month' => '2026-09']);
    }

    public function test_admin_helpers_reject_non_admins(): void
    {
        ['admin' => $admin, 'staff' => $staff, 'updater' => $updater, 'item' => $item] = $this->world();

        // Username oracle: admin only.
        $this->actingAs($staff)->getJson(route('users.check_username', ['username' => 'secadmin']))
            ->assertForbidden();
        $this->actingAs($updater)->getJson(route('users.check_username', ['username' => 'secadmin']))
            ->assertForbidden();
        $this->actingAs($admin)->getJson(route('users.check_username', ['username' => 'secadmin']))
            ->assertOk()->assertJson(['available' => false]);

        // DR oracle + stock-card lookup serve delivery forms (create rights).
        foreach (['ds.check_number' => ['dr_number' => 'DR-NOPE'], 'item.stock_card_lookup' => ['item_id' => $item->id]] as $route => $params) {
            $this->actingAs($staff)->getJson(route($route, $params))->assertForbidden();
            $this->actingAs($admin)->getJson(route($route, $params))->assertOk();
        }
    }

    public function test_updater_read_coherence_across_modules(): void
    {
        ['updater' => $updater, 'ds' => $ds, 'item' => $item, 'transfer' => $transfer] = $this->world();

        $this->actingAs($updater)->get(route('dashboard'))->assertOk();
        $this->actingAs($updater)->get(route('items.index'))->assertOk();
        $this->actingAs($updater)->get(route('items.show', $item))->assertOk();
        $this->actingAs($updater)->get(route('delivery_subsidies.index'))->assertOk();
        $this->actingAs($updater)->get(route('delivery_subsidies.show', $ds))->assertOk();
        $this->actingAs($updater)->get(route('requisitions.index'))->assertOk();
        $this->actingAs($updater)->get(route('reservations.index'))->assertOk();
        $this->actingAs($updater)->get(route('transfers.index'))->assertOk();
        $this->actingAs($updater)->get(route('transfers.show', $transfer))->assertOk();
        $this->actingAs($updater)->get(route('warehouses.index'))->assertOk();
        $this->actingAs($updater)->get(route('stock_cards.summary'))->assertOk();
    }

    public function test_transfer_mutation_routes_reject_warehouse_manager_up_front(): void
    {
        ['wm' => $wm, 'transfer' => $transfer] = $this->world();

        $this->actingAs($wm)->get(route('transfers.edit', $transfer))->assertForbidden();
        $this->actingAs($wm)->put(route('transfers.update', $transfer), [])->assertForbidden();
        $this->actingAs($wm)->delete(route('transfers.destroy', $transfer))->assertForbidden();
        $this->assertDatabaseHas('stock_transfers', ['id' => $transfer->id]);
    }
}
