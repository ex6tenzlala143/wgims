<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes identified during code audit (May 2026).
 *
 * Missing indexes cause full-table scans on every notification poll (every 30s),
 * every stock card lookup, and every items search — all high-frequency queries.
 */
return new class extends Migration {
    public function up(): void
    {
        // system_notifications: getUnread() polls every 30s per logged-in user
        // Queries: WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read', 'created_at'], 'notif_user_unread_date');
        });

        // stock_card_entries: loaded on every stock card view and item history page
        // Queries: WHERE item_id = ? ORDER BY entry_date, id
        Schema::table('stock_card_entries', function (Blueprint $table) {
            $table->index(['item_id', 'entry_date', 'id'], 'sce_item_date_id');
            // Also used in delete queries: WHERE reference_type = ? AND reference_id = ?
            $table->index(['reference_type', 'reference_id'], 'sce_ref_type_id');
        });

        // items: searched by description/stock_number/ris_number on every items list page
        Schema::table('items', function (Blueprint $table) {
            $table->index(['warehouse_id', 'is_active', 'category'], 'items_wh_active_cat');
        });

        // requisitions: filtered by status on dashboard and reports
        Schema::table('requisitions', function (Blueprint $table) {
            $table->index(['warehouse_id', 'status'], 'req_wh_status');
            $table->index(['status', 'date_approved'], 'req_status_date');
        });

    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropIndex('notif_user_unread_date');
        });
        Schema::table('stock_card_entries', function (Blueprint $table) {
            $table->dropIndex('sce_item_date_id');
            $table->dropIndex('sce_ref_type_id');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_wh_active_cat');
        });
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropIndex('req_wh_status');
            $table->dropIndex('req_status_date');
        });
    }
};
