<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the supply_custodian / center_head / center_staff roles.
     *
     * - Existing holders are deactivated (history preserved, login blocked).
     * - The column default moves to delivery_updater (least privilege).
     */
    public function up(): void
    {
        DB::table('users')
            ->whereIn('role', ['supply_custodian', 'center_head', 'center_staff'])
            ->update(['is_active' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('delivery_updater')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('center_staff')->change();
        });
        // Deactivated holders are NOT reactivated on rollback (deliberate:
        // reactivation is a manual admin decision).
    }
};
