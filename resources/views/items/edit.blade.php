@extends('layouts.app')
@section('title', 'Edit Item')
@section('page-title', 'Edit Item')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit Item</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('items.index') }}">Items</a> / Edit</div>
    </div>
</div>

<div class="card" style="max-width:800px">
    <div class="card-header">
        <h3><i class="fas fa-edit" style="color:var(--primary)"></i> Edit: {{ $item->description }}</h3>
        <span class="badge badge-secondary">{{ $item->stock_number }}</span>
    </div>
    <div class="card-body">
        <form action="{{ route('items.update', $item->id) }}" method="POST">
            @csrf @method('PUT')
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Description <span style="color:red">*</span></label>
                    <input type="text" name="description" class="form-control {{ $errors->has('description') ? 'is-invalid' : '' }}"
                        value="{{ old('description', $item->description) }}" required>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Expiration Date</label>
                    <input type="date" name="expiration_date" class="form-control"
                        value="{{ old('expiration_date', $item->expiration_date?->format('Y-m-d')) }}">
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Unit <span style="color:red">*</span></label>
                    <select name="unit" class="form-control" required>
                        @foreach(App\Models\Item::UNITS as $key => $label)
                        <option value="{{ $key }}" {{ old('unit', $item->unit) == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Category <span style="color:red">*</span></label>
                    <select name="category" id="category" class="form-control" required onchange="fillAccountCode()">
                        @foreach(App\Models\Item::getCategories() as $key => $cat)
                        <option value="{{ $key }}" {{ old('category', $item->category) == $key ? 'selected' : '' }}>{{ $cat['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Account Code</label>
                    <input type="text" id="account_code" class="form-control" readonly value="{{ $item->account_code }}" style="background:#f7fafc">
                </div>
                <div class="form-group">
                    <label class="form-label">Warehouse Assignment <span style="color:red">*</span></label>
                    <select name="warehouse_id" class="form-control" required>
                        <option value="">— Select Warehouse —</option>
                        @foreach($warehouses as $c)
                        <option value="{{ $c->id }}" {{ old('warehouse_id', $item->warehouse_id) == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">RIS No.</label>
                    <input type="text" name="ris_number" class="form-control" value="{{ old('ris_number', $item->ris_number) }}" placeholder="e.g. RIS-2026-001">
                </div>
                <div class="form-group">
                    <label class="form-label">Reorder Point</label>
                    <input type="number" name="reorder_point" class="form-control" value="{{ old('reorder_point', $item->reorder_point) }}" min="0" step="0.01" style="max-width:200px">
                </div>
            </div>
            {{-- Engas Unit Cost — admin only --}}
            @if(auth()->user()->isAdmin())
            <div class="form-group">
                <label class="form-label">
                    Engas Unit Cost (₱)
                    <span style="font-size:11px;color:var(--text-muted);font-weight:normal;margin-left:4px">— admin only</span>
                </label>
                <input type="number" name="engas_unit_cost" class="form-control"
                    value="{{ old('engas_unit_cost', $item->engas_unit_cost) }}"
                    min="0" step="0.01" placeholder="0.00" style="max-width:200px">
                <small style="color:var(--text-muted);font-size:11px">Separate from the standard unit cost. Used for Enggas pricing.</small>
            </div>
            @endif
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Item</button>
                <a href="{{ route('items.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
@php
    $accountCodesJson = collect(App\Models\Item::getCategories())->map(function($c) { return $c['account_code']; })->toJson();
@endphp
const accountCodes = {!! $accountCodesJson !!};
function fillAccountCode() {
    const cat = document.getElementById('category').value;
    document.getElementById('account_code').value = accountCodes[cat] || '';
}
</script>
@endpush
@endsection
