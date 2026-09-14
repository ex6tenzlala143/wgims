<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Old stock records carry the legacy category keys 'food' / 'non-food',
     * but the category master now uses slug keys (e.g.
     * 'welfare-goods-for-distribution-food'). The lookup in
     * Item::getCategoryLabel() misses, so inventory pages fall back to the
     * raw "Food" / "Non-food" text instead of the full category label.
     *
     * This remaps the legacy keys to their current counterparts on items and
     * subsidy lines. Quantities, stock cards and identities are untouched —
     * only the category string changes. Target keys are resolved dynamically
     * from the category master so fresh installs (which never had legacy
     * keys) are unaffected.
     */
    public function up(): void
    {
        $foodKey = DB::table('item_categories')
            ->where('label', 'Welfare Goods for Distribution (Food)')
            ->value('key');
        $nonFoodKey = DB::table('item_categories')
            ->where('label', 'Welfare Goods for Distribution (Non-Food)')
            ->value('key');

        if ($foodKey && $foodKey !== 'food') {
            DB::table('items')->where('category', 'food')->update(['category' => $foodKey]);
            DB::table('delivery_subsidy_items')->where('category', 'food')->update(['category' => $foodKey]);
        }

        if ($nonFoodKey && $nonFoodKey !== 'non-food') {
            DB::table('items')->where('category', 'non-food')->update(['category' => $nonFoodKey]);
            DB::table('delivery_subsidy_items')->where('category', 'non-food')->update(['category' => $nonFoodKey]);
        }
    }

    public function down(): void
    {
        // No rollback: the legacy keys no longer exist in the category
        // master, so reversing would re-break every label lookup.
    }
};
