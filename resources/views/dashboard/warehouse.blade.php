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

{{-- ── Row 1: on-hand inventory (assigned warehouses) ───────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-hashtag"></i></div>
        <div>
            <div class="stat-value">{{ number_format($inventoryNamesCount) }}</div>
            <div class="stat-label">Total Items</div>
        </div>
    </div>
    <details class="stat-card stat-drop">
        <summary>
            <div class="stat-icon blue"><i class="fas fa-cubes"></i></div>
            <div>
                <div class="stat-value">{{ number_format($inventoryQty) }}</div>
                <div class="stat-label">Total Items Quantity</div>
            </div>
        </summary>
        <div class="stat-drop-panel">
            @forelse($inventoryByItem as $row)
            <div class="stat-drop-row"><span>{{ $row->description }}</span><strong>{{ number_format($row->qty) }}{{ $row->unit ? ' ' . $row->unit : '' }}</strong></div>
            @empty
            <div class="stat-drop-empty">No items</div>
            @endforelse
        </div>
    </details>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($inventoryAmt, 0) }}</div>
            <div class="stat-label">Total Items Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-tag"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($inventoryEngas, 0) }}</div>
            <div class="stat-label">Total Items ENGAS Value</div>
        </div>
    </div>
</div>

{{-- ── Row 2: subsidies (count IDs) + delivered ─────────────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-hashtag"></i></div>
        <div>
            <div class="stat-value">{{ number_format($stats['total_subsidies']) }}</div>
            <div class="stat-label">Total Subsidies</div>
        </div>
    </div>
    <details class="stat-card stat-drop">
        <summary>
            <div class="stat-icon green"><i class="fas fa-truck"></i></div>
            <div>
                <div class="stat-value">{{ number_format($totalSubsidyQty) }}</div>
                <div class="stat-label">Delivered Quantity</div>
            </div>
        </summary>
        <div class="stat-drop-panel">
            @forelse($deliveredByItem as $row)
            <div class="stat-drop-row"><span>{{ $row->description }}</span><strong>{{ number_format($row->qty) }}{{ $row->unit ? ' ' . $row->unit : '' }}</strong></div>
            @empty
            <div class="stat-drop-empty">No deliveries</div>
            @endforelse
        </div>
    </details>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalSubsidyAmt, 0) }}</div>
            <div class="stat-label">Delivered Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-tags"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalSubsidyEngas, 0) }}</div>
            <div class="stat-label">Delivered ENGAS Value</div>
        </div>
    </div>
</div>

{{-- ── Row 3: requisitions (count RIS IDs) + issued ─────────────────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-hashtag"></i></div>
        <div>
            <div class="stat-value">{{ number_format($totalRequisitionsCount) }}</div>
            <div class="stat-label">Total Requisitions</div>
        </div>
    </div>
    <details class="stat-card stat-drop">
        <summary>
            <div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div>
            <div>
                <div class="stat-value">{{ number_format($totalRequisitionQty) }}</div>
                <div class="stat-label">Issued Quantity</div>
            </div>
        </summary>
        <div class="stat-drop-panel">
            @forelse($issuedByItem as $row)
            <div class="stat-drop-row"><span>{{ $row->description }}</span><strong>{{ number_format($row->qty) }}{{ $row->unit ? ' ' . $row->unit : '' }}</strong></div>
            @empty
            <div class="stat-drop-empty">No issuances</div>
            @endforelse
        </div>
    </details>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalRequisitionAmt, 0) }}</div>
            <div class="stat-label">Issued Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-tags"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalRequisitionEngas, 0) }}</div>
            <div class="stat-label">Issued ENGAS Value</div>
        </div>
    </div>
</div>

{{-- ── Row 4: reservations (count nos.) + reserved (remaining) ──────────────── --}}
<div class="stats-grid" style="margin-bottom:12px">
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-hashtag"></i></div>
        <div>
            <div class="stat-value">{{ number_format($reservationStats['total']) }}</div>
            <div class="stat-label">Total Reservations</div>
        </div>
    </div>
    <details class="stat-card stat-drop">
        <summary>
            <div class="stat-icon yellow"><i class="fas fa-lock"></i></div>
            <div>
                <div class="stat-value">{{ number_format($totalReservedQty) }}</div>
                <div class="stat-label">Reserved Quantity</div>
            </div>
        </summary>
        <div class="stat-drop-panel">
            @forelse($reservedByItem as $row)
            <div class="stat-drop-row"><span>{{ $row->description }}</span><strong>{{ number_format($row->qty) }}{{ $row->unit ? ' ' . $row->unit : '' }}</strong></div>
            @empty
            <div class="stat-drop-empty">No reservations</div>
            @endforelse
        </div>
    </details>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-tag"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalReservedAmt, 0) }}</div>
            <div class="stat-label">Reserved Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-tags"></i></div>
        <div>
            <div class="stat-value">₱{{ number_format($totalReservedEngas, 0) }}</div>
            <div class="stat-label">Reserved ENGAS Value</div>
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
        <div style="position:relative;height:320px">
            <canvas id="activityChart"></canvas>
        </div>
    </div>
</div>

@section('scripts')
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('activityChart');
    if (!ctx) return;
    var chartData = @json($chartData);
    new Chart(ctx, {
        type: 'bar',
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
