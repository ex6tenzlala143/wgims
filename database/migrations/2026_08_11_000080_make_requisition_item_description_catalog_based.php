<?php

use App\Models\Item;
use App\Models\ItemCatalogItem;
use App\Models\RequisitionItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * RIS lines become description-level requests sourced from the Item
     * Categories catalog (item_catalog_items):
     *
     *  - requisition_items.item_id (the representative stock record) becomes
     *    nullable so a request can be created even before any stock exists.
     *    The exact stock record is still chosen by the warehouse at dispatch.
     *  - catalog_item_id pins which Item Categories item-name record the line
     *    was created from.
     *  - account_code snapshots the catalog item's account code so it survives
     *    catalog edits.
     */
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->change();
            $table->foreignId('catalog_item_id')->nullable()->after('item_id')
                ->constrained('item_catalog_items')->nullOnDelete();
            $table->string('account_code', 50)->nullable()->after('unit');
        });

        // Re-attach the FK on item_id (now nullable, nullOnDelete).
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('items')->nullOnDelete();
        });

        // Back-fill the catalog link + account code for existing lines by
        // matching their description snapshot to an active catalog item name.
        $catalogByLowerName = ItemCatalogItem::where('is_active', true)
            ->get()
            ->mapWithKeys(fn ($c) => [mb_strtolower(trim($c->name)) => $c]);

        RequisitionItem::whereNull('catalog_item_id')->orderBy('id')->chunkById(200, function ($lines) use ($catalogByLowerName) {
            foreach ($lines as $line) {
                $catalog = $catalogByLowerName->get(mb_strtolower(trim($line->description ?? '')));
                if (! $catalog) {
                    continue;
                }
                $line->update([
                    'catalog_item_id' => $catalog->id,
                    'account_code'    => $line->account_code ?: ($catalog->account_code ?: $catalog->category?->account_code),
                ]);
            }
        });

        // Refresh the representative stock record for lines that lost it.
        RequisitionItem::whereNull('item_id')->orderBy('id')->chunkById(200, function ($lines) {
            foreach ($lines as $line) {
                $representative = $line->catalog_item_id
                    ? Item::where('is_active', true)
                        ->whereRaw('LOWER(TRIM(description)) = ?', [mb_strtolower(trim($line->description ?? ''))])
                        ->orderByDesc('quantity')
                        ->orderBy('id')
                        ->first()
                    : null;
                if ($representative) {
                    $line->update([
                        'item_id'     => $representative->id,
                        'unit'        => $line->unit ?: $representative->unit,
                        'unit_cost'   => $line->unit_cost ?: $representative->unit_cost,
                        'stock_available' => $representative->quantity > 0,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropForeign(['catalog_item_id']);
            $table->dropColumn(['catalog_item_id', 'account_code']);
            $table->foreignId('item_id')->nullable(false)->change();
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('items');
        });
    }
};
