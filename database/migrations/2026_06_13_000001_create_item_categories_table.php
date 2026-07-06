<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. 'food', 'non-food'
            $table->string('label');                  // e.g. 'Welfare Goods for Distribution (FOOD)'
            $table->string('account_code', 50);       // e.g. '1040202000-01'
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the two existing built-in categories so nothing breaks
        DB::table('item_categories')->insert([
            [
                'key'          => 'food',
                'label'        => 'Welfare Goods for Distribution (FOOD)',
                'account_code' => '1040202000-01',
                'is_active'    => true,
                'sort_order'   => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'key'          => 'non-food',
                'label'        => 'Welfare Goods for Distribution (Non-Food)',
                'account_code' => '1040202000-02',
                'is_active'    => true,
                'sort_order'   => 2,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
