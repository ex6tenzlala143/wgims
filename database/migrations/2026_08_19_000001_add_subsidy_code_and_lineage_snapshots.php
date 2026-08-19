<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Permanent, unique Subsidy ID in SUB-000001 format. Backfilled from
        //    the row id — MySQL auto-increment ids are never reused, so the
        //    codes can never collide with a future Subsidy either.
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->string('subsidy_code')->nullable()->after('dr_number');
        });

        $rows = DB::table('delivery_subsidies')->orderBy('id')->get(['id', 'subsidy_code']);
        foreach ($rows as $row) {
            if (! $row->subsidy_code) {
                DB::table('delivery_subsidies')
                    ->where('id', $row->id)
                    ->update(['subsidy_code' => 'SUB-' . str_pad((string) $row->id, 6, '0', STR_PAD_LEFT)]);
            }
        }

        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->unique('subsidy_code', 'delivery_subsidies_subsidy_code_unique');
        });

        // 2. Snapshot the code on items so the lineage survives subsidy deletion.
        Schema::table('items', function (Blueprint $table) {
            $table->string('source_subsidy_code')->nullable()->after('source_subsidy_id');
        });

        DB::table('items')
            ->whereNotNull('source_subsidy_id')
            ->update(['source_subsidy_code' => DB::raw('(SELECT subsidy_code FROM delivery_subsidies WHERE delivery_subsidies.id = items.source_subsidy_id)')]);

        // 3. Snapshot the code on transfers the same way.
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('source_subsidy_code')->nullable()->after('source_dr_number');
        });

        DB::table('stock_transfers')
            ->whereNotNull('delivery_subsidy_id')
            ->update(['source_subsidy_code' => DB::raw('(SELECT subsidy_code FROM delivery_subsidies WHERE delivery_subsidies.id = stock_transfers.delivery_subsidy_id)')]);

        // 4. Lineage lookup index: every item attributed to a Subsidy, per warehouse.
        Schema::table('items', function (Blueprint $table) {
            $table->index(['source_subsidy_id', 'warehouse_id'], 'items_srcsub_wh');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_srcsub_wh');
            $table->dropColumn('source_subsidy_code');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('source_subsidy_code');
        });

        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropUnique('delivery_subsidies_subsidy_code_unique');
            $table->dropColumn('subsidy_code');
        });
    }
};