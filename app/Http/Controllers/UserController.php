<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['warehouse', 'warehouses']);
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('username', 'like', '%'.$request->search.'%');
            });
        }
        if ($request->role) {
            $query->where('role', $request->role);
        }
        $users = $query->orderBy('name')->paginate(20)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return view('users.create', compact('warehouses'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'username'        => 'required|string|unique:users,username|max:50',
            'name'            => 'required|string|max:100',
            'email'           => 'nullable|email|unique:users,email',
            'role'            => 'required|in:admin,warehouse_manager,supply_custodian,center_staff,center_head',
            'warehouse_ids'   => 'nullable|array',
            'warehouse_ids.*' => 'exists:warehouses,id',
            'password'        => 'required|string|min:8|confirmed',
        ]);

        $noWarehouseRole = in_array($request->role, ['admin', 'warehouse_manager']);

        // Roles other than admin/warehouse_manager must have at least one warehouse
        if (! $noWarehouseRole && empty($request->warehouse_ids)) {
            return back()->withErrors(['warehouse_ids' => 'Please select at least one warehouse.'])->withInput();
        }

        // Primary warehouse: first selected, or null for admin/warehouse_manager
        $primaryId = (! $noWarehouseRole && ! empty($request->warehouse_ids))
            ? (int) $request->warehouse_ids[0]
            : null;

        $user = User::create([
            'username'     => $request->username,
            'name'         => $request->name,
            'email'        => $request->email,
            'role'         => $request->role,
            'warehouse_id' => $primaryId,
            'password'     => Hash::make($request->password),
            'is_active'    => true,
        ]);

        // Sync pivot — empty array for admin/warehouse_manager, selected IDs for everyone else
        $user->warehouses()->sync(
            $noWarehouseRole ? [] : ($request->warehouse_ids ?? [])
        );

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        // Load all active warehouses for the checkbox list
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        $user->load('warehouses');

        // If the user has assignments to inactive warehouses, append them so they
        // remain visible and are not silently wiped on the next save.
        $inactiveAssigned = $user->warehouses->where('is_active', false);
        if ($inactiveAssigned->isNotEmpty()) {
            $warehouses = $warehouses->concat($inactiveAssigned)->sortBy('name')->values();
        }

        return view('users.edit', compact('user', 'warehouses'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'username'        => 'required|string|unique:users,username,'.$user->id.'|max:50',
            'name'            => 'required|string|max:100',
            'email'           => 'nullable|email|unique:users,email,'.$user->id,
            'role'            => 'required|in:admin,warehouse_manager,supply_custodian,center_staff,center_head',
            'warehouse_ids'   => 'nullable|array',
            'warehouse_ids.*' => 'exists:warehouses,id',
            'password'        => 'nullable|string|min:8|confirmed',
        ]);

        $noWarehouseRole = in_array($request->role, ['admin', 'warehouse_manager']);

        // Roles other than admin/warehouse_manager must have at least one warehouse
        if (! $noWarehouseRole && empty($request->warehouse_ids)) {
            return back()->withErrors(['warehouse_ids' => 'Please select at least one warehouse.'])->withInput();
        }

        // Primary warehouse: first selected, or null for admin/warehouse_manager
        $primaryId = (! $noWarehouseRole && ! empty($request->warehouse_ids))
            ? (int) $request->warehouse_ids[0]
            : null;

        $data = [
            'username'     => $request->username,
            'name'         => $request->name,
            'email'        => $request->email,
            'role'         => $request->role,
            'warehouse_id' => $primaryId,
            'is_active'    => $request->boolean('is_active', true),
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        if ($noWarehouseRole) {
            // Admins and warehouse managers have no warehouse assignments
            $user->warehouses()->sync([]);
        } else {
            $submittedIds = array_map('intval', $request->warehouse_ids ?? []);

            // Preserve any assignments to inactive warehouses that were not shown
            // in the form (they would be wiped by a plain sync).
            $inactiveKept = $user->warehouses()
                ->where('is_active', false)
                ->pluck('warehouses.id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            $finalIds = array_unique(array_merge($submittedIds, $inactiveKept));
            $user->warehouses()->sync($finalIds);
        }

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function checkUsername(Request $request)
    {
        $exists = User::where('username', $request->username)
            ->when($request->user_id, fn ($q) => $q->where('id', '!=', $request->user_id))
            ->exists();

        return response()->json(['available' => ! $exists]);
    }
}
