<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouse', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->primary(['user_id', 'warehouse_id']);
        });
        DB::table('users')
            ->whereNotNull('warehouse_id')
            ->select('id', 'warehouse_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('user_warehouse')->insertOrIgnore([
                    'user_id'      => $user->id,
                    'warehouse_id' => $user->warehouse_id,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouse');
    }
};
