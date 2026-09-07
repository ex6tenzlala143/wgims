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
    @if(auth()->user()->canCreate())
    <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()">
        <i class="fas fa-plus"></i> Add New Category
    </button>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <h3>All Categories <span style="font-weight:400;color:var(--text-muted);font-size:13px">({{ $categories->count() }} total)</span></h3>
    </div>
    @if($categories->isEmpty())
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
        <i class="fas fa-tags" style="font-size:32px;margin-bottom:8px;display:block;opacity:.4"></i>
        No categories yet. Click <strong>Add New Category</strong> to create one.
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
                    <th style="text-align:right">Item Names</th>
                    <th style="text-align:right">Items</th>
                    <th>Status</th>
                    <th style="min-width:150px">Actions</th>
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
                        {{ $cat->catalogItems->count() }}
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
                            <button type="button" class="btn btn-sm btn-outline btn-icon"
                                    title="View Items"
                                    onclick="openViewItemsModal({{ $cat->id }})">
                                <i class="fas fa-list"></i>
                            </button>

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

                            <form action="{{ route('item_categories.toggle', $cat->id) }}" method="POST" style="display:inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-icon {{ $cat->is_active ? 'btn-warning' : 'btn-success' }}"
                                        title="{{ $cat->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas {{ $cat->is_active ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                </button>
                            </form>

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
        Manage the <strong>item names</strong> (description + account code) available under each category — they appear in the searchable
        item dropdown of the New Delivery/Subsidy form. Categories with existing inventory items cannot be deleted — deactivate them instead.
    </div>
</div>

{{-- ── Edit form (hidden by default, shown via JS) ───────────────────────── --}}
<div id="edit-card" style="display:none">
    <div class="card" style="margin-top:24px;border:2px solid var(--primary)">
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
                    <label class="form-label">Category Label <span class="req">*</span></label>
                    <input type="text" name="label" id="edit-label" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Code <span class="req">*</span></label>
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

{{-- ── Add Category Modal ───────────────────────────────────────────────── --}}
<div class="modal-overlay" id="addCategoryModal" aria-hidden="true">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="addCategoryTitle" style="max-width:480px;height:auto;max-height:min(80vh,520px)">
        <div class="modal-header">
            <h2 id="addCategoryTitle"><i class="fas fa-plus"></i> Add New Category</h2>
            <button type="button" class="modal-close" onclick="closeAddCategoryModal()">&times;</button>
        </div>
        <form id="addCategoryForm" method="POST" action="{{ route('item_categories.store') }}">
            @csrf
            <div class="modal-body">
                <div id="addCategoryErrors" class="alert alert-danger" style="display:none"></div>
                <div class="form-group">
                    <label class="form-label">Category Label <span class="req">*</span>
                        <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— display name shown everywhere</span>
                    </label>
                    <input type="text" name="label" class="form-control" placeholder="e.g. Welfare Goods for Distribution (FOOD)" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Account Code <span class="req">*</span></label>
                    <input type="text" name="account_code" class="form-control" placeholder="e.g. 1040202000-03" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Key
                        <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— auto-generated if left blank (lowercase, hyphens only)</span>
                    </label>
                    <input type="text" name="key" class="form-control" placeholder="e.g. medicine (auto-generated if blank)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddCategoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Category</button>
            </div>
        </form>
    </div>
</div>

{{-- ── View Items Modal ───────────────────────────────────────────────── --}}
<div class="modal-overlay" id="viewItemsModal" aria-hidden="true">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="viewItemsTitle" style="max-width:640px;height:auto;max-height:min(85vh,620px)">
        <div class="modal-header">
            <h2 id="viewItemsTitle"><i class="fas fa-cubes"></i> Item Names</h2>
            <button type="button" class="modal-close" onclick="closeViewItemsModal()">&times;</button>
        </div>
        <div class="modal-body" style="overflow-y:auto">
            <div id="viewItemsContent"></div>
        </div>
    </div>
</div>

{{-- ── Add Item Name Modal ─────────────────────────────────────────────── --}}
<div class="modal-overlay" id="addItemModal" aria-hidden="true">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="addItemTitle" style="max-width:520px;height:auto;max-height:min(80vh,520px)">
        <div class="modal-header">
            <h2 id="addItemTitle"><i class="fas fa-plus"></i> Add Item Name</h2>
            <button type="button" class="modal-close" onclick="closeAddItemModal()">&times;</button>
        </div>
        <form id="addItemForm" method="POST" action="{{ route('item_catalog_items.store') }}">
            @csrf
            <input type="hidden" name="item_category_id" id="addItemCategoryId">
            <div class="modal-body">
                <div id="addItemErrors" class="alert alert-danger" style="display:none"></div>
                <div class="form-group">
                    <label class="form-label">Item Name / Description <span class="req">*</span></label>
                    <input type="text" name="name" id="addItemName" class="form-control" placeholder="e.g. Bond Paper A4" required>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                        <i class="fas fa-info-circle"></i> Account Code will automatically inherit from the selected category.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Item Name</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Item Name Modal ─────────────────────────────────────────────── --}}
<div class="modal-overlay" id="editItemModal" aria-hidden="true">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="editItemTitle" style="max-width:520px;height:auto;max-height:min(80vh,520px)">
        <div class="modal-header">
            <h2 id="editItemTitle"><i class="fas fa-edit"></i> Edit Item Name</h2>
            <button type="button" class="modal-close" onclick="closeEditItemModal()">&times;</button>
        </div>
        <form id="editItemForm" method="POST">
            @csrf @method('PUT')
            <div class="modal-body">
                <div id="editItemErrors" class="alert alert-danger" style="display:none"></div>
                <div class="form-group">
                    <label class="form-label">Item Name / Description <span class="req">*</span></label>
                    <input type="text" name="name" id="editItemName" class="form-control" required>
                </div>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;padding-top:8px">
                    <input type="checkbox" name="is_active" value="1" id="editItemActive" style="width:15px;height:15px"> Active
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
@php
    $catalogItemsByCategory = [];
    foreach ($categories as $cat) {
        $catalogItemsByCategory[$cat->id] = $cat->catalogItems->map(fn($ci) => [
            'id' => $ci->id,
            'name' => $ci->name,
            'account_code' => $ci->account_code,
            'is_active' => $ci->is_active,
        ]);
    }
@endphp
<script>
const editRouteBase = '{{ url("/item-categories") }}';
const catalogItemsByCategory = @json($catalogItemsByCategory);

function openAddCategoryModal() {
    const modal = document.getElementById('addCategoryModal');
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    const errorsEl = document.getElementById('addCategoryErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';
    document.getElementById('addCategoryForm').reset();
}

function closeAddCategoryModal() {
    const modal = document.getElementById('addCategoryModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

document.getElementById('addCategoryModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddCategoryModal();
});

document.getElementById('addCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);
    const errorsEl = document.getElementById('addCategoryErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';

    fetch(form.action, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: formData,
    })
    .then(response => response.json().then(data => ({ status: response.status, body: data })))
    .then(({ status, body }) => {
        if (status === 200 && body.success) {
            closeAddCategoryModal();
            window.location.reload();
        } else {
            if (body.errors) {
                let html = '<ul style="margin:0;padding-left:20px">';
                for (const [field, messages] of Object.entries(body.errors)) {
                    messages.forEach(msg => {
                        html += '<li>' + msg + '</li>';
                    });
                }
                html += '</ul>';
                errorsEl.innerHTML = html;
                errorsEl.style.display = 'block';
            } else {
                errorsEl.innerHTML = body.message || 'An error occurred. Please try again.';
                errorsEl.style.display = 'block';
            }
        }
    })
    .catch(() => {
        errorsEl.innerHTML = 'Network error. Please try again.';
        errorsEl.style.display = 'block';
    });
});

function openViewItemsModal(catId) {
    const items = catalogItemsByCategory[catId] || [];
    const content = document.getElementById('viewItemsContent');
    const modal = document.getElementById('viewItemsModal');

    if (items.length === 0) {
        content.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:20px">No item names yet. Click <strong>Add Item Name</strong> to create one.</p>';
    } else {
        let html = '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        html += '<thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Item Name</th><th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Account Code</th><th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Status</th><th style="text-align:right;padding:8px;border-bottom:1px solid #ddd">Actions</th></tr></thead>';
        html += '<tbody>';
        items.forEach(item => {
            html += '<tr>';
            html += '<td style="padding:8px;border-bottom:1px solid #eee">' + item.name + '</td>';
            html += '<td style="padding:8px;border-bottom:1px solid #eee"><code style="background:#f0f4f8;padding:2px 6px;border-radius:4px">' + (item.account_code || '—') + '</code></td>';
            html += '<td style="padding:8px;border-bottom:1px solid #eee"><span class="badge ' + (item.is_active ? 'badge-success' : 'badge-secondary') + '">' + (item.is_active ? 'Active' : 'Inactive') + '</span></td>';
            html += '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right">';
            html += '<button class="btn btn-sm btn-outline" onclick="openEditItemModal(' + catId + ', ' + item.id + ', \'' + item.name.replace(/'/g, "\\'") + '\', ' + item.is_active + ')"><i class="fas fa-edit"></i></button> ';
            html += '<button class="btn btn-sm btn-danger" onclick="deleteItem(' + item.id + ', \'' + item.name.replace(/'/g, "\\'").replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '\')"><i class="fas fa-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        content.innerHTML = html;
    }

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closeViewItemsModal() {
    const modal = document.getElementById('viewItemsModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

document.getElementById('viewItemsModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewItemsModal();
});

function openAddItemModal(catId) {
    const modal = document.getElementById('addItemModal');
    document.getElementById('addItemCategoryId').value = catId;
    document.getElementById('addItemName').value = '';
    const errorsEl = document.getElementById('addItemErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closeAddItemModal() {
    const modal = document.getElementById('addItemModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

document.getElementById('addItemModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddItemModal();
});

document.getElementById('addItemForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);
    const errorsEl = document.getElementById('addItemErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';

    fetch(form.action, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: formData,
    })
    .then(response => response.json().then(data => ({ status: response.status, body: data })))
    .then(({ status, body }) => {
        if (status === 200 && body.success) {
            closeAddItemModal();
            window.location.reload();
        } else {
            if (body.errors) {
                let html = '<ul style="margin:0;padding-left:20px">';
                for (const [field, messages] of Object.entries(body.errors)) {
                    messages.forEach(msg => {
                        html += '<li>' + msg + '</li>';
                    });
                }
                html += '</ul>';
                errorsEl.innerHTML = html;
                errorsEl.style.display = 'block';
            } else {
                errorsEl.innerHTML = body.message || 'An error occurred. Please try again.';
                errorsEl.style.display = 'block';
            }
        }
    })
    .catch(() => {
        errorsEl.innerHTML = 'Network error. Please try again.';
        errorsEl.style.display = 'block';
    });
});

function openEdit(id, label, accountCode, sortOrder, isActive) {
    const form = document.getElementById('edit-form');
    form.action = editRouteBase + '/' + id;

    document.getElementById('edit-label').value = label;
    document.getElementById('edit-account-code').value = accountCode;
    document.getElementById('edit-sort-order').value = sortOrder;
    document.getElementById('edit-is-active').checked = isActive;

    document.getElementById('edit-card').style.display = '';
    document.getElementById('edit-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cancelEdit() {
    document.getElementById('edit-card').style.display = 'none';
}

function openEditItemModal(catId, itemId, name, isActive) {
    const modal = document.getElementById('editItemModal');
    document.getElementById('editItemForm').action = '/item-categories/catalog-items/' + itemId;
    document.getElementById('editItemName').value = name;
    document.getElementById('editItemActive').checked = isActive;
    const errorsEl = document.getElementById('editItemErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
}

function closeEditItemModal() {
    const modal = document.getElementById('editItemModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
}

document.getElementById('editItemModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditItemModal();
});

document.getElementById('editItemForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);
    const errorsEl = document.getElementById('editItemErrors');
    errorsEl.style.display = 'none';
    errorsEl.innerHTML = '';

    fetch(form.action, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: formData,
    })
    .then(response => response.json().then(data => ({ status: response.status, body: data })))
    .then(({ status, body }) => {
        if (status === 200 && body.success) {
            closeEditItemModal();
            window.location.reload();
        } else {
            if (body.errors) {
                let html = '<ul style="margin:0;padding-left:20px">';
                for (const [field, messages] of Object.entries(body.errors)) {
                    messages.forEach(msg => {
                        html += '<li>' + msg + '</li>';
                    });
                }
                html += '</ul>';
                errorsEl.innerHTML = html;
                errorsEl.style.display = 'block';
            } else {
                errorsEl.innerHTML = body.message || 'An error occurred. Please try again.';
                errorsEl.style.display = 'block';
            }
        }
    })
    .catch(() => {
        errorsEl.innerHTML = 'Network error. Please try again.';
        errorsEl.style.display = 'block';
    });
});

function deleteItem(itemId, itemName) {
    if (!confirm('Delete item name "' + itemName + '"? This cannot be undone.')) {
        return;
    }

    fetch('/item-categories/catalog-items/' + itemId, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: new URLSearchParams({_method: 'DELETE'}),
    })
    .then(response => response.json().then(data => ({ status: response.status, body: data })))
    .then(({ status, body }) => {
        if (status === 200 && body.success) {
            window.location.reload();
        } else {
            alert(body.message || 'Failed to delete item. Please try again.');
        }
    })
    .catch(() => {
        alert('Network error. Please try again.');
    });
}

@if($errors->has('name') || $errors->has('account_code'))
(function() {
    const catId = Number('{{ old('item_category_id', '') }}');
    if (catId) {
        openViewItemsModal(catId);
    }
})();
@endif
</script>
@endpush
@endsection
