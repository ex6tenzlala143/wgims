@extends('layouts.app')
@section('title', 'Add Warehouse')
@section('page-title', 'Add Warehouse')

@section('content')
<div class="page-header">
    <div>
        <h1>Add New Warehouse</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('warehouses.index') }}">Warehouses</a> / Create</div>
    </div>
</div>

<div class="card" style="max-width:600px">
    <div class="card-header"><h3><i class="fas fa-building" style="color:var(--primary)"></i> Warehouse Details</h3></div>
    <div class="card-body">
        <form action="{{ route('warehouses.store') }}" method="POST">
            @csrf
            <div class="form-group">
                <label class="form-label">Warehouse Name <span style="color:red">*</span></label>
                <input type="text" name="name" class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" value="{{ old('name') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label class="form-label">Warehouse Code <span style="color:red">*</span></label>
                <input type="text" name="code" class="form-control {{ $errors->has('code') ? 'is-invalid' : '' }}" value="{{ old('code') }}" required placeholder="e.g. CFA, RC, YC" style="text-transform:uppercase">
                <small style="color:var(--text-muted);font-size:11px">Short unique code used for stock number generation.</small>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-group">
                <label class="form-label">Place / Location</label>
                <input type="text" name="place" class="form-control" value="{{ old('place') }}" placeholder="e.g. Cagayan de Oro City">
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Warehouse</button>
                <a href="{{ route('warehouses.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
