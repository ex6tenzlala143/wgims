<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Composite index for Item::findOrCreateByUnitCost() called on every delivery
        // Note: description excluded to stay within MySQL's 3072-byte key limit
        Schema::table('items', function (Blueprint $table) {
            $table->index(['warehouse_id', 'unit', 'category', 'unit_cost'], 'items_wh_unit_cat_cost');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_wh_unit_cat_cost');
        });
    }
};
