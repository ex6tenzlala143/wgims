<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make delivery_subsidies.warehouse_id nullable.
 *
 * The table was originally named purchase_orders, so the FK constraint
 * retains the original name: purchase_orders_warehouse_id_foreign.
 *
 * Warehouse is no longer required at subsidy creation time — it is assigned
 * automatically from the first shipment item recorded against the subsidy.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE delivery_subsidies DROP FOREIGN KEY purchase_orders_warehouse_id_foreign');
        DB::statement('ALTER TABLE delivery_subsidies MODIFY warehouse_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE delivery_subsidies ADD CONSTRAINT delivery_subsidies_warehouse_id_foreign
                        FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        // Backfill NULLs with the first available warehouse before reverting
        DB::statement('UPDATE delivery_subsidies SET warehouse_id = (SELECT id FROM warehouses LIMIT 1) WHERE warehouse_id IS NULL');
        DB::statement('ALTER TABLE delivery_subsidies DROP FOREIGN KEY delivery_subsidies_warehouse_id_foreign');
        DB::statement('ALTER TABLE delivery_subsidies MODIFY warehouse_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE delivery_subsidies ADD CONSTRAINT purchase_orders_warehouse_id_foreign
                        FOREIGN KEY (warehouse_id) REFERENCES warehouses (id)');
    }
};
