@extends('layouts.app')
@section('title', 'Inventory Balance Report')
@section('page-title', 'Inventory Balance Report')

@section('content')
<div class="page-header">
    <div>
        <h1>Inventory Balance Report</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Reports / Inventory Balance</div>
    </div>
    <div style="display:flex;gap:8px">
        <button onclick="window.print()" class="btn btn-outline no-print"><i class="fas fa-print"></i> Print</button>
        @if(auth()->user()->hasAdminAccess())
        <a href="{{ route('inventory_balance_report.export', request()->query()) }}" class="btn btn-success no-print">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        @endif
    </div>
</div>

{{-- ── Filter Bar ──────────────────────────────────────────────────────────── --}}
<div class="card no-print" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px">
        <form method="GET" action="{{ route('inventory_balance_report') }}" class="filters-bar" style="margin:0" id="filter-form">
            {{-- Warehouse --}}
            <div>
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:3px">Warehouse</label>
                <select name="warehouse_id" class="form-control" onchange="refreshItems()">
                    <option value="">All Warehouses</option>
                    @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ $warehouseId == $wh->id ? 'selected' : '' }}>
                        {{ $wh->name }} ({{ $wh->code }})
                    </option>
                    @endforeach
                </select>
            </div>

            {{-- Category --}}
            <div>
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:3px">Category</label>
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    @foreach(App\Models\Item::getCategories() as $key => $cat)
                    <option value="{{ $key }}" {{ $categoryKey === $key ? 'selected' : '' }}>
                        {{ $cat['label'] }}
                    </option>
                    @endforeach
                </select>
            </div>

            {{-- Item --}}
            <div>
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:3px">Item</label>
                <select name="item_id" class="form-control">
                    <option value="">All Items</option>
                    @foreach($allItems as $itm)
                    <option value="{{ $itm->id }}" {{ $itemId == $itm->id ? 'selected' : '' }}>
                        {{ $itm->description }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div style="align-self:flex-end;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('inventory_balance_report') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Active filter summary ───────────────────────────────────────────────── --}}
@if($warehouseId || $categoryKey || $itemId)
<div style="margin-bottom:16px;font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <i class="fas fa-filter" style="color:var(--primary)"></i>
    <span>Showing results for:</span>
    @if($warehouseId)
        <span class="badge badge-primary">{{ $warehouses->firstWhere('id', $warehouseId)?->name ?? 'Warehouse #'.$warehouseId }}</span>
    @endif
    @if($categoryKey)
        <span class="badge badge-info">{{ App\Models\Item::getCategories()[$categoryKey]['label'] ?? $categoryKey }}</span>
    @endif
    @if($itemId)
        <span class="badge badge-secondary">{{ $allItems->firstWhere('id', $itemId)?->description ?? 'Item #'.$itemId }}</span>
    @endif
</div>
@endif

{{-- ── Results ─────────────────────────────────────────────────────────────── --}}
@if(count($balances) === 0)
<div class="card">
    <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
        <i class="fas fa-box-open" style="font-size:40px;margin-bottom:12px;display:block"></i>
        No inventory items match the selected filters.
    </div>
</div>
@else

@php $overallTotal = 0; @endphp
@foreach($balances as $b)
<div class="card" style="margin-bottom:20px">
    <div class="card-header" style="background:#1e2a3a">
        <h3 style="color:white"><i class="fas fa-building"></i> {{ $b['warehouse']->name }} ({{ $b['warehouse']->code }})</h3>
        <span style="color:#c8d6e5;font-size:14px">{{ $b['warehouse']->place }}</span>
    </div>
    @if(count($b['categories']) > 0)
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Category</th>
                    <th>Item</th>
                    <th>Unit</th>
                    <th style="text-align:right">Qty</th>
                    <th style="text-align:right">Unit Cost</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Total Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($b['categories'] as $cat)
                <tr style="background:#ebf4ff">
                    <td><span class="badge badge-primary">{{ $cat['account_code'] }}</span></td>
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 7 : 5 }}"><strong>{{ $cat['label'] }}</strong></td>
                    <td style="text-align:right;font-weight:700">₱{{ number_format($cat['total_value'], 2) }}</td>
                </tr>
                @foreach($cat['items'] as $item)
                <tr>
                    <td></td>
                    <td></td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->unit }}</td>
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
                @endforeach
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f0fff4;font-weight:700">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 8 : 6 }}" style="text-align:right">WAREHOUSE TOTAL:</td>
                    <td style="text-align:right">₱{{ number_format($b['grand_total'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @else
    <div class="card-body" style="color:var(--text-muted);font-size:13px">No inventory items for this warehouse.</div>
    @endif
</div>
@php $overallTotal += $b['grand_total']; @endphp
@endforeach

<div class="card" style="background:#1e2a3a">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
        <span style="color:white;font-size:18px;font-weight:700">OVERALL GRAND TOTAL</span>
        <span style="color:#90cdf4;font-size:28px;font-weight:800">₱{{ number_format($overallTotal, 2) }}</span>
    </div>
</div>

@endif
@endsection

@push('scripts')
<script>
/**
 * When the warehouse filter changes, reload the item dropdown via AJAX
 * so users only see items belonging to the selected warehouse.
 */
function refreshItems() {
    const warehouseId = document.querySelector('[name="warehouse_id"]').value;
    const itemSelect  = document.querySelector('[name="item_id"]');
    const currentItem = '{{ $itemId }}';

    if (!warehouseId) {
        // No warehouse selected — submit the form to let PHP reload all items
        document.getElementById('filter-form').submit();
        return;
    }

    fetch(`/api/requisition-items?warehouse_id=${encodeURIComponent(warehouseId)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(data => {
        itemSelect.innerHTML = '<option value="">All Items</option>';
        data.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.description;
            if (String(item.id) === String(currentItem)) opt.selected = true;
            itemSelect.appendChild(opt);
        });
    })
    .catch(() => {
        // Fallback: just submit the form
        document.getElementById('filter-form').submit();
    });
}
</script>
@endpush
