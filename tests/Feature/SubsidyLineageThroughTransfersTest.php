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
 * Deleting (or archiving) a Subsidy must flag EVERY item that carries its
 * stock — including stock that has been transferred to another warehouse.
 * Lineage is followed through the Stock Transfer chain (destination item links
 * + multi-hop recursion), never identified by warehouse name.
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
     * transferred and dispatched to Warehouse B, then the Subsidy is deleted.
     * BOTH warehouses' stock (100 each) must be flagged FROM DELETED SUBSIDY.
     */
    public function test_deleting_subsidy_marks_transferred_stock_at_destination_warehouse(): void
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

        // The destination carried the lineage at dispatch time — status is 'active' (set by storeDelivery).
        $this->assertEquals('active', $destItem->fresh()->source_subsidy_status);
        $this->assertEquals($ds->id, (int) $destItem->fresh()->source_subsidy_id);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'))
            ->assertSessionMissing('warning');

        $this->assertDatabaseMissing('delivery_subsidies', ['id' => $ds->id]);

        // WH A stock was reversed (delivery undone) and flagged.
        $this->assertEquals(0, (float) $sourceItem->fresh()->quantity);
        $this->assertEquals('deleted', $sourceItem->fresh()->source_subsidy_status);
        $this->assertTrue($sourceItem->fresh()->isRelatedToDeletedSubsidy());

        // WH B stock is PRESERVED (the transfer movement is never auto-reversed)
        // but is now flagged as FROM DELETED SUBSIDY too.
        $this->assertEquals(100, (float) $destItem->fresh()->quantity);
        $this->assertEquals('deleted', $destItem->fresh()->source_subsidy_status);
        $this->assertTrue($destItem->fresh()->isRelatedToDeletedSubsidy());
        $this->assertEquals('Deleted', $destItem->fresh()->sourceSubsidyStatusLabel());

        // The marker is visible on WH B's stock card page.
        $this->actingAs($this->admin())
            ->get(route('stock_cards.item_history', $destItem->id))
            ->assertOk()
            ->assertSee('FROM DELETED SUBSIDY');
    }

    public function test_archiving_subsidy_marks_transferred_stock_until_restored(): void
    {
        $whA = $this->makeWarehouse('GAMC 1', 'GAMC1');
        $whB = $this->makeWarehouse('GAMC 2', 'GAMC2');

        $ds = $this->createSubsidy('RIS-LIN-ARC', 120);
        $this->dispatch($ds, 'DR-LIN-ARC', $whA, 120, 150);

        $sourceItem = $this->itemAt($whA);
        $transfer   = $this->createTransfer($whA, $whB, $sourceItem, 40, 150);
        $this->dispatchTransfer($transfer, $sourceItem, 40);
        $destItem = $this->itemAt($whB);

        $this->actingAs($this->admin())
            ->patch(route('delivery_subsidies.archive', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertEquals('archived', $sourceItem->fresh()->source_subsidy_status);
        $this->assertEquals('archived', $destItem->fresh()->source_subsidy_status);
        $this->assertTrue($destItem->fresh()->isRelatedToDeletedSubsidy());
        $this->assertEquals(80, (float) $sourceItem->fresh()->quantity);
        $this->assertEquals(40, (float) $destItem->fresh()->quantity);

        $this->actingAs($this->admin())
            ->patch(route('delivery_subsidies.restore', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Restore clears the 'archived' warning — status goes back to null (no warning).
        $this->assertNull($sourceItem->fresh()->source_subsidy_status);
        $this->assertNull($destItem->fresh()->source_subsidy_status);
        $this->assertFalse($destItem->fresh()->isRelatedToDeletedSubsidy());
    }

    public function test_multi_hop_transfers_mark_every_warehouse_in_the_chain(): void
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

        // Lineage propagated hop by hop — transfer store() copies the source's 'active' status to destinations.
        $this->assertEquals('active', $itemB->fresh()->source_subsidy_status);
        $this->assertEquals('active', $itemC->fresh()->source_subsidy_status);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Every hop of the chain is flagged.
        $this->assertEquals('deleted', $itemA->fresh()->source_subsidy_status);
        $this->assertEquals('deleted', $itemB->fresh()->source_subsidy_status);
        $this->assertEquals('deleted', $itemC->fresh()->source_subsidy_status);

        // Only the direct transfer (WH A → WH B) counts as the preserved review
        // item; the onward hop (WH B → WH C) triggers the downstream warning.
        $this->assertDatabaseHas('stock_transfers', ['id' => $t2->id, 'source_subsidy_status' => 'deleted']);
    }

    public function test_delete_warns_when_transferred_stock_moved_onward(): void
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
            ->assertRedirect(route('delivery_subsidies.index'))
            ->assertSessionHas('warning', fn (string $msg) => str_contains($msg, 'transferred onward')
                && str_contains($msg, $t2->transfer_number)
                && str_contains($msg, 'preserved and flagged'));

        // The onward-moved stock is preserved, not reversed.
        $this->assertEquals(80, (float) $this->itemAt($whB)->fresh()->quantity);
        $this->assertEquals(20, (float) $this->itemAt($whC)->fresh()->quantity);
    }

    public function test_delete_warns_when_transferred_stock_was_issued_to_requisition(): void
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
            ->assertRedirect(route('delivery_subsidies.index'))
            ->assertSessionHas('warning', fn (string $msg) => str_contains($msg, 'issued through a requisition')
                && str_contains($msg, 'RIS-LIN-REQ-1'));

        // The transferred stock was used downstream — preserved, not reversed.
        $this->assertEquals(100, (float) $destItem->fresh()->quantity);
        $this->assertEquals('deleted', $destItem->fresh()->source_subsidy_status);
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

        // Delete the subsidy the old way (before the lineage fix existed): the
        // source keeps its 'deleted' snapshot but the FK nulls source_subsidy_id.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

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
