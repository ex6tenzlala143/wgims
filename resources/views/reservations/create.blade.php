@extends('layouts.app')
@section('title', 'Create Reservation')
@section('page-title', 'Create Reservation')

@section('content')
<div class="page-header">
    <div>
        <h1>Create Reservation</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('reservations.index') }}">Reservations</a> / Create</div>
    </div>
</div>

<div class="card" style="max-width:900px">
    <div class="card-body">
        <form method="POST" action="{{ route('reservations.store') }}" id="reservationForm">
            @csrf

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Warehouse <span class="req">*</span></label>
                    <select name="warehouse_id" id="warehouse_id" class="form-control" required>
                        <option value="">— Select Warehouse —</option>
                        @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}" {{ old('warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label">Item / Stock <span class="req">*</span></label>
                    <select name="item_id" id="item_id" class="form-control" required disabled>
                        <option value="">— Select Item —</option>
                    </select>
                    <div class="hint" id="itemHint">Select a warehouse first</div>
                    @error('item_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div id="stockInfo" style="display:none;margin:16px 0;padding:16px;background:var(--surface-soft);border-radius:8px">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:12px">
                    <div>
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Physical Quantity</div>
                        <div style="font-size:20px;font-weight:700" id="physicalQty">0</div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Reserved Quantity</div>
                        <div style="font-size:20px;font-weight:700;color:var(--primary)" id="reservedQty">0</div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Available Quantity</div>
                        <div style="font-size:20px;font-weight:700;color:var(--success)" id="availableQty">0</div>
                    </div>
                </div>
                <div id="stockDetails" style="font-size:11px;color:var(--text-muted)"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Reservation Quantity <span class="req">*</span></label>
                <input type="number" name="reserved_quantity" id="reserved_quantity" class="form-control" min="0.0001" step="0.0001" value="{{ old('reserved_quantity') }}" required disabled>
                <div class="hint" id="qtyHint">Select an item to enter quantity</div>
                @error('reserved_quantity')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <input type="text" name="purpose" class="form-control" value="{{ old('purpose') }}" placeholder="e.g., For incoming RIS-0001">
            </div>

            <div class="form-group">
                <label class="form-label">Expiration Date</label>
                <input type="date" name="expires_at" class="form-control" value="{{ old('expires_at') }}" min="{{ date('Y-m-d', strtotime('+1 day')) }}">
                <div class="hint">Optional. Reservation will expire on this date if not fulfilled.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes...">{{ old('notes') }}</textarea>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="fas fa-save"></i> Create Reservation</button>
                <a href="{{ route('reservations.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    'use strict';

    const warehouseSelect = document.getElementById('warehouse_id');
    const itemSelect = document.getElementById('item_id');
    const stockInfo = document.getElementById('stockInfo');
    const reservedInput = document.getElementById('reserved_quantity');
    const submitBtn = document.getElementById('submitBtn');
    const itemHint = document.getElementById('itemHint');
    const qtyHint = document.getElementById('qtyHint');
    const stockDetails = document.getElementById('stockDetails');

    function resetForm() {
        itemSelect.innerHTML = '<option value="">— Select Item —</option>';
        itemSelect.disabled = true;
        stockInfo.style.display = 'none';
        reservedInput.disabled = true;
        reservedInput.value = '';
        submitBtn.disabled = true;
        itemHint.textContent = 'Select a warehouse first';
        qtyHint.textContent = 'Select an item to enter quantity';
    }

    function resetItemSelection() {
        itemSelect.innerHTML = '<option value="">— Select Item —</option>';
        itemSelect.disabled = false;
        stockInfo.style.display = 'none';
        reservedInput.disabled = true;
        reservedInput.value = '';
        submitBtn.disabled = true;
        itemHint.textContent = 'Select an item';
        qtyHint.textContent = 'Select an item to enter quantity';
    }

    function loadItems(warehouseId) {
        if (!warehouseId) {
            resetForm();
            return;
        }

        resetItemSelection();
        itemHint.textContent = 'Loading items...';

        const url = '{{ route("reservations.items_by_warehouse") }}?warehouse_id=' + encodeURIComponent(warehouseId);

        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            return response.json();
        })
        .then(function(items) {
            if (!Array.isArray(items)) {
                throw new Error('Invalid response format');
            }

            itemSelect.innerHTML = '<option value="">— Select Item —</option>';

            if (items.length === 0) {
                itemHint.textContent = 'No items available in this warehouse';
                itemSelect.disabled = true;
                return;
            }

            items.forEach(function(item) {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.description + ' (' + item.stock_number + ')';
                opt.dataset.available = item.available_qty || 0;
                opt.dataset.physical = item.physical_qty || 0;
                opt.dataset.reserved = item.reserved_qty || 0;
                opt.dataset.unitcost = item.unit_cost || 0;
                opt.dataset.engas = item.engas_unit_cost || '';
                opt.dataset.expiry = item.expiration_date || '';
                opt.dataset.subsidy = item.source_subsidy_code || '';
                itemSelect.appendChild(opt);
            });

            itemSelect.disabled = false;
            itemHint.textContent = items.length + ' item(s) available';
        })
        .catch(function(error) {
            console.error('Failed to load items:', error);
            itemHint.textContent = 'Error loading items: ' + error.message;
            itemSelect.disabled = true;
        });
    }

    function showStockInfo(opt) {
        if (!opt || !opt.value) {
            stockInfo.style.display = 'none';
            reservedInput.disabled = true;
            submitBtn.disabled = true;
            return;
        }

        const physical = parseFloat(opt.dataset.physical) || 0;
        const reserved = parseFloat(opt.dataset.reserved) || 0;
        const available = parseFloat(opt.dataset.available) || 0;
        const unitCost = parseFloat(opt.dataset.unitcost) || 0;
        const engas = opt.dataset.engas;
        const expiry = opt.dataset.expiry;
        const subsidy = opt.dataset.subsidy;

        document.getElementById('physicalQty').textContent = physical.toLocaleString();
        document.getElementById('reservedQty').textContent = reserved.toLocaleString();
        document.getElementById('availableQty').textContent = available.toLocaleString();

        let details = [];
        details.push('Unit Cost: ₱' + unitCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        if (engas) details.push('ENGAS Cost: ₱' + parseFloat(engas).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        if (expiry) details.push('Expires: ' + expiry);
        if (subsidy) details.push('Source: ' + subsidy);
        stockDetails.textContent = details.join(' · ');

        stockInfo.style.display = 'block';
        reservedInput.disabled = available <= 0;
        reservedInput.max = available;
        reservedInput.value = '';
        submitBtn.disabled = available <= 0;
        qtyHint.textContent = available > 0 ? 'Maximum available: ' + available.toLocaleString() : 'No quantity available for reservation';
    }

    warehouseSelect.addEventListener('change', function() {
        loadItems(this.value);
    });

    itemSelect.addEventListener('change', function() {
        const opt = this.selectedOptions[0];
        showStockInfo(opt);
    });

    // Auto-load items if warehouse is pre-selected on page load
    if (warehouseSelect.value) {
        loadItems(warehouseSelect.value);
    }
})();
</script>
@endpush
