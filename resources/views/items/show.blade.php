@extends('layouts.app')
@section('title', 'Item Details')
@section('page-title', 'Item Details')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $item->description }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('items.index') }}">Items</a> / View</div>
    </div>
    <div style="display:flex;gap:8px">
        @if($item->stock_number)
        <a href="{{ route('stock_cards.item_history', $item->id) }}" class="btn btn-primary"><i class="fas fa-book"></i> Stock Card</a>
        @endif
        @if(auth()->user()->canWrite())
        <a href="{{ route('items.edit', $item->id) }}" class="btn btn-secondary"><i class="fas fa-edit"></i> Edit</a>
        @endif
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div class="card">
        <div class="card-header"><h3>Item Information</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:14px">
                <tr><td style="padding:8px 0;color:var(--text-muted);width:40%">Stock Number</td>
                    <td>
                        @if($item->stock_number)
                            <code>{{ $item->stock_number }}</code>
                        @else
                            <span style="color:var(--text-muted);font-style:italic">Not yet assigned — will be set on first PO delivery</span>
                        @endif
                    </td>
                </tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Description</td><td><strong>{{ $item->description }}</strong></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">RIS No.</td><td>{{ $item->ris_number ?? '—' }}</td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Unit</td><td>{{ App\Models\Item::UNITS[$item->unit] ?? $item->unit }}</td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Category</td><td><span class="badge badge-info">{{ $item->getCategoryLabel() }}</span></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Account Code</td><td><span class="badge badge-primary">{{ $item->account_code }}</span></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Warehouse</td><td>{{ $item->warehouse->name ?? '—' }}</td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Stock Level</h3></div>
        <div class="card-body">
            <div style="text-align:center;padding:20px">
                <div style="font-size:48px;font-weight:800;color:{{ $item->quantity <= $item->reorder_point && $item->reorder_point > 0 ? 'var(--danger)' : 'var(--success)' }}">
                    {{ number_format($item->quantity, 2) }}
                </div>
                <div style="font-size:16px;color:var(--text-muted);margin-top:4px">{{ App\Models\Item::UNITS[$item->unit] ?? $item->unit }}</div>
                @if($item->quantity <= $item->reorder_point && $item->reorder_point > 0)
                <div class="badge badge-danger" style="margin-top:12px;font-size:12px"><i class="fas fa-exclamation-triangle"></i> Below Reorder Point</div>
                @endif
            </div>
            <table style="width:100%;font-size:14px;margin-top:16px">
                <tr><td style="padding:8px 0;color:var(--text-muted)">Current Quantity</td><td style="text-align:right"><strong>{{ number_format($item->quantity, 2) }}</strong></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Unit Cost</td><td style="text-align:right">₱{{ number_format($item->unit_cost, 2) }}</td></tr>
                @if(auth()->user()->hasAdminAccess() && $item->engas_unit_cost !== null)
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Engas Unit Cost</td>
                    <td style="text-align:right">
                        <span style="font-weight:600;color:var(--primary)">₱{{ number_format($item->engas_unit_cost, 2) }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Engas Total Value</td>
                    <td style="text-align:right">
                        <strong style="color:var(--primary)">₱{{ number_format($item->quantity * $item->engas_unit_cost, 2) }}</strong>
                    </td>
                </tr>
                @endif
                <tr><td style="padding:8px 0;color:var(--text-muted)">Total Value</td><td style="text-align:right"><strong>₱{{ number_format($item->quantity * $item->unit_cost, 2) }}</strong></td></tr>
                <tr><td style="padding:8px 0;color:var(--text-muted)">Reorder Point</td><td style="text-align:right">{{ number_format($item->reorder_point, 2) }}</td></tr>
            </table>
        </div>
    </div>
</div>

<!-- Recent stock card entries -->
@if($item->stockCardEntries->count() > 0)
<div class="card" style="margin-top:24px">
    <div class="card-header">
        <h3>Recent Stock Movements</h3>
        <a href="{{ route('stock_cards.item_history', $item->id) }}" class="btn btn-sm btn-outline">View Full History</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Date</th><th>Reference</th><th>Type</th><th style="text-align:right">Receipt Qty</th><th style="text-align:right">Issue Qty</th><th style="text-align:right">Balance</th></tr>
            </thead>
            <tbody>
                @foreach($item->stockCardEntries->take(10) as $entry)
                <tr>
                    <td>{{ $entry->entry_date->format('M d, Y') }}</td>
                    <td>{{ $entry->reference }}</td>
                    <td>
                        @if($entry->reference_type == 'delivery')
                        <span class="badge badge-success">Receipt</span>
                        @elseif($entry->reference_type == 'issuance')
                        <span class="badge badge-warning">Issue</span>
                        @else
                        <span class="badge badge-secondary">Adjustment</span>
                        @endif
                    </td>
                    <td style="text-align:right">{{ $entry->receipt_qty > 0 ? number_format($entry->receipt_qty, 2) : '—' }}</td>
                    <td style="text-align:right">{{ $entry->issue_qty > 0 ? number_format($entry->issue_qty, 2) : '—' }}</td>
                    <td style="text-align:right"><strong>{{ number_format($entry->balance_qty, 2) }}</strong></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
