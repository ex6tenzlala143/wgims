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
 * When a Subsidy is deleted/archived its related Stock Transfers must be
 * PRESERVED and visibly flagged — never deleted automatically — so the
 * administrator can review and (deliberately) reverse them.
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

    public function test_deleting_subsidy_preserves_and_marks_related_transfer(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-DEL', 'DR-MARK-DEL');
        $transfer = $flow['transfer'];

        // The transfer is traced to the live subsidy at creation.
        $this->assertEquals($flow['ds']->id, (int) $transfer->delivery_subsidy_id);
        $this->assertNull($transfer->source_subsidy_status);
        $this->assertEquals(40, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(20, (float) $flow['destItem']->fresh()->quantity);

        // Dispatching the transfer carried the source-subsidy trail to the
        // destination warehouse's stock record.
        $destItem = $flow['destItem']->fresh();
        $this->assertEquals('active', $destItem->source_subsidy_status);
        $this->assertEquals($flow['ds']->id, (int) $destItem->source_subsidy_id);
        $this->assertEquals('RIS-MARK-DEL', $destItem->source_subsidy_ris);

        // Delete the subsidy — the transfer must SURVIVE and be flagged.
        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertDatabaseMissing('delivery_subsidies', ['id' => $flow['ds']->id]);

        $transfer = $transfer->fresh();
        $this->assertNotNull($transfer, 'Stock Transfer must NEVER be auto-deleted with its subsidy.');
        $this->assertNull($transfer->delivery_subsidy_id, 'FK is nulled by the delete.');
        $this->assertEquals('deleted', $transfer->source_subsidy_status);
        $this->assertEquals('RIS-MARK-DEL', $transfer->source_ris_number);
        $this->assertEquals($flow['ds']->dr_number, $transfer->source_dr_number);
        $this->assertTrue($transfer->isRelatedToDeletedSubsidy());
        $this->assertEquals('Deleted', $transfer->sourceSubsidyStatusLabel());

        // The subsidy deletion reverses its DELIVERY (the WH A stock it created)
        // but must NOT touch the transfer's own movement at WH B — that is
        // exactly what the admin reviews via the preserved, flagged transfer.
        $this->assertEquals(0, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(20, (float) $flow['destItem']->fresh()->quantity);

        // The destination stock is ALSO flagged — the FROM DELETED SUBSIDY
        // marker follows the transferred stock to the other warehouse.
        $this->assertEquals('deleted', $flow['sourceItem']->fresh()->source_subsidy_status);
        $this->assertTrue($flow['sourceItem']->fresh()->isRelatedToDeletedSubsidy());
        $this->assertEquals('deleted', $flow['destItem']->fresh()->source_subsidy_status);
        $this->assertTrue($flow['destItem']->fresh()->isRelatedToDeletedSubsidy());

        // Audit trail on the transfer survives the subsidy record.
        $log = StockTransferAuditLog::where('stock_transfer_id', $transfer->id)
            ->where('action', 'subsidy_deleted')->first();
        $this->assertNotNull($log);
        $this->assertEquals('RIS-MARK-DEL', $log->changed_fields['ris_number']);
        $this->assertEquals('deleted', $log->changed_fields['subsidy_status']);

        // Both pages render the review marker.
        $this->actingAs($this->admin())
            ->get(route('transfers.show', $transfer->id))
            ->assertOk()
            ->assertSee('RELATED TO DELETED SUBSIDY')
            ->assertSee('Ration Pack');
    }

    public function test_archiving_subsidy_marks_related_transfers_and_restore_clears(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-ARC', 'DR-MARK-ARC');
        $transfer = $flow['transfer'];

        $this->actingAs($this->admin())
            ->patch(route('delivery_subsidies.archive', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Subsidy archived, transfer flagged — still fully intact.
        $this->assertDatabaseHas('delivery_subsidies', ['id' => $flow['ds']->id, 'is_archived' => 1]);
        $this->assertEquals('archived', $transfer->fresh()->source_subsidy_status);
        $this->assertTrue($transfer->fresh()->isRelatedToDeletedSubsidy());
        $this->assertEquals('Archived', $transfer->fresh()->sourceSubsidyStatusLabel());
        $this->assertEquals(40, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(20, (float) $flow['destItem']->fresh()->quantity);

        // Destination stock is flagged 'archived' alongside the source — the
        // marker follows the transferred stock to the other warehouse.
        $this->assertEquals('archived', $flow['sourceItem']->fresh()->source_subsidy_status);
        $this->assertEquals('archived', $flow['destItem']->fresh()->source_subsidy_status);
        $this->assertTrue($flow['destItem']->fresh()->isRelatedToDeletedSubsidy());

        $this->assertDatabaseHas('stock_transfer_audit_logs', [
            'stock_transfer_id' => $transfer->id,
            'action'            => 'subsidy_archived',
        ]);

        // The subsidy list shows the archived badge.
        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.index'))
            ->assertOk()
            ->assertSee('Archived');

        // Restore → archive flag cleared, transfer flag cleared.
        $this->actingAs($this->admin())
            ->patch(route('delivery_subsidies.restore', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->assertDatabaseHas('delivery_subsidies', ['id' => $flow['ds']->id, 'is_archived' => 0]);
        $this->assertNull($transfer->fresh()->source_subsidy_status);
        $this->assertFalse($transfer->fresh()->isRelatedToDeletedSubsidy());

        // Items return to null status — restore clears the 'archived' warning flag.
        // The system uses null (not 'active') to mean "no subsidy warning" after restore.
        $this->assertNull($flow['sourceItem']->fresh()->source_subsidy_status);
        $this->assertNull($flow['destItem']->fresh()->source_subsidy_status);
        $this->assertFalse($flow['destItem']->fresh()->isRelatedToDeletedSubsidy());

        $this->assertDatabaseHas('stock_transfer_audit_logs', [
            'stock_transfer_id' => $transfer->id,
            'action'            => 'subsidy_restored',
        ]);
    }

    public function test_transfer_related_to_deleted_subsidy_cannot_be_deleted_while_stock_is_reused(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-BLOCK', 'DR-MARK-BLOCK');
        $whC  = $this->makeWarehouse('Warehouse C', 'WHC');

        // The transferred stock at WH B is sent ONWARD to WH C — the movement at
        // WH B now depends on this transfer's arrival.
        $onward = $this->createTransfer($flow['whB'], $whC, $flow['destItem'], 5, 250);
        $this->dispatchTransfer($onward, $flow['destItem'], 5);

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));
        $this->assertEquals('deleted', $flow['transfer']->fresh()->source_subsidy_status);

        // WH A lost the delivered stock (delivery reversed); WH B holds the
        // transferred stock minus the 5 already sent onward to WH C.
        $this->assertEquals(0, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(15, (float) $flow['destItem']->fresh()->quantity);
        $this->assertEquals(5, (float) Item::where('warehouse_id', $whC->id)->where('description', 'Ration Pack')->firstOrFail()->quantity);

        // Every hop of the lineage is flagged as FROM DELETED SUBSIDY.
        $whCItem = Item::where('warehouse_id', $whC->id)->where('description', 'Ration Pack')->firstOrFail();
        $this->assertEquals('deleted', $whCItem->source_subsidy_status);
        $this->assertEquals('deleted', $flow['destItem']->fresh()->source_subsidy_status);

        // Deleting the marked transfer must be REFUSED with an explanation…
        $response = $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $flow['transfer']));

        $response->assertRedirect();
        $response->assertSessionHas('error', fn (string $msg) => str_contains($msg, 'cannot be deleted yet')
            && str_contains($msg, 'Transfer out'));
        $this->assertDatabaseHas('stock_transfers', ['id' => $flow['transfer']->id]);
        $this->assertEquals(0, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(15, (float) $flow['destItem']->fresh()->quantity);
        $this->assertEquals(5, (float) Item::where('warehouse_id', $whC->id)->where('description', 'Ration Pack')->firstOrFail()->quantity);

        // Once the dependent onward transfer is gone, deletion is allowed.
        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $onward))
            ->assertRedirect(route('transfers.index'));

        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $flow['transfer']))
            ->assertRedirect(route('transfers.index'));

        $this->assertDatabaseMissing('stock_transfers', ['id' => $flow['transfer']->id]);
        $this->assertEquals(20, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(0, (float) $flow['destItem']->fresh()->quantity);
        $this->assertEquals(0, (float) Item::where('warehouse_id', $whC->id)->where('description', 'Ration Pack')->firstOrFail()->quantity);
    }

    public function test_deleting_marked_transfer_reverses_stock_and_keeps_audit_trail(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-REV', 'DR-MARK-REV');

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));

        $this->actingAs($this->admin())
            ->delete(route('transfers.destroy', $flow['transfer']))
            ->assertRedirect(route('transfers.index'));

        // The transferred 20 returns to WH A (the delivery itself is already
        // gone, so WH A's base stock is only what the reversal brings back);
        // WH B returns to zero.
        $this->assertEquals(20, (float) $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(0, (float) $flow['destItem']->fresh()->quantity);

        // No stock-card movement for the transfer remains.
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_out')
            ->where('reference_id', $flow['transfer']->id)->count());
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'transfer_in')
            ->where('reference_id', $flow['transfer']->id)->count());

        // The reversal is logged and the log row OUTLIVES the transfer.
        $this->assertDatabaseMissing('stock_transfers', ['id' => $flow['transfer']->id]);
        $this->assertDatabaseHas('stock_transfer_audit_logs', [
            'transfer_number' => $flow['transfer']->transfer_number,
            'action'          => 'reversed_deleted',
        ]);
        $log = StockTransferAuditLog::where('transfer_number', $flow['transfer']->transfer_number)
            ->where('action', 'reversed_deleted')->first();
        $this->assertNotNull($log->changed_fields['reversed_lines']);
    }

    public function test_transfers_index_filters_related_to_deleted_subsidy(): void
    {
        $flow = $this->subsidyToTransferFlow('RIS-MARK-FILT', 'DR-MARK-FILT');

        $this->actingAs($this->admin())
            ->delete(route('delivery_subsidies.destroy', $flow['ds']))
            ->assertRedirect(route('delivery_subsidies.index'));

        // Filter: only the flagged transfer appears.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['related_to_deleted_subsidy' => 'yes']))
            ->assertOk()
            ->assertSee($flow['transfer']->transfer_number)
            ->assertSee('RELATED TO DELETED SUBSIDY');

        // Inverse filter: it does not appear when excluded.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['related_to_deleted_subsidy' => 'no']))
            ->assertOk()
            ->assertDontSee($flow['transfer']->transfer_number);

        // Search by the original RIS reference finds it too.
        $this->actingAs($this->admin())
            ->get(route('transfers.index', ['q' => 'RIS-MARK-FILT']))
            ->assertOk()
            ->assertSee($flow['transfer']->transfer_number);
    }
}