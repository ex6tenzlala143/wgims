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
        DB::statement('
            UPDATE item_catalog_items ci
            INNER JOIN item_categories cat ON ci.item_category_id = cat.id
            SET ci.account_code = cat.account_code
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
