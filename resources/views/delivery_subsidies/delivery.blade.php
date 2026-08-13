@extends('layouts.app')
@section('title', 'Record Shipment')
@section('page-title', 'Record Shipment')

@section('content')

@php
    $totalRequested = (float) $deliverySubsidy->quantity_requested;
    $totalDelivered = (float) $deliverySubsidy->deliveries()->sum('quantity_delivered');
    $totalRemaining = max(0, $totalRequested - $totalDelivered);
    $pct            = $totalRequested > 0 ? min(100, round($totalDelivered / $totalRequested * 100)) : 0;
    $deliveryDate   = old('delivery_date', date('Y-m-d'));
    $whList         = $warehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values();
@endphp

<div class="page-header">
    <div>
        <h1>Record Shipment — RIS #{{ $deliverySubsidy->ris_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> /
            <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}">RIS #{{ $deliverySubsidy->ris_number }}</a> /
            Record Shipment
        </div>
    </div>
</div>

{{-- Fulfilment progress bar --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px">
            <strong>Fulfilment Progress</strong>
            <span>
                <strong style="color:var(--success)">{{ number_format($totalDelivered, 2) }}</strong>
                of <strong>{{ number_format($totalRequested, 2) }}</strong> requested
                &nbsp;—&nbsp;
                <strong style="color:{{ $totalRemaining > 0 ? 'var(--warning)' : 'var(--success)' }}">
                    {{ $totalRemaining > 0 ? number_format($totalRemaining, 2).' still needed' : '✓ Fully delivered' }}
                </strong>
            </span>
        </div>
        <div style="background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden">
            <div style="background:{{ $pct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $pct }}%;height:100%;border-radius:999px"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
            {{ $pct }}% — {{ $deliverySubsidy->deliveries()->count() }} shipment(s) recorded so far
        </div>
    </div>
</div>

<div class="alert alert-info" style="font-size:13px">
    <i class="fas fa-info-circle"></i>
    <strong>Multiple batches supported.</strong>
    If the same item arrives in different lots with different expiry dates or unit costs,
    click <strong>+ Add Batch</strong> on that item's row to add a second (or third) batch entry.
    Each batch gets its own stock card.
    <br>
    <i class="fas fa-warehouse" style="margin-top:6px"></i>
    For each batch, choose the <strong>destination warehouse</strong>, the <strong>unit cost</strong>,
    and its own <strong>DR No.</strong>. Stock is recorded in the selected warehouse's inventory
    and stock card, and each item keeps its own Delivery Receipt number.
</div>

<form action="{{ route('delivery_subsidies.store_delivery', $deliverySubsidy->id) }}" method="POST" id="delivery-form">
@csrf
<input type="hidden" name="delivery_date"      value="{{ $deliveryDate }}">
<input type="hidden" name="condition_status"   id="condition-status-hidden" value="good">
<input type="hidden" name="quantity_delivered" id="qty-delivered-hidden" value="0">

<div class="edit-layout">

    {{-- ── Left column ──────────────────────────────────────────────────── --}}
    <div class="edit-main">
        {{-- Shipment header --}}
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h3><i class="fas fa-truck" style="color:var(--primary)"></i> Shipment Details</h3>
                <span style="font-size:12px;color:var(--text-muted)">
                    Delivery Date: <strong>{{ \Carbon\Carbon::parse($deliveryDate)->format('F d, Y') }}</strong>
                </span>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Batch No. <span style="font-size:11px;color:var(--text-muted);font-weight:normal">optional</span></label>
                        <input type="text" name="batch_number" class="form-control"
                               value="{{ old('batch_number') }}" placeholder="Optional">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">Remarks <span style="font-size:11px;color:var(--text-muted);font-weight:normal">optional</span></label>
                        <input type="text" name="remarks" class="form-control"
                               value="{{ old('remarks') }}" placeholder="Optional notes">
                    </div>
                </div>
            </div>
        </div>

        {{-- Per-item sections --}}
        @foreach($deliverySubsidy->items as $poiIdx => $poi)
        @php
            // Per-item remaining: this line is done when ITS OWN requested
            // quantity has been fully dispatched — independent of other lines.
            $lineRemaining = max(0, $poi->quantity - $poi->qty_delivered);
            $isDone        = $lineRemaining <= 0;
        @endphp

        <div class="card" style="margin-bottom:16px" id="item-section-{{ $poiIdx }}" data-remaining="{{ $lineRemaining }}">
            {{-- Item header --}}
            <div class="card-header" style="background:{{ $isDone ? '#f7fafc' : '#f0f9ff' }}">
                <div style="display:flex;align-items:center;gap:10px">
                    <div>
                        <strong style="font-size:14px">{{ $poi->item->description ?? $poi->description ?? '—' }}</strong>
                        <span style="font-size:12px;color:var(--text-muted);margin-left:6px">{{ $poi->item->unit ?? $poi->unit ?? '' }}</span>
                        @if($poi->warehouse)
                            <span class="badge badge-info" style="margin-left:8px;font-size:10px" title="Assigned warehouse">
                                <i class="fas fa-warehouse"></i> {{ $poi->warehouse->name }}
                            </span>
                        @endif
                        @if($isDone)
                            <span class="badge badge-success" style="margin-left:6px;font-size:10px" title="Requested quantity has already been fully dispatched">
                                <i class="fas fa-check"></i> Fully Dispatched
                            </span>
                        @else
                            <span class="badge badge-warning" style="margin-left:6px;font-size:10px" title="Still dispatchable">
                                <i class="fas fa-hourglass-half"></i> Pending
                            </span>
                        @endif
                    </div>
                    <div style="margin-left:auto;display:flex;gap:16px;font-size:12px;text-align:right">
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Requested</div>
                            <strong>{{ number_format($poi->quantity, 2) }}</strong>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Dispatched</div>
                            <strong style="color:var(--success)">{{ number_format($poi->qty_delivered, 2) }}</strong>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Remaining</div>
                            <strong style="color:{{ $lineRemaining > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $lineRemaining > 0 ? number_format($lineRemaining, 2) : '—' }}
                            </strong>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Batch rows container --}}
            <div id="batches-{{ $poiIdx }}">
                {{-- First (default) batch row --}}
                <div class="batch-row" data-poi="{{ $poiIdx }}">
                    {{-- Hidden po_item_id for this batch. Disabled for fully
                         delivered items so the row is excluded from the
                         submission payload entirely. --}}
                    <input type="hidden" name="items[{{ $poiIdx }}_0][ds_item_id]" value="{{ $poi->id }}"
                           {{ $isDone ? 'disabled' : '' }}>

                    <div class="batch-row-head">
                        <span class="ris-item-num">Batch 1</span>
                        <button type="button" class="btn btn-sm btn-danger remove-batch-btn"
                                style="display:none" onclick="removeBatch(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    {{-- Row 1: Warehouse · Expiration · Quantity --}}
                    <div class="shipment-item-grid">
                        <div class="form-group">
                            <label class="form-label">Warehouse <span style="color:red">*</span></label>
                            <select name="items[{{ $poiIdx }}_0][warehouse_id]"
                                    class="form-control batch-wh"
                                    {{ $isDone ? 'disabled' : 'required' }}>
                                <option value="">— Select warehouse —</option>
                                @foreach($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                    {{ (int) old("items.{$poiIdx}_0.warehouse_id", $poi->warehouse_id ?: ($poi->item?->warehouse_id ?? 0)) === (int) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Expiration Date <span class="hint">— this batch</span></label>
                            <input type="date"
                                   name="items[{{ $poiIdx }}_0][expiration_date]"
                                   class="form-control"
                                   value="{{ old("items.{$poiIdx}_0.expiration_date", $poi->item?->expiration_date ? $poi->item->expiration_date->format('Y-m-d') : '') }}"
                                   {{ $isDone ? 'disabled' : '' }}>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                Quantity <span style="color:red">*</span>
                                @if(!$isDone)
                                    <span class="hint">max {{ number_format($lineRemaining, 2) }}</span>
                                @else
                                    <span class="hint" style="color:var(--success)">no more dispatchable</span>
                                @endif
                            </label>
                            <input type="number"
                                   name="items[{{ $poiIdx }}_0][quantity_delivered]"
                                   class="form-control item-qty"
                                   min="0" step="0.01"
                                   max="{{ $lineRemaining }}"
                                   data-max="{{ $lineRemaining }}"
                                   value="{{ old("items.{$poiIdx}_0.quantity_delivered", 0) }}"
                                   placeholder="{{ $isDone ? 'Fully dispatched' : '0' }}"
                                   {{ $isDone ? 'disabled' : '' }}
                                   oninput="recalcBatch(this)"
                                   style="{{ $isDone ? 'background:#f7fafc;opacity:.6' : 'border:2px solid var(--primary);font-weight:700' }}">
                            @error("items.{$poiIdx}_0.quantity_delivered")
                                <div style="color:var(--danger);font-size:11px;margin-top:4px"><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    {{-- Row 2: Unit Cost · ENGAS Unit Cost · DR No. --}}
                    <div class="shipment-item-grid">
                        <div class="form-group">
                            <label class="form-label">Unit Cost (₱) <span style="color:red">*</span></label>
                            <input type="number"
                                   name="items[{{ $poiIdx }}_0][unit_cost]"
                                   class="form-control"
                                   min="0.01" step="0.01"
                                   value="{{ old("items.{$poiIdx}_0.unit_cost", $poi->unit_cost > 0 ? number_format($poi->unit_cost, 2, '.', '') : '') }}"
                                   placeholder="0.00"
                                   {{ $isDone ? 'disabled' : 'required' }}>
                        </div>

                        <div class="form-group">
                            <label class="form-label">ENGAS Unit Cost (₱) <span style="color:red">*</span></label>
                            <input type="number"
                                   name="items[{{ $poiIdx }}_0][engas_unit_cost]"
                                   class="form-control batch-engas"
                                   min="0" step="0.01"
                                   value="{{ old("items.{$poiIdx}_0.engas_unit_cost", $poi->item?->engas_unit_cost ? number_format($poi->item->engas_unit_cost, 2, '.', '') : '') }}"
                                   placeholder="0.00"
                                   {{ $isDone ? 'disabled' : 'required' }}
                                   oninput="recalcBatch(this)">
                            <div class="hint" style="margin-top:4px">
                                ENGAS Total: <span class="engas-total" style="font-weight:700;color:var(--primary)">₱0.00</span>
                            </div>
                            @error("items.{$poiIdx}_0.engas_unit_cost")
                                <div style="color:var(--danger);font-size:11px;margin-top:4px"><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label class="form-label">DR No. <span style="color:red">*</span></label>
                            <input type="text"
                                   name="items[{{ $poiIdx }}_0][dr_number]"
                                   class="form-control batch-dr"
                                   value="{{ old("items.{$poiIdx}_0.dr_number") }}"
                                   placeholder="e.g. DR-2026-002"
                                   {{ $isDone ? 'disabled' : 'required' }}
                                   maxlength="100">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Add Batch button --}}
            @if(!$isDone)
            <div style="padding:10px 20px;background:#f7fafc">
                <button type="button" class="btn btn-sm btn-outline"
                        onclick="addBatch({{ $poiIdx }}, {{ $poi->id }}, '{{ $poi->item?->expiration_date ? $poi->item->expiration_date->format('Y-m-d') : '' }}', {{ $poi->unit_cost ?? 0 }}, {{ $poi->warehouse_id ?: ($poi->item?->warehouse_id ?? 0) }}, {{ $lineRemaining }}, {{ $poi->item?->engas_unit_cost ?? 0 }})">
                    <i class="fas fa-plus" style="color:var(--primary)"></i>
                    Add Batch
                    <span style="font-size:11px;color:var(--text-muted);margin-left:4px">— different expiry or unit cost</span>
                </button>
            </div>
            @endif

            {{-- Per-item batch overflow warning (filled in by JS) --}}
            <div class="batch-overflow" data-poi="{{ $poiIdx }}" style="display:none;padding:10px 20px;background:#fff5f5;color:var(--danger);font-size:12px;border-top:1px solid var(--border)">
                <i class="fas fa-exclamation-circle"></i>
                The combined quantity across all batches for this item exceeds its remaining quantity of
                <strong>{{ number_format($lineRemaining, 2) }}</strong>.
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── Right sidebar ─────────────────────────────────────────────────── --}}
    <div class="edit-side">
        <div class="card sticky-card">
            <div class="card-header"><h3>Summary</h3></div>
            <div class="card-body">
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">Transaction</div>
                    <strong>{{ $deliverySubsidy->ris_number }}</strong>
                </div>
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">Supplier</div>
                    <strong>{{ $deliverySubsidy->supplier->name ?? '—' }}</strong>
                </div>
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">Delivery Date</div>
                    <strong>{{ \Carbon\Carbon::parse($deliveryDate)->format('F d, Y') }}</strong>
                </div>

                <hr style="border-color:var(--border);margin:12px 0">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;text-align:center">
                    <div style="background:#f0f9ff;border-radius:8px;padding:10px">
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-bottom:2px">Requested</div>
                        <div style="font-size:20px;font-weight:800;color:var(--primary)">{{ number_format($totalRequested, 2) }}</div>
                    </div>
                    <div style="background:#f0fff4;border-radius:8px;padding:10px">
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-bottom:2px">Delivered</div>
                        <div style="font-size:20px;font-weight:800;color:var(--success)">{{ number_format($totalDelivered, 2) }}</div>
                    </div>
                </div>

                <div style="background:#fff;border:2px solid var(--primary);border-radius:8px;padding:12px;margin-bottom:12px;text-align:center">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">This Shipment</div>
                    <div id="sidebar-qty" style="font-size:28px;font-weight:800;color:var(--primary)">0.00</div>
                </div>

                <div style="background:#f7fafc;border-radius:8px;padding:12px;font-size:13px;margin-bottom:16px">
                    <div style="color:var(--text-muted);font-size:11px;font-weight:600;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">After this shipment</div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span>Total delivered</span>
                        <strong id="sidebar-cumul">{{ number_format($totalDelivered, 2) }}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px">
                        <span>Still remaining</span>
                        <strong id="sidebar-remain" style="color:var(--warning)">{{ number_format($totalRemaining, 2) }}</strong>
                    </div>
                    <div id="sidebar-status" style="text-align:center;font-weight:600;padding:8px;border-radius:6px;font-size:12px"></div>
                </div>

                <button type="submit" class="btn btn-success" style="width:100%;justify-content:center;margin-bottom:8px">
                    <i class="fas fa-check"></i> Record Shipment & Update Stock
                </button>
                <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}"
                   class="btn btn-secondary" style="width:100%;justify-content:center">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>

</div>
</form>

@push('scripts')
<script>
const _alreadyDelivered = {{ $totalDelivered }};
const _totalRequested   = {{ $totalRequested }};

// Warehouses available to this dispatcher
const warehouseList = {!! json_encode($whList) !!};

function warehouseOptionsHtml(selectedId) {
    let html = '<option value="">— Select warehouse —</option>';
    warehouseList.forEach(function(w) {
        const sel = String(w.id) === String(selectedId) ? ' selected' : '';
        html += '<option value="' + w.id + '"' + sel + '>' + w.name.replace(/</g, '&lt;') + '</option>';
    });
    return html;
}

// Track how many batch rows exist per subsidy item (starts at 1 — the default row)
const batchCounts = {};

function recalcTotal() {
    let sum = 0;
    document.querySelectorAll('.item-qty').forEach(function(el) {
        sum += parseFloat(el.value) || 0;
    });

    document.getElementById('qty-delivered-hidden').value = sum.toFixed(4);
    document.getElementById('sidebar-qty').textContent    = sum.toFixed(2);

    const cumul     = _alreadyDelivered + sum;
    const remaining = Math.max(0, _totalRequested - cumul);
    const epsilon   = 0.0001;

    // Per-row enforcement: a single batch cannot exceed its line's remaining
    // quantity (drives the browser's native validation message on submit).
    document.querySelectorAll('.item-qty').forEach(function(el) {
        const max = parseFloat(el.dataset.max) || 0;
        const val = parseFloat(el.value) || 0;
        if (max > 0 && val > max + epsilon) {
            el.setCustomValidity('Cannot exceed the remaining quantity of ' + max + ' for this item.');
        } else {
            el.setCustomValidity('');
        }
    });

    // Per-item enforcement: all batches for one subsidy line, combined, must
    // stay within that line's remaining quantity.
    document.querySelectorAll('.batch-overflow').forEach(function(msg) {
        const section = document.getElementById('item-section-' + msg.dataset.poi);
        let itemSum = 0;
        if (section) {
            section.querySelectorAll('.item-qty').forEach(function(el) {
                itemSum += parseFloat(el.value) || 0;
            });
        }
        const remainingLine = parseFloat(section ? section.dataset.remaining : 0) || 0;
        msg.style.display   = (itemSum > remainingLine + epsilon) ? 'block' : 'none';
    });

    document.getElementById('sidebar-cumul').textContent  = cumul.toFixed(2);
    document.getElementById('sidebar-remain').textContent = remaining.toFixed(2);
    document.getElementById('sidebar-remain').style.color = remaining > 0 ? 'var(--warning)' : 'var(--success)';

    // Refresh per-row ENGAS totals (qty x ENGAS unit cost)
    document.querySelectorAll('.batch-row').forEach(function(row) {
        const qty   = parseFloat(row.querySelector('.item-qty').value) || 0;
        const engas = parseFloat(row.querySelector('.batch-engas').value) || 0;
        const span  = row.querySelector('.engas-total');
        if (span) span.textContent = '₱' + (qty * engas)
            .toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });

    // Set condition_status based on fulfilment: good if complete, partial otherwise
    const conditionEl = document.getElementById('condition-status-hidden');
    if (conditionEl) {
        conditionEl.value = (sum > 0 && cumul >= _totalRequested - epsilon) ? 'good' : 'partial';
    }

    const statusEl = document.getElementById('sidebar-status');
    if (sum <= 0) {
        statusEl.textContent      = '';
        statusEl.style.background = 'transparent';
    } else if (cumul >= _totalRequested - epsilon) {
        statusEl.innerHTML        = '<i class="fas fa-check-circle"></i> Will be Fully Delivered';
        statusEl.style.color      = 'var(--success)';
        statusEl.style.background = '#f0fff4';
    } else {
        statusEl.innerHTML        = '<i class="fas fa-exclamation-triangle"></i> Will remain Partial — '
                                    + remaining.toFixed(2) + ' still needed';
        statusEl.style.color      = '#744210';
        statusEl.style.background = '#fffff0';
    }
}

/**
 * Recompute the ENGAS total (qty x ENGAS unit cost) for one batch row,
 * then refresh the grand totals.
 */
function recalcBatch(el) {
    const row = el.closest('.batch-row');
    if (row) {
        const qty   = parseFloat(row.querySelector('.item-qty').value) || 0;
        const engas = parseFloat(row.querySelector('.batch-engas').value) || 0;
        const span  = row.querySelector('.engas-total');
        if (span) span.textContent = '₱' + (qty * engas)
            .toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    recalcTotal();
}

/**
 * Add a new batch row for a given subsidy item.
 * @param {number} poiIdx     - index of the subsidy item in the Blade loop
 * @param {number} poItemId   - delivery_subsidy_items.id
 * @param {string} defaultExpiry - pre-fill expiry from item master
 * @param {number} defaultCost   - pre-fill unit cost from subsidy line
 * @param {number} defaultWh     - pre-fill warehouse
 * @param {number} maxQty        - remaining dispatchable quantity for this line
 * @param {number} defaultEngas  - pre-fill ENGAS unit cost from item master
 */
function addBatch(poiIdx, poItemId, defaultExpiry, defaultCost, defaultWh, maxQty, defaultEngas) {
    if (!batchCounts[poiIdx]) batchCounts[poiIdx] = 1;
    const batchIdx = batchCounts[poiIdx]++;
    const key      = poiIdx + '_' + batchIdx;

    const container = document.getElementById('batches-' + poiIdx);

    const div = document.createElement('div');
    div.className = 'batch-row batch-row-cloned';
    div.dataset.poi = poiIdx;

    div.innerHTML = `
        <input type="hidden" name="items[${key}][ds_item_id]" value="${poItemId}">

        <div class="batch-row-head">
            <span class="ris-item-num">Batch ${batchIdx + 1}</span>
            <button type="button" class="btn btn-sm btn-danger remove-batch-btn"
                    onclick="removeBatch(this)">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="shipment-item-grid">
            <div class="form-group">
                <label class="form-label">Warehouse <span style="color:red">*</span></label>
                <select name="items[${key}][warehouse_id]"
                        class="form-control batch-wh"
                        required
                        style="border-color:#f6ad55">
                    ${warehouseOptionsHtml(defaultWh || '')}
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Expiration Date <span class="hint">— this batch</span></label>
                <input type="date"
                       name="items[${key}][expiration_date]"
                       class="form-control"
                       value="${defaultExpiry}"
                       style="border-color:#f6ad55">
            </div>

            <div class="form-group">
                <label class="form-label">
                    Quantity <span style="color:red">*</span>
                    <span class="hint">max ${maxQty.toFixed(2)}</span>
                </label>
                <input type="number"
                       name="items[${key}][quantity_delivered]"
                       class="form-control item-qty"
                       min="0" step="0.01"
                       max="${maxQty}"
                       data-max="${maxQty}"
                       value="0"
                       placeholder="0"
                       oninput="recalcBatch(this)"
                       style="border:2px solid #f6ad55;font-weight:700">
            </div>
        </div>

        <div class="shipment-item-grid">
            <div class="form-group">
                <label class="form-label">Unit Cost (₱) <span style="color:red">*</span></label>
                <input type="number"
                       name="items[${key}][unit_cost]"
                       class="form-control"
                       min="0.01" step="0.01"
                       value="${defaultCost > 0 ? defaultCost.toFixed(2) : ''}"
                       placeholder="0.00"
                       required
                       style="border-color:#f6ad55">
            </div>

            <div class="form-group">
                <label class="form-label">ENGAS Unit Cost (₱) <span style="color:red">*</span></label>
                <input type="number"
                       name="items[${key}][engas_unit_cost]"
                       class="form-control batch-engas"
                       min="0" step="0.01"
                       value="${defaultEngas > 0 ? defaultEngas.toFixed(2) : ''}"
                       placeholder="0.00"
                       required
                       oninput="recalcBatch(this)"
                       style="border-color:#f6ad55">
                <div class="hint" style="margin-top:4px">
                    ENGAS Total: <span class="engas-total" style="font-weight:700;color:var(--primary)">₱0.00</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">DR No. <span style="color:red">*</span></label>
                <input type="text"
                       name="items[${key}][dr_number]"
                       class="form-control batch-dr"
                       maxlength="100"
                       placeholder="e.g. DR-2026-002"
                       required
                       style="border-color:#f6ad55">
            </div>
        </div>
    `;

    container.appendChild(div);
    recalcTotal();
}

function removeBatch(btn) {
    const row = btn.closest('.batch-row');
    if (row) {
        row.remove();
        recalcTotal();
    }
}

document.addEventListener('DOMContentLoaded', recalcTotal);
</script>
@endpush
@endsection
