<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\StockCardEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySubsidyWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'admin_test_' . $i,
            'name'     => 'Admin Test ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description, float $cost = 10.0): Item
    {
        return Item::create([
            'stock_number' => null,
            'description'  => $description,
            'unit'         => 'piece',
            'category'     => 'food',
            'account_code' => '1040202000-01',
            'warehouse_id' => $wh->id,
            'unit_cost'    => $cost,
            'quantity'     => 0,
            'is_active'    => true,
        ]);
    }

    private function createSubsidy(array $lines, string $ris = 'RIS-2026-TEST', ?int $supplierId = null): DeliverySubsidy
    {
        if ($supplierId === null) {
            $supplierId = Supplier::create(['name' => 'Test Supplier', 'is_active' => true])->id;
        }

        $items = [];
        foreach ($lines as $i => $line) {
            $item = [
                'description'      => $line['description'],
                'unit'             => 'piece',
                'category'         => 'food',
                'quantity'         => $line['quantity'],
                'expiration_date'  => $line['expiration_date'] ?? '2027-01-01',
            ];
            if (array_key_exists('item_id', $line)) {
                $item['item_id'] = $line['item_id'];
            }
            $items[] = $item;
        }

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => $ris,
                'supplier_id' => $supplierId,
                'date'        => '2026-08-01',
                'items'       => $items,
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        return DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
    }

    public function test_creation_does_not_require_unit_cost_or_warehouse(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $wh2 = $this->makeWarehouse('Warehouse Two', 'WH2');
        $itemA = $this->makeItem($wh1, 'Item A', 100);
        $itemB = $this->makeItem($wh2, 'Item B', 200);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
            ['item_id' => $itemB->id, 'description' => 'Item B', 'quantity' => 3],
        ], 'RIS-2026-TEST-1');

        // Header has no warehouse and no total until dispatch
        $this->assertNull($ds->warehouse_id);
        $this->assertEquals(0, $ds->total_amount);

        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();

        $this->assertNull($lineA->warehouse_id);
        $this->assertNull($lineB->warehouse_id);
        $this->assertNull($lineA->unit_cost);
        $this->assertNull($lineB->unit_cost);
        $this->assertNull($lineA->amount);

        // Line identity fields are snapshotted for rendering before dispatch
        $this->assertEquals('Item A', $lineA->description);
        $this->assertEquals('piece', $lineA->unit);
        $this->assertEquals('food', $lineA->category);
    }

    public function test_dispatch_persists_warehouse_and_unit_cost_and_lands_stock(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $wh2 = $this->makeWarehouse('Warehouse Two', 'WH2');

        $ds = $this->createSubsidy([
            ['description' => 'Item A', 'quantity' => 5],
            ['description' => 'Item B', 'quantity' => 3],
        ], 'RIS-2026-TEST-2');

        $lines = $ds->items()->get()->keyBy('description');
        $lineA = $lines->get('Item A');
        $lineB = $lines->get('Item B');

        // The dispatcher picks the warehouse + cost per item
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-TEST-2',
                'condition_status'   => 'good',
                'quantity_delivered' => 8,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 100,
                        'engas_unit_cost'  => 100,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-ITEM-A-1',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh2->id,
                        'quantity_delivered' => 3,
                        'unit_cost'          => 200,
                        'engas_unit_cost'  => 200,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-ITEM-B-1',
                    ],
                ],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        // Warehouse + cost are persisted back onto the line items
        $lineA->refresh();
        $lineB->refresh();
        $this->assertEquals($wh1->id, $lineA->warehouse_id);
        $this->assertEquals($wh2->id, $lineB->warehouse_id);
        $this->assertEquals(100, (float) $lineA->unit_cost);
        $this->assertEquals(200, (float) $lineB->unit_cost);
        $this->assertEquals(500, (float) $lineA->amount);
        $this->assertEquals(600, (float) $lineB->amount);

        // Header total_amount is recomputed from dispatched lines
        $ds->refresh();
        $this->assertEquals(1100, (float) $ds->total_amount);

        // Stock landed in the selected warehouses
        $wh1Item = Item::where('warehouse_id', $wh1->id)->where('description', 'Item A')->firstOrFail();
        $wh2Item = Item::where('warehouse_id', $wh2->id)->where('description', 'Item B')->firstOrFail();

        $this->assertEquals(5, $wh1Item->quantity);
        $this->assertEquals(3, $wh2Item->quantity);

        // The other warehouse must NOT have received this item
        $this->assertFalse(Item::where('warehouse_id', $wh2->id)->where('description', 'Item A')->exists());

        // Stock cards point at the correct (per-warehouse) item records
        $this->assertEquals(5, StockCardEntry::where('item_id', $wh1Item->id)->where('reference_type', 'delivery')->sum('receipt_qty'));
        $this->assertEquals(3, StockCardEntry::where('item_id', $wh2Item->id)->where('reference_type', 'delivery')->sum('receipt_qty'));

        // Delivery items are linked to the warehouse-correct item
        $delivery = Delivery::where('dr_number', 'DR-2026-TEST-2')->firstOrFail();
        $this->assertEquals($wh1Item->id, $delivery->items()->where('delivery_subsidy_item_id', $lineA->id)->first()->item_id);
        $this->assertEquals($wh2Item->id, $delivery->items()->where('delivery_subsidy_item_id', $lineB->id)->first()->item_id);

        // Each dispatched item keeps its OWN DR# — they are not forced to share one
        $this->assertEquals('DR-2026-ITEM-A-1', $delivery->items()->where('delivery_subsidy_item_id', $lineA->id)->first()->dr_number);
        $this->assertEquals('DR-2026-ITEM-B-1', $delivery->items()->where('delivery_subsidy_item_id', $lineB->id)->first()->dr_number);

        // Stock card entries reference the per-item DR#, not a shipment-level one
        $this->assertDatabaseHas('stock_card_entries', [
            'item_id' => $wh1Item->id, 'reference_type' => 'delivery', 'reference' => 'DR-2026-ITEM-A-1',
        ]);
        $this->assertDatabaseHas('stock_card_entries', [
            'item_id' => $wh2Item->id, 'reference_type' => 'delivery', 'reference' => 'DR-2026-ITEM-B-1',
        ]);
    }

    public function test_dr_number_is_required_at_dispatch(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-TEST-3B');

        $lineA = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'condition_status'   => 'good',
                'quantity_delivered' => 5,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 100,
                        'engas_unit_cost'  => 100,
                        'expiration_date'    => '2027-01-01',
                        // dr_number intentionally missing
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.dr_number');

        $this->assertDatabaseMissing('deliveries', ['dr_number' => 'DR-2026-TEST-3B']);
    }

    public function test_warehouse_is_required_at_dispatch(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-TEST-3');

        $lineA = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-TEST-3',
                'condition_status'   => 'good',
                'quantity_delivered' => 5,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 100,
                        'engas_unit_cost'  => 100,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-TEST-3',
                        // warehouse_id intentionally missing
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.warehouse_id');

        $this->assertDatabaseMissing('deliveries', ['dr_number' => 'DR-2026-TEST-3']);
    }

    public function test_invalid_warehouse_is_rejected_at_dispatch(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-TEST-4');

        $lineA = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-TEST-4',
                'condition_status'   => 'good',
                'quantity_delivered' => 5,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => 999999,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 100,
                        'engas_unit_cost'  => 100,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-TEST-4',
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.warehouse_id');
    }

    public function test_create_page_renders_as_modal(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $this->makeItem($wh1, 'Item A', 100);

        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.create'))
            ->assertOk()
            ->assertSee('id="createModal"', false)
            ->assertSee('modal-shell')
            ->assertSee('New Delivery/Subsidy')
            ->assertSee('Item A')
            ->assertSee('modal-overlay open');
    }

    public function test_index_page_embeds_the_create_modal(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $wh2 = $this->makeWarehouse('Warehouse Two', 'WH2');
        $this->makeItem($wh1, 'Item A', 100);

        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.index'))
            ->assertOk()
            ->assertSee('openCreateModal()')
            ->assertSee('id="createModal"', false)
            ->assertSee('modal-shell')
            ->assertSee('Item A');
    }

    public function test_dispatch_page_offers_a_warehouse_selector(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');
        $wh2 = $this->makeWarehouse('Warehouse Two', 'WH2');
        $itemA = $this->makeItem($wh1, 'Item A', 100);
        $itemB = $this->makeItem($wh2, 'Item B', 200);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
            ['item_id' => $itemB->id, 'description' => 'Item B', 'quantity' => 3],
        ], 'RIS-2026-TEST-5');

        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.delivery', $ds))
            ->assertOk()
            ->assertSee('Warehouse One')
            ->assertSee('Warehouse Two')
            ->assertSee('items[0_0][warehouse_id]', false)
            ->assertSee('items[0_0][dr_number]', false);
    }

    public function test_catalog_item_names_crud_under_category(): void
    {
        $cat = ItemCategory::where('key', 'food')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('item_catalog_items.store'), [
                'item_category_id' => $cat->id,
                'name'             => 'Bond Paper A4',
                'account_code'     => '101-001',
            ])
            ->assertStatus(302);

        $ci = ItemCatalogItem::where('name', 'Bond Paper A4')->firstOrFail();
        $this->assertEquals($cat->id, $ci->item_category_id);
        // Account code is always inherited from the parent category, not from the submitted value
        $this->assertEquals($cat->account_code, $ci->account_code);
        $this->assertTrue($ci->is_active);

        // Duplicate name within the same category is rejected
        $this->actingAs($this->admin())
            ->post(route('item_catalog_items.store'), [
                'item_category_id' => $cat->id,
                'name'             => 'Bond Paper A4',
                'account_code'     => '101-001',
            ])
            ->assertSessionHasErrors('name');

        // Update
        $this->actingAs($this->admin())
            ->put(route('item_catalog_items.update', $ci), [
                'name'         => 'Bond Paper A4 (Short)',
                'account_code' => '101-002',
                'is_active'    => '1',
            ])
            ->assertStatus(302);

        $ci->refresh();
        $this->assertEquals('Bond Paper A4 (Short)', $ci->name);
        // Account code is always inherited from the parent category on update too
        $this->assertEquals($cat->account_code, $ci->account_code);

        // Delete once unreferenced
        $this->actingAs($this->admin())
            ->delete(route('item_catalog_items.destroy', $ci))
            ->assertStatus(302);

        $this->assertDatabaseMissing('item_catalog_items', ['id' => $ci->id]);
    }

    public function test_subsidy_line_from_catalog_item_keeps_account_code_through_dispatch(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');

        $cat = ItemCategory::where('key', 'food')->firstOrFail();

        $catalogItem = ItemCatalogItem::create([
            'item_category_id' => $cat->id,
            'name'             => 'Bond Paper A4',
            'account_code'     => '101-001',
            'is_active'        => true,
        ]);

        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store'), [
                'ris_number'  => 'RIS-2026-CAT',
                'supplier_id' => $supplier->id,
                'date'        => '2026-08-01',
                'items'       => [[
                    'catalog_item_id' => $catalogItem->id,
                    'account_code'    => '101-001',
                    'description'     => 'Bond Paper A4',
                    'unit'            => 'ream',
                    'category'        => 'food',
                    'quantity'        => 10,
                    'expiration_date' => '2027-01-01',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.index'));

        $ds   = DeliverySubsidy::where('ris_number', 'RIS-2026-CAT')->firstOrFail();
        $line = $ds->items()->firstOrFail();

        // Catalog identity + account code snapshot persist on the line
        $this->assertEquals($catalogItem->id, $line->catalog_item_id);
        $this->assertEquals('101-001', $line->account_code);
        $this->assertNull($line->item_id);
        $this->assertNull($line->warehouse_id);
        $this->assertNull($line->unit_cost);

        // Dispatch resolves the item and carries the configured account code through
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-CAT',
                'condition_status'   => 'good',
                'quantity_delivered' => 10,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 10,
                    'unit_cost'          => 250,
                    'engas_unit_cost'  => 250,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-CAT',
                ]],
            ])
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $item = Item::where('warehouse_id', $wh1->id)->where('description', 'Bond Paper A4')->firstOrFail();
        $this->assertEquals('101-001', $item->account_code);

        // A referenced catalog item cannot be deleted (deactivate instead)
        $this->actingAs($this->admin())
            ->delete(route('item_catalog_items.destroy', $catalogItem))
            ->assertStatus(302);

        $this->assertDatabaseHas('item_catalog_items', ['id' => $catalogItem->id]);
    }

    public function test_edit_data_returns_the_saved_subsidy_for_the_edit_modal(): void
    {
        $wh1   = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-EDIT-DATA');

        $line = $ds->items()->firstOrFail();

        $payload = $this->actingAs($this->admin())
            ->getJson(route('delivery_subsidies.edit_data', $ds))
            ->assertOk()
            ->assertJson([
                'ris_number'     => 'RIS-2026-EDIT-DATA',
                'supplier_id'    => $ds->supplier_id,
                'has_deliveries' => false,
            ])
            ->json();

        $this->assertCount(1, $payload['items']);
        $this->assertEquals($line->id, $payload['items'][0]['dsi_id']);
        $this->assertEquals('Item A', $payload['items'][0]['description']);
        $this->assertEquals('piece', $payload['items'][0]['unit']);
        $this->assertEquals('food', $payload['items'][0]['category']);
        $this->assertEquals(5, $payload['items'][0]['quantity']);
        $this->assertEquals('2027-01-01', $payload['items'][0]['expiration_date']);
        $this->assertFalse($payload['items'][0]['has_deliveries']);
    }

    public function test_update_persists_edited_lines_and_new_items_without_unit_cost_or_warehouse(): void
    {
        $wh1   = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);
        $itemB = $this->makeItem($wh1, 'Item B', 200);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-EDIT-1');

        $line = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->putJson(route('delivery_subsidies.update', $ds), [
                'ris_number'         => 'RIS-2026-EDIT-1',
                'supplier_id'        => $ds->supplier_id,
                'date'               => '2026-08-02',
                'status'             => 'pending',
                'quantity_requested' => 8,
                'items'              => [
                    [
                        'dsi_id'          => $line->id,
                        'item_id'         => $itemA->id,
                        'description'     => 'Item A',
                        'unit'            => 'piece',
                        'category'        => 'food',
                        'quantity'        => 5,
                        'expiration_date' => '2027-06-01',
                    ],
                    [
                        'item_id'         => $itemB->id,
                        'description'     => 'Item B',
                        'unit'            => 'piece',
                        'category'        => 'food',
                        'quantity'        => 3,
                        'expiration_date' => '2027-06-01',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson(['redirect' => route('delivery_subsidies.show', $ds)]);

        $ds->refresh();
        $this->assertEquals(2, $ds->items()->count());
        $this->assertEquals('2026-08-02', $ds->date->format('Y-m-d'));

        // Unit cost / warehouse stay untouched by the edit form
        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $this->assertEquals('Item A', $lineA->description);
        $this->assertEquals(5, $lineA->quantity);
        $this->assertEquals('2027-06-01', $lineA->expiration_date?->format('Y-m-d'));
        $this->assertNull($lineA->unit_cost);
        $this->assertNull($lineA->warehouse_id);

        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();
        $this->assertEquals(3, $lineB->quantity);
        $this->assertNull($lineB->unit_cost);
        $this->assertNull($lineB->warehouse_id);
    }

    public function test_show_page_embeds_the_edit_modal_for_admin(): void
    {
        $wh1   = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Item A', 100);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Item A', 'quantity' => 5],
        ], 'RIS-2026-SHOW-EDIT');

        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.show', $ds))
            ->assertOk()
            ->assertSee('editModal', false)
            ->assertSee('Edit Subsidy');
    }

    public function test_show_page_displays_each_items_own_warehouse(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');
        $itemA = $this->makeItem($whA, 'Food Pack', 700);
        $itemB = $this->makeItem($whB, 'Rice', 800);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Food Pack', 'quantity' => 10],
            ['item_id' => $itemB->id, 'description' => 'Rice', 'quantity' => 20],
        ], 'RIS-2026-WH-SHOW');

        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();

        // Dispatch each item to its own warehouse
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-WH-SHOW',
                'condition_status'   => 'good',
                'quantity_delivered' => 30,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $whA->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 700,
                        'engas_unit_cost'    => 700,
                        'dr_number'          => 'DR-2026-WH-SHOW-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $whB->id,
                        'quantity_delivered' => 20,
                        'unit_cost'          => 800,
                        'engas_unit_cost'    => 800,
                        'dr_number'          => 'DR-2026-WH-SHOW-B',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.show', $ds))
            ->assertOk();

        // Both warehouses must be visible — each tied to its own item, never
        // a single subsidy-level warehouse.
        $response
            ->assertSee('Warehouse A')
            ->assertSee('Warehouse B')
            ->assertSee('Food Pack')
            ->assertSee('Rice')
            ->assertSee('DR-2026-WH-SHOW-A')
            ->assertSee('DR-2026-WH-SHOW-B');
    }

    public function test_partial_dispatches_to_different_warehouses_each_keep_their_own_warehouse(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $ds = $this->createSubsidy([
            ['description' => 'Rice', 'quantity' => 500],
        ], 'RIS-2026-WH-SPLIT');

        $line = $ds->items()->firstOrFail();

        // First dispatch: 200 units to Warehouse A
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-WH-SPLIT-1',
                'condition_status'   => 'partial',
                'quantity_delivered' => 200,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $whA->id,
                    'quantity_delivered' => 200,
                    'unit_cost'          => 700,
                    'engas_unit_cost'    => 700,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-WH-SPLIT-1',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        // Second dispatch: the remaining 300 to Warehouse B. This must NOT
        // overwrite the first dispatch's warehouse.
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-11',
                'dr_number'          => 'DR-2026-WH-SPLIT-2',
                'condition_status'   => 'good',
                'quantity_delivered' => 300,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $whB->id,
                    'quantity_delivered' => 300,
                    'unit_cost'          => 700,
                    'engas_unit_cost'    => 700,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-WH-SPLIT-2',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        // Each dispatched item keeps its OWN warehouse + DR#
        $di1 = DeliveryItem::where('dr_number', 'DR-2026-WH-SPLIT-1')->firstOrFail();
        $di2 = DeliveryItem::where('dr_number', 'DR-2026-WH-SPLIT-2')->firstOrFail();

        $this->assertEquals($whA->id, $di1->warehouse_id);
        $this->assertEquals(200, (float) $di1->quantity_delivered);
        $this->assertEquals($whB->id, $di2->warehouse_id);
        $this->assertEquals(300, (float) $di2->quantity_delivered);

        // The requested line keeps its FIRST dispatch as the default destination
        $line->refresh();
        $this->assertEquals($whA->id, $line->warehouse_id);

        // Stock landed in the correct warehouses (200 in A, 300 in B)
        $whAItem = Item::where('warehouse_id', $whA->id)->where('description', 'Rice')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $whBItem = Item::where('warehouse_id', $whB->id)->where('description', 'Rice')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(200, $whAItem->quantity);
        $this->assertEquals(300, $whBItem->quantity);

        // The show page renders both warehouses and both DRs, never collapsing
        // them into a single warehouse.
        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.show', $ds))
            ->assertOk()
            ->assertSee('Warehouse A')
            ->assertSee('Warehouse B')
            ->assertSee('DR-2026-WH-SPLIT-1')
            ->assertSee('DR-2026-WH-SPLIT-2');
    }

    public function test_over_delivery_is_rejected_with_a_clear_message(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');

        $ds = $this->createSubsidy([
            ['description' => 'Ballpen', 'quantity' => 20],
        ], 'RIS-2026-LIMIT-1');

        $line = $ds->items()->firstOrFail();

        // First shipment: 5 of 20 dispatched
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-1A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 5,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 5,
                    'unit_cost'          => 15,
                    'engas_unit_cost'  => 15,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-LIMIT-1A',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        // Try to dispatch 20 more when only 15 remain → must be rejected, not clamped
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-11',
                'dr_number'          => 'DR-2026-LIMIT-1B',
                'condition_status'   => 'partial',
                'quantity_delivered' => 20,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 20,
                    'unit_cost'          => 15,
                    'engas_unit_cost'  => 15,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-LIMIT-1B',
                ]],
            ])
            ->assertSessionHasErrors('items.0.quantity_delivered');

        // No second delivery was recorded and stock is unchanged (still 5)
        $this->assertDatabaseMissing('deliveries', ['dr_number' => 'DR-2026-LIMIT-1B']);
        $line->refresh();
        $this->assertEquals(5, (float) $line->qty_delivered);
        $whItem = Item::where('warehouse_id', $wh1->id)->where('description', 'Ballpen')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(5, $whItem->quantity);
    }

    public function test_fully_dispatched_item_cannot_be_dispatched_again(): void
    {
        $wh1  = $this->makeWarehouse('Warehouse One', 'WH1');
        $item = $this->makeItem($wh1, 'Bond Paper', 10);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Bond Paper', 'quantity' => 10],
        ], 'RIS-2026-LIMIT-2');

        $line = $ds->items()->firstOrFail();

        $dispatch = function (string $dr, float $qty) use ($ds, $line, $wh1) {
            return $this->actingAs($this->admin())
                ->post(route('delivery_subsidies.store_delivery', $ds), [
                    'delivery_date'      => '2026-08-10',
                    'dr_number'          => $dr,
                    'condition_status'   => 'good',
                    'quantity_delivered' => $qty,
                    'items'              => [[
                        'ds_item_id'         => $line->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => $qty,
                        'unit_cost'          => 10,
                        'engas_unit_cost'  => 10,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => $dr,
                    ]],
                ]);
        };

        // Fully dispatch the requested 10
        $dispatch('DR-2026-LIMIT-2A', 10)->assertRedirect(route('delivery_subsidies.show', $ds));

        // Remaining is now 0 → any further dispatch must be rejected outright
        $dispatch('DR-2026-LIMIT-2B', 1)
            ->assertSessionHasErrors('items.0.quantity_delivered');

        $this->assertDatabaseMissing('deliveries', ['dr_number' => 'DR-2026-LIMIT-2B']);
    }

    public function test_partial_dispatch_of_one_item_does_not_block_other_items(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');

        $ds = $this->createSubsidy([
            ['description' => 'Bond Paper', 'quantity' => 10],
            ['description' => 'Ballpen', 'quantity' => 20],
        ], 'RIS-2026-LIMIT-3');

        $lines = $ds->items()->get()->keyBy('description');
        $lineA = $lines->get('Bond Paper');
        $lineB = $lines->get('Ballpen');

        // Shipment 1: A fully (10), B partially (5)
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-3A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 15,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 10,
                        'engas_unit_cost'  => 10,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-3A-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 15,
                        'engas_unit_cost'  => 15,
                    'engas_unit_cost'  => 15,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-3A-B',
                    ],
                ],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        $lineA->refresh();
        $lineB->refresh();
        $this->assertEquals(10, (float) $lineA->qty_delivered);
        $this->assertEquals(5, (float) $lineB->qty_delivered);

        // Shipment 2 (rejected): A asks for more than its remaining 0, even
        // though B still has 15 remaining → the over-dispatch line is rejected
        // and the whole submission is blocked.
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-11',
                'dr_number'          => 'DR-2026-LIMIT-3B',
                'condition_status'   => 'partial',
                'quantity_delivered' => 16,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 1,
                        'unit_cost'          => 10,
                        'engas_unit_cost'  => 10,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-3B-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 15,
                        'unit_cost'          => 15,
                        'engas_unit_cost'  => 15,
                    'engas_unit_cost'  => 15,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-3B-B',
                    ],
                ],
            ])->assertSessionHasErrors('items.0.quantity_delivered');

        // Shipment 3: B's remaining 15 alone is still fully dispatchable
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-12',
                'dr_number'          => 'DR-2026-LIMIT-3C',
                'condition_status'   => 'good',
                'quantity_delivered' => 15,
                'items'              => [[
                    'ds_item_id'         => $lineB->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 15,
                    'unit_cost'          => 15,
                    'engas_unit_cost'  => 15,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-LIMIT-3C-B',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        $lineB->refresh();
        $this->assertEquals(20, (float) $lineB->qty_delivered);

        $whItemA = Item::where('warehouse_id', $wh1->id)->where('description', 'Bond Paper')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $whItemB = Item::where('warehouse_id', $wh1->id)->where('description', 'Ballpen')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(10, $whItemA->quantity);
        $this->assertEquals(20, $whItemB->quantity);
    }

    public function test_combined_batches_cannot_exceed_remaining_quantity(): void
    {
        $wh1  = $this->makeWarehouse('Warehouse One', 'WH1');
        $item = $this->makeItem($wh1, 'Rice', 50);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Rice', 'quantity' => 20],
        ], 'RIS-2026-LIMIT-4');

        $line = $ds->items()->firstOrFail();

        // First batch: 5 of 20
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-4A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 5,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 5,
                    'unit_cost'          => 50,
                    'engas_unit_cost'  => 50,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-LIMIT-4A',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        // Two batches in one shipment: 10 + 10 = 20 > 15 remaining → rejected
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-11',
                'dr_number'          => 'DR-2026-LIMIT-4B',
                'condition_status'   => 'partial',
                'quantity_delivered' => 20,
                'items'              => [
                    [
                        'ds_item_id'         => $line->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 50,
                        'engas_unit_cost'  => 50,
                    'engas_unit_cost'  => 50,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-4B-1',
                    ],
                    [
                        'ds_item_id'         => $line->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 50,
                        'engas_unit_cost'  => 50,
                    'engas_unit_cost'  => 50,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-4B-2',
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.quantity_delivered')
            ->assertSessionHasErrors('items.1.quantity_delivered');

        $this->assertDatabaseMissing('deliveries', ['dr_number' => 'DR-2026-LIMIT-4B']);
    }

    public function test_admin_cannot_edit_a_delivery_beyond_remaining_quantity(): void
    {
        $wh1  = $this->makeWarehouse('Warehouse One', 'WH1');
        $item = $this->makeItem($wh1, 'Ballpen', 15);

        $ds = $this->createSubsidy([
            ['item_id' => $item->id, 'description' => 'Ballpen', 'quantity' => 20],
        ], 'RIS-2026-LIMIT-5');

        $line = $ds->items()->firstOrFail();

        // Dispatch 5 first
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-5A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 5,
                'items'              => [[
                    'ds_item_id'         => $line->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 5,
                    'unit_cost'          => 15,
                    'engas_unit_cost'  => 15,
                    'expiration_date'    => '2027-01-01',
                    'dr_number'          => 'DR-2026-LIMIT-5A',
                ]],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        $delivery = Delivery::where('dr_number', 'DR-2026-LIMIT-5A')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();

        // Editing that shipment to 21 would push cumulative dispatched (26) past
        // the requested 20 (only 15 remain after the first 5) → rejected.
        $this->actingAs($this->admin())
            ->put(route('delivery_subsidies.update_delivery', [$ds, $delivery]), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-5A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 21,
                'items'              => [[
                    'di_id'              => $di->id,
                    'warehouse_id'       => $wh1->id,
                    'quantity_delivered' => 21,
                    'unit_cost'          => 15,
                    'engas_unit_cost'  => 15,
                    'dr_number'          => 'DR-2026-LIMIT-5A',
                ]],
            ])
            ->assertSessionHasErrors('items.0.quantity_delivered');

        // Nothing changed: delivery item, subsidy line, and stock all intact
        $di->refresh();
        $this->assertEquals(5, (float) $di->quantity_delivered);
        $line->refresh();
        $this->assertEquals(5, (float) $line->qty_delivered);
    }

    public function test_dispatch_page_disables_fully_dispatched_items_and_shows_remaining(): void
    {
        $wh1   = $this->makeWarehouse('Warehouse One', 'WH1');
        $itemA = $this->makeItem($wh1, 'Bond Paper', 10);
        $itemB = $this->makeItem($wh1, 'Ballpen', 15);

        $ds = $this->createSubsidy([
            ['item_id' => $itemA->id, 'description' => 'Bond Paper', 'quantity' => 10],
            ['item_id' => $itemB->id, 'description' => 'Ballpen', 'quantity' => 20],
        ], 'RIS-2026-LIMIT-6');

        $lineA = $ds->items()->where('item_id', $itemA->id)->firstOrFail();
        $lineB = $ds->items()->where('item_id', $itemB->id)->firstOrFail();

        // A fully dispatched (10/10), B partially dispatched (5/20)
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-LIMIT-6A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 15,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 10,
                        'engas_unit_cost'  => 10,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-6A-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 15,
                        'engas_unit_cost'  => 15,
                    'engas_unit_cost'  => 15,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-LIMIT-6A-B',
                    ],
                ],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        $html = $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.delivery', $ds))
            ->assertOk()
            ->getContent();

        // Item A: remaining 0, disabled, marked Fully Dispatched
        $this->assertStringContainsString('data-remaining="0"', $html);
        $this->assertStringContainsString('Fully Dispatched', $html);
        $this->assertStringContainsString('disabled', $html);

        // Item B: remaining 15, dispatchable up to 15, marked Pending
        $this->assertStringContainsString('data-remaining="15"', $html);
        $this->assertStringContainsString('max="15"', $html);
        $this->assertStringContainsString('Pending', $html);
    }

    public function test_fully_delivered_item_is_not_validated_when_dispatching_other_items(): void
    {
        $wh1 = $this->makeWarehouse('Warehouse One', 'WH1');

        $ds = $this->createSubsidy([
            ['description' => 'Bond Paper', 'quantity' => 10],
            ['description' => 'Ballpen', 'quantity' => 20],
        ], 'RIS-2026-NOREQ-1');

        $lines = $ds->items()->get()->keyBy('description');
        $lineA = $lines->get('Bond Paper');
        $lineB = $lines->get('Ballpen');

        // Shipment 1: A fully dispatched (10/10), B partially (5/20)
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-10',
                'dr_number'          => 'DR-2026-NOREQ-1A',
                'condition_status'   => 'partial',
                'quantity_delivered' => 15,
                'items'              => [
                    [
                        'ds_item_id'         => $lineA->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 10,
                        'unit_cost'          => 10,
                        'engas_unit_cost'  => 10,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-NOREQ-1A-A',
                    ],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 5,
                        'unit_cost'          => 15,
                        'engas_unit_cost'  => 15,
                    'engas_unit_cost'  => 15,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-NOREQ-1A-B',
                    ],
                ],
            ])->assertRedirect(route('delivery_subsidies.show', $ds));

        // Shipment 2: only B's remaining 15 is dispatched. A's row appears in
        // the payload with only its ds_item_id (what a stale/parsed form would
        // send) — it must be ignored and must NOT trigger required-field errors.
        $this->actingAs($this->admin())
            ->post(route('delivery_subsidies.store_delivery', $ds), [
                'delivery_date'      => '2026-08-11',
                'dr_number'          => 'DR-2026-NOREQ-1B',
                'condition_status'   => 'good',
                'quantity_delivered' => 15,
                'items'              => [
                    ['ds_item_id' => $lineA->id],
                    [
                        'ds_item_id'         => $lineB->id,
                        'warehouse_id'       => $wh1->id,
                        'quantity_delivered' => 15,
                        'unit_cost'          => 15,
                        'engas_unit_cost'  => 15,
                    'engas_unit_cost'  => 15,
                        'expiration_date'    => '2027-01-01',
                        'dr_number'          => 'DR-2026-NOREQ-1B-B',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('delivery_subsidies.show', $ds));

        $lineB->refresh();
        $this->assertEquals(20, (float) $lineB->qty_delivered);

        $whItemB = Item::where('warehouse_id', $wh1->id)->where('description', 'Ballpen')
            ->where('source_subsidy_id', $ds->id)->firstOrFail();
        $this->assertEquals(20, $whItemB->quantity);
    }
}
