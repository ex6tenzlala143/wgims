<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery confirmation for RIS dispatches (delivery updater role).
     *
     * Lets a delivery updater (or admin/warehouse manager) record that an
     * issued dispatch line was physically delivered to the requesting party:
     * when it was delivered, who confirmed it, and optional notes.
     * Purely additive — no existing columns touched.
     */
    public function up(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('reservation_item_id');
            $table->foreignId('delivered_by')->nullable()->after('delivered_at')
                ->constrained('users')->nullOnDelete();
            $table->text('delivery_notes')->nullable()->after('delivered_by');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->dropForeign(['delivered_by']);
            $table->dropColumn(['delivered_at', 'delivered_by', 'delivery_notes']);
        });
    }
};
