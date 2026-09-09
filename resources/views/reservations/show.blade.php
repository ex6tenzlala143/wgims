@extends('layouts.app')
@section('title', 'Reservation Details')
@section('page-title', 'Reservation Details')

@section('content')
<div class="page-header">
    <div>
        <h1>
            Reservation
            <code style="font-size:18px;color:var(--primary)">{{ $reservation->reservation_number ?? '#' . $reservation->id }}</code>
        </h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('reservations.index') }}">Reservations</a> /
            {{ $reservation->reservation_number ?? $reservation->id }}
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if(auth()->user()->canWrite() && $reservation->status === 'PENDING')
        <form action="{{ route('reservations.approve', $reservation) }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
        </form>
        @endif

        @if(auth()->user()->canWrite() && $reservation->status === 'RESERVED')
        <form action="{{ route('reservations.ready', $reservation) }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-primary"><i class="fas fa-clipboard-check"></i> Mark Ready for RIS</button>
        </form>
        @endif

        @if(auth()->user()->canWrite() && !in_array($reservation->status, ['DEPLOYED','CANCELLED','EXPIRED']))
        <form action="{{ route('reservations.cancel', $reservation) }}" method="POST" style="display:inline"
              onsubmit="return confirm('Cancel this reservation?\n\nAll reserved quantities will become available again. Deployed quantities are not affected.')">
            @csrf
            <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Cancel Reservation</button>
        </form>
        @endif

        @if(auth()->user()->isAdmin() && !in_array($reservation->status, ['DEPLOYED','CANCELLED','EXPIRED']) && $reservation->items->sum('deployed_quantity') == 0)
        <form action="{{ route('reservations.destroy', $reservation) }}" method="POST" style="display:inline"
              onsubmit="return confirm('Permanently delete reservation {{ $reservation->reservation_number ?? $reservation->id }}?\n\nThis cannot be undone. Use Cancel instead if you want to keep the history.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </form>
        @endif

        <a href="{{ route('reservations.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

{{-- Status banner --}}
<div style="margin-bottom:20px;padding:14px 20px;border-radius:8px;display:flex;align-items:center;gap:12px;
     background:{{ in_array($reservation->status,['DEPLOYED']) ? '#f0fff4' : (in_array($reservation->status,['CANCELLED','EXPIRED']) ? '#fff5f5' : '#f0f9ff') }};
     border:1px solid {{ in_array($reservation->status,['DEPLOYED']) ? '#9ae6b4' : (in_array($reservation->status,['CANCELLED','EXPIRED']) ? '#feb2b2' : '#90cdf4') }}">
    <span class="badge {{ $reservation->status_badge_class }}">
        {{ $reservation->status_label }}
    </span>
    <div style="font-size:13px;color:var(--text-muted)">
        @if($reservation->expires_at)
            Reservation expires: <strong>{{ $reservation->expires_at->format('M d, Y') }}</strong>
            @if($reservation->expires_at->isPast())
                <span style="color:var(--danger);margin-left:6px"><i class="fas fa-exclamation-triangle"></i> Past expiry</span>
            @endif
        @endif
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
    {{-- Info card --}}
    <div class="card">
        <div class="card-header"><h3>Reservation Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px">
                <div><span style="color:var(--text-muted)">Reservation No.</span><br>
                    <strong><code style="color:var(--primary)">{{ $reservation->reservation_number ?? '—' }}</code></strong>
                </div>
                <div><span style="color:var(--text-muted)">Status</span><br>
                    <span class="badge {{ $reservation->status_badge_class }}">{{ $reservation->status_label }}</span>
                </div>
                <div><span style="color:var(--text-muted)">Purpose</span><br>{{ $reservation->purpose ?: '—' }}</div>
                <div><span style="color:var(--text-muted)">Expiration Date</span><br>
                    {{ $reservation->expires_at ? $reservation->expires_at->format('M d, Y') : 'No expiration set' }}
                </div>
                <div><span style="color:var(--text-muted)">Created By</span><br>{{ $reservation->creator->name ?? '—' }}</div>
                <div><span style="color:var(--text-muted)">Created At</span><br>{{ $reservation->created_at->format('M d, Y H:i') }}</div>
                <div><span style="color:var(--text-muted)">Approved By</span><br>{{ $reservation->approver->name ?? '—' }}</div>
                <div><span style="color:var(--text-muted)">Last Updated</span><br>{{ $reservation->updated_at->format('M d, Y H:i') }}</div>
            </div>
            @if($reservation->notes)
            <div style="margin-top:14px;padding:10px;background:var(--surface-soft);border-radius:6px;font-size:13px">
                <strong>Notes:</strong> {{ $reservation->notes }}
            </div>
            @endif
            @if($reservation->intendedRequisition)
            <div style="margin-top:14px;padding:10px;background:#f0f9ff;border-radius:6px;font-size:13px;border:1px solid #90cdf4">
                <i class="fas fa-link"></i> Linked Requisition:
                <a href="{{ route('requisitions.show', $reservation->intendedRequisition) }}" style="font-weight:600">
                    {{ $reservation->intendedRequisition->ris_number }}
                </a>
            </div>
            @endif
        </div>
    </div>

    {{-- Totals card --}}
    <div class="card">
        <div class="card-header"><h3>Quantity Summary</h3></div>
        <div class="card-body">
            @php
                $totalReserved = $reservation->items->sum('reserved_quantity');
                $totalDeployed = $reservation->items->sum('deployed_quantity');
                $totalRemaining= max(0, $totalReserved - $totalDeployed);
                $pct = $totalReserved > 0 ? min(100, round($totalDeployed / $totalReserved * 100)) : 0;
            @endphp
            <div style="display:grid;gap:12px">
                <div style="padding:12px;background:var(--surface-soft);border-radius:8px;text-align:center">
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Total Reserved</div>
                    <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalReserved) }}</div>
                </div>
                <div style="padding:12px;background:#f0fff4;border-radius:8px;text-align:center">
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Total Deployed</div>
                    <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalDeployed) }}</div>
                </div>
                <div style="padding:12px;background:#fffff0;border-radius:8px;text-align:center;border:1px solid #faf089">
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Remaining</div>
                    <div style="font-size:22px;font-weight:800;color:{{ $totalRemaining > 0 ? 'var(--warning)' : 'var(--success)' }}">
                        {{ $totalRemaining > 0 ? number_format($totalRemaining) : '✓ Complete' }}
                    </div>
                </div>
            </div>
            @if($totalReserved > 0)
            <div style="margin-top:12px">
                <div style="background:var(--border);border-radius:999px;height:10px;overflow:hidden">
                    <div style="background:var(--success);width:{{ $pct }}%;height:100%;border-radius:999px"></div>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px">{{ $pct }}% deployed</div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Reserved Items Table --}}
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-boxes"></i> Reserved Items ({{ $reservation->items->count() }})</h3>
    </div>
    <div class="table-wrapper">
        <table style="table-layout:fixed;width:100%">
            <colgroup>
                <col style="width:11%">
                <col style="width:16%">
                <col style="width:5%">
                <col style="width:13%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:10%">
            </colgroup>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Warehouse</th>
                    <th style="text-align:right">Reserved</th>
                    <th style="text-align:right">Deployed</th>
                    <th style="text-align:right">Remaining</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th>Expiry</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservation->items as $ri)
                <tr style="{{ $ri->status === 'CANCELLED' ? 'opacity:.6' : '' }}">
                    <td style="font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <code style="color:var(--primary)">{{ $ri->item->stock_number ?? '—' }}</code>
                    </td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="{{ $ri->item->description ?? '—' }}">
                        {{ $ri->item->description ?? '—' }}
                    </td>
                    <td>{{ $ri->item->unit ?? '—' }}</td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="{{ $ri->warehouse->name ?? '—' }}">
                        {{ $ri->warehouse->name ?? '—' }}
                    </td>
                    <td style="text-align:right;font-weight:600">{{ number_format($ri->reserved_quantity) }}</td>
                    <td style="text-align:right;color:var(--success)">{{ number_format($ri->deployed_quantity) }}</td>
                    <td style="text-align:right;color:{{ $ri->remaining_quantity > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                        {{ $ri->remaining_quantity > 0 ? number_format($ri->remaining_quantity) : '✓' }}
                    </td>
                    <td style="text-align:right">{{ $ri->unit_cost ? '₱'.number_format($ri->unit_cost, 2) : '—' }}</td>
                    <td>{{ $ri->expiration_date ? $ri->expiration_date->format('M d, Y') : '—' }}</td>
                    <td>
                        <span class="badge {{ $ri->status_badge_class }}">
                            {{ $ri->status_label }}
                        </span>
                        @if(auth()->user()->canWrite()
                            && $ri->deployed_quantity == 0
                            && !in_array($reservation->status, ['DEPLOYED','CANCELLED','EXPIRED'])
                            && $reservation->items->count() > 1)
                        <form method="POST"
                              action="{{ route('reservations.items.destroy', [$reservation, $ri]) }}"
                              style="display:inline;margin-left:4px"
                              onsubmit="return confirm('Remove this item from the reservation?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Remove item">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>

                {{-- Dispatch history for this item --}}
                @if($ri->dispatchItems->isNotEmpty())
                <tr style="background:var(--surface-soft)">
                    <td colspan="10" style="padding:8px 20px;color:var(--text-muted)">
                        <strong>Deployment history:</strong>
                        @foreach($ri->dispatchItems as $di)
                            <span style="margin-right:12px">
                                <i class="fas fa-check-circle" style="color:var(--success)"></i>
                                {{ number_format($di->quantity_issued) }} units
                                via
                                @if($di->requisitionItem?->requisition)
                                    <a href="{{ route('requisitions.show', $di->requisitionItem->requisition) }}">
                                        {{ $di->requisitionItem->requisition->ris_number }}
                                    </a>
                                @else
                                    RIS
                                @endif
                                @if($di->dr_number) (DR# {{ $di->dr_number }}) @endif
                                · {{ $di->created_at?->format('M d, Y') }}
                            </span>
                        @endforeach
                    </td>
                </tr>
                @endif

                @empty
                <tr>
                    <td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">
                        No items in this reservation.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
