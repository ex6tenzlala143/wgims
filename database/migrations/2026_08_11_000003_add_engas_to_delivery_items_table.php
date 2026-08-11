<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->decimal('engas_unit_cost', 15, 2)->nullable()->after('unit_cost');
            $table->decimal('engas_total_cost', 15, 2)->nullable()->after('engas_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn(['engas_unit_cost', 'engas_total_cost']);
        });
    }
};
