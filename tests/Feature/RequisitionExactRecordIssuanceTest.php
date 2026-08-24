<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionExactRecordIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'req_admin_' . $i,
            'name'     => 'Req Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description, float $qty, float $cost): Item
    {
        return Item::create([
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
    }

    private function makeCatalogItem(string $name, string $accountCode = '50101010'): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'cat_' . mb_strtolower(str_replace(' ', '_', $name))],
            ['label' => $name, 'account_code' => $accountCode, 'is_active' => true, 'sort_order' => 0]
        );

        return ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name'             => $name,
            'account_code'     => $accountCode,
            'is_active'        => true,
        ]);
    }

    /**
     * Creation is description-level: only catalog_item_id + quantity_requested
     * are sent. The warehouse and exact stock record are chosen during dispatch.
     */
    private function createRis(int $catalogItemId, float $qty): Requisition
    {
        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'ris_number'      => 'RIS-EXACT-' . strtoupper(\Illuminate\Support\Str::random(6)) . '-' . time() . rand(100,999),
                'purpose'         => 'Test issuance',
                'date_requested'  => '2026-08-01',
                'items'           => [
                    [
                        'catalog_item_id'    => $catalogItemId,
                        'quantity_requested' => $qty,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        return Requisition::latest('id')->firstOrFail();
    }

    private function processApproval(Requisition $ris, float $issueQty, array $lineKeys = [])
    {
        $payload = ['approved_by_name' => 'Test Admin', 'issued_by_name' => 'Test Admin'];

        foreach ($lineKeys as $riItemId) {
            $line = RequisitionItem::findOrFail($riItemId);
            $payload['items'][$riItemId]['quantity_issued'] = $issueQty;
            $payload['items'][$riItemId]['dr_number']       = 'DR-ISSUE-' . $riItemId;
            if ($line->item_id) {
                // Line already linked to an exact record (post-issuance)
                $payload['items'][$riItemId]['item_id']      = $line->item_id;
                $payload['items'][$riItemId]['warehouse_id'] = $line->item?->warehouse_id;
            } else {
                // Request-stage lines are intentionally unlinked — resolve the
                // exact stock record explicitly at issuance, just as the UI does.
                $catalog = $line->catalog_item_id ? ItemCatalogItem::find($line->catalog_item_id) : null;
                $rep = $catalog ? Item::where('is_active', true)
                    ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($catalog->name))])
                    ->first() : null;
                $payload['items'][$riItemId]['item_id']      = $rep?->id;
                $payload['items'][$riItemId]['warehouse_id'] = $rep?->warehouse_id;
            }
        }

        return $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), $payload);
    }

    public function test_issuance_deducts_from_exact_selected_unit_cost_record(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $cheap = $this->makeItem($wh, 'Food Pack', 50, 700); // arrived first
        $costly = $this->makeItem($wh, 'Food Pack', 30, 800); // arrived later
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        $ris = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();

        // The dispatcher picks the ₱800 record even though the ₱700 record
        // also exists with the same description.
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $costly->id, 'quantity_issued' => 10, 'dr_number' => 'DR-EXACT-1'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.show', $ris->id));

        // Only the ₱800 record was deducted — never FIFO onto the ₱700 record
        $this->assertEquals(50, (float) $cheap->fresh()->quantity);
        $this->assertEquals(20, (float) $costly->fresh()->quantity);

        // The stock-card entry targets the exact record with its unit cost
        $entry = StockCardEntry::where('reference_type', 'issuance')
            ->where('reference_id', $ris->id)
            ->where('item_id', $costly->id)
            ->firstOrFail();
        $this->assertEquals(10, (float) $entry->issue_qty);
        $this->assertEquals(800, (float) $entry->balance_unit_cost);
        $this->assertEquals(0, StockCardEntry::where('item_id', $cheap->id)->count());
    }

    public function test_issuance_is_rejected_when_selected_record_has_insufficient_stock(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $cheap  = $this->makeItem($wh, 'Food Pack', 50, 700);
        $costly = $this->makeItem($wh, 'Food Pack', 30, 800);
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        // Request 10, but try to issue 35 from the ₱800 record that only holds 30
        $ris = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $costly->id, 'quantity_issued' => 35, 'dr_number' => 'DR-INSUFF-1'],
                ],
            ])
            ->assertSessionHasErrors('items.*.quantity_issued');

        // Nothing was deducted — no fallback to another record
        $this->assertEquals(50, (float) $cheap->fresh()->quantity);
        $this->assertEquals(30, (float) $costly->fresh()->quantity);
        $this->assertEquals(0, (float) $line->fresh()->quantity_issued);
        $this->assertEquals(0, StockCardEntry::where('reference_type', 'issuance')->where('reference_id', $ris->id)->count());
    }

    public function test_issuance_is_rejected_when_more_than_outstanding(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->makeItem($wh, 'Rice', 50, 800);
        $catalog = $this->makeCatalogItem('Rice', '50101020');

        // Request 10, then try to issue 15 (stock exists, but 5 is not outstanding)
        $ris = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();

        $this->processApproval($ris, 15, [$line->id])
            ->assertSessionHasErrors('items.*.quantity_issued');

        $this->assertEquals(50, (float) $item->fresh()->quantity);
        $this->assertEquals(0, (float) $line->fresh()->quantity_issued);
    }

    public function test_partial_issuance_within_record_stock_is_still_allowed(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $other  = $this->makeItem($wh, 'Food Pack', 50, 700);
        $costly = $this->makeItem($wh, 'Food Pack', 30, 800);
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        // Request 20 of the ₱800 record, issue only 8 now
        $ris = $this->createRis($catalog->id, 20);
        $line = $ris->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $costly->id, 'quantity_issued' => 8, 'dr_number' => 'DR-PART-1'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(50, (float) $other->fresh()->quantity);
        $this->assertEquals(22, (float) $costly->fresh()->quantity);
        $this->assertEquals(8, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('partially_approved', $ris->fresh()->status);
    }

    public function test_one_ris_can_draw_items_from_multiple_warehouses(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');
        $whC = $this->makeWarehouse('Warehouse C', 'WHC');

        $foodPack = $this->makeItem($whA, 'Food Pack', 10, 700);
        $rice     = $this->makeItem($whB, 'Rice', 20, 800);
        $water    = $this->makeItem($whC, 'Water', 15, 900);
        $catalogFood = $this->makeCatalogItem('Food Pack', '50101010');
        $catalogRice = $this->makeCatalogItem('Rice', '50101020');
        $catalogWater = $this->makeCatalogItem('Water', '50101030');

        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'purpose'        => 'Multi-warehouse test',
                'date_requested' => '2026-08-01',
                'items'          => [
                    ['catalog_item_id' => $catalogFood->id, 'quantity_requested' => 10],
                    ['catalog_item_id' => $catalogRice->id, 'quantity_requested' => 20],
                    ['catalog_item_id' => $catalogWater->id, 'quantity_requested' => 15],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        $ris = Requisition::latest('id')->firstOrFail();
        $this->assertSame(3, $ris->items()->count());
        $this->assertNull($ris->warehouse_id);

        $lines = $ris->items()->orderBy('id')->get();
        // Warehouse is NOT pinned at creation — it is chosen per dispatch
        $this->assertNull($lines[0]->warehouse_id);
        $this->assertNull($lines[1]->warehouse_id);
        $this->assertNull($lines[2]->warehouse_id);
        $this->assertSame($catalogFood->id, (int) $lines[0]->catalog_item_id);
        $this->assertSame($catalogRice->id, (int) $lines[1]->catalog_item_id);
        $this->assertSame($catalogWater->id, (int) $lines[2]->catalog_item_id);
        $this->assertSame('Food Pack', $lines[0]->description);
        $this->assertSame('50101010', $lines[0]->account_code);

        // Issuance deducts from each warehouse's exact record
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $lines[0]->id => ['warehouse_id' => $whA->id, 'item_id' => $foodPack->id, 'quantity_issued' => 10, 'dr_number' => 'DR-M1-' . uniqid()],
                    $lines[1]->id => ['warehouse_id' => $whB->id, 'item_id' => $rice->id,     'quantity_issued' => 20, 'dr_number' => 'DR-M2-' . uniqid()],
                    $lines[2]->id => ['warehouse_id' => $whC->id, 'item_id' => $water->id,    'quantity_issued' => 15, 'dr_number' => 'DR-M3-' . uniqid()],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0,  (float) $foodPack->fresh()->quantity);
        $this->assertEquals(0,  (float) $rice->fresh()->quantity);
        $this->assertEquals(0,  (float) $water->fresh()->quantity);
        $this->assertEquals('approved', $ris->fresh()->status);
    }

    public function test_one_line_can_be_dispatched_in_parts_to_different_warehouses(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $foodA = $this->makeItem($whA, 'Food Pack', 200, 700);
        $foodB = $this->makeItem($whB, 'Food Pack', 300, 800);
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        // One description-level line requesting 500 Food Packs
        $ris = $this->createRis($catalog->id, 500);
        $line = $ris->items()->firstOrFail();

        // Dispatch 1: 200 from Warehouse A
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $whA->id, 'item_id' => $foodA->id, 'quantity_issued' => 200, 'dr_number' => 'DR-001'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0,   (float) $foodA->fresh()->quantity);
        $this->assertEquals(300, (float) $foodB->fresh()->quantity);
        $this->assertEquals(200, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('partially_approved', $ris->fresh()->status);

        // Dispatch 2: the remaining 300 from Warehouse B, same line
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $whB->id, 'item_id' => $foodB->id, 'quantity_issued' => 300, 'dr_number' => 'DR-002'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(0,   (float) $foodB->fresh()->quantity);
        $this->assertEquals(500, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('approved', $ris->fresh()->status);

        // Each dispatch keeps its own stock record, and its warehouse is derived
        // from that record (items.warehouse_id) — no warehouse column stored.
        $dispatches = $line->dispatchItems()->orderBy('id')->get();
        $this->assertCount(2, $dispatches);
        $this->assertSame($whA->id, (int) $dispatches[0]->item->warehouse_id);
        $this->assertSame($whB->id, (int) $dispatches[1]->item->warehouse_id);
        $this->assertSame($foodA->id, (int) $dispatches[0]->item_id);
        $this->assertSame($foodB->id, (int) $dispatches[1]->item_id);
        $this->assertSame('DR-001', $dispatches[0]->dr_number);
        $this->assertSame('DR-002', $dispatches[1]->dr_number);
        $this->assertEquals(200, (float) $dispatches[0]->quantity_issued);
        $this->assertEquals(300, (float) $dispatches[1]->quantity_issued);

        // Stock card entries target each warehouse's exact record
        $this->assertEquals(2, StockCardEntry::where('reference_type', 'issuance')->where('reference_id', $ris->id)->count());
    }

    public function test_same_item_name_can_be_requested_as_two_separate_lines(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $riceA = $this->makeItem($whA, 'Rice', 100, 50);
        $riceB = $this->makeItem($whB, 'Rice', 30, 60);
        $catalog = $this->makeCatalogItem('Rice', '50101020');

        // Two rows of the same catalog item produce two description-level lines
        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'purpose'        => 'Same item, two lines',
                'date_requested' => '2026-08-01',
                'items'          => [
                    ['catalog_item_id' => $catalog->id, 'quantity_requested' => 10],
                    ['catalog_item_id' => $catalog->id, 'quantity_requested' => 5],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        $ris = Requisition::latest('id')->firstOrFail();
        $this->assertSame(2, $ris->items()->count());

        $lines = $ris->items()->orderBy('id')->get();
        $this->assertSame($catalog->id, (int) $lines[0]->catalog_item_id);
        $this->assertSame($catalog->id, (int) $lines[1]->catalog_item_id);
        $this->assertSame('Rice', $lines[0]->description);

        // Each line can be dispatched to a different warehouse
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $lines[0]->id => ['warehouse_id' => $whA->id, 'item_id' => $riceA->id, 'quantity_issued' => 10, 'dr_number' => 'DR-D1-' . uniqid()],
                    $lines[1]->id => ['warehouse_id' => $whB->id, 'item_id' => $riceB->id, 'quantity_issued' => 5,  'dr_number' => 'DR-D2-' . uniqid()],
                ],
            ])
            ->assertSessionHasNoErrors();

        // Only the exact record in each warehouse was deducted
        $this->assertEquals(90, (float) $riceA->fresh()->quantity);
        $this->assertEquals(25, (float) $riceB->fresh()->quantity);
    }

    public function test_requesting_lgu_is_saved_with_the_requisition_and_editable(): void
    {
        $wh = $this->makeWarehouse('Central', 'CEN');
        $item = $this->makeItem($wh, 'Food Pack', 100, 50);
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        $ris = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();
        $ris->update(['province' => 'Cebu', 'municipality' => 'Lapu-Lapu City']);

        $this->actingAs($this->admin())
            ->put(route('requisitions.update', $ris->id), [
                'purpose'         => 'LGU test updated',
                'date_requested'  => '2026-08-02',
                'status'          => $ris->status,
                'province'        => 'Cebu',
                'municipality'    => 'Cordova',
                'items'           => [
                    ['id' => $line->id, 'catalog_item_id' => $catalog->id, 'quantity_requested' => 10],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Cordova', $ris->fresh()->municipality);
        $this->assertSame('Cebu', $ris->fresh()->province);
    }

    public function test_dr_number_is_saved_per_dispatch(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->makeItem($wh, 'Food Pack', 50, 700);
        $catalog = $this->makeCatalogItem('Food Pack', '50101010');

        $ris = $this->createRis($catalog->id, 10);
        $line = $ris->items()->firstOrFail();

        // No DR number is stored at creation — it belongs to the dispatch
        $this->assertNull($line->dr_number);
        $this->assertNull($ris->dr_number);

        // Issuing requires a DR number (and warehouse + stock record) for the line
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name'  => 'Test Admin',
                'issued_by_name'    => 'Test Admin',
                'items'             => [
                    $line->id => ['quantity_issued' => 10],
                ],
            ])
            ->assertSessionHasErrors('items.*.dr_number');

        // With the DR number provided, the dispatch succeeds and the DR is stored
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name'  => 'Test Admin',
                'issued_by_name'    => 'Test Admin',
                'items'             => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 10, 'dr_number' => 'DR-DISPATCH-001'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $dispatch = $line->dispatchItems()->firstOrFail();
        $this->assertSame('DR-DISPATCH-001', $dispatch->dr_number);
        // DR number lives on the dispatch, not on the RI
        $this->assertNull($line->fresh()->dr_number);
        $this->assertEquals(40, (float) $item->fresh()->quantity);
        $this->assertEquals('approved', $ris->fresh()->status);
    }

    public function test_create_dropdown_lists_active_catalog_items_with_account_codes(): void
    {
        $food   = $this->makeCatalogItem('Food Pack', '50101010');
        $rice   = $this->makeCatalogItem('Rice', '50101020');
        $water  = $this->makeCatalogItem('Bottled Water', '50101030');
        $inactive = ItemCatalogItem::create([
            'item_category_id' => $food->category->id,
            'name'             => 'Unlisted',
            'account_code'     => '00000000',
            'is_active'        => false,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('requisitions.description_items'))
            ->assertOk()
            ->assertJsonCount(3);

        $names = $response->json('*.name');
        $this->assertContains('Food Pack', $names);
        $this->assertContains('Rice', $names);
        $this->assertContains('Bottled Water', $names);
        $this->assertNotContains('Unlisted', $names);

        $byName = collect($response->json())->keyBy('name');
        $this->assertSame('50101010', $byName['Food Pack']['account_code']);
        $this->assertSame('50101020', $byName['Rice']['account_code']);
        $this->assertSame('50101030', $byName['Bottled Water']['account_code']);
        $this->assertSame($food->id, (int) $byName['Food Pack']['id']);
        $this->assertSame('', $byName['Food Pack']['unit']);
        $this->assertSame(0, (int) $byName['Food Pack']['total_stock']);
    }

    public function test_new_catalog_item_becomes_available_in_the_dropdown_automatically(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('requisitions.description_items'))
            ->assertOk()
            ->assertJsonCount(0);

        $this->makeCatalogItem('Medical Supplies', '50101040');

        $this->actingAs($this->admin())
            ->getJson(route('requisitions.description_items'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Medical Supplies')
            ->assertJsonPath('0.account_code', '50101040');
    }

    public function test_creation_needs_no_stock_and_snapshots_description_and_account_code(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');
        $catalog = $this->makeCatalogItem('Medical Supplies', '50101040');

        // No items/stock records exist at all for this catalog item.
        $this->assertSame(0, Item::count());

        $ris = $this->createRis($catalog->id, 25);
        $line = $ris->items()->firstOrFail();

        // Description-level: no warehouse, no stock record pinned.
        $this->assertNull($ris->warehouse_id);
        $this->assertNull($line->warehouse_id);
        $this->assertNull($line->item_id);
        $this->assertSame($catalog->id, (int) $line->catalog_item_id);
        $this->assertSame('Medical Supplies', $line->description);
        $this->assertSame('50101040', $line->account_code);
        $this->assertEquals(25, (float) $line->quantity_requested);
        $this->assertSame(0, StockCardEntry::count());

        // Once a warehouse stocks it, the same line dispatches normally.
        $item = $this->makeItem($wh, 'Medical Supplies', 50, 100);
        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => ['warehouse_id' => $wh->id, 'item_id' => $item->id, 'quantity_issued' => 25, 'dr_number' => 'DR-NOSTOCK-1'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals(25, (float) $item->fresh()->quantity);
        $this->assertEquals(25, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('approved', $ris->fresh()->status);
    }

    public function test_create_page_renders_as_modal_with_only_description_and_quantity(): void
    {
        $wh = $this->makeWarehouse('Central', 'CEN');
        $this->makeItem($wh, 'Food Pack', 10, 700);

        $response = $this->actingAs($this->admin())
            ->get(route('requisitions.create'))
            ->assertOk()
            ->assertSee('id="createModal"', false)
            ->assertSee('modal-shell')
            ->assertSee('New Requisition (RIS)')
            ->assertSee('modal-overlay open');

        // Requesting LGU stays free-text.
        $response->assertSee('name="province"', false)
            ->assertSee('name="municipality"', false);

        // Line items table shows only description + requested quantity.
        $response->assertSee('Item Description')
            ->assertSee('Requested Quantity')
            ->assertSee('][catalog_item_id]"', false)
            ->assertSee('][quantity_requested]"', false);

        // Dispatch-related fields must not appear at creation.
        $response->assertDontSee('][warehouse_id]', false)
            ->assertDontSee('][item_id]', false)
            ->assertDontSee('][unit_cost]', false)
            ->assertDontSee('][engas_unit_cost]', false)
            ->assertDontSee('][dr_number]', false)
            ->assertDontSee('][expiration_date]', false)
            ->assertDontSee('][quantity_delivered]', false);
    }

    public function test_index_page_embeds_the_create_modal(): void
    {
        $this->makeCatalogItem('Food Pack', '50101010');
        $this->makeCatalogItem('Rice', '50101020');

        $this->actingAs($this->admin())
            ->get(route('requisitions.index'))
            ->assertOk()
            ->assertSee('openCreateModal()')
            ->assertSee('id="createModal"', false)
            ->assertSee('modal-shell')
            ->assertSee('New Requisition (RIS)');
    }
}
