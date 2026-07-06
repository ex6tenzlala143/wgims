@extends('layouts.app')
@section('title', 'Delivery / Subsidies')
@section('page-title', 'Delivery / Subsidies')

@section('content')
<div class="page-header">
    <div>
        <h1>Delivery / Subsidies</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / Delivery / Subsidies</div>
    </div>
    @if(auth()->user()->canCreate())
    <a href="{{ route('delivery_subsidies.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Delivery/Subsidy</a>
    @endif
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="filters-bar" style="margin:0;width:100%">
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="pending" {{ request('status')=='pending'?'selected':'' }}>Pending</option>
                <option value="partial" {{ request('status')=='partial'?'selected':'' }}>Partial Delivery</option>
                <option value="fully_delivered" {{ request('status')=='fully_delivered'?'selected':'' }}>Fully Delivered</option>
                <option value="cancelled" {{ request('status')=='cancelled'?'selected':'' }}>Cancelled</option>
            </select>
            @if(auth()->user()->hasAdminAccess())
            <select name="warehouse_id" class="form-control">
                <option value="">All Warehouses</option>
                @foreach($warehouses as $c)
                <option value="{{ $c->id }}" {{ request('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                @endforeach
            </select>
            <select name="account_code" class="form-control">
                <option value="">All Account Codes</option>
                @foreach($accountCodes as $code => $label)
                <option value="{{ $code }}" {{ request('account_code')==$code?'selected':'' }}>{{ $label }}</option>
                @endforeach
            </select>
            @endif
            <select name="description" class="form-control">
                <option value="">All Items</option>
                @foreach($descriptions as $desc)
                <option value="{{ $desc }}" {{ request('description')===$desc?'selected':'' }}>{{ $desc }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="{{ route('delivery_subsidies.index') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>RIS No.</th>
                    <th>Date</th>
                    <th>Supplier/Subsidy</th>
                    @if(auth()->user()->hasAdminAccess())<th>Warehouse</th>@endif
                    <th style="text-align:right">Total Amount</th>
                    <th style="text-align:right">Qty Requested</th>
                    <th style="min-width:160px">Delivery Progress</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pos as $po)
                @php
                    $requested  = (float) $po->quantity_requested;
                    $delivered  = (float) $po->deliveries_sum_quantity_delivered ?? $po->totalDelivered();
                    $remaining  = max(0, $requested - $delivered);
                    $pct        = $requested > 0 ? min(100, round($delivered / $requested * 100)) : 0;
                    $barColor   = $pct >= 100 ? 'var(--success)' : ($pct > 0 ? 'var(--primary)' : '#e2e8f0');
                @endphp
                <tr>
                    <td><strong>{{ $po->ris_number }}</strong></td>
                    <td>{{ $po->date ? $po->date->format('M d, Y') : '-' }}</td>
                    <td>{{ $po->supplier->name ?? '-' }}</td>
                    @if(auth()->user()->hasAdminAccess())<td>{{ $po->warehouse->name ?? '-' }}</td>@endif
                    <td style="text-align:right">₱{{ number_format($po->total_amount, 2) }}</td>
                    <td style="text-align:right;white-space:nowrap">
                        {{ $requested > 0 ? number_format($requested, 2) : '—' }}
                    </td>
                    <td>
                        @if($requested > 0)
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;display:flex;justify-content:space-between">
                            <span>{{ number_format($delivered, 2) }} delivered</span>
                            <span style="color:{{ $remaining > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                                {{ $remaining > 0 ? number_format($remaining, 2).' left' : '✓ Complete' }}
                            </span>
                        </div>
                        <div style="background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden">
                            <div style="background:{{ $barColor }};width:{{ $pct }}%;height:100%;border-radius:999px"></div>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">{{ $pct }}%</div>
                        @else
                        <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $po->getStatusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $po->status)) }}</span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('delivery_subsidies.show', $po->id) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                            @if($po->status !== 'fully_delivered' && $po->status !== 'cancelled')
                            <a href="{{ route('delivery_subsidies.delivery', $po->id) }}" class="btn btn-sm btn-success btn-icon" title="Record Delivery"><i class="fas fa-truck"></i></a>
                            @endif
                            @if(auth()->user()->canWrite() && $po->status !== 'cancelled')
                            <a href="{{ route('delivery_subsidies.edit', $po->id) }}" class="btn btn-sm btn-outline btn-icon" title="Edit"><i class="fas fa-edit"></i></a>
                            @endif
                            @if(auth()->user()->canWrite())
                            <form action="{{ route('delivery_subsidies.destroy', $po->id) }}" method="POST"
                                onsubmit="return confirm('Delete RIS #{{ $po->ris_number }}? This will reverse all delivered stock quantities.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-file-invoice" style="font-size:32px;margin-bottom:8px;display:block"></i>
                    No delivery/subsidy records found.
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($pos->hasPages())
    <div class="card-footer">{{ $pos->links() }}</div>
    @endif
</div>
@endsection
