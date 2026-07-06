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
</div>

<form action="{{ route('delivery_subsidies.store_delivery', $deliverySubsidy->id) }}" method="POST" id="delivery-form">
@csrf
<input type="hidden" name="delivery_date"      value="{{ $deliveryDate }}">
<input type="hidden" name="condition_status"   id="condition-status-hidden" value="good">
<input type="hidden" name="quantity_delivered" id="qty-delivered-hidden" value="0">

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">

    {{-- ── Left column ──────────────────────────────────────────────────── --}}
    <div>
        {{-- Shipment header --}}
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h3><i class="fas fa-truck" style="color:var(--primary)"></i> Shipment Details</h3>
                <span style="font-size:12px;color:var(--text-muted)">
                    Delivery Date: <strong>{{ \Carbon\Carbon::parse($deliveryDate)->format('F d, Y') }}</strong>
                </span>
            </div>
            <div class="card-body">
                <div class="form-row cols-3">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">DR No. <span style="color:red">*</span>
                            <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— this shipment's receipt</span>
                        </label>
                        <input type="text" name="dr_number" class="form-control"
                               value="{{ old('dr_number') }}" placeholder="e.g. DR-2026-002" required>
                        @error('dr_number')
                            <div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>
                        @enderror
                    </div>
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
            $lineRemaining = max(0, $poi->quantity - $poi->qty_delivered);
            $isDone        = $lineRemaining <= 0 && $totalRemaining <= 0;
        @endphp

        <div class="card" style="margin-bottom:16px" id="item-section-{{ $poiIdx }}">
            {{-- Item header --}}
            <div class="card-header" style="background:{{ $isDone ? '#f7fafc' : '#f0f9ff' }}">
                <div style="display:flex;align-items:center;gap:10px">
                    <div>
                        <strong style="font-size:14px">{{ $poi->item->description ?? '—' }}</strong>
                        <span style="font-size:12px;color:var(--text-muted);margin-left:6px">{{ $poi->item->unit ?? '' }}</span>
                        @if($isDone)
                            <span class="badge badge-success" style="margin-left:6px;font-size:10px">
                                <i class="fas fa-check"></i> Complete
                            </span>
                        @endif
                    </div>
                    <div style="margin-left:auto;display:flex;gap:16px;font-size:12px;text-align:right">
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Ordered</div>
                            <strong>{{ number_format($poi->quantity, 2) }}</strong>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Delivered</div>
                            <strong style="color:var(--success)">{{ number_format($poi->qty_delivered, 2) }}</strong>
                        </div>
                        <div>
                            <div style="color:var(--text-muted);font-size:10px;text-transform:uppercase">Still Needed</div>
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
                <div class="batch-row" data-poi="{{ $poiIdx }}" style="padding:14px 20px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end">
                    {{-- Hidden po_item_id for this batch --}}
                    <input type="hidden" name="items[{{ $poiIdx }}_0][ds_item_id]" value="{{ $poi->id }}">

                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label" style="font-size:12px">
                            Expiration Date
                            <span style="font-size:10px;color:var(--text-muted);font-weight:normal">— this batch</span>
                        </label>
                        <input type="date"
                               name="items[{{ $poiIdx }}_0][expiration_date]"
                               class="form-control"
                               value="{{ old("items.{$poiIdx}_0.expiration_date", $poi->item->expiration_date ? $poi->item->expiration_date->format('Y-m-d') : '') }}"
                               {{ $isDone ? 'disabled' : '' }}>
                    </div>

                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label" style="font-size:12px">
                            Unit Cost (₱) <span style="color:red">*</span>
                        </label>
                        <input type="number"
                               name="items[{{ $poiIdx }}_0][unit_cost]"
                               class="form-control"
                               min="0.01" step="0.01"
                               value="{{ old("items.{$poiIdx}_0.unit_cost", $poi->unit_cost > 0 ? number_format($poi->unit_cost, 2, '.', '') : '') }}"
                               placeholder="0.00"
                               {{ $isDone ? 'disabled' : 'required' }}>
                    </div>

                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label" style="font-size:12px">
                            Quantity <span style="color:red">*</span>
                            @if(!$isDone)
                                <span style="font-size:10px;color:var(--text-muted);font-weight:normal">max {{ number_format($lineRemaining, 2) }}</span>
                            @endif
                        </label>
                        <input type="number"
                               name="items[{{ $poiIdx }}_0][quantity_delivered]"
                               class="form-control item-qty"
                               min="0" step="0.01"
                               value="{{ old("items.{$poiIdx}_0.quantity_delivered", 0) }}"
                               placeholder="0"
                               {{ $isDone ? 'disabled' : '' }}
                               oninput="recalcTotal()"
                               style="{{ $isDone ? '' : 'border:2px solid var(--primary);font-weight:700' }}">
                    </div>

                    {{-- Remove button (hidden on first row, shown when cloned) --}}
                    <div style="padding-bottom:2px">
                        <button type="button" class="btn btn-sm btn-danger remove-batch-btn"
                                style="display:none" onclick="removeBatch(this)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Add Batch button --}}
            @if(!$isDone)
            <div style="padding:10px 20px;background:#f7fafc">
                <button type="button" class="btn btn-sm btn-outline"
                        onclick="addBatch({{ $poiIdx }}, {{ $poi->id }}, '{{ $poi->item->expiration_date ? $poi->item->expiration_date->format('Y-m-d') : '' }}', {{ $poi->unit_cost ?? 0 }})">
                    <i class="fas fa-plus" style="color:var(--primary)"></i>
                    Add Batch
                    <span style="font-size:11px;color:var(--text-muted);margin-left:4px">— different expiry or unit cost</span>
                </button>
            </div>
            @endif
        </div>
        @endforeach
    </div>

    {{-- ── Right sidebar ─────────────────────────────────────────────────── --}}
    <div>
        <div class="card" style="position:sticky;top:80px">
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

// Track how many batch rows exist per PO item (starts at 1 — the default row)
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

    document.getElementById('sidebar-cumul').textContent  = cumul.toFixed(2);
    document.getElementById('sidebar-remain').textContent = remaining.toFixed(2);
    document.getElementById('sidebar-remain').style.color = remaining > 0 ? 'var(--warning)' : 'var(--success)';

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
 * Add a new batch row for a given PO item.
 * @param {number} poiIdx     - index of the PO item in the Blade loop
 * @param {number} poItemId   - delivery_subsidy_items.id
 * @param {string} defaultExpiry - pre-fill expiry from item master
 * @param {number} defaultCost   - pre-fill unit cost from PO line
 * @param {number|null} expiryYear
 */
function addBatch(poiIdx, poItemId, defaultExpiry, defaultCost) {
    if (!batchCounts[poiIdx]) batchCounts[poiIdx] = 1;
    const batchIdx = batchCounts[poiIdx]++;
    const key      = poiIdx + '_' + batchIdx;

    const container = document.getElementById('batches-' + poiIdx);

    const div = document.createElement('div');
    div.className = 'batch-row';
    div.dataset.poi = poiIdx;
    div.style.cssText = 'padding:14px 20px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;background:#fffbeb';

    div.innerHTML = `
        <input type="hidden" name="items[${key}][ds_item_id]" value="${poItemId}">

        <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">
                Expiration Date
                <span style="font-size:10px;color:var(--text-muted);font-weight:normal">— this batch</span>
            </label>
            <input type="date"
                   name="items[${key}][expiration_date]"
                   class="form-control"
                   value="${defaultExpiry}"
                   style="border-color:#f6ad55">
        </div>

        <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">
                Unit Cost (₱) <span style="color:red">*</span>
            </label>
            <input type="number"
                   name="items[${key}][unit_cost]"
                   class="form-control"
                   min="0.01" step="0.01"
                   value="${defaultCost > 0 ? defaultCost.toFixed(2) : ''}"
                   placeholder="0.00"
                   required
                   style="border-color:#f6ad55">
        </div>

        <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-size:12px">
                Quantity <span style="color:red">*</span>
            </label>
            <input type="number"
                   name="items[${key}][quantity_delivered]"
                   class="form-control item-qty"
                   min="0" step="0.01"
                   value="0"
                   placeholder="0"
                   oninput="recalcTotal()"
                   style="border:2px solid #f6ad55;font-weight:700">
        </div>

        <div style="padding-bottom:2px">
            <button type="button" class="btn btn-sm btn-danger remove-batch-btn"
                    onclick="removeBatch(this)">
                <i class="fas fa-times"></i>
            </button>
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
