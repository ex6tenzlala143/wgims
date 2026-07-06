<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('place')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add FK from users to warehouses now that warehouses table exists
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
        });
        Schema::dropIfExists('warehouses');
    }
};
