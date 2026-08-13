<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('requisition_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('action')->default('correction');   // correction
            $table->json('changed_fields');                     // { field: { old, new } } — header + line-level changes
            $table->timestamps();

            $table->index(['requisition_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_audit_logs');
    }
};
