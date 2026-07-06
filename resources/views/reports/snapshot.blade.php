@extends('layouts.app')
@section('title', 'Snapshot — ' . $snapshot->period_month)
@section('page-title', 'Report Snapshot')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ strtoupper($snapshot->report_type) }} Snapshot — {{ $snapshot->period_month }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Reports / Snapshot</div>
    </div>
    <button onclick="window.print()" class="btn btn-outline no-print"><i class="fas fa-print"></i> Print</button>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="font-size:14px;display:flex;gap:24px">
        <div><span style="color:var(--text-muted)">Report Type:</span> <strong>{{ strtoupper($snapshot->report_type) }}</strong></div>
        <div><span style="color:var(--text-muted)">Period:</span> <strong>{{ $snapshot->period_month }}</strong></div>
        <div><span style="color:var(--text-muted)">Warehouse:</span> <strong>{{ $snapshot->warehouse->name ?? 'All Warehouses' }}</strong></div>
        <div><span style="color:var(--text-muted)">Serial No.:</span> <strong>{{ $snapshot->serial_number ?? '-' }}</strong></div>
        <div><span style="color:var(--text-muted)">Saved by:</span> <strong>{{ $snapshot->creator->name ?? '-' }}</strong></div>
        <div><span style="color:var(--text-muted)">Saved on:</span> <strong>{{ $snapshot->created_at->format('M d, Y') }}</strong></div>
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        @if($snapshot->report_type == 'rpci')
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Category</th>
                    <th>Warehouse</th>
                    <th style="text-align:right">Qty</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th style="text-align:right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                @php $total = 0; @endphp
                @foreach($data as $row)
                <tr>
                    <td><code>{{ $row['stock_number'] }}</code></td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['unit'] }}</td>
                    <td>{{ App\Models\Item::getCategories()[$row['category']]['label'] ?? $row['category'] }}</td>
                    <td>{{ $row['warehouse'] }}</td>
                    <td style="text-align:right">{{ number_format($row['quantity'], 2) }}</td>
                    <td style="text-align:right">₱{{ number_format($row['unit_cost'], 2) }}</td>
                    <td style="text-align:right">₱{{ number_format($row['total_value'], 2) }}</td>
                </tr>
                @php $total += $row['total_value']; @endphp
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700">
                    <td colspan="7" style="text-align:right">TOTAL:</td>
                    <td style="text-align:right">₱{{ number_format($total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        @else
        <table>
            <thead>
                <tr>
                    <th>RIS No.</th>
                    <th>Warehouse Code</th>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th style="text-align:right">Qty Issued</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th style="text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @php $total = 0; @endphp
                @foreach($data as $row)
                <tr>
                    <td>{{ $row['ris_number'] }}</td>
                    <td>{{ $row['warehouse_code'] }}</td>
                    <td><code>{{ $row['stock_number'] }}</code></td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['unit'] }}</td>
                    <td style="text-align:right">{{ number_format($row['quantity_issued'], 2) }}</td>
                    <td style="text-align:right">₱{{ number_format($row['unit_cost'], 2) }}</td>
                    <td style="text-align:right">₱{{ number_format($row['amount'], 2) }}</td>
                </tr>
                @php $total += $row['amount']; @endphp
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700">
                    <td colspan="7" style="text-align:right">TOTAL:</td>
                    <td style="text-align:right">₱{{ number_format($total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
@endsection
