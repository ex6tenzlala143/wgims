@extends('layouts.app')
@section('title', 'Create Reservation')
@section('page-title', 'Create Reservation')

@section('content')
<div class="page-header">
    <div>
        <h1>Create Reservation</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('reservations.index') }}">Reservations</a> / Create
        </div>
    </div>
</div>

<form method="POST" action="{{ route('reservations.store') }}" id="reservation-form">
    @csrf

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start">

        {{-- ── Left: Header + Items ── --}}
        <div style="display:grid;gap:20px">

            {{-- Header --}}
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> Reservation Details</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Purpose</label>
                        <input type="text" name="purpose" class="form-control {{ $errors->has('purpose') ? 'is-invalid' : '' }}"
                               value="{{ old('purpose') }}" placeholder="e.g. For incoming disaster response">
                        @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Reservation Expiration Date <span style="color:var(--text-muted);font-size:11px">(optional)</span></label>
                            <input type="date" name="expires_at" class="form-control {{ $errors->has('expires_at') ? 'is-invalid' : '' }}"
                                   value="{{ old('expires_at') }}" min="{{ date('Y-m-d', strtotime('+1 day')) }}">
                            @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes <span style="color:var(--text-muted);font-size:11px">(optional)</span></label>
                            <input type="text" name="notes" class="form-control" value="{{ old('notes') }}" placeholder="Additional notes">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Reserved Items --}}
            <div class="card">
                <div class="card-header" style="justify-content:space-between">
                    <h3><i class="fas fa-boxes" style="color:var(--primary)"></i> Reserved Items</h3>
                    <button type="button" class="btn btn-sm btn-primary" onclick="resAddRow()">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>

                @if($errors->has('items'))
                    <div style="padding:10px 20px;background:#fff5f5;border-bottom:1px solid var(--border);color:var(--danger);font-size:13px">
                        <i class="fas fa-exclamation-triangle"></i> {{ $errors->first('items') }}
                    </div>
                @endif

                <div id="res-items-container">
                    {{-- Rows injected by JS; see below for error-state restoration --}}
                </div>

                <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
                    <button type="button" class="btn btn-sm btn-outline" onclick="resAddRow()">
                        <i class="fas fa-plus"></i> Add Another Item
                    </button>
                    <span style="font-size:12px;color:var(--text-muted)" id="res-row-count"></span>
                </div>
            </div>
        </div>

        {{-- ── Right: Summary + Submit ── --}}
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Summary</h3></div>
            <div class="card-body" style="font-size:14px">
                <div style="margin-bottom:16px;padding:14px;background:var(--surface-soft);border-radius:8px;text-align:center">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Total Items</div>
                    <div style="font-size:28px;font-weight:800;color:var(--primary)" id="res-total-items">0</div>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px">
                    <i class="fas fa-info-circle"></i>
                    Reservation must be <strong>approved</strong> before reserved quantities become locked.
                    Items remain visible in stock but are protected from normal requisitions once approved.
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-save"></i> Create Reservation
                </button>
                <a href="{{ route('reservations.index') }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
const RES_ITEMS_API = '{{ route("reservations.items_by_warehouse") }}';
const RES_WAREHOUSES = @json($warehouses->map(fn($w) => ['id' => $w->id, 'name' => $w->name]));
const RES_OLD_ITEMS  = @json(old('items', []));

let resRowIdx = 0;
const resItemCache = {}; // warehouseId → loaded items array

function resUpdateCount() {
    const count = document.querySelectorAll('#res-items-container .res-item-row').length;
    const el = document.getElementById('res-total-items');
    if (el) el.textContent = count;
    const countEl = document.getElementById('res-row-count');
    if (countEl) countEl.textContent = count + ' item' + (count === 1 ? '' : 's') + ' in this reservation';
}

function resAddRow(oldWarehouseId, oldItemId, oldQty, oldIdx) {
    const idx  = oldIdx !== undefined ? oldIdx : resRowIdx++;
    const container = document.getElementById('res-items-container');

    const row = document.createElement('div');
    row.className   = 'res-item-row';
    row.id          = 'res-row-' + idx;
    row.style.cssText = 'border-bottom:1px solid var(--border);padding:16px 20px;display:grid;gap:12px';

    row.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:start">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Warehouse <span class="req">*</span></label>
                <select name="items[${idx}][warehouse_id]" id="res-wh-${idx}"
                        class="form-control" onchange="resLoadItems(${idx})">
                    <option value="">— Select Warehouse —</option>
                    ${RES_WAREHOUSES.map(w => `<option value="${w.id}"${w.id == oldWarehouseId ? ' selected' : ''}>${w.name}</option>`).join('')}
                </select>
                <div id="res-wh-err-${idx}" class="invalid-feedback" style="display:block"></div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Item / Stock Record <span class="req">*</span></label>
                <select name="items[${idx}][item_id]" id="res-item-${idx}"
                        class="form-control" disabled onchange="resShowStockInfo(${idx})">
                    <option value="">— Select Warehouse first —</option>
                </select>
                <div id="res-item-err-${idx}" class="invalid-feedback" style="display:block"></div>
            </div>
            <div style="padding-top:24px">
                <button type="button" class="btn btn-sm btn-danger btn-icon"
                        onclick="resRemoveRow(${idx})" title="Remove item">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div id="res-stock-info-${idx}" style="display:none;padding:12px;background:var(--surface-soft);border-radius:8px;font-size:13px">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px">
                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Physical Qty</div><div style="font-size:18px;font-weight:700" id="res-phys-${idx}">0</div></div>
                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Reserved</div><div style="font-size:18px;font-weight:700;color:var(--warning)" id="res-rsrv-${idx}">0</div></div>
                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Available</div><div style="font-size:18px;font-weight:700;color:var(--success)" id="res-avail-${idx}">0</div></div>
            </div>
            <div id="res-stock-detail-${idx}" style="font-size:11px;color:var(--text-muted)"></div>
        </div>

        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Quantity to Reserve <span class="req">*</span></label>
            <input type="number" name="items[${idx}][reserved_quantity]" id="res-qty-${idx}"
                   class="form-control" min="1" step="1" placeholder="Enter quantity"
                   value="${oldQty || ''}" disabled>
            <div id="res-qty-err-${idx}" class="invalid-feedback" style="display:block"></div>
            <small id="res-qty-hint-${idx}" style="color:var(--text-muted);font-size:11px">Select an item first</small>
        </div>`;

    container.appendChild(row);
    resUpdateCount();

    // Auto-load items if restoring old state
    if (oldWarehouseId) {
        resLoadItems(idx, oldItemId);
    }
}

function resRemoveRow(idx) {
    const rows = document.querySelectorAll('#res-items-container .res-item-row');
    if (rows.length <= 1) {
        alert('A reservation must have at least one item.');
        return;
    }
    const row = document.getElementById('res-row-' + idx);
    if (row) row.remove();
    resUpdateCount();
}

function resLoadItems(idx, restoreItemId) {
    const whSel   = document.getElementById('res-wh-' + idx);
    const itemSel = document.getElementById('res-item-' + idx);
    const info    = document.getElementById('res-stock-info-' + idx);
    const qtyInput= document.getElementById('res-qty-' + idx);

    if (!whSel.value) {
        itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
        itemSel.disabled  = true;
        info.style.display= 'none';
        qtyInput.disabled = true;
        return;
    }

    const whId = whSel.value;

    // Use cache to avoid re-fetching the same warehouse
    if (resItemCache[whId]) {
        resPopulateItems(idx, resItemCache[whId], restoreItemId);
        return;
    }

    itemSel.innerHTML = '<option value="">Loading…</option>';
    itemSel.disabled  = true;

    fetch(`${RES_ITEMS_API}?warehouse_id=${encodeURIComponent(whId)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(data => {
        resItemCache[whId] = data;
        resPopulateItems(idx, data, restoreItemId);
    })
    .catch(err => {
        itemSel.innerHTML = '<option value="">Error loading items</option>';
        itemSel.disabled  = false;
        console.error('Failed to load items:', err);
    });
}

function resPopulateItems(idx, items, restoreItemId) {
    const itemSel = document.getElementById('res-item-' + idx);
    if (!itemSel) return;

    if (items.length === 0) {
        itemSel.innerHTML = '<option value="">No available items in this warehouse</option>';
        itemSel.disabled  = true;
        return;
    }

    const opts = items.map(i => {
        const expiry = i.expiry_formatted ? ` · Exp: ${i.expiry_formatted}` : '';
        const engas  = i.engas_unit_cost  ? ` · ENGAS ₱${parseFloat(i.engas_unit_cost).toLocaleString('en-PH', {minimumFractionDigits:2})}` : '';
        const label  = `${i.description} (${i.stock_number}) · Avail: ${i.available_qty.toLocaleString()} · ₱${parseFloat(i.unit_cost).toLocaleString('en-PH', {minimumFractionDigits:2})}${engas}${expiry}`;
        return `<option value="${i.id}"
            data-physical="${i.physical_qty}"
            data-reserved="${i.reserved_qty}"
            data-available="${i.available_qty}"
            data-unit-cost="${i.unit_cost}"
            data-engas="${i.engas_unit_cost || ''}"
            data-expiry="${i.expiry_formatted || ''}"
            data-subsidy="${i.source_subsidy_code || ''}"
            data-sn="${i.stock_number}"
            >${label}</option>`;
    }).join('');

    itemSel.innerHTML = '<option value="">— Select Stock Record —</option>' + opts;
    itemSel.disabled  = false;

    if (restoreItemId) {
        const found = Array.from(itemSel.options).find(o => o.value == String(restoreItemId));
        if (found) {
            itemSel.value = String(restoreItemId);
            resShowStockInfo(idx);
        }
    }
}

function resShowStockInfo(idx) {
    const itemSel = document.getElementById('res-item-' + idx);
    const info    = document.getElementById('res-stock-info-' + idx);
    const qtyInput= document.getElementById('res-qty-' + idx);
    const hint    = document.getElementById('res-qty-hint-' + idx);

    const opt = itemSel?.options[itemSel.selectedIndex];
    if (!opt || !opt.value) {
        info.style.display = 'none';
        qtyInput.disabled  = true;
        return;
    }

    const phys  = parseFloat(opt.dataset.physical)  || 0;
    const rsrv  = parseFloat(opt.dataset.reserved)  || 0;
    const avail = parseFloat(opt.dataset.available) || 0;

    document.getElementById('res-phys-' + idx).textContent  = phys.toLocaleString();
    document.getElementById('res-rsrv-' + idx).textContent  = rsrv.toLocaleString();
    document.getElementById('res-avail-' + idx).textContent = avail.toLocaleString();

    let details = [];
    if (opt.dataset.unitCost) details.push('₱' + parseFloat(opt.dataset.unitCost).toLocaleString('en-PH', {minimumFractionDigits:2}));
    if (opt.dataset.engas)    details.push('ENGAS ₱' + parseFloat(opt.dataset.engas).toLocaleString('en-PH', {minimumFractionDigits:2}));
    if (opt.dataset.expiry)   details.push('Exp: ' + opt.dataset.expiry);
    if (opt.dataset.subsidy)  details.push('Source: ' + opt.dataset.subsidy);
    document.getElementById('res-stock-detail-' + idx).textContent = details.join(' · ');

    info.style.display = 'block';
    qtyInput.disabled  = avail <= 0;
    qtyInput.max       = avail;
    if (hint) hint.textContent = avail > 0
        ? 'Maximum available: ' + avail.toLocaleString()
        : 'No quantity available for reservation';
}

document.addEventListener('DOMContentLoaded', function () {
    // Restore old() state on validation re-render
    const oldItems = RES_OLD_ITEMS;
    const keys = Object.keys(oldItems);
    if (keys.length > 0) {
        keys.forEach(function (k) {
            const line = oldItems[k];
            resAddRow(line.warehouse_id, line.item_id, line.reserved_quantity, parseInt(k));
            if (parseInt(k) >= resRowIdx) resRowIdx = parseInt(k) + 1;
        });
    } else {
        resAddRow(); // start with one blank row
    }
    resUpdateCount();

    // Guard against double-submit
    guardFormSubmit(document.getElementById('reservation-form'));
});
</script>
@endpush
