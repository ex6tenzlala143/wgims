@extends('layouts.app')
@section('title', 'RPCI Report')
@section('page-title', 'RPCI Report')

@section('content')
<div class="page-header">
    <div>
        <h1>Report on Physical Count of Inventories (RPCI)</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Reports / RPCI</div>
    </div>
    <div style="display:flex;gap:8px">
        <a href="{{ route('rpci_report.print', request()->query()) }}" target="_blank" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Official RPCI
        </a>
        <a href="{{ route('rpci_report.export', request()->query()) }}" class="btn btn-success">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
    </div>
</div>

@if($noWarehouseAssigned)
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px">
    <i class="fas fa-exclamation-triangle" style="color:#856404;font-size:20px"></i>
    <div>
        <strong style="color:#856404">No warehouse assigned to your account.</strong>
        <div style="color:#856404;font-size:13px;margin-top:2px">
            You are not assigned to any warehouse yet. Please contact an administrator to assign you to a warehouse so data will appear here.
        </div>
    </div>
</div>
@endif

<div class="card no-print" style="margin-bottom:16px">
    <div class="card-body" style="padding:16px">
        <form method="GET" class="filters-bar" style="margin:0">
            @if(auth()->user()->hasAdminAccess())
            <select name="warehouse_id" class="form-control">
                <option value="">All Warehouses</option>
                @foreach($warehouses as $c)
                <option value="{{ $c->id }}" {{ request('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                @endforeach
            </select>
            @endif
            <select name="category" class="form-control">
                <option value="">All Categories</option>
                @foreach(App\Models\Item::getCategories() as $key => $cat)
                <option value="{{ $key }}" {{ request('category')==$key?'selected':'' }}>{{ $cat['label'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="{{ route('rpci_report') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
</div>

<!-- RPCI Table -->
<div class="card">
    <div class="card-header">
        <h3>Physical Count of Inventories</h3>
        <span style="font-size:13px;color:var(--text-muted)">As of {{ date('F d, Y') }}</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Category</th>
                    @if(auth()->user()->hasAdminAccess())<th>Warehouse</th>@endif
                    <th style="text-align:right">Qty on Hand</th>
                    <th style="text-align:right">Unit Cost</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotal = 0; $currentCat = ''; @endphp
                @forelse($items as $item)
                @if($currentCat != $item->category)
                @php $currentCat = $item->category; @endphp
                <tr style="background:#f0f4f8">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 9 : 7 }}" style="font-weight:700;color:var(--primary)">                        {{ App\Models\Item::getCategories()[$item->category]['label'] ?? $item->category }}
                        — Account Code: {{ App\Models\Item::getCategories()[$item->category]['account_code'] ?? '' }}
                    </td>
                </tr>
                @endif
                <tr>
                    <td><code style="font-size:11px">{{ $item->stock_number }}</code></td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->unit }}</td>
                    <td><span class="badge badge-info" style="font-size:10px">{{ $item->getCategoryLabel() }}</span></td>
                    @if(auth()->user()->hasAdminAccess())<td>{{ $item->warehouse->name ?? '-' }}</td>@endif
                    <td style="text-align:right">{{ number_format($item->quantity, 2) }}</td>
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
                </tr>
                @php $grandTotal += $item->quantity * $item->unit_cost; @endphp
                @empty
                <tr><td colspan="{{ auth()->user()->hasAdminAccess() ? 9 : 7 }}" style="text-align:center;padding:40px;color:var(--text-muted)">No items found.</td></tr>
                @endforelse
            </tbody>
            @if($items->count() > 0)
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700;font-size:15px">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 10 : 6 }}" style="text-align:right">GRAND TOTAL:</td>
                    <td style="text-align:right">₱{{ number_format($grandTotal, 2) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
