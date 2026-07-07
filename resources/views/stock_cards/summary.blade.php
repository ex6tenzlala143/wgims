@extends('layouts.app')
@section('title', 'Stock Summary — All Categories')
@section('page-title', 'Stock Cards')

@section('content')
<div class="page-header">
    <div>
        <h1>Stock Summary — All Categories</h1>
    </div>
</div>

@if(auth()->user()->hasAdminAccess() || $warehouses->isNotEmpty())
<div class="card" style="margin-bottom:16px">
    <div class="card-body" style="padding:12px 16px">
        <form method="GET" action="{{ route('stock_cards.summary') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            @if(auth()->user()->hasAdminAccess())
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Warehouse</label>
                <select name="warehouse_id" class="form-control" style="width:auto;min-width:180px">
                    <option value="">All Warehouses</option>
                    @foreach($warehouses as $c)
                    <option value="{{ $c->id }}" {{ request('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Account Code</label>
                <select name="account_code" class="form-control" style="width:auto;min-width:200px">
                    <option value="">All Account Codes</option>
                    @foreach($accountCodes as $code => $label)
                    <option value="{{ $code }}" {{ request('account_code')==$code?'selected':'' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div>
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Item</label>
                <select name="description" class="form-control" style="width:auto;min-width:200px">
                    <option value="">All Items</option>
                    @foreach($descriptions as $desc)
                    <option value="{{ $desc }}" {{ request('description')===$desc?'selected':'' }}>{{ $desc }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:6px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('stock_cards.summary') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Merged Stock Summary</h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $items->count() }} item(s)</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Category</th>
                    <th>Account Code</th>
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
                    <td><span class="badge badge-info" style="font-size:10px">{{ $item->getCategoryLabel() }}</span></td>
                    <td><span class="badge badge-primary" style="font-size:10px">{{ $item->account_code }}</span></td>
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
                <tr><td colspan="{{ auth()->user()->hasAdminAccess() ? 13 : 11 }}" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-book-open" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    No items match the selected filters.
                </td></tr>
                @endforelse
            </tbody>
            @if($items->count() > 0)
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 9 : 6 }}" style="text-align:right">TOTAL:</td>
                    <td style="text-align:right">{{ number_format($grandBalance, 2) }}</td>
                    <td></td>
                    <td style="text-align:right">₱{{ number_format($grandValue, 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
