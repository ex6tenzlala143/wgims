<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Stock-specific data (unit cost, ENGAS cost, expiration date, DR number)
     * belongs ONLY on requisition_dispatch_items, never on requisition_items.
     * 
     * A requisition_items row represents a REQUEST (description-level, catalog-based).
     * Actual stock allocation happens when dispatch records are created.
     * 
     * These columns were added in the initial schema and migration 2026_08_11_000030,
     * but became obsolete when the requisition_dispatch_items table was introduced
     * in migration 2026_08_11_000060.
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn([
                'unit_cost',
                'engas_unit_cost',
                'expiration_date',
                'dr_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('stock_available');
            $table->decimal('engas_unit_cost', 15, 4)->nullable()->after('unit_cost');
            $table->date('expiration_date')->nullable()->after('engas_unit_cost');
            $table->string('dr_number')->nullable()->after('expiration_date');
        });
    }
};
