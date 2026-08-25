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
    <button type="button" class="btn btn-primary" onclick="openCreateModal()"><i class="fas fa-plus"></i> New Delivery/Subsidy</button>
    @endif
</div>

<div class="card">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search RIS #, DR #, Subsidy ID, supplier, item..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
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
            </div>
        </form>
    </div>
    @if(request('search'))
    <div class="filter-notice">
        <i class="fas fa-filter"></i>
        Showing <strong>{{ $pos->total() }}</strong> result(s) for "<strong>{{ request('search') }}</strong>"
        <a href="{{ route('delivery_subsidies.index', request()->except(['search', 'page'])) }}">Clear search</a>
    </div>
    @endif
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Subsidy ID</th>
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
                @forelse($pos as $subsidy)
                @php
                    $requested  = (float) $subsidy->quantity_requested;
                    $delivered  = (float) $subsidy->deliveries_sum_quantity_delivered ?? $subsidy->totalDelivered();
                    $remaining  = max(0, $requested - $delivered);
                    $pct        = $requested > 0 ? min(100, round($delivered / $requested * 100)) : 0;
                    $barColor   = $pct >= 100 ? 'var(--success)' : ($pct > 0 ? 'var(--primary)' : '#e2e8f0');
                @endphp
                <tr>
                    <td>
                        <code style="font-weight:700;color:var(--primary)">{{ $subsidy->subsidy_code }}</code>
                    </td>
                    <td>
                        <strong>{{ $subsidy->ris_number }}</strong>
                    </td>
                    <td>{{ $subsidy->date ? $subsidy->date->format('M d, Y') : '-' }}</td>
                    <td>{{ $subsidy->supplier->name ?? '-' }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td>
                        @php
                            $whNames = $subsidy->items
                                ->filter(fn ($i) => $i->warehouse !== null)
                                ->pluck('warehouse.name')
                                ->merge(
                                    $subsidy->deliveries
                                        ->flatMap(fn ($d) => $d->items)
                                        ->filter(fn ($di) => $di->warehouse !== null)
                                        ->pluck('warehouse.name')
                                )
                                ->unique()
                                ->values();
                        @endphp
                        {{ $whNames->isNotEmpty() ? $whNames->join(', ') : '—' }}
                    </td>
                    @endif
                    <td style="text-align:right">₱{{ number_format($subsidy->total_amount, 2) }}</td>
                    <td style="text-align:right;white-space:nowrap">
                        {{ $requested > 0 ? number_format($requested) : '—' }}
                    </td>
                    <td>
                        @if($requested > 0)
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;display:flex;justify-content:space-between">
                            <span>{{ number_format($delivered) }} delivered</span>
                            <span style="color:{{ $remaining > 0 ? 'var(--warning)' : 'var(--success)' }};font-weight:600">
                                {{ $remaining > 0 ? number_format($remaining).' left' : '✓ Complete' }}
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
                    <td><span class="badge {{ $subsidy->getStatusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $subsidy->status)) }}</span></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <a href="{{ route('delivery_subsidies.show', $subsidy->id) }}" class="btn btn-sm btn-outline btn-icon" title="View"><i class="fas fa-eye"></i></a>
                            @if($subsidy->status !== 'fully_delivered' && $subsidy->status !== 'cancelled')
                            <a href="{{ route('delivery_subsidies.delivery', $subsidy->id) }}" class="btn btn-sm btn-success btn-icon" title="Record Delivery"><i class="fas fa-truck"></i></a>
                            @endif
                            @if(auth()->user()->canWrite() && $subsidy->status !== 'cancelled')
                            <button type="button" class="btn btn-sm btn-outline btn-icon" title="Edit" onclick="openEditModal({{ $subsidy->id }})"><i class="fas fa-edit"></i></button>
                            @endif
                            @if(auth()->user()->canWrite())
                            <form action="{{ route('delivery_subsidies.destroy', $subsidy->id) }}" method="POST"
                                onsubmit="return confirm('Delete RIS #{{ $subsidy->ris_number }}? This will reverse all delivered stock quantities. Related stock transfers will be preserved and flagged for review.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted)">
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

@if(auth()->user()->canCreate())
@include('delivery_subsidies._create_form', ['createModalOpen' => $errors->any(), 'modalOnlyPage' => false])
@endif

@if(auth()->user()->canWrite())
@include('delivery_subsidies._edit_form')
@endif
@endsection
