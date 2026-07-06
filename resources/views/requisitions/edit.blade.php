@extends('layouts.app')
@section('title', 'Edit RIS')
@section('page-title', 'Edit Requisition')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Requisition #{{ $requisition->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Edit</div>
    </div>
</div>

<form action="{{ route('requisitions.update', $requisition->id) }}" method="POST">
@csrf @method('PUT')
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> RIS Header</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">RIS Number</label>
                        <input type="text" class="form-control" value="{{ $requisition->ris_number }}" readonly style="background:#f7fafc;font-weight:600">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date Requested <span style="color:red">*</span></label>
                        <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', $requisition->date_requested->format('Y-m-d')) }}" required>
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
                        <label class="form-label">DR Number <span style="color:red">*</span> <span style="color:var(--text-muted);font-size:12px">(Delivery Receipt)</span></label>
                        <input type="text" name="dr_number" class="form-control" value="{{ old('dr_number', $requisition->dr_number) }}" placeholder="e.g. DR-2026-0001" required>
                        @error('dr_number')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        {{-- spacer --}}
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Warehouse <span style="color:red">*</span></label>
                        <select name="warehouse_id" id="warehouse-select" class="form-control" required>
                            <option value="">— Select Warehouse —</option>
                            @foreach($warehouses as $c)
                            <option value="{{ $c->id }}" {{ old('warehouse_id', $requisition->warehouse_id) == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Responsibility Center Code</label>
                        <input type="text" name="responsibility_center_code" class="form-control" value="{{ old('responsibility_center_code', $requisition->responsibility_center_code) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Office</label>
                        <input type="text" name="office" class="form-control" value="{{ old('office', $requisition->office) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Division</label>
                        <input type="text" name="division" class="form-control" value="{{ old('division', $requisition->division) }}">
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
                        <label class="form-label">Status <span style="color:red">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="pending"             {{ old('status', $requisition->status) == 'pending'             ? 'selected' : '' }}>Pending</option>
                            <option value="approved"            {{ old('status', $requisition->status) == 'approved'            ? 'selected' : '' }}>Approved</option>
                            <option value="partially_approved"  {{ old('status', $requisition->status) == 'partially_approved'  ? 'selected' : '' }}>Partially Fulfilled</option>
                            <option value="cancelled"           {{ old('status', $requisition->status) == 'cancelled'           ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group" style="visibility:hidden">
                        <label class="form-label">&nbsp;</label>
                        <input type="text" class="form-control" tabindex="-1">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Purpose <span style="color:red">*</span></label>
                    <textarea name="purpose" class="form-control @error('purpose') is-invalid @enderror" rows="2" required>{{ old('purpose', $requisition->purpose) }}</textarea>
                    @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Requested Items</h3>
                <button type="button" class="btn btn-sm btn-primary" onclick="addRisRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>

            {{-- Loading notice shown while items are being fetched --}}
            <div id="items-notice" style="padding:20px;font-size:13px;color:var(--text-muted);display:none;align-items:center;gap:8px">
                <i class="fas fa-spinner fa-spin" style="color:var(--primary)"></i> Loading items&hellip;
            </div>

            <div class="card-body" style="padding:0" id="items-table-wrapper">
                <div class="table-wrapper">
                    <table class="line-items-table" id="ris-table">
                        <thead>
                            <tr>
                                <th>Stock No.</th>
                                <th>Item Description</th>
                                <th>Unit</th>
                                <th>Available Stock</th>
                                <th>Expiration Date</th>
                                <th style="width:110px;text-align:right">Unit Cost</th>
                                <th style="width:120px">Qty Requested</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="ris-body">
                            {{-- rows pre-rendered by PHP, then JS enriches them once the API loads --}}
                            @foreach($requisition->items as $idx => $ri)
                            <tr id="ris-row-{{ $idx }}">
                                <td><span id="sn-{{ $idx }}" style="font-size:12px;color:var(--text-muted)">{{ $ri->item->stock_number ?? '—' }}</span></td>
                                <td>
                                    {{-- item_id stored so JS can match it after the API loads --}}
                                    <select name="items[{{ $idx }}][item_id]"
                                            class="ris-item-select"
                                            id="item-select-{{ $idx }}"
                                            data-selected="{{ $ri->item_id }}"
                                            onchange="fillRisItem(this, {{ $idx }})" required>
                                        <option value="{{ $ri->item_id }}" selected>{{ $ri->item->description ?? '—' }}</option>
                                    </select>
                                </td>
                                <td><span id="unit-{{ $idx }}">{{ $ri->item->unit ?? '—' }}</span></td>
                                <td><span id="stock-{{ $idx }}" style="font-weight:600;color:{{ ($ri->item->quantity ?? 0) > 0 ? 'var(--success)' : 'var(--danger)' }}">{{ number_format($ri->item->quantity ?? 0, 2) }}</span></td>
                                <td>
                                    <span id="expiry-{{ $idx }}" style="font-size:12px">
                                        @if($ri->expiration_date)
                                            {{ $ri->expiration_date->format('M d, Y') }}
                                        @elseif($ri->item->expiration_date)
                                            {{ $ri->item->expiration_date->format('M d, Y') }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                </td>
                                <td style="text-align:right">
                                    <input type="number"
                                           id="unit-cost-{{ $idx }}"
                                           name="items[{{ $idx }}][unit_cost]"
                                           class="form-control"
                                           style="text-align:right;background:#f7fafc;min-width:90px"
                                           readonly tabindex="-1"
                                           step="0.01" min="0"
                                           value="{{ $ri->unit_cost > 0 ? number_format($ri->unit_cost, 2, '.', '') : ($ri->item->unit_cost > 0 ? number_format($ri->item->unit_cost, 2, '.', '') : '') }}">
                                </td>
        <td>
            <input type="number"
                   name="items[{{ $idx }}][quantity_requested]"
                   id="qty-{{ $idx }}"
                   class="form-control"
                   min="0.01" step="0.01"
                   value="{{ $ri->quantity_requested }}"
                   oninput="validateQty(this, {{ $idx }})" required>
            <div id="qty-warn-{{ $idx }}" style="color:var(--danger);font-size:11px;margin-top:2px;display:none">
                Exceeds available stock.
            </div>
        </td>
                                <td><button type="button" class="remove-row" onclick="removeRisRow('ris-row-{{ $idx }}')"><i class="fas fa-times"></i></button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
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
// ─── State ────────────────────────────────────────────────────────────────────
let risRowCount    = {{ $requisition->items->count() }};
let warehouseItems = [];   // populated once the API responds

const ITEMS_API_URL      = '{{ route("requisitions.items_by_warehouse") }}';
const INITIAL_WAREHOUSE  = '{{ $requisition->warehouse_id }}';

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatExpiry(expiryDate, expiryYear) {
    if (expiryDate) {
        const d = new Date(expiryDate + 'T00:00:00');
        return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
    }
    if (expiryYear) { return 'Year: ' + expiryYear; }
    return '—';
}

function expiryStyle(expiryDate) {
    if (!expiryDate) { return 'color:var(--text-muted)'; }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const exp      = new Date(expiryDate + 'T00:00:00');
    const diffDays = Math.floor((exp - today) / 86400000);
    if (diffDays < 0)   { return 'color:var(--danger);font-weight:700'; }
    if (diffDays <= 30) { return 'color:var(--danger);font-weight:600'; }
    if (diffDays <= 90) { return 'color:var(--warning);font-weight:600'; }
    return 'color:var(--success);font-weight:600';
}

function formatPeso(value) {
    const n = parseFloat(value) || 0;
    return '₱\u00a0' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Show/hide the over-stock warning and mark the input invalid. */
function validateQty(input, idx) {
    const max     = parseFloat(input.max);
    const val     = parseFloat(input.value);
    const warnEl  = document.getElementById('qty-warn-' + idx);
    const isOver  = !isNaN(max) && max >= 0 && !isNaN(val) && val > max;
    if (warnEl) { warnEl.style.display = isOver ? 'block' : 'none'; }
    input.style.borderColor = isOver ? 'var(--danger)' : '';
}

/** Build the <option> list from the current warehouseItems array. */
function buildOptions(selectedId) {
    return warehouseItems.map(i => {
        const sel = String(i.id) === String(selectedId) ? ' selected' : '';
        return `<option value="${i.id}"
            data-unit="${i.unit}"
            data-stock="${i.quantity}"
            data-sn="${i.stock_number || ''}"
            data-unit-cost="${i.unit_cost || 0}"
            data-expiry="${i.expiry_date || ''}"
            data-expiry-year="${i.expiry_year || ''}"
            ${sel}>${i.description}</option>`;
    }).join('');
}

// ─── Warehouse API loader ─────────────────────────────────────────────────────
function loadWarehouseItems(warehouseId, callback) {
    const notice  = document.getElementById('items-notice');
    notice.style.display = 'flex';

    fetch(`${ITEMS_API_URL}?warehouse_id=${encodeURIComponent(warehouseId)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(data => {
        warehouseItems = data;
        notice.style.display = 'none';
        if (callback) callback();
    })
    .catch(() => {
        notice.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i> Failed to load items. Please refresh.';
    });
}

/** After the API loads, rebuild every existing row's dropdown and fill cost/expiry. */
function enrichExistingRows() {
    document.querySelectorAll('.ris-item-select[data-selected]').forEach(sel => {
        const idx        = sel.id.replace('item-select-', '');
        const selectedId = sel.dataset.selected;

        // Rebuild the dropdown with full options
        sel.innerHTML = '<option value="">— Select Item —</option>' + buildOptions(selectedId);

        // Now fill the cost/expiry from the API data
        const match = warehouseItems.find(i => String(i.id) === String(selectedId));
        if (match) {
            // Unit cost
            const costInput = document.getElementById('unit-cost-' + idx);
            if (costInput && (!costInput.value || parseFloat(costInput.value) === 0)) {
                costInput.value = match.unit_cost > 0 ? parseFloat(match.unit_cost).toFixed(2) : '';
            }

            // Expiry
            const expiryEl = document.getElementById('expiry-' + idx);
            if (expiryEl && expiryEl.textContent.trim() === '—') {
                expiryEl.textContent = formatExpiry(match.expiry_date, match.expiry_year);
                expiryEl.setAttribute('style', expiryStyle(match.expiry_date) + ';font-size:12px');
            }

            // Stock
            const stockEl = document.getElementById('stock-' + idx);
            if (stockEl) {
                stockEl.textContent = parseFloat(match.quantity).toFixed(2);
                stockEl.style.color = match.quantity > 0 ? 'var(--success)' : 'var(--danger)';
            }

            // Cap qty to available stock
            const qtyInput = document.getElementById('qty-' + idx);
            if (qtyInput) {
                qtyInput.max = match.quantity;
                validateQty(qtyInput, idx);
            }
        }
    });
}

// ─── Warehouse change handler ─────────────────────────────────────────────────
document.getElementById('warehouse-select').addEventListener('change', function () {
    const warehouseId = this.value;
    if (!warehouseId) { warehouseItems = []; return; }
    loadWarehouseItems(warehouseId, enrichExistingRows);
});

// ─── Row management ───────────────────────────────────────────────────────────
function addRisRow() {
    const idx   = risRowCount++;
    const tbody = document.getElementById('ris-body');
    const tr    = document.createElement('tr');
    tr.id = 'ris-row-' + idx;

    tr.innerHTML = `
        <td><span id="sn-${idx}" style="font-size:12px;color:var(--text-muted)">—</span></td>
        <td>
            <select name="items[${idx}][item_id]" class="ris-item-select"
                    id="item-select-${idx}"
                    onchange="fillRisItem(this, ${idx})" required>
                <option value="">— Select Item —</option>
                ${buildOptions(null)}
            </select>
        </td>
        <td><span id="unit-${idx}">—</span></td>
        <td><span id="stock-${idx}" style="font-weight:600">—</span></td>
        <td><span id="expiry-${idx}" style="font-size:12px">—</span></td>
        <td style="text-align:right">
            <input type="number" id="unit-cost-${idx}" name="items[${idx}][unit_cost]"
                   class="form-control" style="text-align:right;background:#f7fafc;min-width:90px"
                   readonly tabindex="-1" placeholder="—" step="0.01" min="0">
        </td>
        <td>
            <input type="number" name="items[${idx}][quantity_requested]"
                   id="qty-${idx}" class="form-control" min="0.01" step="0.01" required
                   oninput="validateQty(this, ${idx})">
            <div id="qty-warn-${idx}" style="color:var(--danger);font-size:11px;margin-top:2px;display:none">
                Exceeds available stock.
            </div>
        </td>
        <td>
            <button type="button" class="remove-row" onclick="removeRisRow('ris-row-${idx}')">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
}

function removeRisRow(id) {
    if (document.querySelectorAll('#ris-body tr').length > 1) {
        document.getElementById(id)?.remove();
    }
}

/** Called when an item is selected in any row. */
function fillRisItem(sel, idx) {
    const opt    = sel.options[sel.selectedIndex];
    const itemId = opt.value;

    document.getElementById('sn-' + idx).textContent = opt.dataset.sn || '—';
    document.getElementById('unit-' + idx).textContent = opt.dataset.unit || '—';

    const stock   = parseFloat(opt.dataset.stock || 0);
    const stockEl = document.getElementById('stock-' + idx);
    stockEl.textContent = stock.toFixed(2);
    stockEl.style.color = stock > 0 ? 'var(--success)' : 'var(--danger)';

    // Cap qty to available stock
    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) {
        qtyInput.max = stock;
        validateQty(qtyInput, idx);
    }

    // Unit cost
    const costInput = document.getElementById('unit-cost-' + idx);
    if (costInput) {
        if (itemId) {
            const match    = warehouseItems.find(i => String(i.id) === String(itemId));
            const unitCost = match ? parseFloat(match.unit_cost) || 0 : 0;
            costInput.value = unitCost > 0 ? unitCost.toFixed(2) : '';
        } else {
            costInput.value = '';
        }
    }

    // Expiry date
    const expiryEl = document.getElementById('expiry-' + idx);
    if (!itemId) {
        expiryEl.textContent = '—';
        expiryEl.setAttribute('style', 'font-size:12px');
        return;
    }
    const match = warehouseItems.find(i => String(i.id) === String(itemId));
    if (match) {
        expiryEl.textContent = formatExpiry(match.expiry_date, match.expiry_year);
        expiryEl.setAttribute('style', expiryStyle(match.expiry_date) + ';font-size:12px');
    } else {
        expiryEl.textContent = '—';
        expiryEl.setAttribute('style', 'font-size:12px');
    }
}

// ─── Bootstrap: load items for the current warehouse on page load ─────────────
if (INITIAL_WAREHOUSE) {
    loadWarehouseItems(INITIAL_WAREHOUSE, enrichExistingRows);
}
</script>
@endpush
@endsection
