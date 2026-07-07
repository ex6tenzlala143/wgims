@extends('layouts.app')
@section('title', $categoryInfo['label'])
@section('page-title', 'Stock Cards')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $categoryInfo['label'] }}</h1>
    </div>
</div>

{{-- Category tabs removed --}}

@if(auth()->user()->hasAdminAccess())
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:12px 16px">
        <form method="GET" style="display:flex;gap:12px;align-items:center">
            <label style="font-size:13px;font-weight:600">Filter by Warehouse:</label>
            <select name="warehouse_id" class="form-control" style="width:auto;min-width:200px" onchange="this.form.submit()">
                <option value="">All Warehouses</option>
                @foreach($warehouses as $c)
                <option value="{{ $c->id }}" {{ request('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h3>
            <span class="badge badge-primary">{{ $categoryInfo['account_code'] }}</span>
            {{ $categoryInfo['label'] }} — Stock Summary
        </h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $items->count() }} item(s)</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    @if(auth()->user()->hasAdminAccess())<th>Warehouse</th>@endif
                    <th style="text-align:right">Total Received</th>
                    <th style="text-align:right">Total Issued</th>
                    <th style="text-align:right">Current Balance</th>
                    <th style="text-align:right">Unit Cost</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Total Value</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @php $grandBalance = 0; $grandValue = 0; @endphp
                @forelse($items as $item)
                @php
                    $totalReceived = $item->total_received ?? 0;
                    $totalIssued = $item->total_issued ?? 0;
                    $grandBalance += $item->quantity;
                    $grandValue += $item->quantity * $item->unit_cost;
                @endphp
                <tr>
                    <td><code style="font-size:12px">{{ $item->stock_number }}</code></td>
                    <td><strong>{{ $item->description }}</strong>@if($item->ris_number)<br><small style="color:var(--text-muted)">{{ $item->ris_number }}</small>@endif</td>
                    <td>{{ $item->unit }}</td>
                    @if(auth()->user()->hasAdminAccess())<td>{{ $item->warehouse->name ?? '-' }}</td>@endif
                    <td style="text-align:right">{{ number_format($totalReceived, 2) }}</td>
                    <td style="text-align:right">{{ number_format($totalIssued, 2) }}</td>
                    <td style="text-align:right">
                        <strong class="{{ $item->quantity <= $item->reorder_point && $item->reorder_point > 0 ? 'badge badge-danger' : '' }}">
                            {{ number_format($item->quantity, 2) }}
                        </strong>
                    </td>
                    <td style="text-align:right">₱{{ number_format($item->unit_cost, 2) }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right">
                        @if($item->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱{{ number_format($item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        @if($item->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱{{ number_format($item->quantity * $item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif
                    <td style="text-align:right">₱{{ number_format($item->quantity * $item->unit_cost, 2) }}</td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('stock_cards.item_history', $item->id) }}" class="btn btn-sm btn-primary btn-icon" title="View History"><i class="fas fa-history"></i></a>
                            <a href="{{ route('stock_cards.item_history_by_unit_cost', $item->id) }}" class="btn btn-sm btn-outline btn-icon" title="By Unit Cost"><i class="fas fa-layer-group"></i></a>
                            <a href="{{ route('stock_cards.print', $item->id) }}" class="btn btn-sm btn-outline btn-icon" title="Print Stock Card" target="_blank"><i class="fas fa-print"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ auth()->user()->hasAdminAccess() ? 11 : 9 }}" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-book-open" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    No items in this category.
                </td></tr>
                @endforelse
            </tbody>
            @if($items->count() > 0)
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 8 : 5 }}" style="text-align:right">TOTAL:</td>
                    <td style="text-align:right">{{ number_format($grandBalance, 2) }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td></td>
                    <td></td>
                    @endif
                    <td style="text-align:right">₱{{ number_format($grandValue, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
