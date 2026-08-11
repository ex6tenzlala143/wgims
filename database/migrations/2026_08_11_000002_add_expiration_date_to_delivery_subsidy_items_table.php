<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The New Delivery/Subsidy form already collects an expiry date per line item,
 * but it was never persisted on the subsidy line. Store it so the Edit modal
 * can round-trip the exact saved values.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->dropColumn('expiration_date');
        });
    }
};
