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
            <div class="stat-value">{{ number_format($stats['total_pos']) }}</div>
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

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-balance-scale" style="color:var(--primary)"></i> {{ $warehouseLabel }} — Inventory Balance</h3>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Account Code</th>
                        <th>Category</th>
                        <th style="text-align:right">Total Qty</th>
                        <th style="text-align:right">Unit Cost (₱)</th>
                        <th style="text-align:right">Total Value (₱)</th>
                    </tr>
                </thead>
                <tbody>
                    @php $total = 0; @endphp
                    @forelse($accountBalances as $ab)
                    @php $unitCost = $ab['total_qty'] > 0 ? $ab['total_value'] / $ab['total_qty'] : 0; @endphp
                    <tr>
                        <td><span class="badge badge-primary">{{ $ab['account_code'] }}</span></td>
                        <td>{{ $ab['label'] }}</td>
                        <td style="text-align:right">{{ number_format($ab['total_qty'], 2) }}</td>
                        <td style="text-align:right">₱{{ number_format($unitCost, 2) }}</td>
                        <td style="text-align:right">₱{{ number_format($ab['total_value'], 2) }}</td>
                    </tr>
                    @php $total += $ab['total_value']; @endphp
                    @empty
                    <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--text-muted)">No inventory data yet.</td></tr>
                    @endforelse
                    @if(count($accountBalances) > 0)
                    <tr style="background:#f0fff4;font-weight:700">
                        <td colspan="4" style="text-align:right">TOTAL:</td>
                        <td style="text-align:right">₱{{ number_format($total, 2) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
