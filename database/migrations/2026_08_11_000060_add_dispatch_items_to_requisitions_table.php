<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Requisition lines become description-level requests:
     * - item_id becomes nullable (a reference record; the exact stock record is
     *   resolved by the dispatcher when issuing).
     * - description / unit snapshots let the line render without an item.
     * A new requisition_dispatch_items table records EACH dispatch (its own
     * warehouse, stock record, quantity, cost, expiry and DR#), so partial
     * dispatches of one line can go to different warehouses independently.
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();
            $table->string('description')->nullable()->after('item_id');
            $table->string('unit')->nullable()->after('description');
        });

        // Back-fill snapshots for existing rows from their referenced item.
        DB::table('requisition_items')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $ri) {
                $item = DB::table('items')->where('id', $ri->item_id)->first();
                if ($item) {
                    DB::table('requisition_items')
                        ->where('id', $ri->id)
                        ->update([
                            'description' => $item->description,
                            'unit'        => $item->unit,
                        ]);
                }
            }
        });

        Schema::create('requisition_dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_item_id')->constrained('requisition_items')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity_issued', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('engas_unit_cost', 15, 4)->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('dr_number')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_dispatch_items');

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn('unit');
            $table->dropColumn('description');
            $table->foreignId('item_id')->nullable(false)->change();
        });
    }
};
