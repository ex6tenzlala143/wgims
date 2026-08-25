<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a named index on requisition_items.warehouse_id.
 *
 * The column was added in 2026_08_11_000010 with a foreign key constraint
 * (constrainedForeignId) but no explicit index name.  MySQL creates an implicit
 * index for every FK, but having a named index makes it visible in SHOW INDEX,
 * EXPLAIN output, and migration rollbacks, and allows the query planner to use
 * it reliably for warehouse-scoped queries on the RIS list and approval pages.
 *
 * Safe to run on existing databases — addIndex() is a no-op if the index
 * already exists under the same name, and the down() simply drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            // Guard: MySQL may have already created an implicit index for the FK;
            // only add if the named index does not exist yet.
            $indexes = collect(\Illuminate\Support\Facades\DB::select(
                "SHOW INDEX FROM requisition_items WHERE Key_name = 'ri_warehouse_id'"
            ));

            if ($indexes->isEmpty()) {
                $table->index('warehouse_id', 'ri_warehouse_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropIndex('ri_warehouse_id');
        });
    }
};
