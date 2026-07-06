@extends('layouts.app')
@section('title', 'RSMI Report')
@section('page-title', 'RSMI Report')

@section('content')
<div class="page-header">
    <div>
        <h1>Report of Supplies and Materials Issued (RSMI)</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> / Reports / RSMI
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <a href="{{ route('rsmi_report.print', request()->query()) }}" target="_blank" class="btn btn-primary">
            <i class="fas fa-print"></i> Print All RSMI
        </a>
        <a href="{{ route('rsmi_report.export', request()->query()) }}" class="btn btn-success">
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
            Contact an administrator to assign you to a warehouse.
        </div>
    </div>
</div>
@endif

{{-- Filters --}}
<div class="card" style="margin-bottom:16px">
    <div class="card-header-filters">
        <form method="GET" style="margin:0">
            {{-- Search row --}}
            <div class="search-row">
                <div class="search-input" style="width:380px">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search RIS No., office, purpose..." value="{{ request('search') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            </div>
            {{-- Filter row --}}
            <div class="filter-row">
                @if(auth()->user()->hasAdminAccess())
                <select name="warehouse_id" class="form-control">
                    <option value="">All Warehouses</option>
                    @foreach($warehouses as $c)
                    <option value="{{ $c->id }}" {{ request('warehouse_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                @endif
                <div style="display:flex;flex-direction:column;gap:3px">
                    <label style="font-size:12px;color:var(--text-muted);font-weight:600">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                </div>
                <div style="display:flex;flex-direction:column;gap:3px">
                    <label style="font-size:12px;color:var(--text-muted);font-weight:600">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="{{ route('rsmi_report') }}" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

{{-- Summary bar --}}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;font-size:13px;color:var(--text-muted)">
    <span>
        Showing <strong>{{ $risGroups->count() }}</strong> RIS(es)
        @if(request('date_from') || request('date_to'))
        &nbsp;·&nbsp;
        {{ request('date_from') ? date('M d, Y', strtotime(request('date_from'))) : '—' }}
        to
        {{ request('date_to') ? date('M d, Y', strtotime(request('date_to'))) : 'present' }}
        @endif
    </span>
    @if($risGroups->isNotEmpty())
    <span>
        Grand Total:&nbsp;
        <strong style="font-size:15px;color:var(--primary)">₱{{ number_format($grandTotal, 2) }}</strong>
    </span>
    @endif
</div>

{{-- ═══════════════════════════════════════════════════════════════════
     One card per RIS number — items grouped inside each card
════════════════════════════════════════════════════════════════════ --}}
@forelse($risGroups as $group)
@php
    /** @var \App\Models\Requisition $ris */
    $ris      = $group['ris'];
    $items    = $group['items'];
    $subtotal = $group['subtotal'];

    // Build single-RIS print URL — pass existing filters + target RIS number
    $printQuery = array_merge(request()->query(), [
        'ris_number'  => $ris->ris_number,
        'date_from'   => $ris->date_approved?->format('Y-m-d'),
        'date_to'     => $ris->date_approved?->format('Y-m-d'),
        'warehouse_id'=> $ris->warehouse_id,
    ]);
    $singlePrintUrl = route('rsmi_report.print', $printQuery);
@endphp

<div class="card" style="margin-bottom:20px">

    {{-- ── Card header: RIS info + Print button pinned to the right ── --}}
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">

        {{-- Left: RIS metadata --}}
        <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:0">
            <div>
                <div style="font-weight:700;font-size:15px">{{ $ris->ris_number }}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
                    {{ $ris->date_approved?->format('M d, Y') ?? '—' }}
                    &nbsp;·&nbsp;
                    <span class="badge badge-secondary">{{ $ris->warehouse->code ?? '—' }}</span>
                    &nbsp;·&nbsp;
                    {{ $ris->warehouse->name ?? '—' }}
                    @if($ris->office)
                    &nbsp;·&nbsp; Office: {{ $ris->office }}
                    @endif
                </div>
            </div>

            {{-- Status badge --}}
            <span class="badge {{ $ris->getStatusBadgeClass() }}" style="flex-shrink:0">
                {{ $ris->getStatusLabel() }}
            </span>

            {{-- Item count --}}
            <span style="font-size:12px;color:var(--text-muted);flex-shrink:0">
                {{ $items->count() }} item(s) &nbsp;·&nbsp;
                <strong style="color:var(--primary)">₱{{ number_format($subtotal, 2) }}</strong>
            </span>
        </div>

        {{-- Right: Print button — always pinned to far right --}}
        <a href="{{ $singlePrintUrl }}"
           target="_blank"
           class="btn btn-sm btn-outline"
           title="Print RSMI for this RIS"
           style="flex-shrink:0;white-space:nowrap;margin-left:auto">
            <i class="fas fa-print"></i> Print RSMI
        </a>
    </div>

    {{-- ── Items table for this RIS ── --}}
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Stock No.</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th style="text-align:right">Qty Requested</th>
                    <th style="text-align:right">Qty Issued</th>
                    <th style="text-align:right">Outstanding</th>
                    <th style="text-align:right">Unit Cost (₱)</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost (₱)</th>
                    <th style="text-align:right">Engas Total Value (₱)</th>
                    @endif
                    <th style="text-align:right">Amount (₱)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $ri)
                @php
                    $amount      = $ri->quantity_issued * ($ri->item->unit_cost ?? 0);
                    $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued);
                @endphp
                <tr>
                    <td>
                        <code style="font-size:11px">{{ $ri->item->stock_number ?? '—' }}</code>
                    </td>
                    <td>{{ $ri->item->description ?? '—' }}</td>
                    <td>{{ $ri->item->unit ?? '—' }}</td>
                    <td style="text-align:right">{{ number_format($ri->quantity_requested, 2) }}</td>
                    <td style="text-align:right;color:var(--success);font-weight:600">
                        {{ number_format($ri->quantity_issued, 2) }}
                    </td>
                    <td style="text-align:right">
                        @if($outstanding > 0)
                            <span style="color:var(--warning);font-weight:600">{{ number_format($outstanding, 2) }}</span>
                        @else
                            <span class="badge badge-success" style="font-size:10px">
                                <i class="fas fa-check"></i> Fulfilled
                            </span>
                        @endif
                    </td>
                    <td style="text-align:right">{{ number_format($ri->item->unit_cost ?? 0, 2) }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right">
                        @if(($ri->item->engas_unit_cost ?? null) !== null)
                            <span style="color:var(--primary);font-weight:600">{{ number_format($ri->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        @if(($ri->item->engas_unit_cost ?? null) !== null)
                            <span style="color:var(--primary);font-weight:600">{{ number_format($ri->quantity_issued * $ri->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif
                    <td style="text-align:right;font-weight:600">{{ number_format($amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f0f9ff;font-weight:700">
                    <td colspan="{{ auth()->user()->hasAdminAccess() ? 8 : 7 }}" style="text-align:right;padding:10px 14px">
                        RIS Sub-Total:
                    </td>
                    <td style="text-align:right;padding:10px 14px;color:var(--primary)">
                        ₱{{ number_format($subtotal, 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>
@empty
<div class="card">
    <div class="card-body" style="text-align:center;padding:48px;color:var(--text-muted)">
        <i class="fas fa-file-alt" style="font-size:36px;margin-bottom:12px;display:block;opacity:.3"></i>
        No issuances found for the selected period.
    </div>
</div>
@endforelse

{{-- Grand total footer --}}
@if($risGroups->isNotEmpty())
<div style="text-align:right;padding:12px 4px;font-size:15px;font-weight:700;border-top:2px solid var(--border)">
    Grand Total: <span style="color:var(--primary);font-size:18px">₱{{ number_format($grandTotal, 2) }}</span>
</div>
@endif

@endsection
