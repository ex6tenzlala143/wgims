@extends('layouts.app')
@section('title', 'Stock Card — ' . $item->description)
@section('page-title', 'Stock Card History')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $item->description }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('stock_cards.index', $item->category) }}">Stock Cards</a> /
            Item History
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <a href="{{ route('stock_cards.item_history_by_unit_cost', $item->id) }}" class="btn btn-secondary"><i class="fas fa-layer-group"></i> By Unit Cost (FIFO)</a>
        <a href="{{ route('stock_cards.print', $item->id) }}" class="btn btn-outline" target="_blank"><i class="fas fa-print"></i> Print Stock Card</a>
    </div>
</div>

<!-- Item Info -->
<div style="display:grid;grid-template-columns:repeat({{ auth()->user()->hasAdminAccess() && $item->engas_unit_cost !== null ? 5 : 4 }},1fr);gap:16px;margin-bottom:24px">
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Stock Number</div>
            <div style="font-size:16px;font-weight:700;margin-top:4px"><code>{{ $item->stock_number }}</code></div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Unit</div>
            <div style="font-size:16px;font-weight:700;margin-top:4px">{{ App\Models\Item::UNITS[$item->unit] ?? $item->unit }}</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Current Balance</div>
            <div style="font-size:24px;font-weight:800;color:var(--primary);margin-top:4px">{{ number_format($item->quantity, 2) }}</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Total Value</div>
            <div style="font-size:20px;font-weight:700;color:var(--success);margin-top:4px">₱{{ number_format($item->quantity * $item->unit_cost, 2) }}</div>
        </div>
    </div>
    @if(auth()->user()->hasAdminAccess() && $item->engas_unit_cost !== null)
    <div class="card" style="border:1px solid var(--primary)">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--primary);text-transform:uppercase;letter-spacing:1px;font-weight:600">Engas Unit Cost</div>
            <div style="font-size:20px;font-weight:700;color:var(--primary);margin-top:4px">₱{{ number_format($item->engas_unit_cost, 2) }}</div>
        </div>
    </div>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history" style="color:var(--primary)"></i> Movement History</h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $entries->count() }} entries</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>From/To</th>
                    <th style="text-align:right">Receipt Qty</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th style="text-align:right">Total Cost</th>
                    <th style="text-align:right">Issue Qty</th>
                    <th style="text-align:right">Balance Qty</th>
                    <th style="text-align:right">Balance Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($entries as $entry)
                <tr>
                    <td>{{ $entry->entry_date->format('M d, Y') }}</td>
                    <td><strong>{{ $entry->reference }}</strong></td>
                    <td>
                        @if($entry->reference_type == 'delivery')
                        <span class="badge badge-success"><i class="fas fa-arrow-down"></i> Receipt</span>
                        @elseif($entry->reference_type == 'issuance')
                        <span class="badge badge-warning"><i class="fas fa-arrow-up"></i> Issue</span>
                        @else
                        <span class="badge badge-secondary">Adjustment</span>
                        @endif
                    </td>
                    <td style="font-size:12px">{{ $entry->from_to ?? '—' }}</td>
                    <td style="text-align:right;color:var(--success)">{{ $entry->receipt_qty > 0 ? number_format($entry->receipt_qty, 2) : '—' }}</td>
                    <td style="text-align:right">{{ $entry->receipt_unit_cost > 0 ? '₱'.number_format($entry->receipt_unit_cost, 2) : '—' }}</td>
                    <td style="text-align:right">{{ $entry->receipt_total_cost > 0 ? '₱'.number_format($entry->receipt_total_cost, 2) : '—' }}</td>
                    <td style="text-align:right;color:var(--danger)">{{ $entry->issue_qty > 0 ? number_format($entry->issue_qty, 2) : '—' }}</td>
                    <td style="text-align:right"><strong>{{ number_format($entry->balance_qty, 2) }}</strong></td>
                    <td style="text-align:right">₱{{ number_format($entry->balance_total_cost, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">No movement history yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
