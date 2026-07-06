@extends('layouts.app')
@section('title', 'Items')
@section('page-title', 'Items')

@section('content')
<div class="page-header">
    <div>
        <h1>Inventory Items</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Items</div>
    </div>
    @if(auth()->user()->canCreate())
    <a href="{{ route('items.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Add Item</a>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search items..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    @foreach(App\Models\Item::getCategories() as $key => $cat)
                    <option value="{{ $key }}" {{ request('category') == $key ? 'selected' : '' }}>{{ $cat['label'] }}</option>
                    @endforeach
                </select>
                <select name="stock_status" class="form-control">
                    <option value="">All Stock Status</option>
                    <option value="in_stock"    {{ request('stock_status') == 'in_stock'    ? 'selected' : '' }}>In Stock</option>
                    <option value="out_of_stock"{{ request('stock_status') == 'out_of_stock'? 'selected' : '' }}>Out of Stock</option>
                    <option value="low_stock"   {{ request('stock_status') == 'low_stock'   ? 'selected' : '' }}>Low Stock</option>
                </select>
                @if($warehouses->count() > 1)
                <select name="warehouse_id" class="form-control">
                    <option value="">All Warehouses</option>
                    @foreach($warehouses as $c)
                    <option value="{{ $c->id }}" {{ request('warehouse_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                @endif
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('items.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th style="text-align:right">Quantity</th>
                    <th>Expiration Date</th>
                    <th>Unit</th>
                    <th>Category</th>
                    <th>Account Code</th>
                    <th>Warehouse</th>
                    <th style="text-align:right">Unit Cost</th>
                    <th style="text-align:right">Total Value</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                <tr style="{{ !$item->is_active ? 'opacity:.6;background:#fff5f5' : '' }}">
                    <td>
                        @if($item->stock_number)
                            <code style="font-size:12px">{{ $item->stock_number }}</code>
                        @else
                            <span style="color:var(--text-muted);font-size:11px;font-style:italic">Pending Delivery</span>
                        @endif
                    </td>
                    <td><strong>{{ $item->description }}</strong></td>
                    <td style="text-align:right">
                        @if($item->quantity <= 0)
                            <span style="color:var(--danger);font-weight:700">{{ number_format($item->quantity, 2) }}</span>
                            <span class="badge badge-danger" style="font-size:10px;margin-left:4px">Out of Stock</span>
                        @elseif($item->reorder_point > 0 && $item->quantity <= $item->reorder_point)
                            <span style="color:var(--warning);font-weight:700" title="Below reorder point">
                                {{ number_format($item->quantity, 2) }}
                                <i class="fas fa-exclamation-triangle" style="font-size:10px"></i>
                            </span>
                        @else
                            <span style="color:var(--success);font-weight:600">{{ number_format($item->quantity, 2) }}</span>
                        @endif
                    </td>
                    <td>{{ $item->expiration_date ? $item->expiration_date->format('M d, Y') : '—' }}</td>
                    <td>{{ $item->unit }}</td>
                    <td><span class="badge badge-info" style="font-size:10px">{{ $item->getCategoryLabel() }}</span></td>
                    <td><span class="badge badge-primary">{{ $item->account_code }}</span></td>
                    <td>{{ $item->warehouse->name ?? '—' }}</td>
                    <td style="text-align:right">₱{{ number_format($item->unit_cost, 2) }}</td>
                    <td style="text-align:right"><strong>₱{{ number_format($item->quantity * $item->unit_cost, 2) }}</strong></td>
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
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('items.show', $item->id) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                            @if($item->stock_number)
                            <a href="{{ route('stock_cards.item_history', $item->id) }}" class="btn btn-sm btn-outline btn-icon" title="Stock Card"><i class="fas fa-book"></i></a>
                            @endif
                            @if(auth()->user()->canWrite())
                            <a href="{{ route('items.edit', $item->id) }}" class="btn btn-sm btn-outline btn-icon" title="Edit"><i class="fas fa-edit"></i></a>
                            <form action="{{ route('items.destroy', $item->id) }}" method="POST" onsubmit="return confirm('Delete this item?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="{{ auth()->user()->hasAdminAccess() ? 13 : 11 }}" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-box-open" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    @if($warehouses->isEmpty() && !auth()->user()->hasAdminAccess())
                        No warehouses assigned. Please contact an administrator to get access to items.
                    @else
                        No items found.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($items->hasPages())
    <div class="card-footer">{{ $items->links() }}</div>
    @endif
</div>
@endsection
