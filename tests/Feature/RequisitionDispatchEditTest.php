<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionDispatchEditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'de_admin_' . $i,
            'name'     => 'DE Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function staff(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'de_staff_' . $i,
            'name'     => 'DE Staff ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_STAFF,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    /**
     * Creates an item AND its starting receipt stock-card entry, exactly like a
     * delivery would, so running balances are always consistent.
     */
    private function stockItem(Warehouse $wh, string $description, float $qty, float $cost, int $day = 1): Item
    {
        $item = Item::create([
            'stock_number' => null,
            'description'  => $description,
            'unit'         => 'piece',
            'category'     => 'food',
            'account_code' => '1040202000-01',
            'warehouse_id' => $wh->id,
            'unit_cost'    => $cost,
            'quantity'     => $qty,
            'is_active'    => true,
        ]);

        StockCardEntry::create([
            'item_id'            => $item->id,
            'entry_date'         => '2026-08-0' . $day,
            'reference'          => 'DR-RECEIPT-' . $item->id,
            'reference_type'     => 'delivery',
            'reference_id'       => '1',
            'receipt_qty'        => $qty,
            'receipt_unit_cost'  => $cost,
            'receipt_total_cost' => $qty * $cost,
            'issue_qty'          => 0,
            'balance_qty'        => $qty,
            'balance_unit_cost'  => $cost,
            'balance_total_cost' => $qty * $cost,
            'from_to'            => 'Supplier',
        ]);

        return $item;
    }

    private function makeCatalogItem(string $name): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'cat_' . mb_strtolower(str_replace(' ', '_', $name))],
            ['label' => $name, 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
        );

        return ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name'             => $name,
            'account_code'     => '50101010',
            'is_active'        => true,
        ]);
    }

    private function createRis(int $catalogItemId, float $qty): Requisition
    {
        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'ris_number'     => 'RIS-EDIT-' . strtoupper(\Illuminate\Support\Str::random(6)) . '-' . time() . rand(100,999),
                'purpose'        => 'Edit dispatch test',
                'date_requested' => '2026-08-01',
                'items'          => [
                    ['catalog_item_id' => $catalogItemId, 'quantity_requested' => $qty],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        return Requisition::latest('id')->firstOrFail();
    }

    private function dispatch(Requisition $ris, int $itemId, float $qty, string $dr): RequisitionDispatchItem
    {
        $line = $ris->items()->firstOrFail();
        $item = Item::findOrFail($itemId);

        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => [
                        'warehouse_id'    => $item->warehouse_id,
                        'item_id'         => $item->id,
                        'quantity_issued' => $qty,
                        'dr_number'       => $dr,
                        'unit_cost'       => $item->unit_cost,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        return $line->dispatchItems()->firstOrFail();
    }

    private function editDispatch(RequisitionDispatchItem $dispatch, array $overrides)
    {
        $defaults = [
            'warehouse_id'    => $dispatch->item->warehouse_id,
            'item_id'         => $dispatch->item_id,
            'quantity_issued' => $dispatch->quantity_issued,
            'unit_cost'       => $dispatch->unit_cost,
            'engas_unit_cost' => $dispatch->engas_unit_cost,
            'expiration_date' => $dispatch->expiration_date?->format('Y-m-d'),
            'dr_number'       => $dispatch->dr_number,
        ];

        return $this->actingAs($this->admin())
            ->putJson(route('requisitions.dispatch_update', $dispatch->id), array_merge($defaults, $overrides));
    }

    public function test_increasing_quantity_adds_only_the_delta_to_the_same_record(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris   = $this->createRis($catalog->id, 60);
        $line  = $ris->items()->firstOrFail();
        $disp  = $this->dispatch($ris, $item->id, 30, 'DR-ED-1');

        $this->assertEquals(70, (float) $item->fresh()->quantity);

        // 30 → 50: only +20 is taken, on top of the 30 that was returned
        $this->editDispatch($disp, ['quantity_issued' => 50])
            ->assertOk()
            ->assertJsonPath('redirect', route('requisitions.show', $ris->id));

        $this->assertEquals(50, (float) $item->fresh()->quantity);
        $this->assertEquals(50, (float) $line->fresh()->quantity_issued);

        $entry = StockCardEntry::where('dispatch_item_id', $disp->id)->firstOrFail();
        $this->assertEquals(50, (float) $entry->issue_qty);
        $this->assertEquals(50, (float) $entry->balance_qty);   // 100 - 50
        $this->assertEquals(35000, (float) $entry->balance_total_cost); // 50 × ₱700
        $this->assertSame(1, StockCardEntry::where('item_id', $item->id)->where('reference_type', 'issuance')->count());
    }

    public function test_reducing_quantity_returns_the_removed_stock(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 60);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 30, 'DR-ED-2');

        $this->assertEquals(70, (float) $item->fresh()->quantity);

        // 30 → 10: the removed 20 goes back to the same record
        $this->editDispatch($disp, ['quantity_issued' => 10])->assertOk();

        $this->assertEquals(90, (float) $item->fresh()->quantity);
        $this->assertEquals(10, (float) $line->fresh()->quantity_issued);

        $entry = StockCardEntry::where('dispatch_item_id', $disp->id)->firstOrFail();
        $this->assertEquals(10, (float) $entry->issue_qty);
        $this->assertEquals(90, (float) $entry->balance_qty);
    }

    public function test_changing_warehouse_reverses_old_and_deducts_new(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');
        $aItem = $this->stockItem($whA, 'Food Pack', 100, 700, 1);
        $bItem = $this->stockItem($whB, 'Food Pack', 100, 800, 2);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 80);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $aItem->id, 30, 'DR-ED-3');

        $this->assertEquals(70, (float) $aItem->fresh()->quantity);

        // Move the whole dispatch to Warehouse B with a bigger quantity
        $this->editDispatch($disp, [
            'warehouse_id'    => $whB->id,
            'item_id'         => $bItem->id,
            'quantity_issued' => 40,
            'unit_cost'       => 800,
        ])->assertOk();

        // Old record gets the 30 back; new record loses exactly 40
        $this->assertEquals(100, (float) $aItem->fresh()->quantity);
        $this->assertEquals(60, (float) $bItem->fresh()->quantity);
        $this->assertEquals(40, (float) $line->fresh()->quantity_issued);

        // The dispatch now points at the new record
        $fresh = $disp->fresh();
        $this->assertSame($bItem->id, (int) $fresh->item_id);
        $this->assertEquals(40, (float) $fresh->quantity_issued);

        // Stock-card entries moved too — one issuance per item, never two
        $this->assertEquals(0, StockCardEntry::where('item_id', $aItem->id)->where('reference_type', 'issuance')->count());
        $bEntry = StockCardEntry::where('item_id', $bItem->id)->where('reference_type', 'issuance')->firstOrFail();
        $this->assertEquals(40, (float) $bEntry->issue_qty);
        $this->assertEquals(60, (float) $bEntry->balance_qty);
        $this->assertSame($fresh->id, (int) $bEntry->dispatch_item_id);
    }

    public function test_changing_unit_cost_record_uses_the_exact_new_record(): void
    {
        $wh    = $this->makeWarehouse('Warehouse A', 'WHA');
        $cheap  = $this->stockItem($wh, 'Food Pack', 50, 700, 1);
        $costly = $this->stockItem($wh, 'Food Pack', 30, 800, 2);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $costly->id, 10, 'DR-ED-4');

        $this->assertEquals(20, (float) $costly->fresh()->quantity);

        // Switch from the ₱800 record to the ₱700 record — no FIFO fallback
        $this->editDispatch($disp, [
            'item_id'         => $cheap->id,
            'quantity_issued' => 10,
            'unit_cost'       => 700,
        ])->assertOk();

        $this->assertEquals(40, (float) $cheap->fresh()->quantity);
        $this->assertEquals(30, (float) $costly->fresh()->quantity);

        $fresh = $disp->fresh();
        $this->assertSame($cheap->id, (int) $fresh->item_id);
        $this->assertEquals(700, (float) $fresh->unit_cost);

        $this->assertEquals(0, StockCardEntry::where('item_id', $costly->id)->where('reference_type', 'issuance')->count());
        $cheapEntry = StockCardEntry::where('item_id', $cheap->id)->where('reference_type', 'issuance')->firstOrFail();
        $this->assertEquals(10, (float) $cheapEntry->issue_qty);
        $this->assertEquals(40, (float) $cheapEntry->balance_qty);
    }

    public function test_editing_one_dispatch_does_not_touch_another(): void
    {
        $wh    = $this->makeWarehouse('Warehouse A', 'WHA');
        $item  = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 50);
        $line = $ris->items()->firstOrFail();

        // Dispatch 1: 20  →  Dispatch 2: 10 (same line, same record)
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 20, 'dr_number' => 'DR-ED-5A', 'unit_cost' => 700],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 10, 'dr_number' => 'DR-ED-5B', 'unit_cost' => 700],
                ],
            ])
            ->assertSessionHasNoErrors();

        $dispatches = $line->dispatchItems()->orderBy('id')->get();
        $this->assertCount(2, $dispatches);
        $this->assertEquals(70, (float) $item->fresh()->quantity);

        // Edit ONLY the first dispatch: 20 → 25
        $this->editDispatch($dispatches[0], ['quantity_issued' => 25])->assertOk();

        $this->assertEquals(65, (float) $item->fresh()->quantity);
        $this->assertEquals(35, (float) $line->fresh()->quantity_issued);

        $d0 = $dispatches[0]->fresh();
        $d1 = $dispatches[1]->fresh();
        $this->assertEquals(25, (float) $d0->quantity_issued);
        $this->assertEquals(10, (float) $d1->quantity_issued);
        $this->assertSame('DR-ED-5B', $d1->dr_number);
        $this->assertEquals(700, (float) $d1->unit_cost);

        // Two independent issuance entries — each linked to its own dispatch
        $entries = StockCardEntry::where('item_id', $item->id)->where('reference_type', 'issuance')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(25, (float) $entries[0]->issue_qty);
        $this->assertEquals(10, (float) $entries[1]->issue_qty);
        $this->assertEquals(65, (float) $entries[1]->balance_qty);
    }

    public function test_edit_is_rejected_when_it_exceeds_the_outstanding_quantity(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 8, 'DR-ED-6');

        // Outstanding is only 2; raising the dispatch to 11 exceeds the request
        $this->editDispatch($disp, ['quantity_issued' => 11])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity_issued');

        // Nothing moved — stock, line, dispatch and entries are untouched
        $this->assertEquals(92, (float) $item->fresh()->quantity);
        $this->assertEquals(8, (float) $line->fresh()->quantity_issued);
        $this->assertEquals(8, (float) $disp->fresh()->quantity_issued);
        $entry = StockCardEntry::where('dispatch_item_id', $disp->id)->firstOrFail();
        $this->assertEquals(8, (float) $entry->issue_qty);
    }

    public function test_edit_is_rejected_when_the_new_record_has_insufficient_stock(): void
    {
        $wh    = $this->makeWarehouse('Warehouse A', 'WHA');
        $big   = $this->stockItem($wh, 'Food Pack', 30, 800, 1);
        $small = $this->stockItem($wh, 'Food Pack', 5, 700, 2);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 20);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $big->id, 10, 'DR-ED-7');

        // Switch to the ₱700 record that only holds 5
        $this->editDispatch($disp, [
            'item_id'         => $small->id,
            'quantity_issued' => 10,
            'unit_cost'       => 700,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity_issued');

        $this->assertEquals(20, (float) $big->fresh()->quantity);
        $this->assertEquals(5, (float) $small->fresh()->quantity);
        $this->assertSame($big->id, (int) $disp->fresh()->item_id);
        $this->assertEquals(10, (float) $line->fresh()->quantity_issued);
    }

    public function test_edit_data_endpoint_returns_the_dispatch_and_available_warehouses(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');
        $item = $this->stockItem($whA, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 20);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 10, 'DR-ED-8');
        $disp->update(['engas_unit_cost' => 750, 'expiration_date' => '2026-12-31']);

        $this->actingAs($this->admin())
            ->getJson(route('requisitions.dispatch_edit_data', $disp->id))
            ->assertOk()
            ->assertJsonPath('id', $disp->id)
            ->assertJsonPath('requisition_id', $ris->id)
            ->assertJsonPath('item_id', $item->id)
            ->assertJsonPath('warehouse_id', $whA->id)
            ->assertJsonPath('quantity_issued', 10)
            ->assertJsonPath('unit_cost', 700)
            ->assertJsonPath('engas_unit_cost', 750)
            ->assertJsonPath('expiration_date', '2026-12-31')
            ->assertJsonPath('dr_number', 'DR-ED-8')
            ->assertJsonCount(2, 'warehouses')
            ->assertJsonCount(1, 'stock_records');
    }

    public function test_non_approvers_cannot_edit_dispatches(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 20);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 10, 'DR-ED-9');

        $this->actingAs($this->staff())
            ->getJson(route('requisitions.dispatch_edit_data', $disp->id))
            ->assertForbidden();

        $this->actingAs($this->staff())
            ->putJson(route('requisitions.dispatch_update', $disp->id), [
                'warehouse_id'    => $wh->id,
                'item_id'         => $item->id,
                'quantity_issued' => 20,
                'unit_cost'       => 700,
                'dr_number'       => 'DR-ED-9',
            ])
            ->assertForbidden();

        $this->assertEquals(90, (float) $item->fresh()->quantity);
        $this->assertEquals(10, (float) $line->fresh()->quantity_issued);
    }

    public function test_edit_refreshes_fulfilment_status_and_line_cache(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->stockItem($wh, 'Food Pack', 100, 700);
        $catalog = $this->makeCatalogItem('Food Pack');

        $ris  = $this->createRis($catalog->id, 20);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 20, 'DR-ED-10');

        $this->assertSame('approved', $ris->fresh()->status);

        // Reduce below the requested quantity → back to partially fulfilled
        $this->editDispatch($disp, ['quantity_issued' => 15])->assertOk();

        $this->assertEquals(85, (float) $item->fresh()->quantity);
        $this->assertEquals(15, (float) $line->fresh()->quantity_issued);
        $this->assertEquals(700, (float) $line->fresh()->unit_cost);
        $this->assertSame('partially_approved', $ris->fresh()->status);
        $this->assertEquals(15, (float) $line->fresh()->quantity_issued);
    }
}
