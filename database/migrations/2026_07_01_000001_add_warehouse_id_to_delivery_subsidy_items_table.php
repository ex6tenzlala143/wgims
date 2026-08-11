<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            // Per-item warehouse — allows different items in the same delivery
            // to be assigned to different warehouses.
            // Nullable so existing rows without a warehouse_id still work
            // (they fall back to the parent delivery_subsidies.warehouse_id).
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('delivery_subsidy_id')
                ->constrained('warehouses')
                ->nullOnDelete();
        });

        // Back-fill existing rows: copy the parent delivery's warehouse_id
        // (portable across MySQL / SQLite — chunked to avoid loading everything).
        DB::table('delivery_subsidy_items')
            ->whereNull('warehouse_id')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $parent = DB::table('delivery_subsidies')
                        ->where('id', $item->delivery_subsidy_id)
                        ->value('warehouse_id');

                    if ($parent !== null) {
                        DB::table('delivery_subsidy_items')
                            ->where('id', $item->id)
                            ->update(['warehouse_id' => $parent]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
