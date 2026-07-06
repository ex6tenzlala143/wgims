@extends('layouts.app')

@section('title', 'Transfer ' . $transfer->transfer_number)
@section('page-title', 'Stock Transfer Detail')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ $transfer->transfer_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('transfers.index') }}">Stock Transfers</a> › {{ $transfer->transfer_number }}
        </div>
    </div>
    <div style="display:flex;gap:8px">
        @if($transfer->status !== 'completed' && auth()->user()->role !== \App\Models\User::ROLE_STAFF)
        <a href="{{ route('transfers.dispatch', $transfer) }}" class="btn btn-success">
            <i class="fas fa-paper-plane"></i>
            {{ $transfer->status === 'partial' ? 'Dispatch Remaining' : 'Dispatch Items' }}
        </a>
        @endif
        @if(auth()->user()->canWrite())
        <a href="{{ route('transfers.edit', $transfer) }}" class="btn btn-secondary">
            <i class="fas fa-edit"></i> Edit Transfer
        </a>
        @endif
        <a href="{{ route('transfers.print', $transfer) }}" class="btn btn-outline" target="_blank">
            <i class="fas fa-print"></i> Print Slip
        </a>
        <a href="{{ route('transfers.index') }}" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="form-row cols-2" style="margin-bottom:16px">
    {{-- Header info --}}
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-info-circle"></i> Transfer Information</h3></div>
        <div class="card-body">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted);width:40%">Transfer Number</td>
                    <td style="padding:8px 0;font-weight:700">{{ $transfer->transfer_number }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Transfer Date</td>
                    <td style="padding:8px 0">{{ $transfer->transfer_date->format('F d, Y') }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Status</td>
                    <td style="padding:8px 0"><span class="badge {{ $transfer->getStatusBadgeClass() }}">{{ $transfer->getStatusLabel() }}</span></td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Transferred By</td>
                    <td style="padding:8px 0">{{ $transfer->transferredBy->name }}</td>
                </tr>
                @if($transfer->remarks)
                <tr>
                    <td style="padding:8px 0;color:var(--text-muted)">Remarks</td>
                    <td style="padding:8px 0">{{ $transfer->remarks }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    {{-- Warehouse info --}}
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-building"></i> Warehouse Movement</h3></div>
        <div class="card-body">
            <div style="display:flex;align-items:center;gap:16px;justify-content:center;padding:16px 0">
                <div style="text-align:center;flex:1">
                    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Source</div>
                    <div style="background:#fff5f5;border:2px solid #feb2b2;border-radius:10px;padding:14px">
                        <i class="fas fa-warehouse" style="font-size:24px;color:var(--danger);margin-bottom:6px;display:block"></i>
                        <strong style="font-size:15px">{{ $transfer->fromWarehouse->name }}</strong>
                        @if($transfer->fromWarehouse->place)
                        <div style="font-size:12px;color:var(--text-muted)">{{ $transfer->fromWarehouse->place }}</div>
                        @endif
                    </div>
                </div>
                <div style="font-size:28px;color:var(--primary)">
                    <i class="fas fa-arrow-right"></i>
                </div>
                <div style="text-align:center;flex:1">
                    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Destination</div>
                    <div style="background:#f0fff4;border:2px solid #9ae6b4;border-radius:10px;padding:14px">
                        <i class="fas fa-warehouse" style="font-size:24px;color:var(--success);margin-bottom:6px;display:block"></i>
                        <strong style="font-size:15px">{{ $transfer->toWarehouse->name }}</strong>
                        @if($transfer->toWarehouse->place)
                        <div style="font-size:12px;color:var(--text-muted)">{{ $transfer->toWarehouse->place }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Transfer Progress Summary --}}
@php
    $totalRequested   = $transfer->items->sum('quantity_requested');
    $totalTransferred = $transfer->items->sum('quantity');
    $totalRemaining   = max(0, $totalRequested - $totalTransferred);
    $pct              = $totalRequested > 0 ? min(100, round($totalTransferred / $totalRequested * 100)) : 0;
    $isComplete       = $totalRemaining <= 0 && $totalTransferred > 0;
    $itemCount        = $transfer->items->count();
    $totalValue       = $transfer->items->sum(fn($i) => $i->quantity * $i->unit_cost);
@endphp
<div class="card" style="margin-bottom:24px">
    <div class="card-body" style="padding:20px 24px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px">
            <span style="font-weight:700;font-size:15px">
                <i class="fas fa-exchange-alt" style="color:var(--primary);margin-right:6px"></i>
                Dispatch Progress
            </span>
            <span style="font-size:13px;color:var(--text-muted)">
                <span class="badge {{ $transfer->getStatusBadgeClass() }}">{{ $transfer->getStatusLabel() }}</span>
            </span>
        </div>

        {{-- Progress bar --}}
        <div style="background:#e2e8f0;border-radius:999px;height:14px;overflow:hidden;margin-bottom:10px">
            <div style="background:{{ $isComplete ? 'var(--success)' : ($pct > 0 ? 'var(--primary)' : '#e2e8f0') }};width:{{ $pct }}%;height:100%;border-radius:999px;position:relative">
                @if($pct >= 15)
                <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10px;font-weight:700;color:white">{{ $pct }}%</span>
                @endif
            </div>
        </div>

        {{-- Stats --}}
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;text-align:center">
            <div style="background:#f0f9ff;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Planned</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalRequested, 2) }}</div>
            </div>
            <div style="background:#f0fff4;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Dispatched</div>
                <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalTransferred, 2) }}</div>
            </div>
            <div style="background:{{ $isComplete ? '#f0fff4' : '#fffff0' }};border-radius:8px;padding:12px;border:{{ $isComplete ? 'none' : '1px solid #faf089' }}">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">
                    {{ $isComplete ? 'Status' : 'Still Needed' }}
                </div>
                @if($isComplete)
                    <div style="font-size:18px;font-weight:800;color:var(--success)"><i class="fas fa-check-circle"></i> Complete</div>
                @else
                    <div style="font-size:22px;font-weight:800;color:var(--warning)">{{ number_format($totalRemaining, 2) }}</div>
                    <div style="font-size:11px;color:var(--warning);margin-top:2px">units pending dispatch</div>
                @endif
            </div>
            <div style="background:#f7fafc;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Value Dispatched</div>
                <div style="font-size:18px;font-weight:800;color:var(--primary)">₱{{ number_format($totalValue, 2) }}</div>
            </div>
        </div>

        {{-- Route line --}}
        <div style="margin-top:12px;padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:10px">
            <i class="fas fa-warehouse" style="color:var(--danger)"></i>
            <strong>{{ $transfer->fromWarehouse->name }}</strong>
            @if($transfer->fromWarehouse->code)
            <span style="color:var(--text-muted)">({{ $transfer->fromWarehouse->code }})</span>
            @endif
            <i class="fas fa-long-arrow-alt-right" style="color:var(--primary);font-size:18px;margin:0 6px"></i>
            <i class="fas fa-warehouse" style="color:var(--success)"></i>
            <strong>{{ $transfer->toWarehouse->name }}</strong>
            @if($transfer->toWarehouse->code)
            <span style="color:var(--text-muted)">({{ $transfer->toWarehouse->code }})</span>
            @endif
            @if(!$isComplete && auth()->user()->role !== \App\Models\User::ROLE_STAFF)
            <a href="{{ route('transfers.dispatch', $transfer) }}" style="margin-left:auto;color:var(--primary);font-weight:600;font-size:13px">
                {{ $totalTransferred > 0 ? 'Dispatch remaining →' : 'Dispatch items →' }}
            </a>
            @endif
        </div>
    </div>
</div>

{{-- Line items --}}
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Transferred Items</h3>
        <span style="font-size:13px;color:var(--text-muted)">{{ $transfer->items->count() }} item(s)</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Category</th>
                    <th>Source Stock #</th>
                    <th>Dest. Stock #</th>
                    <th style="text-align:right">Qty Planned</th>
                    <th style="text-align:right">Qty Dispatched</th>
                    <th style="text-align:right">Outstanding</th>
                    <th style="text-align:right">Unit Cost</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Value Dispatched</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotal = 0; @endphp
                @foreach($transfer->items as $i => $line)
                @php
                    $lineValue   = $line->quantity * $line->unit_cost;
                    $outstanding = max(0, $line->quantity_requested - $line->quantity);
                    $grandTotal += $lineValue;
                @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $line->sourceItem->description }}</strong>
                        @if($line->sourceItem->ris_number)
                        <div style="font-size:12px;color:var(--text-muted)">{{ $line->sourceItem->ris_number }}</div>
                        @endif
                    </td>
                    <td>{{ $line->sourceItem->unit }}</td>
                    <td>{{ $line->sourceItem->getCategoryLabel() }}</td>
                    <td>
                        @if($line->sourceItem->stock_number)
                        <span class="badge badge-secondary">{{ $line->sourceItem->stock_number }}</span>
                        @else
                        <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td>
                        @if($line->destinationItem->stock_number)
                        <span class="badge badge-primary">{{ $line->destinationItem->stock_number }}</span>
                        @else
                        <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;font-weight:600">{{ number_format($line->quantity_requested, 4) }}</td>
                    <td style="text-align:right;color:var(--success);font-weight:600">{{ number_format($line->quantity, 4) }}</td>
                    <td style="text-align:right">
                        @if($outstanding > 0)
                            <span style="color:var(--warning);font-weight:700">{{ number_format($outstanding, 4) }}</span>
                        @else
                            <span class="badge badge-success"><i class="fas fa-check"></i> Done</span>
                        @endif
                    </td>
                    <td style="text-align:right">₱ {{ number_format($line->unit_cost, 2) }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right">
                        @if($line->sourceItem->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱ {{ number_format($line->sourceItem->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        @if($line->sourceItem->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱ {{ number_format($line->quantity * $line->sourceItem->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif
                    <td style="text-align:right">₱ {{ number_format($lineValue, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f7fafc;font-weight:700">
                    <td colspan="10" style="text-align:right;padding:12px 14px">Total Value Dispatched:</td>
                    <td style="text-align:right;padding:12px 14px">₱ {{ number_format($grandTotal, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endsection
