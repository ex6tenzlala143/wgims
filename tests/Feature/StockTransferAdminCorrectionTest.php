<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
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
 * Admin corrections to dispatched/completed Stock Transfers must reconcile the
 * full inventory movement — Source Warehouse → Transfer → Destination Warehouse
 * → Stock Cards → Inventory Balance — without corrupting history or lineage,
 * while Warehouse Managers stay locked out of editing.
 */
class StockTransferAdminCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'corr_admin_' . $i,
            'name'     => 'Correction Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function warehouseManager(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'corr_wm_' . $i,
            'name'     => 'Correction WM ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_WAREHOUSE_MANAGER,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    /** Subsidy request → shipment into $wh → returns the source Item holding the stock. */
    private function deliverStock(Warehouse $wh, string $description, int $qty, float $cost, string $ris): array
    {
        static $s = 0;
        $s++;

        $admin    = $this->admin();
        $supplier = Supplier::create(['name' => 'Corr Supplier ' . $s, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => [[
                    'description'     => $description,
                    'unit'            => 'piece',
                    'category'        => 'food',
                    'quantity'        => $qty,
                    'expiration_date' => '2027-01-01',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        $ds = DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-05',
                'dr_number'          => $ris . '-DR',
                'condition_status'   => 'good',
                'quantity_delivered' => $qty,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => $qty,
                    'unit_cost'          => $cost,
                    'engas_unit_cost'    => $cost,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => $ris . '-DR-A',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $item = Item::where('warehouse_id', $wh->id)->where('description', $description)->firstOrFail();

        return ['subsidy' => $ds, 'item' => $item];
    }

    private function createTransfer(Warehouse $from, Warehouse $to, Item $sourceItem, int $qty, float $cost): StockTransfer
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
            ->assertRedirect();

        return StockTransfer::where('from_warehouse_id', $from->id)
            ->where('to_warehouse_id', $to->id)
            ->firstOrFail();
    }

    private function dispatchTransfer(StockTransfer $transfer, int $qty, string $date = '2026-08-13'): void
    {
        $sti = $transfer->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('transfers.process_dispatch', $transfer), [
                'dispatch_date' => $date,
                'items'         => [['sti_id' => $sti->id, 'quantity' => $qty]],
            ])
            ->assertSessionHasNoErrors();
    }

    /** Full chain: subsidy → delivery → transfer → dispatched. */
    private function dispatchedTransferFlow(int $stockQty, int $transferQty, float $cost = 250.0): array
    {
        static $f = 0;
        $f++;

        $whA = $this->makeWarehouse('Source WH ' . $f, 'SWH' . $f);
        $whB = $this->makeWarehouse('Dest WH ' . $f, 'DWH' . $f);

        $stock  = $this->deliverStock($whA, 'Ration Pack', $stockQty, $cost, 'RIS-CORR-' . $f);
        $sourceItem = $stock['item'];

        $transfer = $this->createTransfer($whA, $whB, $sourceItem, $transferQty, $cost);
        $this->dispatchTransfer($transfer, $transferQty);

        $transfer  = $transfer->fresh();
        $sti       = $transfer->items()->firstOrFail();
        $destItem  = Item::find($sti->destination_item_id);

        return compact('whA', 'whB', 'sourceItem', 'destItem', 'transfer', 'sti') + ['cost' => $cost];
    }

    public function test_admin_can_decrease_a_dispatched_transfer_and_inventory_reconciles(): void
    {
        // 100 transferred GAMC1 → GAMC2; admin corrects it to 80.
        $flow     = $this->dispatchedTransferFlow(stockQty: 200, transferQty: 100, cost: 250.0);
        $transfer = $flow['transfer'];
        $sti      = $flow['sti'];

        $this->assertEquals('completed', $transfer->status);
        $this->assertEquals(100, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(100, $flow['destItem']->fresh()->quantity);

        $response = $this->actingAs($this->admin())
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'remarks'       => 'Corrected dispatched quantity',
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 80,
                    'unit_cost' => 250.00,
                ]],
            ]);

        $response->assertRedirect(route('transfers.show', $transfer));
        $response->assertSessionHas('success');

        // ── Inventory balances reconciled by the 20-unit difference ──
        $this->assertEquals(120, $flow['sourceItem']->fresh()->quantity, 'Source must regain the 20 units.');
        $this->assertEquals(80, $flow['destItem']->fresh()->quantity, 'Destination must reflect the corrected 80.');

        // ── Transfer line corrected, ID preserved ──
        $sti = $sti->fresh();
        $this->assertEquals(80, $sti->quantity);
        $this->assertEquals(80, $sti->quantity_requested, 'Planned qty must be corrected alongside a fully-dispatched line.');
        $this->assertEquals($transfer->id, $sti->stock_transfer_id);

        // ── Transfer identity & status preserved/correct ──
        $transfer = $transfer->fresh();
        $this->assertEquals('completed', $transfer->status, 'A corrected full transfer remains completed.');
        $this->assertTrue($transfer->exists);

        // ── Stock cards corrected ──
        $outSum = StockCardEntry::where('reference_type', 'transfer_out')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['sourceItem']->id)
            ->sum('issue_qty');
        $inSum = StockCardEntry::where('reference_type', 'transfer_in')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['destItem']->id)
            ->sum('receipt_qty');
        $this->assertEquals(80, $outSum);
        $this->assertEquals(80, $inSum);

        // Running balances rebuilt so later entries can never be stale.
        $lastOut = StockCardEntry::where('reference_type', 'transfer_out')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['sourceItem']->id)
            ->orderByDesc('id')->first();
        $lastIn = StockCardEntry::where('reference_type', 'transfer_in')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['destItem']->id)
            ->orderByDesc('id')->first();
        $this->assertEquals(120, $lastOut->balance_qty);
        $this->assertEquals(80, $lastIn->balance_qty);
        $this->assertEquals(80 * 250.0, (float) $lastIn->receipt_total_cost);

        // ── Audit trail records the correction ──
        $audit = StockTransferAuditLog::where('stock_transfer_id', $transfer->id)
            ->where('action', 'corrected')->latest('id')->first();
        $this->assertNotNull($audit, 'Correction must be written to the audit trail.');
        $lines = $audit->changed_fields['corrected_lines'];
        $this->assertEquals(100, $lines[0]['dispatched']['old']);
        $this->assertEquals(80, $lines[0]['dispatched']['new']);

        // ── Subsidy lineage intact ──
        $destItem = $flow['destItem']->fresh();
        $this->assertEquals('active', $destItem->source_subsidy_status);
        $this->assertEquals('RIS-CORR-1', $destItem->source_subsidy_ris);
    }

    public function test_admin_can_increase_a_dispatched_quantity_when_source_has_stock(): void
    {
        $flow     = $this->dispatchedTransferFlow(stockQty: 200, transferQty: 50, cost: 250.0);
        $transfer = $flow['transfer'];
        $sti      = $flow['sti'];

        $this->assertEquals(150, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(50, $flow['destItem']->fresh()->quantity);

        $this->actingAs($this->admin())
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'remarks'       => null,
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 90,
                    'unit_cost' => 250.00,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer))
            ->assertSessionHas('success');

        $this->assertEquals(110, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(90, $flow['destItem']->fresh()->quantity);

        $outSum = StockCardEntry::where('reference_type', 'transfer_out')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['sourceItem']->id)
            ->sum('issue_qty');
        $inSum = StockCardEntry::where('reference_type', 'transfer_in')
            ->where('reference_id', $transfer->id)->where('item_id', $flow['destItem']->id)
            ->sum('receipt_qty');
        $this->assertEquals(90, $outSum);
        $this->assertEquals(90, $inSum);

        $this->assertEquals('completed', $transfer->fresh()->status);
    }

    public function test_increase_beyond_source_stock_is_rejected_without_changes(): void
    {
        // Only 60 delivered; 50 transferred away → source holds just 10.
        $flow     = $this->dispatchedTransferFlow(stockQty: 60, transferQty: 50, cost: 100.0);
        $transfer = $flow['transfer'];
        $sti      = $flow['sti'];

        $beforeSource = $flow['sourceItem']->fresh()->quantity;
        $beforeDest   = $flow['destItem']->fresh()->quantity;
        $auditsBefore = StockTransferAuditLog::count();

        $this->actingAs($this->admin())
            ->from(route('transfers.edit', $transfer))
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 70, // needs +20 more than source holds (10)
                    'unit_cost' => 100.00,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['items.0.quantity']);

        // Nothing changed anywhere.
        $this->assertEquals($beforeSource, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals($beforeDest, $flow['destItem']->fresh()->quantity);
        $this->assertEquals(50, $sti->fresh()->quantity);
        $this->assertEquals($auditsBefore, StockTransferAuditLog::count(), 'A rejected correction must not write an audit entry.');

        $cardTotalsStillConsistent = StockCardEntry::where('reference_id', $transfer->id)
            ->where('reference_type', 'transfer_out')->sum('issue_qty');
        $this->assertEquals(50, $cardTotalsStillConsistent);
    }

    public function test_decrease_beyond_destination_holdings_is_rejected(): void
    {
        $flow     = $this->dispatchedTransferFlow(stockQty: 100, transferQty: 60, cost: 100.0);
        $transfer = $flow['transfer'];
        $sti      = $flow['sti'];

        // Simulate later consumption at the destination: only 5 units remain there.
        Item::whereKey($flow['destItem']->id)->update(['quantity' => 5]);

        $beforeSource = $flow['sourceItem']->fresh()->quantity;

        $this->actingAs($this->admin())
            ->from(route('transfers.edit', $transfer))
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 30, // would return 30 units but dest only holds 5
                    'unit_cost' => 100.00,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['items.0.quantity']);

        $this->assertEquals($beforeSource, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(5, $flow['destItem']->fresh()->quantity);
        $this->assertEquals(60, $sti->fresh()->quantity);
    }

    public function test_correcting_a_partially_dispatched_line_keeps_every_stock_card_valid(): void
    {
        // Build the chain manually: two separate shipments (40 + 35) against a
        // planned 100 — each dispatch writes its own stock-card pair.
        static $m = 0;
        $m++;
        $whA = $this->makeWarehouse('Multi Src ' . $m, 'MSW' . $m);
        $whB = $this->makeWarehouse('Multi Dst ' . $m, 'MDW' . $m);

        $stock      = $this->deliverStock($whA, 'Ration Pack', 300, 100.0, 'RIS-CORR-MULTI-' . $m);
        $sourceItem = $stock['item'];

        $transfer = $this->createTransfer($whA, $whB, $sourceItem, 100, 100.0);
        $this->dispatchTransfer($transfer, 40, '2026-08-13');
        $this->dispatchTransfer($transfer, 35, '2026-08-15');

        $sti       = $transfer->items()->firstOrFail();
        $destItem  = Item::find($sti->destination_item_id);
        $transfer  = $transfer->fresh();

        $entryCount = StockCardEntry::where('reference_id', $transfer->id)
            ->where('reference_type', 'transfer_out')->count();
        $this->assertEquals(2, $entryCount, 'Two shipments must produce two transfer_out entries.');
        $this->assertEquals('partial', $transfer->status);

        // Correct the dispatched total from 75 down to 50.
        $this->actingAs($this->admin())
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 50,
                    'unit_cost' => 100.00,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer))
            ->assertSessionHas('success');

        $outEntries = StockCardEntry::where('reference_id', $transfer->id)
            ->where('reference_type', 'transfer_out')->orderBy('id')->get();
        $inEntries  = StockCardEntry::where('reference_id', $transfer->id)
            ->where('reference_type', 'transfer_in')->orderBy('id')->get();

        // Every shipment row survives, none negative, sums match the correction.
        $this->assertCount(2, $outEntries);
        $this->assertCount(2, $inEntries);
        $this->assertEquals([40, 10], $outEntries->pluck('issue_qty')->all(), 'Newest entry absorbs the reduction.');
        $this->assertEquals([40, 10], $inEntries->pluck('receipt_qty')->all());

        // Inventory reconciled by the 25-unit difference.
        $this->assertEquals(250, $sourceItem->fresh()->quantity);
        $this->assertEquals(50, $destItem->fresh()->quantity);

        // Running balances rebuilt across BOTH entries.
        foreach ($outEntries as $entry) {
            $this->assertGreaterThanOrEqual(0, $entry->fresh()->balance_qty);
        }
        $lastIn = $inEntries->last()->fresh();
        $this->assertEquals(50, $lastIn->balance_qty);

        // Outstanding plan preserved: requested−dispatched stays 25
        // (100 − 75 before → 75 − 50 after), so the line remains partial.
        $sti = $sti->fresh();
        $this->assertEquals(50, $sti->quantity);
        $this->assertEquals(75, $sti->quantity_requested);
        $this->assertEquals('partial', $transfer->fresh()->status);
    }

    public function test_warehouse_manager_cannot_edit_a_transfer(): void
    {
        $flow     = $this->dispatchedTransferFlow(stockQty: 100, transferQty: 40, cost: 100.0);
        $transfer = $flow['transfer'];
        $sti      = $flow['sti'];
        $wm       = $this->warehouseManager();

        // Edit form is blocked…
        $this->actingAs($wm)
            ->get(route('transfers.edit', $transfer))
            ->assertForbidden();

        // …and so is the save itself.
        $this->actingAs($wm)
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-12',
                'items'         => [[
                    'sti_id'    => $sti->id,
                    'quantity'  => 10,
                    'unit_cost' => 100.00,
                ]],
            ])
            ->assertForbidden();

        // Nothing moved.
        $this->assertEquals(60, $flow['sourceItem']->fresh()->quantity);
        $this->assertEquals(40, $flow['destItem']->fresh()->quantity);
        $this->assertEquals(40, $sti->fresh()->quantity);
    }

    public function test_correction_preserves_transfer_identity_and_subsidy_linkage(): void
    {
        $flow = $this->dispatchedTransferFlow(stockQty: 120, transferQty: 60, cost: 500.0);
        $transfer = $flow['transfer'];

        $originalId      = $transfer->id;
        $originalNumber  = $transfer->transfer_number;
        $originalSubsidy = $transfer->delivery_subsidy_id;

        $this->actingAs($this->admin())
            ->put(route('transfers.update', $transfer), [
                'transfer_date' => '2026-08-20',
                'remarks'       => 'Line corrected after review',
                'items'         => [[
                    'sti_id'    => $flow['sti']->id,
                    'quantity'  => 45,
                    'unit_cost' => 500.00,
                ]],
            ])
            ->assertRedirect(route('transfers.show', $transfer));

        $fresh = StockTransfer::findOrFail($originalId);
        $this->assertEquals($originalNumber, $fresh->transfer_number, 'Transfer number/ID must never change on correction.');
        $this->assertEquals($originalSubsidy, $fresh->delivery_subsidy_id, 'Originating Subsidy link must survive the correction.');

        // Destination stock still traces back to the originating subsidy.
        $destItem = $flow['destItem']->fresh();
        $this->assertEquals($originalSubsidy, $destItem->source_subsidy_id);
        $this->assertEquals('active', $destItem->source_subsidy_status);

        // Exactly one transfer record — no duplicate created.
        $this->assertEquals(1, StockTransfer::where('transfer_number', $originalNumber)->count());
    }
}
