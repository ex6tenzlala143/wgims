@extends('layouts.app')

@section('title', 'New Stock Transfer')
@section('page-title', 'New Stock Transfer')

@section('content')
<div class="page-header">
    <div>
        <h1>New Stock Transfer</h1>
        <div class="breadcrumb">
            <a href="{{ route('transfers.index') }}">Stock Transfers</a> › Create
        </div>
    </div>
</div>

<form method="POST" action="{{ route('transfers.store') }}" id="transfer-form">
    @csrf

    <div class="form-row cols-2" style="margin-bottom:16px">
        {{-- Transfer Header --}}
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-info-circle"></i> Transfer Details</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Source Warehouse <span style="color:var(--danger)">*</span></label>
                        @if($sourceWarehouse)
                            {{-- Non-admin: fixed to their warehouse --}}
                            <input type="text" class="form-control" value="{{ $sourceWarehouse->name }}" readonly>
                            <input type="hidden" name="from_warehouse_id" value="{{ $sourceWarehouse->id }}">
                        @else
                            <select name="from_warehouse_id" id="from_warehouse_id" class="form-control {{ $errors->has('from_warehouse_id') ? 'is-invalid' : '' }}" required onchange="loadSourceItems()">
                                <option value="">— Select Source —</option>
                                @foreach($warehouses as $wh)
                                <option value="{{ $wh->id }}" {{ old('from_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @error('from_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Destination Warehouse <span style="color:var(--danger)">*</span></label>
                        <select name="to_warehouse_id" id="to_warehouse_id" class="form-control {{ $errors->has('to_warehouse_id') ? 'is-invalid' : '' }}" required>
                            <option value="">— Select Destination —</option>
                            @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}" {{ old('to_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                            @endforeach
                        </select>
                        @error('to_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Transfer Date <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="transfer_date" class="form-control {{ $errors->has('transfer_date') ? 'is-invalid' : '' }}"
                               value="{{ old('transfer_date', date('Y-m-d')) }}" required>
                        @error('transfer_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes about this transfer...">{{ old('remarks') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- Info panel --}}
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-lightbulb"></i> Pre-Positioning Guide</h3></div>
            <div class="card-body">
                <div class="alert alert-info" style="margin-bottom:12px">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Pre-Positioning</strong> distributes inventory from a central warehouse to strategic field locations, ensuring faster response when goods are needed for distribution.
                    </div>
                </div>
                <ul style="font-size:13px;color:var(--text-muted);padding-left:18px;line-height:1.8">
                    <li>Select the <strong>source</strong> warehouse where stock currently resides.</li>
                    <li>Select the <strong>destination</strong> warehouse closer to the target area.</li>
                    <li>Add one or more items and specify the quantity to transfer.</li>
                    <li>Quantities cannot exceed available stock at the source.</li>
                    <li>Stock cards at both locations are updated automatically.</li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Line Items --}}
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Items to Transfer</h3>
            <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">
                <i class="fas fa-plus"></i> Add Item
            </button>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrapper">
                <table class="line-items-table" id="items-table">
                    <thead>
                        <tr>
                            <th style="width:35%">Item</th>
                            <th style="width:12%">Unit</th>
                            <th style="width:12%">Available</th>
                            <th style="width:14%">Qty to Transfer</th>
                            <th style="width:14%">Unit Cost</th>
                            <th style="width:10%">Total</th>
                            <th style="width:3%"></th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        {{-- Rows injected by JS --}}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right;font-weight:600;padding:10px 14px">Grand Total:</td>
                            <td style="font-weight:700;padding:10px 14px" id="grand-total">₱ 0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end;gap:10px">
            <a href="{{ route('transfers.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" id="submit-btn">
                <i class="fas fa-save"></i> Save Transfer
            </button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
// Items available at the source warehouse (pre-loaded for non-admin users)
let sourceItems = @json($sourceItems ?? []);
let rowIndex = 0;

// For admin users: fetch items when source warehouse changes
function loadSourceItems() {
    const warehouseId = document.getElementById('from_warehouse_id')?.value;
    if (!warehouseId) { sourceItems = []; return; }

    fetch(`{{ route('transfers.items_for_warehouse') }}?warehouse_id=${warehouseId}`)
        .then(r => r.json())
        .then(data => {
            sourceItems = data;
            // Refresh all existing row selects
            document.querySelectorAll('.item-select').forEach(sel => {
                const currentVal = sel.value;
                populateItemSelect(sel);
                sel.value = currentVal;
            });
        });
}

function populateItemSelect(selectEl) {
    const currentVal = selectEl.value;
    selectEl.innerHTML = '<option value="">— Select Item —</option>';
    sourceItems.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = `${item.description} (${item.unit}) — ${item.stock_number || 'No SN'} | Qty: ${item.quantity}`;
        opt.dataset.unit = item.unit;
        opt.dataset.unitCost = item.unit_cost;
        opt.dataset.available = item.quantity;
        selectEl.appendChild(opt);
    });
    if (currentVal) selectEl.value = currentVal;
}

function addRow() {
    const tbody = document.getElementById('items-body');
    const idx = rowIndex++;
    const tr = document.createElement('tr');
    tr.id = `row-${idx}`;
    tr.innerHTML = `
        <td>
            <select name="items[${idx}][item_id]" class="item-select" required onchange="onItemChange(this, ${idx})">
                <option value="">— Select Item —</option>
            </select>
        </td>
        <td><input type="text" id="unit-${idx}" readonly placeholder="—"></td>
        <td><input type="text" id="avail-${idx}" readonly placeholder="—"></td>
        <td>
            <input type="number" name="items[${idx}][quantity]" id="qty-${idx}"
                   step="0.0001" min="0.0001" placeholder="0"
                   required oninput="recalcRow(${idx})">
        </td>
        <td>
            <input type="number" name="items[${idx}][unit_cost]" id="cost-${idx}"
                   step="0.01" min="0.01" placeholder="0.00"
                   required oninput="recalcRow(${idx})">
        </td>
        <td><input type="text" id="total-${idx}" readonly placeholder="0.00"></td>
        <td>
            <button type="button" class="remove-row" onclick="removeRow(${idx})" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    populateItemSelect(tr.querySelector('.item-select'));
}

function onItemChange(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById(`unit-${idx}`).value  = opt.dataset.unit || '';
    document.getElementById(`avail-${idx}`).value = opt.dataset.available || '';
    document.getElementById(`cost-${idx}`).value  = opt.dataset.unitCost || '';
    recalcRow(idx);
}

function recalcRow(idx) {
    const qty  = parseFloat(document.getElementById(`qty-${idx}`)?.value) || 0;
    const cost = parseFloat(document.getElementById(`cost-${idx}`)?.value) || 0;
    const total = qty * cost;
    const totalEl = document.getElementById(`total-${idx}`);
    if (totalEl) totalEl.value = '₱ ' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    recalcGrandTotal();
}

function recalcGrandTotal() {
    let grand = 0;
    document.querySelectorAll('[id^="total-"]').forEach(el => {
        grand += parseFloat(el.value.replace(/[^0-9.]/g, '')) || 0;
    });
    document.getElementById('grand-total').textContent = '₱ ' + grand.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function removeRow(idx) {
    const row = document.getElementById(`row-${idx}`);
    if (row) row.remove();
    recalcGrandTotal();
}

// Validate qty vs available before submit
document.getElementById('transfer-form').addEventListener('submit', function(e) {
    let valid = true;
    document.querySelectorAll('[id^="qty-"]').forEach(qtyEl => {
        const idx = qtyEl.id.replace('qty-', '');
        const avail = parseFloat(document.getElementById(`avail-${idx}`)?.value) || 0;
        const qty   = parseFloat(qtyEl.value) || 0;
        if (qty > avail) {
            alert(`Quantity exceeds available stock (${avail}) for one of the items.`);
            valid = false;
        }
    });
    if (!valid) e.preventDefault();
});

// Add first row on load
addRow();

// If admin, trigger load on page load if a warehouse is already selected (old input)
@if(auth()->user()->hasAdminAccess())
const fromWh = document.getElementById('from_warehouse_id');
if (fromWh && fromWh.value) loadSourceItems();
@endif
</script>
@endpush
