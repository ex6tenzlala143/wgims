<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Audit trail for Stock Transfer lifecycle events. Unlike the requisition /
     * subsidy audit logs, entries here must OUTLIVE the transfer itself (a
     * deleted transfer keeps its reversal history), so the FK is nullable and
     * nullOnDelete and the transfer number is snapshotted onto the row.
     */
    public function up(): void
    {
        Schema::create('stock_transfer_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')
                ->nullable()
                ->constrained('stock_transfers')
                ->nullOnDelete();
            $table->string('transfer_number');
            $table->foreignId('user_id')->constrained('users');
            $table->string('action')->default('update');   // create, update, dispatch, reversed_deleted
            $table->json('changed_fields');
            $table->timestamps();

            $table->index(['stock_transfer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_audit_logs');
    }
};
