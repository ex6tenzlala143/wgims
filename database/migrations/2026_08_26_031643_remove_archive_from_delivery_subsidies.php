<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove Archive functionality from WGIMS.
 *
 * Changes:
 * 1. Reset any subsidies still flagged is_archived = 1 to 0
 *    (no subsidy should remain frozen after archive is removed).
 * 2. Clear source_subsidy_status = 'archived' on items and stock_transfers
 *    (these were set by the archive action; since archive is gone, the flag
 *     is meaningless and should not show a warning badge).
 * 3. Drop the is_archived column from delivery_subsidies.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Clear any archived subsidies (unfreeze them)
        DB::table('delivery_subsidies')
            ->where('is_archived', true)
            ->update(['is_archived' => false]);

        // 2. Clear source_subsidy_status = 'archived' from items
        //    (set to null so no stale warning badge appears)
        DB::table('items')
            ->where('source_subsidy_status', 'archived')
            ->update(['source_subsidy_status' => null]);

        // 3. Clear source_subsidy_status = 'archived' from stock_transfers
        DB::table('stock_transfers')
            ->where('source_subsidy_status', 'archived')
            ->update(['source_subsidy_status' => null]);

        // 4. Drop the is_archived column
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });
    }

    public function down(): void
    {
        // Re-add the column (data cannot be restored, but schema can be reversed)
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('status');
        });
    }
};
