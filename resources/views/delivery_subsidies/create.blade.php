@extends('layouts.app')
@section('title', 'New Delivery/Subsidy')
@section('page-title', 'New Delivery/Subsidy')

@section('content')
<div class="page-header">
    <div>
        <h1>New Delivery/Subsidy</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> / New</div>
    </div>
</div>

<form action="{{ route('delivery_subsidies.store') }}" method="POST" id="po-form">
@csrf
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <!-- Header -->
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Delivery/Subsidy Header</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Date <span style="color:red">*</span></label>
                        <input type="date" name="date" class="form-control" value="{{ old('date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supplier/Subsidy <span style="color:red">*</span></label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">— Select Supplier/Subsidy —</option>
                            @foreach($suppliers as $s)
                            <option value="{{ $s->id }}" {{ old('supplier_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if(auth()->user()->hasAdminAccess())
                    <div class="form-group">
                        <label class="form-label">Warehouse <span style="color:red">*</span></label>
                        <select name="warehouse_id" id="warehouse_id" class="form-control" required>
                            <option value="">— Select Warehouse —</option>
                            @foreach($warehouses as $c)
                            <option value="{{ $c->id }}" {{ old('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @else
                    @php
                        $primaryWarehouseId = auth()->user()->warehouse_id
                            ?? auth()->user()->warehouses()->value('warehouses.id');
                    @endphp
                    <input type="hidden" name="warehouse_id" value="{{ $primaryWarehouseId }}">
                    @endif
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">RIS No. <span style="color:red">*</span></label>
                        <input type="text" name="ris_number" class="form-control" value="{{ old('ris_number') }}" placeholder="e.g. RIS-2026-001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Place of Delivery</label>
                        <input type="text" name="place_of_delivery" class="form-control" value="{{ old('place_of_delivery') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2">{{ old('remarks') }}</textarea>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list" style="color:var(--primary)"></i> Line Items</h3>
                <button type="button" class="btn btn-sm btn-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-wrapper">
                    <table class="line-items-table" id="items-table">
                        <thead>
                            <tr>
                                <th style="width:22%">Description <span style="color:red">*</span></th>
                                <th style="width:9%">Unit <span style="color:red">*</span></th>
                                <th style="width:10%">Category <span style="color:red">*</span></th>
                                <th style="width:7%">Quantity Requested</th>
                                <th style="width:10%">Unit Cost (₱)</th>
                                <th style="width:10%">Expiry Date</th>
                                <th style="width:8%">Amount (₱)</th>
                                <th style="width:16%">Stock No.</th>
                                <th style="width:4%"></th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="row-0">
                                <td>
                                    <input type="text" name="items[0][description]"
                                        class="form-control desc-input" list="items-datalist-0"
                                        placeholder="Type item description..." required
                                        oninput="onDescInput(this, 0)" autocomplete="off"
                                        style="color:#000;background:#fff">
                                    <datalist id="items-datalist-0">
                                        @foreach($items as $item)
                                        <option value="{{ $item->description }}"
                                            data-unit="{{ $item->unit }}"
                                            data-category="{{ $item->category }}"
                                            data-id="{{ $item->id }}"
                                            data-expiry="{{ $item->expiration_date ? $item->expiration_date->format('Y-m-d') : '' }}">
                                        @endforeach
                                    </datalist>
                                    <input type="hidden" name="items[0][item_id]" id="item-id-0">
                                </td>
                                <td>
                                    <select name="items[0][unit]" id="unit-0" class="form-control" required style="color:#000;background:#fff">
                                        <option value="">—</option>
                                        @foreach(App\Models\Item::UNITS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="items[0][category]" id="category-0" class="form-control" required style="color:#000;background:#fff">
                                        <option value="">—</option>
                                        @foreach(App\Models\Item::getCategories() as $key => $cat)
                                        <option value="{{ $key }}">{{ $cat['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" name="items[0][quantity]" class="qty-input form-control" min="0.01" step="0.01" oninput="calcRow(0)" required style="color:#000;background:#fff"></td>
                                <td><input type="number" name="items[0][unit_cost]" id="cost-0" class="cost-input form-control" min="0" step="0.01" oninput="calcRow(0); lookupStockCard(0)" required style="color:#000;background:#fff"></td>
                                <td><input type="date" name="items[0][expiration_date]" id="expiry-0" class="form-control" oninput="lookupStockCard(0)" style="font-size:12px;color:#000;background:#fff"></td>
                                <td><input type="text" id="amount-0" class="form-control" readonly style="background:#f7fafc;text-align:right"></td>
                                <td>
                                    <input type="text" id="sc-0" class="form-control sc-field" readonly style="font-family:monospace;font-size:11px" placeholder="—">
                                    <div id="sc-msg-0" style="font-size:10px;margin-top:2px"></div>
                                </td>
                                <td><button type="button" class="remove-row" onclick="removeRow('row-0')"><i class="fas fa-times"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Order Summary</h3></div>
            <div class="card-body">
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Subtotal</div>
                <div style="font-size:28px;font-weight:800;color:var(--primary)" id="grand-total">₱0.00</div>
                <hr style="margin:16px 0;border-color:var(--border)">
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                    <i class="fas fa-save"></i> Save Delivery/Subsidy
                </button>
                <a href="{{ route('delivery_subsidies.index') }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>
</div>
</form>
@endsection

@push('scripts')
<script>
let rowCount = 1;
@php
    $itemsJson = $items->map(function($i) {
        return [
            'id'          => $i->id,
            'description' => $i->description,
            'unit'        => $i->unit,
            'category'    => $i->category,
            'warehouse_id'   => $i->warehouse_id,
            'expiry'      => $i->expiration_date ? $i->expiration_date->format('Y-m-d') : '',
        ];
    })->values()->toJson();

    $unitOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::UNITS as $uKey => $uLabel) {
        $unitOptionsHtml .= '<option value="' . $uKey . '">' . $uLabel . '</option>';
    }

    $catOptionsHtml = '<option value="">—</option>';
    foreach (App\Models\Item::getCategories() as $cKey => $cCat) {
        $catOptionsHtml .= '<option value="' . $cKey . '">' . $cCat['label'] . '</option>';
    }
@endphp
const allItems    = {!! $itemsJson !!};
const unitOptions = {!! json_encode($unitOptionsHtml) !!};
const catOptions  = {!! json_encode($catOptionsHtml) !!};

function addRow() {
    const idx   = rowCount++;
    const tbody = document.getElementById('items-body');
    const tr    = document.createElement('tr');
    tr.id = 'row-' + idx;
    tr.innerHTML =
        '<td>' +
            '<input type="text" name="items[' + idx + '][description]" class="form-control desc-input" list="items-datalist-' + idx + '" placeholder="Type item description..." required oninput="onDescInput(this,' + idx + ')" autocomplete="off" style="color:#000;background:#fff">' +
            '<datalist id="items-datalist-' + idx + '">' +
                allItems.map(function(i) { return '<option value="' + i.description + '" data-unit="' + i.unit + '" data-category="' + i.category + '" data-id="' + i.id + '" data-expiry="' + i.expiry + '">'; }).join('') +
            '</datalist>' +
            '<input type="hidden" name="items[' + idx + '][item_id]" id="item-id-' + idx + '">' +
        '</td>' +
        '<td><select name="items[' + idx + '][unit]" id="unit-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + unitOptions + '</select></td>' +
        '<td><select name="items[' + idx + '][category]" id="category-' + idx + '" class="form-control" required style="color:#000;background:#fff">' + catOptions + '</select></td>' +
        '<td><input type="number" name="items[' + idx + '][quantity]" class="qty-input form-control" min="0.01" step="0.01" oninput="calcRow(' + idx + ')" required style="color:#000;background:#fff"></td>' +
        '<td><input type="number" name="items[' + idx + '][unit_cost]" id="cost-' + idx + '" class="cost-input form-control" min="0" step="0.01" oninput="calcRow(' + idx + '); lookupStockCard(' + idx + ')" required style="color:#000;background:#fff"></td>' +
        '<td><input type="date" name="items[' + idx + '][expiration_date]" id="expiry-' + idx + '" class="form-control" oninput="lookupStockCard(' + idx + ')" style="font-size:12px;color:#000;background:#fff"></td>' +
        '<td><input type="text" id="amount-' + idx + '" class="form-control" readonly style="background:#f7fafc;color:#718096;text-align:right"></td>' +
        '<td><input type="text" id="sc-' + idx + '" class="form-control" readonly style="font-family:monospace;font-size:11px;background:#f7fafc;color:#000" placeholder="—"><div id="sc-msg-' + idx + '" style="font-size:10px;margin-top:2px"></div></td>' +
        '<td><button type="button" class="remove-row" onclick="removeRow(\'row-' + idx + '\')"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
}

function onDescInput(input, idx) {
    var val   = input.value.trim().toLowerCase();
    var match = allItems.find(function(i) { return i.description.toLowerCase() === val; });
    if (match) {
        document.getElementById('item-id-' + idx).value = match.id;
        var unitSel = document.getElementById('unit-' + idx);
        var catSel  = document.getElementById('category-' + idx);
        var expiry  = document.getElementById('expiry-' + idx);
        if (unitSel) unitSel.value = match.unit;
        if (catSel)  catSel.value  = match.category;
        if (expiry && match.expiry) expiry.value = match.expiry;
        lookupStockCard(idx);
    } else {
        document.getElementById('item-id-' + idx).value = '';
    }
}

function calcRow(idx) {
    var row = document.getElementById('row-' + idx);
    if (!row) return;
    var qty  = parseFloat(row.querySelector('.qty-input').value) || 0;
    var cost = parseFloat(row.querySelector('.cost-input').value) || 0;
    var amtField = document.getElementById('amount-' + idx);
    if (amtField) amtField.value = (qty * cost).toFixed(2);
    calcTotal();
}

function calcTotal() {
    var total = 0;
    document.querySelectorAll('[id^="amount-"]').forEach(function(el) { total += parseFloat(el.value) || 0; });
    document.getElementById('grand-total').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
}

function removeRow(id) {
    if (document.querySelectorAll('#items-body tr').length > 1) {
        var row = document.getElementById(id);
        if (row) row.remove();
        calcTotal();
    }
}

// Stock card lookup
var scTimers = {};
function lookupStockCard(idx) {
    clearTimeout(scTimers[idx]);
    var itemIdEl = document.getElementById('item-id-' + idx);
    var costEl   = document.getElementById('cost-' + idx);
    var expiryEl = document.getElementById('expiry-' + idx);
    var scEl     = document.getElementById('sc-' + idx);
    var msgEl    = document.getElementById('sc-msg-' + idx);
    if (!costEl || !scEl) return;

    var itemId    = itemIdEl ? itemIdEl.value : '';
    var unitCost  = parseFloat(costEl.value) || 0;
    var expiryVal = expiryEl ? expiryEl.value : '';

    if (!itemId || unitCost <= 0) {
        scEl.value = unitCost > 0 ? '— (new item)' : '—';
        scEl.style.color = '#a0aec0';
        if (msgEl) msgEl.textContent = (!itemId && unitCost > 0) ? 'New item — stock no. generated on delivery' : '';
        return;
    }

    scTimers[idx] = setTimeout(function() {
        var url = '/api/item-stock-card?item_id=' + encodeURIComponent(itemId) + '&unit_cost=' + encodeURIComponent(unitCost);
        if (expiryVal) url += '&expiration_date=' + encodeURIComponent(expiryVal);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.found && d.stock_number) {
                    scEl.value = d.stock_number;
                    scEl.style.color = '#276749';
                    scEl.style.fontWeight = '700';
                    scEl.style.background = '#f0fff4';
                    if (msgEl) msgEl.innerHTML = '<span style="color:#276749"><i class="fas fa-check-circle"></i> Existing stock card</span>';
                } else if (d.preview) {
                    scEl.value = d.preview;
                    scEl.style.color = '#a0aec0';
                    scEl.style.fontWeight = 'normal';
                    scEl.style.background = '#f7fafc';
                    if (msgEl) msgEl.innerHTML = '<span style="color:#a0aec0"><i class="fas fa-plus-circle"></i> New — generated on delivery</span>';
                }
            }).catch(function() { scEl.value = '—'; });
    }, 400);
}
</script>
@endpush
