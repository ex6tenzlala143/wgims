<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Reservation;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferSourceTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $admin = User::create(['username' => 'tmptsrc', 'name' => 'A', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $wh1 = Warehouse::create(['name' => 'WHA', 'code' => 'WHA', 'place' => null, 'is_active' => true]);
        $wh2 = Warehouse::create(['name' => 'WHB', 'code' => 'WHB', 'place' => null, 'is_active' => true]);
        $item = Item::create(['stock_number' => 'WHA-FOO-0001', 'description' => 'Src Packs', 'unit' => 'pack', 'category' => 'food', 'account_code' => '1', 'warehouse_id' => $wh1->id, 'unit_cost' => 10, 'quantity' => 100, 'is_active' => true]);
        return [$admin, $wh1, $wh2, $item];
    }

    private function reserve($admin, $wh, $item, int $qty): Reservation
    {
        $this->actingAs($admin)->post(route('reservations.store'), [
            'purpose' => 't', 'items' => [['warehouse_id' => $wh->id, 'item_id' => $item->id, 'reserved_quantity' => $qty]],
        ])->assertSessionHasNoErrors();
        return Reservation::latest('id')->firstOrFail();
    }

    public function test_normal_transfer_capped_by_available(): void
    {
        [$admin, $wh1, $wh2, $item] = $this->world();
        $this->reserve($admin, $wh1, $item, 60); // 100 physical, 60 locked => 40 avail
        // Normal line asking 50 must fail (only 40 available)
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 50, 'unit_cost' => 10]],
        ])->assertSessionHas('error');
        // 40 passes
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 40, 'unit_cost' => 10]],
        ])->assertSessionHasNoErrors();
        $sti = StockTransfer::firstOrFail()->items()->firstOrFail();
        $this->assertNull($sti->reservation_item_id);
    }

    public function test_reserved_transfer_consumes_lock_on_dispatch(): void
    {
        [$admin, $wh1, $wh2, $item] = $this->world();
        $res = $this->reserve($admin, $wh1, $item, 60);
        $riId = $res->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('reservations.approve', $res))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('reservations.ready', $res))->assertSessionHasNoErrors();

        // Reserved line for 25 of the 60 locked
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 25, 'unit_cost' => 10, 'reservation_item_id' => $riId]],
        ])->assertSessionHasNoErrors();
        $trf = StockTransfer::firstOrFail();
        $sti = $trf->items()->firstOrFail();
        $this->assertEquals($riId, $sti->reservation_item_id);

        // Over-remaining must fail
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 61, 'unit_cost' => 10, 'reservation_item_id' => $riId]],
        ])->assertSessionHas('error');

        // Dispatch 25: stock moves AND lock consumed
        $this->actingAs($admin)->post(route('transfers.process_dispatch', $trf), [
            'dispatch_date' => '2026-09-02',
            'items' => [['sti_id' => $sti->id, 'quantity' => 25]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(75, $item->fresh()->quantity);
        $this->assertEquals(25, $res->items()->firstOrFail()->deployed_quantity);
        $this->assertEquals('PARTIALLY_DEPLOYED', $res->items()->firstOrFail()->status);
        $this->assertEquals('PARTIALLY_DEPLOYED', $res->fresh()->status);
        $dest = Item::where('warehouse_id', $wh2->id)->where('description', 'Src Packs')->firstOrFail();
        $this->assertEquals(25, $dest->quantity);

        // Show page displays the source
        $html = $this->actingAs($admin)->get(route('transfers.show', $trf))->assertOk()->getContent();
        $this->assertStringContainsString('Reserved', $html);
        $this->assertStringContainsString($res->reservation_number, $html);
    }

    public function test_destroy_reverses_reservation_deploy(): void
    {
        [$admin, $wh1, $wh2, $item] = $this->world();
        $res = $this->reserve($admin, $wh1, $item, 60);
        $riId = $res->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('reservations.approve', $res))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('reservations.ready', $res))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 60, 'unit_cost' => 10, 'reservation_item_id' => $riId]],
        ])->assertSessionHasNoErrors();
        $trf = StockTransfer::firstOrFail();
        $sti = $trf->items()->firstOrFail();
        $this->actingAs($admin)->post(route('transfers.process_dispatch', $trf), [
            'dispatch_date' => '2026-09-02',
            'items' => [['sti_id' => $sti->id, 'quantity' => 60]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals('DEPLOYED', $res->fresh()->status);

        $this->actingAs($admin)->delete(route('transfers.destroy', $trf))->assertRedirect();
        $this->assertEquals(100, $item->fresh()->quantity);
        $line = $res->items()->firstOrFail();
        $this->assertEquals(0, $line->deployed_quantity);
        $this->assertEquals('ACTIVE', $line->status);
    }

    public function test_dead_reservation_link_rejected(): void
    {
        [$admin, $wh1, $wh2, $item] = $this->world();
        $res = $this->reserve($admin, $wh1, $item, 60);
        $riId = $res->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('reservations.cancel', $res))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh1->id, 'to_warehouse_id' => $wh2->id, 'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 10, 'reservation_item_id' => $riId]],
        ])->assertSessionHas('error');
        $this->assertEquals(0, StockTransfer::count());
    }
}
