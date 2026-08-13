<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Snapshot the Subsidy that produced each Item so the "FROM DELETED
     * SUBSIDY" trail survives a hard delete (same pattern as Stock Transfers):
     *
     *   - items.source_subsidy_id     FK (nullOnDelete) — auto-nulled on delete
     *   - items.source_subsidy_ris    RIS snapshot (survives the delete)
     *   - items.source_subsidy_dr     DR snapshot (survives the delete)
     *   - items.source_subsidy_status active | archived | deleted
     *
     * Existing items delivered by a Subsidy are backfilled as 'active'.
     *
     * The delivery/subsidy audit log is switched to nullOnDelete so the
     * "deleted" audit entry is written BEFORE the subsidy row disappears and
     * outlives it, preserving the trail.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('source_subsidy_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('delivery_subsidies')
                ->nullOnDelete();

            $table->string('source_subsidy_ris')->nullable();
            $table->string('source_subsidy_dr')->nullable();
            $table->string('source_subsidy_status')->nullable()->index();
        });

        // Back-fill: every item ever delivered by a Subsidy inherits that
        // subsidy's RIS/DR snapshot, so the trail works even for records that
        // were created before this snapshot feature existed.
        $rows = DB::table('delivery_items')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->join('delivery_subsidies', 'delivery_subsidies.id', '=', 'deliveries.delivery_subsidy_id')
            ->select(
                'delivery_items.item_id',
                'delivery_subsidies.id as subsidy_id',
                'delivery_subsidies.ris_number',
                'delivery_subsidies.dr_number'
            )
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            DB::table('items')->where('id', $row->item_id)->update([
                'source_subsidy_id'     => $row->subsidy_id,
                'source_subsidy_ris'    => $row->ris_number,
                'source_subsidy_dr'     => $row->dr_number,
                'source_subsidy_status' => 'active',
            ]);
        }

        Schema::table('delivery_subsidy_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['delivery_subsidy_id']);
            $table->unsignedBigInteger('delivery_subsidy_id')->nullable()->change();
            $table->foreign('delivery_subsidy_id')
                ->references('id')->on('delivery_subsidies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_subsidy_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['delivery_subsidy_id']);
            $table->unsignedBigInteger('delivery_subsidy_id')->nullable(false)->change();
            $table->foreign('delivery_subsidy_id')
                ->references('id')->on('delivery_subsidies')
                ->cascadeOnDelete();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['source_subsidy_status']);
            $table->dropForeign(['source_subsidy_id']);
            $table->dropColumn([
                'source_subsidy_id',
                'source_subsidy_ris',
                'source_subsidy_dr',
                'source_subsidy_status',
            ]);
        });
    }
};
