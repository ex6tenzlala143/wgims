<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyItem;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\StockCardEntry;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// DATA-INTEGRITY RULE: subsidy requested quantity is permanently locked after
// creation — header `quantity_requested` + every line `quantity`. Covers:
// plain edits, dispatched lines, augmentation (transfer) lines, RIS-issued
// lines, crafted API requests, and line add/remove attempts.
class SubsidyQuantityLockTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function admin(): User
    {
        self::$seq++;

        return User::create([
            'username' => 'sql_admin_' . self::$seq . '_' . uniqid(),
            'name' => 'SQL Admin ' . self::$seq,
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $code): Warehouse
    {
        return Warehouse::create(['name' => 'SQL WH ' . $code, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeCatalog(string $name): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'sql_' . mb_strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) . '_' . self::$seq],
            ['label' => $name, 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
        );

        return ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name' => $name . ' ' . self::$seq,
            'account_code' => '50101010',
            'is_active' => true,
        ]);
    }

    private function editPayload(DeliverySubsidy $ds, array $lineOverrides = [], array $headerOverrides = []): array
    {
        $ds->loadMissing('items');
        $items = [];
        foreach ($ds->items as $line) {
            $items[] = array_merge([
                'dsi_id' => $line->id,
                'item_id' => $line->item_id,
                'catalog_item_id' => $line->catalog_item_id,
                'account_code' => $line->account_code,
                'description' => $line->item?->description ?? $line->description,
                'unit' => $line->item?->unit ?? $line->unit,
                'category' => $line->item?->category ?? $line->category,
                'quantity' => $line->quantity,
                'expiration_date' => $line->expiration_date?->format('Y-m-d'),
            ], $lineOverrides[$line->id] ?? []);
        }

        return array_merge([
            'ris_number' => $ds->ris_number,
            'supplier_id' => $ds->supplier_id,
            'date' => '2026-08-01',
            'place_of_delivery' => $ds->place_of_delivery,
            'remarks' => $ds->remarks,
            'items' => $items,
        ], $headerOverrides);
    }

    /** Build the task's example: 200 requested, 100 dispatched, 50 moved via augmentation (transfer). */
    private function exampleSubsidy(): array
    {
        $admin = $this->admin();
        $wh = $this->makeWarehouse('SQL' . substr(md5(uniqid()), 0, 4));
        $wh2 = $this->makeWarehouse('SQ2' . substr(md5(uniqid()), 0, 4));
        $catalog = $this->makeCatalog('Lock Pack');
        $supplier = Supplier::create(['name' => 'SQL Supplier ' . uniqid(), 'is_active' => true]);
        $ris = 'RIS-SQL-' . uniqid();

        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number' => $ris,
            'supplier_id' => $supplier->id,
            'date' => '2026-08-01',
            'items' => [[
                'catalog_item_id' => $catalog->id,
                'description' => $catalog->name,
                'unit' => 'piece',
                'category' => 'food',
                'quantity' => 200,
                'expiration_date' => '2027-01-01',
            ]],
        ])->assertSessionHasNoErrors();

        $ds = DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
        $line = $ds->items()->firstOrFail();

        // Dispatch 100 of the locked 200.
        $this->actingAs($admin)->post(route('delivery_subsidies.store_delivery', $ds), [
            'delivery_date' => '2026-08-10',
            'condition_status' => 'good',
            'quantity_delivered' => 100,
            'items' => [[
                'ds_item_id' => $line->id,
                'warehouse_id' => $wh->id,
                'quantity_delivered' => 100,
                'unit_cost' => 10,
                'engas_unit_cost' => 10,
                'expiration_date' => '2027-01-01',
                'dr_number' => 'DR-' . $ris,
                'condition' => 'good',
            ]],
        ])->assertSessionHasNoErrors();

        $stockItem = Item::where('warehouse_id', $wh->id)
            ->where('description', $catalog->name)
            ->whereNotNull('stock_number')
            ->firstOrFail();

        // Augmentation: move 50 via stock transfer and dispatch it.
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $wh->id,
            'to_warehouse_id' => $wh2->id,
            'transfer_date' => '2026-09-01',
            'items' => [['item_id' => $stockItem->id, 'quantity' => 50, 'unit_cost' => 10]],
        ])->assertSessionHasNoErrors();
        $trf = StockTransfer::latest('id')->firstOrFail();
        $sti = $trf->items()->firstOrFail();
        $this->actingAs($admin)->post(route('transfers.process_dispatch', $trf), [
            'dispatch_date' => '2026-09-02',
            'items' => [['sti_id' => $sti->id, 'quantity' => 50]],
        ])->assertSessionHasNoErrors();

        return [$admin, $wh, $wh2, $catalog, $ds->fresh(), $stockItem->fresh(), $trf->fresh()];
    }

    public function test_quantity_increase_is_rejected_and_everything_preserved(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds, $stockItem] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        $before = [
            'line_qty' => (float) $line->quantity,
            'header_qty' => (float) $ds->quantity_requested,
            'delivered' => (float) $line->qty_delivered,
            'stock_qty' => (float) $stockItem->quantity,
            'stock_lineage' => [$stockItem->source_subsidy_id, $stockItem->source_subsidy_code, $stockItem->source_subsidy_status],
            'transfer_qty' => (float) StockTransfer::latest('id')->firstOrFail()->items()->firstOrFail()->quantity,
            'cards' => StockCardEntry::where('item_id', $stockItem->id)->orderBy('id')->pluck('balance_qty')->all(),
        ];

        // B: crafted API attempt 200 -> 300 must be refused.
        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, [$line->id => ['quantity' => 300]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        // A/C/D/E/F/G/H: nothing changed.
        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals(200, (float) $ds->fresh()->quantity_requested);
        $this->assertEquals($before['delivered'], (float) $line->fresh()->qty_delivered);
        $this->assertEquals($before['stock_qty'], (float) $stockItem->fresh()->quantity);
        $fresh = $stockItem->fresh();
        $this->assertEquals($before['stock_lineage'], [$fresh->source_subsidy_id, $fresh->source_subsidy_code, $fresh->source_subsidy_status]);
        $this->assertEquals($before['transfer_qty'], (float) StockTransfer::latest('id')->firstOrFail()->items()->firstOrFail()->quantity);
        $this->assertEquals($before['cards'], StockCardEntry::where('item_id', $stockItem->id)->orderBy('id')->pluck('balance_qty')->all());
    }

    public function test_quantity_decrease_is_rejected(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, [$line->id => ['quantity' => 150]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals(200, (float) $ds->fresh()->quantity_requested);
    }

    public function test_quantity_locked_even_with_no_deliveries(): void
    {
        $admin = $this->admin();
        $catalog = $this->makeCatalog('NoDispatch Pack');
        $supplier = Supplier::create(['name' => 'SQL Sup ND', 'is_active' => true]);
        $ris = 'RIS-SQL-ND-' . uniqid();

        $this->actingAs($admin)->post(route('delivery_subsidies.store'), [
            'ris_number' => $ris,
            'supplier_id' => $supplier->id,
            'date' => '2026-08-01',
            'items' => [[
                'catalog_item_id' => $catalog->id,
                'description' => $catalog->name,
                'unit' => 'piece',
                'category' => 'food',
                'quantity' => 200,
            ]],
        ])->assertSessionHasNoErrors();

        $ds = DeliverySubsidy::where('ris_number', $ris)->firstOrFail();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, [$line->id => ['quantity' => 300]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals(200, (float) $ds->fresh()->quantity_requested);
    }

    public function test_line_add_remove_rejected(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds] = $this->exampleSubsidy();
        $payload = $this->editPayload($ds);
        // Attempt to add a second line.
        $payload['items'][] = [
            'description' => 'Smuggled Pack',
            'unit' => 'piece',
            'category' => 'food',
            'quantity' => 50,
        ];

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $payload)
            ->assertStatus(422);

        $this->assertEquals(1, $ds->items()->count());
        $this->assertEquals(200, (float) $ds->fresh()->quantity_requested);

        // Attempt to drop the only line.
        $payload2 = $this->editPayload($ds);
        $payload2['items'] = [];
        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $payload2)
            ->assertStatus(422);

        $this->assertEquals(1, $ds->items()->count());
    }

    public function test_ris_issued_line_rejects_quantity_change_and_preserves_ris(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds, $stockItem] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 'relief', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 10]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $risLine = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$risLine => ['warehouse_id' => $wh->id, 'item_id' => $stockItem->id, 'quantity_issued' => 5, 'dr_number' => 'DR-SQL-RIS']],
        ])->assertSessionHasNoErrors();

        $risQtyBefore = (float) $ris->items()->firstOrFail()->quantity_requested;

        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload($ds, [$line->id => ['quantity' => 250]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals($risQtyBefore, (float) $ris->items()->firstOrFail()->quantity_requested);
    }

    public function test_legitimate_edits_still_work(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        // I(a): header date/remarks on a delivered subsidy succeed; quantities preserved.
        // Delivered lines keep their item identity (incl. expiry) locked by design.
        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $ds->id), $this->editPayload(
                $ds,
                [],
                ['date' => '2026-08-02', 'remarks' => 'corrected remarks']
            ))
            ->assertOk();

        $ds->refresh();
        $this->assertEquals('2026-08-02', $ds->date->format('Y-m-d'));
        $this->assertEquals('corrected remarks', $ds->remarks);
        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals(200, (float) $ds->quantity_requested);

        // I(b): descriptive fields on a no-delivery subsidy remain editable.
        $admin2 = $this->admin();
        $catalog2 = $this->makeCatalog('Editable Pack');
        $supplier2 = Supplier::create(['name' => 'SQL Sup ED', 'is_active' => true]);
        $ris2 = 'RIS-SQL-ED-' . uniqid();
        $this->actingAs($admin2)->post(route('delivery_subsidies.store'), [
            'ris_number' => $ris2,
            'supplier_id' => $supplier2->id,
            'date' => '2026-08-01',
            'items' => [[
                'catalog_item_id' => $catalog2->id,
                'description' => $catalog2->name,
                'unit' => 'piece',
                'category' => 'food',
                'quantity' => 50,
            ]],
        ])->assertSessionHasNoErrors();
        $ds2 = DeliverySubsidy::where('ris_number', $ris2)->firstOrFail();
        $line2 = $ds2->items()->firstOrFail();

        $this->actingAs($admin2)
            ->putJson(route('delivery_subsidies.update', $ds2->id), $this->editPayload(
                $ds2,
                [$line2->id => ['expiration_date' => '2028-01-01', 'description' => $line2->description]],
                ['remarks' => 'desc corrected']
            ))
            ->assertOk();

        $this->assertEquals(50, (float) $line2->fresh()->quantity);
        $this->assertEquals(50, (float) $ds2->fresh()->quantity_requested);
        $this->assertEquals('2028-01-01', $line2->fresh()->expiration_date->format('Y-m-d'));
    }

    public function test_ris_correction_preserves_id_links_and_quantities(): void
    {
        // Full scenario: subsidy with dispatch + augmentation (transfer) +
        // RIS issue. Correct ONLY the RIS No. and verify ID, links and all
        // quantities survive unchanged.
        [$admin, $wh, $wh2, $catalog, $ds, $stockItem, $trf] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        // Add an RIS issue so requisition lineage exists too.
        $this->actingAs($admin)->post(route('requisitions.store'), [
            'purpose' => 'relief', 'date_requested' => '2026-09-01',
            'items' => [['catalog_item_id' => $catalog->id, 'quantity_requested' => 10]],
        ])->assertSessionHasNoErrors();
        $ris = Requisition::latest('id')->firstOrFail();
        $risLineId = $ris->items()->firstOrFail()->id;
        $this->actingAs($admin)->post(route('requisitions.process_approval', $ris), [
            'approved_by_name' => 'A', 'issued_by_name' => 'B',
            'items' => [$risLineId => ['warehouse_id' => $wh->id, 'item_id' => $stockItem->id, 'quantity_issued' => 5, 'dr_number' => 'DR-SQL-RIS2']],
        ])->assertSessionHasNoErrors();

        // 3-4: record ID + current RIS + all quantities and links.
        $ds->refresh();
        $subsidyId = $ds->id;
        $subsidyCode = $ds->subsidy_code;
        $oldRis = $ds->ris_number;
        $newRis = $oldRis . '-CORRECTED';
        $drNumber = $ds->dr_number;
        $before = [
            'header_qty' => (float) $ds->quantity_requested,
            'line_qty' => (float) $line->fresh()->quantity,
            'qty_delivered' => (float) $line->fresh()->qty_delivered,
            'delivery_qty' => (float) $ds->deliveries()->sum('quantity_delivered'),
            'delivery_items_qty' => $ds->deliveries()->with('items')->get()->flatMap->items->map->quantity_delivered->all(),
            'transfer_qty' => (float) $trf->fresh()->items()->firstOrFail()->quantity,
            'transfer_requested' => (float) $trf->fresh()->items()->firstOrFail()->quantity_requested,
            'stock_qty' => (float) $stockItem->fresh()->quantity,
            'ris_qty' => (float) $ris->items()->firstOrFail()->quantity_requested,
            'ris_issued' => (float) $ris->items()->firstOrFail()->quantity_issued,
            'cards' => StockCardEntry::where('item_id', $stockItem->id)->orderBy('id')->pluck('balance_qty')->all(),
            'subsidy_count' => DeliverySubsidy::count(),
            'delivery_count' => $ds->deliveries()->count(),
        ];

        // 5-6: change ONLY the RIS No. via the normal Edit Subsidy request,
        // addressed by Subsidy ID.
        $this->actingAs($admin)
            ->putJson(route('delivery_subsidies.update', $subsidyId), $this->editPayload($ds, [], ['ris_number' => $newRis]))
            ->assertOk();

        // 7-8: RIS changed; ID and code did NOT.
        $ds->refresh();
        $this->assertSame($newRis, $ds->ris_number);
        $this->assertSame($subsidyId, $ds->id);
        $this->assertSame($subsidyCode, $ds->subsidy_code);
        $this->assertSame($drNumber, $ds->dr_number);

        // 9: every related row still points at the same subsidy ID.
        foreach ($ds->deliveries as $delivery) {
            $this->assertSame($subsidyId, $delivery->delivery_subsidy_id);
        }
        foreach ($ds->items as $dsi) {
            $this->assertSame($subsidyId, $dsi->delivery_subsidy_id);
        }
        $this->assertSame($subsidyId, $trf->fresh()->delivery_subsidy_id);
        $this->assertSame($subsidyId, $stockItem->fresh()->source_subsidy_id);

        // 10-13: no quantity moved anywhere; no duplicates created.
        $this->assertEquals($before['header_qty'], (float) $ds->quantity_requested);
        $this->assertEquals($before['line_qty'], (float) $line->fresh()->quantity);
        $this->assertEquals($before['qty_delivered'], (float) $line->fresh()->qty_delivered);
        $this->assertEquals($before['delivery_qty'], (float) $ds->deliveries()->sum('quantity_delivered'));
        $this->assertEquals($before['delivery_items_qty'], $ds->deliveries()->with('items')->get()->flatMap->items->map->quantity_delivered->all());
        $this->assertEquals($before['transfer_qty'], (float) $trf->fresh()->items()->firstOrFail()->quantity);
        $this->assertEquals($before['transfer_requested'], (float) $trf->fresh()->items()->firstOrFail()->quantity_requested);
        $this->assertEquals($before['stock_qty'], (float) $stockItem->fresh()->quantity);
        $this->assertEquals($before['ris_qty'], (float) $ris->items()->firstOrFail()->quantity_requested);
        $this->assertEquals($before['ris_issued'], (float) $ris->items()->firstOrFail()->quantity_issued);
        $this->assertEquals($before['cards'], StockCardEntry::where('item_id', $stockItem->id)->orderBy('id')->pluck('balance_qty')->all());
        $this->assertSame($before['subsidy_count'], DeliverySubsidy::count());
        $this->assertSame($before['delivery_count'], $ds->deliveries()->count());

        // 14: FK-based displays follow the corrected RIS automatically.
        $this->assertSame($newRis, $stockItem->fresh()->sourceSubsidyReference());
        $this->assertSame($newRis, $trf->fresh()->sourceSubsidyReference());
    }

    public function test_model_level_lock_blocks_direct_quantity_write(): void
    {
        [$admin, $wh, $wh2, $catalog, $ds] = $this->exampleSubsidy();
        $line = $ds->items()->firstOrFail();

        // Direct model writes bypassing the controller must also refuse.
        try {
            $line->update(['quantity' => 999]);
            $this->fail('DeliverySubsidyItem quantity update should have been blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        try {
            $ds->update(['quantity_requested' => 999]);
            $this->fail('DeliverySubsidy quantity_requested update should have been blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        $this->assertEquals(200, (float) $line->fresh()->quantity);
        $this->assertEquals(200, (float) $ds->fresh()->quantity_requested);
    }
}
