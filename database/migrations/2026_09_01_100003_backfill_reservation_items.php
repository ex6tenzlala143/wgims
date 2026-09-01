<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For every existing reservations row that still references a single item
        // (legacy structure), create one reservation_items row and generate a
        // reservation_number if it doesn't have one yet.
        $reservations = DB::table('reservations')
            ->whereNotNull('item_id')
            ->get();

        foreach ($reservations as $res) {
            // Skip if already backfilled
            if (DB::table('reservation_items')->where('reservation_id', $res->id)->exists()) {
                continue;
            }

            // Map old status to new per-item status
            $itemStatus = match ($res->status) {
                'FULFILLED'  => 'DEPLOYED',
                'CANCELLED'  => 'CANCELLED',
                'EXPIRED'    => 'CANCELLED',
                'ALLOCATED'  => 'PARTIALLY_DEPLOYED',
                default      => 'ACTIVE',
            };

            // Snapshot costs from the item record
            $item = DB::table('items')->find($res->item_id);

            DB::table('reservation_items')->insert([
                'reservation_id'   => $res->id,
                'item_id'          => $res->item_id,
                'warehouse_id'     => $res->warehouse_id,
                'reserved_quantity'=> $res->reserved_quantity ?? 0,
                'deployed_quantity'=> $res->allocated_quantity ?? 0,
                'status'           => $itemStatus,
                'unit_cost'        => $item->unit_cost ?? null,
                'engas_unit_cost'  => $item->engas_unit_cost ?? null,
                'expiration_date'  => $item->expiration_date ?? null,
                'notes'            => null,
                'created_at'       => $res->created_at,
                'updated_at'       => $res->updated_at,
            ]);

            // Generate reservation_number if missing
            if (empty($res->reservation_number)) {
                $num = 'RES-' . str_pad($res->id, 6, '0', STR_PAD_LEFT);
                DB::table('reservations')
                    ->where('id', $res->id)
                    ->update(['reservation_number' => $num]);
            }
        }
    }

    public function down(): void
    {
        // Remove backfilled rows — rows that were created by this migration
        // have reservation_id values that correspond to reservations with item_id set.
        // We cannot safely distinguish them from newly-created rows, so down() is a no-op.
    }
};
