@extends('layouts.app')
@section('title', 'Edit RIS')
@section('page-title', 'Edit Requisition')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Requisition {{ $requisition->ris_code ?? $requisition->ris_id }} <span style="font-weight:400;color:var(--text-muted);font-size:16px">/ {{ $requisition->ris_number }}</span></h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Edit</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">RIS ID: <code>{{ $requisition->ris_code ?? $requisition->ris_id }}</code> &nbsp;·&nbsp; RIS No.: <strong>{{ $requisition->ris_number }}</strong></div>
    </div>
</div>

<form action="{{ route('requisitions.update', $requisition->id) }}" method="POST" id="edit-ris-form">
@csrf @method('PUT')
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> RIS Header</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">RIS No. <span class="req">*</span></label>
                        <input type="text" name="ris_number" class="form-control {{ $errors->has('ris_number') ? 'is-invalid' : '' }}" value="{{ old('ris_number', $requisition->ris_number) }}" placeholder="e.g. RIS-CAM-2026-001" required>
                        @error('ris_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small style="color:var(--text-muted);font-size:11px">Official reference number (user-entered). RIS ID <code>{{ $requisition->ris_code ?? $requisition->ris_id }}</code> stays unchanged.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date Requested <span class="req">*</span></label>
                        <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', $requisition->date_requested->format('Y-m-d')) }}" required>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Requested By</label>
                        <input type="text" name="requested_by_name" class="form-control" value="{{ old('requested_by_name', $requisition->requested_by_name) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Requested By Designation</label>
                        <input type="text" name="requested_by_designation" class="form-control" value="{{ old('requested_by_designation', $requisition->requested_by_designation) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Entity Name</label>
                        <input type="text" name="entity_name" class="form-control" value="{{ old('entity_name', $requisition->entity_name) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fund Cluster</label>
                        <input type="text" name="fund_cluster" class="form-control" value="{{ old('fund_cluster', $requisition->fund_cluster) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Responsibility Center Code</label>
                        <input type="text" name="responsibility_center_code" class="form-control" value="{{ old('responsibility_center_code', $requisition->responsibility_center_code) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Office</label>
                        <input type="text" name="office" class="form-control" value="{{ old('office', $requisition->office) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Division</label>
                        <input type="text" name="division" class="form-control" value="{{ old('division', $requisition->division) }}">
                    </div>
                    <div class="form-group" style="visibility:hidden" aria-hidden="true">
                        <label class="form-label">&nbsp;</label>
                        <input type="text" class="form-control" tabindex="-1" readonly>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Purpose <span class="req">*</span></label>
                    <textarea name="purpose" class="form-control @error('purpose') is-invalid @enderror" rows="2" required>{{ old('purpose', $requisition->purpose) }}</textarea>
                    @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-city" style="color:var(--primary)"></i> Requesting LGU</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Province</label>
                        <input type="text" name="province" class="form-control" value="{{ old('province', $requisition->province) }}" placeholder="e.g. Cebu" style="width:100%">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Municipality</label>
                        <input type="text" name="municipality" class="form-control" value="{{ old('municipality', $requisition->municipality) }}" placeholder="e.g. Lapu-Lapu City" style="width:100%">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list" style="color:var(--primary)"></i> Requested Items</h3>
                <button type="button" class="btn btn-sm btn-primary" onclick="risEditAddRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>
            <div class="card-body" style="padding:0">
                @foreach($errors->keys() as $k)
                    @if($k === 'items' || str_starts_with($k, 'items.'))
                        <div style="padding:10px 20px;background:#fff5f5;border-bottom:1px solid var(--border);color:var(--danger);font-size:13px">
                            <i class="fas fa-exclamation-triangle"></i> {{ $errors->first($k) }}
                        </div>
                    @endif
                @endforeach
                <div class="table-wrapper">
                    <table class="line-items-table" id="ris-items-table">
                        <thead>
                            <tr>
                                <th style="width:70%">Item Description <span class="req">*</span></th>
                                <th style="width:24%">Requested Quantity <span class="req">*</span></th>
                                <th style="width:6%"></th>
                            </tr>
                        </thead>
                        <tbody id="ris-items-body">
                            @php
                                // Prefer failed-submission input so newly typed rows survive
                                // a validation round-trip instead of vanishing.
                                $oldInput = old('items');
                                if (is_array($oldInput) && count($oldInput)) {
                                    $byId = $requisition->items->keyBy('id');
                                    $editRows = [];
                                    foreach (array_values($oldInput) as $l) {
                                        $ref = ! empty($l['id']) ? $byId->get((int) $l['id']) : null;
                                        $issued = $ref ? (float) $ref->quantity_issued : 0;
                                        $editRows[] = [
                                            'id' => $l['id'] ?? null,
                                            'catalog_item_id' => $l['catalog_item_id'] ?? null,
                                            'description' => $l['description'] ?? ($ref->description ?? ($ref->item?->description ?? '')),
                                            'quantity_requested' => $l['quantity_requested'] ?? '',
                                            'locked' => $ref ? ($issued > 0 || $ref->dispatchItems->isNotEmpty()) : false,
                                            'issued' => $issued,
                                        ];
                                    }
                                } else {
                                    $editRows = $requisition->items->map(fn($ri) => [
                                        'id' => $ri->id,
                                        'catalog_item_id' => $ri->catalog_item_id,
                                        'description' => $ri->description ?? ($ri->item?->description ?? ''),
                                        'quantity_requested' => $ri->quantity_requested,
                                        'locked' => $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty(),
                                        'issued' => (float) $ri->quantity_issued,
                                    ])->all();
                                }
                            @endphp
                            @foreach($editRows as $idx => $row)
                            @php
                                $locked = $row['locked'];
                                $riDesc = $row['description'];
                            @endphp
                            <tr id="ris-row-{{ $idx }}">
                                <td data-label="Item Description">
                                    <div class="autocomplete-wrapper" id="ris-autocomplete-wrapper-{{ $idx }}">
                                        <input type="text" class="form-control ris-desc-input"
                                               name="items[{{ $idx }}][description]"
                                               value="{{ $riDesc }}"
                                               placeholder="Type item description, then pick from the list..."
                                               {{ $locked ? 'readonly' : 'required' }} autocomplete="off"
                                               data-ris-row-idx="{{ $idx }}">
                                        <div class="autocomplete-dropdown" id="ris-autocomplete-dropdown-{{ $idx }}"></div>
                                    </div>
                                    @if(! empty($row['id']))
                                    <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $row['id'] }}">
                                    @endif
                                    <input type="hidden" name="items[{{ $idx }}][catalog_item_id]" id="ris-catalog-item-id-{{ $idx }}" value="{{ $row['catalog_item_id'] }}">
                                    @if($locked)
                                        <small style="color:var(--text-muted);font-size:11px">
                                            <i class="fas fa-lock"></i> Locked — {{ number_format($row['issued']) }} already issued; item cannot be changed and quantity cannot go below issued.
                                        </small>
                                    @endif
                                </td>
                                <td data-label="Requested Quantity">
                                    <input type="number" name="items[{{ $idx }}][quantity_requested]" id="ris-qty-{{ $idx }}"
                                           class="ris-qty-input form-control"
                                           min="{{ $locked ? max(1, (int) ceil($row['issued'])) : 1 }}" step="1"
                                           value="{{ $row['quantity_requested'] }}" placeholder="e.g. 500" required>
                                </td>
                                <td><button type="button" class="remove-row" onclick="risEditRemoveRow('ris-row-{{ $idx }}')"><i class="fas fa-times"></i></button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
                    <button type="button" class="btn btn-sm btn-outline" onclick="risEditAddRow()">
                        <i class="fas fa-plus"></i> Add Another Item
                    </button>
                    <span id="ris-edit-row-count" style="font-size:12px;color:var(--text-muted)"></span>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Update RIS</h3></div>
            <div class="card-body">
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
                    Review the requisition details and update as needed.
                </p>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-save"></i> Update RIS
                </button>
                <a href="{{ route('requisitions.show', $requisition->id) }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>
</div>
</form>

@push('scripts')
<script>
// ─── Same item picker as the RIS creation form: type-ahead autocomplete over
// the catalog list. Pre-filled rows keep their saved values; locked
// (already dispatched) rows stay read-only. Warehouse is chosen later at
// dispatch/approve time — exactly like creation.
const RIS_EDIT_ITEMS_API_URL = '{{ route("requisitions.description_items") }}';
// Next free row index, derived from the rendered rows so repopulated
// (post-validation) rows never collide with newly added ones.
let risEditRowCount = (function () {
    let max = 0;
    document.querySelectorAll('#ris-items-body tr[id^="ris-row-"]').forEach(function (tr) {
        const n = parseInt(tr.id.replace('ris-row-', ''), 10);
        if (!isNaN(n) && n >= max) max = n + 1;
    });
    return max;
})();
let risEditAllItems = [];
const risEditAutocompleteState = {};

// Load catalog items once for all rows
fetch(RIS_EDIT_ITEMS_API_URL, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
})
.then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
.then(data => { risEditAllItems = data; })
.catch(err => {
    console.error('Failed to load RIS items:', err);
    risEditAllItems = [];
});

function risEditInitAutocomplete(idx) {
    const input = document.querySelector(`input[data-ris-row-idx="${idx}"]`);
    const dropdown = document.getElementById(`ris-autocomplete-dropdown-${idx}`);

    if (!input || !dropdown || input.readOnly) return;

    risEditAutocompleteState[idx] = {
        input: input,
        dropdown: dropdown,
        selectedIndex: -1,
        filteredOptions: []
    };

    function positionDropdown() {
        const rect = input.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const dropdownMaxHeight = 320;
        const spaceBelow = viewportHeight - rect.bottom;
        const spaceAbove = rect.top;

        const openAbove = spaceBelow < dropdownMaxHeight && spaceAbove > spaceBelow;

        if (openAbove) {
            dropdown.style.bottom = (viewportHeight - rect.top + 5) + 'px';
            dropdown.style.top = 'auto';
        } else {
            dropdown.style.top = (rect.bottom + 5) + 'px';
            dropdown.style.bottom = 'auto';
        }

        dropdown.style.left = rect.left + 'px';
        dropdown.style.width = rect.width + 'px';
    }

    input.addEventListener('input', function(e) {
        const value = e.target.value.trim();
        if (value.length === 0) {
            risEditCloseDropdown(idx);
            risEditClearItemData(idx);
            return;
        }
        positionDropdown();
        risEditFilterAndShowDropdown(idx, value);
    });

    input.addEventListener('focus', function(e) {
        const value = e.target.value.trim();
        if (value.length > 0) {
            positionDropdown();
            risEditFilterAndShowDropdown(idx, value);
        }
    });

    input.addEventListener('click', function(e) {
        const value = e.target.value.trim();
        if (value.length === 0 && risEditAllItems.length > 0) {
            positionDropdown();
            risEditFilterAndShowDropdown(idx, '');
        }
    });

    window.addEventListener('resize', function() {
        if (dropdown.classList.contains('open')) {
            positionDropdown();
        }
    });

    input.addEventListener('keydown', function(e) {
        const state = risEditAutocompleteState[idx];
        if (!state || !state.dropdown.classList.contains('open')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            state.selectedIndex = Math.min(state.selectedIndex + 1, state.filteredOptions.length - 1);
            risEditUpdateSelectedItem(idx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
            risEditUpdateSelectedItem(idx);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (state.selectedIndex >= 0 && state.selectedIndex < state.filteredOptions.length) {
                risEditSelectItem(idx, state.filteredOptions[state.selectedIndex]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            risEditCloseDropdown(idx);
        }
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            risEditCloseDropdown(idx);
        }
    });
}

function risEditFilterAndShowDropdown(idx, searchTerm) {
    const state = risEditAutocompleteState[idx];
    if (!state) return;

    if (risEditAllItems.length === 0) {
        state.dropdown.innerHTML = '<div class="autocomplete-no-results"><i class="fas fa-hourglass-half"></i> Loading items...</div>';
        state.dropdown.classList.add('open');
        return;
    }

    const lowerSearch = searchTerm.toLowerCase();
    state.filteredOptions = risEditAllItems.filter(function(opt) {
        return opt.name.toLowerCase().includes(lowerSearch) ||
               (opt.account_code && opt.account_code.toLowerCase().includes(lowerSearch));
    });

    state.selectedIndex = -1;
    risEditRenderDropdown(idx);
}

function risEditRenderDropdown(idx) {
    const state = risEditAutocompleteState[idx];
    if (!state) return;

    const dropdown = state.dropdown;
    dropdown.innerHTML = '';

    if (state.filteredOptions.length === 0) {
        dropdown.innerHTML = '<div class="autocomplete-no-results"><i class="fas fa-search"></i> No matching items found</div>';
        dropdown.classList.add('open');
        return;
    }

    state.filteredOptions.forEach(function(opt, index) {
        const item = document.createElement('div');
        item.className = 'autocomplete-item';
        if (index === state.selectedIndex) {
            item.classList.add('selected');
        }

        let metaHtml = '<div class="item-meta">';
        if (opt.account_code) {
            metaHtml += '<span><i class="fas fa-hashtag"></i> ' + risEditEscapeHtml(opt.account_code) + '</span>';
        }
        if (opt.unit) {
            metaHtml += '<span><i class="fas fa-box"></i> ' + risEditEscapeHtml(opt.unit) + '</span>';
        }
        if (opt.total_stock && opt.total_stock > 0) {
            metaHtml += '<span><i class="fas fa-warehouse"></i> Available: ' + opt.total_stock.toLocaleString() + '</span>';
        }
        metaHtml += '</div>';

        item.innerHTML = '<div>' + risEditEscapeHtml(opt.name) + '</div>' + metaHtml;

        item.addEventListener('click', function() {
            risEditSelectItem(idx, opt);
        });

        dropdown.appendChild(item);
    });

    dropdown.classList.add('open');
}

function risEditUpdateSelectedItem(idx) {
    const state = risEditAutocompleteState[idx];
    if (!state) return;

    const items = state.dropdown.querySelectorAll('.autocomplete-item');
    items.forEach(function(item, index) {
        if (index === state.selectedIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            item.classList.remove('selected');
        }
    });
}

function risEditSelectItem(idx, option) {
    const state = risEditAutocompleteState[idx];
    if (!state) return;

    state.input.value = option.name;

    const catalogItemId = document.getElementById('ris-catalog-item-id-' + idx);
    if (catalogItemId) catalogItemId.value = option.id;

    risEditCloseDropdown(idx);
}

function risEditClearItemData(idx) {
    const catalogItemId = document.getElementById('ris-catalog-item-id-' + idx);
    if (catalogItemId) catalogItemId.value = '';
}

function risEditCloseDropdown(idx) {
    const state = risEditAutocompleteState[idx];
    if (state && state.dropdown) {
        state.dropdown.classList.remove('open');
        state.selectedIndex = -1;
    }
}

function risEditEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function risEditUpdateCount() {
    const n = document.querySelectorAll('#ris-items-body tr').length;
    const el = document.getElementById('ris-edit-row-count');
    if (el) el.textContent = n + ' line item' + (n === 1 ? '' : 's');
}

function risEditAddRow() {
    const idx = risEditRowCount++;
    const tbody = document.getElementById('ris-items-body');
    const tr = document.createElement('tr');
    tr.id = 'ris-row-' + idx;
    tr.innerHTML =
        '<td data-label="Item Description">' +
            '<div class="autocomplete-wrapper" id="ris-autocomplete-wrapper-' + idx + '">' +
                '<input type="text" name="items[' + idx + '][description]" class="form-control ris-desc-input" placeholder="Type item description, then pick from the list..." required autocomplete="off" data-ris-row-idx="' + idx + '">' +
                '<div class="autocomplete-dropdown" id="ris-autocomplete-dropdown-' + idx + '"></div>' +
            '</div>' +
            '<input type="hidden" name="items[' + idx + '][catalog_item_id]" id="ris-catalog-item-id-' + idx + '">' +
        '</td>' +
        '<td data-label="Requested Quantity">' +
            '<input type="number" name="items[' + idx + '][quantity_requested]" id="ris-qty-' + idx + '" class="ris-qty-input form-control" min="1" step="1" placeholder="e.g. 500" required>' +
        '</td>' +
        '<td><button type="button" class="remove-row" onclick="risEditRemoveRow(\'ris-row-' + idx + '\')"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    risEditUpdateCount();

    // Make the new row impossible to miss: scroll to it, flash it, focus it.
    try { tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
    tr.style.transition = 'background-color .6s';
    tr.style.backgroundColor = '#ebf8ff';
    setTimeout(function() { tr.style.backgroundColor = ''; }, 1200);

    setTimeout(function() {
        risEditInitAutocomplete(idx);
        const input = tr.querySelector('input[data-ris-row-idx]');
        if (input) input.focus();
    }, 50);
}

function risEditRemoveRow(id) {
    if (document.querySelectorAll('#ris-items-body tr').length > 1) {
        var row = document.getElementById(id);
        if (row) {
            const idx = id.replace('ris-row-', '');
            if (risEditAutocompleteState[idx]) {
                delete risEditAutocompleteState[idx];
            }
            row.remove();
            risEditUpdateCount();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Wire autocomplete to every pre-filled, unlocked row
    document.querySelectorAll('#ris-items-body input[data-ris-row-idx]').forEach(function(input) {
        risEditInitAutocomplete(input.getAttribute('data-ris-row-idx'));
    });
    risEditUpdateCount();
});
});

// If a description was typed but never picked from the dropdown, link it
// automatically when it exactly matches a catalog name — otherwise the row
// would fail validation even though the name is correct.
document.getElementById('edit-ris-form').addEventListener('submit', function() {
    document.querySelectorAll('#ris-items-body tr').forEach(function(tr) {
        const input = tr.querySelector('input[data-ris-row-idx]');
        if (!input || input.readOnly) return;
        const idx = input.getAttribute('data-ris-row-idx');
        const hidden = document.getElementById('ris-catalog-item-id-' + idx);
        if (!hidden || hidden.value) return;
        const text = input.value.trim().toLowerCase();
        if (!text || risEditAllItems.length === 0) return;
        const match = risEditAllItems.find(function(o) {
            return (o.name || '').trim().toLowerCase() === text;
        });
        if (match) {
            hidden.value = match.id;
            input.value = match.name;
        }
    });
});

// Never allow a double-click to save the same RIS edit twice.
guardFormSubmit(document.getElementById('edit-ris-form'));
</script>
@endpush
@endsection
