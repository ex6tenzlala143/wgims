@extends('layouts.app')
@section('title', 'Warehouses')
@section('page-title', 'Warehouses')

@section('content')
<div class="page-header">
    <div>
        <h1>Warehouses</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Warehouses</div>
    </div>
    @if(auth()->user()->canCreate())
    <a href="{{ route('warehouses.create') }}" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Warehouse
    </a>
    @endif
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Warehouse Name</th>
                    <th>Code</th>
                    <th>Place</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th>Assigned Users</th>
                    <th>Items</th>
                    @endif
                    <th>Status</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th>Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($warehouses as $warehouse)
                <tr>
                    <td><strong>{{ $warehouse->name }}</strong></td>
                    <td><span class="badge badge-primary">{{ $warehouse->code }}</span></td>
                    <td>{{ $warehouse->place ?? '-' }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td>{{ $warehouse->assigned_users_count }}</td>
                    <td>{{ $warehouse->items_count }}</td>
                    @endif
                    <td>
                        <span class="badge {{ $warehouse->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $warehouse->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    @if(auth()->user()->canWrite())
                    <td>
                        <a href="{{ route('warehouses.edit', $warehouse->id) }}"
                           class="btn btn-sm btn-outline btn-icon" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                    </td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 7 : 4 }}"
                        style="text-align:center;padding:40px;color:var(--text-muted)">
                        <i class="fas fa-warehouse" style="font-size:32px;margin-bottom:8px;display:block;opacity:.3"></i>
                        @if(auth()->user()->hasAdminAccess())
                            No warehouses found.
                        @else
                            You have no warehouses assigned to your account.
                            Please contact an administrator.
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($warehouses->hasPages())
    <div class="card-footer">{{ $warehouses->links() }}</div>
    @endif
</div>
@endsection
