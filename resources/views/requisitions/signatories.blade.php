@extends('layouts.app')
@section('title', 'RIS Signatories')
@section('page-title', 'RIS Signatories')

@section('content')
<div class="page-header">
    <div>
        <h1>Signatories — RIS #{{ $requisition->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Signatories</div>
    </div>
</div>

<div class="card" style="max-width:700px">
    <div class="card-header"><h3><i class="fas fa-signature" style="color:var(--primary)"></i> Manage Signatories</h3></div>
    <div class="card-body">
        <form action="{{ route('requisitions.update_signatories', $requisition->id) }}" method="POST">
            @csrf @method('PUT')
            <div class="form-row cols-2">
                <div class="form-group">
                    <label class="form-label">Requested By — Name</label>
                    <input type="text" name="requested_by_name" class="form-control" value="{{ old('requested_by_name', $requisition->requested_by_name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Requested By — Designation</label>
                    <input type="text" name="requested_by_designation" class="form-control" value="{{ old('requested_by_designation', $requisition->requested_by_designation) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Approved By — Name</label>
                    <input type="text" name="approved_by_name" class="form-control" value="{{ old('approved_by_name', $requisition->approved_by_name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Approved By — Designation</label>
                    <input type="text" name="approved_by_designation" class="form-control" value="{{ old('approved_by_designation', $requisition->approved_by_designation) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Issued By — Name</label>
                    <input type="text" name="issued_by_name" class="form-control" value="{{ old('issued_by_name', $requisition->issued_by_name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Issued By — Designation</label>
                    <input type="text" name="issued_by_designation" class="form-control" value="{{ old('issued_by_designation', $requisition->issued_by_designation) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Received By — Name</label>
                    <input type="text" name="received_by_name" class="form-control" value="{{ old('received_by_name', $requisition->received_by_name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Received By — Designation</label>
                    <input type="text" name="received_by_designation" class="form-control" value="{{ old('received_by_designation', $requisition->received_by_designation) }}">
                </div>
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Signatories</button>
                <a href="{{ route('requisitions.show', $requisition->id) }}" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
