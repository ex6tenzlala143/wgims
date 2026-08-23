<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Add permanent system-generated RIS ID (RIS-000001 format).
        //    Keep existing ris_number as the user-entered official reference (RIS-CAM-2026-001).
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('ris_code')->nullable()->after('ris_number');
        });

        // Backfill existing rows from their primary key — ids are never reused.
        $rows = DB::table('requisitions')->orderBy('id')->get(['id', 'ris_code']);
        foreach ($rows as $row) {
            if (! $row->ris_code) {
                DB::table('requisitions')
                    ->where('id', $row->id)
                    ->update(['ris_code' => 'RIS-' . str_pad((string) $row->id, 6, '0', STR_PAD_LEFT)]);
            }
        }

        // Enforce uniqueness for future system IDs (allows multiple NULLs before backfill, but none remain).
        Schema::table('requisitions', function (Blueprint $table) {
            $table->unique('ris_code', 'requisitions_ris_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropUnique('requisitions_ris_code_unique');
            $table->dropColumn('ris_code');
        });
    }
};
