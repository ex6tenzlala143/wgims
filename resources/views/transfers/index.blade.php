@extends('layouts.app')

@section('title', 'Stock Transfers')
@section('page-title', 'Stock Transfers')

@section('content')
<div class="page-header">
    <div>
        <h1>Stock Transfers</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('transfers.index') }}">Stock Transfers</a></div>
    </div>
    @if(auth()->user()->canCreate())
    <button type="button" class="btn btn-primary" onclick="openTransferModal()">
        <i class="fas fa-exchange-alt"></i> New Transfer
    </button>
    @endif
</div>

{{-- Filters --}}
<div class="card" style="margin-bottom:16px">
    <div class="card-header-filters">
        <form method="GET" action="{{ route('transfers.index') }}">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search transfer #, RIS #, DR #, Subsidy ID, warehouse..." value="{{ request('q') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
                <select name="related_to_deleted_subsidy" class="form-control">
                    <option value="">All Transfers</option>
                    <option value="yes" {{ request('related_to_deleted_subsidy') === 'yes' ? 'selected' : '' }}>Related to Deleted Subsidy</option>
                    <option value="no" {{ request('related_to_deleted_subsidy') === 'no' ? 'selected' : '' }}>Not Related to Deleted Subsidy</option>
                </select>
                @if(auth()->user()->hasAdminAccess())
                <select name="from_warehouse_id" class="form-control">
                    <option value="">All Source Warehouses</option>
                    @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ request('from_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                    @endforeach
                </select>
                <select name="to_warehouse_id" class="form-control">
                    <option value="">All Destination Warehouses</option>
                    @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ request('to_warehouse_id') == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                    @endforeach
                </select>
                @endif
                <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}" placeholder="From date">
                <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}" placeholder="To date">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('transfers.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    @if(request('q'))
    <div class="filter-notice">
        <i class="fas fa-filter"></i>
        Showing <strong>{{ $transfers->total() }}</strong> result(s) for "<strong>{{ request('q') }}</strong>"
        <a href="{{ route('transfers.index', request()->except(['q', 'page'])) }}">Clear search</a>
    </div>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-exchange-alt"></i> Transfer Records</h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $transfers->total() }} record(s)</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Transfer #</th>
                    <th>Date</th>
                    <th>From</th>
                    <th>To</th>
                    <th style="text-align:right">Items</th>
                    <th style="min-width:150px">Transfer Summary</th>
                    <th>Status</th>
                    <th>Transferred By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                @php
                    $totalQty   = (float) ($transfer->total_requested ?? 0);
                    $sentQty    = (float) ($transfer->total_transferred ?? 0);
                    $remQty     = max(0, $totalQty - $sentQty);
                    $pct        = $totalQty > 0 ? min(100, round($sentQty / $totalQty * 100)) : 0;
                    $barColor   = $pct >= 100 ? 'var(--success)' : ($pct > 0 ? 'var(--primary)' : '#e2e8f0');
                @endphp
                <tr>
                    <td>
                        <strong>{{ $transfer->transfer_number }}</strong>
                        @if($transfer->isRelatedToDeletedSubsidy())
                        <div style="margin-top:5px">
                            <span class="badge badge-danger" style="white-space:normal;text-align:left">
                                <i class="fas fa-exclamation-triangle"></i> RELATED TO DELETED SUBSIDY
                            </span>
                        </div>
                        @endif
                        @if($transfer->source_subsidy_code || $transfer->source_ris_number)
                        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                            @if($transfer->sourceSubsidyCode())
                            <code style="font-weight:700;color:var(--primary)">{{ $transfer->sourceSubsidyCode() }}</code>
                            @endif
                            @if($transfer->source_ris_number)
                            @if($transfer->sourceSubsidyCode()) · @endif
                            RIS: {{ $transfer->sourceSubsidyReference() }}
                            @endif
                        </div>
                        @endif
                    </td>
                    <td>{{ $transfer->transfer_date->format('M d, Y') }}</td>
                    <td><span style="font-size:12px">{{ $transfer->fromWarehouse->name }}</span></td>
                    <td><span style="font-size:12px">{{ $transfer->toWarehouse->name }}</span></td>
                    <td style="text-align:right;font-weight:600">
                        {{ $transfer->items_count ?? 0 }}
                    </td>
                    <td>
                        @if($totalQty > 0)
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;display:flex;justify-content:space-between">
                            <span>{{ number_format($sentQty) }} dispatched</span>
                            <span style="color:{{ $remQty > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                                {{ $remQty > 0 ? number_format($remQty).' left' : '✓ Complete' }}
                            </span>
                        </div>
                        <div style="background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden">
                            <div style="background:{{ $barColor }};width:{{ $pct }}%;height:100%;border-radius:999px"></div>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                            {{ $pct }}% of {{ number_format($totalQty) }}
                        </div>
                        @else
                        <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $transfer->getStatusBadgeClass() }}">{{ $transfer->getStatusLabel() }}</span>
                    </td>
                    <td>{{ $transfer->transferredBy->name }}</td>
                    <td>
                        <a href="{{ route('transfers.show', $transfer) }}" class="btn btn-sm btn-outline btn-icon" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="{{ route('transfers.print', $transfer) }}" class="btn btn-sm btn-secondary btn-icon" target="_blank" title="Print">
                            <i class="fas fa-print"></i>
                        </a>
                        @if(auth()->user()->canWrite())
                        <form action="{{ route('transfers.destroy', $transfer) }}" method="POST" style="display:inline"
                            onsubmit="return confirm({{ $transfer->isRelatedToDeletedSubsidy()
                                ? "'This Stock Transfer is linked to a deleted Subsidy. Deleting this transfer will reverse its inventory movement. Are you sure?'"
                                : "'Delete transfer {$transfer->transfer_number}?\\n\\nThis will permanently delete the transfer and reverse all dispatched stock quantities — stock returns to the source warehouse.'"
                            }})">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger btn-icon" title="{{ $transfer->isRelatedToDeletedSubsidy() ? 'Review & Delete Transfer' : 'Delete Transfer' }}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">
                        <i class="fas fa-exchange-alt" style="font-size:32px;margin-bottom:8px;display:block;opacity:0.3"></i>
                        No transfers found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transfers->hasPages())
    <div class="card-footer">
        {{ $transfers->links() }}
    </div>
    @endif
</div>

@if(auth()->user()->canCreate())
@include('transfers._create_modal', [
    'createModalOpen' => $errors->any(),
    'warehouses' => $warehouses,
    'sourceWarehouse' => $sourceWarehouse ?? null,
    'sourceItems' => $sourceItems ?? collect(),
])
@endif
@endsection
