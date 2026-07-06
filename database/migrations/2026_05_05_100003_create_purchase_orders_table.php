<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_subsidies', function (Blueprint $table) {
            $table->id();
            $table->string('dr_number')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('created_by')->constrained('users');
            $table->date('date');
            $table->string('ris_number')->nullable();
            $table->string('place_of_delivery')->nullable();
            $table->date('date_of_delivery')->nullable();
            $table->string('date_of_expiration')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('quantity_requested', 15, 4)->default(0);
            $table->string('status')->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status'], 'po_wh_status');
        });

        Schema::create('delivery_subsidy_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_subsidy_id')->constrained('delivery_subsidies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->decimal('qty_delivered', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_subsidy_items');
        Schema::dropIfExists('delivery_subsidies');
    }
};
