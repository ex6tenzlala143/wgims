<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A Subsidy whose stock moved through Stock Transfers can NEVER be deleted —
 * neither the direct hop nor any multi-hop descendant. Lineage is followed
 * through the Stock Transfer chain (destination item links + multi-hop
 * recursion), never identified by warehouse name. The backfill migration
 * tests below cover pre-existing data independently of deletion.
 */
class SubsidyLineageThroughTransfersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'lineage_admin_' . $i,
            'name'     => 'Lineage Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function createSubsidy(string $ris, int $qty): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => [[
                    'item_id'         => null,
                    'description'     => 'Ration Pack',
                    'unit'            => 'piece',
                    'category'        => 'food',
                    'quantity'        => $qty,
                    'expiration_date' => '2027-01-01',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        return DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
    }

    private function dispatch(DeliverySubsidy $ds, string $dr, Warehouse $wh, int $qty, float $cost): void
    {
        $line = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => $dr,
                'condition_status'   => 'good',
                'quantity_delivered' => $qty,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => $qty,
                    'unit_cost'          => $cost,
                    'engas_unit_cost'    => $cost,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => $dr . '-A',
                ]],
            ])
            ->assertSessionHasNoErrors();
    }

    private function createTransfer(Warehouse $from, Warehouse $to, Item $sourceItem, float $qty, float $cost): StockTransfer
    {
        $this->actingAs($this->admin())
            ->post(route('transfers.store'), [
                'from_warehouse_id' => $from->id,
                'to_warehouse_id'   => $to->id,
                'transfer_date'     => '2026-08-12',
                'items'             => [[
                    'item_id'   => $sourceItem->id,
                    'quantity'  => $qty,
                    'unit_cost' => $cost,
                ]],
            ])
            ->assertSessionHasNoErrors();

        return StockTransfer::where('from_warehouse_id', $from->id)
            ->where('to_warehouse_id', $to->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function dispatchTransfer(StockTransfer $transfer, Item $sourceItem, float $qty): void
    {
        $sti = StockTransferItem::where('stock_transfer_id', $transfer->id)
            ->where('item_id', $sourceItem->id)
            ->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('transfers.process_dispatch', $transfer), [
                'dispatch_date' => '2026-08-13',
                'items'         => [['sti_id' => $sti->id, 'quantity' => $qty]],
            ])
            ->assertSessionHasNoErrors();
    }

    private function itemAt(Warehouse $wh): Item
    {
        return Item::where('warehouse_id', $wh->id)->where('description', 'Ration Pack')->firstOrFail();
    }

    /**
     * The exact reported scenario: Subsidy delivers 200 to Warehouse A, 100 is
     * transferred and dispatched to Warehouse B — the Subsidy can no longer be
     * deleted. BOTH warehouses' stock (100 each) stays exactly as it was.
     */
    public function test_subsidy_with_transferred_stock_cannot_be_deleted(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');

        $ds = $this->createSubsidy('RIS-LIN-200', 200);
        $this->dispatch($ds, 'DR-LIN-200', $whA, 200, 150);

        $sourceItem = $this->itemAt($whA);
        $transfer   = $this->createTransfer($whA, $whB, $sourceItem, 100, 150);
        $this->dispatchTransfer($transfer, $sourceItem, 100);

        $destItem = $this->itemAt($whB);

        // 100 stays in WH A, 100 moved to WH B — both from the same Subsidy.
        $this->assertEquals(100, (float) $sourceItem->fresh()->quantity);
        $this->assertEquals(100, (float) $destItem->fresh()->quantity);

        // The destination carried the lineage at dispatch time.
        $this->assertEquals('active', $destItem->fresh()->source_subsidy_status);
        $this->assertEquals($ds->id, (int) $destItem->fresh()->source_subsidy_id);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted')
                && str_contains($msg, $transfer->transfer_number));

        $this->assertDatabaseHas('delivery_subsidies', ['id' => $ds->id]);

        // Nothing moved: WH A and WH B quantities are intact, transfer unflagged.
        $this->assertEquals(100, (float) $sourceItem->fresh()->quantity);
        $this->assertEquals(100, (float) $destItem->fresh()->quantity);
        $this->assertNull($transfer->fresh()->source_subsidy_status);
    }

    public function test_multi_hop_chain_blocks_deletion(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');
        $whC = $this->makeWarehouse('GAMC 3', 'GAMC3');

        $ds = $this->createSubsidy('RIS-LIN-CHAIN', 200);
        $this->dispatch($ds, 'DR-LIN-CHAIN', $whA, 200, 150);

        $itemA = $this->itemAt($whA);
        $t1    = $this->createTransfer($whA, $whB, $itemA, 100, 150);
        $this->dispatchTransfer($t1, $itemA, 100);

        $itemB = $this->itemAt($whB);
        $t2    = $this->createTransfer($whB, $whC, $itemB, 30, 150);
        $this->dispatchTransfer($t2, $itemB, 30);

        $itemC = $this->itemAt($whC);

        // Lineage propagated hop by hop.
        $this->assertEquals('active', $itemB->fresh()->source_subsidy_status);
        $this->assertEquals('active', $itemC->fresh()->source_subsidy_status);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted'));

        // Every hop of the chain is untouched.
        $this->assertDatabaseHas('delivery_subsidies', ['id' => $ds->id]);
        $this->assertEquals(100, (float) $itemA->fresh()->quantity);
        $this->assertEquals(70, (float) $itemB->fresh()->quantity);
        $this->assertEquals(30, (float) $itemC->fresh()->quantity);
    }

    public function test_delete_refused_when_transferred_stock_moved_onward(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');
        $whC = $this->makeWarehouse('GAMC 3', 'GAMC3');

        $ds = $this->createSubsidy('RIS-LIN-ONWD', 200);
        $this->dispatch($ds, 'DR-LIN-ONWD', $whA, 200, 150);

        $itemA = $this->itemAt($whA);
        $t1    = $this->createTransfer($whA, $whB, $itemA, 100, 150);
        $this->dispatchTransfer($t1, $itemA, 100);

        $itemB = $this->itemAt($whB);
        $t2    = $this->createTransfer($whB, $whC, $itemB, 20, 150);
        $this->dispatchTransfer($t2, $itemB, 20);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'moved by transfer')
                && str_contains($msg, $t2->transfer_number));

        // The onward-moved stock is untouched.
        $this->assertEquals(80, (float) $this->itemAt($whB)->fresh()->quantity);
        $this->assertEquals(20, (float) $this->itemAt($whC)->fresh()->quantity);
    }

    public function test_delete_refused_when_transferred_stock_was_issued_to_requisition(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');

        $ds = $this->createSubsidy('RIS-LIN-REQ', 200);
        $this->dispatch($ds, 'DR-LIN-REQ', $whA, 200, 150);

        $itemA = $this->itemAt($whA);
        $t1    = $this->createTransfer($whA, $whB, $itemA, 100, 150);
        $this->dispatchTransfer($t1, $itemA, 100);

        $destItem = $this->itemAt($whB);

        // A requisition has already issued the transferred stock.
        $admin = $this->admin();
        $req   = Requisition::create([
            'ris_number'     => 'RIS-LIN-REQ-1',
            'warehouse_id'   => null,
            'created_by'     => $admin->id,
            'purpose'        => 'Issue transferred stock',
            'date_requested' => '2026-08-14',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id'     => $req->id,
            'item_id'            => $destItem->id,
            'description'        => 'Ration Pack',
            'unit'               => 'piece',
            'quantity_requested' => 10,
            'quantity_issued'    => 10,
            'stock_available'    => true,
            'unit_cost'          => 150,
            'engas_unit_cost'    => 150,
        ]);
        RequisitionDispatchItem::create([
            'requisition_item_id' => $ri->id,
            'item_id'             => $destItem->id,
            'quantity_issued'     => 10,
            'unit_cost'           => 150,
            'engas_unit_cost'     => 150,
            'expiration_date'     => '2027-01-01',
            'dr_number'           => 'DR-LIN-REQ-A',
            'created_by'          => $admin->id,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'issued through RIS')
                && str_contains($msg, 'RIS-LIN-REQ-1'));

        // The issued stock is untouched.
        $this->assertEquals(100, (float) $destItem->fresh()->quantity);
        $this->assertDatabaseHas('delivery_subsidies', ['id' => $ds->id]);
    }

    public function test_backfill_migration_propagates_snapshot_to_pre_existing_destinations(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');
        $whC = $this->makeWarehouse('GAMC 3', 'GAMC3');

        $ds = $this->createSubsidy('RIS-LIN-BFILL', 200);
        $this->dispatch($ds, 'DR-LIN-BFILL', $whA, 200, 150);

        $itemA = $this->itemAt($whA);
        $t1    = $this->createTransfer($whA, $whB, $itemA, 100, 150);
        $this->dispatchTransfer($t1, $itemA, 100);

        $itemB = $this->itemAt($whB);
        $t2    = $this->createTransfer($whB, $whC, $itemB, 30, 150);
        $this->dispatchTransfer($t2, $itemB, 30);

        // Simulate data created BEFORE the lineage feature: destinations carry
        // no snapshot even though their source does.
        DB::table('items')->where('id', $itemB->id)->update([
            'source_subsidy_id' => null, 'source_subsidy_ris' => null,
            'source_subsidy_dr' => null, 'source_subsidy_status' => null,
        ]);
        DB::table('items')->where('id', $this->itemAt($whC)->id)->update([
            'source_subsidy_id' => null, 'source_subsidy_ris' => null,
            'source_subsidy_dr' => null, 'source_subsidy_status' => null,
        ]);
        $this->assertNull($itemB->fresh()->source_subsidy_status);

        $migration = require base_path('database/migrations/2026_08_13_000002_backfill_subsidy_lineage_to_transfer_destinations.php');
        $migration->up();

        // Both hops are backfilled from the source, recursively.
        // The backfill copies source_subsidy_status (which is 'active') to destinations.
        $this->assertEquals('active', $itemB->fresh()->source_subsidy_status);
        $this->assertEquals($ds->id, (int) $itemB->fresh()->source_subsidy_id);
        $this->assertEquals('active', $this->itemAt($whC)->fresh()->source_subsidy_status);
        $this->assertEquals($ds->id, (int) $this->itemAt($whC)->fresh()->source_subsidy_id);
    }

    public function test_backfill_covers_stock_from_an_already_deleted_subsidy(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');

        $ds = $this->createSubsidy('RIS-LIN-DEL-SRC', 200);
        $this->dispatch($ds, 'DR-LIN-DEL-SRC', $whA, 200, 150);

        $itemA = $this->itemAt($whA);
        $t1    = $this->createTransfer($whA, $whB, $itemA, 100, 150);
        $this->dispatchTransfer($t1, $itemA, 100);
        $destItem = $this->itemAt($whB);

        // Simulate a subsidy deleted the old way (before the hard block
        // existed): the source keeps its 'deleted' snapshot, the subsidy row
        // is gone. Route deletion is used nowhere here on purpose.
        $drNumber = $ds->dr_number;
        DB::table('items')->where('id', $itemA->id)->update([
            'source_subsidy_id' => null, 'source_subsidy_ris' => 'RIS-LIN-DEL-SRC',
            'source_subsidy_dr' => $drNumber, 'source_subsidy_status' => 'deleted',
        ]);
        $ds->delete();

        $source = $itemA->fresh();
        $this->assertEquals('deleted', $source->source_subsidy_status);
        $this->assertNull($source->source_subsidy_id);
        $this->assertEquals('RIS-LIN-DEL-SRC', $source->source_subsidy_ris);

        // The destination still carries no lineage (data predates the fix).
        DB::table('items')->where('id', $destItem->id)->update([
            'source_subsidy_id' => null, 'source_subsidy_ris' => null,
            'source_subsidy_dr' => null, 'source_subsidy_status' => null,
        ]);
        $this->assertNull($destItem->fresh()->source_subsidy_status);

        $migration = require base_path('database/migrations/2026_08_13_000002_backfill_subsidy_lineage_to_transfer_destinations.php');
        $migration->up();

        // The 'deleted' trail propagates even without a live subsidy id.
        $dest = $destItem->fresh();
        $this->assertEquals('deleted', $dest->source_subsidy_status);
        $this->assertEquals('RIS-LIN-DEL-SRC', $dest->source_subsidy_ris);
        $this->assertEquals($ds->dr_number, $dest->source_subsidy_dr);
        $this->assertNull($dest->source_subsidy_id);
        $this->assertTrue($dest->isRelatedToDeletedSubsidy());
    }
}
