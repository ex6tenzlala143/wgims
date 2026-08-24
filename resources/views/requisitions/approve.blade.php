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

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    @if($requisition->status === 'partially_approved')
        <strong>Partially fulfilled.</strong>
        Some items were previously issued. For each line below, choose the warehouse and exact stock
        record to issue from now — a line may be issued in parts from different warehouses.
    @else
        <strong>Review the requested quantities.</strong>
        Choose the warehouse and exact stock record (item + unit cost) to issue each line from.
        Issuance is deducted only from that record — it will never fall back to another
        unit-cost/FIFO record, and each dispatch keeps its own warehouse and DR Number.
    @endif
</div>

<form action="{{ route('requisitions.process_approval', $requisition->id) }}" method="POST" id="approval-form">
@csrf
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header">
                <h3>Items to Issue</h3>
                <span style="font-size:12px;color:var(--text-muted);font-weight:400">
                    Each dispatch is recorded with its own warehouse, stock record, DR Number and costs.
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
                                    <strong style="color:var(--warning)">{{ number_format($outstanding, 2) }}</strong>
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
                                (DR# {{ $di->dr_number ?? '—' }})
                                @if($di->expiration_date) · Exp. {{ $di->expiration_date->format('M d, Y') }} @endif
                                · {{ $di->created_at?->format('M d, Y') }}
                            </span>
                        @endforeach
                    </div>
                    @endif

                    @if(!$isDone)
                    <div class="form-section-label"><i class="fas fa-truck-fast"></i> Dispatch from (this issuance)</div>
                    
                    <!-- Warehouse dropdown - full width row -->
                    <div class="form-group">
                        <label class="form-label">Warehouse <span class="req">*</span></label>
                        <select name="items[{{ $ri->id }}][warehouse_id]"
                                id="wh-select-{{ $ri->id }}"
                                class="form-control ris-wh-select {{ $errors->has('items.' . $ri->id . '.warehouse_id') ? 'is-invalid' : '' }}"
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
                    
                    <!-- Stock Record dropdown - full width row below warehouse -->
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

                    <!-- Hidden fields for unit cost, ENGAS cost, and expiration (auto-populated from selected item) -->
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
                        <span style="color:var(--success)">{{ number_format($totalIssSidebar, 2) }} issued</span>
                        <span style="color:var(--warning)">{{ number_format($totalRemSidebar, 2) }} remaining</span>
                    </div>
                </div>
                @endif
                <div style="margin-bottom:20px"></div>
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
const ITEMS_API_URL = '{{ route("requisitions.items_by_warehouse") }}';

function onWhChange(idx, restoreItemId) {
    const sel = document.getElementById('wh-select-' + idx);
    const itemSel = document.getElementById('item-select-' + idx);
    const stockEl = document.getElementById('stock-' + idx);
    if (!sel || !itemSel) { return; }

    // Reset the item dropdown
    itemSel.innerHTML = '<option value="">— Select Warehouse first —</option>';
    if (stockEl) { stockEl.textContent = ''; }
    resetDispatchFields(idx);

    if (!sel.value) { return; }

    itemSel.innerHTML = '<option value="">— Loading stock records… —</option>';
    itemSel.disabled = true;

    fetch(`${ITEMS_API_URL}?warehouse_id=${encodeURIComponent(sel.value)}&description=${encodeURIComponent(sel.dataset.description || '')}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
    .then(data => {
        const opts = data.map(i =>
            `<option value="${i.id}"
                data-unit-cost="${i.unit_cost || 0}"
                data-engas="${i.engas_unit_cost || ''}"
                data-expiry="${i.expiry_date || ''}"
                data-stock="${i.quantity}"
                data-sn="${i.stock_number || ''}"
                data-unit="${i.unit || ''}"
            >${i.display_text || i.description}</option>`
        ).join('');
        itemSel.innerHTML = '<option value="">— Select Stock Record —</option>' + opts;
        itemSel.disabled = false;

        // Sync with SearchableSelect component
        if (window.SS && typeof window.SS.sync === 'function') {
            window.SS.sync(itemSel);
        }

        // Restore the exact stock record chosen before a validation re-render,
        // so the quantity can be corrected without reselecting anything.
        if (restoreItemId) {
            const found = Array.from(itemSel.options).find(o => o.value === String(restoreItemId));
            if (found) {
                itemSel.value = String(restoreItemId);
                fillDispatchItem(itemSel, idx);
            }
        }
    })
    .catch(() => {
        itemSel.innerHTML = '<option value="">— Failed to load records —</option>';
        itemSel.disabled = false;
    });
}

function resetDispatchFields(idx) {
    ['unit-cost', 'engas-cost', 'expiry-date'].forEach(id => {
        const el = document.getElementById(id + '-' + idx);
        if (el) { el.value = ''; }
    });
}

function fillDispatchItem(sel, idx) {
    const opt = sel.options[sel.selectedIndex];

    if (!opt.value) {
        resetDispatchFields(idx);
        return;
    }

    const cost = document.getElementById('unit-cost-' + idx);
    if (cost) { cost.value = opt.dataset.unitCost || ''; }

    const engas = document.getElementById('engas-cost-' + idx);
    if (engas) { engas.value = opt.dataset.engas || ''; }

    const exp = document.getElementById('expiry-date-' + idx);
    if (exp) { exp.value = opt.dataset.expiry || ''; }

    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) { qtyInput.max = opt.dataset.stock || ''; }
    
    checkDispatch(idx);
}

function checkDispatch(idx) {
    const qtyInput = document.getElementById('qty-' + idx);
    const drInput  = document.getElementById('dr-number-' + idx);
    if (!qtyInput) { return; }

    const qty = parseFloat(qtyInput.value) || 0;
    if (drInput) {
        // DR# is only required when actually dispatching a quantity
        if (qty > 0) { drInput.setAttribute('required', ''); }
        else         { drInput.removeAttribute('required'); }
    }
}

// On a re-render after a server-side validation error, the warehouse selects
// are pre-selected from old() but the stock record dropdowns start empty
// (they are only filled by the onchange handler). Auto-load them and restore
// the exact stock record chosen before, so the quantity can be corrected
// without reselecting anything.
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[id^="wh-select-"]').forEach(function(sel) {
        if (!sel.value) { return; }
        const idx = sel.id.replace('wh-select-', '');
        const itemSel = document.getElementById('item-select-' + idx);
        const restoreItemId = itemSel ? itemSel.dataset.restoreId : '';
        onWhChange(idx, restoreItemId || null);
    });
});

// Never allow a double-click to process the approval twice.
guardFormSubmit(document.getElementById('approval-form'));
</script>
@endpush
@endsection
