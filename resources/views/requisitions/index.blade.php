@extends('layouts.app')
@section('title', 'Requisitions')
@section('page-title', 'Requisitions (RIS)')

@section('content')
<div class="page-header">
    <div>
        <h1>Requisition and Issue Slips</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Requisitions</div>
    </div>
    @if(auth()->user()->canCreate())
    <a href="{{ route('requisitions.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New RIS</a>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input" style="width:380px">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search RIS#, DR#, office, purpose..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending" {{ request('status')=='pending'?'selected':'' }}>Pending</option>
                    <option value="approved" {{ request('status')=='approved'?'selected':'' }}>Approved</option>
                    <option value="partially_approved" {{ request('status')=='partially_approved'?'selected':'' }}>Partially Fulfilled</option>
                    <option value="cancelled" {{ request('status')=='cancelled'?'selected':'' }}>Cancelled</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('requisitions.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>RIS Number</th>
                    <th>DR No.</th>
                    <th>Date</th>
                    <th>Warehouse</th>
                    <th>Office</th>
                    <th>Purpose</th>
                    <th style="min-width:150px">Fulfilment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requisitions as $ris)
                @php
                    // Use withSum aggregates — avoids loading all items just for these two numbers
                    $risReq  = (float) ($ris->total_requested ?? 0);
                    $risIss  = (float) ($ris->total_issued ?? 0);
                    $risRem  = max(0, $risReq - $risIss);
                    $risPct  = $risReq > 0 ? min(100, round($risIss / $risReq * 100)) : 0;
                    $risBar  = $risPct >= 100 ? 'var(--success)' : ($risPct > 0 ? 'var(--primary)' : '#e2e8f0');
                @endphp
                <tr>
                    <td><strong>{{ $ris->ris_number }}</strong></td>
                    <td>
                        @if($ris->dr_number)
                            <code style="font-size:12px">{{ $ris->dr_number }}</code>
                        @else
                            <span style="color:var(--danger);font-size:12px;font-weight:600">
                                <i class="fas fa-exclamation-triangle"></i> Missing
                            </span>
                        @endif
                    </td>
                    <td>{{ $ris->date_requested->format('M d, Y') }}</td>
                    <td>{{ $ris->warehouse->name ?? '-' }}</td>
                    <td>{{ $ris->office ?? '-' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($ris->purpose, 40) }}</td>
                    <td>
                        @if($risReq > 0)
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;display:flex;justify-content:space-between">
                            <span>{{ number_format($risIss, 2) }} issued</span>
                            <span style="color:{{ $risRem > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                                {{ $risRem > 0 ? number_format($risRem, 2).' left' : '✓ Done' }}
                            </span>
                        </div>
                        <div style="background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden">
                            <div style="background:{{ $risBar }};width:{{ $risPct }}%;height:100%;border-radius:999px"></div>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">{{ $risPct }}% of {{ number_format($risReq, 2) }}</div>
                        @else
                        <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $ris->getStatusBadgeClass() }}">{{ $ris->getStatusLabel() }}</span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('requisitions.show', $ris->id) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                            @if(auth()->user()->canWrite())
                            <a href="{{ route('requisitions.edit', $ris->id) }}" class="btn btn-sm btn-primary btn-icon" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="{{ route('requisitions.destroy', $ris->id) }}" method="POST" style="display:inline"
                                onsubmit="return confirm('Delete RIS #{{ $ris->ris_number }}?\n\nThis will permanently delete the requisition. Any stock that was already issued will be reversed back to inventory.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                            @if(($ris->status == 'pending' || $ris->status == 'partially_approved') && auth()->user()->canApprove())
                            <a href="{{ route('requisitions.approve', $ris->id) }}" class="btn btn-sm btn-success btn-icon" title="Issue Items"><i class="fas fa-check"></i></a>
                            @endif
                            <a href="{{ route('requisitions.print', $ris->id) }}" class="btn btn-sm btn-outline btn-icon" title="Print" target="_blank"><i class="fas fa-print"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-clipboard" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    No requisitions found.
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($requisitions->hasPages())
    <div class="card-footer">{{ $requisitions->links() }}</div>
    @endif
</div>
@endsection