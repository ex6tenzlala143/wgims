@extends('layouts.app')
@section('title', 'Add Supplier')
@section('page-title', 'Add Supplier')

@section('content')
<div class="page-header">
    <div>
        <h1>Add New Supplier</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('suppliers.index') }}">Suppliers</a> / Create</div>
    </div>
</div>

<div class="card" style="max-width:700px">
    <div class="card-header"><h3><i class="fas fa-truck" style="color:var(--primary)"></i> Supplier Details</h3></div>
    <div class="card-body">
        <form action="{{ route('suppliers.store') }}" method="POST">
            @csrf
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Supplier Name <span style="color:red">*</span></label>
                    <input type="text" name="name" class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label class="form-label">TIN Number</label>
                    <input type="text" name="tin" class="form-control" value="{{ old('tin') }}" placeholder="000-000-000">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <textarea name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
            </div>
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="{{ old('contact_person') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="{{ old('email') }}">
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Supplier</button>
                <a href="{{ route('suppliers.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
