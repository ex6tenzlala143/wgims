@extends('layouts.app')
@section('title', 'Edit Reservation')
@section('page-title', 'Edit Reservation')

@section('content')
<div class="page-header">
    <div>
        <h1>
            Edit Reservation
            <code style="font-size:18px;color:var(--primary)">{{ $reservation->reservation_number ?? '#' . $reservation->id }}</code>
        </h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('reservations.index') }}">Reservations</a> /
            <a href="{{ route('reservations.show', $reservation) }}">{{ $reservation->reservation_number ?? $reservation->id }}</a> / Edit
        </div>
    </div>
    <a href="{{ route('reservations.show', $reservation) }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<form method="POST" action="{{ route('reservations.update', $reservation) }}" id="reservation-edit-form">
    @csrf @method('PUT')

    {{-- Header fields — same as create --}}
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3><i class="fas fa-info-circle" style="color:var(--primary)"></i> Reservation Details</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Purpose</label>
                <input type="text" name="purpose" class="form-control @error('purpose') is-invalid @enderror"
                       value="{{ old('purpose', $reservation->purpose) }}" placeholder="e.g. For incoming disaster response">
                @error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-row cols-2">
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Expiration Date <span style="color:var(--text-muted);font-size:11px">(optional)</span></label>
                    <input type="date" name="expires_at" class="form-control @error('expires_at') is-invalid @enderror"
                           value="{{ old('expires_at', $reservation->expires_at?->format('Y-m-d')) }}"
                           min="{{ date('Y-m-d', strtotime('+1 day')) }}">
                    @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label">Notes <span style="color:var(--text-muted);font-size:11px">(optional)</span></label>
                    <input type="text" name="notes" class="form-control @error('notes') is-invalid @enderror"
                           value="{{ old('notes', $reservation->notes) }}" placeholder="Additional notes">
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Lines — same fields as create (warehouse + item + quantity), pre-filled --}}
    <div class="card" style="margin-bottom:16px">
        <div class="card-header" style="justify-content:space-between">
            <h3><i class="fas fa-boxes" style="color:var(--primary)"></i> Reserved Items</h3>
            <button type="button" class="btn btn-sm btn-primary" onclick="resEditAddRow()">
                <i class="fas fa-plus"></i> Add Item
            </button>
        </div>

        @if($errors->has('items'))
        <div style="padding:10px 20px;background:#fff5f5;border-bottom:1px solid var(--border);color:var(--danger);font-size:13px">
            <i class="fas fa-exclamation-triangle"></i> {{ $errors->first('items') }}
        </div>
        @endif

        <div id="res-edit-items-container"></div>

        <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
            <button type="button" class="btn btn-sm btn-outline" onclick="resEditAddRow()">
                <i class="fas fa-plus"></i> Add Another Item
            </button>
            <span id="res-edit-row-count" style="font-size:12px;color:var(--text-muted)"></span>
        </div>

        <div class="card-footer" style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ route('reservations.show', $reservation) }}" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
'use strict';

const RES_EDIT_API = '{{ route("reservations.items_by_warehouse") }}';
const RES_EDIT_WH  = @json($warehouses->map(fn($w) => ['id' => $w->id, 'name' => $w->name]));
// Initial rows: old input on validation failure, else current reservation lines
@php
    $editInitRows = old('items')
        ? collect(old('items'))->values()->map(function ($l) use ($reservation) {
            $existing = ! empty($l['id']) ? $reservation->items->firstWhere('id', (int) $l['id']) : null;
            return [
                'id' => $l['id'] ?? null,
                'warehouse_id' => isset($l['warehouse_id']) ? (int) $l['warehouse_id'] : null,
                'item_id' => isset($l['item_id']) ? (int) $l['item_id'] : null,
                'reserved_quantity' => $l['reserved_quantity'] ?? '',
                'deployed_quantity' => $existing ? (float) $existing->deployed_quantity : 0,
                'status' => $existing?->status,
                'description' => $existing?->item?->description ?? '',
                'stock_number' => $existing?->item?->stock_number ?? '',
            ];
          })->all()
        : $reservation->items->map(fn($ri) => [
            'id' => $ri->id,
            'warehouse_id' => $ri->warehouse_id,
            'item_id' => $ri->item_id,
            'reserved_quantity' => $ri->reserved_quantity,
            'deployed_quantity' => (float) $ri->deployed_quantity,
            'status' => $ri->status,
            'description' => $ri->item->description ?? '',
            'stock_number' => $ri->item->stock_number ?? '',
          ])->all();
@endphp
const RES_EDIT_INIT = @json($editInitRows);

let resEditRowIdx = 0;
const resEditCache = {};

function resEditUpdateCount() {
    const n = document.querySelectorAll('#res-edit-items-container .res-edit-row').length;
    const el = document.getElementById('res-edit-row-count');
    if (el) el.textContent = n + ' item' + (n === 1 ? '' : 's');
}

window.resEditAddRow = function (preset) {
    const data = preset || {};
    const idx = resEditRowIdx++;
    const container = document.getElementById('res-edit-items-container');
    const deployed = parseFloat(data.deployed_quantity || 0) || 0;
    const locked = deployed > 0;
    const existingId = data.id || '';

    const row = document.createElement('div');
    row.className = 'res-edit-row';
    row.id = 'res-edit-row-' + idx;
    row.style.cssText = 'border-bottom:1px solid var(--border);padding:16px 20px;display:grid;gap:12px';
    row.dataset.deployed = deployed;
    if (existingId) row.dataset.lineId = existingId;

    const whOptions = RES_EDIT_WH.map(function (w) {
        const sel = (data.warehouse_id && String(w.id) === String(data.warehouse_id)) ? ' selected' : '';
        return '<option value="' + w.id + '"' + sel + '>' + escHtml(w.name) + '</option>';
    }).join('');

    // Locked (partially deployed) lines keep their warehouse/item — show them
    // read-only with the values preserved via hidden inputs.
    const lockedItemOption = locked && data.item_id
        ? '<option value="' + escAttr(data.item_id) + '" selected>' + escHtml((data.description || 'Current item') + ' (' + (data.stock_number || '') + ')') + '</option>'
        : '<option value="">— Select Warehouse first —</option>';

    row.innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:start">' +
            '<div class="form-group" style="margin-bottom:0">' +
                '<label class="form-label">Warehouse <span class="req">*</span></label>' +
                '<select name="items[' + idx + '][warehouse_id]" id="res-edit-wh-' + idx + '" class="form-control"' +
                    (locked ? ' disabled' : '') + ' onchange="resEditLoadItems(' + idx + ')">' +
                    '<option value="">— Select Warehouse —</option>' + whOptions +
                '</select>' +
                (locked ? '<input type="hidden" name="items[' + idx + '][warehouse_id]" value="' + escAttr(data.warehouse_id || '') + '">' : '') +
                '<div style="font-size:11px;color:var(--text-muted);margin-top:3px">' + (locked ? 'Locked — partially deployed (' + deployed.toLocaleString() + ')' : '') + '</div>' +
            '</div>' +
            '<div class="form-group" style="margin-bottom:0">' +
                '<label class="form-label">Item / Stock Record <span class="req">*</span></label>' +
                '<select name="items[' + idx + '][item_id]" id="res-edit-item-' + idx + '" class="form-control"' +
                    (locked ? ' disabled' : '') + ' onchange="resEditShowInfo(' + idx + ')">' +
                    lockedItemOption +
                '</select>' +
                (locked ? '<input type="hidden" name="items[' + idx + '][item_id]" value="' + escAttr(data.item_id || '') + '">' : '') +
                (locked ? '<div style="font-size:11px;color:var(--text-muted);margin-top:3px">Locked — ' + escHtml(data.description || '') + ' (' + escHtml(data.stock_number || '') + ')</div>' : '') +
            '</div>' +
            '<div style="padding-top:24px">' +
                '<button type="button" class="btn btn-sm btn-danger btn-icon" onclick="resEditRemoveRow(' + idx + ')" title="Remove"' +
                    (locked ? ' disabled style="opacity:.4;cursor:not-allowed"' : '') + '>' +
                    '<i class="fas fa-times"></i>' +
                '</button>' +
            '</div>' +
        '</div>' +
        '<div id="res-edit-info-' + idx + '" style="display:none;padding:12px;background:var(--surface-soft);border-radius:8px;font-size:13px">' +
            '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px">' +
                '<div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Physical Qty</div><div style="font-size:18px;font-weight:700" id="res-edit-phys-' + idx + '">0</div></div>' +
                '<div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Reserved</div><div style="font-size:18px;font-weight:700;color:var(--warning)" id="res-edit-rsrv-' + idx + '">0</div></div>' +
                '<div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Available</div><div style="font-size:18px;font-weight:700;color:var(--success)" id="res-edit-avail-' + idx + '">0</div></div>' +
            '</div>' +
            '<div id="res-edit-detail-' + idx + '" style="font-size:11px;color:var(--text-muted)"></div>' +
        '</div>' +
        '<div class="form-group" style="margin-bottom:0">' +
            '<label class="form-label">Quantity to Reserve <span class="req">*</span></label>' +
            (existingId ? '<input type="hidden" name="items[' + idx + '][id]" value="' + escAttr(existingId) + '">' : '') +
            '<input type="number" name="items[' + idx + '][reserved_quantity]" id="res-edit-qty-' + idx + '"' +
                ' class="form-control" min="' + Math.max(1, Math.ceil(deployed)) + '" step="1" placeholder="Enter quantity"' +
                ' value="' + escAttr(data.reserved_quantity ?? '') + '" disabled>' +
            '<small id="res-edit-hint-' + idx + '" style="color:var(--text-muted);font-size:11px">Select an item first' + (deployed > 0 ? ' · Min: ' + deployed.toLocaleString() + ' (deployed)' : '') + '</small>' +
        '</div>';

    container.appendChild(row);
    resEditUpdateCount();

    // Locked lines already show their fixed item — no need to fetch.
    // New/unlocked lines with a warehouse preload the item list (preserving
    // the current selection so its own lock counts toward availability).
    if (!locked && data.warehouse_id) {
        resEditLoadItems(idx, data.item_id);
    } else if (locked) {
        const qtyInput = document.getElementById('res-edit-qty-' + idx);
        if (qtyInput) qtyInput.disabled = false;
    }
};

window.resEditRemoveRow = function (idx) {
    const row = document.getElementById('res-edit-row-' + idx);
    if (!row) return;
    if (parseFloat(row.dataset.deployed || 0) > 0) {
        alert('This item cannot be removed because part of it was already deployed. Reduce its quantity to the deployed amount instead.');
        return;
    }
    const rows = document.querySelectorAll('#res-edit-items-container .res-edit-row');
    if (rows.length <= 1) {
        alert('A reservation must have at least one item.');
        return;
    }
    row.remove();
    resEditUpdateCount();
};

window.resEditLoadItems = function (idx, restoreItemId) {
    const whSel = document.getElementById('res-edit-wh-' + idx);
    const itemSel = document.getElementById('res-edit-item-' + idx);
    const info = document.getElementById('res-edit-info-' + idx);
    const qtyInput = document.getElementById('res-edit-qty-' + idx);
    if (!whSel || !itemSel) return;

    if (!whSel.value) {
        itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
        itemSel.disabled = true;
        if (info) info.style.display = 'none';
        if (qtyInput) qtyInput.disabled = true;
        return;
    }

    const whId = whSel.value;
    if (resEditCache[whId]) {
        resEditPopulate(idx, resEditCache[whId], restoreItemId);
        return;
    }

    itemSel.innerHTML = '<option value="">Loading…</option>';
    itemSel.disabled = true;

    fetch(RES_EDIT_API + '?warehouse_id=' + encodeURIComponent(whId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function (data) {
        resEditCache[whId] = data;
        resEditPopulate(idx, data, restoreItemId);
    })
    .catch(function (err) {
        itemSel.innerHTML = '<option value="">Error loading items</option>';
        itemSel.disabled = false;
        console.error('resEditLoadItems failed:', err);
    });
};

function resEditPopulate(idx, items, restoreItemId) {
    const itemSel = document.getElementById('res-edit-item-' + idx);
    if (!itemSel) return;
    if (itemSel.disabled && itemSel.dataset.locked === '1') return;

    let list = items || [];
    // Keep the current selection visible even when its available qty is 0
    // (edit API filters those out) — prepend a placeholder that gets replaced
    // with full details once selected.
    if (restoreItemId && !list.some(function (i) { return String(i.id) === String(restoreItemId); })) {
        const row = document.getElementById('res-edit-row-' + idx);
        const hint = row ? row.querySelector('[id^="res-edit-hint-"]') : null;
        list = [{
            id: restoreItemId,
            description: '(current item — loading…)',
            stock_number: '',
            unit_cost: 0,
            engas_unit_cost: null,
            expiry_formatted: '',
            physical_qty: 0,
            reserved_qty: 0,
            available_qty: 0,
        }].concat(list);
    }

    if (list.length === 0) {
        itemSel.innerHTML = '<option value="">No available items in this warehouse</option>';
        itemSel.disabled = true;
        return;
    }

    const opts = list.map(function (i) {
        const expiry = i.expiry_formatted ? ' · Exp: ' + i.expiry_formatted : '';
        const engas = i.engas_unit_cost ? ' · ENGAS ₱' + parseFloat(i.engas_unit_cost).toLocaleString('en-PH', {minimumFractionDigits: 2}) : '';
        const label = i.description + ' (' + i.stock_number + ')'
            + ' · Avail: ' + Number(i.available_qty).toLocaleString()
            + ' · ₱' + parseFloat(i.unit_cost).toLocaleString('en-PH', {minimumFractionDigits: 2})
            + engas + expiry;
        return '<option value="' + i.id + '"'
            + ' data-physical="' + i.physical_qty + '"'
            + ' data-reserved="' + i.reserved_qty + '"'
            + ' data-available="' + i.available_qty + '"'
            + ' data-unit-cost="' + i.unit_cost + '"'
            + ' data-engas="' + (i.engas_unit_cost || '') + '"'
            + ' data-expiry="' + (i.expiry_formatted || '') + '"'
            + ' data-subsidy="' + escAttr(i.source_subsidy_code || '') + '"'
            + '>' + escHtml(label) + '</option>';
    }).join('');

    const wasDisabled = itemSel.disabled;
    itemSel.innerHTML = '<option value="">— Select Stock Record —</option>' + opts;
    itemSel.disabled = false;

    if (restoreItemId) {
        const found = Array.from(itemSel.options).find(function (o) { return o.value === String(restoreItemId); });
        if (found) {
            itemSel.value = String(restoreItemId);
            resEditShowInfo(idx);
        }
    }
}

window.resEditShowInfo = function (idx) {
    const itemSel = document.getElementById('res-edit-item-' + idx);
    const info = document.getElementById('res-edit-info-' + idx);
    const qtyInput = document.getElementById('res-edit-qty-' + idx);
    const hint = document.getElementById('res-edit-hint-' + idx);
    const row = document.getElementById('res-edit-row-' + idx);
    const deployed = row ? (parseFloat(row.dataset.deployed || 0) || 0) : 0;
    const opt = itemSel && itemSel.options[itemSel.selectedIndex];

    if (!opt || !opt.value) {
        if (info) info.style.display = 'none';
        if (qtyInput) qtyInput.disabled = true;
        return;
    }

    const phys = parseFloat(opt.dataset.physical) || 0;
    const rsrv = parseFloat(opt.dataset.reserved) || 0;
    const avail = parseFloat(opt.dataset.available) || 0;

    const physEl = document.getElementById('res-edit-phys-' + idx);
    const rsrvEl = document.getElementById('res-edit-rsrv-' + idx);
    const availEl = document.getElementById('res-edit-avail-' + idx);
    const detEl = document.getElementById('res-edit-detail-' + idx);

    if (physEl) physEl.textContent = phys.toLocaleString();
    if (rsrvEl) rsrvEl.textContent = rsrv.toLocaleString();
    if (availEl) availEl.textContent = avail.toLocaleString();

    const parts = [];
    if (opt.dataset.unitCost) parts.push('₱' + parseFloat(opt.dataset.unitCost).toLocaleString('en-PH', {minimumFractionDigits: 2}));
    if (opt.dataset.engas) parts.push('ENGAS ₱' + parseFloat(opt.dataset.engas).toLocaleString('en-PH', {minimumFractionDigits: 2}));
    if (opt.dataset.expiry) parts.push('Exp: ' + opt.dataset.expiry);
    if (opt.dataset.subsidy) parts.push('Source: ' + opt.dataset.subsidy);
    if (detEl) detEl.textContent = parts.join(' · ');

    if (info) info.style.display = 'block';
    if (qtyInput) {
        // Editing an existing lock: its own reserved qty is part of "reserved",
        // so allow current qty even when global available is 0.
        const rowId = row && row.dataset.lineId ? true : false;
        const currentQty = parseFloat(qtyInput.value || 0) || 0;
        const maxForExisting = avail + currentQty;
        qtyInput.disabled = false;
        if (!rowId) qtyInput.max = avail;
        else qtyInput.removeAttribute('max');
        if (hint) {
            hint.textContent = (avail > 0 || rowId)
                ? 'Maximum available: ' + (rowId ? maxForExisting.toLocaleString() + ' (incl. current lock)' : avail.toLocaleString()) + (deployed > 0 ? ' · Min: ' + deployed.toLocaleString() + ' (deployed)' : '')
                : 'No quantity available for reservation';
        }
        if (!rowId) qtyInput.disabled = avail <= 0;
    }
};

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escAttr(str) { return escHtml(str); }

document.addEventListener('DOMContentLoaded', function () {
    if (RES_EDIT_INIT.length > 0) {
        RES_EDIT_INIT.forEach(function (line) { resEditAddRow(line); });
    } else {
        resEditAddRow();
    }
    resEditUpdateCount();
    const form = document.getElementById('reservation-edit-form');
    if (form && typeof guardFormSubmit === 'function') guardFormSubmit(form);
});

})();
</script>
@endpush
