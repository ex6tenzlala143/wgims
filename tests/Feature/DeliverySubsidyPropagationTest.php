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
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        $ds = $this->createSubsidy([
            ['description' => 'Food Pack', 'quantity' => 20],
        ], 'RIS-PROP-1');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 20, 'unit_cost' => 10, 'engas_unit_cost' => 10,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-1-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-1')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        // Use the actual item created by the delivery
        $item     = \App\Models\Item::findOrFail($di->item_id);

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

        // requisition_items no longer stores cost (columns removed) — cost lives on dispatch items only
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
        // requisition_rows is 0 (no cost columns on requisition_items anymore)
        $this->assertEquals(0, $audit->cascade_summary['requisition_rows'] ?? 0);
        $this->assertEquals(1, $audit->cascade_summary['requisition_dispatch_rows'] ?? 0);
    }

    public function test_editing_delivery_engas_only_updates_dispatch_and_reservation_snapshots(): void
    {
        $wh = $this->makeWarehouse('Warehouse C', 'WHC');

        $ds = $this->createSubsidy([
            ['description' => 'Hygiene Kit', 'quantity' => 20],
        ], 'RIS-PROP-ENGAS');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-ENGAS', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 20, 'unit_cost' => 10, 'engas_unit_cost' => 10,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-ENGAS-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-ENGAS')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        $item     = \App\Models\Item::findOrFail($di->item_id);

        $admin = $this->admin();
        $req   = Requisition::create([
            'ris_number'     => 'RIS-PROP-ENGAS-REQ',
            'warehouse_id'   => null,
            'created_by'     => $admin->id,
            'purpose'        => 'Test engas-only propagation',
            'date_requested' => '2026-08-05',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id'     => $req->id,
            'item_id'            => $item->id,
            'description'        => 'Hygiene Kit',
            'unit'               => 'piece',
            'quantity_requested' => 5,
            'quantity_issued'    => 5,
            'stock_available'    => true,
        ]);
        RequisitionDispatchItem::create([
            'requisition_item_id' => $ri->id,
            'item_id'             => $item->id,
            'quantity_issued'     => 5,
            'unit_cost'           => 10,
            'engas_unit_cost'     => 10,
            'expiration_date'     => '2027-01-01',
            'dr_number'           => 'DR-PROP-ENGAS-A',
            'created_by'          => $admin->id,
        ]);

        $reservation = \App\Models\Reservation::create([
            'reservation_number' => 'RES-PROP-ENGAS',
            'warehouse_id'       => $wh->id,
            'item_id'            => $item->id,
            'reserved_quantity'  => 3,
            'allocated_quantity' => 0,
            'status'             => 'RESERVED',
            'purpose'            => 'Test reservation engas cascade',
            'created_by'         => $admin->id,
        ]);
        \App\Models\ReservationItem::create([
            'reservation_id'    => $reservation->id,
            'item_id'           => $item->id,
            'warehouse_id'      => $wh->id,
            'reserved_quantity' => 3,
            'deployed_quantity' => 0,
            'status'            => \App\Models\ReservationItem::STATUS_ACTIVE,
            'unit_cost'         => 10,
            'engas_unit_cost'   => 10,
        ]);

        // Edit: ENGAS 10 → 15 ONLY (quantity and unit cost unchanged).
        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-PROP-ENGAS',
                'condition_status'   => 'good',
                'quantity_delivered' => 20,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => 20,
                    'unit_cost'          => 10,
                    'engas_unit_cost'    => 15,
                    'dr_number'          => 'DR-PROP-ENGAS-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $this->assertEquals(10, (float) $item->fresh()->unit_cost);
        $this->assertEquals(15, (float) $item->fresh()->engas_unit_cost);
        $this->assertEquals(15, (float) $di->fresh()->engas_unit_cost);

        // Connected requisition dispatch row follows the corrected ENGAS.
        $this->assertEquals(10, (float) RequisitionDispatchItem::where('requisition_item_id', $ri->id)->value('unit_cost'));
        $this->assertEquals(15, (float) RequisitionDispatchItem::where('requisition_item_id', $ri->id)->value('engas_unit_cost'));

        // Connected reservation snapshot follows the corrected ENGAS.
        $this->assertEquals(15, (float) \App\Models\ReservationItem::where('reservation_id', $reservation->id)->value('engas_unit_cost'));

        $audit = \App\Models\DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->latest()->first();
        $this->assertNotNull($audit);
        $this->assertArrayHasKey('items.0.engas_unit_cost', $audit->changed_fields);
        $this->assertEquals(1, $audit->cascade_summary['requisition_dispatch_rows'] ?? 0);
        $this->assertEquals(1, $audit->cascade_summary['reservation_rows'] ?? 0);
    }

    public function test_editing_delivery_cost_cascades_through_transfer_chain(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse A', 'WHA');
        $wh2 = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([
            ['description' => 'Food Pack', 'quantity' => 20],
        ], 'RIS-PROP-2');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
            'quantity_delivered' => 20, 'unit_cost' => 10, 'engas_unit_cost' => 10,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-2-A',
        ]]);

        // Resolve the actual source item created by the delivery
        $delivery = Delivery::where('dr_number', 'DR-PROP-2')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        $source   = \App\Models\Item::findOrFail($di->item_id);

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
            '1040202000-01',
            $source->source_subsidy_id
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

        // Transfer row + destination item cascaded.
        $this->assertEquals(20, (float) $sti->fresh()->unit_cost);
        $this->assertEquals(20, (float) $dest->fresh()->unit_cost);
        $this->assertEquals(25, (float) $dest->fresh()->engas_unit_cost);
        // requisition_items no longer stores cost — check dispatch items only
        $dispatch = \App\Models\RequisitionDispatchItem::where('requisition_item_id', $ri->id)->first();
        if ($dispatch) {
            $this->assertEquals(20, (float) $dispatch->unit_cost);
            $this->assertEquals(25, (float) $dispatch->engas_unit_cost);
        }
    }

    public function test_editing_delivery_quantity_recalculates_stock_card_balances(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        $ds = $this->createSubsidy([
            ['description' => 'Sugar', 'quantity' => 25],
        ], 'RIS-PROP-3');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-3', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 10, 'unit_cost' => 40, 'engas_unit_cost' => 40,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-3-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-3')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        // Use the actual item created by the delivery (subsidy-linked)
        $item     = \App\Models\Item::findOrFail($di->item_id);

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

    public function test_engas_edit_with_no_transactions_updates_item_only(): void
    {
        $wh = $this->makeWarehouse('Warehouse N', 'WHN');

        $ds = $this->createSubsidy([
            ['description' => 'Sardines', 'quantity' => 50],
        ], 'RIS-PROP-NOTX');
        $codeBefore = $ds->subsidy_code;

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-PROP-NOTX', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 50, 'unit_cost' => 30, 'engas_unit_cost' => 32,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-PROP-NOTX-A',
        ]]);

        $delivery = Delivery::where('dr_number', 'DR-PROP-NOTX')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        $item     = \App\Models\Item::findOrFail($di->item_id);

        $admin = $this->admin();
        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-PROP-NOTX',
                'condition_status'   => 'good',
                'quantity_delivered' => 50,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh->id,
                    'quantity_delivered' => 50,
                    'unit_cost'          => 30,
                    'engas_unit_cost'    => 28,
                    'dr_number'          => 'DR-PROP-NOTX-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        // Stock record + shipment row follow the edit; identity untouched.
        $this->assertEquals(32 - 4, (float) $item->fresh()->engas_unit_cost);
        $this->assertEquals(30, (float) $item->fresh()->unit_cost);
        $this->assertEquals(28, (float) $di->fresh()->engas_unit_cost);
        $this->assertEquals(50 * 28, (float) $di->fresh()->engas_total_cost);
        $this->assertEquals(50, (float) $item->fresh()->quantity);
        $this->assertEquals($codeBefore, $ds->fresh()->subsidy_code);
        $this->assertEquals('RIS-PROP-NOTX', $ds->fresh()->ris_number);
        $this->assertEquals(0, RequisitionDispatchItem::where('item_id', $item->id)->count());
    }

    public function test_engas_edit_syncs_exact_subsidy_lineage_only(): void
    {
        // Three subsidies, SAME item name, DIFFERENT engas (980 / 880 / 900).
        $wh1 = $this->makeWarehouse('Warehouse L1', 'WHL1');
        $wh2 = $this->makeWarehouse('Warehouse L2', 'WHL2');
        $admin = $this->admin();

        $setup = [];
        foreach ([
            ['ris' => 'RIS-ISO-A', 'dr' => 'DR-ISO-A', 'cost' => 100, 'engas' => 980],
            ['ris' => 'RIS-ISO-B', 'dr' => 'DR-ISO-B', 'cost' => 90, 'engas' => 880],
            ['ris' => 'RIS-ISO-C', 'dr' => 'DR-ISO-C', 'cost' => 95, 'engas' => 900],
        ] as $s) {
            $ds = $this->createSubsidy([
                ['description' => 'Family Food Pack', 'quantity' => 100],
            ], $s['ris']);
            $line = $ds->items()->firstOrFail();
            $this->dispatch($ds, $s['dr'], [[
                'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
                'quantity_delivered' => 100, 'unit_cost' => $s['cost'], 'engas_unit_cost' => $s['engas'],
                'expiration_date' => '2027-01-01', 'dr_number' => $s['dr'].'-A',
            ]]);
            $delivery = Delivery::where('dr_number', $s['dr'])->firstOrFail();
            $di       = $delivery->items()->firstOrFail();
            $setup[$s['ris']] = ['ds' => $ds, 'delivery' => $delivery, 'di' => $di,
                'item' => \App\Models\Item::findOrFail($di->item_id)];
        }

        $srcA = $setup['RIS-ISO-A']['item'];

        // Augmentation (transfer): 20 units of A's stock moved to warehouse 2.
        $destA = Item::findOrCreateByUnitCost(
            $wh2->id, 'Family Food Pack', 'piece', 'food', 100,
            $srcA->ris_number, '2027-01-01', 980, '1040202000-01', $srcA->source_subsidy_id
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
            'item_id'             => $srcA->id,
            'destination_item_id' => $destA->id,
            'quantity_requested'  => 20,
            'quantity'            => 20,
            'unit_cost'           => 100,
        ]);

        // RIS dispatches: 10 from A's source record, 5 from A's dest record,
        // 10 from B's record (must stay 880).
        $mkDispatch = function (string $risNo, Item $stock, int $qty) use ($admin) {
            $req = Requisition::create([
                'ris_number' => $risNo, 'warehouse_id' => null, 'created_by' => $admin->id,
                'purpose' => 'Test isolation', 'date_requested' => '2026-08-16',
            ]);
            $ri = RequisitionItem::create([
                'requisition_id' => $req->id, 'item_id' => $stock->id,
                'description' => 'Family Food Pack', 'unit' => 'piece',
                'quantity_requested' => $qty, 'quantity_issued' => $qty, 'stock_available' => true,
            ]);
            return RequisitionDispatchItem::create([
                'requisition_item_id' => $ri->id, 'item_id' => $stock->id,
                'quantity_issued' => $qty, 'unit_cost' => $stock->unit_cost,
                'engas_unit_cost' => $stock->engas_unit_cost,
                'expiration_date' => '2027-01-01', 'dr_number' => 'DR-ISO-X', 'created_by' => $admin->id,
            ]);
        };
        $dispSrcA  = $mkDispatch('RIS-ISO-REQ-A1', $srcA, 10);
        $dispDestA = $mkDispatch('RIS-ISO-REQ-A2', $destA, 5);
        $dispB     = $mkDispatch('RIS-ISO-REQ-B', $setup['RIS-ISO-B']['item'], 10);

        // Reservation locking 7 units of A's source record.
        $reservation = \App\Models\Reservation::create([
            'reservation_number' => 'RES-ISO-A', 'warehouse_id' => $wh1->id,
            'item_id' => $srcA->id, 'reserved_quantity' => 7, 'allocated_quantity' => 0,
            'status' => 'RESERVED', 'purpose' => 'Test isolation', 'created_by' => $admin->id,
        ]);
        $resItemA = \App\Models\ReservationItem::create([
            'reservation_id' => $reservation->id, 'item_id' => $srcA->id,
            'warehouse_id' => $wh1->id, 'reserved_quantity' => 7, 'deployed_quantity' => 0,
            'status' => \App\Models\ReservationItem::STATUS_ACTIVE,
            'unit_cost' => 100, 'engas_unit_cost' => 980,
        ]);

        $codesBefore = [
            'A' => $setup['RIS-ISO-A']['ds']->subsidy_code,
            'B' => $setup['RIS-ISO-B']['ds']->subsidy_code,
            'C' => $setup['RIS-ISO-C']['ds']->subsidy_code,
        ];

        // Edit ONLY A's shipment: ENGAS 980 → 950 (qty + unit cost unchanged).
        $dsA = $setup['RIS-ISO-A']['ds'];
        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$dsA, $setup['RIS-ISO-A']['delivery']]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-ISO-A',
                'condition_status'   => 'good',
                'quantity_delivered' => 100,
                'items'              => [[
                    'di_id'              => $setup['RIS-ISO-A']['di']->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 100,
                    'unit_cost'          => 100,
                    'engas_unit_cost'    => 950,
                    'dr_number'          => 'DR-ISO-A-A',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $dsA));

        // ── A's lineage follows the edit ──
        $this->assertEquals(950, (float) $srcA->fresh()->engas_unit_cost);
        $this->assertEquals(100 * 950, (float) $setup['RIS-ISO-A']['di']->fresh()->engas_total_cost);
        $this->assertEquals(950, (float) $dispSrcA->fresh()->engas_unit_cost);
        $this->assertEquals(950, (float) $destA->fresh()->engas_unit_cost);
        $this->assertEquals(950, (float) $dispDestA->fresh()->engas_unit_cost);
        $this->assertEquals(950, (float) $resItemA->fresh()->engas_unit_cost);

        // ── B and C lineages are untouched ──
        $this->assertEquals(880, (float) $setup['RIS-ISO-B']['item']->fresh()->engas_unit_cost);
        $this->assertEquals(880, (float) $setup['RIS-ISO-B']['di']->fresh()->engas_unit_cost);
        $this->assertEquals(880, (float) $dispB->fresh()->engas_unit_cost);
        $this->assertEquals(900, (float) $setup['RIS-ISO-C']['item']->fresh()->engas_unit_cost);
        $this->assertEquals(900, (float) $setup['RIS-ISO-C']['di']->fresh()->engas_unit_cost);

        // ── Quantities everywhere are unchanged ──
        $this->assertEquals(100, (float) $srcA->fresh()->quantity);
        $this->assertEquals(100, (float) $setup['RIS-ISO-A']['di']->fresh()->quantity_delivered);
        $this->assertEquals(100, (float) $setup['RIS-ISO-A']['ds']->fresh()->quantity_requested);
        $this->assertEquals(10, (float) $dispSrcA->fresh()->quantity_issued);
        $this->assertEquals(5, (float) $dispDestA->fresh()->quantity_issued);
        $this->assertEquals(20, (float) $sti->fresh()->quantity);
        $this->assertEquals(7, (float) $resItemA->fresh()->reserved_quantity);

        // ── Identities unchanged: subsidy codes + RIS numbers ──
        $this->assertEquals($codesBefore['A'], $setup['RIS-ISO-A']['ds']->fresh()->subsidy_code);
        $this->assertEquals($codesBefore['B'], $setup['RIS-ISO-B']['ds']->fresh()->subsidy_code);
        $this->assertEquals($codesBefore['C'], $setup['RIS-ISO-C']['ds']->fresh()->subsidy_code);
        $this->assertEquals('RIS-ISO-A', $setup['RIS-ISO-A']['ds']->fresh()->ris_number);

        // ── Report sources carry the corrected cost ──
        // RSMI reads per-dispatch engas; RPCI/Inventory Balance read the item.
        $this->assertEquals(950 * 10, $dispSrcA->fresh()->quantity_issued * $dispSrcA->fresh()->engas_unit_cost);
        $this->assertEquals(950, (float) $srcA->fresh()->engas_unit_cost);

        $audit = \App\Models\DeliverySubsidyAuditLog::where('delivery_subsidy_id', $dsA->id)->latest()->first();
        $this->assertNotNull($audit);
        $this->assertArrayHasKey('items.0.engas_unit_cost', $audit->changed_fields);
    }

    public function test_stock_number_rename_syncs_exact_lineage_only(): void
    {
        // Stock numbers live ONLY on items; downstream rows read them live
        // via item_id — renaming the exact record syncs the whole lineage.
        $wh1 = $this->makeWarehouse('Warehouse S1', 'WHS1');
        $wh2 = $this->makeWarehouse('Warehouse S2', 'WHS2');
        $admin = $this->admin();

        $setup = [];
        foreach ([
            ['ris' => 'RIS-SN-A', 'dr' => 'DR-SN-A', 'stock' => 'FFP-001'],
            ['ris' => 'RIS-SN-B', 'dr' => 'DR-SN-B', 'stock' => 'FFP-002'],
        ] as $s) {
            $ds = $this->createSubsidy([
                ['description' => 'Family Food Pack', 'quantity' => 100],
            ], $s['ris']);
            $line = $ds->items()->firstOrFail();
            $this->dispatch($ds, $s['dr'], [[
                'ds_item_id' => $line->id, 'warehouse_id' => $wh1->id,
                'quantity_delivered' => 100, 'unit_cost' => 50, 'engas_unit_cost' => 55,
                'expiration_date' => '2027-01-01', 'dr_number' => $s['dr'].'-A',
            ]]);
            $delivery = Delivery::where('dr_number', $s['dr'])->firstOrFail();
            $di       = $delivery->items()->firstOrFail();
            $item     = \App\Models\Item::findOrFail($di->item_id);
            $item->update(['stock_number' => $s['stock']]);
            $setup[$s['ris']] = ['ds' => $ds, 'delivery' => $delivery, 'di' => $di, 'item' => $item];
        }

        $srcA = $setup['RIS-SN-A']['item'];

        // Augmentation (transfer) of A's stock + dispatches + reservation.
        $destA = Item::findOrCreateByUnitCost(
            $wh2->id, 'Family Food Pack', 'piece', 'food', 50,
            $srcA->ris_number, '2027-01-01', 55, '1040202000-01', $srcA->source_subsidy_id
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
            'item_id'             => $srcA->id,
            'destination_item_id' => $destA->id,
            'quantity_requested'  => 20,
            'quantity'            => 20,
            'unit_cost'           => 50,
        ]);

        $mkDispatch = function (string $risNo, Item $stock, int $qty) use ($admin) {
            $req = Requisition::create([
                'ris_number' => $risNo, 'warehouse_id' => null, 'created_by' => $admin->id,
                'purpose' => 'Test stock rename', 'date_requested' => '2026-08-16',
            ]);
            $ri = RequisitionItem::create([
                'requisition_id' => $req->id, 'item_id' => $stock->id,
                'description' => 'Family Food Pack', 'unit' => 'piece',
                'quantity_requested' => $qty, 'quantity_issued' => $qty, 'stock_available' => true,
            ]);
            return RequisitionDispatchItem::create([
                'requisition_item_id' => $ri->id, 'item_id' => $stock->id,
                'quantity_issued' => $qty, 'unit_cost' => 50,
                'engas_unit_cost' => 55,
                'expiration_date' => '2027-01-01', 'dr_number' => 'DR-SN-X', 'created_by' => $admin->id,
            ]);
        };
        $dispSrcA  = $mkDispatch('RIS-SN-REQ-A1', $srcA, 10);
        $dispDestA = $mkDispatch('RIS-SN-REQ-A2', $destA, 5);
        $dispB     = $mkDispatch('RIS-SN-REQ-B', $setup['RIS-SN-B']['item'], 10);

        $resItemA = \App\Models\ReservationItem::create([
            'reservation_id' => \App\Models\Reservation::create([
                'reservation_number' => 'RES-SN-A', 'warehouse_id' => $wh1->id,
                'item_id' => $srcA->id, 'reserved_quantity' => 7, 'allocated_quantity' => 0,
                'status' => 'RESERVED', 'purpose' => 'Test stock rename', 'created_by' => $admin->id,
            ])->id,
            'item_id' => $srcA->id, 'warehouse_id' => $wh1->id,
            'reserved_quantity' => 7, 'deployed_quantity' => 0,
            'status' => \App\Models\ReservationItem::STATUS_ACTIVE,
            'unit_cost' => 50, 'engas_unit_cost' => 55,
        ]);
        $cardA = StockCardEntry::where('reference_type', 'delivery')
            ->where('reference_id', $setup['RIS-SN-A']['delivery']->id)
            ->firstOrFail();

        $codeA = $setup['RIS-SN-A']['ds']->subsidy_code;

        // Rename ONLY A's stock record: FFP-001 → FFP-010.
        $dsA = $setup['RIS-SN-A']['ds'];
        $payload = [
            'delivery_date' => '2026-08-10', 'dr_number' => 'DR-SN-A',
            'condition_status' => 'good', 'quantity_delivered' => 100,
            'items' => [[
                'di_id' => $setup['RIS-SN-A']['di']->id, 'warehouse_id' => $wh1->id,
                'quantity_delivered' => 100, 'unit_cost' => 50, 'engas_unit_cost' => 55,
                'dr_number' => 'DR-SN-A-A', 'stock_number' => 'FFP-010',
            ]],
        ];
        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$dsA, $setup['RIS-SN-A']['delivery']]), $payload)
            ->assertRedirect(route('delivery_subsidies.show', $dsA));

        // ── A's lineage displays the new number via live relations ──
        $this->assertEquals('FFP-010', $srcA->fresh()->stock_number);
        $this->assertEquals('FFP-010', $setup['RIS-SN-A']['di']->fresh()->item->stock_number);
        $this->assertEquals('FFP-010', $dispSrcA->fresh()->item->stock_number);
        $this->assertEquals('FFP-010', $sti->fresh()->sourceItem->stock_number);
        $this->assertEquals('FFP-010', $resItemA->fresh()->item->stock_number);
        $this->assertEquals('FFP-010', $cardA->fresh()->item->stock_number);
        // Destination keeps its own number (different warehouse record).
        $this->assertNotEquals('FFP-010', $destA->fresh()->stock_number);
        $this->assertEquals($destA->fresh()->stock_number, $dispDestA->fresh()->item->stock_number);

        // ── B is untouched ──
        $this->assertEquals('FFP-002', $setup['RIS-SN-B']['item']->fresh()->stock_number);
        $this->assertEquals('FFP-002', $dispB->fresh()->item->stock_number);

        // ── Quantities + identities unchanged ──
        $this->assertEquals(100, (float) $srcA->fresh()->quantity);
        $this->assertEquals(100, (float) $setup['RIS-SN-A']['di']->fresh()->quantity_delivered);
        $this->assertEquals(10, (float) $dispSrcA->fresh()->quantity_issued);
        $this->assertEquals($codeA, $dsA->fresh()->subsidy_code);
        $this->assertEquals('RIS-SN-A', $dsA->fresh()->ris_number);

        $audit = \App\Models\DeliverySubsidyAuditLog::where('delivery_subsidy_id', $dsA->id)->latest()->first();
        $this->assertNotNull($audit);
        $this->assertEquals(['old' => 'FFP-001', 'new' => 'FFP-010'], $audit->changed_fields['items.0.stock_number']);
    }

    public function test_stock_number_rename_combined_with_engas(): void
    {
        $wh = $this->makeWarehouse('Warehouse SC', 'WHSC');
        $admin = $this->admin();

        $ds = $this->createSubsidy([['description' => 'Rice Pack', 'quantity' => 40]], 'RIS-SN-COMB');
        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-SN-COMB', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 40, 'unit_cost' => 20, 'engas_unit_cost' => 22,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-SN-COMB-A',
        ]]);
        $delivery = Delivery::where('dr_number', 'DR-SN-COMB')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();
        $item     = \App\Models\Item::findOrFail($di->item_id);
        $item->update(['stock_number' => 'RP-001']);

        $req = Requisition::create([
            'ris_number' => 'RIS-SN-COMB-REQ', 'warehouse_id' => null,
            'created_by' => $admin->id, 'purpose' => 'Test combo', 'date_requested' => '2026-08-16',
        ]);
        $ri = RequisitionItem::create([
            'requisition_id' => $req->id, 'item_id' => $item->id,
            'description' => 'Rice Pack', 'unit' => 'piece',
            'quantity_requested' => 6, 'quantity_issued' => 6, 'stock_available' => true,
        ]);
        $dispatch = RequisitionDispatchItem::create([
            'requisition_item_id' => $ri->id, 'item_id' => $item->id,
            'quantity_issued' => 6, 'unit_cost' => 20, 'engas_unit_cost' => 22,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-SN-COMB-A', 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date' => '2026-08-10', 'dr_number' => 'DR-SN-COMB',
                'condition_status' => 'good', 'quantity_delivered' => 40,
                'items' => [[
                    'di_id' => $di->id, 'warehouse_id' => $wh->id,
                    'quantity_delivered' => 40, 'unit_cost' => 20, 'engas_unit_cost' => 18,
                    'dr_number' => 'DR-SN-COMB-A', 'stock_number' => 'RP-010',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $this->assertEquals('RP-010', $item->fresh()->stock_number);
        $this->assertEquals(18, (float) $item->fresh()->engas_unit_cost);
        $this->assertEquals('RP-010', $dispatch->fresh()->item->stock_number);
        $this->assertEquals(18, (float) $dispatch->fresh()->engas_unit_cost);
        $this->assertEquals(40 * 18, (float) $di->fresh()->engas_total_cost);
        $this->assertEquals(40, (float) $item->fresh()->quantity);
    }

    public function test_stock_number_rename_rejects_duplicates(): void
    {
        $wh = $this->makeWarehouse('Warehouse SD', 'WHSD');
        $admin = $this->admin();

        foreach ([
            ['ris' => 'RIS-SN-D1', 'dr' => 'DR-SN-D1', 'stock' => 'DUP-001'],
            ['ris' => 'RIS-SN-D2', 'dr' => 'DR-SN-D2', 'stock' => 'DUP-002'],
        ] as $s) {
            $ds = $this->createSubsidy([['description' => 'Beans', 'quantity' => 10]], $s['ris']);
            $line = $ds->items()->firstOrFail();
            $this->dispatch($ds, $s['dr'], [[
                'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
                'quantity_delivered' => 10, 'unit_cost' => 5, 'engas_unit_cost' => 5,
                'expiration_date' => '2027-01-01', 'dr_number' => $s['dr'].'-A',
            ]]);
            $di = Delivery::where('dr_number', $s['dr'])->firstOrFail()->items()->firstOrFail();
            \App\Models\Item::findOrFail($di->item_id)->update(['stock_number' => $s['stock']]);
            $setup[$s['ris']] = ['ds' => $ds, 'di' => $di];
        }

        $this->actingAs($admin)
            ->put(route(
                'delivery_subsidies.update_delivery',
                [$setup['RIS-SN-D1']['ds'], $setup['RIS-SN-D1']['di']->delivery]
            ), [
                'delivery_date' => '2026-08-10', 'dr_number' => 'DR-SN-D1',
                'condition_status' => 'good', 'quantity_delivered' => 10,
                'items' => [[
                    'di_id' => $setup['RIS-SN-D1']['di']->id, 'warehouse_id' => $wh->id,
                    'quantity_delivered' => 10, 'unit_cost' => 5, 'engas_unit_cost' => 5,
                    'dr_number' => 'DR-SN-D1-A', 'stock_number' => 'DUP-002',
                ]],
            ])
            ->assertSessionHasErrors('items.0.stock_number');

        $this->assertEquals('DUP-001', $setup['RIS-SN-D1']['di']->fresh()->item->stock_number);
        $this->assertEquals('DUP-002', $setup['RIS-SN-D2']['di']->fresh()->item->stock_number);
    }
}
