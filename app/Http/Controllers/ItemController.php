<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $user  = Auth::user();

        // Admins can see all items including inactive (out-of-stock) ones.
        // Non-admins only see active items.
        $query = Item::with('warehouse');

        if ($user->hasAdminAccess()) {
            // No is_active filter for admins/managers — they see everything including out-of-stock
        } else {
            $query->where('is_active', true);

            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id);

            if ($user->warehouse_id && ! $assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }

            $assignedIds = $assignedIds->unique()->values();

            $warehouses = Warehouse::whereIn('id', $assignedIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            if ($assignedIds->isEmpty()) {
                $items = $query->whereRaw('1 = 0')->paginate(20);
                return view('items.index', compact('items', 'warehouses'));
            }

            $query->whereIn('warehouse_id', $assignedIds);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('description', 'like', '%'.$request->search.'%')
                    ->orWhere('stock_number', 'like', '%'.$request->search.'%')
                    ->orWhere('ris_number', 'like', '%'.$request->search.'%');
            });
        }

        if ($request->category) {
            $query->where('category', $request->category);
        }

        if ($request->warehouse_id) {
            if ($user->hasAdminAccess() || $user->hasWarehouse((int) $request->warehouse_id)) {
                $query->where('warehouse_id', $request->warehouse_id);
            }
        }

        // Stock status filter
        switch ($request->stock_status) {
            case 'out_of_stock':
                $query->where('quantity', '<=', 0);
                break;
            case 'in_stock':
                $query->where('quantity', '>', 0)->where('is_active', true);
                break;
            case 'low_stock':
                $query->where('quantity', '>', 0)
                      ->whereColumn('quantity', '<=', 'reorder_point')
                      ->where('reorder_point', '>', 0);
                break;
        }

        // Source subsidy filter (deleted / active)
        if (in_array($request->source_subsidy_status, ['deleted', 'active'], true)) {
            $query->where('source_subsidy_status', $request->source_subsidy_status);
        }

        // Fetch all items individually — no merging
        $items = $query->orderBy('is_active', 'desc')
                          ->orderBy('quantity', 'desc')
                          ->orderBy('description')
                          ->paginate(20);

        if (! isset($warehouses)) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        }
        return view('items.index', compact('items', 'warehouses'));
    }

    public function show(Item $item)
    {
        $user = Auth::user();

        if (! $user->hasAdminAccess() && ! $user->hasWarehouse((int) $item->warehouse_id)) {
            abort(403);
        }

        $item->load('warehouse', 'stockCardEntries');

        return view('items.show', compact('item'));
    }
}
