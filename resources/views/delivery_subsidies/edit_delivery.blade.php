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
        The delta between old and new quantity is applied — no stock is double-counted.
    </div>
</div>

<form action="{{ route('delivery_subsidies.update_delivery', [$deliverySubsidy->id, $delivery->id]) }}" method="POST">
    @csrf @method('PUT')

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
        <div>

            {{-- ═══════════════════════════════════════════════════════════
                 SHIPMENT HEADER — DR No. + Qty Delivered for this shipment
            ══════════════════════════════════════════════════════════════ --}}
            <div class="card" style="margin-bottom:20px">
                <div class="card-header">
                    <h3><i class="fas fa-truck" style="color:var(--primary)"></i> Shipment Details</h3>
                </div>
                <div class="card-body">
                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label class="form-label">Delivery Date <span style="color:red">*</span></label>
                            <input type="date" name="delivery_date" class="form-control"
                                   value="{{ old('delivery_date', $delivery->delivery_date->format('Y-m-d')) }}" required>
                            @error('delivery_date')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">DR No. <span style="color:red">*</span>
                                <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— this shipment's receipt</span>
                            </label>
                            <input type="text" name="dr_number" class="form-control"
                                   value="{{ old('dr_number', $delivery->dr_number) }}"
                                   placeholder="e.g. DR-2026-001" required>
                            @error('dr_number')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Qty Delivered <span style="color:red">*</span>
                                <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— this shipment only</span>
                            </label>
                            <input type="number" name="quantity_delivered" id="qty-delivered-header"
                                   class="form-control" min="0" step="0.01"
                                   value="{{ old('quantity_delivered', $delivery->quantity_delivered) }}"
                                   oninput="updateProgress()" required>
                            <div id="progress-hint" style="font-size:11px;margin-top:4px"></div>
                            @error('quantity_delivered')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-row cols-3">
                        <div class="form-group">
                            <label class="form-label">Batch Number</label>
                            <input type="text" name="batch_number" class="form-control"
                                   value="{{ old('batch_number', $delivery->batch_number) }}" placeholder="Optional">
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
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════
                 LINE ITEMS — per-item stock detail (no DR No. here)
            ══════════════════════════════════════════════════════════════ --}}
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes"></i> Item Detail</h3>
                    <span style="font-size:12px;color:var(--text-muted)">Adjust quantities and unit costs. Stock levels update automatically.</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Unit</th>
                                <th>Stock No.</th>
                                <th style="text-align:right">Original Qty</th>
                                <th style="width:120px">Qty Delivered <span style="color:red">*</span></th>
                                <th style="width:120px">Unit Cost (₱) <span style="color:red">*</span></th>
                                <th style="text-align:right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($delivery->items as $idx => $di)
                            <input type="hidden" name="items[{{ $idx }}][di_id]" value="{{ $di->id }}">
                            <tr>
                                <td>
                                    <strong>{{ $di->item->description ?? '-' }}</strong>
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        Current stock: <strong>{{ number_format($di->item->quantity ?? 0, 2) }}</strong>
                                    </div>
                                </td>
                                <td>{{ $di->item->unit ?? '-' }}</td>
                                <td>
                                    @if($di->item?->stock_number)
                                        <code style="font-size:11px">{{ $di->item->stock_number }}</code>
                                    @else
                                        <span style="color:var(--text-muted)">—</span>
                                    @endif
                                </td>
                                <td style="text-align:right">
                                    <span class="badge badge-secondary">{{ number_format($di->quantity_delivered, 2) }}</span>
                                </td>
                                <td>
                                    <input type="number"
                                           name="items[{{ $idx }}][quantity_delivered]"
                                           id="qty-{{ $idx }}"
                                           class="form-control qty-delivered-input"
                                           value="{{ old("items.{$idx}.quantity_delivered", $di->quantity_delivered) }}"
                                           min="0" step="0.01" required
                                           oninput="recalcRow({{ $idx }})">
                                    @error("items.{$idx}.quantity_delivered")
                                        <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td>
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
                                </td>
                                <td style="text-align:right">
                                    <span id="total-{{ $idx }}" style="font-weight:600">
                                        ₱{{ number_format($di->quantity_delivered * $di->unit_cost, 2) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="background:#f7fafc;font-weight:700">
                                <td colspan="6" style="text-align:right;padding:10px 14px">Grand Total:</td>
                                <td style="text-align:right;padding:10px 14px" id="grand-total">
                                    ₱{{ number_format($delivery->items->sum(fn($di) => $di->quantity_delivered * $di->unit_cost), 2) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div>
            <div class="card" style="position:sticky;top:80px">
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

function recalcRow(idx) {
    const qty  = parseFloat(document.getElementById('qty-'  + idx)?.value) || 0;
    const cost = parseFloat(document.getElementById('cost-' + idx)?.value) || 0;
    const el   = document.getElementById('total-' + idx);
    if (el) {
        el.textContent = '₱\u00a0' + (qty * cost).toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }
    recalcGrand();
}

function recalcGrand() {
    let grand = 0;
    document.querySelectorAll('[id^="total-"]').forEach(el => {
        grand += parseFloat(el.textContent.replace(/[^\d.]/g, '')) || 0;
    });
    const el = document.getElementById('grand-total');
    if (el) {
        el.textContent = '₱\u00a0' + grand.toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateProgress();
});
</script>
@endpush
@endsection
