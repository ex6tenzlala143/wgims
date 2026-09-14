<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional link from a stock-transfer line to the reservation line it
     * draws from. Null = the transfer uses normal (unreserved) stock.
     * Purely additive — existing transfers keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->foreignId('reservation_item_id')
                ->nullable()
                ->after('destination_item_id')
                ->constrained('reservation_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropForeign(['reservation_item_id']);
            $table->dropColumn('reservation_item_id');
        });
    }
};
