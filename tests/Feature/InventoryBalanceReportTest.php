<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $i = 0;
        $i++;

        return User::create([
            'username' => 'ib_admin_' . $i,
            'name'     => 'IB Admin ' . $i,
            'password' => bcrypt('secret'),
            'role'     => User::ROLE_ADMIN,
            'is_active' => true,
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
        ?float $engas = null,
        ?string $expiry = null
    ): Item {
        return Item::create([
            'stock_number'    => null,
            'description'     => $description,
            'unit'            => 'piece',
            'category'        => 'food',
            'account_code'    => '1040202000-01',
            'warehouse_id'    => $wh->id,
            'unit_cost'       => $cost,
            'engas_unit_cost' => $engas,
            'quantity'        => $qty,
            'expiration_date' => $expiry,
            'is_active'       => true,
        ]);
    }

    public function test_balance_keeps_variants_separate_and_shows_total_quantity(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        // Same name + warehouse, different cost / engas / expiry → each record
        // keeps its own row, and each row shows Total Qty 175.
        $this->makeItem($whA, 'Food Pack', 100, 700, 800, '2026-12-31');
        $this->makeItem($whA, 'Food Pack', 50, 800, 900, '2027-06-30');
        $this->makeItem($whA, 'Food Pack', 25, 750, 850, '2028-01-15');

        // Same name, different warehouse → stays separate.
        $this->makeItem($whB, 'Food Pack', 50, 700, 800, '2026-12-31');

        // Different name, same warehouse → stays separate.
        $this->makeItem($whA, 'Rice', 100, 50, null, null);

        $response = $this->actingAs($this->admin())
            ->get(route('inventory_balance_report'))
            ->assertOk();

        $html = $response->getContent();

        // Total Qty is shown ONCE per Item + Warehouse group — here the summary
        // line for the three Food Pack records in Warehouse A is 175.
        $this->assertEquals(1, substr_count($html, '>175<'));
        // Individual quantities remain separate rows: 100, 50, 25.
        $this->assertEquals(3, substr_count($html, '>100<')); // Food Pack row + Rice row + Rice summary
        $this->assertEquals(3, substr_count($html, '>50<'));  // row + WH B row + WH B summary
        $this->assertEquals(1, substr_count($html, '>25<'));
        // One "Total Qty" summary per group (Food Pack A, Rice A, Food Pack B).
        $this->assertEquals(3, substr_count($html, '>Total Qty<'));
        // No merge badge — every record is its own row.
        $this->assertStringNotContainsString('variants)', $html);

        // Underlying stock records are untouched — all three variants still exist.
        $this->assertEquals(3, Item::where('warehouse_id', $whA->id)->where('description', 'Food Pack')->count());
        $this->assertEquals(175, (float) Item::where('warehouse_id', $whA->id)->where('description', 'Food Pack')->sum('quantity'));
        $this->assertEquals(1, Item::where('warehouse_id', $whB->id)->where('description', 'Food Pack')->count());
    }

    public function test_balance_keeps_different_warehouses_and_items_separate(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');
        $whB = $this->makeWarehouse('Warehouse B', 'WHB');

        $this->makeItem($whA, 'Food Pack', 100, 700, 800, '2026-12-31');
        $this->makeItem($whB, 'Food Pack', 50, 800, 900, '2027-06-30');
        $this->makeItem($whA, 'Rice', 50, 60, null, null);

        $response = $this->actingAs($this->admin())
            ->get(route('inventory_balance_report'))
            ->assertOk();

        // Each item/warehouse group shows its Total Qty once. Values repeat only
        // when a group has a single record (row + its own summary).
        $html = $response->getContent();
        $this->assertEquals(2, substr_count($html, '>100<')); // Food Pack A row + summary
        $this->assertEquals(4, substr_count($html, '>50<'));  // Rice A row+summary, Food Pack B row+summary
        $this->assertEquals(3, substr_count($html, '>Total Qty<'));
        // No merge badge — every record is its own row.
        $this->assertStringNotContainsString('variants)', $html);
    }

    public function test_export_balance_builds_with_total_quantity_column(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');

        $this->makeItem($whA, 'Food Pack', 100, 700, 800, '2026-12-31');
        $this->makeItem($whA, 'Food Pack', 50, 800, 900, '2027-06-30');
        $this->makeItem($whA, 'Rice', 100, 50, null, null);

        $response = $this->actingAs($this->admin())
            ->get(route('inventory_balance_report.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Parse the xlsx to verify: Total Quantity column present, one row per
        // stock record, and total qty written once per Item + Warehouse group.
        // Parsing the xlsx requires the ext-zip (ZipArchive). Skip the deep
        // checks when it is not loaded so the test stays portable.
        if (! class_exists('ZipArchive')) {
            $this->assertNotEmpty($response->streamedContent());
            $this->markTestSkipped('ext-zip not loaded; cannot parse xlsx output.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ib_') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $headers[] = $sheet->getCell($col . '4')->getValue();
        }
        $this->assertContains('Total Quantity', $headers);

        $rows = [];
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            $desc = $sheet->getCell("D{$r}")->getValue();
            if ($desc === null || $desc === '') {
                continue;
            }
            $rows[] = ['desc' => $desc, 'qty' => (float) $sheet->getCell("F{$r}")->getValue(), 'total' => (float) $sheet->getCell("G{$r}")->getValue()];
        }

        $this->assertCount(3, $rows); // one row per stock record
        $foodPacks = collect($rows)->where('desc', 'Food Pack')->values();
        $this->assertCount(2, $foodPacks);
        // Total Quantity is written once per group — on the first Food Pack row only.
        $this->assertEquals(150.0, $foodPacks[0]['total']);
        $this->assertEquals(0.0, $foodPacks[1]['total']);
    }

    public function test_balance_reflects_quantity_changes_after_edits(): void
    {
        $whA = $this->makeWarehouse('Warehouse A', 'WHA');

        $item = $this->makeItem($whA, 'Food Pack', 100, 700, 800, '2026-12-31');
        $this->makeItem($whA, 'Food Pack', 25, 750, 850, '2028-01-15');

        $this->actingAs($this->admin())
            ->get(route('inventory_balance_report'))
            ->assertOk()
            ->assertSee('125', false);

        // Simulate stock landing on one variant (e.g. a new subsidy dispatch).
        $item->update(['quantity' => 150, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->get(route('inventory_balance_report'))
            ->assertOk()
            ->assertSee('175', false);
    }
}
