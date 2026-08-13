<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Surface-level coverage for the "source subsidy" trail on Items, Requisitions
 * and the Inventory Balance report: filters must scope correctly and pages must
 * render the shared FROM / RELATED TO badge for flagged records.
 */
class SubsidySourceSnapshotSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'snap_admin_' . $i,
            'name'     => 'Snap Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
        ]);
    }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    private function makeItem(
        Warehouse $wh,
        string $description,
        float $qty,
        float $cost,
        ?string $sourceStatus = null,
        ?string $ris = null,
        ?string $dr = null
    ): Item {
        return Item::create([
            'stock_number'          => null,
            'description'           => $description,
            'unit'                  => 'piece',
            'category'              => 'food',
            'account_code'          => '1040202000-01',
            'warehouse_id'          => $wh->id,
            'unit_cost'             => $cost,
            'quantity'              => $qty,
            'is_active'             => true,
            'source_subsidy_status' => $sourceStatus,
            'source_subsidy_ris'    => $ris,
            'source_subsidy_dr'     => $dr,
        ]);
    }

    public function test_items_index_filters_by_source_subsidy_status_and_renders_badge(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        $this->makeItem($wh, 'Deleted Pack',  50, 100, 'deleted',  'RIS-DEL', 'DR-DEL');
        $this->makeItem($wh, 'Archived Pack', 60, 200, 'archived', 'RIS-ARC', 'DR-ARC');
        $this->makeItem($wh, 'Active Pack',   70, 300);

        $this->actingAs($this->admin())
            ->get(route('items.index', ['source_subsidy_status' => 'deleted']))
            ->assertOk()
            ->assertSee('Deleted Pack')
            ->assertSee('FROM DELETED SUBSIDY')
            ->assertDontSee('Archived Pack')
            ->assertDontSee('Active Pack');

        $this->actingAs($this->admin())
            ->get(route('items.index', ['source_subsidy_status' => 'archived']))
            ->assertOk()
            ->assertSee('Archived Pack')
            ->assertSee('FROM ARCHIVED SUBSIDY')
            ->assertDontSee('Deleted Pack');

        // Default (no filter) shows every item; only flagged ones carry a badge.
        $html = $this->actingAs($this->admin())
            ->get(route('items.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Deleted Pack', $html);
        $this->assertStringContainsString('Active Pack', $html);
        $this->assertStringContainsString('FROM DELETED SUBSIDY', $html);
        $this->assertStringContainsString('FROM ARCHIVED SUBSIDY', $html);
    }

    public function test_items_show_renders_source_subsidy_row(): void
    {
        $wh   = $this->makeWarehouse('Warehouse A', 'WHA');
        $item = $this->makeItem($wh, 'Traced Pack', 50, 100, 'deleted', 'RIS-DEL', 'DR-DEL');

        $this->actingAs($this->admin())
            ->get(route('items.show', $item))
            ->assertOk()
            ->assertSee('Source Subsidy')
            ->assertSee('FROM DELETED SUBSIDY')
            ->assertSee('RIS-DEL')
            ->assertSee('DR-DEL');
    }

    public function test_requisitions_index_filters_related_to_deleted_subsidy(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');
        $this->makeItem($wh, 'Flagged Pack', 50, 100, 'deleted', 'RIS-DEL', 'DR-DEL');

        $category = ItemCategory::firstOrCreate(
            ['key' => 'cat_subsidy_surface'],
            ['label' => 'Test', 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
        );
        $catalog = ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name'             => 'Flagged Pack',
            'account_code'     => '50101010',
            'is_active'        => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('requisitions.store'), [
                'purpose'        => 'Surface test',
                'date_requested' => '2026-08-01',
                'items'          => [
                    ['catalog_item_id' => $catalog->id, 'quantity_requested' => 10],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('requisitions.index'));

        $ris = Requisition::latest('id')->firstOrFail();

        // Pin the requested line to the flagged stock record (as a dispatch would).
        $ri = RequisitionItem::where('requisition_id', $ris->id)->firstOrFail();
        $ri->update(['item_id' => Item::where('description', 'Flagged Pack')->firstOrFail()->id]);

        // The flag is queryable and rendered on the index badge + show page.
        $this->actingAs($this->admin())
            ->get(route('requisitions.index', ['related_to_deleted_subsidy' => 'yes']))
            ->assertOk()
            ->assertSee($ris->ris_number)
            ->assertSee('RELATED TO DELETED SUBSIDY');

        $this->actingAs($this->admin())
            ->get(route('requisitions.index', ['related_to_deleted_subsidy' => 'no']))
            ->assertOk()
            ->assertDontSee($ris->ris_number);

        $this->actingAs($this->admin())
            ->get(route('requisitions.show', $ris))
            ->assertOk()
            ->assertSee('RELATED TO DELETED SUBSIDY');
    }

    public function test_inventory_balance_filters_by_source_and_renders_badge(): void
    {
        $wh = $this->makeWarehouse('Warehouse A', 'WHA');

        $this->makeItem($wh, 'Deleted Pack',  50, 100, 'deleted',  'RIS-DEL', 'DR-DEL');
        $this->makeItem($wh, 'Clean Pack',    70, 300);

        $this->actingAs($this->admin())
            ->get(route('inventory_balance_report', ['source_subsidy_status' => 'deleted']))
            ->assertOk()
            ->assertSee('Source: Deleted Subsidy')
            ->assertSee('FROM DELETED SUBSIDY')
            ->assertSee('>50.00<', false)   // Deleted Pack qty row present
            ->assertDontSee('>70.00<', false); // Clean Pack row excluded from results

        $this->actingAs($this->admin())
            ->get(route('inventory_balance_report'))
            ->assertOk()
            ->assertSee('Clean Pack')
            ->assertSee('Deleted Pack');
    }
}
