<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Condition status is now Good/Damaged only. Legacy auto-generated
     * 'partial' values (fulfilment-based, not physical condition) are
     * normalized to 'good' so the breakdown shows Good everywhere.
     */
    public function up(): void
    {
        DB::table('deliveries')
            ->where('condition_status', 'partial')
            ->update(['condition_status' => 'good']);

        DB::table('delivery_items')
            ->where('condition', 'partial')
            ->update(['condition' => 'good']);
    }

    public function down(): void
    {
        // No rollback: the original 'partial' distinction is intentionally
        // discarded (fulfilment is tracked via qty_delivered/status).
    }
};
