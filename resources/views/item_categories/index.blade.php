@extends('layouts.app')
@section('title', 'Item Categories')
@section('page-title', 'Item Categories')

@section('content')
<div class="page-header">
    <div>
        <h1>Item Categories</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> / Administration / Item Categories
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:24px;align-items:start">

    {{-- ── Add / Edit form ──────────────────────────────────────────────── --}}
    <div>
        {{-- Add new --}}
        <div class="card" style="margin-bottom:20px" id="add-card">
            <div class="card-header"><h3><i class="fas fa-plus" style="color:var(--primary)"></i> Add New Category</h3></div>
            <div class="card-body">
                <form action="{{ route('item_categories.store') }}" method="POST" id="add-form">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">Category Label <span style="color:red">*</span>
                            <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— display name shown everywhere</span>
                        </label>
                        <input type="text" name="label" class="form-control {{ $errors->has('label') ? 'is-invalid' : '' }}"
                               value="{{ old('label') }}" placeholder="e.g. Welfare Goods for Distribution (FOOD)" required>
                        @error('label')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Code <span style="color:red">*</span></label>
                        <input type="text" name="account_code" class="form-control {{ $errors->has('account_code') ? 'is-invalid' : '' }}"
                               value="{{ old('account_code') }}" placeholder="e.g. 1040202000-03" required>
                        @error('account_code')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Key
                            <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— auto-generated if left blank (lowercase, hyphens only)</span>
                        </label>
                        <input type="text" name="key" class="form-control {{ $errors->has('key') ? 'is-invalid' : '' }}"
                               value="{{ old('key') }}" placeholder="e.g. medicine (auto-generated if blank)">
                        @error('key')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Category</button>
                </form>
            </div>
        </div>

        {{-- Edit form (shown via JS when Edit is clicked) --}}
        <div class="card" id="edit-card" style="display:none;border:2px solid var(--primary)">
            <div class="card-header" style="background:#f0f9ff">
                <h3 style="color:var(--primary)"><i class="fas fa-edit"></i> Edit Category</h3>
                <button type="button" onclick="cancelEdit()" class="btn btn-sm btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
            <div class="card-body">
                <form action="" method="POST" id="edit-form">
                    @csrf @method('PUT')
                    <div class="form-group">
                        <label class="form-label">Category Label <span style="color:red">*</span></label>
                        <input type="text" name="label" id="edit-label" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Code <span style="color:red">*</span></label>
                        <input type="text" name="account_code" id="edit-account-code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="edit-sort-order" class="form-control" min="0" style="max-width:120px">
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
                            <input type="checkbox" name="is_active" value="1" id="edit-is-active" style="width:16px;height:16px">
                            Active (visible in dropdowns)
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Categories list ──────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-header">
            <h3>All Categories <span style="font-weight:400;color:var(--text-muted);font-size:13px">({{ $categories->count() }} total)</span></h3>
        </div>
        @if($categories->isEmpty())
        <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
            <i class="fas fa-tags" style="font-size:32px;margin-bottom:8px;display:block;opacity:.4"></i>
            No categories yet. Add one on the left.
        </div>
        @else
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Label</th>
                        <th>Account Code</th>
                        <th>Key</th>
                        <th style="text-align:right">Items</th>
                        <th>Status</th>
                        <th style="min-width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categories as $cat)
                    <tr>
                        <td style="color:var(--text-muted);font-size:12px">{{ $cat->sort_order }}</td>
                        <td>
                            <strong>{{ $cat->label }}</strong>
                        </td>
                        <td>
                            <code style="font-size:12px;background:#f0f4f8;padding:2px 6px;border-radius:4px">{{ $cat->account_code }}</code>
                        </td>
                        <td>
                            <code style="font-size:11px;color:var(--text-muted)">{{ $cat->key }}</code>
                        </td>
                        <td style="text-align:right;font-weight:600">
                            {{ $cat->items_count }}
                        </td>
                        <td>
                            @if($cat->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </td>
                        <td>
                            <div style="display:flex;gap:4px">
                                {{-- Edit --}}
                                <button type="button" class="btn btn-sm btn-outline btn-icon"
                                        title="Edit"
                                        onclick="openEdit(
                                            {{ $cat->id }},
                                            {{ json_encode($cat->label) }},
                                            {{ json_encode($cat->account_code) }},
                                            {{ $cat->sort_order }},
                                            {{ $cat->is_active ? 'true' : 'false' }}
                                        )">
                                    <i class="fas fa-edit"></i>
                                </button>

                                {{-- Toggle active --}}
                                <form action="{{ route('item_categories.toggle', $cat->id) }}" method="POST" style="display:inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-icon {{ $cat->is_active ? 'btn-warning' : 'btn-success' }}"
                                            title="{{ $cat->is_active ? 'Deactivate' : 'Activate' }}">
                                        <i class="fas {{ $cat->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                    </button>
                                </form>

                                {{-- Delete --}}
                                @if($cat->items_count === 0)
                                <form action="{{ route('item_categories.destroy', $cat->id) }}" method="POST" style="display:inline"
                                      onsubmit="return confirm('Delete category &quot;{{ $cat->label }}&quot;? This cannot be undone.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                @else
                                <button type="button" class="btn btn-sm btn-danger btn-icon" disabled title="Has items — cannot delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="card-footer" style="font-size:12px;color:var(--text-muted)">
            <i class="fas fa-info-circle"></i>
            Categories with existing items cannot be deleted — deactivate them instead to hide them from dropdowns.
            Changes take effect immediately across all modules.
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
const editRouteBase = '{{ url("/item-categories") }}';

function openEdit(id, label, accountCode, sortOrder, isActive) {
    const form = document.getElementById('edit-form');
    form.action = editRouteBase + '/' + id;

    document.getElementById('edit-label').value        = label;
    document.getElementById('edit-account-code').value = accountCode;
    document.getElementById('edit-sort-order').value   = sortOrder;
    document.getElementById('edit-is-active').checked  = isActive;

    document.getElementById('edit-card').style.display = '';
    document.getElementById('add-card').style.display  = 'none';
    document.getElementById('edit-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cancelEdit() {
    document.getElementById('edit-card').style.display = 'none';
    document.getElementById('add-card').style.display  = '';
}
</script>
@endpush
