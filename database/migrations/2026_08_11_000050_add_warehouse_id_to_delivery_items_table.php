<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            // Per-dispatch warehouse — each dispatch (delivery item) permanently
            // records which warehouse its stock was delivered into. This lets a
            // subsidy line be partially dispatched to several warehouses without
            // older dispatches being overwritten by the most recent one.
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('delivery_subsidy_item_id')
                ->constrained('warehouses')
                ->nullOnDelete();
        });

        // Back-fill existing rows: copy the requested line item's warehouse_id
        // (the default destination recorded at dispatch time). Chunked to stay
        // portable across MySQL / SQLite.
        DB::table('delivery_items')
            ->whereNull('warehouse_id')
            ->orderBy('id')
            ->chunkById(500, function ($deliveryItems) {
                foreach ($deliveryItems as $deliveryItem) {
                    $parent = DB::table('delivery_subsidy_items')
                        ->where('id', $deliveryItem->delivery_subsidy_item_id)
                        ->value('warehouse_id');

                    if ($parent !== null) {
                        DB::table('delivery_items')
                            ->where('id', $deliveryItem->id)
                            ->update(['warehouse_id' => $parent]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
