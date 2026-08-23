@php
    $createModalOpen = $createModalOpen ?? false;
    $modalOnlyPage   = $modalOnlyPage ?? false;

    $unitOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::UNITS as $uKey => $uLabel) {
        $unitOptionsHtml .= '<option value="' . $uKey . '">' . $uLabel . '</option>';
    }

    $catOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::getCategories() as $cKey => $cCat) {
        $catOptionsHtml .= '<option value="' . $cKey . '">' . $cCat['label'] . '</option>';
    }

    // Searchable item dropdown sources: configured item names (per category)
    // take priority; existing inventory items remain available as a fallback.
    $catalogItems = $catalogItems ?? collect();
    $datalistOptions = $catalogItems
        ->filter(fn ($ci) => $ci->is_active && $ci->category)
        ->map(fn ($ci) => [
            'id'           => $ci->id,
            'name'         => $ci->name,
            'account_code' => $ci->account_code,
            'category'     => $ci->category->key,
            'unit'         => '',
            'source'       => 'catalog',
        ])
        ->concat(
            $items->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->description,
                'account_code' => App\Models\Item::getAccountCodeForCategory($i->category),
                'category'     => $i->category,
                'unit'         => $i->unit,
                'source'       => 'item',
            ])
        )
        ->unique('name')
        ->values();

    $categoryCodes = collect(App\Models\Item::getCategories())
        ->mapWithKeys(fn ($c, $key) => [$key => $c['account_code']]);
@endphp

<div class="modal-overlay{{ $createModalOpen ? ' open' : '' }}" id="createModal">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="createModalTitle"><i class="fas fa-truck-loading"></i> New Delivery/Subsidy</h2>
                <div class="modal-subtitle">Complete the subsidy details below. Unit cost and destination warehouse are chosen later, when the delivery is dispatched.</div>
            </div>
            <button type="button" class="modal-close" onclick="closeCreateModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('delivery_subsidies.store') }}" method="POST" id="subsidy-form" class="subsidy-form">
            @csrf
            <div class="modal-body">
                <div class="subsidy-form-grid">
                    <div class="subsidy-main">
                        <!-- Subsidy Details -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Subsidy Details</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">Date <span style="color:red">*</span></label>
                                        <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Supplier/Subsidy <span style="color:red">*</span></label>
                                        <select name="supplier_id" class="form-control" required>
                                            <option value="">— Select Supplier/Subsidy —</option>
                                            @foreach($suppliers as $s)
                                            <option value="{{ $s->id }}" {{ old('supplier_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">RIS No. <span style="color:red">*</span></label>
                                        <input type="text" name="ris_number" class="form-control" value="{{ old('ris_number') }}" placeholder="e.g. RIS-2026-001" required>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom:0">
                                    <label class="form-label">Remarks</label>
                                    <textarea name="remarks" class="form-control" rows="2">{{ old('remarks') }}</textarea>
                                </div>
                            </div>
                        </section>

                        <!-- Line Items -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-list" style="color:var(--primary)"></i> Line Items</h3>
                                <button type="button" class="btn btn-sm btn-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-wrapper">
                                    <table class="line-items-table" id="items-table">
                                        <thead>
                                            <tr>
                                                <th style="width:44%">Description <span style="color:red">*</span></th>
                                                <th style="width:12%">Unit <span style="color:red">*</span></th>
                                                <th style="width:18%">Category <span style="color:red">*</span></th>
                                                <th style="width:20%">Quantity Requested</th>
                                                <th style="width:6%"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="items-body">
                                            <tr id="row-0">
                                                <td data-label="Description">
                                                    <div class="autocomplete-wrapper" id="autocomplete-wrapper-0">
                                                        <input type="text" name="items[0][description]"
                                                            class="form-control desc-input"
                                                            placeholder="Type item description..." required
                                                            autocomplete="off"
                                                            style="color:#000;background:#fff"
                                                            data-row-idx="0">
                                                        <div class="autocomplete-dropdown" id="autocomplete-dropdown-0"></div>
                                                    </div>
                                                    <input type="hidden" name="items[0][item_id]" id="item-id-0">
                                                    <input type="hidden" name="items[0][catalog_item_id]" id="catalog-item-id-0">
                                                    <input type="hidden" name="items[0][account_code]" id="account-code-0">
                                                </td>
                                                <td data-label="Unit">
                                                    <select name="items[0][unit]" id="unit-0" class="form-control" required style="color:#000;background:#fff">
                                                        <option value="">—</option>
                                                        @foreach(App\Models\Item::UNITS as $key => $label)
                                                        <option value="{{ $key }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td data-label="Category">
                                                    <select name="items[0][category]" id="category-0" class="form-control" required style="color:#000;background:#fff">
                                                        <option value="">—</option>
                                                        @foreach(App\Models\Item::getCategories() as $key => $cat)
                                                        <option value="{{ $key }}">{{ $cat['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td data-label="Quantity Requested"><input type="number" name="items[0][quantity]" class="qty-input form-control" min="1" step="1" oninput="calcTotal()" required style="color:#000;background:#fff"></td>
                                                <td><button type="button" class="remove-row" onclick="removeRow('row-0')"><i class="fas fa-times"></i></button></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Order Summary -->
                    <aside class="subsidy-summary">
                        <div class="summary-left">
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px">Requested Quantity</div>
                            <div style="font-size:26px;font-weight:800;color:var(--primary);line-height:1.1" id="grand-total">0</div>
                            <div id="item-count" style="font-size:12px;color:var(--text-muted);margin-top:2px">0 line items</div>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" style="min-width:160px;justify-content:center"><i class="fas fa-save"></i> Save Subsidy</button>
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
        /* Removed fixed min-height that was causing the blank space */
        max-height: calc(100vh - 420px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
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
    .autocomplete-item .item-category {
        font-size: 11px;
        color: #718096;
        margin-top: 2px;
    }
    .autocomplete-no-results {
        padding: 16px 14px;
        text-align: center;
        color: #718096;
        font-size: 13px;
    }
    .desc-input:focus {
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
let rowCount = 1;
const allOptions   = {!! json_encode($datalistOptions) !!};
const categoryCodes = {!! json_encode($categoryCodes) !!};
const unitOptions = {!! json_encode($unitOptionsHtml) !!};
const catOptions  = {!! json_encode($catOptionsHtml) !!};

// Autocomplete state management
const autocompleteState = {};

function initAutocomplete(idx) {
    const input = document.querySelector(`input[data-row-idx="${idx}"]`);
    const dropdown = document.getElementById(`autocomplete-dropdown-${idx}`);
    
    if (!input || !dropdown) return;
    
    autocompleteState[idx] = {
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
            closeDropdown(idx);
            clearItemData(idx);
            return;
        }
        positionDropdown();
        filterAndShowDropdown(idx, value);
    });
    
    // Focus event - show dropdown if there's text
    input.addEventListener('focus', function(e) {
        const value = e.target.value.trim();
        if (value.length > 0) {
            positionDropdown();
            filterAndShowDropdown(idx, value);
        }
    });
    
    // Click event - show all options
    input.addEventListener('click', function(e) {
        positionDropdown();
        const value = e.target.value.trim();
        if (value.length === 0) {
            filterAndShowDropdown(idx, '');
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
        const state = autocompleteState[idx];
        if (!state || !state.dropdown.classList.contains('open')) return;
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            state.selectedIndex = Math.min(state.selectedIndex + 1, state.filteredOptions.length - 1);
            updateSelectedItem(idx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            state.selectedIndex = Math.max(state.selectedIndex - 1, -1);
            updateSelectedItem(idx);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (state.selectedIndex >= 0 && state.selectedIndex < state.filteredOptions.length) {
                selectItem(idx, state.filteredOptions[state.selectedIndex]);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDropdown(idx);
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            closeDropdown(idx);
        }
    });
}

function filterAndShowDropdown(idx, searchTerm) {
    const state = autocompleteState[idx];
    if (!state) return;
    
    const lowerSearch = searchTerm.toLowerCase();
    state.filteredOptions = allOptions.filter(function(opt) {
        return opt.name.toLowerCase().includes(lowerSearch);
    });
    
    state.selectedIndex = -1;
    renderDropdown(idx);
}

function renderDropdown(idx) {
    const state = autocompleteState[idx];
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
        
        const categoryLabel = getCategoryLabel(opt.category);
        item.innerHTML = '<div>' + escapeHtml(opt.name) + '</div>' +
            '<div class="item-category">' + escapeHtml(categoryLabel) + '</div>';
        
        item.addEventListener('click', function() {
            selectItem(idx, opt);
        });
        
        dropdown.appendChild(item);
    });
    
    dropdown.classList.add('open');
}

function updateSelectedItem(idx) {
    const state = autocompleteState[idx];
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

function selectItem(idx, option) {
    const state = autocompleteState[idx];
    if (!state) return;
    
    // Set the input value
    state.input.value = option.name;
    
    // Set hidden fields
    const itemId  = document.getElementById('item-id-' + idx);
    const catId   = document.getElementById('catalog-item-id-' + idx);
    const acct    = document.getElementById('account-code-' + idx);
    const unitSel = document.getElementById('unit-' + idx);
    const catSel  = document.getElementById('category-' + idx);
    
    if (option.source === 'catalog') {
        if (itemId) itemId.value = '';
        if (catId) catId.value = option.id;
        if (acct) acct.value = option.account_code || '';
        if (catSel) catSel.value = option.category || '';
    } else {
        if (catId) catId.value = '';
        if (itemId) itemId.value = option.id;
        if (acct) acct.value = categoryCodes[option.category] || option.account_code || '';
        if (unitSel) unitSel.value = option.unit || '';
        if (catSel) catSel.value = option.category || '';
    }
    
    closeDropdown(idx);
}

function clearItemData(idx) {
    const itemId = document.getElementById('item-id-' + idx);
    const catId = document.getElementById('catalog-item-id-' + idx);
    const acct = document.getElementById('account-code-' + idx);
    
    if (itemId) itemId.value = '';
    if (catId) catId.value = '';
    if (acct) acct.value = '';
}

function closeDropdown(idx) {
    const state = autocompleteState[idx];
    if (state && state.dropdown) {
        state.dropdown.classList.remove('open');
        state.selectedIndex = -1;
    }
}

function getCategoryLabel(key) {
    const categories = {!! json_encode(collect(App\Models\Item::getCategories())->mapWithKeys(fn($c, $k) => [$k => $c['label']])) !!};
    return categories[key] || key || 'Unknown';
}

function escapeHtml(text) {
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
    const first = m.querySelector('input[name="date"]');
    if (first) setTimeout(function() { first.focus(); }, 250);
}

function closeCreateModal() {
    const m = document.getElementById('createModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onModalEsc);
    @if($modalOnlyPage)
    window.location.href = '{{ route('delivery_subsidies.index') }}';
    @endif
}

function onModalEsc(e) {
    if (e.key === 'Escape') {
        // Check if any dropdown is open first
        const anyDropdownOpen = Object.values(autocompleteState).some(state => 
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
    calcTotal();
    updateItemCount();
    
    // Initialize autocomplete for row 0
    initAutocomplete(0);
});

@if($errors->any() && !$modalOnlyPage)
document.addEventListener('DOMContentLoaded', openCreateModal);
@endif

function updateItemCount() {
    const el = document.getElementById('item-count');
    if (!el) return;
    const n = document.querySelectorAll('#items-body tr').length;
    el.textContent = n + ' line item' + (n === 1 ? '' : 's');
}

function addRow() {
    const idx   = rowCount++;
    const tbody = document.getElementById('items-body');
    const tr    = document.createElement('tr');
    tr.id = 'row-' + idx;
    tr.innerHTML =
        '<td data-label="Description">' +
            '<div class="autocomplete-wrapper" id="autocomplete-wrapper-' + idx + '">' +
                '<input type="text" name="items[' + idx + '][description]" class="form-control desc-input" placeholder="Type or pick item name..." required autocomplete="off" style="color:#000;background:#fff" data-row-idx="' + idx + '">' +
                '<div class="autocomplete-dropdown" id="autocomplete-dropdown-' + idx + '"></div>' +
            '</div>' +
            '<input type="hidden" name="items[' + idx + '][item_id]" id="item-id-' + idx + '">' +
            '<input type="hidden" name="items[' + idx + '][catalog_item_id]" id="catalog-item-id-' + idx + '">' +
            '<input type="hidden" name="items[' + idx + '][account_code]" id="account-code-' + idx + '">' +
        '</td>' +
        '<td data-label="Unit"><select name="items[' + idx + '][unit]" id="unit-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + unitOptions + '</select></td>' +
        '<td data-label="Category"><select name="items[' + idx + '][category]" id="category-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + catOptions + '</select></td>' +
        '<td data-label="Quantity Requested"><input type="number" name="items[' + idx + '][quantity]" class="qty-input form-control" min="1" step="1" oninput="calcTotal()" required style="color:#000;background:#fff"></td>' +
        '<td><button type="button" class="remove-row" onclick="removeRow(\'row-' + idx + '\')"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    updateItemCount();
    
    // Initialize autocomplete for the new row
    setTimeout(function() {
        initAutocomplete(idx);
    }, 50);
}

function calcTotal() {
    var total = 0;
    document.querySelectorAll('.qty-input').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('grand-total').textContent = total.toLocaleString('en-PH', {maximumFractionDigits: 0});
}

function removeRow(id) {
    if (document.querySelectorAll('#items-body tr').length > 1) {
        var row = document.getElementById(id);
        if (row) {
            // Extract row index and clean up state
            const idx = id.replace('row-', '');
            if (autocompleteState[idx]) {
                delete autocompleteState[idx];
            }
            row.remove();
        }
        calcTotal();
        updateItemCount();
    }
}

// Never allow a double-click to submit the same subsidy twice.
guardFormSubmit(document.getElementById('subsidy-form'));
</script>
@endpush
