<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the intended lifecycle: an RIS line carries ONLY request-stage data
 * when created. Stock linkage, warehouse, costs, ENGAS values, expiry, DR
 * number and availability may exist only AFTER a real dispatch is recorded.
 */
class RequisitionCreationStockIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username'  => 'iso_admin_' . $i,
            'name'      => 'ISO Admin ' . $i,
            'password'  => bcrypt('secret'),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(string $name): Warehouse
    {
        return Warehouse::create([
            'name'      => $name,
            'code'      => 'ISO-' . substr(strtoupper(md5(uniqid())), 0, 6),
            'place'     => null,
            'is_active' => true,
        ]);
    }

    private function stockRecord(Warehouse $wh, string $description, string $stockNo, float $qty, float $cost, float $engas, string $expiry): Item
    {
        return Item::create([
            'stock_number'     => $stockNo,
            'description'      => $description,
            'unit'             => 'family pack',
            'category'         => 'food',
            'account_code'     => '1040202000-01',
            'warehouse_id'     => $wh->id,
            'unit_cost'        => $cost,
            'engas_unit_cost'  => $engas,
            'quantity'         => $qty,
            'expiration_date'  => $expiry,
            'is_active'        => true,
        ]);
    }

    private function catalog(string $name): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'iso_food'],
            ['label' => 'Food', 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
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
                'ris_number'     => 'RIS-ISO-' . strtoupper(\Illuminate\Support\Str::random(6)) . '-' . time() . rand(100, 999),
                'purpose'        => 'Stock isolation test',
                'date_requested' => '2026-08-24',
                'items'          => [
                    ['catalog_item_id' => $catalogItemId, 'quantity_requested' => $qty],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        return Requisition::latest('id')->firstOrFail();
    }

    public function test_creating_ris_does_not_link_stock_or_assign_issuance_data(): void
    {
        $wh   = $this->makeWarehouse('ZZIso Warehouse');
        $item = $this->stockRecord($wh, 'Family Food Pack', 'GAMC1-FOO-0001', 500, 100, 100, '2026-09-05');
        $cat  = $this->catalog('Family Food Pack');

        $ris  = $this->createRis($cat->id, 500);
        $line = $ris->items()->firstOrFail();

        // No allocation of any kind happened at creation time
        $this->assertNull($line->item_id);
        $this->assertNull($line->warehouse_id);
        $this->assertEquals(0, (float) $line->unit_cost);
        $this->assertFalse((bool) $line->stock_available);
        $this->assertNull($line->engas_unit_cost);
        $this->assertNull($line->expiration_date);
        $this->assertNull($line->dr_number);
        $this->assertEquals(0, (int) $line->quantity_issued);
        $this->assertEquals(500, (float) $line->quantity_requested);

        // The physical stock record is untouched by mere creation
        $this->assertEquals(500, (float) $item->fresh()->quantity);

        // Unit (request-descriptive) and description/account code are filled
        $this->assertSame('family pack', $line->unit);
        $this->assertSame('Family Food Pack', $line->description);
    }

    public function test_new_ris_details_page_shows_no_dispatch_data(): void
    {
        $wh   = $this->makeWarehouse('ZZIso Warehouse');
        $this->stockRecord($wh, 'Family Food Pack', 'GAMC1-FOO-0001', 500, 100, 100, '2026-09-05');
        $cat  = $this->catalog('Family Food Pack');

        $ris = $this->createRis($cat->id, 500);

        $response = $this->actingAs($this->admin())
            ->get(route('requisitions.show', $ris->id))
            ->assertOk();

        $response->assertSee('Requested Items');
        $response->assertSee('No items issued yet.');

        // None of the stock/dispatch-specific values may leak onto the page
        $response->assertDontSee('GAMC1-FOO-0001');
        $response->assertDontSee('ZZIso Warehouse');
        $response->assertDontSee('Sep 05, 2026');
    }

    public function test_process_issuance_populates_stock_and_dispatch_details(): void
    {
        $wh   = $this->makeWarehouse('ZZIso Warehouse');
        $item = $this->stockRecord($wh, 'Family Food Pack', 'GAMC1-FOO-0001', 500, 100, 100, '2026-09-05');
        $cat  = $this->catalog('Family Food Pack');

        $ris  = $this->createRis($cat->id, 500);
        $line = $ris->items()->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('requisitions.process_approval', $ris->id), [
                'approved_by_name' => 'Test Admin',
                'issued_by_name'   => 'Test Admin',
                'items'            => [
                    $line->id => [
                        'warehouse_id'    => $wh->id,
                        'item_id'         => $item->id,
                        'quantity_issued' => 200,
                        'dr_number'       => 'DR-ISO-1',
                        'unit_cost'       => 100,
                        'engas_unit_cost' => 100,
                        'expiration_date' => '2026-09-05',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $line = $line->fresh();

        // The line points at the exact issued record — but cost data lives
        // exclusively on the dispatch row, not on the RI.
        $this->assertSame($item->id, (int) $line->item_id);
        $this->assertEquals(200, (float) $line->quantity_issued);
        $this->assertEquals(0, (float) $line->unit_cost);
        $this->assertNull($line->engas_unit_cost);
        $this->assertNull($line->expiration_date);
        $this->assertNull($line->dr_number);
        $this->assertFalse((bool) $line->stock_available); // 300 left < 500 requested
        $this->assertEquals('partially_approved', $ris->fresh()->status);

        // The dispatch row carries all issuance-specific data
        $dispatch = $line->dispatchItems()->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals(100, (float) $dispatch->unit_cost);
        $this->assertEquals(100, (float) $dispatch->engas_unit_cost);
        $this->assertSame('2026-09-05', $dispatch->expiration_date?->toDateString());
        $this->assertSame('DR-ISO-1', $dispatch->dr_number);

        // Stock was actually deducted from the exact record
        $this->assertEquals(300, (float) $item->fresh()->quantity);

        // Details now render on the details page (from dispatch items, not RI)
        $this->actingAs($this->admin())
            ->get(route('requisitions.show', $ris->id))
            ->assertOk()
            ->assertSee('GAMC1-FOO-0001')
            ->assertSee('DR-ISO-1')
            ->assertSee('ZZIso Warehouse');
    }

    public function test_editing_an_undispatched_line_keeps_it_unlinked_and_clears_stale_cache(): void
    {
        $wh   = $this->makeWarehouse('ZZIso Warehouse');
        $this->stockRecord($wh, 'Family Food Pack', 'GAMC1-FOO-0001', 500, 100, 100, '2026-09-05');
        $cat  = $this->catalog('Family Food Pack');

        $ris  = $this->createRis($cat->id, 500);
        $line = $ris->items()->firstOrFail();

        // Simulate a legacy row carrying stale pre-dispatch caches
        $line->update([
            'item_id'         => Item::where('stock_number', 'GAMC1-FOO-0001')->firstOrFail()->id,
            'unit_cost'       => 55,
            'stock_available' => true,
            'dr_number'       => 'STALE-DR',
        ]);

        $this->actingAs($this->admin())
            ->put(route('requisitions.update', $ris->id), [
                'purpose'        => 'Stock isolation test',
                'date_requested' => '2026-08-24',
                'status'         => 'pending',
                'items'          => [
                    [
                        'id'                => $line->id,
                        'catalog_item_id'   => $cat->id,
                        'quantity_requested' => 450,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.show', $ris->id));

        $line = $line->fresh();

        $this->assertEquals(450, (float) $line->quantity_requested);
        $this->assertNull($line->item_id);
        $this->assertNull($line->warehouse_id);
        $this->assertNull($line->dr_number);
        $this->assertNull($line->engas_unit_cost);
        $this->assertNull($line->expiration_date);
        $this->assertFalse((bool) $line->stock_available);
        $this->assertEquals(0, (float) $line->unit_cost);
    }
}
