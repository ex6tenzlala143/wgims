<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\StockCardEntry;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class StockCardController extends Controller
{
    use ScopesWarehouse;

    public function index(Request $request, string $category = 'food')
    {
        $user = Auth::user();

        $allCategories = Item::getCategories();

        if (! array_key_exists($category, $allCategories)) {
            $category = array_key_first($allCategories);
        }

        $query = Item::with(['warehouse', 'stockCardEntries'])
            ->where('category', $category)
            ->where('is_active', true);

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        $items        = $query->orderBy('description')->get();
        $warehouses   = $user->hasAdminAccess() ? Warehouse::where('is_active', true)->get() : collect();
        $categoryInfo = $allCategories[$category];

        return view('stock_cards.index', compact('items', 'warehouses', 'category', 'categoryInfo'));
    }

    public function itemHistory(Request $request, Item $item)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $item->warehouse_id)) {
            abort(403);
        }

        $entries = StockCardEntry::where('item_id', $item->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $item->load('warehouse');

        return view('stock_cards.item_history', compact('item', 'entries'));
    }

    public function itemHistoryByUnitCost(Request $request, Item $item)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $item->warehouse_id)) {
            abort(403);
        }

        $entries = StockCardEntry::where('item_id', $item->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        // Build FIFO batches from receipt entries
        $batches = [];
        foreach ($entries as $entry) {
            if ($entry->receipt_qty > 0) {
                $batches[] = [
                    'unit_cost'    => $entry->receipt_unit_cost,
                    'original_qty' => $entry->receipt_qty,
                    'remaining_qty'=> $entry->receipt_qty,
                    'reference'    => $entry->reference,
                    'date'         => $entry->entry_date,
                    'movements'    => [],
                    'depleted'     => false,
                ];
            }
        }

        // Apply issues to batches FIFO
        foreach ($entries as $entry) {
            if ($entry->issue_qty > 0) {
                $remaining = $entry->issue_qty;
                foreach ($batches as &$batch) {
                    if ($remaining <= 0) break;
                    if ($batch['remaining_qty'] <= 0) continue;

                    $deduct = min($remaining, $batch['remaining_qty']);
                    $batch['remaining_qty'] -= $deduct;
                    $batch['movements'][] = [
                        'date'      => $entry->entry_date,
                        'reference' => $entry->reference,
                        'type'      => 'issue',
                        'qty'       => $deduct,
                    ];
                    $remaining -= $deduct;
                    if ($batch['remaining_qty'] <= 0) {
                        $batch['depleted'] = true;
                    }
                }
            }
        }

        $item->load('warehouse');

        return view('stock_cards.item_history_by_unit_cost', compact('item', 'batches', 'entries'));
    }

    public function printStockCard(Item $item)
    {
        $user = Auth::user();
        if (! $this->userCanAccessWarehouse($user, $item->warehouse_id)) {
            abort(403);
        }

        $entries = StockCardEntry::where('item_id', $item->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $item->load('warehouse');

        return view('stock_cards.print', compact('item', 'entries'));
    }

    public function summary(Request $request)
    {
        $user = Auth::user();

        $query = Item::with(['warehouse', 'stockCardEntries'])
            ->where('is_active', true);

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        $query->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->account_code, fn ($q, $code) => $q->where('account_code', $code))
            ->when($request->description, fn ($q, $desc) => $q->where('description', $desc));

        $items = $query->orderBy('category')->orderBy('description')->get();
        $warehouses = $user->hasAdminAccess() ? Warehouse::where('is_active', true)->orderBy('name')->get() : collect();

        $accountCodes = collect(Item::getCategories())
            ->mapWithKeys(fn ($cat, $key) => [$cat['account_code'] => $cat['account_code'] . ' — ' . $cat['label']])
            ->unique()
            ->sortKeys();

        $descriptions = Item::where('is_active', true)
            ->select('description')->distinct()
            ->orderBy('description')
            ->pluck('description');

        return view('stock_cards.summary', compact('items', 'warehouses', 'accountCodes', 'descriptions'));
    }
}
