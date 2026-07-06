<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('stock_number')->nullable()->unique();
            $table->string('description');
            $table->string('ris_number')->nullable();
            $table->string('unit');
            $table->string('category'); // food, non-food
            $table->string('account_code', 50);
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('quantity_per_item')->nullable();
            $table->decimal('reorder_point', 15, 4)->default(0);
            $table->date('expiration_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
