@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1>Good {{ date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') }}, {{ auth()->user()->name }}!</h1>
        <div class="breadcrumb">{{ date('l, F j, Y') }} — Administrator View</div>
    </div>
</div>

{{-- Stats grid --}}
<div class="stats-grid" style="margin-bottom:24px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-cubes"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_items']) }}</div>
            <div class="stat-label">Total Items</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-truck-loading"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_subsidies']) }}</div>
            <div class="stat-label">Delivery / Subsidies</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-clipboard-check"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['pending_ris']) }}</div>
            <div class="stat-label">Pending Requisitions</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-warehouse"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_warehouses']) }}</div>
            <div class="stat-label">Active Warehouses</div>
        </div>
    </div>
</div>

{{-- ── NEW: Requisitions/Augmentations Summary ──────────────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div>
        <div>
            <div class="stat-value">{{ number_format($totalRequisitionQty) }}</div>
            <div class="stat-label">Requisitions Qty</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalRequisitionAmt, 0) }}</div>
            <div class="stat-label">Requisitions Amount</div>
        </div>
    </div>
</div>

{{-- ── NEW: Subsidies Summary ──────────────────────────────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-truck"></i></div>
        <div>
            <div class="stat-value">{{ number_format($totalSubsidyQty) }}</div>
            <div class="stat-label">Subsidies Qty</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalSubsidyAmt, 0) }}</div>
            <div class="stat-label">Subsidies Amount</div>
        </div>
    </div>
</div>

{{-- ── NEW: Reservations Summary ────────────────────────────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-lock"></i></div>
        <div>
            <div class="stat-value">{{ number_format($totalReservedQty) }}</div>
            <div class="stat-label">Reservations Qty</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-tag"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalReservedAmt, 0) }}</div>
            <div class="stat-label">Reservations Amount</div>
        </div>
    </div>
</div>

{{-- ── NEW: Activity Chart ──────────────────────────────────────────────────── --}}
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Monthly Activity Comparison</h3>
        <span style="font-size:11px;color:var(--text-muted)">Last 12 months - comparing requisitions, subsidies, and reservations</span>
    </div>
    <div class="card-body" style="padding:12px">
        <div style="position:relative;height:180px">
            <canvas id="activityChart"></canvas>
        </div>
    </div>
</div>

{{-- ── Reservation Summary ──────────────────────────────────────────────── --}}
@include('dashboard._reservation_summary', ['reservationStats' => $reservationStats, 'recentReservations' => $recentReservations, 'reservedItems' => $reservedItems])

{{-- Unliquidated Delivery/Subsidies per Warehouse --}}
@if($unliquidated->count() > 0)
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-exclamation-triangle" style="color:var(--warning)"></i> Unliquidated Delivery/Subsidies per Warehouse</h3>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>RIS No.</th>
                        <th>Warehouse</th>
                        <th>Supplier</th>
                        <th>Date</th>
                        <th style="text-align:right">Amount (₱)</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($unliquidated->flatten() as $subsidy)
                    <tr>
                        <td><strong>{{ $subsidy->ris_number }}</strong></td>
                        <td>{{ $subsidy->warehouse->name ?? '-' }}</td>
                        <td>{{ $subsidy->supplier->name ?? '-' }}</td>
                        <td>{{ $subsidy->date ? $subsidy->date->format('M d, Y') : '-' }}</td>
                        <td style="text-align:right">₱{{ number_format($subsidy->total_amount, 2) }}</td>
                        <td><span class="badge {{ $subsidy->getStatusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $subsidy->status)) }}</span></td>
                        <td><a href="{{ route('delivery_subsidies.show', $subsidy->id) }}" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@section('scripts')
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('activityChart');
    if (!ctx) return;
    var chartData = @json($chartData);
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: chartData.datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { usePointStyle: true, padding: 20 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: function(value) { return Number(value).toLocaleString(); } }
                }
            },
            interaction: { intersect: false, mode: 'index' }
        }
    });
});
</script>
@endpush
@endsection
@endsection
