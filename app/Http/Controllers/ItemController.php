<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Fetch items first for merging
        $allItems = $query->orderBy('is_active', 'desc')
                          ->orderBy('quantity', 'desc')
                          ->orderBy('description')
                          ->get();

        // Group items by merge key: description + unit_cost + engas_unit_cost + expiration_date + warehouse_id
        $mergedGroups = $allItems->groupBy(function ($item) {
            return implode('|', [
                $item->description,
                round((float) $item->unit_cost, 2),
                $item->engas_unit_cost !== null ? round((float) $item->engas_unit_cost, 2) : 'null',
                $item->expiration_date ? $item->expiration_date->format('Y-m-d') : 'null',
                $item->warehouse_id,
            ]);
        })->map(function ($group) {
            // Use the first item as the representative, but sum quantities
            $representative = $group->first();
            $totalQuantity = $group->sum('quantity');
            $sourceItems = $group->values(); // Keep all source items for reference
            
            // Create a merged item object
            $merged = clone $representative;
            $merged->quantity = $totalQuantity;
            $merged->_source_items = $sourceItems; // Store source items for tracking
            $merged->_is_merged = $group->count() > 1; // Flag if actually merged
            
            return $merged;
        })->values();

        // Paginate the merged results manually
        $perPage = 20;
        $currentPage = \Illuminate\Pagination\Paginator::resolveCurrentPage('page');
        $currentItems = $mergedGroups->slice(($currentPage - 1) * $perPage, $perPage)->values();
        
        $items = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentItems,
            $mergedGroups->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'query' => request()->query()]
        );

        if (! isset($warehouses)) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        }
        return view('items.index', compact('items', 'warehouses'));
    }

    public function create()
    {
        $user = Auth::user();

        if ($user->hasAdminAccess()) {
            $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        } else {
            $assignedIds = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id);

            if ($user->warehouse_id && ! $assignedIds->contains((int) $user->warehouse_id)) {
                $assignedIds->push((int) $user->warehouse_id);
            }

            $warehouses = Warehouse::whereIn('id', $assignedIds->unique())
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        if (! $user->hasAdminAccess() && $warehouses->isEmpty()) {
            return redirect()->route('items.index')
                ->with('error', 'You have no warehouses assigned. Please contact an administrator.');
        }

        return view('items.create', compact('warehouses'));
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'description'    => 'required|string|max:255',
            'unit'           => 'required|string|in:'.implode(',', array_keys(Item::UNITS)),
            'category'       => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'warehouse_id'   => 'required|exists:warehouses,id',
            'expiration_date'   => 'nullable|date',
            'quantity_per_item' => 'nullable|integer|min:1',
            'reorder_point'    => 'nullable|integer|min:0',
            'ris_number'       => 'nullable|string|max:255',
            'engas_unit_cost'  => 'nullable|numeric|min:0',
        ]);

        // Non-admins and non-managers may only add items to one of their assigned warehouses.
        if (! $user->canCreate() && ! $user->hasWarehouse((int) $request->warehouse_id)) {
            abort(403, 'You can only add items to a warehouse assigned to you.');
        }

        Item::create([
            'stock_number'     => null,
            'description'      => $request->description,
            'ris_number'       => $request->ris_number,
            'unit'             => $request->unit,
            'category'         => $request->category,
            'account_code'     => Item::getAccountCodeForCategory($request->category),
            'warehouse_id'     => $request->warehouse_id,
            'expiration_date'  => $request->expiration_date,
            'quantity_per_item' => $request->quantity_per_item,
            'reorder_point'    => $request->reorder_point ?? 0,
            'engas_unit_cost'  => $user->isAdmin() ? $request->engas_unit_cost : null,
        ]);

        return redirect()->route('items.index')
            ->with('success', 'Item created. Stock number will be assigned when a delivery / subsidy is delivered.');
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

    public function edit(Item $item)
    {
        abort_unless(Auth::user()->canWrite(), 403);
        $warehouses = Warehouse::where('is_active', true)->get();

        return view('items.edit', compact('item', 'warehouses'));
    }

    public function update(Request $request, Item $item)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        $request->validate([
            'description' => 'required|string|max:255',
            'unit' => 'required|string|in:'.implode(',', array_keys(Item::UNITS)),
            'category' => 'required|string|in:'.implode(',', array_keys(Item::getCategories())),
            'warehouse_id' => 'required|exists:warehouses,id',
            'expiration_date'   => 'nullable|date',
            'quantity_per_item' => 'nullable|integer|min:1',
            'reorder_point'     => 'nullable|integer|min:0',
            'ris_number'        => 'nullable|string|max:255',
            'engas_unit_cost'   => 'nullable|numeric|min:0',
        ]);

        $updateData = [
            'description'       => $request->description,
            'ris_number'        => $request->ris_number,
            'unit'              => $request->unit,
            'category'          => $request->category,
            'account_code'      => Item::getAccountCodeForCategory($request->category),
            'warehouse_id'      => $request->warehouse_id,
            'expiration_date'   => $request->expiration_date,
            'quantity_per_item' => $request->quantity_per_item,
            'reorder_point'     => $request->reorder_point ?? 0,
        ];

        // Only admin can set/change engas_unit_cost
        if (Auth::user()->isAdmin()) {
            $updateData['engas_unit_cost'] = $request->engas_unit_cost;
        }

        $item->update($updateData);

        return redirect()->route('items.index')->with('success', 'Item updated successfully.');
    }

    public function destroy(Item $item)
    {
        abort_unless(Auth::user()->canWrite(), 403);

        // Check for dependent delivery/subsidy or requisition records
        if ($item->deliverySubsidyItems()->exists()) {
            return redirect()->route('items.index')
                ->with('error', "Cannot delete \"{$item->description}\" — it is referenced by one or more Delivery / Subsidies.");
        }
        if ($item->requisitionItems()->exists()) {
            return redirect()->route('items.index')
                ->with('error', "Cannot delete \"{$item->description}\" — it is referenced by one or more Requisitions.");
        }
        if ($item->requisitionDispatchItems()->exists()) {
            return redirect()->route('items.index')
                ->with('error', "Cannot delete \"{$item->description}\" — it has already been dispatched against a Requisition.");
        }

        try {
            DB::transaction(function () use ($item) {
                $item->stockCardEntries()->delete();
                $item->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Item deletion failed', [
                'item_id' => $item->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'The transaction could not be completed. No changes were made. Please try again.');
        }

        return redirect()->route('items.index')->with('success', 'Item deleted.');
    }
}
