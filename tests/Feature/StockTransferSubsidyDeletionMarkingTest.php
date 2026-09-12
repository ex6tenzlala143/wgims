<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferAuditLog;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Subsidy whose stock has downstream transactions can NEVER be deleted:
 * executed transfers, RIS issuance, and active reservations all block the
 * delete with an explanation. Planned-but-undispatched transfers (moved
 * quantity = 0) do NOT block — they are PRESERVED and visibly flagged so the
 * administrator can review them.
 */
class StockTransferSubsidyDeletionMarkingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'mark_admin_' . $i,
            'name'     => 'Mark Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function createSubsidy(array $lines, string $ris): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'description'     => $line['description'],
                'unit'            => 'piece',
                'category'        => 'food',
                'quantity'        => $line['quantity'],
                'expiration_date' => '2027-01-01',
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => $items,
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        return DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
    }

    private function dispatch(DeliverySubsidy $ds, string $dr, Warehouse $wh, int $qty, float $cost): DeliverySubsidyItem
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

        return $line;
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

    /** Full chain: subsidy → delivery → transfer → dispatch. */
    private function subsidyToTransferFlow(string $ris, string $dr): array
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([['description' => 'Ration Pack', 'quantity' => 60]], $ris);
        $this->dispatch($ds, $dr, $whA, 60, 250);

        $sourceItem = Item::where('warehouse_id', $whA->id)->where('description', 'Ration Pack')->firstOrFail();
        $transfer   = $this->createTransfer($whA, $whB, $sourceItem, 20, 250);
        $this->dispatchTransfer($transfer, $sourceItem, 20);

        $destItem = Item::where('warehouse_id', $whB->id)->where('description', 'Ration Pack')->firstOrFail();

        return compact('whA', 'whB', 'ds', 'transfer', 'sourceItem', 'destItem');
    }

    public function test_subsidy_with_dispatched_transfer_cannot_be_deleted(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-DEL', 'DR-MARK-DEL');
        $transfer = $flow['transfer'];

        // The transfer is traced to the live subsidy at creation.
        $this->assertEquals($flow['ds']->id, (int) $transfer->delivery_subsidy_id);
        $this->assertNull($transfer->source_subsidy_status);
        $this->assertEquals(40, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(20, (float) $flow['destItem']->fresh()->quantity);

        // Delete is REFUSED — the transfer moved this subsidy's stock.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted')
                && str_contains($msg, $transfer->transfer_number));

        // Nothing changed: subsidy, stock, and the unflagged transfer survive.
        $this->assertDatabaseHas('delivery_subsidies', ['id' => $flow['ds']->id]);
        $this->assertEquals(40, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(20, (float) $flow['destItem']->fresh()->quantity);
        $this->assertNull($flow['transfer']->fresh()->source_subsidy_status);
    }

    public function test_subsidy_with_planned_only_transfer_deletes_and_marks_it(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([['description' => 'Ration Pack', 'quantity' => 60]], 'RIS-MARK-PLAN');
        $this->dispatch($ds, 'DR-MARK-PLAN', $whA, 60, 250);

        $sourceItem = Item::where('warehouse_id', $whA->id)->where('description', 'Ration Pack')->firstOrFail();
        // Planned only — never dispatched, so no stock moved.
        $transfer = $this->createTransfer($whA, $whB, $sourceItem, 20, 250);

        // A planned transfer does NOT block: delete succeeds and flags it.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertDatabaseMissing('delivery_subsidies', ['id' => $ds->id]);

        $transfer = $transfer->fresh();
        $this->assertNotNull($transfer, 'Stock Transfer must NEVER be auto-deleted with its subsidy.');
        $this->assertNull($transfer->delivery_subsidy_id, 'FK is nulled by the delete.');
        $this->assertEquals('deleted', $transfer->source_subsidy_status);
        $this->assertEquals('RIS-MARK-PLAN', $transfer->source_ris_number);
        $this->assertEquals($ds->dr_number, $transfer->source_dr_number);
        $this->assertTrue($transfer->isRelatedToDeletedSubsidy());

        // The delivery reversal still happened (60 → 0); nothing was moved.
        $this->assertEquals(0, (float) $sourceItem->fresh()->quantity);

        // No delete-audit rows are written anymore; the transfer itself keeps
        // the deletion flag and snapshots for review.
        $log = StockTransferAuditLog::where('stock_transfer_id', $transfer->id)
            ->where('action', 'subsidy_deleted')->first();
        $this->assertNull($log);

        // The review marker renders.
        $this->actingAs($this->admin())
            ->get(route('transfers.show', $transfer->id))
            ->assertOk()
            ->assertSee('RELATED TO DELETED SUBSIDY')
            ->assertSee('Ration Pack');
    }

    public function test_subsidy_with_onward_chain_cannot_be_deleted(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-BLOCK', 'DR-MARK-BLOCK');
        $whC  = $this->makeWarehouse('Warehouse C', 'WHC');

        // The transferred stock at WH B is sent ONWARD to WH C.
        $onward = $this->createTransfer($flow['whB'], $whC, $flow['destItem'], 5, 250);
        $this->dispatchTransfer($onward, $flow['destItem'], 5);

        // Delete is REFUSED — the whole chain stays intact.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted'));

        $this->assertDatabaseHas('delivery_subsidies', ['id' => $flow['ds']->id]);
        $this->assertEquals(40, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(15, (float) $flow['destItem']->fresh()->quantity);
        $this->assertEquals(5, (float) Item::where('warehouse_id', $whC->id)->where('description', 'Ration Pack')->firstOrFail()->quantity);
    }

    public function test_deleting_marked_planned_transfer_reverses_nothing_and_leaves_no_cards(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([['description' => 'Ration Pack', 'quantity' => 60]], 'RIS-MARK-REV');
        $this->dispatch($ds, 'DR-MARK-REV', $whA, 60, 250);

        $sourceItem = Item::where('warehouse_id', $whA->id)->where('description', 'Ration Pack')->firstOrFail();
        // Planned only — the transfer is marked (not blocked) on subsidy delete.
        $transfer = $this->createTransfer($whA, $whB, $sourceItem, 20, 250);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));
        $this->assertEquals('deleted', $transfer->fresh()->source_subsidy_status);

        // Deleting the marked (never-dispatched) transfer succeeds: nothing
        // ever moved, so quantities stay 0/0 and no cards remain.
        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $transfer))
            ->assertRedirect(route('transfers.index'));

        $this->assertEquals(0, (float) $sourceItem->fresh()->quantity);

        // No stock-card movement for the transfer remains.
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_out')
            ->where('reference_id', $transfer->id)->count());
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_in')
            ->where('reference_id', $transfer->id)->count());

        // No delete-audit rows are written anymore; stock reversal is verified above.
        $this->assertDatabaseMissing('stock_transfers', ['id' => $transfer->id]);
        $this->assertDatabaseMissing('stock_transfer_audit_logs', [
            'transfer_number' => $transfer->transfer_number,
            'action'          => 'reversed_deleted',
        ]);
    }

    public function test_transfers_index_filters_related_to_deleted_subsidy(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([['description' => 'Ration Pack', 'quantity' => 60]], 'RIS-MARK-FILT');
        $this->dispatch($ds, 'DR-MARK-FILT', $whA, 60, 250);

        $sourceItem = Item::where('warehouse_id', $whA->id)->where('description', 'Ration Pack')->firstOrFail();
        // Planned only, so the subsidy delete marks (not blocks) this transfer.
        $transfer = $this->createTransfer($whA, $whB, $sourceItem, 20, 250);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $ds))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Filter: only the flagged transfer appears.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['related_to_deleted_subsidy' => 'yes']))
            ->assertOk()
            ->assertSee($transfer->transfer_number)
            ->assertSee('RELATED TO DELETED SUBSIDY');

        // Inverse filter: it does not appear when excluded.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['related_to_deleted_subsidy' => 'no']))
            ->assertOk()
            ->assertDontSee($transfer->transfer_number);

        // Search by the original RIS reference finds it too.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['q' => 'RIS-MARK-FILT']))
            ->assertOk()
            ->assertSee($transfer->transfer_number);
    }
}