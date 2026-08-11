<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Receipt numbers are now tracked per dispatched item (each line in a
 * shipment may carry its own DR#). The column is nullable so existing delivery
 * item records remain valid; only new dispatches require a per-item DR#.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->string('dr_number')->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->dropColumn('dr_number');
        });
    }
};
