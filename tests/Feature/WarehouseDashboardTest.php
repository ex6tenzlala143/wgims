<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_dashboard_renders_for_center_user(): void
    {
        $wh = Warehouse::create(['name' => 'WHC', 'code' => 'WHC', 'place' => null, 'is_active' => true]);
        $staff = User::create(['username' => 'dashcenter', 'name' => 'C', 'password' => bcrypt('secret'), 'role' => 'center_staff', 'is_active' => true]);
        $staff->warehouses()->attach($wh->id);
        $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertSee('Monthly Activity Comparison');
    }

    public function test_admin_dashboard_renders(): void
    {
        $admin = User::create(['username' => 'dashadmin', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Monthly Activity Comparison');
    }
}
