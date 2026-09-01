{{--
    Reservation summary partial — included by both admin and warehouse dashboards.
    Variables expected:
      $reservationStats    — array from DashboardController::getReservationStats()
      $recentReservations  — collection from DashboardController::getRecentReservations()
      $reservedItems       — collection from DashboardController::getReservedItemsSummary()
--}}

{{-- ── Reservation Stats Cards ──────────────────────────────────────────── --}}
<div style="margin-bottom:8px;display:flex;align-items:center;gap:8px">
    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted)">
        Reservations
    </span>
    <a href="{{ route('reservations.index') }}" style="font-size:11px;color:var(--primary)">View all →</a>
</div>

<div class="stats-grid" style="margin-bottom:24px">
    {{-- Active --}}
    <a href="{{ route('reservations.index', ['status' => 'RESERVED']) }}"
       class="stat-card" style="text-decoration:none;color:inherit">
        <div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div>
        <div>
            <div class="stat-value">{{ number_format($reservationStats['active']) }}</div>
            <div class="stat-label">Active Reservations</div>
        </div>
    </a>

    {{-- Partially deployed --}}
    <a href="{{ route('reservations.index', ['status' => 'PARTIALLY_DEPLOYED']) }}"
       class="stat-card" style="text-decoration:none;color:inherit">
        <div class="stat-icon yellow"><i class="fas fa-hourglass-half"></i></div>
        <div>
            <div class="stat-value">{{ number_format($reservationStats['partially_deployed']) }}</div>
            <div class="stat-label">Partially Deployed</div>
        </div>
    </a>

    {{-- Deployed --}}
    <a href="{{ route('reservations.index', ['status' => 'DEPLOYED']) }}"
       class="stat-card" style="text-decoration:none;color:inherit">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="stat-value">{{ number_format($reservationStats['deployed']) }}</div>
            <div class="stat-label">Deployed</div>
        </div>
    </a>

    {{-- Total reserved quantity --}}
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-lock"></i></div>
        <div>
            <div class="stat-value">{{ number_format($reservationStats['total_reserved']) }}</div>
            <div class="stat-label">Total Reserved Units</div>
        </div>
    </div>

    {{-- Near expiry alert --}}
    @if($reservationStats['near_expiry'] > 0 || $reservationStats['expired'] > 0)
    <a href="{{ route('reservations.index', ['status' => 'EXPIRED']) }}"
       class="stat-card" style="text-decoration:none;color:inherit;border-color:var(--warning)">
        <div class="stat-icon yellow"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
            <div class="stat-value" style="color:var(--warning)">
                {{ number_format($reservationStats['near_expiry'] + $reservationStats['expired']) }}
            </div>
            <div class="stat-label">Expiring / Expired</div>
        </div>
    </a>
    @endif
</div>

{{-- ── Two-column: Reserved Items Impact + Recent Reservations ─────────── --}}
@if($reservedItems->isNotEmpty() || $recentReservations->isNotEmpty())
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    {{-- Reserved Items Impact --}}
    @if($reservedItems->isNotEmpty())
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-lock" style="color:var(--warning)"></i> Reservation Impact on Inventory</h3>
            <span style="font-size:11px;color:var(--text-muted)">Items with active reservations</span>
        </div>
        <div class="table-wrapper">
            <table style="font-size:12px">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Warehouse</th>
                        <th style="text-align:right">On Hand</th>
                        <th style="text-align:right">Reserved</th>
                        <th style="text-align:right">Available</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reservedItems as $ri)
                    <tr>
                        <td>
                            <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px"
                                 title="{{ $ri['description'] }}">{{ $ri['description'] }}</div>
                            @if($ri['stock_number'])
                            <code style="font-size:10px;color:var(--text-muted)">{{ $ri['stock_number'] }}</code>
                            @endif
                        </td>
                        <td style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px"
                            title="{{ $ri['warehouse'] }}">{{ $ri['warehouse'] }}</td>
                        <td style="text-align:right">{{ number_format($ri['physical_qty']) }}</td>
                        <td style="text-align:right;color:var(--warning);font-weight:600">
                            {{ number_format($ri['reserved_qty']) }}
                        </td>
                        <td style="text-align:right;color:{{ $ri['available_qty'] > 0 ? 'var(--success)' : 'var(--danger)' }};font-weight:600">
                            {{ number_format($ri['available_qty']) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:8px 14px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted)">
            Available = On Hand − Reserved. Only Available units can be used by a normal RIS.
        </div>
    </div>
    @endif

    {{-- Recent Reservations --}}
    @if($recentReservations->isNotEmpty())
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history" style="color:var(--primary)"></i> Recent Reservations</h3>
            <a href="{{ route('reservations.index') }}" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div style="padding:0">
            @foreach($recentReservations as $res)
            @php
                $statusColors = [
                    'PENDING'              => 'var(--warning)',
                    'RESERVED'             => 'var(--primary)',
                    'READY_FOR_REQUISITION'=> 'var(--info)',
                    'PARTIALLY_DEPLOYED'   => 'var(--warning)',
                    'DEPLOYED'             => 'var(--success)',
                    'CANCELLED'            => 'var(--danger)',
                    'EXPIRED'              => 'var(--danger)',
                ];
                $dotColor = $statusColors[$res['status']] ?? 'var(--text-muted)';
            @endphp
            <a href="{{ route('reservations.show', $res['id']) }}"
               style="display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .15s"
               onmouseover="this.style.background='var(--surface-hover)'"
               onmouseout="this.style.background=''">
                <div style="display:flex;justify-content:space-between;align-items:start;gap:8px">
                    <div style="min-width:0">
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="width:8px;height:8px;border-radius:50%;background:{{ $dotColor }};flex-shrink:0;display:inline-block"></span>
                            <strong style="font-size:13px;color:var(--primary)">{{ $res['reservation_number'] }}</strong>
                            <span class="badge {{ $res['status_badge'] }}" style="font-size:10px">{{ $res['status_label'] }}</span>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                            {{ $res['created_at']->format('M d, Y') }}
                            @if($res['purpose']) · {{ \Illuminate\Support\Str::limit($res['purpose'], 30) }} @endif
                        </div>
                        <div style="font-size:11px;margin-top:3px;color:var(--text-muted)">
                            {{ $res['item_count'] }} item(s) · {{ $res['warehouses'] }}
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;font-size:11px">
                        <div><span style="color:var(--warning)">Reserved: <strong>{{ number_format($res['total_reserved']) }}</strong></span></div>
                        @if($res['total_deployed'] > 0)
                        <div><span style="color:var(--success)">Deployed: {{ number_format($res['total_deployed']) }}</span></div>
                        @endif
                        @if($res['total_remaining'] > 0)
                        <div>Remaining: {{ number_format($res['total_remaining']) }}</div>
                        @endif
                    </div>
                </div>
                @if($res['expires_at'] && $res['expires_at']->isFuture() && $res['expires_at']->diffInDays(now()) <= 7)
                <div style="margin-top:6px;padding:4px 8px;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;font-size:10px;color:#92400e">
                    <i class="fas fa-exclamation-triangle"></i>
                    Expires {{ $res['expires_at']->diffForHumans() }}
                </div>
                @elseif($res['expires_at'] && $res['expires_at']->isPast())
                <div style="margin-top:6px;padding:4px 8px;background:#fff5f5;border:1px solid #feb2b2;border-radius:4px;font-size:10px;color:var(--danger)">
                    <i class="fas fa-clock"></i> Expired {{ $res['expires_at']->diffForHumans() }}
                </div>
                @endif
            </a>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endif

{{-- ── Attention Banner (expired/near-expiry) ──────────────────────────── --}}
@if($reservationStats['near_expiry'] > 0 || $reservationStats['expired'] > 0)
<div style="margin-bottom:24px;padding:14px 18px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;display:flex;align-items:center;gap:12px">
    <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:18px;flex-shrink:0"></i>
    <div style="font-size:13px;color:#92400e">
        @if($reservationStats['expired'] > 0)
            <strong>{{ $reservationStats['expired'] }} reservation(s) have expired</strong> with unreleased quantities.
        @endif
        @if($reservationStats['near_expiry'] > 0)
            @if($reservationStats['expired'] > 0) &nbsp;·&nbsp; @endif
            <strong>{{ $reservationStats['near_expiry'] }} reservation(s)</strong> expire within 7 days.
        @endif
        &nbsp;
        <a href="{{ route('reservations.index') }}" style="color:#92400e;font-weight:700;text-decoration:underline">Review reservations →</a>
    </div>
</div>
@endif
