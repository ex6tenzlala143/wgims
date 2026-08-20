<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySubsidyPropagationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'prop_admin_' . $i,
            'name'     => 'Prop Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(
        Warehouse $wh,
        string $description,
        float $cost = 10.0,
        float $qty = 0,
        ?string $expiry = null,
        ?float $engas = null
    ): Item {
        return Item::create([
            'stock_number'    => null,
            'description'     => $description,
            'unit'            => 'piece',
            'category'        => 'food',
            'account_code'    => '1040202000-01',
            'warehouse_id'    => $wh->id,
            'unit_cost'       => $cost,
            'engas_unit_cost' => $engas,
            'quantity'        => $qty,
            'expiration_date' => $expiry,
            'is_active'       => true,
        ]);
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
                'expiration_date' => $line['expiration_date'] ?? '2027-01-01',
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

    private function dispatch(DeliverySubsidy $ds, string $dr, array $lines): void
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'ds_item_id'         => $line['ds_item_id'],
                'warehouse_id'       => $line['warehouse_id'],
                'quantity_delivered' => $line['quantity_delivered'],
                'unit_cost'          => $line['unit_cost'],
                'engas_unit_cost'    => $line['engas_unit_cost'] ?? $line['unit_cost'],
                'expiration_date'    => $line['expiration_date'] ?? '2027-01-01',
                'dr_number'          => $line['dr_number'],
            ];
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => $dr,
                'condition_status'   => 'good',
                'quantity_delivered' => array_sum(array_column($lines, 'quantity_delivered')),
                'items'              => $items,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_editing_delivery_engas_and_cost_updates_item_and_requisition_snapshots(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->makeItem($wh, 'Food Pack', 10, 100, null, 10);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Food Pack', 'quantity' => 20],
        ], 'RIS-PROP-1');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 20, 'unit_cost' => 10, 'engas_unit_cost' => 10,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-1-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-1')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();

        // A requisition that already dispatched this exact stock record.
        $admin = $this->admin();
        $req   = Requisition::create([
            'ris_number'     => 'RIS-PROP-REQ-1',
            'warehouse_id'   => null,
            'created_by'     => $admin->id,
            'purpose'        => 'Test propagation',
            'date_requested' => '2026-08-05',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id'     => $req->id,
            'item_id'            => $item->id,
            'description'        => 'Food Pack',
            'unit'               => 'piece',
            'quantity_requested' => 5,
            'quantity_issued'    => 5,
            'stock_available'    => true,
            'unit_cost'          => 10,
            'engas_unit_cost'    => 10,
        ]);
        RequisitionDispatchItem::create([
            'requisition_item_id' => $ri->id,
            'item_id'             => $item->id,
            'quantity_issued'     => 5,
            'unit_cost'           => 10,
            'engas_unit_cost'     => 10,
            'expiration_date'     => '2027-01-01',
            'dr_number'           => 'DR-PROP-1-A',
            'created_by'          => $admin->id,
        ]);

        // Edit: unit cost 10 → 12, ENGAS 10 → 15, same warehouse.
        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-PROP-1',
                'condition_status'   => 'good',
                'quantity_delivered' => 20,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => 20,
                    'unit_cost'          => 12,
                    'engas_unit_cost'    => 15,
                    'dr_number'          => 'DR-PROP-1-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $this->assertEquals(12, (float) $item->fresh()->unit_cost);
        $this->assertEquals(15, (float) $item->fresh()->engas_unit_cost);
        $this->assertEquals(12, (float) $di->fresh()->unit_cost);
        $this->assertEquals(15, (float) $di->fresh()->engas_unit_cost);

        $this->assertEquals(12, (float) $ri->fresh()->unit_cost);
        $this->assertEquals(15, (float) $ri->fresh()->engas_unit_cost);
        $this->assertEquals(12, (float) RequisitionDispatchItem::where('requisition_item_id', $ri->id)->value('unit_cost'));
        $this->assertEquals(15, (float) RequisitionDispatchItem::where('requisition_item_id', $ri->id)->value('engas_unit_cost'));

        // The edit is fully audited: changed line fields + cascade summary.
        $audit = \App\Models\DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->latest()->first();
        $this->assertNotNull($audit);
        $this->assertEquals('update', $audit->action);
        $this->assertArrayHasKey('items.0.unit_cost', $audit->changed_fields);
        $this->assertArrayHasKey('items.0.engas_unit_cost', $audit->changed_fields);
        $this->assertArrayNotHasKey('items.0.quantity_delivered', $audit->changed_fields);
        $this->assertArrayNotHasKey('quantity_delivered', $audit->changed_fields);
        $this->assertEquals(1, $audit->cascade_summary['requisition_rows'] ?? 0);
        $this->assertEquals(1, $audit->cascade_summary['requisition_dispatch_rows'] ?? 0);
    }

    public function test_editing_delivery_cost_cascades_through_transfer_chain(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse A', 'WHA');
        $wh2 = $this->makeWarehouse('Warehouse B', 'WHB');

        $source = $this->makeItem($wh1, 'Food Pack', 10, 100, null, 10);

        $ds = $this->createSubsidy([
            ['item_id' => $source->id, 'description' => 'Food Pack', 'quantity' => 20],
        ], 'RIS-PROP-2');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 20, 'unit_cost' => 10, 'engas_unit_cost' => 10,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-2-A',
        ]]);

        // Source was transferred to warehouse B (dest item + transfer row).
        $admin = $this->admin();
        $dest  = Item::findOrCreateByUnitCost(
            $wh2->id,
            'Food Pack',
            'piece',
            'food',
            10,
            $source->ris_number,
            '2027-01-01',
            10,
            '1040202000-01'
        );
        $transfer = StockTransfer::create([
            'transfer_number'   => StockTransfer::generateTransferNumber(),
            'from_warehouse_id' => $wh1->id,
            'to_warehouse_id'   => $wh2->id,
            'transfer_date'     => '2026-08-15',
            'transferred_by'    => $admin->id,
            'status'            => 'completed',
        ]);
        $sti = StockTransferItem::create([
            'stock_transfer_id'   => $transfer->id,
            'item_id'             => $source->id,
            'destination_item_id' => $dest->id,
            'quantity_requested'  => 5,
            'quantity'            => 5,
            'unit_cost'           => 10,
        ]);

        // A requisition that already dispatched the DESTINATION record.
        $req = Requisition::create([
            'ris_number'     => 'RIS-PROP-REQ-2',
            'warehouse_id'   => null,
            'created_by'     => $admin->id,
            'purpose'        => 'Test chain',
            'date_requested' => '2026-08-16',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id'     => $req->id,
            'item_id'            => $dest->id,
            'description'        => 'Food Pack',
            'unit'               => 'piece',
            'quantity_requested' => 3,
            'quantity_issued'    => 3,
            'stock_available'    => true,
            'unit_cost'          => 10,
            'engas_unit_cost'    => 10,
        ]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-2')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();

        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-PROP-2',
                'condition_status'   => 'good',
                'quantity_delivered' => 20,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 20,
                    'unit_cost'          => 20,
                    'engas_unit_cost'    => 25,
                    'dr_number'          => 'DR-PROP-2-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        // Source item updated by the controller.
        $this->assertEquals(20, (float) $source->fresh()->unit_cost);
        $this->assertEquals(25, (float) $source->fresh()->engas_unit_cost);

        // Transfer row + destination item + its requisition snapshot cascaded.
        $this->assertEquals(20, (float) $sti->fresh()->unit_cost);
        $this->assertEquals(20, (float) $dest->fresh()->unit_cost);
        $this->assertEquals(25, (float) $dest->fresh()->engas_unit_cost);
        $this->assertEquals(20, (float) $ri->fresh()->unit_cost);
        $this->assertEquals(25, (float) $ri->fresh()->engas_unit_cost);
    }

    public function test_editing_delivery_quantity_recalculates_stock_card_balances(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->makeItem($wh, 'Sugar', 40, 0);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Sugar', 'quantity' => 25],
        ], 'RIS-PROP-3');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-3', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 10, 'unit_cost' => 40, 'engas_unit_cost' => 40,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-3-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-3')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();

        // A later issuance on the same stock card, so running balances must be
        // recomputed when the earlier receipt is edited.
        StockCardEntry::create([
            'item_id'            => $item->id,
            'entry_date'         => '2026-08-12',
            'reference'          => 'RIS-LATER',
            'reference_type'     => 'issuance',
            'reference_id'       => 999,
            'receipt_qty'        => 0,
            'receipt_unit_cost'  => 40,
            'receipt_total_cost' => 0,
            'issue_qty'          => 4,
            'balance_qty'        => 6,
            'balance_unit_cost'  => 40,
            'balance_total_cost' => 240,
        ]);

        // Reduce the receipt from 10 → 6.
        $this->actingAs($this->admin())
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-PROP-3',
                'condition_status'   => 'good',
                'quantity_delivered' => 6,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => 6,
                    'unit_cost'          => 40,
                    'engas_unit_cost'    => 40,
                    'dr_number'          => 'DR-PROP-3-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $deliveryEntry = StockCardEntry::where('reference_type', 'delivery')
            ->where('reference_id', $delivery->id)
            ->firstOrFail();
        $this->assertEquals(6, (float) $deliveryEntry->balance_qty);

        $issuanceEntry = StockCardEntry::where('reference_type', 'issuance')
            ->where('reference_id', 999)
            ->firstOrFail();
        $this->assertEquals(2, (float) $issuanceEntry->balance_qty);
        $this->assertEquals(80, (float) $issuanceEntry->balance_total_cost);

        $this->assertEquals(6, (float) $item->fresh()->quantity);
    }

    public function test_deleting_requisition_recalculates_stock_card_balances(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->makeItem($wh, 'Rice', 50, 100);

        $admin = $this->admin();
        $req   = Requisition::create([
            'ris_number'     => 'RIS-PROP-REQ-3',
            'warehouse_id'   => null,
            'created_by'     => $admin->id,
            'purpose'        => 'Test delete recalc',
            'date_requested' => '2026-08-05',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id'     => $req->id,
            'item_id'            => $item->id,
            'description'        => 'Rice',
            'unit'               => 'piece',
            'quantity_requested' => 20,
            'quantity_issued'    => 10,
            'stock_available'    => true,
            'unit_cost'          => 50,
        ]);
        RequisitionDispatchItem::create([
            'requisition_item_id' => $ri->id,
            'item_id'             => $item->id,
            'quantity_issued'     => 10,
            'unit_cost'           => 50,
            'engas_unit_cost'     => 50,
            'expiration_date'     => '2027-01-01',
            'dr_number'           => 'DR-PROP-3-A',
            'created_by'          => $admin->id,
        ]);

        // Simulate the issuance that already happened: stock + opening entry.
        $item->decrement('quantity', 10);
        StockCardEntry::create([
            'item_id'            => $item->id,
            'entry_date'         => '2026-08-01',
            'reference'          => 'OPENING',
            'reference_type'     => 'opening',
            'reference_id'       => 0,
            'receipt_qty'        => 100,
            'receipt_unit_cost'  => 50,
            'receipt_total_cost' => 5000,
            'issue_qty'          => 0,
            'balance_qty'        => 100,
            'balance_unit_cost'  => 50,
            'balance_total_cost' => 5000,
        ]);
        StockCardEntry::create([
            'item_id'            => $item->id,
            'entry_date'         => '2026-08-10',
            'reference'          => $req->ris_number,
            'reference_type'     => 'issuance',
            'reference_id'       => $req->id,
            'receipt_qty'        => 0,
            'receipt_unit_cost'  => 50,
            'receipt_total_cost' => 0,
            'issue_qty'          => 10,
            'balance_qty'        => 90,
            'balance_unit_cost'  => 50,
            'balance_total_cost' => 4500,
        ]);
        StockCardEntry::create([
            'item_id'            => $item->id,
            'entry_date'         => '2026-08-11',
            'reference'          => 'DR-LATER',
            'reference_type'     => 'delivery',
            'reference_id'       => 888,
            'receipt_qty'        => 5,
            'receipt_unit_cost'  => 50,
            'receipt_total_cost' => 250,
            'issue_qty'          => 0,
            'balance_qty'        => 95,
            'balance_unit_cost'  => 50,
            'balance_total_cost' => 4750,
        ]);

        $this->actingAs($admin)
            ->delete(route('requisitions.destroy', $req))
            ->assertRedirect(route('requisitions.index'));

        // Stock returned, issuance rows gone, later balance recomputed to 105.
        $this->assertEquals(100, (float) $item->fresh()->quantity);
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'issuance')
            ->where('reference_id', $req->id)->count());

        $later = StockCardEntry::where('reference_type', 'delivery')
            ->where('reference_id', 888)
            ->firstOrFail();
        $this->assertEquals(105, (float) $later->balance_qty);
        $this->assertEquals(5250, (float) $later->balance_total_cost);
    }
}
