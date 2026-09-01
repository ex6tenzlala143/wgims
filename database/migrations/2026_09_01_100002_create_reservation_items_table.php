<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->decimal('reserved_quantity', 15, 4)->default(0);
            $table->decimal('deployed_quantity', 15, 4)->default(0);

            $table->string('status', 30)->default('ACTIVE');

            // Cost/identity snapshots captured at reservation time
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('engas_unit_cost', 15, 4)->nullable();
            $table->date('expiration_date')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['reservation_id']);
            $table->index(['item_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_items');
    }
};
