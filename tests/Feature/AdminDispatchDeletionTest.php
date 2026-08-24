<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\RequisitionAuditLog;
use App\Models\RequisitionDispatchItem;
use App\Models\StockCardEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDispatchDeletionTest extends TestCase
{
    use RefreshDatabase;

    public static bool $forceAuditFailure = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Registered once per test run; only fires while the rollback test
        // flips the flag, simulating a crash mid-reversal.
        RequisitionAuditLog::creating(function () {
            if (self::$forceAuditFailure) {
                throw new \RuntimeException('Simulated failure during reversal.');
            }
        });
    }

    private function user(string $role): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username'  => 'dd_' . $role . '_' . $i,
            'name'      => 'DD ' . ucfirst($role) . ' ' . $i,
            'password'  => bcrypt('secret'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function adminUser(): User   { return $this->user(User::ROLE_ADMIN); }
    private function wmUser(): User      { return $this->user(User::ROLE_WAREHOUSE_MANAGER); }
    private function custodianUser(): User { return $this->user(User::ROLE_CUSTODIAN); }
    private function staffUser(): User   { return $this->user(User::ROLE_STAFF); }
    private function headUser(): User    { return $this->user(User::ROLE_HEAD); }

    private function makeWarehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['name' => $name, 'code' => $code, 'place' => null, 'is_active' => true]);
    }

    /**
     * Creates an item AND its starting receipt stock-card entry, exactly like
     * a delivery would, so running balances are always consistent.
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
            ['key' => 'dd_' . mb_strtolower(str_replace([' ', '/'], '_', $name))],
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
        $this->actingAs($this->adminUser())
            ->post(route('requisitions.store'), [
                'ris_number'     => 'RIS-DD-' . strtoupper(\Illuminate\Support\Str::random(6)) . '-' . time() . rand(100, 999),
                'purpose'        => 'Dispatch deletion test',
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

        $this->actingAs($this->adminUser())
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

        return $line->dispatchItems()
            ->where('item_id', $itemId)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function deleteDispatch(RequisitionDispatchItem $disp)
    {
        return $this->deleteJson(route('requisitions.dispatch_destroy', $disp->id));
    }

    public function test_admin_delete_restores_stock_and_recalculates_everything(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->stockItem($wh, 'Family Food Pack', 100, 950);
        $catalog = $this->makeCatalogItem('Family Food Pack');

        $ris  = $this->createRis($catalog->id, 100);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 40, 'DR-DD-1');

        $this->assertEquals(60, (float) $item->fresh()->quantity);
        $this->assertEquals('partially_approved', $ris->fresh()->status);

        $this->actingAs($this->adminUser());
        $this->deleteDispatch($disp)
            ->assertOk()
            ->assertJsonPath('redirect', route('requisitions.show', $ris->id));

        // Stock restored exactly once: 60 + 40 = 100 (not more)
        $this->assertEquals(100, (float) $item->fresh()->quantity);

        // The dispatch is gone and no longer counted as issued
        $this->assertSame(0, RequisitionDispatchItem::count());
        $this->assertEquals(0, (float) $line->fresh()->quantity_issued);

        // Requested quantity is independent and untouched
        $this->assertEquals(100, (float) $line->fresh()->quantity_requested);

        // Status recomputed from remaining dispatches
        $this->assertEquals('pending', $ris->fresh()->status);

        // Stock card: only the original receipt remains, balances replayed
        $entries = StockCardEntry::where('item_id', $item->id)->get();
        $this->assertSame(1, $entries->count());
        $receipt = $entries->first();
        $this->assertEquals('delivery', $receipt->reference_type);
        $this->assertEquals(100, (float) $receipt->balance_qty);
        $this->assertEquals(100 * 950, (float) $receipt->balance_total_cost);

        // Audit trail records who deleted what
        $log = RequisitionAuditLog::where('requisition_id', $ris->id)
            ->where('action', 'dispatch_deleted')
            ->firstOrFail();
        $this->assertEquals(40, (float) $log->changed_fields['quantity_issued']);
    }

    public function test_deletion_returns_stock_to_the_exact_record_not_a_same_name_record(): void
    {
        $wh    = $this->makeWarehouse('Warehouse A', 'WHA');
        $recA  = $this->stockItem($wh, 'Family Food Pack', 50, 950, 1); // Stock ID A
        $recB  = $this->stockItem($wh, 'Family Food Pack', 50, 980, 2); // Stock ID B
        $catalog = $this->makeCatalogItem('Family Food Pack');

        $ris = $this->createRis($catalog->id, 20);
        $disp = $this->dispatch($ris, $recA->id, 20, 'DR-DD-2');

        // Only record A was deducted by the dispatch
        $this->assertEquals(30, (float) $recA->fresh()->quantity);
        $this->assertEquals(50, (float) $recB->fresh()->quantity);

        $this->actingAs($this->adminUser());
        $this->deleteDispatch($disp)->assertOk();

        // The 20 units returned to record A (₱950), never to record B (₱980)
        $this->assertEquals(50, (float) $recA->fresh()->quantity);
        $this->assertEquals(50, (float) $recB->fresh()->quantity);

        $this->assertSame(0, StockCardEntry::where('item_id', $recA->id)->where('reference_type', 'issuance')->count());
        $this->assertSame(0, StockCardEntry::where('item_id', $recB->id)->where('reference_type', 'issuance')->count());

        $aReceipt = StockCardEntry::where('item_id', $recA->id)->firstOrFail();
        $this->assertEquals(50, (float) $aReceipt->balance_qty);
    }

    public function test_partial_delivery_delete_keeps_requested_quantity_and_restores_once(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->stockItem($wh, 'Rice', 100, 700);
        $catalog = $this->makeCatalogItem('Rice');

        $ris  = $this->createRis($catalog->id, 100);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 40, 'DR-DD-3');

        $this->assertEquals(60, (float) $item->fresh()->quantity);

        $this->actingAs($this->adminUser());
        $this->deleteDispatch($disp)->assertOk();

        // Requested stays exactly as originally filed
        $this->assertEquals(100, (float) $line->fresh()->quantity_requested);

        // Restored exactly once — 60 + 40, not 140
        $this->assertEquals(100, (float) $item->fresh()->quantity);

        // Exactly one receipt entry remains with the fully-restored balance
        $this->assertSame(1, StockCardEntry::where('item_id', $item->id)->count());
        $this->assertEquals(100, (float) StockCardEntry::where('item_id', $item->id)->firstOrFail()->balance_qty);
    }

    public function test_only_admin_can_delete_dispatched_items(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->stockItem($wh, 'Hygiene Kit', 100, 350);
        $catalog = $this->makeCatalogItem('Hygiene Kit');

        $ris  = $this->createRis($catalog->id, 40);
        $disp = $this->dispatch($ris, $item->id, 10, 'DR-DD-4');
        $url  = route('requisitions.dispatch_destroy', $disp->id);

        // Admin is allowed
        $this->actingAs($this->adminUser())->deleteJson($url)->assertOk();
        $this->assertEquals(100, (float) $item->fresh()->quantity);

        // Fresh scenario for denial checks
        $disp2 = $this->dispatch($ris, $item->id, 10, 'DR-DD-5');
        $this->assertEquals(90, (float) $item->fresh()->quantity);

        foreach ([$this->wmUser(), $this->custodianUser(), $this->staffUser(), $this->headUser()] as $roleUser) {
            $this->actingAs($roleUser)->deleteJson(route('requisitions.dispatch_destroy', $disp2->id))
                ->assertStatus(403);
            // Even direct URL access with any verb is denied server-side
            $this->actingAs($roleUser)->delete(route('requisitions.dispatch_destroy', $disp2->id))->assertStatus(403);
        }

        // Guests: JSON gets 401, plain requests are bounced to login
        auth()->logout();
        $this->deleteJson(route('requisitions.dispatch_destroy', $disp2->id))->assertStatus(401);
        $this->delete(route('requisitions.dispatch_destroy', $disp2->id))->assertRedirect(route('login'));

        // Nothing moved for any denied actor
        $this->assertEquals(90, (float) $item->fresh()->quantity);
        $this->assertModelExists($disp2);

        // Unknown dispatch id → 404 even for admin
        $this->actingAs($this->adminUser())->deleteJson(route('requisitions.dispatch_destroy', 999999))
            ->assertStatus(404);
    }

    public function test_failed_reversal_rolls_back_completely(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->stockItem($wh, 'Family Food Pack', 100, 950);
        $catalog = $this->makeCatalogItem('Family Food Pack');

        $ris  = $this->createRis($catalog->id, 100);
        $line = $ris->items()->firstOrFail();
        $disp = $this->dispatch($ris, $item->id, 40, 'DR-DD-6');

        $this->assertEquals(60, (float) $item->fresh()->quantity);

        self::$forceAuditFailure = true;
        try {
            $this->actingAs($this->adminUser());
            $this->deleteDispatch($disp)->assertStatus(500);
        } finally {
            self::$forceAuditFailure = false;
        }

        // Nothing was partially changed: stock, dispatch, card, cache, status
        $this->assertEquals(60, (float) $item->fresh()->quantity);
        $this->assertModelExists($disp);
        $this->assertEquals(40, (float) StockCardEntry::where('dispatch_item_id', $disp->id)->firstOrFail()->issue_qty);
        $this->assertEquals(40, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('partially_approved', $ris->fresh()->status);
        $this->assertSame(0, RequisitionAuditLog::where('requisition_id', $ris->id)->where('action', 'dispatch_deleted')->count());

        // The same deletion succeeds once the failure is gone
        $this->deleteDispatch($disp)->assertOk();
        $this->assertEquals(100, (float) $item->fresh()->quantity);
        $this->assertEquals('pending', $ris->fresh()->status);
    }

    public function test_deleting_one_dispatch_keeps_sibling_dispatch_intact(): void
    {
        $wh     = $this->makeWarehouse('Warehouse A', 'WHA');
        $item   = $this->stockItem($wh, 'Family Food Pack', 100, 950);
        $catalog = $this->makeCatalogItem('Family Food Pack');

        $ris  = $this->createRis($catalog->id, 50);
        $line = $ris->items()->firstOrFail();

        // Two separate partial dispatches from the same record: 20 then 10
        $disp1 = $this->dispatch($ris, $item->id, 20, 'DR-DD-7A');
        $disp2 = $this->dispatch($ris, $item->id, 10, 'DR-DD-7B');

        $this->assertEquals(70, (float) $item->fresh()->quantity);
        $this->assertEquals(30, (float) $line->fresh()->quantity_issued);

        $this->actingAs($this->adminUser());
        $this->deleteDispatch($disp1)->assertOk();

        // Only disp1 reversed: 70 + 20 = 90; disp2's effect (−10) remains
        $this->assertEquals(90, (float) $item->fresh()->quantity);
        $this->assertModelExists($disp2);
        $this->assertEquals(10, (float) $line->fresh()->quantity_issued);
        $this->assertEquals('partially_approved', $ris->fresh()->status);

        // Sibling stock-card entry untouched and linked to its own dispatch
        $sibling = StockCardEntry::where('dispatch_item_id', $disp2->id)->firstOrFail();
        $this->assertEquals(10, (float) $sibling->issue_qty);
        $this->assertEquals(90, (float) $sibling->balance_qty);

        $this->assertSame(0, StockCardEntry::where('dispatch_item_id', $disp1->id)->count());
    }
}
