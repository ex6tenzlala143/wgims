@extends('layouts.app')
@section('title', 'Edit RIS')
@section('page-title', 'Edit Requisition')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Requisition {{ $requisition->ris_code ?? $requisition->ris_id }} <span style="font-weight:400;color:var(--text-muted);font-size:16px">/ {{ $requisition->ris_number }}</span></h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Edit</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">RIS ID: <span style="font-family:monospace;color:var(--primary);font-weight:600">{{ $requisition->ris_code ?? $requisition->ris_id }}</span> &nbsp;·&nbsp; RIS No.: <strong>{{ $requisition->ris_number }}</strong></div>
    </div>
</div>

<form action="{{ route('requisitions.update', $requisition->id) }}" method="POST" id="edit-ris-form">
@csrf @method('PUT')
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-clipboard-list" style="color:var(--primary)"></i> RIS Header</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">RIS ID <span style="font-size:10px;color:var(--text-muted)">system, not editable</span></label>
                        <input type="text" class="form-control" value="{{ $requisition->ris_code ?? $requisition->ris_id }}" readonly style="background:var(--surface-soft);font-family:monospace;color:var(--primary);font-weight:700">
                    </div>
                    <div class="form-group">
                        <label class="form-label">RIS No. <span style="color:red">*</span></label>
                        <input type="text" name="ris_number" class="form-control {{ $errors->has('ris_number') ? 'is-invalid' : '' }}" value="{{ old('ris_number', $requisition->ris_number) }}" required>
                        @error('ris_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Date Requested <span style="color:red">*</span></label>
                        <input type="date" name="date_requested" class="form-control" value="{{ old('date_requested', $requisition->date_requested->format('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Entity Name</label>
                        <input type="text" name="entity_name" class="form-control" value="{{ old('entity_name', $requisition->entity_name) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Fund Cluster</label>
                        <input type="text" name="fund_cluster" class="form-control" value="{{ old('fund_cluster', $requisition->fund_cluster) }}">
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

                <div class="form-section-label">
                    <i class="fas fa-city"></i> Requesting LGU
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Province</label>
                        <input type="text" name="province" class="form-control" value="{{ old('province', $requisition->province) }}" placeholder="e.g. Cebu" style="width:100%">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Municipality</label>
                        <input type="text" name="municipality" class="form-control" value="{{ old('municipality', $requisition->municipality) }}" placeholder="e.g. Lapu-Lapu City" style="width:100%">
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
                <span style="font-size:12px;color:var(--text-muted);font-weight:400">
                    The warehouse and exact stock record are chosen by the warehouse when items are issued.
                    Lines that have already been dispatched are locked — their quantity cannot go below what
                    was issued, and their item cannot be changed.
                </span>
                <button type="button" class="btn btn-sm btn-primary" onclick="addRisRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>

            <div class="card-body">
                <div id="ris-items" style="display:grid;gap:20px">
                    @foreach($requisition->items as $idx => $ri)
                    @php
                        $locked = $ri->quantity_issued > 0 || $ri->dispatchItems->isNotEmpty();
                    @endphp
                    <div class="ris-item-card" id="ris-card-{{ $idx }}">
                        <div class="ris-item-head">
                            <div>
                                <i class="fas fa-box" style="color:var(--primary)"></i>
                                Item <span class="ris-item-num">{{ $loop->iteration }}</span>
                                @if($ri->quantity_issued > 0)
                                    <span class="badge badge-success" style="font-size:10px;margin-left:6px">
                                        <i class="fas fa-check"></i> {{ number_format($ri->quantity_issued) }} issued
                                    </span>
                                @endif
                            </div>
                            <button type="button" class="remove-row" title="Remove item" onclick="removeRisRow('ris-card-{{ $idx }}')"><i class="fas fa-times"></i></button>
                        </div>

                        <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $ri->id }}">
                        @if($locked)
                            {{-- Disabled inputs are not submitted, so mirror the values --}}
                            <input type="hidden" name="items[{{ $idx }}][catalog_item_id]" value="{{ $ri->catalog_item_id }}">
                            <input type="hidden" name="items[{{ $idx }}][quantity_requested]" value="{{ $ri->quantity_requested }}">
                        @endif

                        <div class="form-group">
                            <label class="form-label">Item Description <span class="req">*</span></label>
                            <select name="items[{{ $idx }}][catalog_item_id]"
                                    class="ris-item-select form-control"
                                    id="item-select-{{ $idx }}"
                                    data-selected="{{ $ri->catalog_item_id }}"
                                    onchange="fillRisItem(this, {{ $idx }})"
                                    {{ $locked ? 'disabled' : '' }} required>
                                <option value="{{ $ri->catalog_item_id }}" selected>{{ $ri->description ?? ($ri->item?->description ?? '—') }}</option>
                            </select>
                            @if($locked)
                                <small style="color:var(--text-muted);font-size:11px">Item is locked because this line has already been dispatched.</small>
                            @endif
                        </div>

                        <div class="form-row cols-2">
                            <div class="form-group">
                                <label class="form-label">Requested Quantity <span class="req">*</span></label>
                                <input type="number"
                                       name="items[{{ $idx }}][quantity_requested]"
                                       id="qty-{{ $idx }}"
                                       class="form-control"
                                       min="{{ $locked ? number_format($ri->quantity_issued, 0, '.', '') : '1' }}" step="1"
                                       value="{{ $ri->quantity_requested }}"
                                       {{ $locked ? 'disabled' : '' }} required>
                                @if($locked)
                                    <small style="color:var(--text-muted);font-size:11px">
                                        Cannot be reduced below the {{ number_format($ri->quantity_issued) }} already issued.
                                    </small>
                                @endif
                                @error('items.' . $idx . '.quantity_requested')
                                <small style="color:var(--danger);font-size:11px;display:block;margin-top:4px">
                                    <i class="fas fa-exclamation-triangle"></i> {{ $message }}
                                </small>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="form-label">Account Code</label>
                                <input type="text" name="items[{{ $idx }}][account_code]"
                                       id="account-code-{{ $idx }}" class="form-control" readonly tabindex="-1"
                                       placeholder="Auto from Item Categories"
                                       value="{{ $ri->account_code ?? '' }}">
                            </div>
                        </div>

                        <div class="ris-item-meta">
                            <span>Unit <strong>{{ $ri->unit ?? ($ri->item?->unit ?? '—') }}</strong></span>
                            @if($ri->dispatchItems->isNotEmpty())
                                <span>Dispatched From <strong>{{ $ri->dispatchItems->pluck('item.warehouse.name')->unique()->filter()->implode(', ') ?: '—' }}</strong></span>
                            @endif
                        </div>
                    </div>
                    @endforeach
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
let risRowCount = {{ $requisition->items->count() }};
let rowState    = {};   // idx → { items, selectedItemId }

const ITEMS_API_URL = '{{ route("requisitions.description_items") }}';

function formatQty(value) {
    const n = parseFloat(value) || 0;
    return n.toLocaleString('en-PH', { maximumFractionDigits: 0 });
}

function buildOptionsFor(idx, selectedId) {
    return (rowState[idx]?.items || []).map(i => {
        const sel = String(i.id) === String(selectedId) ? ' selected' : '';
        const acctInfo  = i.account_code ? ` · <code>${i.account_code}</code>` : '';
        const stockInfo = i.total_stock > 0
            ? ` · ${formatQty(i.total_stock)} ${i.unit || ''} available`
            : '';
        return `<option value="${i.id}"
            data-unit="${i.unit}"
            data-total-stock="${i.total_stock}"
            data-account-code="${i.account_code || ''}"
            ${sel}>${i.name}${acctInfo}${stockInfo}</option>`;
    }).join('');
}

/** Load the description-level item list. */
function loadRowItems(idx, selectItemId) {
    const sel  = document.getElementById('item-select-' + idx);
    if (!sel) { return; }

    sel.innerHTML = '<option value="">— Loading items… —</option>';
    sel.disabled  = true;

    fetch(ITEMS_API_URL, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
    .then(data => {
        rowState[idx].items = data;
        sel.innerHTML = '<option value="">— Select Item —</option>' + buildOptionsFor(idx, selectItemId);
        sel.disabled  = false;
        if (selectItemId) {
            sel.value = String(selectItemId);
            if (!sel.value) {
                // Fall back to keeping the original option if the item is gone
                sel.disabled = true;
            }
        }
    })
    .catch(() => {
        sel.innerHTML = '<option value="">— Failed to load items —</option>';
        sel.disabled  = false;
    });
}

// ─── Row management ───────────────────────────────────────────────────────────
function renumberItems() {
    document.querySelectorAll('#ris-items .ris-item-card .ris-item-num').forEach((el, i) => {
        el.textContent = i + 1;
    });
}

function addRisRow() {
    const idx = risRowCount++;
    const container = document.getElementById('ris-items');
    const card      = document.createElement('div');
    card.className = 'ris-item-card';
    card.id = 'ris-card-' + idx;

    card.innerHTML = `
        <div class="ris-item-head">
            <div><i class="fas fa-box" style="color:var(--primary)"></i> Item <span class="ris-item-num">${idx + 1}</span></div>
            <button type="button" class="remove-row" title="Remove item" onclick="removeRisRow('ris-card-${idx}')">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="form-group">
            <label class="form-label">Item Description <span class="req">*</span></label>
            <select name="items[${idx}][catalog_item_id]" class="ris-item-select form-control"
                    id="item-select-${idx}" onchange="fillRisItem(this, ${idx})" required>
                <option value="">— Select Item —</option>
            </select>
        </div>

        <div class="form-row cols-2">
            <div class="form-group">
                <label class="form-label">Requested Quantity <span class="req">*</span></label>
                <input type="number" name="items[${idx}][quantity_requested]"
                       id="qty-${idx}" class="form-control" min="1" step="1" required
                       oninput="checkStock(${idx})">
            </div>
            <div class="form-group">
                <label class="form-label">Account Code</label>
                <input type="text" name="items[${idx}][account_code]"
                       id="account-code-${idx}" class="form-control" readonly tabindex="-1"
                       placeholder="Auto from Item Categories">
            </div>
        </div>

        <div class="ris-item-meta">
            <span>Unit <strong id="unit-${idx}">—</strong></span>
            <span>Available <strong id="stock-${idx}">—</strong></span>
        </div>
    `;
    container.appendChild(card);

    rowState[idx] = { items: [], selectedItemId: null };
    loadRowItems(idx);
}

function removeRisRow(id) {
    if (document.querySelectorAll('#ris-items .ris-item-card').length > 1) {
        document.getElementById(id)?.remove();
        renumberItems();
    }
}

function fillRisItem(sel, idx) {
    const opt    = sel.options[sel.selectedIndex];
    const itemId = opt.value;

    rowState[idx].selectedItemId = itemId;

    document.getElementById('unit-' + idx).textContent = opt.dataset.unit || '—';

    const acct = document.getElementById('account-code-' + idx);
    if (acct) { acct.value = opt.dataset.accountCode || ''; }

    const stockEl = document.getElementById('stock-' + idx);
    const stock   = parseFloat(opt.dataset.totalStock || 0);
    stockEl.textContent = stock > 0 ? formatQty(stock) : '—';
    stockEl.style.color = stock > 0 ? 'var(--success)' : 'var(--danger)';

    const qtyInput = document.getElementById('qty-' + idx);
    if (qtyInput) { qtyInput.max = stock; }
    checkStock(idx);
}

function checkStock(idx) {
    const qtyInput = document.getElementById('qty-' + idx);
    if (!qtyInput) { return; }

    const opt = document.getElementById('item-select-' + idx)?.selectedOptions?.[0];
    const stock = opt ? parseFloat(opt.dataset.totalStock || 0) : 0;
    const qty   = parseFloat(qtyInput.value) || 0;

    qtyInput.classList.toggle('is-invalid', stock > 0 && qty > stock);
}

// ─── Bootstrap: wire up the server-rendered item cards ────────────────────────
document.querySelectorAll('#ris-items .ris-item-select').forEach(sel => {
    const idx    = sel.id.replace('item-select-', '');
    const itemId = sel.dataset.selected;

    rowState[idx] = { items: [], selectedItemId: itemId };

    if (sel.disabled) {
        // Locked (already dispatched) lines keep their server-rendered option.
        rowState[idx].items = [{
            id: itemId,
            description: sel.options[sel.selectedIndex]?.text,
            unit: '',
            total_stock: 0,
            record_count: 1,
        }];
    } else {
        loadRowItems(idx, itemId);
    }
});

// Never allow a double-click to save the same RIS edit twice.
guardFormSubmit(document.getElementById('edit-ris-form'));
</script>
@endpush
@endsection
