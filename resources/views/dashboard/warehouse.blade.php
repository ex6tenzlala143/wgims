@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@php
    $warehouseLabel = isset($assignedWarehouses) && $assignedWarehouses->count() > 1
        ? $assignedWarehouses->pluck('name')->implode(', ')
        : $warehouse->name;
@endphp

<div class="page-header">
    <div>
        <h1>Good {{ date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') }}, {{ auth()->user()->name }}!</h1>
        <div class="breadcrumb">{{ date('l, F j, Y') }} — {{ $warehouseLabel }}</div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-boxes"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_items']) }}</div>
            <div class="stat-label">Items in Warehouse</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_subsidies']) }}</div>
            <div class="stat-label">Delivery / Subsidies</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-clipboard-list"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['pending_ris']) }}</div>
            <div class="stat-label">Pending RIS</div>
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
@endpush
@endsection
