<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // delivery_items: index warehouse_id for multi-warehouse subsidy queries
        Schema::table('delivery_items', function (Blueprint $table) {
            if (! $this->indexExists('delivery_items', 'idx_delivery_items_warehouse')) {
                $table->index('warehouse_id', 'idx_delivery_items_warehouse');
            }
        });

        // requisition_dispatch_items: index item_id for dispatch lookups
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            if (! $this->indexExists('requisition_dispatch_items', 'idx_dispatch_items_item')) {
                $table->index('item_id', 'idx_dispatch_items_item');
            }
        });

        // stock_transfer_items: index destination_item_id for transfer chain queries
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            if (! $this->indexExists('stock_transfer_items', 'idx_transfer_items_destination')) {
                $table->index('destination_item_id', 'idx_transfer_items_destination');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropIndex('idx_delivery_items_warehouse');
        });

        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->dropIndex('idx_dispatch_items_item');
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropIndex('idx_transfer_items_destination');
        });
    }

    /**
     * Check whether an index already exists (safe to call in up() for idempotency).
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return ! empty($indexes);
    }
};
