<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Link a Stock Transfer back to the Subsidy that originally delivered its
     * source stock. The link is snapshotted at transfer creation so the trace
     * survives even after the subsidy is later deleted (delivery_subsidy_id is
     * nulled by the FK, but source_ris_number / source_subsidy_status remain).
     *
     * source_subsidy_status: null (still active) | 'deleted' | 'archived'
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('delivery_subsidy_id')
                ->nullable()
                ->after('transfer_number')
                ->constrained('delivery_subsidies')
                ->nullOnDelete();
            $table->string('source_ris_number')->nullable()->after('delivery_subsidy_id');
            $table->string('source_subsidy_status')->nullable()->after('source_ris_number');
        });

        // Back-fill the link for transfers created before this feature: the
        // source item carries the RIS number of the subsidy that delivered it.
        DB::table('stock_transfers')
            ->orderBy('id')
            ->chunkById(200, function ($transfers) {
                foreach ($transfers as $transfer) {
                    $risNumber = DB::table('stock_transfer_items as sti')
                        ->join('items as i', 'i.id', '=', 'sti.item_id')
                        ->where('sti.stock_transfer_id', $transfer->id)
                        ->whereNotNull('i.ris_number')
                        ->value('i.ris_number');

                    if (! $risNumber) {
                        continue;
                    }

                    $subsidy = DB::table('delivery_subsidies')
                        ->where('ris_number', $risNumber)
                        ->orderByDesc('id')
                        ->first();

                    if ($subsidy) {
                        DB::table('stock_transfers')->where('id', $transfer->id)->update([
                            'delivery_subsidy_id' => $subsidy->id,
                            'source_ris_number'   => $risNumber,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_subsidy_id');
            $table->dropColumn(['source_ris_number', 'source_subsidy_status']);
        });
    }
};
