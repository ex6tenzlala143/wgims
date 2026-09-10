<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionAuditLog;
use App\Models\RequisitionDispatchItem;
use App\Models\RequisitionItem;
use App\Models\StockCardEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'rc_admin_' . $i,
            'name'     => 'RC Admin ' . $i,
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
            'username' => 'rc_staff_' . $i,
            'name'     => 'RC Staff ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_STAFF,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

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

    private function createRis(int $catalogItemId, float $qty, string $purpose = 'Correction test'): Requisition
    {
        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'ris_number'     => 'RIS-CORR-' . strtoupper(\Illuminate\Support\Str::random(6)) . '-' . time() . rand(100,999),
                'purpose'        => $purpose,
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

    private function correctPayload(Requisition $ris, array $overrides = []): array
    {
        $line = $ris->items()->firstOrFail();

        $payload = [
            'ris_number'     => $ris->ris_number,
            'purpose'        => $ris->purpose,
            'date_requested' => '2026-08-01',
            'items'          => [
                0 => [
                    'id'                 => $line->id,
                    'catalog_item_id'    => $line->catalog_item_id,
                    'quantity_requested' => $line->quantity_requested,
                ],
            ],
        ];

        foreach (['ris_number', 'purpose', 'date_requested', 'office', 'division', 'entity_name'] as $field) {
            if (isset($overrides[$field])) {
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

    private function correctRis(Requisition $ris, array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->putJson(route('requisitions.correct', $ris->id), $this->correctPayload($ris, $overrides));
    }

    // ── Completed-RIS setup: requested 500, issued 500, status approved ───────
    private function completedRis(): array
    {
        $wh     = $this->makeWarehouse('Correction WH', 'CRW');
        $item   = $this->stockItem($wh, 'Gallon Water', 1000, 700.00);
        $catalog = $this->makeCatalogItem('Gallon Water');

        $ris = $this->createRis($catalog->id, 500, 'Correction scenario');
        $this->dispatch($ris, $item->id, 500, 'DR-1001');

        $ris->refresh();
        $this->assertSame('approved', $ris->status);

        return [$wh, $item, $catalog, $ris];
    }

    public function test_increasing_requested_quantity_on_completed_ris_reopens_outstanding(): void
    {
        [$wh, $item, $catalog, $ris] = $this->completedRis();
        $line = $ris->items()->firstOrFail();
        $disp = $line->dispatchItems()->firstOrFail();

        $this->correctRis($ris, [
            'items' => [0 => ['quantity_requested' => 600]],
        ])->assertOk()->assertJson(['redirect' => route('requisitions.show', $ris->id)]);

        $line->refresh();
        $disp->refresh();
        $item->refresh();

        // Request corrected, issuance untouched
        $this->assertEquals(600, (float) $line->quantity_requested);
        $this->assertEquals(500, (float) $line->quantity_issued);

        // Dispatch record + inventory + stock cards untouched
        $this->assertEquals(500, (float) $disp->quantity_issued);
        $this->assertEquals($item->id, $disp->item_id);
        $this->assertEquals(700, (float) $disp->unit_cost);
        $this->assertSame('DR-1001', $disp->dr_number);
        $this->assertEquals(500, (float) $item->quantity);   // 1000 - 500, unchanged

        $entry = StockCardEntry::where('dispatch_item_id', $disp->id)->firstOrFail();
        $this->assertEquals(500, (float) $entry->issue_qty);

        // Status reclassified → partially fulfilled, 100 outstanding
        $ris->refresh();
        $this->assertSame('partially_approved', $ris->status);
        $this->assertEqualsWithDelta(100, $ris->totalRemaining(), 0.0001);
    }

    public function test_decreasing_below_issued_is_rejected_without_changes(): void
    {
        [, , , $ris] = $this->completedRis();
        $lineBefore = $ris->items()->firstOrFail();

        $this->correctRis($ris, [
            'items' => [0 => ['quantity_requested' => 400]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity_requested');

        $line = $ris->items()->firstOrFail();
        $this->assertEquals(500, (float) $line->quantity_requested);
        $this->assertEquals(500, (float) $line->quantity_issued);

        // No audit log entry, no status change
        $ris->refresh();
        $this->assertSame('approved', $ris->status);
        $this->assertSame(0, RequisitionAuditLog::where('requisition_id', $ris->id)->count());
    }

    public function test_decreasing_to_exact_issued_keeps_fully_fulfilled_status(): void
    {
        $wh   = $this->makeWarehouse('Correction WH 2', 'CRW2');
        $item = $this->stockItem($wh, 'Paper Reams', 500, 250.00);
        $cat  = $this->makeCatalogItem('Paper Reams');

        $ris = $this->createRis($cat->id, 500, 'Reduce scenario');
        $this->dispatch($ris, $item->id, 300, 'DR-2001');

        $ris->refresh();
        $this->assertSame('partially_approved', $ris->status);

        $this->correctRis($ris, [
            'items' => [0 => ['quantity_requested' => 300]],
        ])->assertOk();

        $line = $ris->items()->firstOrFail();
        $this->assertEquals(300, (float) $line->quantity_requested);
        $this->assertEquals(300, (float) $line->quantity_issued);

        $ris->refresh();
        $this->assertSame('approved', $ris->status);   // now exactly fulfilled
        $this->assertEqualsWithDelta(0, $ris->totalRemaining(), 0.0001);
    }

    public function test_audit_log_records_who_old_and_new_values(): void
    {
        [$wh, $item, $catalog, $ris] = $this->completedRis();
        $user = $this->admin();

        $this->actingAs($user)
            ->putJson(route('requisitions.correct', $ris->id), $this->correctPayload($ris, [
                'office' => 'New Office',
                'items'  => [0 => ['quantity_requested' => 650]],
            ]))
            ->assertOk();

        $log = RequisitionAuditLog::where('requisition_id', $ris->id)->firstOrFail();

        $this->assertEquals($user->id, $log->user_id);
        $this->assertSame('correction', $log->action);

        $changes = $log->changed_fields;

        $this->assertSame('New Office', $changes['office']['new']);
        $line = $ris->items()->firstOrFail();
        $this->assertSame('500', (string) $changes["items.{$line->id}.quantity_requested"]['old']);
        $this->assertSame('650', (string) $changes["items.{$line->id}.quantity_requested"]['new']);
        $this->assertSame('500', (string) $changes['total_requested']['old']);
        $this->assertSame('650', (string) $changes['total_requested']['new']);
        $this->assertSame('approved', $changes['status']['old']);
        $this->assertSame('partially_approved', $changes['status']['new']);
    }

    public function test_audit_log_records_item_change_on_undispatched_line(): void
    {
        $wh     = $this->makeWarehouse('Correction WH 3', 'CRW3');
        $this->stockItem($wh, 'Notebooks', 300, 50.00);
        $catA   = $this->makeCatalogItem('Notebooks');
        $catB   = $this->makeCatalogItem('Ballpens');

        $ris = $this->createRis($catA->id, 100, 'Item swap');

        $line = $ris->items()->firstOrFail();
        $this->assertSame('Notebooks', $line->description);
        $this->assertEquals(0, (float) $line->quantity_issued);

        $this->correctRis($ris, [
            'items' => [0 => ['catalog_item_id' => $catB->id]],
        ])->assertOk();

        $line->refresh();
        $this->assertSame('Ballpens', $line->description);
        $this->assertEquals($catB->id, $line->catalog_item_id);

        $log = RequisitionAuditLog::where('requisition_id', $ris->id)->firstOrFail();
        $this->assertArrayHasKey("items.{$line->id}.item", $log->changed_fields);
        $this->assertSame('Notebooks', $log->changed_fields["items.{$line->id}.item"]['old']);
        $this->assertSame('Ballpens', $log->changed_fields["items.{$line->id}.item"]['new']);
    }

    public function test_item_change_is_blocked_on_dispatched_line(): void
    {
        [$wh, $item, $catalog, $ris] = $this->completedRis();
        $catB = $this->makeCatalogItem('Toner Cartridge');
        $line = $ris->items()->firstOrFail();

        $this->correctRis($ris, [
            'items' => [0 => ['catalog_item_id' => $catB->id]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.catalog_item_id');

        $line->refresh();
        $this->assertSame('Gallon Water', $line->description);
        $this->assertEquals($catalog->id, $line->catalog_item_id);
    }

    public function test_header_fields_are_updated_and_logged(): void
    {
        [, , , $ris] = $this->completedRis();

        $this->correctRis($ris, [
            'purpose' => 'Corrected purpose text',
            'office'  => 'Corrected Office',
        ])->assertOk();

        $ris->refresh();
        $this->assertSame('Corrected purpose text', $ris->purpose);
        $this->assertSame('Corrected Office', $ris->office);

        $log = RequisitionAuditLog::where('requisition_id', $ris->id)->firstOrFail();
        $this->assertSame('Corrected purpose text', $log->changed_fields['purpose']['new']);
        $this->assertSame('Corrected Office', $log->changed_fields['office']['new']);
    }

    public function test_no_audit_entry_when_nothing_changed(): void
    {
        [, , , $ris] = $this->completedRis();

        $this->correctRis($ris)->assertOk();

        $this->assertSame(0, RequisitionAuditLog::where('requisition_id', $ris->id)->count());
        $ris->refresh();
        $this->assertSame('approved', $ris->status);
    }

    public function test_correction_data_endpoint_returns_header_lines_and_totals(): void
    {
        [$wh, $item, $catalog, $ris] = $this->completedRis();
        $line = $ris->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->getJson(route('requisitions.correction_data', $ris->id))
            ->assertOk()
            ->assertJsonPath('ris_number', $ris->ris_number)
            ->assertJsonPath('is_completed', true)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('totals.requested', 500)
            ->assertJsonPath('totals.issued', 500)
            ->assertJsonPath('totals.remaining', 0)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $line->id)
            ->assertJsonPath('items.0.quantity_issued', 500)
            ->assertJsonPath('items.0.locked', true);
    }

    public function test_non_write_roles_cannot_correct_or_view_history(): void
    {
        [, , , $ris] = $this->completedRis();

        $this->actingAs($this->staff())
            ->getJson(route('requisitions.correction_data', $ris->id))
            ->assertStatus(403);

        $this->actingAs($this->staff())
            ->putJson(route('requisitions.correct', $ris->id), $this->correctPayload($ris))
            ->assertStatus(403);
    }

    public function test_audit_log_route_has_been_removed(): void
    {
        [, , , $ris] = $this->completedRis();

        $this->expectException(\Symfony\Component\Routing\Exception\RouteNotFoundException::class);
        route('requisitions.audit_log', $ris->id);
    }
}
