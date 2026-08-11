<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Dispatch/issuance info is stored per line item: each requested item
     * carries its own DR Number (and ENGAS unit cost).
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('engas_unit_cost', 15, 4)->nullable()->after('unit_cost');
            $table->string('dr_number')->nullable()->after('engas_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn(['dr_number', 'engas_unit_cost']);
        });
    }
};
