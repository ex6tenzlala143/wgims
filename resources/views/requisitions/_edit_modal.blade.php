<div class="modal-overlay{{ (($editModalOpen ?? false) || $errors->any()) ? ' open' : '' }}" id="editRisModal">
    <div class="modal-shell" style="max-width:1200px;height:auto;max-height:calc(100vh - 32px)" role="dialog" aria-modal="true" aria-labelledby="editRisModalTitle">

        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="editRisModalTitle"><i class="fas fa-edit"></i> Edit Requisition (RIS)</h2>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                    RIS ID: <code>{{ $requisition->ris_code ?? $requisition->ris_id }}</code>
                    &nbsp;·&nbsp; RIS No.: <strong>{{ $requisition->ris_number }}</strong>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeEditRisModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('requisitions.update', $requisition->id) }}" method="POST" id="edit-ris-modal-form" class="subsidy-form">
            @csrf @method('PUT')
            <div class="modal-body">

                {{-- ── RIS Header (same order/sections as the creation form) ── --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-file-invoice" style="color:var(--primary)"></i> RIS Header</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">RIS No. <span class="req">*</span></label>
                                <input type="text" name="ris_number" class="form-control {{ $errors->has('ris_number') ? 'is-invalid' : '' }}" value="{{ old('ris_number', $requisition->ris_number) }}" placeholder="e.g. RIS-CAM-2026-001" required>
                                @error('ris_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small style="color:var(--text-muted);font-size:11px">Official reference number (user-entered). RIS ID stays unchanged.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Date Requested <span class="req">*</span></label>
                                <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', $requisition->date_requested?->format('Y-m-d')) }}" required>
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
                            <textarea name="purpose" class="form-control {{ $errors->has('purpose') ? 'is-invalid' : '' }}" rows="2" required>{{ old('purpose', $requisition->purpose) }}</textarea>
                            @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>

                {{-- ── Requesting LGU ── --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-city" style="color:var(--primary)"></i> Requesting LGU</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row cols-2">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Province</label>
                                <input type="text" name="province" class="form-control" value="{{ old('province', $requisition->province) }}" placeholder="e.g. Cebu">
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Municipality</label>
                                <input type="text" name="municipality" class="form-control" value="{{ old('municipality', $requisition->municipality) }}" placeholder="e.g. Lapu-Lapu City">
                            </div>
                        </div>
                    </div>
                </section>

                {{-- ── Requested Items (creation-style table, pre-filled) ── --}}
                <section class="card form-section">
                    <div class="card-header" style="justify-content:space-between">
                        <h3><i class="fas fa-list" style="color:var(--primary)"></i> Requested Items</h3>
                        <button type="button" class="btn btn-sm btn-primary" onclick="risModalEditAddRow()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>

                    @foreach($errors->keys() as $k)
                        @if($k === 'items' || str_starts_with($k, 'items.'))
                            <div style="padding:10px 20px;background:#fff5f5;border-bottom:1px solid var(--border);color:var(--danger);font-size:13px">
                                <i class="fas fa-exclamation-triangle"></i> {{ $errors->first($k) }}
                            </div>
                        @endif
                    @endforeach

                    <div class="table-wrapper">
                        <table class="line-items-table" id="ris-modal-edit-items-table">
                            <thead>
                                <tr>
                                    <th style="width:70%">Item Description <span class="req">*</span></th>
                                    <th style="width:24%">Requested Quantity <span class="req">*</span></th>
                                    <th style="width:6%"></th>
                                </tr>
                            </thead>
                            <tbody id="ris-modal-edit-items-body">
                                @php
                                    // Prefer failed-submission input so newly typed rows survive
                                    // a validation round-trip instead of vanishing.
                                    $oldInput = old('items');
                                    if (is_array($oldInput) && count($oldInput)) {
                                        $byId = $requisition->items->keyBy('id');
                                        $modalEditRows = [];
                                        foreach (array_values($oldInput) as $l) {
                                            $ref = ! empty($l['id']) ? $byId->get((int) $l['id']) : null;
                                            $issued = $ref ? (float) $ref->quantity_issued : 0;
                                            $modalEditRows[] = [
                                                'id' => $l['id'] ?? null,
                                                'catalog_item_id' => $l['catalog_item_id'] ?? null,
                                                'description' => $l['description'] ?? ($ref->description ?? ($ref->item?->description ?? '')),
                                                'quantity_requested' => $l['quantity_requested'] ?? '',
                                                'locked' => $ref ? ($issued > 0 || $ref->dispatchItems->isNotEmpty()) : false,
                                                'issued' => $issued,
                                            ];
                                        }
                                    } else {
                                        $modalEditRows = $requisition->items->map(fn($ri) => [
                                            'id' => $ri->id,
                                            'catalog_item_id' => $ri->catalog_item_id,
                                            'description' => $ri->description ?? ($ri->item?->description ?? ''),
                                            'quantity_requested' => $ri->quantity_requested,
                                            'locked' => $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty(),
                                            'issued' => (float) $ri->quantity_issued,
                                        ])->all();
                                    }
                                @endphp
                                @foreach($modalEditRows as $idx => $row)
                                <tr id="ris-modal-edit-row-{{ $idx }}">
                                    <td data-label="Item Description">
                                        <div class="autocomplete-wrapper" id="ris-modal-edit-autocomplete-wrapper-{{ $idx }}">
                                            <input type="text" class="form-control ris-desc-input"
                                                   name="items[{{ $idx }}][description]"
                                                   value="{{ $row['description'] }}"
                                                   placeholder="Type item description, then pick from the list..."
                                                   {{ $row['locked'] ? 'readonly' : 'required' }} autocomplete="off"
                                                   data-modal-edit-row-idx="{{ $idx }}">
                                            <div class="autocomplete-dropdown" id="ris-modal-edit-autocomplete-dropdown-{{ $idx }}"></div>
                                        </div>
                                        @if(! empty($row['id']))
                                        <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $row['id'] }}">
                                        @endif
                                        <input type="hidden" name="items[{{ $idx }}][catalog_item_id]" id="ris-modal-edit-catalog-item-id-{{ $idx }}" value="{{ $row['catalog_item_id'] }}">
                                        @if($row['locked'])
                                            <small style="color:var(--text-muted);font-size:11px">
                                                <i class="fas fa-lock"></i> Locked — {{ number_format($row['issued']) }} already issued; item cannot be changed and quantity cannot go below issued.
                                            </small>
                                        @endif
                                    </td>
                                    <td data-label="Requested Quantity">
                                        <input type="number" name="items[{{ $idx }}][quantity_requested]" id="ris-modal-edit-qty-{{ $idx }}"
                                               class="ris-qty-input form-control"
                                               min="{{ $row['locked'] ? max(1, (int) ceil($row['issued'])) : 1 }}" step="1"
                                               value="{{ $row['quantity_requested'] }}" placeholder="e.g. 500" required>
                                    </td>
                                    <td><button type="button" class="remove-row" onclick="risModalEditRemoveRow('ris-modal-edit-row-{{ $idx }}')"><i class="fas fa-times"></i></button></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
                        <button type="button" class="btn btn-sm btn-outline" onclick="risModalEditAddRow()">
                            <i class="fas fa-plus"></i> Add Another Item
                        </button>
                        <span id="ris-modal-edit-row-count" style="font-size:12px;color:var(--text-muted)"></span>
                    </div>
                </section>

            </div>{{-- /modal-body --}}

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditRisModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="min-width:180px;justify-content:center">
                    <i class="fas fa-save"></i> Update RIS
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
'use strict';

// Same item picker as the RIS creation form, pre-filled with this RIS's
// lines. Locked (already dispatched) rows stay read-only. Warehouse is
// chosen later at dispatch/approve time — exactly like creation.
const RIS_MODAL_EDIT_API_URL = '{{ route("requisitions.description_items") }}';
let risModalEditRowCount = (function () {
    let max = 0;
    document.querySelectorAll('#ris-modal-edit-items-body tr[id^="ris-modal-edit-row-"]').forEach(function (tr) {
        const n = parseInt(tr.id.replace('ris-modal-edit-row-', ''), 10);
        if (!isNaN(n) && n >= max) max = n + 1;
    });
    return max;
})();
let risModalEditAllItems = [];
const risModalEditState = {};

fetch(RIS_MODAL_EDIT_API_URL, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
})
.then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
.then(data => { risModalEditAllItems = data; })
.catch(err => {
    console.error('Failed to load RIS items:', err);
    risModalEditAllItems = [];
});

function risModalEditUpdateCount() {
    const n = document.querySelectorAll('#ris-modal-edit-items-body tr').length;
    const el = document.getElementById('ris-modal-edit-row-count');
    if (el) el.textContent = n + ' line item' + (n === 1 ? '' : 's');
}

function risModalEditEsc(e) {
    if (e.key === 'Escape') closeEditRisModal();
}

window.openEditRisModal = function () {
    const m = document.getElementById('editRisModal');
    if (!m) return;
    m.classList.add('open');
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', risModalEditEsc);
    risModalEditUpdateCount();
    setTimeout(function () {
        const first = m.querySelector('input[name="ris_number"]');
        if (first) first.focus();
    }, 250);
};

window.closeEditRisModal = function () {
    const m = document.getElementById('editRisModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', risModalEditEsc);
};

function risModalEditInitAutocomplete(idx) {
    const input = document.querySelector(`input[data-modal-edit-row-idx="${idx}"]`);
    const dropdown = document.getElementById(`ris-modal-edit-autocomplete-dropdown-${idx}`);

    if (!input || !dropdown || input.readOnly) return;

    risModalEditState[idx] = { input: input, dropdown: dropdown, selectedIndex: -1, filteredOptions: [] };

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

    input.addEventListener('input', function (e) {
        const value = e.target.value.trim();
        if (value.length === 0) {
            risModalEditCloseDropdown(idx);
            risModalEditClearItemData(idx);
            return;
        }
        positionDropdown();
        risModalEditFilterAndShowDropdown(idx, value);
    });

    input.addEventListener('focus', function (e) {
        const value = e.target.value.trim();
        if (value.length > 0) {
            positionDropdown();
            risModalEditFilterAndShowDropdown(idx, value);
        }
    });

    input.addEventListener('click', function () {
        const value = input.value.trim();
        if (value.length === 0 && risModalEditAllItems.length > 0) {
            positionDropdown();
            risModalEditFilterAndShowDropdown(idx, '');
        }
    });

    const modalBody = document.querySelector('#editRisModal .modal-body');
    if (modalBody) {
        modalBody.addEventListener('scroll', function () {
            if (dropdown.classList.contains('open')) positionDropdown();
        });
    }
    window.addEventListener('resize', function () {
        if (dropdown.classList.contains('open')) positionDropdown();
    });

    input.addEventListener('keydown', function (e) {
        const state = risModalEditState[idx];
        if (!state || !state.dropdown.classList.contains('open')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            state.selectedIndex = Math.min(state.selectedIndex + 1, state.filteredOptions.length - 1);
            risModalEditUpdateSelected(idx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
            risModalEditUpdateSelected(idx);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (state.selectedIndex >= 0 && state.selectedIndex < state.filteredOptions.length) {
                risModalEditSelectItem(idx, state.filteredOptions[state.selectedIndex]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            risModalEditCloseDropdown(idx);
        }
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            risModalEditCloseDropdown(idx);
        }
    });
}

function risModalEditFilterAndShowDropdown(idx, searchTerm) {
    const state = risModalEditState[idx];
    if (!state) return;
    if (risModalEditAllItems.length === 0) {
        state.dropdown.innerHTML = '<div class="autocomplete-no-results"><i class="fas fa-hourglass-half"></i> Loading items...</div>';
        state.dropdown.classList.add('open');
        return;
    }
    const lowerSearch = searchTerm.toLowerCase();
    state.filteredOptions = risModalEditAllItems.filter(function (opt) {
        return opt.name.toLowerCase().includes(lowerSearch) ||
            (opt.account_code && opt.account_code.toLowerCase().includes(lowerSearch));
    });
    state.selectedIndex = -1;
    risModalEditRenderDropdown(idx);
}

function risModalEditRenderDropdown(idx) {
    const state = risModalEditState[idx];
    if (!state) return;
    const dropdown = state.dropdown;
    dropdown.innerHTML = '';
    if (state.filteredOptions.length === 0) {
        dropdown.innerHTML = '<div class="autocomplete-no-results"><i class="fas fa-search"></i> No matching items found</div>';
        dropdown.classList.add('open');
        return;
    }
    state.filteredOptions.forEach(function (opt, index) {
        const item = document.createElement('div');
        item.className = 'autocomplete-item';
        if (index === state.selectedIndex) item.classList.add('selected');
        let metaHtml = '<div class="item-meta">';
        if (opt.account_code) metaHtml += '<span><i class="fas fa-hashtag"></i> ' + risModalEditEscapeHtml(opt.account_code) + '</span>';
        if (opt.unit) metaHtml += '<span><i class="fas fa-box"></i> ' + risModalEditEscapeHtml(opt.unit) + '</span>';
        if (opt.total_stock && opt.total_stock > 0) metaHtml += '<span><i class="fas fa-warehouse"></i> Available: ' + opt.total_stock.toLocaleString() + '</span>';
        metaHtml += '</div>';
        item.innerHTML = '<div>' + risModalEditEscapeHtml(opt.name) + '</div>' + metaHtml;
        item.addEventListener('click', function () { risModalEditSelectItem(idx, opt); });
        dropdown.appendChild(item);
    });
    dropdown.classList.add('open');
}

function risModalEditUpdateSelected(idx) {
    const state = risModalEditState[idx];
    if (!state) return;
    state.dropdown.querySelectorAll('.autocomplete-item').forEach(function (item, index) {
        if (index === state.selectedIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            item.classList.remove('selected');
        }
    });
}

function risModalEditSelectItem(idx, option) {
    const state = risModalEditState[idx];
    if (!state) return;
    state.input.value = option.name;
    const hidden = document.getElementById('ris-modal-edit-catalog-item-id-' + idx);
    if (hidden) hidden.value = option.id;
    risModalEditCloseDropdown(idx);
}

function risModalEditClearItemData(idx) {
    const hidden = document.getElementById('ris-modal-edit-catalog-item-id-' + idx);
    if (hidden) hidden.value = '';
}

function risModalEditCloseDropdown(idx) {
    const state = risModalEditState[idx];
    if (state && state.dropdown) {
        state.dropdown.classList.remove('open');
        state.selectedIndex = -1;
    }
}

function risModalEditEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.risModalEditAddRow = function () {
    const idx = risModalEditRowCount++;
    const tbody = document.getElementById('ris-modal-edit-items-body');
    const tr = document.createElement('tr');
    tr.id = 'ris-modal-edit-row-' + idx;
    tr.innerHTML =
        '<td data-label="Item Description">' +
            '<div class="autocomplete-wrapper" id="ris-modal-edit-autocomplete-wrapper-' + idx + '">' +
                '<input type="text" name="items[' + idx + '][description]" class="form-control ris-desc-input" placeholder="Type item description, then pick from the list..." required autocomplete="off" data-modal-edit-row-idx="' + idx + '">' +
                '<div class="autocomplete-dropdown" id="ris-modal-edit-autocomplete-dropdown-' + idx + '"></div>' +
            '</div>' +
            '<input type="hidden" name="items[' + idx + '][catalog_item_id]" id="ris-modal-edit-catalog-item-id-' + idx + '">' +
        '</td>' +
        '<td data-label="Requested Quantity">' +
            '<input type="number" name="items[' + idx + '][quantity_requested]" class="ris-qty-input form-control" min="1" step="1" placeholder="e.g. 500" required>' +
        '</td>' +
        '<td><button type="button" class="remove-row" onclick="risModalEditRemoveRow(\'ris-modal-edit-row-' + idx + '\')"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    risModalEditUpdateCount();
    try { tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
    tr.style.transition = 'background-color .6s';
    tr.style.backgroundColor = '#ebf8ff';
    setTimeout(function () { tr.style.backgroundColor = ''; }, 1200);
    setTimeout(function () {
        risModalEditInitAutocomplete(idx);
        const input = tr.querySelector('input[data-modal-edit-row-idx]');
        if (input) input.focus();
    }, 50);
};

window.risModalEditRemoveRow = function (id) {
    if (document.querySelectorAll('#ris-modal-edit-items-body tr').length > 1) {
        const row = document.getElementById(id);
        if (row) {
            const idx = id.replace('ris-modal-edit-row-', '');
            if (risModalEditState[idx]) delete risModalEditState[idx];
            row.remove();
            risModalEditUpdateCount();
        }
    }
};

document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('editRisModal');
    if (!overlay) return;
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeEditRisModal();
    });
    document.querySelectorAll('#ris-modal-edit-items-body input[data-modal-edit-row-idx]').forEach(function (input) {
        risModalEditInitAutocomplete(input.getAttribute('data-modal-edit-row-idx'));
    });
    risModalEditUpdateCount();
    if (typeof guardFormSubmit === 'function') guardFormSubmit(document.getElementById('edit-ris-modal-form'));
    @if($editModalOpen)
    openEditRisModal();
    @endif
});

// Typed-but-unpicked descriptions that exactly match a catalog name are
// linked automatically on save so the row does not fail validation.
document.getElementById('edit-ris-modal-form').addEventListener('submit', function () {
    document.querySelectorAll('#ris-modal-edit-items-body tr').forEach(function (tr) {
        const input = tr.querySelector('input[data-modal-edit-row-idx]');
        if (!input || input.readOnly) return;
        const idx = input.getAttribute('data-modal-edit-row-idx');
        const hidden = document.getElementById('ris-modal-edit-catalog-item-id-' + idx);
        if (!hidden || hidden.value) return;
        const text = input.value.trim().toLowerCase();
        if (!text || risModalEditAllItems.length === 0) return;
        const match = risModalEditAllItems.find(function (o) {
            return (o.name || '').trim().toLowerCase() === text;
        });
        if (match) {
            hidden.value = match.id;
            input.value = match.name;
        }
    });
});

})();
</script>
@endpush
