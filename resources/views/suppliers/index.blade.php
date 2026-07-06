@extends('layouts.app')
@section('title', 'Suppliers')
@section('page-title', 'Suppliers')

@section('content')
<div class="page-header">
    <div>
        <h1>Suppliers</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Suppliers</div>
    </div>
    @if(auth()->user()->canCreate())
    <a href="{{ route('suppliers.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Supplier</a>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="filters-bar" style="margin:0">
            <div class="search-input">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control" placeholder="Search suppliers..." value="{{ request('search') }}">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="{{ route('suppliers.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Address</th>
                    <th>TIN</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($suppliers as $supplier)
                <tr>
                    <td><strong>{{ $supplier->name }}</strong></td>
                    <td>{{ $supplier->address ?? '-' }}</td>
                    <td>{{ $supplier->tin ?? '-' }}</td>
                    <td>{{ $supplier->contact_person ?? '-' }}</td>
                    <td>{{ $supplier->phone ?? '-' }}</td>
                    <td>{{ $supplier->email ?? '-' }}</td>
                    <td>
                        <span class="badge {{ $supplier->is_active ? 'badge-success' : 'badge-secondary' }}">
                            {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px">
                            @if(auth()->user()->canWrite())
                            <a href="{{ route('suppliers.edit', $supplier->id) }}" class="btn btn-sm btn-outline btn-icon" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="{{ route('suppliers.toggle', $supplier->id) }}" method="POST" style="display:inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-icon {{ $supplier->is_active ? 'btn-warning' : 'btn-success' }}"
                                        title="{{ $supplier->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas {{ $supplier->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                </button>
                            </form>
                            @else
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No suppliers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($suppliers->hasPages())
    <div class="card-footer">{{ $suppliers->links() }}</div>
    @endif
</div>
@endsection
