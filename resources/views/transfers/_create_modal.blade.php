@php
    $createModalOpen = $createModalOpen ?? false;
@endphp

<div class="modal-overlay{{ $createModalOpen ? ' open' : '' }}" id="createTransferModal">
    <div class="modal-shell transfer-modal" role="dialog" aria-modal="true" aria-labelledby="createTransferModalTitle">
        <div class="modal-header">
            <div style="min-width:0">
                <h2 id="createTransferModalTitle"><i class="fas fa-exchange-alt"></i> New Stock Transfer</h2>
                <div class="modal-subtitle">Pre-position inventory by transferring stock between warehouses</div>
            </div>
            <button type="button" class="modal-close" onclick="closeTransferModal()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="{{ route('transfers.store') }}" method="POST" id="transfer-form">
            @csrf
            <div class="modal-body">
                {{-- Transfer Details --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-info-circle" style="color:var(--primary)"></i> Transfer Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">Source Warehouse <span style="color:red">*</span></label>
                                @if($sourceWarehouse)
                                    {{-- Non-admin: fixed to their warehouse --}}
                                    <input type="text" class="form-control" value="{{ $sourceWarehouse->name }}" readonly>
                                    <input type="hidden" name="from_warehouse_id" value="{{ $sourceWarehouse->id }}">
                                @else
                                    <select name="from_warehouse_id" id="from_warehouse_id" class="form-control" required onchange="loadSourceItems(); syncWarehouseOptions()">
                                        <option value="">— Select Source —</option>
                                        @foreach($warehouses as $wh)
                                        <option value="{{ $wh->id }}" {{ old('from_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            <div class="form-group">
                                <label class="form-label">Destination Warehouse <span style="color:red">*</span></label>
                                <select name="to_warehouse_id" id="to_warehouse_id" class="form-control" required onchange="syncWarehouseOptions()">
                                    <option value="">— Select Destination —</option>
                                    @foreach($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ old('to_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                    @endforeach
                                </select>
                                <div id="wh-diff-hint" style="display:none;color:var(--danger);font-size:12px;margin-top:4px">
                                    <i class="fas fa-exclamation-circle"></i> Source and destination must be different
                                </div>
                            </div>
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">Transfer Date <span style="color:red">*</span></label>
                                <input type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', date('Y-m-d')) }}" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes...">{{ old('remarks') }}</textarea>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Line Items --}}
                <section class="card form-section">
                    <div class="card-header">
                        <h3><i class="fas fa-list" style="color:var(--primary)"></i> Items to Transfer</h3>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addTransferRow()"><i class="fas fa-plus"></i> Add Item</button>
                    </div>
                    <div class="card-body" style="padding:0">
                        <div class="table-wrapper">
                            <table class="line-items-table" id="transfer-items-table">
                                <thead>
                                    <tr>
                                        <th style="width:28%">Item</th>
                                        <th style="width:10%">Unit</th>
                                        <th style="width:10%">Available</th>
                                        <th style="width:12%">ENGAS Cost</th>
                                        <th style="width:12%">Qty to Transfer</th>
                                        <th style="width:12%">Unit Cost</th>
                                        <th style="width:12%">Total</th>
                                        <th style="width:4%"></th>
                                    </tr>
                                </thead>
                                <tbody id="transfer-items-body">
                                    {{-- Rows injected by JS --}}
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="6" style="text-align:right;font-weight:600;padding:12px 16px;background:#f8fafc">Grand Total:</td>
                                        <td style="font-weight:700;padding:12px 16px;background:#f8fafc" id="transfer-grand-total">₱0.00</td>
                                        <td style="background:#f8fafc"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </section>

                {{-- Info Panel --}}
                <div class="alert alert-info" style="margin-bottom:0">
                    <i class="fas fa-lightbulb"></i>
                    <div>
                        <strong>Pre-Positioning:</strong> Stock is deducted from source and added to destination. Stock cards and inventory balances update automatically. Quantities cannot exceed available stock.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="transfer-submit-btn" style="min-width:160px;justify-content:center"><i class="fas fa-save"></i> Save Transfer</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    /* Modal base styles - same as delivery subsidy modal */
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
        max-width: 1400px;
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
        flex-wrap: wrap;
    }
    body.modal-open { overflow: hidden; }

    /* Transfer modal specific styles */
    .transfer-modal .form-section { margin-bottom: 20px; }
    .transfer-modal .form-section:last-child { margin-bottom: 0; }
    .transfer-modal .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .transfer-modal .line-items-table {
        width: 100%;
        min-width: 900px;
        border-collapse: separate;
        border-spacing: 0;
    }
    .transfer-modal .line-items-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--surface-soft, #f7fafc);
        box-shadow: 0 1px 0 var(--border);
        padding: 12px 14px;
        white-space: nowrap;
    }
    .transfer-modal .line-items-table th,
    .transfer-modal .line-items-table td {
        padding: 12px 14px;
    }
    .transfer-modal .line-items-table td {
        border-bottom: 1px solid var(--border);
    }
    .transfer-modal .line-items-table tbody tr:last-child td {
        border-bottom: none;
    }
    .transfer-modal .line-items-table input[type="text"],
    .transfer-modal .line-items-table input[type="number"],
    .transfer-modal .line-items-table select {
        width: 100%;
        min-width: 120px;
        padding: 10px 12px;
        font-size: 13px;
        border-radius: 6px;
        border: 1px solid #cbd5e0;
        box-sizing: border-box;
    }
    .transfer-modal .line-items-table input[readonly] {
        background: #f7fafc;
        color: var(--text-muted);
    }
    .transfer-modal .line-items-table .remove-row {
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
        flex-shrink: 0;
    }
    .transfer-modal .line-items-table .remove-row:hover { background: #fed7d7; }
    
    /* Tablet responsive */
    @media (max-width: 1024px) {
        .transfer-modal .line-items-table {
            min-width: 800px;
        }
    }
    
    /* Mobile responsive */
    @media (max-width: 820px) {
        /* Stack the card header content */
        .transfer-modal .card-header {
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
        }
        
        .transfer-modal .card-header h3 {
            margin: 0;
        }
        
        .transfer-modal .card-header .btn {
            width: 100%;
            justify-content: center;
        }
        
        /* Make table scrollable but keep structure */
        .transfer-modal .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -16px;
            padding: 0 16px;
        }
        
        .transfer-modal .line-items-table {
            min-width: 700px;
            font-size: 12px;
        }
        
        .transfer-modal .line-items-table th,
        .transfer-modal .line-items-table td {
            padding: 10px 8px;
        }
        
        .transfer-modal .line-items-table input[type="text"],
        .transfer-modal .line-items-table input[type="number"],
        .transfer-modal .line-items-table select {
            min-width: 100px;
            padding: 8px 10px;
            font-size: 12px;
        }
        
        /* Stack form rows */
        .transfer-modal .form-row.cols-2 {
            display: block;
        }
        
        .transfer-modal .form-row.cols-2 .form-group {
            margin-bottom: 16px;
        }
        
        .transfer-modal .form-row.cols-2 .form-group:last-child {
            margin-bottom: 0;
        }
    }
    
    /* Small mobile phones */
    @media (max-width: 640px) {
        .modal-overlay { 
            padding: 10px;
            align-items: stretch;
        }
        .modal-shell { 
            width: 100%; 
            height: 100vh;
            max-height: 100vh;
            border-radius: 0;
        }
        .modal-header { 
            padding: 12px 16px;
        }
        .modal-header h2 {
            font-size: 16px;
        }
        .modal-subtitle {
            font-size: 11px;
        }
        .modal-body { 
            padding: 12px;
        }
        .modal-footer { 
            padding: 12px 16px;
        }
        .modal-footer .btn {
            flex: 1;
            justify-content: center;
        }
        
        /* Improve touch targets */
        .transfer-modal .line-items-table input[type="text"],
        .transfer-modal .line-items-table input[type="number"],
        .transfer-modal .line-items-table select {
            min-height: 44px;
        }
        
        .transfer-modal .line-items-table .remove-row {
            min-width: 44px;
            min-height: 44px;
        }
    }
    
    /* Very small screens */
    @media (max-width: 375px) {
        .transfer-modal .line-items-table {
            min-width: 650px;
            font-size: 11px;
        }
        
        .transfer-modal .line-items-table th,
        .transfer-modal .line-items-table td {
            padding: 8px 6px;
        }
    }
</style>
@endpush



@push('scripts')
<script>
// Transfer modal logic
let transferSourceItems = @json($sourceItems ?? []);
let transferRowIndex = 0;

function openTransferModal() {
    const m = document.getElementById('createTransferModal');
    if (!m) return;
    m.classList.add('open');
    document.body.classList.add('modal-open');
    document.addEventListener('keydown', onTransferModalEsc);
    
    // Add first row if empty
    if (document.querySelectorAll('#transfer-items-body tr').length === 0) {
        addTransferRow();
    }
    
    // Load items if admin and warehouse already selected
    @if(auth()->user()->hasAdminAccess())
    const fromWh = document.getElementById('from_warehouse_id');
    if (fromWh && fromWh.value) loadSourceItems();
    @endif
    
    syncWarehouseOptions();
}

function closeTransferModal() {
    const m = document.getElementById('createTransferModal');
    if (!m) return;
    m.classList.remove('open');
    document.body.classList.remove('modal-open');
    document.removeEventListener('keydown', onTransferModalEsc);
}

function onTransferModalEsc(e) {
    if (e.key === 'Escape') closeTransferModal();
}

// Load items from source warehouse
function loadSourceItems() {
    const warehouseId = document.getElementById('from_warehouse_id')?.value;
    if (!warehouseId) { 
        transferSourceItems = []; 
        return; 
    }

    fetch(`{{ route('transfers.items_for_warehouse') }}?warehouse_id=${warehouseId}`)
        .then(r => r.json())
        .then(data => {
            transferSourceItems = data;
            // Refresh all existing row selects
            document.querySelectorAll('.transfer-item-select').forEach(sel => {
                const currentVal = sel.value;
                populateTransferItemSelect(sel);
                sel.value = currentVal;
            });
        });
}

// Sync warehouse options (disable same warehouse selection)
function syncWarehouseOptions() {
    const fromEl = document.getElementById('from_warehouse_id');
    const toEl   = document.getElementById('to_warehouse_id');
    if (!toEl) return;

    const fromId = fromEl
        ? String(fromEl.value || '')
        : String(document.querySelector('input[name="from_warehouse_id"]')?.value || '');

    // Disable matching warehouse
    toEl.querySelectorAll('option').forEach(opt => {
        opt.disabled = !!(opt.value && opt.value === fromId);
    });

    if (fromEl) {
        fromEl.querySelectorAll('option').forEach(opt => {
            opt.disabled = !!(opt.value && opt.value === String(toEl.value || ''));
        });
    }

    // Show hint if same
    const hint = document.getElementById('wh-diff-hint');
    if (String(toEl.value || '') === fromId && fromId) {
        toEl.value = '';
        if (hint) hint.style.display = 'block';
    } else if (hint) {
        hint.style.display = 'none';
    }
}

function populateTransferItemSelect(selectEl) {
    const currentVal = selectEl.value;
    selectEl.innerHTML = '<option value="">— Select Item —</option>';
    transferSourceItems.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = `${item.description} — ${item.stock_number || 'No SN'}`;
        opt.dataset.unit = item.unit;
        opt.dataset.unitCost = item.unit_cost;
        opt.dataset.engasUnitCost = item.engas_unit_cost || 'null';
        opt.dataset.available = item.quantity;
        selectEl.appendChild(opt);
    });
    if (currentVal) selectEl.value = currentVal;
}

function addTransferRow() {
    const tbody = document.getElementById('transfer-items-body');
    const idx = transferRowIndex++;
    const tr = document.createElement('tr');
    tr.id = `transfer-row-${idx}`;
    tr.innerHTML = `
        <td data-label="Item">
            <select name="items[${idx}][item_id]" class="transfer-item-select" required onchange="onTransferItemChange(this, ${idx})">
                <option value="">— Select Item —</option>
            </select>
        </td>
        <td data-label="Unit"><input type="text" id="transfer-unit-${idx}" readonly placeholder="—"></td>
        <td data-label="Available"><input type="text" id="transfer-avail-${idx}" readonly placeholder="—"></td>
        <td data-label="ENGAS Cost"><input type="text" id="transfer-engas-${idx}" readonly placeholder="—"></td>
        <td data-label="Qty to Transfer">
            <input type="number" name="items[${idx}][quantity]" id="transfer-qty-${idx}"
                   step="0.0001" min="0.0001" placeholder="0" required oninput="recalcTransferRow(${idx})">
        </td>
        <td data-label="Unit Cost">
            <input type="number" name="items[${idx}][unit_cost]" id="transfer-cost-${idx}"
                   step="0.01" min="0.01" placeholder="0.00" required oninput="recalcTransferRow(${idx})">
        </td>
        <td data-label="Total"><input type="text" id="transfer-total-${idx}" readonly placeholder="0.00"></td>
        <td>
            <button type="button" class="remove-row" onclick="removeTransferRow(${idx})" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    populateTransferItemSelect(tr.querySelector('.transfer-item-select'));
}

function onTransferItemChange(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById(`transfer-unit-${idx}`).value  = opt.dataset.unit || '';
    document.getElementById(`transfer-avail-${idx}`).value = opt.dataset.available || '';

    const engasEl = document.getElementById(`transfer-engas-${idx}`);
    const engasRaw = opt.dataset.engasUnitCost;
    const engas = (engasRaw && engasRaw !== 'null') ? parseFloat(engasRaw) : NaN;
    if (Number.isNaN(engas)) {
        engasEl.value = 'Not set';
        engasEl.style.color = 'var(--warning)';
    } else {
        engasEl.value = '₱' + engas.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        engasEl.style.color = '';
    }

    document.getElementById(`transfer-cost-${idx}`).value = opt.dataset.unitCost || '';
    recalcTransferRow(idx);
}

function recalcTransferRow(idx) {
    const qty  = parseFloat(document.getElementById(`transfer-qty-${idx}`)?.value) || 0;
    const cost = parseFloat(document.getElementById(`transfer-cost-${idx}`)?.value) || 0;
    const total = qty * cost;
    const totalEl = document.getElementById(`transfer-total-${idx}`);
    if (totalEl) totalEl.value = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    recalcTransferGrandTotal();
}

function recalcTransferGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[id^="transfer-total-"]').forEach(el => {
        grand += parseFloat(el.value.replace(/[^0-9.]/g, '')) || 0;
    });
    document.getElementById('transfer-grand-total').textContent = '₱' + grand.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function removeTransferRow(idx) {
    const row = document.getElementById(`transfer-row-${idx}`);
    if (row) row.remove();
    recalcTransferGrandTotal();
}

// Validate qty vs available before submit
let transferSubmitting = false;
document.getElementById('transfer-form').addEventListener('submit', function(e) {
    let valid = true;
    
    if (transferSubmitting) {
        e.preventDefault();
        return;
    }
    
    document.querySelectorAll('[id^="transfer-qty-"]').forEach(qtyEl => {
        const idx = qtyEl.id.replace('transfer-qty-', '');
        const avail = parseFloat(document.getElementById(`transfer-avail-${idx}`)?.value) || 0;
        const qty   = parseFloat(qtyEl.value) || 0;
        if (qty > avail) {
            alert(`Quantity exceeds available stock (${avail}) for one of the items.`);
            valid = false;
        }
    });
    
    if (!valid) {
        e.preventDefault();
        return;
    }
    
    // Disable submit button and show loading
    transferSubmitting = true;
    const btn = document.getElementById('transfer-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

// Click overlay to close
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('createTransferModal');
    if (!overlay) return;
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeTransferModal();
    });
});

@if($errors->any())
// Open modal if validation errors
document.addEventListener('DOMContentLoaded', openTransferModal);
@endif
</script>
@endpush
