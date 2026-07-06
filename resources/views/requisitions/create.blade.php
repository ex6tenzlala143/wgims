@extends('layouts.app')
@section('title', 'Create RIS')
@section('page-title', 'Create Requisition')

@section('content')
<div class="page-header">
    <div>
        <h1>Create Requisition and Issue Slip</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Create</div>
    </div>
</div>

<form action="{{ route('requisitions.store') }}" method="POST">
@csrf
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> RIS Header</h3></div>
            <div class="card-body">
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
                        <label class="form-label">DR Number <span style="color:red">*</span> <span style="color:var(--text-muted);font-size:12px">(Delivery Receipt)</span></label>
                        <input type="text" name="dr_number" class="form-control" value="{{ old('dr_number') }}" placeholder="e.g. DR-2026-0001" required>
                        @error('dr_number')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        {{-- spacer --}}
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Warehouse <span style="color:red">*</span></label>
                        @if($warehouses->count() === 1)
                            {{-- Single warehouse: show as read-only text, pass value via hidden input --}}
                            @php $onlyWarehouse = $warehouses->first(); @endphp
                            <input type="text" class="form-control"
                                   value="{{ $onlyWarehouse->name }}"
                                   readonly style="background:#f7fafc">
                            <input type="hidden"
                                   name="warehouse_id"
                                   id="warehouse-select"
                                   value="{{ $onlyWarehouse->id }}">
                        @else
                            {{-- Multiple warehouses (admin or multi-assigned user): show dropdown --}}
                            <select name="warehouse_id" id="warehouse-select" class="form-control" required>
                                <option value="">— Select Warehouse —</option>
                                @foreach($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                    {{ old('warehouse_id') == $wh->id ? 'selected' : '' }}>
                                    {{ $wh->name }}
                                    @if($wh->code) ({{ $wh->code }}) @endif
                                </option>
                                @endforeach
                            </select>
                        @endif
                        @error('warehouse_id')
                        <div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Responsibility Center Code</label>
                        <input type="text" name="responsibility_center_code" class="form-control"
                               id="resp-center-code"
                               value="{{ old('responsibility_center_code', $warehouses->count() === 1 ? $warehouses->first()->code : '') }}">
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
                <div class="form-group">
                    <label class="form-label">Purpose <span style="color:red">*</span></label>
                    <textarea name="purpose" class="form-control {{ $errors->has('purpose') ? 'is-invalid' : '' }}" rows="2" required>{{ old('purpose') }}</textarea>
                    @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Requested Items</h3>
                <button type="button" class="btn btn-sm btn-primary" onclick="addRisRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>

            {{-- Loading / empty state shown while items are being fetched --}}
            <div id="items-notice" style="padding:20px 20px;font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:8px">
                <i class="fas fa-arrow-up" style="color:var(--warning)"></i>
                @if(auth()->user()->hasAdminAccess())
                    Select a warehouse above to load available items.
                @else
                    Loading items&hellip;
                @endif
            </div>

            <div class="card-body" style="padding:0;display:none" id="items-table-wrapper">
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
                                <th style="width:110px;text-align:right">Total Cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="ris-body">
                            {{-- rows injected by JS --}}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Submit RIS</h3></div>
            <div class="card-body">
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px">
                    The RIS will be submitted for approval. Stock will be deducted upon approval.
                </p>

                {{-- Grand total — only visible once items are loaded --}}
                <div id="grand-total-wrapper" style="display:none;margin-bottom:16px;padding:12px;background:#f0fff4;border-radius:8px;border:1px solid #9ae6b4">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:4px">Estimated Total Cost</div>
                    <div id="grand-total-cost" style="font-size:22px;font-weight:800;color:var(--success)">—</div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-paper-plane"></i> Submit RIS
                </button>
                <a href="{{ route('requisitions.index') }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
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
let risRowCount    = 0;
let warehouseItems = [];   // items for the currently selected warehouse

const ITEMS_API_URL = '{{ route("requisitions.items_by_warehouse") }}';

// ─── Warehouse change handler ─────────────────────────────────────────────────
const warehouseSelect = document.getElementById('warehouse-select');

function onWarehouseChange() {
    const warehouseId = warehouseSelect.value;
    const notice      = document.getElementById('items-notice');
    const wrapper     = document.getElementById('items-table-wrapper');
    const tbody       = document.getElementById('ris-body');

    if (!warehouseId) {
        warehouseItems = [];
        tbody.innerHTML = '';
        risRowCount = 0;
        wrapper.style.display = 'none';
        document.getElementById('grand-total-wrapper').style.display = 'none';
        notice.innerHTML = '<i class="fas fa-arrow-up" style="color:var(--warning)"></i> Select a warehouse above to load available items.';
        notice.style.display = 'flex';
        return;
    }

    notice.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--primary)"></i> Loading items for selected warehouse&hellip;';
    notice.style.display = 'flex';
    wrapper.style.display = 'none';

    fetch(`${ITEMS_API_URL}?warehouse_id=${encodeURIComponent(warehouseId)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
    .then(data => {
        warehouseItems = data;
        tbody.innerHTML = '';
        risRowCount = 0;

        if (warehouseItems.length === 0) {
            notice.innerHTML = '<i class="fas fa-box-open" style="color:var(--warning)"></i> No items with available stock in this warehouse.';
            notice.style.display = 'flex';
            return;
        }

        notice.style.display = 'none';
        wrapper.style.display = 'block';
        document.getElementById('grand-total-wrapper').style.display = 'block';
        addRisRow();
    })
    .catch(() => {
        notice.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--danger)"></i> Failed to load items. Please try again.';
        notice.style.display = 'flex';
    });
}

if (warehouseSelect.tagName === 'SELECT') {
    warehouseSelect.addEventListener('change', onWarehouseChange);

    // Auto-fill Responsibility Center Code from the selected warehouse's code
    @php
        $warehouseCodesJson = $warehouses->pluck('code', 'id')->toJson();
    @endphp
    const warehouseCodes = {!! $warehouseCodesJson !!};

    warehouseSelect.addEventListener('change', function () {
        const codeField = document.getElementById('resp-center-code');
        if (codeField && !codeField.dataset.userEdited) {
            codeField.value = warehouseCodes[this.value] || '';
        }
    });

    // Mark the field as user-edited if they type in it manually
    const codeField = document.getElementById('resp-center-code');
    if (codeField) {
        codeField.addEventListener('input', function () {
            this.dataset.userEdited = '1';
        });
    }
}

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

/** Format a number as Philippine Peso. */
function formatPeso(value) {
    const n = parseFloat(value) || 0;
    return '₱\u00a0' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Recalculate the total cost cell for a row and update the grand total. */
function recalcRow(idx) {
    const costInput = document.getElementById('unit-cost-' + idx);
    const qtyInput  = document.getElementById('qty-' + idx);
    const totalEl   = document.getElementById('total-cost-' + idx);
    if (!costInput || !qtyInput || !totalEl) { return; }

    const cost  = parseFloat(costInput.value) || 0;
    const qty   = parseFloat(qtyInput.value)  || 0;
    const total = cost * qty;

    totalEl.textContent = total > 0 ? formatPeso(total) : '—';
    recalcGrandTotal();
}

/** Sum all visible total-cost cells and write to the sidebar. */
function recalcGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[id^="total-cost-"]').forEach(el => {
        const raw = el.textContent.replace(/[^\d.]/g, '');
        grand += parseFloat(raw) || 0;
    });
    const el = document.getElementById('grand-total-cost');
    if (el) { el.textContent = grand > 0 ? formatPeso(grand) : '—'; }
}

/** Build the <option> list from the current warehouseItems array. */
function buildOptions() {
    return warehouseItems.map(i =>
        `<option value="${i.id}"
            data-unit="${i.unit}"
            data-stock="${i.quantity}"
            data-sn="${i.stock_number || ''}"
            data-unit-cost="${i.unit_cost || 0}"
            data-expiry="${i.expiry_date || ''}"
            data-expiry-year="${i.expiry_year || ''}"
        >${i.description}</option>`
    ).join('');
}

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
                    onchange="fillRisItem(this, ${idx})" required>
                <option value="">— Select Item —</option>
                ${buildOptions()}
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
                   oninput="recalcRow(${idx})">
        </td>
        <td style="text-align:right">
            <span id="total-cost-${idx}" style="font-size:13px;font-weight:600;white-space:nowrap">—</span>
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
    const tbody = document.getElementById('ris-body');
    if (tbody.querySelectorAll('tr').length > 1) {
        document.getElementById(id)?.remove();
        recalcGrandTotal();
    }
}

/** Called when an item is selected in any row. */
function fillRisItem(sel, idx) {
    const opt    = sel.options[sel.selectedIndex];
    const itemId = opt.value;

    // Stock number
    document.getElementById('sn-' + idx).textContent = opt.dataset.sn || '—';

    // Unit label
    document.getElementById('unit-' + idx).textContent = opt.dataset.unit || '—';

    // Available stock
    const stock   = parseFloat(opt.dataset.stock || 0);
    const stockEl = document.getElementById('stock-' + idx);
    stockEl.textContent = stock.toFixed(2);
    stockEl.style.color = stock > 0 ? 'var(--success)' : 'var(--danger)';

    // Cap qty to available stock
    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) { qtyInput.max = stock; }

    // ── Unit cost (the core of this feature) ──────────────────────────────────
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

    // Recalculate total for this row (qty may already be filled)
    recalcRow(idx);

    // Expiry date
    const expiryEl = document.getElementById('expiry-' + idx);
    if (!itemId) {
        expiryEl.textContent = '—';
        expiryEl.removeAttribute('style');
        return;
    }

    const match = warehouseItems.find(i => String(i.id) === String(itemId));
    if (match) {
        expiryEl.textContent = formatExpiry(match.expiry_date, match.expiry_year);
        expiryEl.setAttribute('style', expiryStyle(match.expiry_date) + ';font-size:12px');
    } else {
        expiryEl.textContent = '—';
        expiryEl.removeAttribute('style');
    }
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────
@if(!auth()->user()->hasAdminAccess())
onWarehouseChange();
@endif

@if(auth()->user()->hasAdminAccess() && old('warehouse_id'))
onWarehouseChange();
@endif
</script>
@endpush
@endsection
