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
                @if($errors->any())
                <div class="alert alert-danger" style="margin-bottom:18px">
                    <i class="fas fa-exclamation-triangle" style="margin-top:3px"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin:6px 0 0;padding-left:18px">
                            @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endif

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
                                        <label class="form-label">Date Requested <span style="color:red">*</span></label>
                                        <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', date('Y-m-d')) }}" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Requested By</label>
                                        <input type="text" name="requested_by_name" class="form-control" value="{{ old('requested_by_name', auth()->user()->name) }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Requested By Designation</label>
                                        <input type="text" name="requested_by_designation" class="form-control" value="{{ old('requested_by_designation') }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Entity Name</label>
                                        <input type="text" name="entity_name" class="form-control" value="{{ old('entity_name', 'DSWD Region X') }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Fund Cluster</label>
                                        <input type="text" name="fund_cluster" class="form-control" value="{{ old('fund_cluster') }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Responsibility Center Code</label>
                                        <input type="text" name="responsibility_center_code" class="form-control" value="{{ old('responsibility_center_code') }}">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Office</label>
                                        <input type="text" name="office" class="form-control" value="{{ old('office') }}">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Division</label>
                                        <input type="text" name="division" class="form-control" value="{{ old('division') }}">
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Purpose <span style="color:red">*</span></label>
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
                                                <th style="width:70%">Item Description <span style="color:red">*</span></th>
                                                <th style="width:24%">Requested Quantity <span style="color:red">*</span></th>
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
                        <div class="summary-note">
                            <i class="fas fa-info-circle" style="color:var(--primary)"></i>
                            Only the item description and quantity are needed here. The warehouse, unit cost and DR number are set per item when the RIS is dispatched.
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

@push('styles')
<style>
    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 1200;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .modal-overlay.open {
        opacity: 1;
        visibility: visible;
    }
    .modal-shell {
        width: 95%;
        max-width: 1560px;
        height: calc(100vh - 40px);
        max-height: calc(100vh - 40px);
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 24px 70px rgba(2, 6, 23, 0.35);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transform: translateY(28px) scale(0.985);
        opacity: 0;
        transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.25, 1), opacity 0.2s ease;
    }
    .modal-overlay.open .modal-shell {
        transform: none;
        opacity: 1;
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(180deg, #ffffff, #f9fbfd);
        flex-shrink: 0;
    }
    .modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .modal-header h2 i { color: var(--primary); }
    .modal-subtitle { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
    .modal-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-muted);
        font-size: 18px;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.15s;
    }
    .modal-close:hover { background: #fee2e2; color: var(--danger); }
    .modal-shell > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
    }
    .modal-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 20px 24px;
        background: #f8fafc;
        -webkit-overflow-scrolling: touch;
    }
    .modal-footer {
        flex-shrink: 0;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        padding: 14px 24px;
        border-top: 1px solid var(--border);
        background: #ffffff;
    }
    body.modal-open { overflow: hidden; }

    .subsidy-form-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }
    .subsidy-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        padding: 14px 20px;
        background: var(--card-bg, var(--surface));
        border: 1px solid var(--border);
        border-radius: 10px;
    }
    .summary-left { flex-shrink: 0; }
    .summary-note { font-size: 12.5px; color: var(--text-muted); line-height: 1.6; max-width: 640px; }
    .subsidy-form .form-section { margin-bottom: 20px; }
    .subsidy-form .form-section:last-child { margin-bottom: 0; }

    /* Line Items = a dedicated workspace, not a cramped table */
    .modal-body .line-items-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .modal-body .line-items-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--surface-soft);
        box-shadow: 0 1px 0 var(--border);
    }
    .modal-body .table-wrapper {
        max-width: 100%;
    }
    .modal-body .line-items-table th,
    .modal-body .line-items-table td { padding: 18px 16px; }
    .modal-body .line-items-table td { border-bottom: 1px solid var(--border); }
    .modal-body .line-items-table tbody tr:last-child td { border-bottom: none; }
    .modal-body .line-items-table input,
    .modal-body .line-items-table select {
        width: 100%;
        padding: 13px 14px;
        font-size: 14px;
        border-radius: 7px;
        border-color: #cbd5e0;
        box-sizing: border-box;
    }
    .modal-body .line-items-table .remove-row {
        width: 40px;
        height: 40px;
        border-radius: 7px;
        border: 1px solid #feb2b2;
        background: #fff5f5;
        color: var(--danger);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .modal-body .line-items-table .remove-row:hover { background: #fed7d7; }

    /* Custom Autocomplete Dropdown Styles */
    .autocomplete-wrapper {
        position: relative;
        width: 100%;
    }
    .autocomplete-dropdown {
        position: fixed;
        max-height: 320px;
        min-width: 300px;
        overflow-y: auto;
        background: #ffffff;
        border: 1px solid #cbd5e0;
        border-radius: 7px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        z-index: 9999;
        display: none;
    }
    .autocomplete-dropdown.open {
        display: block;
    }
    .autocomplete-item {
        padding: 12px 14px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
        transition: background-color 0.1s ease;
        font-size: 14px;
        color: #2d3748;
    }
    .autocomplete-item:last-child {
        border-bottom: none;
    }
    .autocomplete-item:hover,
    .autocomplete-item.selected {
        background-color: #ebf8ff;
        color: var(--primary);
    }
    .autocomplete-item .item-meta {
        font-size: 11px;
        color: #718096;
        margin-top: 3px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .autocomplete-item .item-meta span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .autocomplete-no-results {
        padding: 16px 14px;
        text-align: center;
        color: #718096;
        font-size: 13px;
    }
    .desc-input:focus,
    .ris-desc-input:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
    }

    @media (max-width: 1150px) {
        .subsidy-summary { align-items: flex-start; }
    }
    /* On small screens stack the item fields instead of cramming them */
    @media (max-width: 820px) {
        .modal-body .line-items-table,
        .modal-body .line-items-table tbody,
        .modal-body .line-items-table tr,
        .modal-body .line-items-table td {
            display: block;
            width: 100%;
            box-sizing: border-box;
        }
        .modal-body .line-items-table thead { display: none; }
        .modal-body .line-items-table tr {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
            background: #ffffff;
        }
        .modal-body .line-items-table td { padding: 8px 4px; border: none; }
        .modal-body .line-items-table td::before {
            content: attr(data-label);
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .modal-body .line-items-table td[data-label=""]::before,
        .modal-body .line-items-table td:not([data-label])::before { display: none; }
    }
    @media (max-width: 640px) {
        .modal-overlay { padding: 10px; }
        .modal-shell { width: 100%; height: calc(100vh - 20px); max-height: calc(100vh - 20px); }
        .modal-header { padding: 12px 16px; }
        .modal-body { padding: 14px; }
        .modal-footer { padding: 12px 16px; }
    }
</style>
@endpush

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
    if (el) el.textContent = total.toLocaleString('en-PH', { maximumFractionDigits: 2 });
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
            '<input type="number" name="items[' + idx + '][quantity_requested]" id="ris-qty-' + idx + '" class="ris-qty-input form-control" min="0.01" step="0.01" placeholder="e.g. 500" oninput="risCalcTotal()" required>' +
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
</script>
@endpush
