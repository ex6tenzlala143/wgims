@extends('layouts.app')
@section('title', 'New Delivery/Subsidy')
@section('page-title', 'New Delivery/Subsidy')

@section('content')
<div class="page-header">
    <div>
        <h1>New Delivery/Subsidy</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> /
            New
        </div>
    </div>
</div>

<form action="{{ route('delivery_subsidies.store') }}" method="POST" id="po-form">
@csrf
<div style="max-width:760px">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Delivery/Subsidy Header</h3>
        </div>
        <div class="card-body">

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date <span style="color:red">*</span></label>
                    <input type="date" name="date" class="form-control"
                           value="{{ old('date', date('Y-m-d')) }}" required>
                    @error('date')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Supplier/Subsidy <span style="color:red">*</span></label>
                    <select name="supplier_id" class="form-control" required>
                        <option value="">— Select Supplier/Subsidy —</option>
                        @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }}
                        </option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">RIS No. <span style="color:red">*</span></label>
                    <input type="text" name="ris_number" class="form-control"
                           value="{{ old('ris_number') }}" placeholder="e.g. RIS-2026-001" required>
                    @error('ris_number')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity Requested <span style="color:red">*</span></label>
                    <input type="number" name="quantity_requested" class="form-control"
                           value="{{ old('quantity_requested') }}" placeholder="0.00"
                           min="0.01" step="0.01" required>
                    @error('quantity_requested')<div style="color:var(--danger);font-size:12px;margin-top:3px">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Warehouse is set automatically from the first shipment item --}}

            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Date of Delivery</label>
                    <input type="date" name="date_of_delivery" class="form-control"
                           value="{{ old('date_of_delivery') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Date of Expiration</label>
                    <input type="date" name="date_of_expiration" class="form-control"
                           value="{{ old('date_of_expiration') }}">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Remarks</label>
                <textarea name="remarks" class="form-control" rows="2"
                          placeholder="Optional notes…">{{ old('remarks') }}</textarea>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Delivery/Subsidy
                </button>
                <a href="{{ route('delivery_subsidies.index') }}" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>

        </div>
    </div>
</div>
</form>
@endsection
