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
    <a href="{{ route('reservations.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Reservation</a>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            <div class="search-row">
                <select name="status" class="form-control" style="width:auto">
                    <option value="">All Statuses</option>
                    <option value="PENDING" {{ request('status')=='PENDING'?'selected':'' }}>Pending</option>
                    <option value="RESERVED" {{ request('status')=='RESERVED'?'selected':'' }}>Reserved</option>
                    <option value="READY_FOR_REQUISITION" {{ request('status')=='READY_FOR_REQUISITION'?'selected':'' }}>Ready for Requisition</option>
                    <option value="ALLOCATED" {{ request('status')=='ALLOCATED'?'selected':'' }}>Allocated</option>
                    <option value="FULFILLED" {{ request('status')=='FULFILLED'?'selected':'' }}>Fulfilled</option>
                    <option value="CANCELLED" {{ request('status')=='CANCELLED'?'selected':'' }}>Cancelled</option>
                    <option value="EXPIRED" {{ request('status')=='EXPIRED'?'selected':'' }}>Expired</option>
                </select>
                @if(auth()->user()->hasAdminAccess())
                <select name="warehouse_id" class="form-control" style="width:auto">
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
                    <th>Item</th>
                    <th>Warehouse</th>
                    <th style="text-align:right">Physical Qty</th>
                    <th style="text-align:right">Reserved Qty</th>
                    <th style="text-align:right">Available Qty</th>
                    <th>Status</th>
                    <th>Purpose</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                <tr>
                    <td>
                        <div style="font-weight:600">{{ $reservation->item->description ?? '-' }}</div>
                        <code style="font-size:11px;color:var(--text-muted)">{{ $reservation->item->stock_number ?? 'N/A' }}</code>
                    </td>
                    <td>{{ $reservation->warehouse->name ?? '-' }}</td>
                    <td style="text-align:right">{{ number_format($reservation->item->quantity ?? 0) }}</td>
                    <td style="text-align:right;font-weight:600;color:var(--primary)">{{ number_format($reservation->reserved_quantity) }}</td>
                    <td style="text-align:right">{{ number_format(($reservation->item->quantity ?? 0) - $reservation->reserved_quantity) }}</td>
                    <td>
                        @php
                            $badgeClass = match($reservation->status) {
                                'PENDING' => 'badge-warning',
                                'RESERVED' => 'badge-info',
                                'READY_FOR_REQUISITION' => 'badge-primary',
                                'FULFILLED' => 'badge-success',
                                'CANCELLED', 'EXPIRED' => 'badge-danger',
                                default => '',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ str_replace('_', ' ', $reservation->status) }}</span>
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($reservation->purpose, 30) }}</td>
                    <td>{{ $reservation->creator->name ?? '-' }}</td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('reservations.show', $reservation) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-clipboard-list" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    No reservations found.
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($reservations->hasPages())
    <div class="card-footer">{{ $reservations->links() }}</div>
    @endif
</div>
@endsection
