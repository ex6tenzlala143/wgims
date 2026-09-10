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
    <button type="button" class="btn btn-primary" onclick="openAddSupplierModal()"><i class="fas fa-plus"></i> Add Supplier</button>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search suppliers..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                <a href="{{ route('suppliers.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
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
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-handshake" style="font-size:32px;display:block;margin-bottom:8px;opacity:.3"></i>
                    No suppliers found.
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($suppliers->hasPages())
    <div class="card-footer">{{ $suppliers->links() }}</div>
    @endif
</div>

{{-- ── Add Supplier Modal ───────────────────────────────────────────────── --}}
<div class="modal-overlay" id="addSupplierModal" aria-hidden="true">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="addSupplierTitle" style="max-width:560px;height:auto;max-height:min(85vh,640px)">
        <div class="modal-header">
            <h2 id="addSupplierTitle"><i class="fas fa-plus"></i> Add Supplier</h2>
            <button type="button" class="modal-close" onclick="closeAddSupplierModal()">&times;</button>
        </div>
        <form method="POST" action="{{ route('suppliers.store') }}">
            @csrf
            <div class="modal-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Supplier Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" value="{{ old('name') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">TIN Number</label>
                        <input type="text" name="tin" class="form-control" value="{{ old('tin') }}" placeholder="000-000-000">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" value="{{ old('contact_person') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddSupplierModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Supplier</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openAddSupplierModal() {
    const modal = document.getElementById('addSupplierModal');
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}
function closeAddSupplierModal() {
    const modal = document.getElementById('addSupplierModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}
document.getElementById('addSupplierModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddSupplierModal();
});
@if($errors->any())
openAddSupplierModal();
@endif
</script>
@endpush
@endsection
