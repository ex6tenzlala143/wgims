@php
    // Same autocomplete sources the create form uses: configured item names
    // (per category) take priority, inventory items are the fallback.
    $catalogItems = $catalogItems ?? collect();

    $datalistOptions = $catalogItems
        ->filter(fn ($ci) => $ci->is_active && $ci->category)
        ->map(fn ($ci) => [
            'id'           => $ci->id,
            'name'         => $ci->name,
            'account_code' => $ci->account_code,
            'category'     => $ci->category->key,
            'unit'         => '',
            'expiry'       => '',
            'source'       => 'catalog',
        ])
        ->concat(
            $items->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->description,
                'account_code' => App\Models\Item::getAccountCodeForCategory($i->category),
                'category'     => $i->category,
                'unit'         => $i->unit,
                'expiry'       => $i->expiration_date ? $i->expiration_date->format('Y-m-d') : '',
                'source'       => 'item',
            ])
        )
        ->unique('name')
        ->values();

    $categoryCodes = collect(App\Models\Item::getCategories())
        ->mapWithKeys(fn ($c, $key) => [$key => $c['account_code']]);

    $unitOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::UNITS as $uKey => $uLabel) {
        $unitOptionsHtml .= '<option value="' . $uKey . '">' . $uLabel . '</option>';
    }

    $catOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::getCategories() as $cKey => $cCat) {
        $catOptionsHtml .= '<option value="' . $cKey . '">' . $cCat['label'] . '</option>';
    }
@endphp

<div class="modal-overlay" id="editModal">
    <div class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="editModalTitle"><i class="fas fa-edit"></i> Edit Subsidy <span id="edit-ris-ref" style="color:var(--text-muted);font-weight:600"></span></h2>
                <div class="modal-subtitle" id="edit-modal-subtitle">Editing the subsidy header and its line items. Unit cost and warehouse are assigned at dispatch.</div>
            </div>
            <button type="button" class="modal-close" onclick="closeEditModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="#" method="POST" id="edit-form" class="subsidy-form" novalidate>
            @csrf
            <input type="hidden" name="_method" value="PUT">
            <div class="modal-body">
                <div id="edit-form-top-error" style="display:none"></div>

                <div id="edit-related-warning" class="edit-related-warning" style="display:none">
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <i class="fas fa-exclamation-triangle" style="font-size:20px;margin-top:2px"></i>
                        <div>
                            <strong>This subsidy already has related transactions.</strong>
                            <div id="edit-related-warning-detail" style="margin-top:4px;font-size:12.5px;line-height:1.5">
                                Deliveries, stock cards and inventory records exist for this request. The RIS number, supplier and DR number are locked, and lines that already have delivered stock can only have their requested quantity changed. Delivered stock and inventory are never modified here.
                            </div>
                        </div>
                    </div>
                </div>

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
                                        <input type="date" name="date" id="edit-date" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Supplier/Subsidy <span style="color:red">*</span> <i class="fas fa-lock" id="edit-supplier-lock" style="display:none;color:var(--text-muted);font-size:11px"></i></label>
                                        <select name="supplier_id" id="edit-supplier" class="form-control" required>
                                            <option value="">— Select Supplier/Subsidy —</option>
                                            @foreach($suppliers as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                        <div id="edit-supplier-note" style="display:none;font-size:11px;color:var(--text-muted);margin-top:4px">
                                            <i class="fas fa-info-circle"></i> Locked because deliveries have been recorded.
                                        </div>
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group">
                                        <label class="form-label">RIS No. <span style="color:red">*</span> <i class="fas fa-lock" id="edit-ris-lock" style="display:none;color:var(--text-muted);font-size:11px"></i></label>
                                        <input type="text" name="ris_number" id="edit-ris-number" class="form-control" placeholder="e.g. RIS-2026-001" required>
                                        <div id="edit-ris-note" style="display:none;font-size:11px;color:var(--text-muted);margin-top:4px">
                                            <i class="fas fa-info-circle"></i> Locked because deliveries have been recorded.
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Place of Delivery</label>
                                        <input type="text" name="place_of_delivery" id="edit-place-of-delivery" class="form-control">
                                    </div>
                                </div>
                                <div class="form-row cols-2">
                                    <div class="form-group" id="edit-status-group">
                                        <label class="form-label">Status <span style="color:red">*</span></label>
                                        <select name="status" id="edit-status" class="form-control" required>
                                            <option value="pending">Pending</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                                            <i class="fas fa-info-circle"></i>
                                            Status is recomputed automatically from the requested and delivered quantities once deliveries are recorded.
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Remarks</label>
                                        <textarea name="remarks" id="edit-remarks" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- Line Items -->
                        <section class="card form-section">
                            <div class="card-header">
                                <h3><i class="fas fa-list" style="color:var(--primary)"></i> Line Items</h3>
                                <button type="button" class="btn btn-sm btn-primary" id="edit-add-row-btn" onclick="editAddRow()"><i class="fas fa-plus"></i> Add Item</button>
                            </div>
                            <div class="card-body" style="padding:0">
                                <div class="table-wrapper">
                                    <table class="line-items-table" id="edit-items-table">
                                        <thead>
                                            <tr>
                                                <th style="width:40%">Description <span style="color:red">*</span></th>
                                                <th style="width:11%">Unit <span style="color:red">*</span></th>
                                                <th style="width:17%">Category <span style="color:red">*</span></th>
                                                <th style="width:16%">Quantity Requested</th>
                                                <th style="width:13%">Expiry Date</th>
                                                <th style="width:3%"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="edit-items-body">
                                            <!-- populated by JS from the edit-data endpoint -->
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
                            <div style="font-size:26px;font-weight:800;color:var(--primary);line-height:1.1" id="edit-grand-total">0</div>
                            <div id="edit-item-count" style="font-size:12px;color:var(--text-muted);margin-top:2px">0 line items</div>
                        </div>
                        <div class="summary-note">
                            <div id="edit-requested-note" style="font-size:12px;line-height:1.6;color:var(--text-muted)">
                                The requested quantity is always recomputed from the line items so the header and the lines can never drift apart.
                            </div>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="modal-footer">
                <div id="edit-confirm-wrap" style="display:none;flex:1;min-width:0;font-size:12.5px;line-height:1.5;color:var(--text-muted)">
                    <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin:0">
                        <input type="checkbox" id="edit-confirm-check" style="margin-top:3px">
                        <span><strong style="color:var(--danger)">I understand</strong> — this subsidy already has related transactions. I only want to edit the request details; delivered stock and inventory records will not be changed.</span>
                    </label>
                </div>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" style="min-width:170px;justify-content:center"><i class="fas fa-save"></i> Save Changes</button>
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
    .edit-related-warning {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        font-size: 13px;
        line-height: 1.5;
        color: #7f1d1d;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 16px;
    }
    .edit-related-warning i { color: var(--danger); }
    .edit-locked-field {
        background: #f1f5f9 !important;
        color: #64748b !important;
        cursor: not-allowed !important;
    }
    .edit-delivered-note {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 4px;
        line-height: 1.4;
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
        min-height: 340px;
        max-height: calc(100vh - 520px);
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
    .modal-body .line-items-table .remove-row:disabled {
        border-color: #e2e8f0;
        background: #f1f5f9;
        color: #94a3b8;
        cursor: not-allowed;
    }

    /* Inline validation feedback */
    .edit-field-error {
        color: var(--danger);
        font-size: 11.5px;
        margin-top: 4px;
        line-height: 1.4;
    }
    .edit-form-error {
        color: var(--danger);
        font-size: 13px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
    }
    .is-invalid {
        border-color: var(--danger) !important;
        background: #fff5f5 !important;
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
(function () {
    'use strict';

    var editRowCount    = 0;
    var editLoading     = false;
    var editCurrentId   = null;
    var editConfirmRequired = false;
    var editDataUrl     = '{{ route('delivery_subsidies.edit_data', ['deliverySubsidy' => '__ID__']) }}';
    var editUpdateUrl   = '{{ route('delivery_subsidies.update', ['deliverySubsidy' => '__ID__']) }}';
    var allOptions      = {!! json_encode($datalistOptions) !!};
    var categoryCodes   = {!! json_encode($categoryCodes) !!};
    var unitOptions     = {!! json_encode($unitOptionsHtml) !!};
    var catOptions      = {!! json_encode($catOptionsHtml) !!};

    function editOptionHtml() {
        return allOptions.map(function (o) {
            return '<option value="' + o.name + '"' +
                ' data-source="' + o.source + '"' +
                ' data-id="' + (o.id || '') + '"' +
                ' data-account-code="' + (o.account_code || '') + '"' +
                ' data-category="' + (o.category || '') + '"' +
                ' data-unit="' + (o.unit || '') + '"' +
                ' data-expiry="' + (o.expiry || '') + '">';
        }).join('');
    }

    function editOpen() {
        var m = document.getElementById('editModal');
        if (!m) return;
        m.classList.add('open');
        document.body.classList.add('modal-open');
        document.addEventListener('keydown', onEditEsc);
        var first = document.getElementById('edit-date');
        if (first) setTimeout(function () { first.focus(); }, 250);
    }

    function editClose() {
        var m = document.getElementById('editModal');
        if (!m) return;
        m.classList.remove('open');
        document.body.classList.remove('modal-open');
        document.removeEventListener('keydown', onEditEsc);
    }

    function onEditEsc(e) {
        if (e.key === 'Escape') editClose();
    }

    function editClearRows() {
        var body = document.getElementById('edit-items-body');
        if (body) body.innerHTML = '';
        editRowCount = 0;
    }

    function editAddRow(item) {
        var idx = editRowCount++;
        var tbody = document.getElementById('edit-items-body');
        var tr = document.createElement('tr');
        tr.id = 'edit-row-' + idx;

        tr.innerHTML =
            '<input type="hidden" name="items[' + idx + '][dsi_id]" id="edit-dsi-' + idx + '">' +
            '<td data-label="Description">' +
                '<input type="text" name="items[' + idx + '][description]" class="form-control edit-desc-input" list="edit-datalist-' + idx + '" placeholder="Type or pick item name..." oninput="window.editOnDescInput && editOnDescInput(this,' + idx + ')" autocomplete="off" required style="color:#000;background:#fff">' +
                '<datalist id="edit-datalist-' + idx + '">' + editOptionHtml() + '</datalist>' +
                '<input type="hidden" name="items[' + idx + '][item_id]" id="edit-item-id-' + idx + '">' +
                '<input type="hidden" name="items[' + idx + '][catalog_item_id]" id="edit-catalog-item-id-' + idx + '">' +
                '<input type="hidden" name="items[' + idx + '][account_code]" id="edit-account-code-' + idx + '">' +
            '</td>' +
            '<td data-label="Unit"><select name="items[' + idx + '][unit]" id="edit-unit-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + unitOptions + '</select></td>' +
            '<td data-label="Category"><select name="items[' + idx + '][category]" id="edit-category-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + catOptions + '</select></td>' +
            '<td data-label="Quantity Requested"><input type="number" name="items[' + idx + '][quantity]" class="form-control edit-qty-input" min="0.01" step="0.01" oninput="editCalcTotal()" required style="color:#000;background:#fff"></td>' +
            '<td data-label="Expiry Date"><input type="date" name="items[' + idx + '][expiration_date]" id="edit-expiry-' + idx + '" class="form-control" style="font-size:12px;color:#000;background:#fff"></td>' +
            '<td><button type="button" class="remove-row" id="edit-remove-' + idx + '" onclick="editRemoveRow(\'edit-row-' + idx + '\', ' + idx + ')"><i class="fas fa-times"></i></button></td>';

        tbody.appendChild(tr);

        if (item) editFillRow(idx, item);

        editUpdateCount();
        return idx;
    }

    function editFillRow(idx, item) {
        var set = function (id, val) {
            var el = document.getElementById(id);
            if (el && val !== undefined && val !== null) el.value = val;
        };

        set('edit-dsi-' + idx, item.dsi_id);
        set('edit-item-id-' + idx, item.item_id || '');
        set('edit-catalog-item-id-' + idx, item.catalog_item_id || '');
        set('edit-account-code-' + idx, item.account_code || '');

        var desc = document.querySelector('#edit-row-' + idx + ' .edit-desc-input');
        if (desc) desc.value = item.description || '';

        set('edit-unit-' + idx, item.unit || '');
        set('edit-category-' + idx, item.category || '');
        set('edit-expiry-' + idx, item.expiration_date || '');

        var qty = document.querySelector('#edit-row-' + idx + ' .edit-qty-input');
        if (qty) qty.value = item.quantity !== undefined ? item.quantity : '';

        // Lines that already have delivered stock are locked: only the requested
        // quantity may change; the item identity cannot be re-pointed or removed.
        if (item.locked) {
            var btn = document.getElementById('edit-remove-' + idx);
            if (btn) {
                btn.disabled = true;
                btn.title = 'This line already has recorded deliveries and cannot be removed';
                btn.innerHTML = '<i class="fas fa-lock"></i>';
            }
            if (desc) {
                desc.readOnly = true;
                desc.classList.add('edit-locked-field');
                desc.title = 'This line has delivered stock — the item cannot be changed';
            }
            var unitSel = document.getElementById('edit-unit-' + idx);
            if (unitSel) {
                unitSel.setAttribute('data-orig', item.unit || '');
                unitSel.classList.add('edit-locked-field');
            }
            var catSel = document.getElementById('edit-category-' + idx);
            if (catSel) {
                catSel.setAttribute('data-orig', item.category || '');
                catSel.classList.add('edit-locked-field');
            }
            var expiry = document.getElementById('edit-expiry-' + idx);
            if (expiry) {
                expiry.readOnly = true;
                expiry.classList.add('edit-locked-field');
            }
            if (qty) {
                qty.min = item.qty_delivered;
                var note = document.createElement('div');
                note.className = 'edit-delivered-note';
                note.textContent = 'Delivered: ' + Number(item.qty_delivered || 0).toLocaleString('en-PH', { maximumFractionDigits: 2 }) + ' — cannot go below this.';
                qty.parentElement.appendChild(note);
            }
        }
    }

    window.editOnDescInput = function (input, idx) {
        var val = input.value.trim().toLowerCase();
        var match = allOptions.find(function (o) { return o.name.toLowerCase() === val; });

        var itemId  = document.getElementById('edit-item-id-' + idx);
        var catId   = document.getElementById('edit-catalog-item-id-' + idx);
        var acct    = document.getElementById('edit-account-code-' + idx);
        var unitSel = document.getElementById('edit-unit-' + idx);
        var catSel  = document.getElementById('edit-category-' + idx);
        var expiry  = document.getElementById('edit-expiry-' + idx);

        if (match) {
            if (match.source === 'catalog') {
                itemId.value = '';
                catId.value  = match.id;
                if (acct) acct.value = match.account_code || '';
                if (catSel) catSel.value = match.category || '';
                if (expiry) expiry.value = '';
            } else {
                catId.value  = '';
                itemId.value = match.id;
                if (acct) acct.value = categoryCodes[match.category] || match.account_code || '';
                if (unitSel) unitSel.value = match.unit || '';
                if (catSel) catSel.value = match.category || '';
                if (expiry && match.expiry) expiry.value = match.expiry;
            }
        } else {
            itemId.value = '';
            catId.value  = '';
            if (acct) acct.value = '';
        }
    };

    function editCalcTotal() {
        var total = 0;
        document.querySelectorAll('#edit-items-body .edit-qty-input').forEach(function (el) {
            total += parseFloat(el.value) || 0;
        });
        var grand = document.getElementById('edit-grand-total');
        if (grand) grand.textContent = total.toLocaleString('en-PH', { maximumFractionDigits: 2 });
        var hidden = document.getElementById('edit-quantity-requested');
        if (hidden) hidden.value = total.toFixed(2);
    }

    function editUpdateCount() {
        var el = document.getElementById('edit-item-count');
        if (!el) return;
        var n = document.querySelectorAll('#edit-items-body tr').length;
        el.textContent = n + ' line item' + (n === 1 ? '' : 's');
    }

    window.editAddRow = function () { editAddRow(null); editCalcTotal(); };
    window.editCalcTotal = editCalcTotal;
    window.editRemoveRow = function (rowId) {
        if (document.querySelectorAll('#edit-items-body tr').length > 1) {
            var row = document.getElementById(rowId);
            if (row) row.remove();
            editCalcTotal();
            editUpdateCount();
        }
    };

    function editClearErrors() {
        document.querySelectorAll('#edit-form .edit-field-error, #edit-form .edit-form-error:not(#edit-form-top-error)').forEach(function (el) { el.remove(); });
        document.querySelectorAll('#edit-form .is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        var top = document.getElementById('edit-form-top-error');
        if (top) { top.style.display = 'none'; top.className = ''; top.innerHTML = ''; }
    }

    function editShowErrors(errors) {
        editClearErrors();
        var form = document.getElementById('edit-form');
        var body = form.querySelector('.modal-body');

        Object.keys(errors).forEach(function (key) {
            var name = key.replace(/items\.(\d+)\.(\w+)/g, 'items[$1][$2]');
            var el = form.querySelector('[name="' + name + '"]');
            if (el) {
                el.classList.add('is-invalid');
                var wrap = el.closest('td') || el.closest('.form-group') || el.parentElement;
                var div = document.createElement('div');
                div.className = 'edit-field-error';
                div.textContent = errors[key][0];
                wrap.appendChild(div);
            } else {
                var top = document.getElementById('edit-form-top-error');
                if (top) {
                    top.style.display = 'block';
                    top.className = 'edit-form-error';
                    top.innerHTML = '';
                    var msg = document.createElement('div');
                    msg.textContent = errors[key][0];
                    top.appendChild(msg);
                }
            }
        });

        if (body) body.scrollTop = 0;
    }

    function editPopulate(data) {
        editCurrentId = data.id;
        editClearRows();
        editClearErrors();

        document.getElementById('edit-form').dataset.id = data.id;
        document.getElementById('edit-form').action = editUpdateUrl.replace('__ID__', data.id);

        var set = function (id, val) {
            var el = document.getElementById(id);
            if (el) el.value = val || '';
        };
        set('edit-date', data.date);
        set('edit-supplier', data.supplier_id);
        set('edit-ris-number', data.ris_number);
        set('edit-place-of-delivery', data.place_of_delivery);
        set('edit-status', data.status);
        set('edit-remarks', data.remarks);

        var ref = document.getElementById('edit-ris-ref');
        if (ref) ref.textContent = data.ris_number ? '#' + data.ris_number : '';

        // ── Locked mode (deliveries exist): freeze the historical identity, ──
        //    hide the status picker, require explicit confirmation to save.
        var locked = !!data.has_deliveries;
        editConfirmRequired = locked;

        var risInput = document.getElementById('edit-ris-number');
        if (risInput) {
            risInput.readOnly = locked;
            risInput.classList.toggle('edit-locked-field', locked);
        }
        var supplierSel = document.getElementById('edit-supplier');
        if (supplierSel) {
            supplierSel.setAttribute('data-orig', data.supplier_id || '');
            supplierSel.classList.toggle('edit-locked-field', locked);
        }
        var statusGroup = document.getElementById('edit-status-group');
        if (statusGroup) statusGroup.style.display = locked ? 'none' : '';
        var addBtn = document.getElementById('edit-add-row-btn');
        if (addBtn) addBtn.disabled = locked;
        var confirmWrap = document.getElementById('edit-confirm-wrap');
        if (confirmWrap) confirmWrap.style.display = locked ? 'flex' : 'none';
        var confirmCheck = document.getElementById('edit-confirm-check');
        if (confirmCheck) confirmCheck.checked = false;
        var warn = document.getElementById('edit-related-warning');
        if (warn) warn.style.display = locked ? 'block' : 'none';
        var risLock = document.getElementById('edit-ris-lock');
        if (risLock) risLock.style.display = locked ? 'inline' : 'none';
        var supplierLock = document.getElementById('edit-supplier-lock');
        if (supplierLock) supplierLock.style.display = locked ? 'inline' : 'none';
        var risNote = document.getElementById('edit-ris-note');
        if (risNote) risNote.style.display = locked ? 'block' : 'none';
        var supplierNote = document.getElementById('edit-supplier-note');
        if (supplierNote) supplierNote.style.display = locked ? 'block' : 'none';
        var sub = document.getElementById('edit-modal-subtitle');
        if (sub) {
            sub.textContent = locked
                ? 'Only the request details can be edited: date, place of delivery, remarks and requested quantities. Delivered stock and inventory are never changed.'
                : 'Editing the subsidy header and its line items. Unit cost and warehouse are assigned at dispatch.';
        }

        (data.items || []).forEach(function (item) { editAddRow(item); });

        // In locked mode no lines may be added or removed — only quantities
        // on the existing lines (the Add Item button is already disabled above).
        if (locked) {
            document.querySelectorAll('#edit-items-body .remove-row').forEach(function (btn) {
                btn.disabled = true;
                btn.title = 'Lines cannot be added or removed once deliveries exist';
                btn.innerHTML = '<i class="fas fa-lock"></i>';
            });
        }

        editCalcTotal();
        editUpdateCount();
    }

    window.openEditModal = function (id) {
        if (editLoading) return;
        editLoading = true;
        editClearErrors();
        fetch(editDataUrl.replace('__ID__', id), { headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                if (!res.ok) throw new Error('Failed to load');
                return res.json();
            })
            .then(function (data) {
                editPopulate(data);
                editOpen();
            })
            .catch(function () {
                alert('Could not load the subsidy data. Please try again.');
            })
            .finally(function () {
                editLoading = false;
            });
    };

    window.closeEditModal = editClose;

    var editForm = document.getElementById('edit-form');
    if (editForm) {
        // Locked-mode guard for the supplier select (readonly is not supported
        // on <select> — silently reset any attempted change).
        var supplierSel = document.getElementById('edit-supplier');
        if (supplierSel) {
            supplierSel.addEventListener('change', function () {
                if (this.classList.contains('edit-locked-field')) {
                    this.value = this.getAttribute('data-orig') || '';
                }
            });
        }

        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!editCurrentId) return;

            var btn = editForm.querySelector('button[type="submit"]');
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            editClearErrors();

            // This subsidy already has related transactions — require explicit
            // confirmation before anything is saved.
            if (editConfirmRequired) {
                var confirmCheck = document.getElementById('edit-confirm-check');
                if (!confirmCheck || !confirmCheck.checked) {
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    var top = document.getElementById('edit-form-top-error');
                    if (top) {
                        top.style.display = 'block';
                        top.className = 'edit-form-error';
                        top.innerHTML = '';
                        var msg = document.createElement('div');
                        msg.textContent = 'Tick the confirmation box to continue — this subsidy already has related transactions and only the request details will be changed.';
                        top.appendChild(msg);
                    }
                    var body = editForm.querySelector('.modal-body');
                    if (body) body.scrollTop = 0;
                    return;
                }
            }

            var fd = new FormData(editForm);

            fetch(editForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                        ? document.querySelector('meta[name="csrf-token"]').content
                        : ''
                },
                body: fd
            })
                .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
                .then(function (r) {
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    if (r.ok && r.d.redirect) {
                        window.location.href = r.d.redirect;
                        return;
                    }
                    if (r.d.errors) editShowErrors(r.d.errors);
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = orig;
                    alert('Update failed. Please try again.');
                });
        });
    }

    // Auto-open from ?edit=ID (e.g. legacy /edit links redirect here)
    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        var id = params.get('edit');
        if (id && /^\d+$/.test(id)) {
            window.openEditModal(id);
            var cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    });
})();
</script>
@endpush
