@extends('layouts.app')
@section('title', 'Users')
@section('page-title', 'User Management')

@section('content')
<div class="page-header">
    <div>
        <h1>System Users</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Users</div>
    </div>
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add User</a>
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search users..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" {{ request('role')=='admin'?'selected':'' }}>Administrator</option>
                    <option value="warehouse_manager" {{ request('role')=='warehouse_manager'?'selected':'' }}>Warehouse Manager</option>
                    <option value="supply_custodian" {{ request('role')=='supply_custodian'?'selected':'' }}>Supply Custodian</option>
                    <option value="center_head" {{ request('role')=='center_head'?'selected':'' }}>Warehouse Head</option>
                    <option value="center_staff" {{ request('role')=='center_staff'?'selected':'' }}>Warehouse Staff</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Warehouse</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                <tr>
                    <td><code>{{ $user->username }}</code></td>
                    <td><strong>{{ $user->name }}</strong></td>
                    <td>{{ $user->email ?? '-' }}</td>
                    <td>
                        <span class="badge {{ $user->role == 'admin' ? 'badge-danger' : ($user->role == 'warehouse_manager' ? 'badge-warning' : ($user->role == 'center_head' ? 'badge-primary' : 'badge-secondary')) }}">
                            {{ $user->getRoleLabel() }}
                        </span>
                    </td>
                    <td>
                        @if($user->warehouses->isNotEmpty())
                            @foreach($user->warehouses as $wh)
                            <span class="badge badge-secondary" style="margin-right:2px">{{ $wh->code ?: $wh->name }}</span>
                            @endforeach
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $user->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <a href="{{ route('users.edit', $user->id) }}" class="btn btn-sm btn-outline btn-icon"><i class="fas fa-edit"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
    <div class="card-footer">{{ $users->links() }}</div>
    @endif
</div>
@endsection
