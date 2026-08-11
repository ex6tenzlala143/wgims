<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Master list of item names configured per item category. Each item name
     * carries its own account code so the New Delivery/Subsidy form can offer
     * a searchable dropdown of pre-defined item names (description + account
     * code) instead of free-typed descriptions.
     */
    public function up(): void
    {
        Schema::create('item_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_category_id')->constrained('item_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('account_code', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['item_category_id', 'name'], 'item_catalog_category_name_unique');
            $table->index('account_code');
        });

        // Link each subsidy line to the catalog item that produced it and keep
        // a snapshot of its account code so it survives even if the item is
        // later resolved into a real inventory Item.
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->nullable()->after('category')
                ->constrained('item_catalog_items')->nullOnDelete();
            $table->string('account_code', 50)->nullable()->after('catalog_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
            $table->dropColumn(['catalog_item_id', 'account_code']);
        });

        Schema::dropIfExists('item_catalog_items');
    }
};
