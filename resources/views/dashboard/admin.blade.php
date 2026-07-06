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
            <div class="stat-value">{{ number_format($stats['total_pos']) }}</div>
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

<!-- Inventory Balances per Warehouse -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-balance-scale" style="color:var(--primary)"></i> Inventory Balances per Warehouse</h3>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table class="balance-table">
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Account Code</th>
                        <th>Category</th>
                        <th style="text-align:right">Total Qty</th>
                        <th style="text-align:right">Total Value (₱)</th>
                    </tr>
                </thead>
                <tbody>
                    @php $grandTotal = 0; @endphp
                    @foreach($balances as $b)
                        @if(count($b['account_balances']) > 0)
                        <tr class="warehouse-row">
                            <td colspan="5">{{ $b['warehouse']->name }} ({{ $b['warehouse']->code }})</td>
                        </tr>
                        @foreach($b['account_balances'] as $ab)
                        <tr>
                            <td></td>
                            <td><span class="badge badge-primary">{{ $ab['account_code'] }}</span></td>
                            <td>{{ $ab['label'] }}</td>
                            <td style="text-align:right">{{ number_format($ab['total_qty'], 2) }}</td>
                            <td style="text-align:right">₱{{ number_format($ab['total_value'], 2) }}</td>
                        </tr>
                        @endforeach
                        @php $grandTotal += $b['grand_total']; @endphp
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:600">Warehouse Total:</td>
                            <td style="text-align:right;font-weight:700">₱{{ number_format($b['grand_total'], 2) }}</td>
                        </tr>
                        @endif
                    @endforeach
                    <tr class="total-row">
                        <td colspan="4" style="text-align:right;font-size:15px">GRAND TOTAL:</td>
                        <td style="text-align:right;font-size:15px">₱{{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Unliquidated POs per Warehouse -->
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
                    @foreach($unliquidated->flatten() as $po)
                    <tr>
                        <td><strong>{{ $po->ris_number }}</strong></td>
                        <td>{{ $po->warehouse->name ?? '-' }}</td>
                        <td>{{ $po->supplier->name ?? '-' }}</td>
                        <td>{{ $po->date ? $po->date->format('M d, Y') : '-' }}</td>
                        <td style="text-align:right">₱{{ number_format($po->total_amount, 2) }}</td>
                        <td><span class="badge {{ $po->getStatusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</span></td>
                        <td><a href="{{ route('delivery_subsidies.show', $po->id) }}" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection
