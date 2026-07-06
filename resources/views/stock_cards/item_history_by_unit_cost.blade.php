@extends('layouts.app')
@section('title', 'Stock Card by Unit Cost')
@section('page-title', 'Stock Card by Unit Cost (FIFO)')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $item->description }} — FIFO Batches</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('stock_cards.index', $item->category) }}">Stock Cards</a> /
            <a href="{{ route('stock_cards.item_history', $item->id) }}">History</a> /
            By Unit Cost
        </div>
    </div>
    <a href="{{ route('stock_cards.item_history', $item->id) }}" class="btn btn-secondary"><i class="fas fa-history"></i> Full History</a>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Stock Number</div>
            <div style="font-size:16px;font-weight:700;margin-top:4px"><code>{{ $item->stock_number }}</code></div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Current Balance</div>
            <div style="font-size:24px;font-weight:800;color:var(--primary);margin-top:4px">{{ number_format($item->quantity, 2) }}</div>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="text-align:center">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Total Batches</div>
            <div style="font-size:24px;font-weight:800;color:var(--info);margin-top:4px">{{ count($batches) }}</div>
        </div>
    </div>
</div>

@forelse($batches as $idx => $batch)
<div class="card" style="margin-bottom:16px">
    <div class="card-header" style="background:{{ $batch['depleted'] ? '#f7fafc' : '#ebf4ff' }}">
        <div style="display:flex;align-items:center;gap:12px">
            <span style="font-weight:700;font-size:15px">Batch #{{ $idx + 1 }}</span>
            <span class="badge {{ $batch['depleted'] ? 'badge-secondary' : 'badge-success' }}">
                {{ $batch['depleted'] ? 'Depleted' : 'Active' }}
            </span>
            <span style="font-size:13px;color:var(--text-muted)">PO: {{ $batch['reference'] }} — {{ $batch['date'] ? $batch['date']->format('M d, Y') : '—' }}</span>
        </div>
        <div style="display:flex;gap:20px;font-size:13px">
            <div><span style="color:var(--text-muted)">Unit Cost:</span> <strong>₱{{ number_format($batch['unit_cost'], 2) }}</strong></div>
            <div><span style="color:var(--text-muted)">Original Qty:</span> <strong>{{ number_format($batch['original_qty'], 2) }}</strong></div>
            <div><span style="color:var(--text-muted)">Remaining:</span> <strong style="color:{{ $batch['depleted'] ? 'var(--text-muted)' : 'var(--success)' }}">{{ number_format($batch['remaining_qty'], 2) }}</strong></div>
        </div>
    </div>
    @if(count($batch['movements']) > 0)
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th style="text-align:right">Qty Issued from Batch</th>
                </tr>
            </thead>
            <tbody>
                @foreach($batch['movements'] as $mv)
                <tr>
                    <td>{{ $mv['date'] ? $mv['date']->format('M d, Y') : '—' }}</td>
                    <td>{{ $mv['reference'] }}</td>
                    <td><span class="badge badge-warning">Issue</span></td>
                    <td style="text-align:right">{{ number_format($mv['qty'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="card-body" style="color:var(--text-muted);font-size:13px">No issues from this batch yet.</div>
    @endif
</div>
@empty
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
        No receipt batches found for this item.
    </div>
</div>
@endforelse
@endsection
