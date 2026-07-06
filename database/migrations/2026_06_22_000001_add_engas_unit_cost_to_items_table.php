<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Engas unit cost — set by admin, separate from the standard unit_cost
            $table->decimal('engas_unit_cost', 15, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('engas_unit_cost');
        });
    }
};
