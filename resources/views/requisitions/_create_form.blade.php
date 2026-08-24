@php
    $createModalOpen = $createModalOpen ?? false;
    $modalOnlyPage   = $modalOnlyPage ?? false;
@endphp

<div class="modal-overlay{{ $createModalOpen ? ' open' : '' }}" id="createModal">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="createModalTitle"><i class="fas fa-clipboard-list"></i> New Requisition (RIS)</h2>
                <div class="modal-subtitle">Complete the requisition details below. The warehouse, unit cost, expiry and DR number are set later, when the items are dispatched.</div>
            </div>
            <button type="button" class="modal-close" onclick="closeCreateModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('requisitions.store') }}" method="POST" id="ris-form" class="subsidy-form">
            @csrf
            <div class="modal-body">
                <div class="subsidy-form-grid">
                    <div class="subsidy-main">
                        <!-- RIS Header -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-file-invoice" style="color:var(--primary)"></i> RIS Header</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">RIS No. <span class="req">*</span></label>
                                        <input type="text" name="ris_number" class="form-control {{ $errors->has('ris_number') ? 'is-invalid' : '' }}" value="{{ old('ris_number') }}" placeholder="e.g. RIS-CAM-2026-001" required>
                                        @error('ris_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <small style="color:var(--text-muted);font-size:11px">Official reference number (user-entered). RIS ID will be auto-generated.</small>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Date Requested <span class="req">*</span></label>
                                        <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', date('Y-m-d')) }}" required>
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Requested By</label>
                                        <input type="text" name="requested_by_name" class="form-control" value="{{ old('requested_by_name', auth()->user()->name) }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Requested By Designation</label>
                                        <input type="text" name="requested_by_designation" class="form-control" value="{{ old('requested_by_designation') }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Entity Name</label>
                                        <input type="text" name="entity_name" class="form-control" value="{{ old('entity_name', 'DSWD Region X') }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Fund Cluster</label>
                                        <input type="text" name="fund_cluster" class="form-control" value="{{ old('fund_cluster') }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Responsibility Center Code</label>
                                        <input type="text" name="responsibility_center_code" class="form-control" value="{{ old('responsibility_center_code') }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Office</label>
                                        <input type="text" name="office" class="form-control" value="{{ old('office') }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Division</label>
                                        <input type="text" name="division" class="form-control" value="{{ old('division') }}">
                                    </div>
                                    <div class="form-group" style="visibility:hidden" aria-hidden="true">
                                        <label class="form-label">&nbsp;</label>
                                        <input type="text" class="form-control" tabindex="-1" readonly>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Purpose <span class="req">*</span></label>
                                    <textarea name="purpose" class="form-control {{ $errors->has('purpose') ? 'is-invalid' : '' }}" rows="2" required>{{ old('purpose') }}</textarea>
                                    @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </section>

                        <!-- Requesting LGU -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-city" style="color:var(--primary)"></i> Requesting LGU</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Province</label>
                                        <input type="text" name="province" class="form-control" value="{{ old('province') }}" placeholder="e.g. Cebu">
                                    </div>
                                    <div class="form-group" style="margin-bottom:0">
                                        <label class="form-label">Municipality</label>
                                        <input type="text" name="municipality" class="form-control" value="{{ old('municipality') }}" placeholder="e.g. Lapu-Lapu City">
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Requested Items -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-list" style="color:var(--primary)"></i> Requested Items</h3>
                                <button type="button" class="btn btn-sm btn-primary" onclick="risAddRow()"><i class="fas fa-plus"></i> Add Item</button>
                            </div>
                            <div class="card-body" style="padding:0">
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
                                            {{-- First row added by JS on page load --}}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Request Summary -->
                    <aside class="subsidy-summary">
                        <div class="summary-left">
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px">Requested Quantity</div>
                            <div style="font-size:26px;font-weight:800;color:var(--primary);line-height:1.1" id="ris-grand-total">0</div>
                            <div id="ris-item-count" style="font-size:12px;color:var(--text-muted);margin-top:2px">0 line items</div>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" style="min-width:160px;justify-content:center"><i class="fas fa-paper-plane"></i> Save RIS</button>
            </div>
        </form>
    </div>
</div>


@push('scripts')
<script>
const RIS_ITEMS_API_URL = '{{ route("requisitions.description_items") }}';
let risRowCount = 0;
let risAllItems = [];
const risAutocompleteState = {};

// Load items from API on page load
fetch(RIS_ITEMS_API_URL, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
})
.then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
.then(data => {
    risAllItems = data;
})
.catch(err => {
    console.error('Failed to load RIS items:', err);
    risAllItems = [];
});

function risInitAutocomplete(idx) {
    const input = document.querySelector(`input[data-ris-row-idx="${idx}"]`);
    const dropdown = document.getElementById(`ris-autocomplete-dropdown-${idx}`);
    
    if (!input || !dropdown) return;
    
    risAutocompleteState[idx] = {
        input: input,
        dropdown: dropdown,
        selectedIndex: -1,
        filteredOptions: []
    };
    
    // Position dropdown function
    function positionDropdown() {
        const rect = input.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        const dropdownMaxHeight = 320;
        const spaceBelow = viewportHeight - rect.bottom;
        const spaceAbove = rect.top;
        
        // Determine if dropdown should open above or below
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
    
    // Input event - filter and show dropdown
    input.addEventListener('input', function(e) {
        const value = e.target.value.trim();
        if (value.length === 0) {
            risCloseDropdown(idx);
            risClearItemData(idx);
            return;
        }
        positionDropdown();
        risFilterAndShowDropdown(idx, value);
    });
    
    // Focus event - show dropdown if there's text
    input.addEventListener('focus', function(e) {
        const value = e.target.value.trim();
        if (value.length > 0) {
            positionDropdown();
            risFilterAndShowDropdown(idx, value);
        }
    });
    
    // Click event - show all options
    input.addEventListener('click', function(e) {
        const value = e.target.value.trim();
        if (value.length === 0 && risAllItems.length > 0) {
            positionDropdown();
            risFilterAndShowDropdown(idx, '');
        }
    });
    
    // Reposition on scroll
    const modalBody = document.querySelector('.modal-body');
    if (modalBody) {
        modalBody.addEventListener('scroll', function() {
            if (dropdown.classList.contains('open')) {
                positionDropdown();
            }
        });
    }
    
    // Reposition on window resize
    window.addEventListener('resize', function() {
        if (dropdown.classList.contains('open')) {
            positionDropdown();
        }
    });
    
    // Keyboard navigation
    input.addEventListener('keydown', function(e) {
        const state = risAutocompleteState[idx];
        if (!state || !state.dropdown.classList.contains('open')) return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            state.selectedIndex = Math.min(state.selectedIndex + 1, state.filteredOptions.length - 1);
            risUpdateSelectedItem(idx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
            risUpdateSelectedItem(idx);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (state.selectedIndex >= 0 && state.selectedIndex < state.filteredOptions.length) {
                risSelectItem(idx, state.filteredOptions[state.selectedIndex]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            risCloseDropdown(idx);
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            risCloseDropdown(idx);
        }
    });
}

function risFilterAndShowDropdown(idx, searchTerm) {
    const state = risAutocompleteState[idx];
    if (!state) return;
    
    if (risAllItems.length === 0) {
        state.dropdown.innerHTML = '<div class="autocomplete-no-results"><i class="fas fa-hourglass-half"></i> Loading items...</div>';
        state.dropdown.classList.add('open');
        return;
    }
    
    const lowerSearch = searchTerm.toLowerCase();
    state.filteredOptions = risAllItems.filter(function(opt) {
        return opt.name.toLowerCase().includes(lowerSearch) || 
               (opt.account_code && opt.account_code.toLowerCase().includes(lowerSearch));
    });
    
    state.selectedIndex = -1;
    risRenderDropdown(idx);
}

function risRenderDropdown(idx) {
    const state = risAutocompleteState[idx];
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
            metaHtml += '<span><i class="fas fa-hashtag"></i> ' + risEscapeHtml(opt.account_code) + '</span>';
        }
        if (opt.unit) {
            metaHtml += '<span><i class="fas fa-box"></i> ' + risEscapeHtml(opt.unit) + '</span>';
        }
        if (opt.total_stock && opt.total_stock > 0) {
            metaHtml += '<span><i class="fas fa-warehouse"></i> Available: ' + opt.total_stock.toLocaleString() + '</span>';
        }
        metaHtml += '</div>';
        
        item.innerHTML = '<div>' + risEscapeHtml(opt.name) + '</div>' + metaHtml;
        
        item.addEventListener('click', function() {
            risSelectItem(idx, opt);
        });
        
        dropdown.appendChild(item);
    });
    
    dropdown.classList.add('open');
}

function risUpdateSelectedItem(idx) {
    const state = risAutocompleteState[idx];
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

function risSelectItem(idx, option) {
    const state = risAutocompleteState[idx];
    if (!state) return;
    
    // Set the input value
    state.input.value = option.name;
    
    // Set hidden catalog_item_id field
    const catalogItemId = document.getElementById('ris-catalog-item-id-' + idx);
    if (catalogItemId) catalogItemId.value = option.id;
    
    risCloseDropdown(idx);
}

function risClearItemData(idx) {
    const catalogItemId = document.getElementById('ris-catalog-item-id-' + idx);
    if (catalogItemId) catalogItemId.value = '';
}

function risCloseDropdown(idx) {
    const state = risAutocompleteState[idx];
    if (state && state.dropdown) {
        state.dropdown.classList.remove('open');
        state.selectedIndex = -1;
    }
}

function risEscapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openCreateModal() {
    const m = document.getElementById('createModal');
    if (!m) return;
    m.classList.add('open');
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', onModalEsc);
    const first = m.querySelector('input[name="date_requested"]');
    if (first) setTimeout(function() { first.focus(); }, 250);
}

function closeCreateModal() {
    const m = document.getElementById('createModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onModalEsc);
    @if($modalOnlyPage)
    window.location.href = '{{ route('requisitions.index') }}';
    @endif
}

function onModalEsc(e) {
    if (e.key === 'Escape') {
        // Check if any dropdown is open first
        const anyDropdownOpen = Object.values(risAutocompleteState).some(state => 
            state.dropdown && state.dropdown.classList.contains('open')
        );
        if (!anyDropdownOpen) {
            closeCreateModal();
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('createModal');
    if (!overlay) return;
    if (overlay.classList.contains('open')) document.body.classList.add('modal-open');
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeCreateModal();
    });
    
    // Add first row after a short delay to ensure items are loaded
    setTimeout(function() {
        risAddRow();
    }, 100);
    
    risCalcTotal();
    risUpdateItemCount();
});

@if($errors->any() && !$modalOnlyPage)
document.addEventListener('DOMContentLoaded', openCreateModal);
@endif

function risUpdateItemCount() {
    const el = document.getElementById('ris-item-count');
    if (!el) return;
    const n = document.querySelectorAll('#ris-items-body tr').length;
    el.textContent = n + ' line item' + (n === 1 ? '' : 's');
}

function risCalcTotal() {
    var total = 0;
    document.querySelectorAll('#ris-items-body .ris-qty-input').forEach(function(el) {
        total += parseFloat(el.value) || 0;
    });
    const el = document.getElementById('ris-grand-total');
    if (el) el.textContent = total.toLocaleString('en-PH', { maximumFractionDigits: 0 });
}

function risAddRow() {
    const idx = risRowCount++;
    const tbody = document.getElementById('ris-items-body');
    const tr = document.createElement('tr');
    tr.id = 'ris-row-' + idx;
    tr.innerHTML =
        '<td data-label="Item Description">' +
            '<div class="autocomplete-wrapper" id="ris-autocomplete-wrapper-' + idx + '">' +
                '<input type="text" class="form-control ris-desc-input" placeholder="Type item description..." required autocomplete="off" data-ris-row-idx="' + idx + '">' +
                '<div class="autocomplete-dropdown" id="ris-autocomplete-dropdown-' + idx + '"></div>' +
            '</div>' +
            '<input type="hidden" name="items[' + idx + '][catalog_item_id]" id="ris-catalog-item-id-' + idx + '">' +
        '</td>' +
        '<td data-label="Requested Quantity">' +
            '<input type="number" name="items[' + idx + '][quantity_requested]" id="ris-qty-' + idx + '" class="ris-qty-input form-control" min="1" step="1" placeholder="e.g. 500" oninput="risCalcTotal()" required>' +
        '</td>' +
        '<td><button type="button" class="remove-row" onclick="risRemoveRow(\'ris-row-' + idx + '\')"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    
    risUpdateItemCount();
    
    // Initialize autocomplete for the new row
    setTimeout(function() {
        risInitAutocomplete(idx);
    }, 50);
}

function risRemoveRow(id) {
    if (document.querySelectorAll('#ris-items-body tr').length > 1) {
        var row = document.getElementById(id);
        if (row) {
            // Extract row index and clean up state
            const idx = id.replace('ris-row-', '');
            if (risAutocompleteState[idx]) {
                delete risAutocompleteState[idx];
            }
            row.remove();
        }
        risCalcTotal();
        risUpdateItemCount();
    }
}

// Never allow a double-click to submit the same RIS twice.
guardFormSubmit(document.getElementById('ris-form'));
</script>
@endpush
