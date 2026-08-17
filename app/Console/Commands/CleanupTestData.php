<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupTestData extends Command
{
    protected $signature = 'data:cleanup {--dry-run : Show what would be deleted without actually deleting} {--force : Run without prompting for confirmation}';
    protected $description = 'Clean up test/sample data while preserving users, structure, and migrations';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be deleted');
            $this->newLine();
        } else {
            if (!$force) {
                $this->warn('⚠️  This will DELETE test data from the database!');
                if (!$this->confirm('Have you created a database backup?')) {
                    $this->error('Please create a backup first using: php artisan db:backup or mysqldump');
                    return 1;
                }
                if (!$this->confirm('Are you sure you want to proceed with cleanup?')) {
                    $this->info('Cleanup cancelled.');
                    return 0;
                }
            } else {
                $this->warn('⚠️  Running in force mode - proceeding with cleanup...');
            }
        }

        $this->newLine();
        $this->info('📊 Analyzing database for test/sample data...');
        $this->newLine();

        $stats = $this->analyzeData();
        
        $this->displayStats($stats);
        
        if (!$isDryRun) {
            if (!$force) {
                $this->newLine();
                if (!$this->confirm('Proceed with deletion?')) {
                    $this->info('Cleanup cancelled.');
                    return 0;
                }
            }
            
            $this->performCleanup();
        }

        $this->newLine();
        $this->info('✅ Cleanup analysis complete!');
        
        return 0;
    }

    protected function analyzeData(): array
    {
        $stats = [];

        // Tables to clean (order matters for foreign key constraints)
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
            'item_categories' => 'Item Categories',
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
        $this->info('📋 Test/Sample Data Found:');
        $this->newLine();

        if (empty($stats)) {
            $this->info('   No test data found - database is already clean!');
            return;
        }

        $this->table(
            ['Table', 'Record Count'],
            collect($stats)->map(function ($data, $table) {
                return [$data['label'], $data['count']];
            })->toArray()
        );

        $this->newLine();
        $this->info('🔒 PRESERVED (Will NOT be deleted):');
        $this->line('   ✓ All users and employee accounts');
        $this->line('   ✓ All warehouses');
        $this->line('   ✓ Database structure and migrations');
        $this->line('   ✓ Application code');
        $this->line('   ✓ User-warehouse assignments');
        $this->line('   ✓ Roles and permissions');
    }

    protected function performCleanup(): void
    {
        $this->newLine();
        $this->info('🗑️  Starting cleanup...');
        
        try {
            // Disable foreign key checks temporarily
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
            $this->cleanTable('item_categories');
            
            $this->cleanTable('suppliers');
            $this->cleanTable('system_notifications');
            $this->cleanTable('report_snapshots');

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            $this->newLine();
            $this->info('✅ All test data has been successfully removed!');
            
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
