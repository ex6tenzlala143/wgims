<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * - delivery_subsidies.is_archived: soft "frozen" state for a Subsidy that
     *   the admin wants removed from active operations WITHOUT deleting it.
     * - stock_transfers.source_dr_number: snapshot of the source Subsidy's DR
     *   number so a transfer keeps the full reference after the Subsidy is gone.
     */
    public function up(): void
    {
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('status');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->string('source_dr_number')->nullable()->after('source_ris_number');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('source_dr_number');
        });
    }
};