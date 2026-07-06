<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_card_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('reference'); // PO number or RIS number
            $table->string('reference_type'); // delivery, issuance, adjustment
            $table->string('reference_id')->nullable(); // delivery_id or requisition_id
            $table->decimal('receipt_qty', 15, 4)->default(0);
            $table->decimal('receipt_unit_cost', 15, 2)->default(0);
            $table->decimal('receipt_total_cost', 15, 2)->default(0);
            $table->decimal('issue_qty', 15, 4)->default(0);
            $table->decimal('balance_qty', 15, 4)->default(0);
            $table->decimal('balance_unit_cost', 15, 2)->default(0);
            $table->decimal('balance_total_cost', 15, 2)->default(0);
            $table->integer('no_of_days_to_consume')->nullable();
            $table->string('from_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_card_entries');
    }
};
