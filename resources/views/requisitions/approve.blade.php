@extends('layouts.app')
@section('title', 'Approve RIS')
@section('page-title', 'Approve RIS')

@section('content')
<div class="page-header">
    <div>
        <h1>Approve RIS #{{ $requisition->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Approve</div>
    </div>
</div>

<form action="{{ route('requisitions.process_approval', $requisition->id) }}" method="POST" id="approval-form">
@csrf
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h3>Items to Issue</h3>
                <span style="font-size:12px;color:var(--text-muted);font-weight:400">
                    Choose Normal Stock or Reserved Items per line. Each dispatch is recorded separately.
                </span>
            </div>
            <div class="card-body" style="display:grid;gap:20px">
                @foreach($requisition->items as $ri)
                @php
                    $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued);
                    $isDone      = $outstanding <= 0;
                @endphp
                <div class="ris-item-card" style="{{ $isDone ? 'opacity:.72;background:#fbfcfd' : '' }}">
                    <div class="ris-item-head">
                        <div>
                            <i class="fas fa-box" style="color:var(--primary)"></i>
                            <span style="font-weight:700">{{ $ri->description ?? ($ri->item?->description ?? '—') }}</span>
                            @if($ri->unit)
                                <span style="font-size:12px;color:var(--text-muted);margin-left:6px">{{ $ri->unit }}</span>
                            @endif
                            @if($isDone)
                                <span class="badge badge-success" style="font-size:10px;margin-left:6px"><i class="fas fa-check"></i> Fulfilled</span>
                            @endif
                        </div>
                        <div style="display:flex;gap:18px;font-size:12px;text-align:right">
                            <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Requested</div><strong>{{ number_format($ri->quantity_requested) }}</strong></div>
                            <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Already Issued</div><strong style="color:var(--success)">{{ $ri->quantity_issued > 0 ? number_format($ri->quantity_issued) : '—' }}</strong></div>
                            <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Outstanding</div>
                                @if($isDone)
                                    <span class="badge badge-success" style="font-size:10px">—</span>
                                @else
                                    <strong style="color:var(--warning)">{{ number_format($outstanding) }}</strong>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($ri->dispatchItems->isNotEmpty())
                    <div class="ris-item-meta" style="display:grid;gap:4px">
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Previously Dispatched</div>
                        @foreach($ri->dispatchItems as $di)
                            <span style="font-size:12px">
                                <strong>{{ $di->quantity_issued }} {{ $ri->unit }}</strong>
                                from <strong>{{ $di->item?->warehouse?->name ?? '—' }}</strong>
                                @if($di->reservationItem) · <i class="fas fa-lock" style="color:var(--warning)"></i> {{ $di->reservationItem->reservation->reservation_number ?? 'Reservation' }} @endif
                                (DR# {{ $di->dr_number ?? '—' }})
                                @if($di->expiration_date) · Exp. {{ $di->expiration_date->format('M d, Y') }} @endif
                                · {{ $di->created_at?->format('M d, Y') }}
                            </span>
                        @endforeach
                    </div>
                    @endif

                    @if(!$isDone)
                    {{-- ── Source selector ─────────────────────────────────────────────── --}}
                    <div class="form-section-label"><i class="fas fa-truck-fast"></i> Dispatch from (this issuance)</div>

                    <div class="form-group">
                        <label class="form-label">Source of Items</label>
                        <div style="display:flex;gap:16px;align-items:center;padding:10px 14px;background:var(--surface-soft);border-radius:8px;border:1px solid var(--border)">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;font-weight:500">
                                <input type="radio"
                                       name="items[{{ $ri->id }}][source]"
                                       value="normal"
                                       id="src-normal-{{ $ri->id }}"
                                       checked
                                       onchange="onSourceChange('{{ $ri->id }}', 'normal')"
                                       style="width:16px;height:16px">
                                <i class="fas fa-warehouse" style="color:var(--primary)"></i>
                                Normal Available Stock
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;font-weight:500">
                                <input type="radio"
                                       name="items[{{ $ri->id }}][source]"
                                       value="reserved"
                                       id="src-reserved-{{ $ri->id }}"
                                       onchange="onSourceChange('{{ $ri->id }}', 'reserved')"
                                       style="width:16px;height:16px">
                                <i class="fas fa-lock" style="color:var(--warning)"></i>
                                Reserved Items
                            </label>
                        </div>
                    </div>

                    {{-- ── Normal Stock panel ───────────────────────────────────────────── --}}
                    <div id="panel-normal-{{ $ri->id }}">
                        <div class="form-group">
                            <label class="form-label">Warehouse <span class="req">*</span></label>
                            <select name="items[{{ $ri->id }}][warehouse_id]"
                                    id="wh-select-{{ $ri->id }}"
                                    class="form-control {{ $errors->has('items.' . $ri->id . '.warehouse_id') ? 'is-invalid' : '' }}"
                                    data-description="{{ $ri->description ?? ($ri->item?->description ?? '') }}"
                                    onchange="onWhChange('{{ $ri->id }}')">
                                <option value="">— Select Warehouse —</option>
                                @foreach($warehouses as $w)
                                    <option value="{{ $w->id }}" {{ old('items.' . $ri->id . '.warehouse_id') == $w->id ? 'selected' : '' }}>
                                        {{ $w->name }}{{ $w->code ? ' (' . $w->code . ')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error("items.{$ri->id}.warehouse_id")
                            <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                                <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                            </small>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock Record <span class="req">*</span></label>
                            <select name="items[{{ $ri->id }}][item_id]"
                                    id="item-select-{{ $ri->id }}"
                                    class="form-control {{ $errors->has('items.' . $ri->id . '.item_id') ? 'is-invalid' : '' }}"
                                    data-restore-id="{{ old('items.' . $ri->id . '.item_id') }}"
                                    onchange="fillDispatchItem(this, '{{ $ri->id }}')">
                                <option value="">— Select Warehouse first —</option>
                            </select>
                            @error("items.{$ri->id}.item_id")
                            <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                                <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                            </small>
                            @enderror
                        </div>
                    </div>

                    {{-- ── Reserved Items panel (hidden by default) ─────────────────────── --}}
                    <div id="panel-reserved-{{ $ri->id }}" style="display:none">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock" style="color:var(--warning)"></i>
                                Select Reservation <span class="req">*</span>
                            </label>
                            <select id="res-select-{{ $ri->id }}"
                                    class="form-control"
                                    onchange="onReservationSelect('{{ $ri->id }}')"
                                    data-description="{{ $ri->description ?? ($ri->item?->description ?? '') }}">
                                <option value="">— Loading reservations… —</option>
                            </select>
                            <small style="color:var(--text-muted);font-size:11px">Only reservations containing {{ $ri->description ?? 'this item' }} with remaining quantity are shown.</small>
                        </div>

                        {{-- Reservation detail card (shown after selection) --}}
                        <div id="res-detail-{{ $ri->id }}" style="display:none;padding:12px 14px;background:#f0f9ff;border:1px solid #90cdf4;border-radius:8px;margin-bottom:12px;font-size:13px">
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:8px">
                                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">On Hand</div><div style="font-size:16px;font-weight:700" id="res-phys-{{ $ri->id }}">—</div></div>
                                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;color:var(--warning)">Reserved</div><div style="font-size:16px;font-weight:700;color:var(--warning)" id="res-total-{{ $ri->id }}">—</div></div>
                                <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;color:var(--success)">Remaining</div><div style="font-size:16px;font-weight:700;color:var(--success)" id="res-remain-{{ $ri->id }}">—</div></div>
                            </div>
                            <div id="res-meta-{{ $ri->id }}" style="font-size:11px;color:var(--text-muted)"></div>
                        </div>

                        {{-- Hidden fields populated when a reservation is selected --}}
                        <input type="hidden" name="items[{{ $ri->id }}][reservation_item_id]" id="res-item-id-{{ $ri->id }}" disabled>
                        <input type="hidden" name="items[{{ $ri->id }}][item_id]" id="res-item-stock-id-{{ $ri->id }}" disabled>
                        <input type="hidden" name="items[{{ $ri->id }}][warehouse_id]" id="res-item-wh-id-{{ $ri->id }}" disabled>
                        @error("items.{$ri->id}.reservation_item_id")
                        <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                            <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                        </small>
                        @enderror
                    </div>

                    {{-- ── Quantity + DR (shared for both sources) ──────────────────────── --}}
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Quantity to Issue Now <span class="req">*</span></label>
                            <input type="number"
                                   name="items[{{ $ri->id }}][quantity_issued]"
                                   id="qty-{{ $ri->id }}"
                                   class="form-control"
                                   min="0" step="1"
                                   value="{{ old('items.' . $ri->id . '.quantity_issued', 0) }}"
                                   oninput="checkDispatch('{{ $ri->id }}')">
                            @error("items.{$ri->id}.quantity_issued")
                            <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                                <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                            </small>
                            @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">DR Number <span class="req">*</span> <span class="hint">(Delivery Receipt)</span></label>
                            <input type="text" name="items[{{ $ri->id }}][dr_number]"
                                   id="dr-number-{{ $ri->id }}"
                                   class="form-control {{ $errors->has('items.' . $ri->id . '.dr_number') ? 'is-invalid' : '' }}"
                                   value="{{ old('items.' . $ri->id . '.dr_number') }}"
                                   placeholder="e.g. DR-001">
                            @error("items.{$ri->id}.dr_number")
                            <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                                <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                            </small>
                            @enderror
                        </div>
                    </div>

                    <input type="hidden" name="items[{{ $ri->id }}][unit_cost]" id="unit-cost-{{ $ri->id }}">
                    <input type="hidden" name="items[{{ $ri->id }}][engas_unit_cost]" id="engas-cost-{{ $ri->id }}">
                    <input type="hidden" name="items[{{ $ri->id }}][expiration_date]" id="expiry-date-{{ $ri->id }}">
                    @else
                        <input type="hidden" name="items[{{ $ri->id }}][quantity_issued]" value="0">
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>Approval Signatories</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Approved By (Name) <span class="req">*</span></label>
                        <input type="text" name="approved_by_name" class="form-control" value="{{ old('approved_by_name', auth()->user()->name) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Approved By (Designation)</label>
                        <input type="text" name="approved_by_designation" class="form-control" value="{{ old('approved_by_designation') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issued By (Name) <span class="req">*</span></label>
                        <input type="text" name="issued_by_name" class="form-control" value="{{ old('issued_by_name', auth()->user()->name) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issued By (Designation)</label>
                        <input type="text" name="issued_by_designation" class="form-control" value="{{ old('issued_by_designation') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Received By (Name)</label>
                        <input type="text" name="received_by_name" class="form-control" value="{{ old('received_by_name', $requisition->requested_by_name) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Received By (Designation)</label>
                        <input type="text" name="received_by_designation" class="form-control" value="{{ old('received_by_designation') }}">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>RIS Summary</h3></div>
            <div class="card-body" style="font-size:14px">
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">RIS Number:</span><br><strong>{{ $requisition->ris_number }}</strong></div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Warehouse(s):</span><br>{{ $requisition->warehouse_names }}</div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Purpose:</span><br>{{ $requisition->purpose }}</div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Items:</span><br>{{ $requisition->items->count() }} item(s)</div>
                @php
                    $totalReqSidebar = $requisition->items->sum('quantity_requested');
                    $totalIssSidebar = $requisition->items->sum('quantity_issued');
                    $totalRemSidebar = max(0, $totalReqSidebar - $totalIssSidebar);
                    $sidePct         = $totalReqSidebar > 0 ? min(100, round($totalIssSidebar / $totalReqSidebar * 100)) : 0;
                @endphp
                @if($totalIssSidebar > 0)
                <div style="margin-bottom:16px;padding:12px;background:#f0fff4;border-radius:8px;border:1px solid #9ae6b4">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px">Fulfilment So Far</div>
                    <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;margin-bottom:6px">
                        <div style="background:var(--success);width:{{ $sidePct }}%;height:100%;border-radius:999px"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:12px">
                        <span style="color:var(--success)">{{ number_format($totalIssSidebar) }} issued</span>
                        <span style="color:var(--warning)">{{ number_format($totalRemSidebar) }} remaining</span>
                    </div>
                </div>
                @endif
                <button type="submit" class="btn btn-success" style="width:100%;justify-content:center">
                    <i class="fas fa-check-circle"></i> Process Approval
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
(function () {
'use strict';

const ITEMS_API_URL   = '{{ route("requisitions.items_by_warehouse") }}';
const RES_API_URL     = '{{ route("reservations.api.for_dispatch") }}';

// Cache per-warehouse normal stock to avoid re-fetching
const stockCache = {};
// Cache reservation items per item description
const resCache   = {};

// ── Source toggle ─────────────────────────────────────────────────────────

window.onSourceChange = function (idx, source) {
    const normalPanel   = document.getElementById('panel-normal-' + idx);
    const reservedPanel = document.getElementById('panel-reserved-' + idx);
    const whSel         = document.getElementById('wh-select-' + idx);
    const itemSel       = document.getElementById('item-select-' + idx);
    const resSel        = document.getElementById('res-select-' + idx);

    if (source === 'normal') {
        if (normalPanel)   normalPanel.style.display = 'block';
        if (reservedPanel) reservedPanel.style.display = 'none';
        // Re-enable normal-stock required fields
        if (whSel)   whSel.disabled = false;
        if (itemSel) itemSel.disabled = false;
        // Clear reservation hidden fields
        clearReservationFields(idx);
        // Reset qty max to normal available
        const qtyInput = document.getElementById('qty-' + idx);
        if (qtyInput) qtyInput.max = '';
    } else {
        if (normalPanel)   normalPanel.style.display = 'none';
        if (reservedPanel) reservedPanel.style.display = 'block';
        // Disable normal fields so they don't submit
        if (whSel)   { whSel.disabled = true; }
        if (itemSel) { itemSel.disabled = true; }
        // Re-enable the reservation hidden fields so they DO submit
        ['res-item-id', 'res-item-stock-id', 'res-item-wh-id'].forEach(function (id) {
            const el = document.getElementById(id + '-' + idx);
            if (el) el.disabled = false;
        });
        // Load reservations for this item description
        loadReservationsForItem(idx);
    }
};

function clearReservationFields(idx) {
    // Disable ONLY the reservation-specific hidden fields (not the shared cost fields)
    ['res-item-id', 'res-item-stock-id', 'res-item-wh-id'].forEach(function (id) {
        const el = document.getElementById(id + '-' + idx);
        if (el) { el.value = ''; el.disabled = true; }
    });
    // Clear the shared cost/expiry fields (but keep them enabled — filled by normal stock selection)
    ['unit-cost', 'engas-cost', 'expiry-date'].forEach(function (id) {
        const el = document.getElementById(id + '-' + idx);
        if (el) { el.value = ''; el.disabled = false; }
    });
    // Also clear reservation_item_id
    const riEl = document.getElementById('res-item-id-' + idx);
    if (riEl) { riEl.value = ''; riEl.disabled = true; }
    const detail = document.getElementById('res-detail-' + idx);
    if (detail) detail.style.display = 'none';
    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) qtyInput.max = '';
}

// ── Reservation picker ─────────────────────────────────────────────────────

function loadReservationsForItem(idx) {
    const resSel = document.getElementById('res-select-' + idx);
    if (!resSel) return;

    const description = resSel.dataset.description || '';
    if (!description) {
        resSel.innerHTML = '<option value="">— No item description —</option>';
        return;
    }

    // Use cache
    if (resCache[description]) {
        populateReservationSelect(idx, resCache[description]);
        return;
    }

    resSel.innerHTML = '<option value="">Loading reservations…</option>';
    resSel.disabled = true;

    fetch(RES_API_URL + '?description=' + encodeURIComponent(description), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (data) {
        resCache[description] = data;
        populateReservationSelect(idx, data);
    })
    .catch(function (err) {
        resSel.innerHTML = '<option value="">Error loading reservations</option>';
        resSel.disabled = false;
        console.error('loadReservationsForItem failed:', err);
    });
}

function populateReservationSelect(idx, items) {
    const resSel = document.getElementById('res-select-' + idx);
    if (!resSel) return;

    if (!items || items.length === 0) {
        resSel.innerHTML = '<option value="">No active reservations available for this item</option>';
        resSel.disabled = true;
        return;
    }

    const opts = items.map(function (r) {
        return '<option value="' + r.reservation_item_id + '"'
            + ' data-item-id="' + r.item_id + '"'
            + ' data-wh-id="' + r.warehouse_id + '"'
            + ' data-remaining="' + r.remaining_quantity + '"'
            + ' data-reserved="' + r.reserved_quantity + '"'
            + ' data-physical="' + (r.physical_qty || '') + '"'
            + ' data-unit-cost="' + (r.unit_cost || '') + '"'
            + ' data-engas="' + (r.engas_unit_cost || '') + '"'
            + ' data-expiry="' + (r.expiration_date || '') + '"'
            + ' data-expiry-fmt="' + (r.expiry_formatted || '') + '"'
            + ' data-wh-name="' + escAttr(r.warehouse_name) + '"'
            + ' data-res-num="' + escAttr(r.reservation_number) + '"'
            + ' data-subsidy="' + escAttr(r.source_subsidy_code || '') + '"'
            + '>' + escHtml(r.display_label) + '</option>';
    }).join('');

    resSel.innerHTML = '<option value="">— Select a reservation —</option>' + opts;
    resSel.disabled = false;
}

window.onReservationSelect = function (idx) {
    const resSel  = document.getElementById('res-select-' + idx);
    const opt     = resSel && resSel.options[resSel.selectedIndex];
    const detail  = document.getElementById('res-detail-' + idx);
    const qtyInput= document.getElementById('qty-' + idx);

    if (!opt || !opt.value) {
        clearReservationFields(idx);
        return;
    }

    // Populate hidden fields for form submission
    const riIdEl = document.getElementById('res-item-id-' + idx);
    const itemEl = document.getElementById('res-item-stock-id-' + idx);
    const whEl   = document.getElementById('res-item-wh-id-' + idx);
    const costEl = document.getElementById('unit-cost-' + idx);
    const engasEl= document.getElementById('engas-cost-' + idx);
    const expiryEl= document.getElementById('expiry-date-' + idx);

    if (riIdEl)  riIdEl.value  = opt.value;
    if (itemEl)  itemEl.value  = opt.dataset.itemId  || '';
    if (whEl)    whEl.value    = opt.dataset.whId    || '';
    if (costEl)  costEl.value  = opt.dataset.unitCost || '';
    if (engasEl) engasEl.value = opt.dataset.engas   || '';
    if (expiryEl)expiryEl.value= opt.dataset.expiry  || '';

    // Update quantity max to remaining reserved
    const remaining = parseFloat(opt.dataset.remaining || 0);
    if (qtyInput) {
        qtyInput.max   = remaining;
        qtyInput.value = qtyInput.value > 0 ? Math.min(parseFloat(qtyInput.value), remaining) : '';
    }

    // Show detail card
    if (detail) {
        const physEl  = document.getElementById('res-phys-' + idx);
        const totalEl = document.getElementById('res-total-' + idx);
        const remEl   = document.getElementById('res-remain-' + idx);
        const metaEl  = document.getElementById('res-meta-' + idx);

        if (physEl)  physEl.textContent  = opt.dataset.physical ? parseInt(opt.dataset.physical).toLocaleString() : '—';
        if (totalEl) totalEl.textContent = parseFloat(opt.dataset.reserved || 0).toLocaleString();
        if (remEl)   remEl.textContent   = remaining.toLocaleString();

        const parts = [];
        if (opt.dataset.whName)   parts.push('Warehouse: ' + opt.dataset.whName);
        if (opt.dataset.unitCost) parts.push('₱' + parseFloat(opt.dataset.unitCost).toLocaleString('en-PH', {minimumFractionDigits:2}));
        if (opt.dataset.engas)    parts.push('ENGAS ₱' + parseFloat(opt.dataset.engas).toLocaleString('en-PH', {minimumFractionDigits:2}));
        if (opt.dataset.expiryFmt)parts.push('Exp: ' + opt.dataset.expiryFmt);
        if (opt.dataset.subsidy)  parts.push('Source: ' + opt.dataset.subsidy);
        if (metaEl) metaEl.textContent = parts.join(' · ');

        detail.style.display = 'block';
    }

    checkDispatch(idx);
};

// ── Normal stock loading ──────────────────────────────────────────────────

window.onWhChange = function (idx, restoreItemId) {
    const sel     = document.getElementById('wh-select-' + idx);
    const itemSel = document.getElementById('item-select-' + idx);
    if (!sel || !itemSel) return;

    itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
    resetDispatchCostFields(idx);

    if (!sel.value) return;

    itemSel.innerHTML = '<option value="">— Loading stock records… —</option>';
    itemSel.disabled  = true;

    const cacheKey = sel.value + '|' + (sel.dataset.description || '');
    if (stockCache[cacheKey]) {
        populateStockSelect(idx, stockCache[cacheKey], restoreItemId);
        return;
    }

    fetch(ITEMS_API_URL + '?warehouse_id=' + encodeURIComponent(sel.value) + '&description=' + encodeURIComponent(sel.dataset.description || ''), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (data) {
        stockCache[cacheKey] = data;
        populateStockSelect(idx, data, restoreItemId);
    })
    .catch(function () {
        itemSel.innerHTML = '<option value="">— Failed to load records —</option>';
        itemSel.disabled  = false;
    });
};

function populateStockSelect(idx, data, restoreItemId) {
    const itemSel = document.getElementById('item-select-' + idx);
    if (!itemSel) return;

    const opts = data.map(function (i) {
        return '<option value="' + i.id + '"'
            + ' data-unit-cost="' + (i.unit_cost || 0) + '"'
            + ' data-engas="' + (i.engas_unit_cost || '') + '"'
            + ' data-expiry="' + (i.expiry_date || '') + '"'
            + ' data-stock="' + i.quantity + '"'
            + ' data-available="' + (i.available_qty !== undefined ? i.available_qty : i.quantity) + '"'
            + ' data-reserved="' + (i.reserved_qty || 0) + '"'
            + ' data-sn="' + (i.stock_number || '') + '"'
            + ' data-unit="' + (i.unit || '') + '"'
            + '>' + (i.display_text || i.description) + '</option>';
    }).join('');

    itemSel.innerHTML = '<option value="">— Select Stock Record —</option>' + opts;
    itemSel.disabled  = false;

    if (window.SS && typeof window.SS.sync === 'function') {
        window.SS.sync(itemSel);
    }

    if (restoreItemId) {
        const found = Array.from(itemSel.options).find(function (o) { return o.value === String(restoreItemId); });
        if (found) {
            itemSel.value = String(restoreItemId);
            fillDispatchItem(itemSel, idx);
        }
    }
}

window.resetDispatchCostFields = function (idx) {
    ['unit-cost', 'engas-cost', 'expiry-date'].forEach(function (id) {
        const el = document.getElementById(id + '-' + idx);
        if (el) el.value = '';
    });
};

window.fillDispatchItem = function (sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        resetDispatchCostFields(idx);
        return;
    }

    const costEl   = document.getElementById('unit-cost-' + idx);
    const engasEl  = document.getElementById('engas-cost-' + idx);
    const expiryEl = document.getElementById('expiry-date-' + idx);

    if (costEl)   costEl.value   = opt.dataset.unitCost || '';
    if (engasEl)  engasEl.value  = opt.dataset.engas    || '';
    if (expiryEl) expiryEl.value = opt.dataset.expiry   || '';

    const available = parseFloat(opt.dataset.available || opt.dataset.stock || 0);
    const reserved  = parseFloat(opt.dataset.reserved  || 0);
    const physical  = parseFloat(opt.dataset.stock     || 0);

    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) {
        qtyInput.max = available;

        let hintEl = document.getElementById('avail-hint-' + idx);
        if (!hintEl) {
            hintEl = document.createElement('small');
            hintEl.id = 'avail-hint-' + idx;
            hintEl.style.cssText = 'display:block;margin-top:4px;font-size:11px';
            qtyInput.parentNode.insertBefore(hintEl, qtyInput.nextSibling);
        }
        if (reserved > 0) {
            hintEl.innerHTML =
                '<span style="color:var(--success)">Available: <strong>'
                + available.toLocaleString('en-PH', {maximumFractionDigits:0}) + '</strong></span>'
                + ' &nbsp;·&nbsp; On Hand: ' + physical.toLocaleString('en-PH', {maximumFractionDigits:0})
                + ' &nbsp;·&nbsp; <span style="color:var(--warning)">🔒 Reserved: '
                + reserved.toLocaleString('en-PH', {maximumFractionDigits:0}) + '</span>';
        } else {
            hintEl.innerHTML =
                '<span style="color:var(--success)">Available: <strong>'
                + available.toLocaleString('en-PH', {maximumFractionDigits:0}) + '</strong></span>';
        }
    }

    checkDispatch(idx);
};

window.checkDispatch = function (idx) {
    const qtyInput = document.getElementById('qty-' + idx);
    const drInput  = document.getElementById('dr-number-' + idx);
    if (!qtyInput) return;
    const qty = parseFloat(qtyInput.value) || 0;
    if (drInput) {
        if (qty > 0) drInput.setAttribute('required', '');
        else         drInput.removeAttribute('required');
    }
};

// ── Helpers ───────────────────────────────────────────────────────────────

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}
function escAttr(str) { return escHtml(str).replace(/"/g, '&quot;'); }

// ── Init ──────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    // On validation re-render, restore warehouse → stock record selection
    document.querySelectorAll('[id^="wh-select-"]').forEach(function (sel) {
        if (!sel.value) return;
        const idx = sel.id.replace('wh-select-', '');
        const itemSel = document.getElementById('item-select-' + idx);
        const restoreId = itemSel ? itemSel.dataset.restoreId : '';
        window.onWhChange(idx, restoreId || null);
    });
});

guardFormSubmit(document.getElementById('approval-form'));

})(); // end IIFE
</script>
@endpush
@endsection
