<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sync existing item catalog items to inherit account codes from their parent categories
        // (correlated subquery form, no table alias — runs on both MySQL and SQLite)
        DB::statement('
            UPDATE item_catalog_items
            SET account_code = (
                SELECT cat.account_code
                FROM item_categories cat
                WHERE cat.id = item_catalog_items.item_category_id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse action needed - account codes remain as they were set
    }
};
