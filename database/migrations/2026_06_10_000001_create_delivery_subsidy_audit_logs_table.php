<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_subsidy_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_subsidy_id')->constrained('delivery_subsidies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('action')->default('update');          // update, unit_cost_cascade, ris_cascade, supplier_cascade
            $table->json('changed_fields');                       // { field: { old, new } }
            $table->json('cascade_summary')->nullable();          // summary of downstream records affected
            $table->timestamps();

            $table->index(['delivery_subsidy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_subsidy_audit_logs');
    }
};
