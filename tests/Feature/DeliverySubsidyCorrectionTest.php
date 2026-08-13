<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliverySubsidy;
use App\Models\DeliverySubsidyAuditLog;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\StockCardEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySubsidyCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'dsc_admin_' . $i,
            'name'     => 'DSC Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
        ]);
    }

    private function staff(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'dsc_staff_' . $i,
            'name'     => 'DSC Staff ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_STAFF,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(Warehouse $wh, string $description, float $cost = 10.0, float $qty = 0): Item
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

    private function createSubsidy(array $lines, string $ris, ?int $catalogItemId = null): DeliverySubsidy
    {
        $supplier = Supplier::create(['name' => 'Test Supplier', 'is_active' => true]);

        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'item_id'         => $line['item_id'] ?? null,
                'catalog_item_id' => $line['catalog_item_id'] ?? $catalogItemId,
                'description'     => $line['description'],
                'unit'            => $line['unit'] ?? 'piece',
                'category'        => $line['category'] ?? 'food',
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
            ->assertSessionHasNoErrors()
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

    private function correctPayload(DeliverySubsidy $ds, array $overrides = []): array
    {
        $line = $ds->items()->firstOrFail();

        $payload = [
            'date'              => '2026-08-01',
            'place_of_delivery' => $ds->place_of_delivery,
            'remarks'           => $ds->remarks,
            'items'             => [
                0 => [
                    'dsi_id'          => $line->id,
                    'item_id'         => $line->item_id,
                    'catalog_item_id' => $line->catalog_item_id,
                    'account_code'    => $line->account_code,
                    'description'     => $line->item?->description ?? $line->description,
                    'unit'            => $line->item?->unit ?? $line->unit,
                    'category'        => $line->item?->category ?? $line->category,
                    'quantity'        => $line->quantity,
                    'expiration_date' => $line->expiration_date?->format('Y-m-d'),
                ],
            ],
        ];

        foreach (['date', 'place_of_delivery', 'remarks'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $payload[$field] = $overrides[$field];
            }
        }

        if (isset($overrides['items'])) {
            foreach ($overrides['items'] as $idx => $changes) {
                $payload['items'][$idx] = array_merge($payload['items'][$idx], $changes);
            }
        }

        return $payload;
    }

    private function correctSubsidy(DeliverySubsidy $ds, array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->putJson(route('delivery_subsidies.correct', $ds->id), $this->correctPayload($ds, $overrides));
    }

    // ── Completed-subsidy setup: requested 500, delivered 500, fully_delivered ──
    private function completedSubsidy(): array
    {
        $wh     = $this->makeWarehouse('Correction WH', 'CSW');
        $item   = $this->makeItem($wh, 'Gallon Water', 700.00);
        $catalog = $this->makeCatalogItem('Gallon Water');

        $ds = $this->createSubsidy([
            ['description' => 'Gallon Water', 'quantity' => 500],
        ], 'RIS-DSC-1', $catalog->id);

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-DSC-1', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 500, 'unit_cost' => 700,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-DSC-1-A',
        ]]);

        $ds->refresh();
        $this->assertSame('fully_delivered', $ds->status);

        return [$wh, $item, $catalog, $ds];
    }

    public function test_increasing_requested_quantity_on_completed_subsidy_reopens_outstanding(): void
    {
        [$wh, $item, $catalog, $ds] = $this->completedSubsidy();
        $line = $ds->items()->firstOrFail();
        $delivery = Delivery::where('dr_number', 'DR-DSC-1')->firstOrFail();
        $di       = $delivery->items()->firstOrFail();

        $this->correctSubsidy($ds, [
            'items' => [0 => ['quantity' => 600]],
        ])->assertOk()->assertJson(['redirect' => route('delivery_subsidies.show', $ds->id)]);

        $line->refresh();
        $di->refresh();
        $item->refresh();

        // Request corrected, delivery untouched
        $this->assertEquals(600, (float) $line->quantity);
        $this->assertEquals(500, (float) $line->qty_delivered);

        // Shipment record + inventory + stock cards untouched
        $this->assertEquals(500, (float) $delivery->quantity_delivered);
        $this->assertEquals(500, (float) $di->quantity_delivered);
        $this->assertSame('DR-DSC-1-A', $di->dr_number);
        $this->assertEquals(500, (float) $item->quantity);   // stock from delivery, unchanged

        // Status reclassified → partial, 100 outstanding
        $ds->refresh();
        $this->assertSame('partial', $ds->status);
        $this->assertEqualsWithDelta(100, (float) $ds->quantity_requested - $ds->totalDelivered(), 0.0001);
    }

    public function test_decreasing_below_delivered_is_rejected_without_changes(): void
    {
        [, , , $ds] = $this->completedSubsidy();

        $this->correctSubsidy($ds, [
            'items' => [0 => ['quantity' => 400]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $line = $ds->items()->firstOrFail();
        $this->assertEquals(500, (float) $line->quantity);
        $this->assertEquals(500, (float) $line->qty_delivered);

        // No audit log entry, no status change
        $ds->refresh();
        $this->assertSame('fully_delivered', $ds->status);
        $this->assertSame(0, DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->count());
    }

    public function test_decreasing_to_exact_delivered_keeps_fully_delivered_status(): void
    {
        $wh = $this->makeWarehouse('Correction WH 2', 'CSW2');
        $this->makeItem($wh, 'Paper Reams', 250.00);

        $ds = $this->createSubsidy([
            ['description' => 'Paper Reams', 'quantity' => 500],
        ], 'RIS-DSC-2');

        $line = $ds->items()->firstOrFail();
        $this->dispatch($ds, 'DR-DSC-2', [[
            'ds_item_id' => $line->id, 'warehouse_id' => $wh->id,
            'quantity_delivered' => 300, 'unit_cost' => 250,
            'expiration_date' => '2027-01-01', 'dr_number' => 'DR-DSC-2-A',
        ]]);

        $ds->refresh();
        $this->assertSame('partial', $ds->status);

        $this->correctSubsidy($ds, [
            'items' => [0 => ['quantity' => 300]],
        ])->assertOk();

        $line = $ds->items()->firstOrFail();
        $this->assertEquals(300, (float) $line->quantity);
        $this->assertEquals(300, (float) $line->qty_delivered);

        $ds->refresh();
        $this->assertSame('fully_delivered', $ds->status);
        $this->assertEqualsWithDelta(0, (float) $ds->quantity_requested - $ds->totalDelivered(), 0.0001);
    }

    public function test_audit_log_records_who_old_and_new_values(): void
    {
        [$wh, $item, $catalog, $ds] = $this->completedSubsidy();
        $user = $this->admin();

        $this->actingAs($user)
            ->putJson(route('delivery_subsidies.correct', $ds->id), $this->correctPayload($ds, [
                'date'  => '2026-08-02',
                'items' => [0 => ['quantity' => 650]],
            ]))
            ->assertOk();

        $log = DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->firstOrFail();

        $this->assertEquals($user->id, $log->user_id);
        $this->assertSame('correction', $log->action);

        $changes = $log->changed_fields;

        $this->assertSame('2026-08-01', $changes['date']['old']);
        $this->assertSame('2026-08-02', $changes['date']['new']);
        $line = $ds->items()->firstOrFail();
        $this->assertSame('500', (string) $changes["items.{$line->id}.quantity"]['old']);
        $this->assertSame('650', (string) $changes["items.{$line->id}.quantity"]['new']);
        $this->assertSame('500', (string) $changes['quantity_requested']['old']);
        $this->assertSame('650', (string) $changes['quantity_requested']['new']);
        $this->assertSame('fully_delivered', $changes['status']['old']);
        $this->assertSame('partial', $changes['status']['new']);
    }

    public function test_audit_log_records_item_change_on_undelivered_line(): void
    {
        $wh = $this->makeWarehouse('Correction WH 3', 'CSW3');
        $this->makeItem($wh, 'Notebooks', 50.00);
        $catA = $this->makeCatalogItem('Notebooks');
        $catB = $this->makeCatalogItem('Ballpens');

        $ds = $this->createSubsidy([
            ['description' => 'Notebooks', 'quantity' => 100],
        ], 'RIS-DSC-3', $catA->id);

        $line = $ds->items()->firstOrFail();
        $this->assertSame('Notebooks', $line->description);
        $this->assertEquals(0, (float) $line->qty_delivered);

        $this->correctSubsidy($ds, [
            'items' => [0 => [
                'catalog_item_id' => $catB->id,
                'description'     => 'Ballpens',
            ]],
        ])->assertOk();

        $line->refresh();
        $this->assertSame('Ballpens', $line->description);
        $this->assertEquals($catB->id, $line->catalog_item_id);

        $log = DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->firstOrFail();
        $this->assertArrayHasKey("items.{$line->id}.item", $log->changed_fields);
        $this->assertSame('Notebooks', $log->changed_fields["items.{$line->id}.item"]['old']);
        $this->assertSame('Ballpens', $log->changed_fields["items.{$line->id}.item"]['new']);
    }

    public function test_item_change_is_blocked_on_delivered_line(): void
    {
        [, , , $ds] = $this->completedSubsidy();
        $catB = $this->makeCatalogItem('Toner Cartridge');
        $line = $ds->items()->firstOrFail();

        $this->correctSubsidy($ds, [
            'items' => [0 => [
                'catalog_item_id' => $catB->id,
                'description'     => 'Toner Cartridge',
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.catalog_item_id');

        $line->refresh();
        $this->assertSame('Gallon Water', $line->description);
        $this->assertNotEquals($catB->id, $line->catalog_item_id);
    }

    public function test_header_fields_are_updated_and_logged(): void
    {
        [, , , $ds] = $this->completedSubsidy();

        $this->correctSubsidy($ds, [
            'place_of_delivery' => 'LGU Hall, Baler',
            'remarks'           => 'Corrected remarks text',
        ])->assertOk();

        $ds->refresh();
        $this->assertSame('LGU Hall, Baler', $ds->place_of_delivery);
        $this->assertSame('Corrected remarks text', $ds->remarks);

        $log = DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->firstOrFail();
        $this->assertSame('LGU Hall, Baler', $log->changed_fields['place_of_delivery']['new']);
        $this->assertSame('Corrected remarks text', $log->changed_fields['remarks']['new']);
    }

    public function test_no_audit_entry_when_nothing_changed(): void
    {
        [, , , $ds] = $this->completedSubsidy();

        $this->correctSubsidy($ds)->assertOk();

        $this->assertSame(0, DeliverySubsidyAuditLog::where('delivery_subsidy_id', $ds->id)->count());
        $ds->refresh();
        $this->assertSame('fully_delivered', $ds->status);
    }

    public function test_correction_data_endpoint_returns_header_lines_and_totals(): void
    {
        [$wh, $item, $catalog, $ds] = $this->completedSubsidy();
        $line = $ds->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->getJson(route('delivery_subsidies.correction_data', $ds->id))
            ->assertOk()
            ->assertJsonPath('ris_number', $ds->ris_number)
            ->assertJsonPath('dr_number', $ds->dr_number)
            ->assertJsonPath('is_completed', true)
            ->assertJsonPath('status', 'fully_delivered')
            ->assertJsonPath('totals.requested', 500)
            ->assertJsonPath('totals.delivered', 500)
            ->assertJsonPath('totals.remaining', 0)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.dsi_id', $line->id)
            ->assertJsonPath('items.0.qty_delivered', 500)
            ->assertJsonPath('items.0.locked', true)
            ->assertJsonCount(0, 'catalog_items');
    }

    public function test_correction_data_returns_catalog_options_for_unlocked_lines(): void
    {
        $wh = $this->makeWarehouse('Correction WH 4', 'CSW4');
        $this->makeItem($wh, 'Rice', 50.00, 200);
        $cat = $this->makeCatalogItem('Rice');

        $ds = $this->createSubsidy([
            ['description' => 'Rice', 'quantity' => 20],
        ], 'RIS-DSC-4', $cat->id);

        $this->actingAs($this->admin())
            ->getJson(route('delivery_subsidies.correction_data', $ds->id))
            ->assertOk()
            ->assertJsonPath('items.0.locked', false)
            ->assertJsonPath('totals.remaining', 20)
            ->assertJsonCount(1, 'catalog_items')
            ->assertJsonPath('catalog_items.0.name', 'Rice');
    }

    public function test_non_write_roles_cannot_correct(): void
    {
        [, , , $ds] = $this->completedSubsidy();

        $this->actingAs($this->staff())
            ->getJson(route('delivery_subsidies.correction_data', $ds->id))
            ->assertStatus(403);

        $this->actingAs($this->staff())
            ->putJson(route('delivery_subsidies.correct', $ds->id), $this->correctPayload($ds))
            ->assertStatus(403);
    }

    public function test_show_page_embeds_correct_subsidy_modal_for_admin(): void
    {
        [, , , $ds] = $this->completedSubsidy();

        $this->actingAs($this->admin())
            ->get(route('delivery_subsidies.show', $ds->id))
            ->assertOk()
            ->assertSee('Correct Subsidy')
            ->assertSee('correctSubsidyModal', false);
    }
}
