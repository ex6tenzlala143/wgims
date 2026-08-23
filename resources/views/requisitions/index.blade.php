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
    <button type="button" class="btn btn-primary" onclick="openCreateModal()"><i class="fas fa-plus"></i> New RIS</button>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search RIS ID, RIS No., DR#, office, purpose..." value="{{ request('search') }}">
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
                <select name="related_to_deleted_subsidy" class="form-control">
                    <option value="">All Subsidy Sources</option>
                    <option value="yes" {{ request('related_to_deleted_subsidy') === 'yes' ? 'selected' : '' }}>Related to Deleted/Archived Subsidy</option>
                    <option value="no" {{ request('related_to_deleted_subsidy') === 'no' ? 'selected' : '' }}>Not Related to Deleted/Archived Subsidy</option>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('requisitions.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    @if(request('search'))
    <div class="filter-notice">
        <i class="fas fa-filter"></i>
        Showing <strong>{{ $requisitions->total() }}</strong> result(s) for "<strong>{{ request('search') }}</strong>"
        <a href="{{ route('requisitions.index', request()->except(['search', 'page'])) }}">Clear search</a>
    </div>
    @endif
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>RIS ID / RIS No.</th>
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
                    $subSnapshot = $ris->deletedSubsidySnapshot();
                @endphp
                <tr>
                    <td>
                        <div style="line-height:1.6">
                            <div style="display:flex;align-items:baseline;gap:6px">
                                <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;min-width:46px">RIS ID:</span>
                                <strong style="color:var(--primary);font-family:monospace">{{ $ris->ris_code ?? $ris->ris_id }}</strong>
                            </div>
                            <div style="display:flex;align-items:baseline;gap:6px">
                                <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;min-width:46px">RIS No.:</span>
                                <span style="font-weight:600">{{ $ris->ris_number }}</span>
                            </div>
                        </div>
                        @if($subSnapshot)
                        <div style="margin-top:5px">
                            @include('partials.subsidy-source-badge', [
                                'status' => $subSnapshot['status'],
                                'ris'    => $subSnapshot['ris'],
                                'dr'     => $subSnapshot['dr'],
                                'prefix' => 'RELATED TO',
                            ])
                        </div>
                        @endif
                    </td>
                    <td>
                        @php $drs = $ris->items->pluck('dr_number')->filter()->unique(); @endphp
                        @if($drs->isNotEmpty())
                            @foreach($drs as $dr)
                                <code style="font-size:12px">{{ $dr }}</code>@if(!$loop->last)<br>@endif
                            @endforeach
                        @else
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    <td>{{ $ris->date_requested->format('M d, Y') }}</td>
                    <td>{{ $ris->warehouse_names ?? ($ris->warehouse->name ?? '-') }}</td>
                    <td>{{ $ris->office ?? '-' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($ris->purpose, 40) }}</td>
                    <td>
                        @if($risReq > 0)
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;display:flex;justify-content:space-between">
                            <span>{{ number_format($risIss) }} issued</span>
                            <span style="color:{{ $risRem > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                                {{ $risRem > 0 ? number_format($risRem).' left' : '✓ Done' }}
                            </span>
                        </div>
                        <div style="background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden">
                            <div style="background:{{ $risBar }};width:{{ $risPct }}%;height:100%;border-radius:999px"></div>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">{{ $risPct }}% of {{ number_format($risReq) }}</div>
                        @else
                        <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $ris->getStatusBadgeClass() }}">{{ $ris->getStatusLabel() }}</span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('requisitions.show', $ris->id) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                            @if(auth()->user()->canWrite())
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

@if(auth()->user()->canCreate())
@include('requisitions._create_form', ['createModalOpen' => $errors->any(), 'modalOnlyPage' => false])
@endif
@endsection