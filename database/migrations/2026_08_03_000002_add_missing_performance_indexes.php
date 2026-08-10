<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional performance indexes (August 2026).
 *
 * Covers:
 *  - delivery_subsidies: status filter on index page + dashboard unliquidated query
 *  - delivery_subsidy_items: loaded per delivery on every show/delivery page
 *  - deliveries: loaded per subsidy on show/delivery pages
 *  - stock_transfers: status + warehouse filters on index page
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            // Index for non-admin index query: WHERE (warehouse_id IN (...) OR warehouse_id IS NULL) AND status = ?
            $table->index('status', 'ds_status');
        });

        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            // Used in storeDelivery() pre-load and show() eager load
            $table->index('delivery_subsidy_id', 'dsi_ds_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            // Used in show/delivery views
            $table->index('delivery_subsidy_id', 'del_ds_id');
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            // Used in index filter: status + warehouse
            $table->index('status', 'st_status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidies', function (Blueprint $table) {
            $table->dropIndex('ds_status');
        });
        Schema::table('delivery_subsidy_items', function (Blueprint $table) {
            $table->dropIndex('dsi_ds_id');
        });
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropIndex('del_ds_id');
        });
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex('st_status');
        });
    }
};
