<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('report_type'); // rpci, rsmi
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('period_month'); // e.g. 2026-05
            $table->string('serial_number')->nullable();
            $table->longText('data'); // JSON snapshot
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
