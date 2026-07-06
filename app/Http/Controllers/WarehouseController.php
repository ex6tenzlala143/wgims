<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $query = Warehouse::withCount(['users', 'assignedUsers', 'items'])
            ->orderBy('name');

        if (! $user->hasAdminAccess()) {
            // Non-admin users only see warehouses they are assigned to via the pivot.
            $assignedIds = $user->warehouses()->pluck('warehouses.id');
            $query->whereIn('id', $assignedIds);
        }

        $warehouses = $query->paginate(20);

        return view('warehouses.index', compact('warehouses'));
    }

    public function create()
    {
        return view('warehouses.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:100',
            'code'  => 'required|string|max:20|unique:warehouses,code',
            'place' => 'nullable|string|max:100',
        ]);

        Warehouse::create($validated);

        return redirect()->route('warehouses.index')->with('success', 'Warehouse created.');
    }

    public function edit(Warehouse $warehouse)
    {
        // Load all users assigned via the pivot so the edit page shows the full list.
        $warehouse->load(['assignedUsers' => function ($q) {
            $q->orderBy('name');
        }]);

        return view('warehouses.edit', compact('warehouse'));
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:100',
            'code'      => 'required|string|max:20|unique:warehouses,code,' . $warehouse->id,
            'place'     => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $warehouse->update($validated);

        return redirect()->route('warehouses.index')->with('success', 'Warehouse updated.');
    }
}
