<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsidyIndexScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_filter_applies_to_all_warehouse_scope_branches(): void
    {
        $whA = Warehouse::create(['name' => 'WHA', 'code' => 'WHA', 'place' => null, 'is_active' => true]);
        $whB = Warehouse::create(['name' => 'WHB', 'code' => 'WHB', 'place' => null, 'is_active' => true]);
        $staff = User::create(['username' => 'scope_staff', 'name' => 'S', 'password' => bcrypt('secret'), 'role' => 'center_staff', 'is_active' => true]);
        $staff->warehouses()->attach($whA->id);
        $supplier = Supplier::create(['name' => 'S', 'is_active' => true]);
        $admin = User::create(['username' => 'scope_admin', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);

        $mk = function (string $ris, int $whId, string $status) use ($supplier, $admin) {
            $ds = DeliverySubsidy::create([
                'ris_number' => $ris, 'dr_number' => 'DR-' . $ris, 'supplier_id' => $supplier->id,
                'date' => '2026-09-01', 'warehouse_id' => null, 'quantity_requested' => 10, 'status' => $status,
                'created_by' => $admin->id,
            ]);
            DeliverySubsidyItem::create([
                'delivery_subsidy_id' => $ds->id, 'description' => 'Scope Packs', 'unit' => 'pack',
                'category' => 'food', 'quantity' => 10, 'warehouse_id' => $whId,
            ]);
            return $ds;
        };

        $mk('RIS-SC-A-PEND', $whA->id, 'pending');          // visible, pending
        $mk('RIS-SC-B-PEND', $whB->id, 'pending');          // other warehouse: hidden
        $mk('RIS-SC-A-FULL', $whA->id, 'fully_delivered');  // visible, filtered status

        // No filter: sees A-subsidies only
        $html = $this->actingAs($staff)->get(route('delivery_subsidies.index'))->assertOk()->getContent();
        $this->assertStringContainsString('RIS-SC-A-PEND', $html);
        $this->assertStringContainsString('RIS-SC-A-FULL', $html);
        $this->assertStringNotContainsString('RIS-SC-B-PEND', $html);

        // Status filter must apply to the line-item scope branch too
        $html = $this->actingAs($staff)->get(route('delivery_subsidies.index', ['status' => 'fully_delivered']))->assertOk()->getContent();
        $this->assertStringContainsString('RIS-SC-A-FULL', $html);
        $this->assertStringNotContainsString('RIS-SC-A-PEND', $html);
        $this->assertStringNotContainsString('RIS-SC-B-PEND', $html);

        // Search filter likewise
        $html = $this->actingAs($staff)->get(route('delivery_subsidies.index', ['search' => 'RIS-SC-A-FULL']))->assertOk()->getContent();
        $this->assertStringContainsString('RIS-SC-A-FULL', $html);
        $this->assertStringNotContainsString('RIS-SC-A-PEND', $html);
    }
}
