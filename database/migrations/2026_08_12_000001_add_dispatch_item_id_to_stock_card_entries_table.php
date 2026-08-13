<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Link each issuance stock-card entry to the exact RequisitionDispatchItem
     * that produced it, so editing one dispatch reverses/updates exactly that
     * entry — never another dispatch of the same item.
     */
    public function up(): void
    {
        Schema::table('stock_card_entries', function (Blueprint $table) {
            $table->foreignId('dispatch_item_id')
                ->nullable()
                ->after('reference_id')
                ->constrained('requisition_dispatch_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_card_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatch_item_id');
        });
    }
};
