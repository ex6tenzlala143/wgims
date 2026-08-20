<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Workflow change: Unit Cost and Warehouse are no longer collected when a
     * delivery/subsidy is created. They are only required at DISPATCH time,
     * when the dispatcher picks the destination warehouse and unit cost per
     * line item. This makes the relevant columns nullable and stores the item
     * identity fields (description/unit/category) so lines can still be shown
     * and resolved before a dispatch has occurred.
     */
    public function up(): void
    {
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
        });

        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            // Legacy constraint name from the old schema — it only exists on
            // MySQL, and dropping a foreign key BY NAME is not supported on
            // SQLite (which generates its own constraint names). Skip it there.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign('purchase_order_items_item_id_foreign');
            }
            $table->unsignedBigInteger('item_id')->nullable()->change();
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();

            $table->decimal('unit_cost', 15, 2)->nullable()->change();
            $table->decimal('amount', 15, 2)->nullable()->change();

            $table->string('description')->nullable();
            $table->string('unit')->nullable();
            $table->string('category')->nullable();
        });

        // Back-fill description/unit/category from the linked item for existing rows
        DB::table('delivery_subsidy_items')
            ->whereNotNull('item_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $item = DB::table('items')->where('id', $row->item_id)->first();
                    if ($item) {
                        DB::table('delivery_subsidy_items')->where('id', $row->id)->update([
                            'description' => $item->description,
                            'unit'        => $item->unit,
                            'category'    => $item->category,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->dropColumn(['description', 'unit', 'category']);
            $table->decimal('amount', 15, 2)->change();
            $table->decimal('unit_cost', 15, 2)->change();
            // Only drop the foreign key constraint, MySQL will handle the index
            DB::statement('ALTER TABLE delivery_subsidy_items DROP FOREIGN KEY IF EXISTS delivery_subsidy_items_item_id_foreign');
            $table->unsignedBigInteger('item_id')->change();
            $table->foreign('item_id', 'purchase_order_items_item_id_foreign')->references('id')->on('items')->cascadeOnDelete();
        });

        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->unsignedBigInteger('warehouse_id')->change();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });
    }
};
