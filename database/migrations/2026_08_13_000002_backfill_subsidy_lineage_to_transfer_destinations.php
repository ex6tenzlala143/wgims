<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Propagate the source-subsidy snapshot to every destination Item created
     * by a Stock Transfer, so stock that has moved to another warehouse still
     * carries the "FROM DELETED SUBSIDY" trail.
     *
     * A dispatched transfer (quantity > 0) moved stock from source item →
     * destination item. Destination items were created through
     * findOrCreateByUnitCost(), which never copied the snapshot columns, so
     * anything transferred before this fix is unflagged. This walks the whole
     * transfer chain (a destination may itself be transferred onward) and copies
     * the source's snapshot to any destination that does not have one yet.
     */
    public function up(): void
    {
        do {
            $changed = false;

            $pairs = DB::table('stock_transfer_items as sti')
                ->join('items as source', 'source.id', '=', 'sti.item_id')
                ->join('items as dest', 'dest.id', '=', 'sti.destination_item_id')
                ->where('sti.quantity', '>', 0)
                ->whereNotNull('source.source_subsidy_status')
                ->whereNull('dest.source_subsidy_status')
                ->select(
                    'dest.id as dest_id',
                    'source.source_subsidy_id as subsidy_id',
                    'source.source_subsidy_ris as ris',
                    'source.source_subsidy_dr as dr',
                    'source.source_subsidy_status as status'
                )
                ->get();

            foreach ($pairs as $pair) {
                DB::table('items')->where('id', $pair->dest_id)->update([
                    'source_subsidy_id'     => $pair->subsidy_id,
                    'source_subsidy_ris'    => $pair->ris,
                    'source_subsidy_dr'     => $pair->dr,
                    'source_subsidy_status' => $pair->status,
                ]);
                $changed = true;
            }
        } while ($changed);
    }

    public function down(): void
    {
        // Data backfill — nothing to roll back.
    }
};
