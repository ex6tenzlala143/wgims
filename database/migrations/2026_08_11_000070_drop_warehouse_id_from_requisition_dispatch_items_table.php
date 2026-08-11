<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The warehouse of a dispatch is fully determined by the exact stock record
     * it references (requisition_dispatch_items.item_id -> items.warehouse_id).
     * The duplicate warehouse_id column is removed to keep a single source of
     * truth and prevent a dispatch's warehouse from ever disagreeing with its
     * stock record.
     */
    public function up(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('item_id');
        });

        // Restore the derived value from each dispatch's exact stock record so a
        // rollback returns the data to its previous (redundant but populated) shape.
        DB::table('requisition_dispatch_items')
            ->leftJoin('items', 'items.id', '=', 'requisition_dispatch_items.item_id')
            ->whereNull('requisition_dispatch_items.warehouse_id')
            ->update(['requisition_dispatch_items.warehouse_id' => DB::raw('items.warehouse_id')]);

        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });
    }
};
