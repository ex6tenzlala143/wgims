<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Each requested item on a Requisition may now come from a different
     * warehouse, so the warehouse must be stored on the line item itself.
     *
     * - adds requisition_items.warehouse_id (backfilled from the item's warehouse)
     * - makes requisitions.warehouse_id nullable (now a legacy/backward-compat field)
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('requisition_id')
                ->constrained('warehouses');
        });

        // Backfill existing line items from their item's warehouse
        DB::table('requisition_items')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $ri) {
                $wh = DB::table('items')->where('id', $ri->item_id)->value('warehouse_id');
                DB::table('requisition_items')
                    ->where('id', $ri->id)
                    ->update(['warehouse_id' => $wh]);
            }
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable(false)->change();
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
