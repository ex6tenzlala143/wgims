@extends('layouts.app')
@section('title', 'Add Item')
@section('page-title', 'Add Item')

@section('content')
<div class="page-header">
    <div>
        <h1>Add New Item</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('items.index') }}">Items</a> / Create</div>
    </div>
</div>

<div class="card" style="max-width:800px">
    <div class="card-header"><h3><i class="fas fa-plus-circle" style="color:var(--primary)"></i> Item Details</h3></div>
    <div class="card-body">
        <form action="{{ route('items.store') }}" method="POST">
            @csrf

            {{-- Row 1: Description + Expiration Date --}}
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Description <span style="color:red">*</span></label>
                    <input type="text" name="description"
                        class="form-control {{ $errors->has('description') ? 'is-invalid' : '' }}"
                        value="{{ old('description') }}" placeholder="Item description" required>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">Expiration Date</label>
                    <input type="date" name="expiration_date" class="form-control"
                        value="{{ old('expiration_date') }}">
                    <small style="color:var(--text-muted);font-size:11px">Leave blank if not applicable.</small>
                </div>
            </div>

            {{-- Row 1b removed (Quantity Per Item field removed from UI) --}}

            {{-- Row 2: Unit + Category --}}
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Unit of Measurement <span style="color:red">*</span></label>
                    <select name="unit" class="form-control {{ $errors->has('unit') ? 'is-invalid' : '' }}" required>
                        <option value="">— Select Unit —</option>
                        @foreach(App\Models\Item::UNITS as $key => $label)
                        <option value="{{ $key }}" {{ old('unit') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small style="color:var(--text-muted);font-size:11px">Select the appropriate unit of measurement.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Category <span style="color:red">*</span></label>
                    <select name="category" id="category"
                        class="form-control {{ $errors->has('category') ? 'is-invalid' : '' }}"
                        required onchange="fillAccountCode()">
                        <option value="">— Select Category —</option>
                        @foreach(App\Models\Item::getCategories() as $key => $cat)
                        <option value="{{ $key }}" {{ old('category') == $key ? 'selected' : '' }}>{{ $cat['label'] }}</option>
                        @endforeach
                    </select>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Row 3: Account Code + Warehouse --}}
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Account Code</label>
                    <input type="text" id="account_code" class="form-control" readonly
                        placeholder="Auto-filled from category"
                        value="{{ old('category') ? App\Models\Item::getAccountCodeForCategory(old('category')) : '' }}"
                        style="background:#f7fafc">
                    <small style="color:var(--text-muted);font-size:11px">Automatically filled based on category.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Warehouse Assignment <span style="color:red">*</span></label>
                    @if(auth()->user()->hasAdminAccess())
                    <select name="warehouse_id" class="form-control {{ $errors->has('warehouse_id') ? 'is-invalid' : '' }}" required>
                        <option value="">— Select Warehouse —</option>
                        @foreach($warehouses as $c)
                        <option value="{{ $c->id }}" {{ old('warehouse_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @else
                    @php
                        $singleWarehouse = auth()->user()->warehouse
                            ?? auth()->user()->warehouses()->first();
                    @endphp
                    <input type="text" class="form-control" value="{{ $singleWarehouse?->name ?? '' }}" readonly style="background:#f7fafc">
                    <input type="hidden" name="warehouse_id" value="{{ $singleWarehouse?->id ?? auth()->user()->warehouse_id }}">
                    @endif
                    @error('warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Row 4: RIS No. + Reorder Point --}}
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">RIS No.</label>
                    <input type="text" name="ris_number" class="form-control"
                        value="{{ old('ris_number') }}" placeholder="e.g. RIS-2026-001" style="max-width:300px">
                </div>
                <div class="form-group">
                    <label class="form-label">Reorder Point</label>
                    <input type="number" name="reorder_point" class="form-control"
                        value="{{ old('reorder_point', 0) }}" min="0" step="0.01" style="max-width:200px">
                    <small style="color:var(--text-muted);font-size:11px">Alert when stock falls below this quantity.</small>
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
                    value="{{ old('engas_unit_cost') }}" min="0" step="0.01"
                    placeholder="0.00" style="max-width:200px">
                <small style="color:var(--text-muted);font-size:11px">Separate from the standard unit cost. Used for Enggas pricing.</small>
            </div>
            @endif

            <div style="display:flex;gap:12px;margin-top:8px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Item</button>
                <a href="{{ route('items.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
@php
    $accountCodesJson = collect(App\Models\Item::getCategories())->map(function($c) {
        return $c['account_code'];
    })->toJson();
@endphp
const accountCodes = {!! $accountCodesJson !!};

function fillAccountCode() {
    const cat = document.getElementById('category').value;
    document.getElementById('account_code').value = accountCodes[cat] || '';
}
</script>
@endpush
@endsection
