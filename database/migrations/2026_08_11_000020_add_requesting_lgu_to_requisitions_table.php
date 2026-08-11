<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Free-text "Requesting LGU" info stored once per Requisition/Augmentation.
     */
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->string('province')->nullable()->after('division');
            $table->string('municipality')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn(['province', 'municipality']);
        });
    }
};
