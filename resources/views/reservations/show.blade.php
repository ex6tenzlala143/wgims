@extends('layouts.app')
@section('title', 'Reservation Details')
@section('page-title', 'Reservation Details')

@section('content')
<div class="page-header">
    <div>
        <h1>Reservation #{{ $reservation->id }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('reservations.index') }}">Reservations</a> / #{{ $reservation->id }}</div>
    </div>
    <div style="display:flex;gap:8px">
        @if(auth()->user()->canWrite() && $reservation->status === 'PENDING')
        <form action="{{ route('reservations.approve', $reservation) }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
        </form>
        @endif
        @if(auth()->user()->canWrite() && $reservation->status === 'RESERVED')
        <form action="{{ route('reservations.ready', $reservation) }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-primary"><i class="fas fa-clipboard-check"></i> Mark Ready</button>
        </form>
        @endif
        @if(auth()->user()->canWrite() && !in_array($reservation->status, ['FULFILLED', 'CANCELLED', 'EXPIRED']))
        <form action="{{ route('reservations.cancel', $reservation) }}" method="POST" style="display:inline" onsubmit="return confirm('Cancel this reservation? Reserved quantity will become available again.')">
            @csrf
            <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Cancel</button>
        </form>
        @endif
        <a href="{{ route('reservations.index') }}" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div class="card">
        <div class="card-header"><h3>Reservation Information</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:12px">
                <tr><td style="padding:6px 0;color:var(--text-muted)">Status</td>
                    <td><span class="badge {{ $reservation->status === 'PENDING' ? 'badge-warning' : ($reservation->status === 'RESERVED' ? 'badge-info' : ($reservation->status === 'READY_FOR_REQUISITION' ? 'badge-primary' : ($reservation->status === 'FULFILLED' ? 'badge-success' : ($reservation->status === 'CANCELLED' || $reservation->status === 'EXPIRED' ? 'badge-danger' : ''))))) }}">{{ str_replace('_', ' ', $reservation->status) }}</span></td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Warehouse</td>
                    <td>{{ $reservation->warehouse->name ?? '-' }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Item</td>
                    <td>
                        <div style="font-weight:600">{{ $reservation->item->description ?? '-' }}</div>
                        <code style="font-size:11px;color:var(--text-muted)">{{ $reservation->item->stock_number ?? 'N/A' }}</code>
                    </td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Purpose</td>
                    <td>{{ $reservation->purpose ?: '-' }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Expiration</td>
                    <td>{{ $reservation->expires_at ? $reservation->expires_at->format('M d, Y') : 'No expiration' }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Notes</td>
                    <td>{{ $reservation->notes ?: '-' }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Quantity Details</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:12px">
                <tr><td style="padding:6px 0;color:var(--text-muted)">Physical Quantity</td>
                    <td style="text-align:right;font-size:18px;font-weight:700">{{ number_format($reservation->item->quantity ?? 0) }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Reserved Quantity</td>
                    <td style="text-align:right;font-size:18px;font-weight:700;color:var(--primary)">{{ number_format($reservation->reserved_quantity) }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Allocated Quantity</td>
                    <td style="text-align:right;font-size:18px;font-weight:700;color:var(--warning)">{{ number_format($reservation->allocated_quantity) }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Remaining Quantity</td>
                    <td style="text-align:right;font-size:18px;font-weight:700;color:var(--success)">{{ number_format($reservation->remaining_quantity) }}</td>
                </tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Available Quantity</td>
                    <td style="text-align:right;font-size:18px;font-weight:700">{{ number_format(($reservation->item->quantity ?? 0) - \App\Models\Reservation::reservedQuantityForItem($reservation->item_id)) }}</td>
                </tr>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:24px">
    <div class="card-header"><h3>Audit Information</h3></div>
    <div class="card-body">
        <table style="width:100%;font-size:12px">
            <tr><td style="padding:6px 0;color:var(--text-muted)">Created By</td>
                <td>{{ $reservation->creator->name ?? '-' }}</td>
            </tr>
            <tr><td style="padding:6px 0;color:var(--text-muted)">Created At</td>
                <td>{{ $reservation->created_at->format('M d, Y H:i') }}</td>
            </tr>
            <tr><td style="padding:6px 0;color:var(--text-muted)">Approved By</td>
                <td>{{ $reservation->approver->name ?? '-' }}</td>
            </tr>
            <tr><td style="padding:6px 0;color:var(--text-muted)">Last Updated</td>
                <td>{{ $reservation->updated_at->format('M d, Y H:i') }}</td>
            </tr>
            @if($reservation->intended_requisition_id)
            <tr><td style="padding:6px 0;color:var(--text-muted)">Linked Requisition</td>
                <td>
                    @if($reservation->intendedRequisition)
                    <a href="{{ route('requisitions.show', $reservation->intendedRequisition) }}">{{ $reservation->intendedRequisition->ris_number }}</a>
                    @else
                    #{{ $reservation->intended_requisition_id }}
                    @endif
                </td>
            </tr>
            @endif
        </table>
    </div>
</div>
@endsection
