<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanTestData extends Command
{
    protected $signature = 'test:clean {--force : Skip confirmation}';
    protected $description = 'Clean all test/sample data from the system while preserving structure';

    public function handle()
    {
        if (!$this->option('force')) {
            $this->warn('⚠️  This will DELETE ALL test data from the database!');
            $this->warn('This includes: Items, Suppliers, Deliveries, Requisitions, Stock Transfers, etc.');
            $this->newLine();
            $this->info('PRESERVED: Users, Warehouses, System Structure');
            $this->newLine();
            
            if (!$this->confirm('Do you want to proceed?')) {
                $this->info('Cleanup cancelled.');
                return 0;
            }
        }

        $this->newLine();
        $this->info('📊 Analyzing test data...');
        $this->newLine();

        $stats = $this->analyzeData();
        $this->displayStats($stats);

        if (!$this->option('force')) {
            $this->newLine();
            if (!$this->confirm('Proceed with deletion?')) {
                $this->info('Cleanup cancelled.');
                return 0;
            }
        }

        $this->performCleanup($stats);

        return 0;
    }

    protected function analyzeData(): array
    {
        $stats = [];

        $tables = [
            'stock_card_entries' => 'Stock Card Entries',
            'requisition_dispatch_items' => 'Requisition Dispatch Items',
            'requisition_items' => 'Requisition Items',
            'requisition_audit_logs' => 'Requisition Audit Logs',
            'requisitions' => 'Requisitions (RIS)',
            'stock_transfer_items' => 'Stock Transfer Items',
            'stock_transfer_audit_logs' => 'Stock Transfer Audit Logs',
            'stock_transfers' => 'Stock Transfers',
            'delivery_subsidy_items' => 'Delivery Subsidy Items',
            'delivery_subsidy_audit_logs' => 'Delivery Subsidy Audit Logs',
            'delivery_subsidies' => 'Delivery Subsidies',
            'delivery_items' => 'Delivery Items',
            'deliveries' => 'Deliveries',
            'items' => 'Items/Inventory',
            'suppliers' => 'Suppliers',
            'system_notifications' => 'System Notifications',
            'report_snapshots' => 'Report Snapshots',
        ];

        foreach ($tables as $table => $label) {
            if (Schema::hasTable($table)) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $stats[$table] = [
                        'label' => $label,
                        'count' => $count,
                    ];
                }
            }
        }

        return $stats;
    }

    protected function displayStats(array $stats): void
    {
        if (empty($stats)) {
            $this->info('✅ No test data found - database is already clean!');
            return;
        }

        $this->table(
            ['Data Type', 'Records to Delete'],
            collect($stats)->map(function ($data, $table) {
                return [$data['label'], $data['count']];
            })->toArray()
        );

        $total = collect($stats)->sum('count');
        $this->newLine();
        $this->warn("Total records to delete: {$total}");
    }

    protected function performCleanup(array $stats): void
    {
        if (empty($stats)) {
            return;
        }

        $this->newLine();
        $this->info('🗑️  Starting cleanup...');
        $this->newLine();

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Order matters - delete children before parents
            $this->cleanTable('stock_card_entries');
            $this->cleanTable('requisition_dispatch_items');
            $this->cleanTable('requisition_items');
            $this->cleanTable('requisition_audit_logs');
            $this->cleanTable('requisitions');
            
            $this->cleanTable('stock_transfer_items');
            $this->cleanTable('stock_transfer_audit_logs');
            $this->cleanTable('stock_transfers');
            
            $this->cleanTable('delivery_subsidy_items');
            $this->cleanTable('delivery_subsidy_audit_logs');
            $this->cleanTable('delivery_subsidies');
            
            $this->cleanTable('delivery_items');
            $this->cleanTable('deliveries');
            
            $this->cleanTable('items');
            $this->cleanTable('suppliers');
            
            $this->cleanTable('system_notifications');
            $this->cleanTable('report_snapshots');

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->newLine();
            $this->info('✅ Cleanup completed successfully!');
            $this->newLine();
            $this->info('🔒 PRESERVED:');
            $this->line('   ✓ All users and accounts');
            $this->line('   ✓ All warehouses');
            $this->line('   ✓ Item categories (system reference data)');
            $this->line('   ✓ Database structure and migrations');
            $this->line('   ✓ Application code and configuration');

        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function cleanTable(string $table): void
    {
        if (Schema::hasTable($table)) {
            $count = DB::table($table)->count();
            if ($count > 0) {
                DB::table($table)->truncate();
                $this->line("   ✓ Cleaned {$table}: {$count} records removed");
            }
        }
    }
}
