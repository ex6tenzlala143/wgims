<?php

namespace Tests\Feature;

use App\Models\DeliverySubsidy;
use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\StockCardEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Controlled cleanup: ITEM DELETE is normally NOT allowed. EXCEPTION: an
// ADMIN may delete an item ONLY when its originating subsidy (traced via the
// stock-lineage Subsidy ID, never by name/description/qty/stock/RIS) is
// already deleted AND no active dependency would break.
class OrphanItemDeleteTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeUser(string $role): User
    {
        self::$seq++;

        return User::create([
            'username' => 'oid_' . $role . '_' . self::$seq . '_' . uniqid(),
            'name' => 'OID ' . $role . ' ' . self::$seq,
            'password' => bcrypt('secret'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'name' => 'OID WH ' . self::$seq . uniqid(),
            'code' => 'OID' . substr(md5(uniqid()), 0, 5),
            'place' => null,
            'is_active' => true,
        ]);
    }

    private function makeCatalog(string $name): ItemCatalogItem
    {
        $category = ItemCategory::firstOrCreate(
            ['key' => 'oid_' . mb_strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) . '_' . self::$seq],
            ['label' => $name, 'account_code' => '50101010', 'is_active' => true, 'sort_order' => 0]
        );

        return ItemCatalogItem::create([
            'item_category_id' => $category->id,
            'name' => $name . ' ' . self::$seq . uniqid(),
            'account_code' => '50101010',
            'is_active' => true,
        ]);
    }

    /** A live subsidy (200 requested) with 100 dispatched → real item with lineage. */
    private function liveSubsidyWithStock(): array
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $wh = $this->makeWarehouse();
        $catalog = $this->makeCatalog('Orphan Pack');
        $supplier = Supplier::create(['name' => 'OID Supplier ' . uniqid(), 'is_active' => true]);
        $ris = 'RIS-OID-' . uniqid();

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

        return [$admin, $wh, $catalog, $ds->fresh(), $stockItem->fresh()];
    }

    /**
     * An orphaned record exactly as the subsidy-deletion flow leaves it:
     * flagged deleted, FK nulled (nullOnDelete), permanent code snapshot of a
     * subsidy row that no longer exists, no remaining references.
     */
    private function makeOrphan(Warehouse $wh, string $desc = 'Orphaned Pack'): Item
    {
        $item = Item::create([
            'stock_number' => 'ORP-' . strtoupper(substr(md5(uniqid()), 0, 6)),
            'description' => $desc . ' ' . uniqid(),
            'unit' => 'piece',
            'category' => 'food',
            'account_code' => '50101010',
            'warehouse_id' => $wh->id,
            'unit_cost' => 10,
            'quantity' => 0,
            'is_active' => true,
        ]);
        // Same shape as applySubsidySnapshot(..., 'deleted') + FK nullOnDelete.
        Item::whereKey($item->id)->update([
            'source_subsidy_id' => null,
            'source_subsidy_code' => 'SUB-009999',
            'source_subsidy_ris' => 'RIS-DELETED-999',
            'source_subsidy_dr' => 'DR-DELETED-999',
            'source_subsidy_status' => 'deleted',
        ]);

        return $item->fresh();
    }

    public function test_1_admin_cannot_delete_item_of_existing_subsidy(): void
    {
        [$admin, $wh, $catalog, $ds, $stockItem] = $this->liveSubsidyWithStock();

        $this->actingAs($admin)
            ->delete(route('items.destroy', $stockItem))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue(Item::whereKey($stockItem->id)->exists());
        $this->assertTrue(DeliverySubsidy::whereKey($ds->id)->exists());
    }

    public function test_2_admin_can_delete_orphan_of_deleted_subsidy(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $wh = $this->makeWarehouse();
        $orphan = $this->makeOrphan($wh);

        $this->actingAs($admin)
            ->delete(route('items.destroy', $orphan))
            ->assertRedirect(route('items.index'))
            ->assertSessionHas('success');

        $this->assertFalse(Item::whereKey($orphan->id)->exists());
    }

    public function test_3_non_admin_cannot_delete_orphan(): void
    {
        $manager = $this->makeUser(User::ROLE_WAREHOUSE_MANAGER);
        $wh = $this->makeWarehouse();
        $orphan = $this->makeOrphan($wh);

        // Up-front middleware rejects warehouse managers before the controller.
        $this->actingAs($manager)
            ->delete(route('items.destroy', $orphan))
            ->assertForbidden();

        $updater = $this->makeUser(User::ROLE_DELIVERY_UPDATER);
        $this->actingAs($updater)
            ->delete(route('items.destroy', $orphan))
            ->assertForbidden();

        $this->assertTrue(Item::whereKey($orphan->id)->exists());
    }

    public function test_4_crafted_api_request_for_existing_subsidy_item_is_rejected(): void
    {
        [$admin, $wh, $catalog, $ds, $stockItem] = $this->liveSubsidyWithStock();

        $this->actingAs($admin)
            ->deleteJson(route('items.destroy', $stockItem))
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertTrue(Item::whereKey($stockItem->id)->exists());
        $this->assertEquals(100, (float) $stockItem->fresh()->quantity);
    }

    public function test_5_orphan_with_active_dependencies_is_blocked(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $wh = $this->makeWarehouse();
        $orphan = $this->makeOrphan($wh);

        // A leftover stock-card movement still references this item.
        StockCardEntry::create([
            'item_id' => $orphan->id,
            'entry_date' => '2026-08-10',
            'reference' => 'DR-ORPHAN',
            'reference_type' => 'transfer_in',
            'receipt_qty' => 5,
            'receipt_unit_cost' => 10,
            'receipt_total_cost' => 50,
            'issue_qty' => 0,
            'balance_qty' => 5,
            'balance_unit_cost' => 10,
            'balance_total_cost' => 50,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('items.destroy', $orphan))
            ->assertStatus(422)
            ->assertJsonValidationErrors('item');

        $this->assertTrue(Item::whereKey($orphan->id)->exists());
        $this->assertTrue(StockCardEntry::where('item_id', $orphan->id)->exists());
    }

    public function test_6_only_the_target_orphan_is_deleted(): void
    {
        [$admin, $wh, $catalog, $ds, $liveItem] = $this->liveSubsidyWithStock();
        $orphanA = $this->makeOrphan($wh, 'Orphan A');
        $orphanB = $this->makeOrphan($wh, 'Orphan B');

        $subsidyCount = DeliverySubsidy::count();
        $deliveryCount = $ds->deliveries()->count();
        $liveQty = (float) $liveItem->quantity;

        $this->actingAs($admin)
            ->delete(route('items.destroy', $orphanA))
            ->assertRedirect(route('items.index'));

        $this->assertFalse(Item::whereKey($orphanA->id)->exists());
        $this->assertTrue(Item::whereKey($orphanB->id)->exists());
        $this->assertTrue(Item::whereKey($liveItem->id)->exists());
        $this->assertEquals($liveQty, (float) $liveItem->fresh()->quantity);
        $this->assertSame($subsidyCount, DeliverySubsidy::count());
        $this->assertSame($deliveryCount, $ds->fresh()->deliveries()->count());
        $this->assertTrue(DeliverySubsidy::whereKey($ds->id)->exists());
    }

    public function test_ui_shows_delete_only_for_orphans_to_admins(): void
    {
        [$admin, $wh, $catalog, $ds, $liveItem] = $this->liveSubsidyWithStock();
        $orphan = $this->makeOrphan($wh);
        $manager = $this->makeUser(User::ROLE_WAREHOUSE_MANAGER);

        // Admin on orphan: button + warning visible.
        $this->actingAs($admin)->get(route('items.show', $orphan))
            ->assertOk()
            ->assertSee('Delete Orphaned Item', false)
            ->assertSee('originated from a deleted subsidy', false);

        // Admin on live-subsidy item: no delete UI.
        $this->actingAs($admin)->get(route('items.show', $liveItem))
            ->assertOk()
            ->assertDontSee('Delete Orphaned Item', false);

        // Non-admin on orphan: no delete UI.
        $this->actingAs($manager)->get(route('items.show', $orphan))
            ->assertOk()
            ->assertDontSee('Delete Orphaned Item', false);
    }
}
