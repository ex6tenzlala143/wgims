<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_subsidy_id')->constrained('delivery_subsidies')->cascadeOnDelete();
            $table->string('dr_number')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->date('delivery_date');
            $table->string('batch_number')->nullable();
            $table->string('condition_status')->default('good');
            $table->decimal('quantity_delivered', 15, 4)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('delivery_subsidy_item_id')->constrained('delivery_subsidy_items')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('quantity_delivered', 15, 4)->default(0);
            $table->string('condition')->default('good');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
        Schema::dropIfExists('deliveries');
    }
};
