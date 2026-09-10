<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Guards around editing/deleting delivery shipments and transfer dispatch.
class ShipmentEditGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $admin = User::create(['username' => 'sgadmin', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh1 = Warehouse::create(['name' => 'WH1', 'code' => 'WH1', 'place' => null, 'is_active' => true]);
        $wh2 = Warehouse::create(['name' => 'WH2', 'code' => 'WH2', 'place' => null, 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'S', 'is_active' => true]);
        return [$admin, $wh1, $wh2, $supplier];
    }

    private function subsidyWithDelivery($admin, $supplier, $wh, float $cost = 10): array
    {
        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number' => 'RIS-SG-' . uniqid(), 'supplier_id' => $supplier->id, 'date' => '2026-09-01',
            'items' => [['description' => 'Guard Packs', 'unit' => 'pack', 'category' => 'food', 'quantity' => 100, 'expiration_date' => '2027-01-01']],
        ])->assertSessionHasNoErrors();
        $ds = DeliverySubsidy::latest('id')->firstOrFail();
        $line = $ds->items()->firstOrFail();
        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), [
            'delivery_date' => '2026-09-02', 'dr_number' => 'DRH', 'condition_status' => 'good', 'quantity_delivered' => 100,
            'items' => [['ds_item_id' => $line->id, 'quantity_delivered' => 100, 'unit_cost' => $cost, 'engas_unit_cost' => 9, 'warehouse_id' => $wh->id, 'dr_number' => 'DR-D1']],
        ])->assertSessionHasNoErrors();
        return [$ds, $ds->deliveries()->firstOrFail(), Item::where('description', 'Guard Packs')->firstOrFail()];
    }

    public function test_zero_qty_edit_preserves_cost_basis(): void
    {
        [$admin, $wh1, $wh2, $supplier] = $this->world();
        [$ds, $delivery, $item] = $this->subsidyWithDelivery($admin, $supplier, $wh1, 10);
        $di = $delivery->items()->firstOrFail();
        // Full reversal to zero must not zero the shared cost basis.
        $this->actingAs($admin)->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
            'delivery_date' => '2026-09-02', 'condition_status' => 'good',
            'items' => [['di_id' => $di->id, 'quantity_delivered' => 0, 'unit_cost' => 0, 'engas_unit_cost' => null, 'warehouse_id' => $wh1->id, 'dr_number' => 'DR-D1']],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(10.0, (float) $item->fresh()->unit_cost);
        $this->assertEquals(10.0, (float) $ds->items()->firstOrFail()->unit_cost);
        $this->assertEquals(0, $item->fresh()->quantity);
    }

    public function test_cross_warehouse_move_beyond_stock_rejected(): void
    {
        [$admin, $wh1, $wh2, $supplier] = $this->world();
        [$ds, $delivery, $item] = $this->subsidyWithDelivery($admin, $supplier, $wh1, 10);
        $di = $delivery->items()->firstOrFail();
        // Drain the record directly so the receipt no longer covers the move.
        $item->update(['quantity' => 10]);
        $this->actingAs($admin)->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
            'delivery_date' => '2026-09-02', 'condition_status' => 'good',
            'items' => [['di_id' => $di->id, 'quantity_delivered' => 100, 'unit_cost' => 10, 'engas_unit_cost' => 9, 'warehouse_id' => $wh2->id, 'dr_number' => 'DR-D1']],
        ])->assertSessionHasErrors();
        $this->assertEquals(10, $item->fresh()->quantity);
        $this->assertFalse(Item::where('warehouse_id', $wh2->id)->where('description', 'Guard Packs')->exists());
    }

    public function test_destroy_delivery_blocked_by_downstream_use(): void
    {
        [$admin, $wh1, $wh2, $supplier] = $this->world();
        $cat = \App\Models\ItemCategory::create(['key' => 'sgfood', 'label' => 'Food', 'account_code' => '1', 'is_active' => true, 'sort_order' => 1]);
        $catalog = \App\Models\ItemCatalogItem::create(['item_category_id' => $cat->id, 'name' => 'Guard Packs', 'account_code' => '1', 'is_active' => true]);
        [$ds, $delivery, $item] = $this->subsidyWithDelivery($admin, $supplier, $wh1, 10);
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 't', 'date_requested' => '2026-09-03',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 10]],
        ])->assertSessionHasNoErrors();
        $ris = \App\Models\Requisition::firstOrFail();
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$ris->items()->firstOrFail()->id => ['warehouse_id' => $wh1->id, 'item_id' => $item->id, 'quantity_issued' => 10, 'dr_number' => 'DR-R1']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->delete(route('delivery_subsidies.destroy_delivery', [$ds, $delivery]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($delivery->fresh()->id);
        $this->assertEquals(90, $item->fresh()->quantity);
    }

    public function test_transfer_overdispatch_rejected_not_capped(): void
    {
        [$admin, $wh1, $wh2, $supplier] = $this->world();
        [$ds, $delivery, $item] = $this->subsidyWithDelivery($admin, $supplier, $wh1, 10);
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-04',
            'items' => [['item_id' => $item->id, 'quantity' => 20, 'unit_cost' => 10]],
        ])->assertSessionHasNoErrors();
        $trf = \App\Models\StockTransfer::firstOrFail();
        $this->actingAs($admin)->post(route('transfers.process_dispatch', $trf), [
            'dispatch_date' => '2026-09-04',
            'items' => [['sti_id' => $trf->items()->firstOrFail()->id, 'quantity' => 99]],
        ])->assertSessionHasErrors('items');
        $this->assertEquals(100, $item->fresh()->quantity);
    }
}
