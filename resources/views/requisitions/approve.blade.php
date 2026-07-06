@extends('layouts.app')
@section('title', 'Approve RIS')
@section('page-title', 'Approve RIS')

@section('content')
<div class="page-header">
    <div>
        <h1>Approve RIS #{{ $requisition->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / Approve</div>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    @if($requisition->status === 'partially_approved')
        <strong>Partially fulfilled.</strong>
        Some items were previously issued. The table below shows only the outstanding quantities.
        Enter how much to issue now — you can issue less than the outstanding amount and come back later.
    @else
        <strong>Review the requested quantities.</strong>
        If stock is insufficient, you can issue only the available amount.
        The stock card will be updated automatically upon approval.
    @endif
</div>

<form action="{{ route('requisitions.process_approval', $requisition->id) }}" method="POST">
@csrf
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
    <div>
        <div class="card" style="margin-bottom:20px">
            <div class="card-header"><h3>Items to Issue</h3></div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Stock No.</th>
                            <th>Description</th>
                            <th>Unit</th>
                            <th style="text-align:right">Qty Requested</th>
                            <th style="text-align:right">Already Issued</th>
                            <th style="text-align:right">Outstanding</th>
                            <th style="text-align:right">Current Stock</th>
                            <th style="width:140px">Qty to Issue Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requisition->items as $ri)
                        @php
                            $available   = $ri->item->quantity ?? 0;
                            $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued);
                            $canIssue    = min($available, $outstanding);
                            $isDone      = $outstanding <= 0;
                        @endphp
                        <tr style="{{ $isDone ? 'opacity:.6;background:#f7fafc' : '' }}">
                            <td><code>{{ $ri->item->stock_number ?? '-' }}</code></td>
                            <td>
                                {{ $ri->item->description ?? '-' }}
                                @if($isDone)
                                    <span class="badge badge-success" style="font-size:10px;margin-left:4px">
                                        <i class="fas fa-check"></i> Fulfilled
                                    </span>
                                @endif
                            </td>
                            <td>{{ $ri->item->unit ?? '-' }}</td>
                            <td style="text-align:right;font-weight:600">{{ number_format($ri->quantity_requested, 2) }}</td>
                            <td style="text-align:right;color:var(--success)">
                                {{ $ri->quantity_issued > 0 ? number_format($ri->quantity_issued, 2) : '—' }}
                            </td>
                            <td style="text-align:right">
                                @if($isDone)
                                    <span class="badge badge-success">—</span>
                                @else
                                    <span style="font-weight:700;color:var(--warning)">{{ number_format($outstanding, 2) }}</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <span class="{{ $available >= $outstanding ? 'badge badge-success' : 'badge badge-danger' }}">
                                    {{ number_format($available, 2) }}
                                </span>
                            </td>
                            <td>
                                <input type="number"
                                       name="items[{{ $ri->id }}][quantity_issued]"
                                       class="form-control"
                                       min="0"
                                       step="0.01"
                                       value="{{ $isDone ? 0 : $canIssue }}"
                                       {{ $isDone ? 'disabled' : '' }}
                                       title="Outstanding: {{ $outstanding }} | Available: {{ $available }}">
                                @if(!$isDone && $available < $outstanding)
                                <small style="color:var(--danger);font-size:11px">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Only {{ number_format($available, 2) }} in stock
                                </small>
                                @endif
                                @if($isDone)
                                    {{-- Submit 0 for fulfilled lines so the controller sees them --}}
                                    <input type="hidden" name="items[{{ $ri->id }}][quantity_issued]" value="0">
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>Approval Signatories</h3></div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label">Approved By (Name) <span style="color:red">*</span></label>
                        <input type="text" name="approved_by_name" class="form-control" value="{{ old('approved_by_name', auth()->user()->name) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Approved By (Designation)</label>
                        <input type="text" name="approved_by_designation" class="form-control" value="{{ old('approved_by_designation') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issued By (Name) <span style="color:red">*</span></label>
                        <input type="text" name="issued_by_name" class="form-control" value="{{ old('issued_by_name', auth()->user()->name) }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Issued By (Designation)</label>
                        <input type="text" name="issued_by_designation" class="form-control" value="{{ old('issued_by_designation') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Received By (Name)</label>
                        <input type="text" name="received_by_name" class="form-control" value="{{ old('received_by_name', $requisition->requested_by_name) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Received By (Designation)</label>
                        <input type="text" name="received_by_designation" class="form-control" value="{{ old('received_by_designation') }}">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>RIS Summary</h3></div>
            <div class="card-body" style="font-size:14px">
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">RIS Number:</span><br><strong>{{ $requisition->ris_number }}</strong></div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Warehouse:</span><br>{{ $requisition->warehouse->name ?? '-' }}</div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Purpose:</span><br>{{ $requisition->purpose }}</div>
                <div style="margin-bottom:10px"><span style="color:var(--text-muted)">Items:</span><br>{{ $requisition->items->count() }} item(s)</div>
                @php
                    $totalReqSidebar = $requisition->items->sum('quantity_requested');
                    $totalIssSidebar = $requisition->items->sum('quantity_issued');
                    $totalRemSidebar = max(0, $totalReqSidebar - $totalIssSidebar);
                    $sidePct         = $totalReqSidebar > 0 ? min(100, round($totalIssSidebar / $totalReqSidebar * 100)) : 0;
                @endphp
                @if($totalIssSidebar > 0)
                <div style="margin-bottom:16px;padding:12px;background:#f0fff4;border-radius:8px;border:1px solid #9ae6b4">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px">Fulfilment So Far</div>
                    <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;margin-bottom:6px">
                        <div style="background:var(--success);width:{{ $sidePct }}%;height:100%;border-radius:999px"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:12px">
                        <span style="color:var(--success)">{{ number_format($totalIssSidebar, 2) }} issued</span>
                        <span style="color:var(--warning)">{{ number_format($totalRemSidebar, 2) }} remaining</span>
                    </div>
                </div>
                @endif
                <div style="margin-bottom:20px"></div>
                <button type="submit" class="btn btn-success" style="width:100%;justify-content:center">
                    <i class="fas fa-check-circle"></i> Process Approval
                </button>
                <a href="{{ route('requisitions.show', $requisition->id) }}" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>
</div>
</form>
@endsection
