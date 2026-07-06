@extends('layouts.app')
@section('title', 'Edit Warehouse')
@section('page-title', 'Edit Warehouse')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Warehouse</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('warehouses.index') }}">Warehouses</a> / Edit</div>
    </div>
</div>

<div class="card" style="max-width:600px">
    <div class="card-header"><h3>{{ $warehouse->name }}</h3></div>
    <div class="card-body">
        <form action="{{ route('warehouses.update', $warehouse->id) }}" method="POST">
            @csrf @method('PUT')
            <div class="form-group">
                <label class="form-label">Warehouse Name <span style="color:red">*</span></label>
                <input type="text" name="name" class="form-control" value="{{ old('name', $warehouse->name) }}" required>
            </div>
            <div class="form-group">
                <label class="form-label">Warehouse Code <span style="color:red">*</span></label>
                <input type="text" name="code" class="form-control" value="{{ old('code', $warehouse->code) }}" required style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label class="form-label">Place / Location</label>
                <input type="text" name="place" class="form-control" value="{{ old('place', $warehouse->place) }}">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $warehouse->is_active) ? 'checked' : '' }}>
                    <span class="form-label" style="margin:0">Active</span>
                </label>
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Warehouse</button>
                <a href="{{ route('warehouses.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

{{-- Assigned Users panel — always reflects the current pivot state --}}
<div class="card" style="max-width:600px;margin-top:24px">
    <div class="card-header">
        <h3><i class="fas fa-users" style="color:var(--primary)"></i> Assigned Users</h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $warehouse->assignedUsers->count() }} user(s)</span>
    </div>
    @if($warehouse->assignedUsers->isNotEmpty())
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($warehouse->assignedUsers as $u)
                <tr>
                    <td><strong>{{ $u->name }}</strong></td>
                    <td><code>{{ $u->username }}</code></td>
                    <td>
                        <span class="badge {{ $u->role === 'admin' ? 'badge-danger' : ($u->role === 'center_head' ? 'badge-primary' : 'badge-secondary') }}">
                            {{ $u->getRoleLabel() }}
                        </span>
                    </td>
                    <td>
                        <span class="badge {{ $u->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $u->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        @if(auth()->user()->canWrite())
                        <a href="{{ route('users.edit', $u->id) }}" class="btn btn-sm btn-outline btn-icon" title="Edit User">
                            <i class="fas fa-edit"></i>
                        </a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="card-body" style="color:var(--text-muted);font-size:13px;text-align:center;padding:32px">
        <i class="fas fa-user-slash" style="font-size:28px;margin-bottom:8px;display:block;opacity:.4"></i>
        No users are currently assigned to this warehouse.<br>
        <a href="{{ route('users.index') }}" style="color:var(--primary)">Manage users</a> to assign them here.
    </div>
    @endif
</div>
@endsection
