<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Clear the confusing 'active' source_subsidy_status from items.
     * The 'active' status was used when restoring an archived subsidy,
     * but it displays as "FROM ACTIVE SUBSIDY" which looks like a warning.
     * 
     * Instead, we now clear the status (set to null) when restoring,
     * so only 'deleted' and 'archived' statuses show warning badges.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE items 
            SET source_subsidy_status = NULL 
            WHERE source_subsidy_status = 'active'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse - we don't know which items had 'active' status before
    }
};
