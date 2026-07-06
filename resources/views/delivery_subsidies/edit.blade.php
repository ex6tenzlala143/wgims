@extends('layouts.app')
@section('title', 'Edit Delivery/Subsidy')
@section('page-title', 'Edit Delivery/Subsidy')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Delivery/Subsidy #{{ $deliverySubsidy->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> / Edit</div>
    </div>
</div>

@if($deliverySubsidy->deliveries()->count() > 0)
<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <strong>This record has existing deliveries — changes will cascade automatically:</strong>
        <ul style="margin:8px 0 0 18px;padding:0;font-size:13px">
            <li><strong>RIS No.</strong> — updates the RIS number on all linked item records</li>
            <li><strong>Unit Cost</strong> — cascades to the item's cost, stock card entries, requisition items, and stock transfer items</li>
            <li><strong>Quantity / Item</strong> — updates the ordered quantity on existing line items; line items that already have deliveries recorded cannot be deleted</li>
            <li><strong>Supplier / Warehouse / Date / Remarks</strong> — updated on the header only</li>
        </ul>
    </div>
</div>
@endif

<form action="{{ route('delivery_subsidies.update', $deliverySubsidy->id) }}" method="POST" id="po-form">
@csrf @method('PUT')
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3>Delivery/Subsidy Header</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Date <span style="color:red">*</span></label>
                        <input type="date" name="date" class="form-control" value="{{ old('date', $deliverySubsidy->date?->format('Y-m-d')) }}" required>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Supplier/Subsidy <span style="color:red">*</span></label>
                        <select name="supplier_id" class="form-control" required>
                            @foreach($suppliers as $s)
                            <option value="{{ $s->id }}" {{ old('supplier_id', $deliverySubsidy->supplier_id)==$s->id?'selected':'' }}>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Warehouse</label>
                        <select name="warehouse_id" class="form-control" required>
                            @foreach($warehouses as $c)
                            <option value="{{ $c->id }}" {{ old('warehouse_id', $deliverySubsidy->warehouse_id)==$c->id?'selected':'' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">RIS No. <span style="color:red">*</span></label>
                        <input type="text" name="ris_number" class="form-control" value="{{ old('ris_number', $deliverySubsidy->ris_number) }}" placeholder="e.g. RIS-2026-001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Place of Delivery</label>
                        <input type="text" name="place_of_delivery" class="form-control" value="{{ old('place_of_delivery', $deliverySubsidy->place_of_delivery) }}">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Status <span style="color:red">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="pending" {{ old('status', $deliverySubsidy->status)=='pending'?'selected':'' }}>Pending</option>
                            <option value="partial" {{ old('status', $deliverySubsidy->status)=='partial'?'selected':'' }}>Partial Delivery</option>
                            <option value="fully_delivered" {{ old('status', $deliverySubsidy->status)=='fully_delivered'?'selected':'' }}>Fully Delivered</option>
                            <option value="cancelled" {{ old('status', $deliverySubsidy->status)=='cancelled'?'selected':'' }}>Cancelled</option>
                        </select>
                        @error('status')
                            <div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>
                        @enderror
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                            <i class="fas fa-info-circle"></i>
                            <strong>Fully Delivered</strong> can only be set when the quantity delivered matches the quantity ordered for every line item.
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Qty Requested <span style="color:red">*</span>
                            <span style="font-size:11px;color:var(--text-muted);font-weight:normal">— total target for this request</span>
                        </label>
                        <input type="number" name="quantity_requested" class="form-control"
                               min="0.01" step="0.01"
                               value="{{ old('quantity_requested', $deliverySubsidy->quantity_requested) }}" required>
                        @error('quantity_requested')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2">{{ old('remarks', $deliverySubsidy->remarks) }}</textarea>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Line Items</h3>
                <button type="button" class="btn btn-sm btn-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-wrapper">
                    <table class="line-items-table" id="items-table">
                        <thead>
                            <tr><th>Item</th><th>Unit</th><th>Qty</th><th>Unit Cost (₱)</th><th>Amount (₱)</th><th></th></tr>
                        </thead>
                        <tbody id="items-body">
                            @foreach($deliverySubsidy->items as $idx => $poi)
                            <tr id="row-{{ $idx }}">
                                <input type="hidden" name="items[{{ $idx }}][dsi_id]" value="{{ $poi->id }}">
                                <td>
                                    <select name="items[{{ $idx }}][item_id]" class="item-select" onchange="fillUnit(this, {{ $idx }})" required>
                                        <option value="">— Select Item —</option>
                                        @foreach($items as $item)
                                        <option value="{{ $item->id }}" data-unit="{{ $item->unit }}" {{ $poi->item_id == $item->id ? 'selected' : '' }}>{{ $item->description }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" id="unit-{{ $idx }}" class="form-control" readonly value="{{ $poi->item->unit ?? '' }}" style="background:#f7fafc"></td>
                                <td><input type="number" name="items[{{ $idx }}][quantity]" class="qty-input" min="0.01" step="0.01" value="{{ $poi->quantity }}" onchange="calcRow({{ $idx }})" required></td>
                                <td><input type="number" name="items[{{ $idx }}][unit_cost]" class="cost-input" min="0" step="0.01" value="{{ $poi->unit_cost }}" onchange="calcRow({{ $idx }})" required></td>
                                <td><input type="text" id="amount-{{ $idx }}" class="form-control" readonly value="{{ number_format($poi->amount, 2) }}" style="background:#f7fafc;text-align:right"></td>
                                <td><button type="button" class="remove-row" onclick="removeRow('row-{{ $idx }}')"><i class="fas fa-times"></i></button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Order Summary</h3></div>
            <div class="card-body">
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Total Amount</div>
                <div style="font-size:28px;font-weight:800;color:var(--primary)" id="grand-total">₱{{ number_format($deliverySubsidy->total_amount, 2) }}</div>
                <hr style="margin:16px 0;border-color:var(--border)">
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center"><i class="fas fa-save"></i> Update PO</button>
                <a href="{{ route('delivery_subsidies.show', $deliverySubsidy->id) }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </div>
    </div>
</div>
</form>

@push('scripts')
<script>
let rowCount = {{ $deliverySubsidy->items->count() }};
@php
    $itemsJson = $items->map(function($i) {
        return [
            'id'           => $i->id,
            'unit'         => $i->unit,
            'description'  => $i->description,
            'stock_number' => $i->stock_number,
        ];
    })->values()->toJson();
@endphp
const allItems = {!! $itemsJson !!};

function addRow() {
    const idx = rowCount++;
    const tbody = document.getElementById('items-body');
    const tr = document.createElement('tr');
    tr.id = 'row-' + idx;
    tr.innerHTML = `
        <td><select name="items[${idx}][item_id]" class="item-select" onchange="fillUnit(this, ${idx})" required>
            <option value="">— Select Item —</option>
            ${allItems.map(i => `<option value="${i.id}" data-unit="${i.unit}">${i.description}</option>`).join('')}
        </select></td>
        <td><input type="text" id="unit-${idx}" class="form-control" readonly style="background:#f7fafc"></td>
        <td><input type="number" name="items[${idx}][quantity]" class="qty-input" min="0.01" step="0.01" onchange="calcRow(${idx})" required></td>
        <td><input type="number" name="items[${idx}][unit_cost]" class="cost-input" min="0" step="0.01" onchange="calcRow(${idx})" required></td>
        <td><input type="text" id="amount-${idx}" class="form-control" readonly style="background:#f7fafc;text-align:right"></td>
        <td><button type="button" class="remove-row" onclick="removeRow('row-${idx}')"><i class="fas fa-times"></i></button></td>
    `;
    tbody.appendChild(tr);
}

function fillUnit(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    const u = document.getElementById('unit-' + idx);
    if (u) u.value = opt.dataset.unit || '';
}

function calcRow(idx) {
    const row = document.getElementById('row-' + idx);
    if (!row) return;
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const cost = parseFloat(row.querySelector('.cost-input').value) || 0;
    const amtField = document.getElementById('amount-' + idx);
    if (amtField) amtField.value = (qty * cost).toFixed(2);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    document.querySelectorAll('[id^="amount-"]').forEach(el => { total += parseFloat(el.value) || 0; });
    document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
}

function removeRow(id) {
    if (document.querySelectorAll('#items-body tr').length > 1) {
        document.getElementById(id)?.remove();
        calcTotal();
    }
}
calcTotal();
</script>
@endpush
@endsection
