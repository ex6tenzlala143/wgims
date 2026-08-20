@extends('layouts.app')
@section('title', 'Edit Shipment')
@section('page-title', 'Edit Shipment')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Shipment — RIS #{{ $deliverySubsidy->ris_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> /
            <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}">RIS #{{ $deliverySubsidy->ris_number }}</a> /
            Edit Shipment
        </div>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <strong>Admin Edit Mode.</strong>
        Changing quantities will automatically adjust item stock levels, stock card entries,
        RIS (requisition items), and stock transfer records.
    </div>
</div>

<form action="{{ route('delivery_subsidies.update_delivery', [$deliverySubsidy->id, $delivery->id]) }}" method="POST" id="edit-delivery-form">
    @csrf @method('PUT')

    <div class="edit-layout">
        <div class="edit-main">

            {{-- ═══════════════════════════════════════════════════════════
                 SHIPMENT HEADER — DR No. + Qty Delivered for this shipment
            ══════════════════════════════════════════════════════════════ --}}
            <div class="card" style="margin-bottom:20px">
                <div class="card-header">
                    <h3><i class="fas fa-truck" style="color:var(--primary)"></i> Shipment Details</h3>
                </div>
                <div class="card-body">
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Delivery Date <span style="color:red">*</span></label>
                            <input type="date" name="delivery_date" class="form-control"
                                   value="{{ old('delivery_date', $delivery->delivery_date->format('Y-m-d')) }}" required>
                            @error('delivery_date')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Qty Delivered <span style="color:red">*</span>
                                <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— auto = sum of items below</span>
                            </label>
                            <input type="number" name="quantity_delivered" id="qty-delivered-header"
                                   class="form-control" step="0.01" readonly tabindex="-1"
                                   value="{{ old('quantity_delivered', $delivery->quantity_delivered) }}"
                                   style="background:#f7fafc;color:var(--text-muted)">
                            <div id="progress-hint" style="font-size:11px;margin-top:4px"></div>
                            @error('quantity_delivered')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-row cols-4">
                        <div class="form-group">
                            <label class="form-label">Supplier / Subsidy</label>
                            <input type="text" class="form-control" tabindex="-1"
                                   value="{{ $deliverySubsidy->supplier->name ?? '—' }}"
                                   style="background:#f7fafc;color:var(--text-muted)" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Condition Status <span style="color:red">*</span></label>
                            <select name="condition_status" class="form-control" required>
                                <option value="good"    {{ old('condition_status', $delivery->condition_status) === 'good'    ? 'selected' : '' }}>Good</option>
                                <option value="damaged" {{ old('condition_status', $delivery->condition_status) === 'damaged' ? 'selected' : '' }}>Damaged</option>
                                <option value="partial" {{ old('condition_status', $delivery->condition_status) === 'partial' ? 'selected' : '' }}>Partial</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control"
                                   value="{{ old('remarks', $delivery->remarks) }}" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Batch Number</label>
                            <input type="text" name="batch_number" class="form-control"
                                   value="{{ old('batch_number', $delivery->batch_number) }}" placeholder="Optional">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════
                 LINE ITEMS — one comfortable card per item
            ══════════════════════════════════════════════════════════════ --}}
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes"></i> Item Detail</h3>
                    <span style="font-size:12px;color:var(--text-muted)">Adjust quantities, unit cost, warehouse, ENGAS unit cost and DR#. Stock levels update automatically.</span>
                </div>

                <div style="padding:20px 20px 4px">
                    @foreach($delivery->items as $idx => $di)
                    <div class="shipment-item-card">
                        <input type="hidden" name="items[{{ $idx }}][di_id]" value="{{ $di->id }}">
                        @php
                            $dsLine     = $di->deliverySubsidyItem;
                            $lineMax    = $dsLine ? max(0, (float) $dsLine->quantity - (float) $dsLine->qty_delivered) : 0;
                            $editMaxQty = (float) $di->quantity_delivered + $lineMax;
                            $currentWh  = (int) ($di->warehouse_id ?? $dsLine?->warehouse_id ?? $di->item->warehouse_id ?? 0);
                            $defaultWh  = (int) old("items.{$idx}.warehouse_id", $currentWh);
                        @endphp

                        <div class="shipment-item-head">
                            <span class="ris-item-num">Item {{ $idx + 1 }}</span>
                            <div class="shipment-item-desc">
                                <strong class="item-desc-name">{{ $di->item->description ?? '-' }}</strong>
                                <div style="font-size:12px;color:var(--text-muted)">
                                    {{ $di->item->unit ?? '-' }}
                                    @if($di->item?->stock_number)
                                        · <code style="font-size:11px">{{ $di->item->stock_number }}</code>
                                    @endif
                                    · Current stock: <strong>{{ number_format($di->item->quantity ?? 0, 2) }}</strong>
                                </div>
                            </div>
                        </div>

                        {{-- Row 1: Warehouse · Qty Delivered · Unit Cost --}}
                        <div class="shipment-item-grid">
                            <div class="form-group">
                                <label class="form-label">Warehouse <span style="color:red">*</span></label>
                                <select name="items[{{ $idx }}][warehouse_id]" class="form-control" required>
                                    <option value="">— Select —</option>
                                    @foreach($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ $defaultWh === (int) $wh->id ? 'selected' : '' }}>
                                        {{ $wh->name }}
                                    </option>
                                    @endforeach
                                </select>
                                @error("items.{$idx}.warehouse_id")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                @php
                                    $oldQtyVal = old("items.{$idx}.quantity_delivered", $di->quantity_delivered);
                                @endphp
                                <label class="form-label">Quantity Delivered <span style="color:red">*</span></label>
                                <input type="number"
                                       name="items[{{ $idx }}][quantity_delivered]"
                                       id="qty-{{ $idx }}"
                                       class="form-control qty-delivered-input"
                                       value="{{ $oldQtyVal }}"
                                       min="0" step="0.01"
                                       max="{{ $editMaxQty }}"
                                       data-max="{{ $editMaxQty }}"
                                       required
                                       oninput="recalcRow({{ $idx }})">
                                <div class="hint" style="margin-top:4px">
                                    Max <strong>{{ number_format($editMaxQty, 2) }}</strong>
                                    ({{ number_format($lineMax, 2) }} remaining)
                                </div>
                                @error("items.{$idx}.quantity_delivered")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Unit Cost (₱) <span style="color:red">*</span></label>
                                <input type="number"
                                       name="items[{{ $idx }}][unit_cost]"
                                       id="cost-{{ $idx }}"
                                       class="form-control"
                                       value="{{ old("items.{$idx}.unit_cost", $di->unit_cost) }}"
                                       min="0.01" step="0.01" required
                                       oninput="recalcRow({{ $idx }})">
                                @error("items.{$idx}.unit_cost")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        {{-- Row 2: ENGAS Unit Cost · DR No. · Expiration --}}
                        <div class="shipment-item-grid">
                            <div class="form-group">
                                <label class="form-label">ENGAS Unit Cost (₱) <span style="color:red">*</span></label>
                                <input type="number"
                                       name="items[{{ $idx }}][engas_unit_cost]"
                                       id="engas-{{ $idx }}"
                                       class="form-control engas-cost-input"
                                       value="{{ old("items.{$idx}.engas_unit_cost", $di->engas_unit_cost) }}"
                                       min="0" step="0.01"
                                       oninput="recalcRow({{ $idx }})">
                                @error("items.{$idx}.engas_unit_cost")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">DR No. <span style="color:red">*</span></label>
                                <input type="text"
                                       name="items[{{ $idx }}][dr_number]"
                                       id="dr-{{ $idx }}"
                                       class="form-control"
                                       value="{{ old("items.{$idx}.dr_number", $di->dr_number ?? $delivery->dr_number) }}"
                                       maxlength="100" required>
                                @error("items.{$idx}.dr_number")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Expiration</label>
                                <input type="date"
                                       name="items[{{ $idx }}][expiration_date]"
                                       class="form-control"
                                       value="{{ old("items.{$idx}.expiration_date", $di->item?->expiration_date?->format('Y-m-d')) }}">
                            </div>
                        </div>

                        {{-- Row 3: computed totals --}}
                        <div class="shipment-item-total">
                            <span class="total-cell">
                                <span class="tlabel">Total Value</span>
                                <span class="tvalue" id="total-{{ $idx }}">
                                    ₱{{ number_format($di->quantity_delivered * $di->unit_cost, 2) }}
                                </span>
                            </span>
                            <span class="total-cell">
                                <span class="tlabel">ENGAS Total</span>
                                <span class="tvalue engas" id="etotal-{{ $idx }}">
                                    ₱{{ number_format($di->engas_total_cost ?? ($di->quantity_delivered * $di->engas_unit_cost), 2) }}
                                </span>
                            </span>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="shipment-grand">
                    <span class="total-cell">
                        <span class="tlabel">Grand Total</span>
                        <span class="tvalue" id="grand-total">
                            ₱{{ number_format($delivery->items->sum(fn($di) => $di->quantity_delivered * $di->unit_cost), 2) }}
                        </span>
                    </span>
                    <span class="total-cell">
                        <span class="tlabel">ENGAS Grand Total</span>
                        <span class="tvalue engas" id="grand-engas-total">
                            ₱{{ number_format($delivery->items->sum(fn($di) => $di->engas_total_cost ?? ($di->quantity_delivered * $di->engas_unit_cost)), 2) }}
                        </span>
                    </span>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="edit-side">
            <div class="card sticky-card">
                <div class="card-header"><h3>Summary</h3></div>
                <div class="card-body" style="font-size:14px">
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">Transaction No.</span><br>
                        <strong>{{ $deliverySubsidy->ris_number }}</strong>
                    </div>
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">Supplier</span><br>
                        {{ $deliverySubsidy->supplier->name ?? '-' }}
                    </div>
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">Warehouse</span><br>
                        {{ $deliverySubsidy->warehouse->name ?? '-' }}
                    </div>
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">Total Qty Requested</span><br>
                        <strong>{{ number_format($deliverySubsidy->quantity_requested, 2) }}</strong>
                    </div>
                    <div style="margin-bottom:12px">
                        <span style="color:var(--text-muted)">Original Delivery Date</span><br>
                        {{ $delivery->delivery_date->format('F d, Y') }}
                    </div>

                    {{-- Live progress after edit --}}
                    <div style="background:#f7fafc;border-radius:8px;padding:12px;font-size:12px;margin-bottom:12px">
                        <div style="color:var(--text-muted);margin-bottom:6px;font-weight:600">After saving this shipment:</div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                            <span>This shipment qty</span>
                            <strong id="sidebar-this">{{ number_format($delivery->quantity_delivered, 2) }}</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:6px;margin-top:6px">
                            <span>Status will be</span>
                            <strong id="sidebar-status">—</strong>
                        </div>
                    </div>

                    <div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:8px;padding:12px;font-size:12px;margin-bottom:16px">
                        <i class="fas fa-info-circle" style="color:var(--danger)"></i>
                        <strong>Stock Impact:</strong><br>
                        Increasing qty → adds stock.<br>
                        Decreasing qty → removes stock.<br>
                        Changing warehouse → moves stock between warehouses (old stock reversed first).<br>
                        Setting to 0 → fully reverses that line.
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}"
                       class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
const totalRequested  = {{ (float) $deliverySubsidy->quantity_requested }};
const otherDelivered  = {{ (float) ($deliverySubsidy->totalDelivered() - $delivery->quantity_delivered) }};

function recalcRow(idx) {
    const qty   = parseFloat(document.getElementById('qty-'   + idx)?.value) || 0;
    const cost  = parseFloat(document.getElementById('cost-'  + idx)?.value) || 0;
    const engas = parseFloat(document.getElementById('engas-' + idx)?.value) || 0;

    const el = document.getElementById('total-' + idx);
    if (el) {
        el.textContent = '₱\u00a0' + (qty * cost).toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    const eTotal = qty * engas;
    const etEl = document.getElementById('etotal-' + idx);
    if (etEl) {
        etEl.textContent = '₱\u00a0' + eTotal.toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    recalcGrand();
}

function fmtPhp(n) {
    return '₱\u00a0' + n.toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}

function recalcGrand() {
    let grand = 0, grandEngas = 0, qtySum = 0;

    document.querySelectorAll('.qty-delivered-input').forEach(el => {
        qtySum += parseFloat(el.value) || 0;
    });

    document.querySelectorAll('[id^="total-"]').forEach(el => {
        grand += parseFloat(el.textContent.replace(/[^\d.]/g, '')) || 0;
    });
    document.querySelectorAll('[id^="etotal-"]').forEach(el => {
        grandEngas += parseFloat(el.textContent.replace(/[^\d.]/g, '')) || 0;
    });

    const el = document.getElementById('grand-total');
    if (el) el.textContent = fmtPhp(grand);

    const ge = document.getElementById('grand-engas-total');
    if (ge) ge.textContent = fmtPhp(grandEngas);

    // Header quantity is always the sum of the per-item quantities
    const header = document.getElementById('qty-delivered-header');
    if (header) header.value = qtySum.toFixed(2);
}

function updateProgress() {
    const input   = document.getElementById('qty-delivered-header');
    const thisQty = parseFloat(input ? input.value : 0) || 0;
    const cumul   = otherDelivered + thisQty;
    const epsilon = 0.0001;

    const sThis   = document.getElementById('sidebar-this');
    const sStatus = document.getElementById('sidebar-status');
    if (sThis)   sThis.textContent = thisQty.toFixed(2);
    if (sStatus) {
        if (cumul >= totalRequested - epsilon) {
            sStatus.innerHTML = '<span style="color:var(--success)">Fully Delivered</span>';
        } else if (cumul > 0) {
            sStatus.innerHTML = '<span style="color:#d69e2e">Partial</span>';
        } else {
            sStatus.innerHTML = '<span style="color:var(--text-muted)">Pending</span>';
        }
    }

    const hint = document.getElementById('progress-hint');
    if (hint) {
        const remaining = Math.max(0, totalRequested - cumul);
        if (thisQty <= 0) {
            hint.innerHTML = '';
        } else if (cumul >= totalRequested - epsilon) {
            hint.innerHTML = '<span style="color:var(--success)"><i class="fas fa-check-circle"></i> Completes the request</span>';
        } else {
            hint.innerHTML = '<span style="color:#d69e2e"><i class="fas fa-exclamation-triangle"></i> '
                + remaining.toFixed(2) + ' will still remain</span>';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initial live totals
    document.querySelectorAll('.qty-delivered-input').forEach(el => {
        const id = el.id.replace('qty-', '');
        recalcRow(parseInt(id, 10));
    });
    updateProgress();

    // ENGAS Unit Cost is required for any line that keeps a positive quantity,
    // even though the field is not marked required in HTML (qty-0 rows skip it).
    document.querySelector('form').addEventListener('submit', function(e) {
        let missing = [];
        document.querySelectorAll('.qty-delivered-input').forEach(el => {
            const id    = parseInt(el.id.replace('qty-', ''), 10);
            const qty   = parseFloat(el.value) || 0;
            const engas = parseFloat(document.getElementById('engas-' + id)?.value || '');
            if (qty > 0 && !(engas >= 0)) {
                missing.push(el.closest('.shipment-item-card').querySelector('.item-desc-name').textContent.trim());
            }
        });
        if (missing.length > 0) {
            e.preventDefault();
            alert('ENGAS Unit Cost is required for the following dispatched item(s):\n\n'
                + missing.join('\n'));
            return false;
        }
    });

    // Never allow a double-click to save the same shipment edit twice.
    guardFormSubmit(document.querySelector('#edit-delivery-form'));
});
</script>
@endpush
@endsection
