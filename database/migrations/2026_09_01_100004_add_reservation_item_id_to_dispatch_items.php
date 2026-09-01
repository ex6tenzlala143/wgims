<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->foreignId('reservation_item_id')
                ->nullable()
                ->after('created_by')
                ->constrained('reservation_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requisition_dispatch_items', function (Blueprint $table) {
            $table->dropForeign(['reservation_item_id']);
            $table->dropColumn('reservation_item_id');
        });
    }
};
