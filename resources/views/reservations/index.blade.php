@extends('layouts.app')
@section('title', 'Reservations')
@section('page-title', 'Reservations')

@section('content')
<div class="page-header">
    <div>
        <h1>Inventory Reservations</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Reservations</div>
    </div>
    @if(auth()->user()->canCreate())
    <button type="button" class="btn btn-primary" onclick="openResModal()">
        <i class="fas fa-plus"></i> New Reservation
    </button>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search reservation no., purpose, item..."
                           value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            <div class="filter-row">
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="PENDING"              {{ request('status')=='PENDING'?'selected':'' }}>Pending</option>
                    <option value="RESERVED"             {{ request('status')=='RESERVED'?'selected':'' }}>Reserved</option>
                    <option value="READY_FOR_REQUISITION"{{ request('status')=='READY_FOR_REQUISITION'?'selected':'' }}>Ready for RIS</option>
                    <option value="PARTIALLY_DEPLOYED"   {{ request('status')=='PARTIALLY_DEPLOYED'?'selected':'' }}>Partially Deployed</option>
                    <option value="DEPLOYED"             {{ request('status')=='DEPLOYED'?'selected':'' }}>Deployed</option>
                    <option value="CANCELLED"            {{ request('status')=='CANCELLED'?'selected':'' }}>Cancelled</option>
                    <option value="EXPIRED"              {{ request('status')=='EXPIRED'?'selected':'' }}>Expired</option>
                </select>
                @if(auth()->user()->hasAdminAccess())
                <select name="warehouse_id" class="form-control">
                    <option value="">All Warehouses</option>
                    @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ request('warehouse_id')==$wh->id?'selected':'' }}>{{ $wh->name }}</option>
                    @endforeach
                </select>
                @endif
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('reservations.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Reservation No.</th>
                    <th>Items</th>
                    <th>Warehouses</th>
                    <th style="text-align:right">Total Reserved</th>
                    <th style="text-align:right">Total Deployed</th>
                    <th style="text-align:right">Remaining</th>
                    <th>Status</th>
                    <th>Purpose</th>
                    <th>Created By</th>
                    <th>Expires</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                @php
                    $resItems       = $reservation->items;
                    $totalReserved  = $resItems->sum('reserved_quantity');
                    $totalDeployed  = $resItems->sum('deployed_quantity');
                    $totalRemaining = max(0, $totalReserved - $totalDeployed);
                    $whNames        = $resItems->map(fn($i) => $i->warehouse?->name)->filter()->unique()->implode(', ');
                    $itemDescs      = $resItems->map(fn($i) => $i->item?->description)->filter()->unique()->take(3);
                    $badgeClass     = match($reservation->status) {
                        'PENDING'              => 'badge-warning',
                        'RESERVED'             => 'badge-info',
                        'READY_FOR_REQUISITION'=> 'badge-primary',
                        'PARTIALLY_DEPLOYED'   => 'badge-warning',
                        'DEPLOYED'             => 'badge-success',
                        'CANCELLED','EXPIRED'  => 'badge-danger',
                        default                => 'badge-secondary',
                    };
                    $statusLabel = match($reservation->status) {
                        'PENDING'              => 'Pending',
                        'RESERVED'             => 'Reserved',
                        'READY_FOR_REQUISITION'=> 'Ready for RIS',
                        'PARTIALLY_DEPLOYED'   => 'Partially Deployed',
                        'DEPLOYED'             => 'Deployed',
                        'CANCELLED'            => 'Cancelled',
                        'EXPIRED'              => 'Expired',
                        default                => $reservation->status,
                    };
                @endphp
                <tr>
                    <td>
                        <strong style="color:var(--primary)">
                            {{ $reservation->reservation_number ?? '#' . $reservation->id }}
                        </strong>
                        <div style="font-size:11px;color:var(--text-muted)">
                            {{ $reservation->created_at->format('M d, Y') }}
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px">
                            @foreach($itemDescs as $desc)
                                <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px" title="{{ $desc }}">
                                    {{ $desc }}
                                </div>
                            @endforeach
                            @if($resItems->count() > 3)
                                <div style="font-size:11px;color:var(--text-muted)">+{{ $resItems->count() - 3 }} more</div>
                            @endif
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                            {{ $resItems->count() }} item(s)
                        </div>
                    </td>
                    <td style="font-size:12px">{{ $whNames ?: '—' }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($totalReserved) }}</td>
                    <td style="text-align:right;color:var(--success)">{{ number_format($totalDeployed) }}</td>
                    <td style="text-align:right;color:{{ $totalRemaining > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                        {{ $totalRemaining > 0 ? number_format($totalRemaining) : '✓' }}
                    </td>
                    <td><span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span></td>
                    <td style="font-size:12px">{{ \Illuminate\Support\Str::limit($reservation->purpose, 30) }}</td>
                    <td>{{ $reservation->creator->name ?? '—' }}</td>
                    <td style="font-size:12px">
                        @if($reservation->expires_at)
                            <span style="{{ $reservation->expires_at->isPast() ? 'color:var(--danger)' : '' }}">
                                {{ $reservation->expires_at->format('M d, Y') }}
                            </span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('reservations.show', $reservation) }}"
                           class="btn btn-sm btn-outline btn-icon" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted)">
                        <i class="fas fa-clipboard-list" style="font-size:32px;margin-bottom:8px;display:block"></i>
                        No reservations found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($reservations->hasPages())
    <div class="card-footer">{{ $reservations->links() }}</div>
    @endif
</div>

{{-- Create Reservation Modal --}}
@if(auth()->user()->canCreate())
@include('reservations._create_modal')
@endif
@endsection
