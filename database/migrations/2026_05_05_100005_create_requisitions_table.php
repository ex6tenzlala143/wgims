<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('ris_number')->unique();
            $table->string('dr_number')->default('');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->string('entity_name')->nullable();
            $table->string('fund_cluster')->nullable();
            $table->string('office')->nullable();
            $table->string('division')->nullable();
            $table->string('responsibility_center_code')->nullable();
            $table->text('purpose');
            $table->date('date_requested');
            $table->date('date_approved')->nullable();
            $table->string('status')->default('pending'); // pending, approved, partially_approved, cancelled
            // Signatories
            $table->string('requested_by_name')->nullable();
            $table->string('requested_by_designation')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_designation')->nullable();
            $table->string('issued_by_name')->nullable();
            $table->string('issued_by_designation')->nullable();
            $table->string('received_by_name')->nullable();
            $table->string('received_by_designation')->nullable();
            $table->timestamps();
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('quantity_requested', 15, 4);
            $table->decimal('quantity_issued', 15, 4)->default(0);
            $table->boolean('stock_available')->default(false);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->date('expiration_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};
