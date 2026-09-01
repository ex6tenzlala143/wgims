<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reservation_number', 20)->nullable()->unique()->after('id');

            // Make the legacy single-item columns nullable so existing rows survive
            // and new multi-item reservations can leave them null.
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();
            $table->unsignedBigInteger('item_id')->nullable()->change();
            $table->decimal('reserved_quantity', 15, 4)->nullable()->change();
            $table->decimal('allocated_quantity', 15, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('reservation_number');
            $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
            $table->unsignedBigInteger('item_id')->nullable(false)->change();
            $table->decimal('reserved_quantity', 15, 4)->default(0)->nullable(false)->change();
            $table->decimal('allocated_quantity', 15, 4)->default(0)->nullable(false)->change();
        });
    }
};
