@extends('layouts.app')
@section('title', 'RIS Details')
@section('page-title', 'RIS Details')

@section('content')
<div class="page-header">
    <div>
        <h1>RIS {{ $requisition->ris_code ?? $requisition->ris_id }} <span style="font-weight:400;color:var(--text-muted);font-size:16px">/ {{ $requisition->ris_number }}</span></h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / View</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">RIS ID: <span style="font-family:monospace;color:var(--primary);font-weight:600">{{ $requisition->ris_code ?? $requisition->ris_id }}</span> &nbsp;·&nbsp; RIS No.: <strong>{{ $requisition->ris_number }}</strong></div>
    </div>
    <div style="display:flex;gap:8px">
        @if(($requisition->status == 'pending' || $requisition->status == 'partially_approved') && auth()->user()->canApprove())
        <a href="{{ route('requisitions.approve', $requisition->id) }}" class="btn btn-success">
            <i class="fas fa-check"></i>
            {{ $requisition->status == 'partially_approved' ? 'Issue Remaining Items' : 'Approve' }}
        </a>
        @endif
        @if(auth()->user()->canWrite())
        <a href="{{ route('requisitions.signatories', $requisition->id) }}" class="btn btn-secondary"><i class="fas fa-signature"></i> Signatories</a>
        @else
        <a href="{{ route('requisitions.signatories', $requisition->id) }}" class="btn btn-outline"><i class="fas fa-eye"></i> View Signatories</a>
        @endif
        <a href="{{ route('requisitions.print', $requisition->id) }}" class="btn btn-outline" target="_blank"><i class="fas fa-print"></i> Print RIS</a>
@if(auth()->user()->canWrite())
<button type="button" class="btn btn-primary" onclick="openCorrectRisModal()"><i class="fas fa-edit"></i> Edit RIS</button>
        @endif
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
        <div class="card">
        <div class="card-header"><h3>RIS Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                <div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap"><span style="color:var(--text-muted)">RIS ID:</span><strong style="font-family:monospace;color:var(--primary)">{{ $requisition->ris_code ?? $requisition->ris_id }}</strong> <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">(system)</span></div>
                <div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap"><span style="color:var(--text-muted)">RIS No.:</span><strong>{{ $requisition->ris_number }}</strong> <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">(official)</span></div>
                <div><span style="color:var(--text-muted)">Date Requested:</span><br>{{ $requisition->date_requested?->format('F d, Y') ?? '—' }}</div>
                <div><span style="color:var(--text-muted)">Entity Name:</span><br>{{ $requisition->entity_name ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Fund Cluster:</span><br>{{ $requisition->fund_cluster ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Office:</span><br>{{ $requisition->office ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Division:</span><br>{{ $requisition->division ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Requesting LGU:</span><br>{{ $requisition->province ? $requisition->province . ' / ' . $requisition->municipality : ($requisition->municipality ?? '-') }}</div>
                <div><span style="color:var(--text-muted)">Warehouse:</span><br>{{ $requisition->warehouse_names ?? ($requisition->warehouse->name ?? '-') }}</div>
                <div><span style="color:var(--text-muted)">Resp. Center Code:</span><br>{{ $requisition->responsibility_center_code ?? '-' }}</div>
                <div colspan="2"><span style="color:var(--text-muted)">Purpose:</span><br>{{ $requisition->purpose }}</div>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Status</h3></div>
        <div class="card-body" style="text-align:center">
            <span class="badge {{ $requisition->getStatusBadgeClass() }}">
                {{ $requisition->getStatusLabel() }}
            </span>
            @if($requisition->date_approved)
            <div style="margin-top:16px;font-size:13px;color:var(--text-muted)">
                Approved on {{ $requisition->date_approved->format('F d, Y') }}<br>
                by {{ $requisition->approver->name ?? '-' }}
            </div>
            @endif
            @php $subSnapshot = $requisition->deletedSubsidySnapshot(); @endphp
            @if($subSnapshot)
            <div style="margin-top:16px;display:flex;flex-direction:column;align-items:center;gap:8px">
                @include('partials.subsidy-source-badge', [
                    'status' => $subSnapshot['status'],
                    'ris'    => $subSnapshot['ris'],
                    'dr'     => $subSnapshot['dr'],
                    'prefix' => 'RELATED TO',
                ])
                <span style="font-size:12px;color:var(--text-muted)">
                    This RIS draws from stock that traces back to a {{ $subSnapshot['status'] === 'deleted' }} Subsidy.
                </span>
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Fulfilment Progress -->
@php
    $risItems       = $requisition->items;
    $totalReq       = $risItems->sum('quantity_requested');
    $totalIss       = $risItems->sum('quantity_issued');
    $totalRem       = max(0, $totalReq - $totalIss);
    $fulPct         = $totalReq > 0 ? min(100, round($totalIss / $totalReq * 100)) : 0;
    $isFullyIssued  = $totalRem <= 0 && $totalIss > 0;
@endphp
@if($totalReq > 0)
<div class="card" style="margin-bottom:24px">
    <div class="card-body" style="padding:20px 24px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px">
            <span style="font-weight:700;font-size:14px">
                <i class="fas fa-boxes" style="color:var(--primary);margin-right:6px"></i>
                Fulfilment Progress
            </span>
            <span style="font-size:13px;color:var(--text-muted)">
                {{ $requisition->items->sum(fn($ri) => $ri->quantity_issued > 0 ? 1 : 0) }} line(s) with issuances
            </span>
        </div>
        <div style="background:#e2e8f0;border-radius:999px;height:14px;overflow:hidden;margin-bottom:10px">
            <div style="background:{{ $isFullyIssued ? 'var(--success)' : 'var(--primary)' }};width:{{ $fulPct }}%;height:100%;border-radius:999px;position:relative">
                @if($fulPct >= 15)
                <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10px;font-weight:700;color:white">{{ $fulPct }}%</span>
                @endif
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;text-align:center">
            <div style="background:#f0f9ff;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Requested</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalReq) }}</div>
            </div>
            <div style="background:#f0fff4;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Issued</div>
                <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalIss) }}</div>
            </div>
            <div style="background:{{ $isFullyIssued ? '#f0fff4' : '#fffff0' }};border-radius:8px;padding:12px;border:{{ $isFullyIssued ? 'none' : '1px solid #faf089' }}">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">
                    {{ $isFullyIssued ? 'Status' : 'Still Outstanding' }}
                </div>
                @if($isFullyIssued)
                    <div style="font-size:18px;font-weight:800;color:var(--success)"><i class="fas fa-check-circle"></i> Complete</div>
                @else
                    <div style="font-size:22px;font-weight:800;color:var(--warning)">{{ number_format($totalRem) }}</div>
                    <div style="font-size:11px;color:var(--warning);margin-top:2px">units still outstanding</div>
                @endif
            </div>
        </div>
        @if(!$isFullyIssued && $totalIss > 0)
        <div style="margin-top:12px;padding:10px 14px;background:#fffff0;border:1px solid #faf089;border-radius:8px;font-size:13px;color:#744210">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Partially fulfilled</strong> — {{ number_format($totalRem) }} units still outstanding.
            @if(auth()->user()->canApprove())
            <a href="{{ route('requisitions.approve', $requisition->id) }}" style="color:var(--primary);font-weight:600;margin-left:6px">
                Issue remaining items →
            </a>
            @endif
        </div>
        @elseif($totalIss == 0)
        <div style="margin-top:12px;padding:10px 14px;background:#f7fafc;border-radius:8px;font-size:13px;color:var(--text-muted)">
            <i class="fas fa-info-circle"></i> No items issued yet.
            @if(auth()->user()->canApprove())
            <a href="{{ route('requisitions.approve', $requisition->id) }}" style="color:var(--primary);font-weight:600;margin-left:6px">
                Process issuance →
            </a>
            @endif
        </div>
        @endif
    </div>
</div>
@endif

<!-- Items -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header"><h3>Requested Items</h3></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="text-align:center">Description</th>
                    <th style="text-align:center">Unit</th>
                    <th style="text-align:center">Requested Quantity</th>
                    <th style="text-align:center">Delivered Quantity</th>
                    <th style="text-align:center">Remaining</th>
                    <th style="text-align:center">Status</th>
                </tr>
            </thead>
            <tbody>
                {{-- Requested Items mirrors the creation input only: one row per
                     requisition line with the requested description and quantity,
                     plus live delivery progress (delivered vs requested).
                     Issuance detail (stock, costs, DR) lives in the
                     Partial Delivery Breakdown section below. --}}
                @foreach($requisition->items as $ri)
                @php
                    // Same fulfilment rule as Requisition::updateFulfilmentStatus():
                    // a line is complete once total issued reaches requested.
                    $riDelivered = (float) $ri->quantity_issued;
                    $riRemaining = max(0, (float) $ri->quantity_requested - $riDelivered);
                    $riComplete  = $riDelivered >= (float) $ri->quantity_requested - 0.0001;
                    $riPending   = $riDelivered <= 0;
                @endphp
                <tr>
                    <td style="text-align:center">{{ $ri->description }}</td>
                    <td style="text-align:center">{{ $ri->unit ?? '—' }}</td>
                    <td style="text-align:center">{{ number_format($ri->quantity_requested) }}</td>
                    <td style="text-align:center">{{ number_format($riDelivered) }}</td>
                    <td style="text-align:center">
                        @if($riComplete)
                            <span style="color:var(--text-muted)">—</span>
                        @else
                            <span style="color:var(--warning);font-weight:600">{{ number_format($riRemaining) }}</span>
                        @endif
                    </td>
                    <td style="text-align:center">
                        @if($riComplete)
                            <span class="badge badge-success"><i class="fas fa-check"></i> Complete</span>
                        @elseif($riPending)
                            <span class="badge badge-warning">Pending</span>
                        @else
                            <span class="badge badge-info">Partial Delivery</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Partial Delivery Breakdown -->
@if($totalIss > 0)
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Partial Delivery Breakdown</h3>
    </div>

    @foreach($requisition->items as $ri)
    @php
        $dispatches  = $ri->dispatchItems;
        $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued);
        $itemPct     = $ri->quantity_requested > 0
            ? min(100, round($ri->quantity_issued / $ri->quantity_requested * 100))
            : 0;
    @endphp
    <div style="border-bottom:1px solid var(--border)">

        {{-- Item header --}}
        <div style="padding:12px 20px;background:#f7fafc;display:flex;justify-content:space-between;align-items:center">
            <div>
                <strong style="font-size:11px">{{ $ri->description ?? ($ri->item?->description ?? '—') }}</strong>
                <span style="font-size:11px;color:var(--text-muted);margin-left:6px">{{ $ri->unit ?? '' }}</span>
            </div>
            <div style="display:flex;gap:16px;font-size:11px;text-align:right">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Requested</div>
                    <strong>{{ number_format($ri->quantity_requested) }}</strong>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Issued</div>
                    <strong style="color:var(--success)">{{ number_format($ri->quantity_issued) }}</strong>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Outstanding</div>
                    <strong style="color:{{ $outstanding > 0 ? 'var(--warning)' : 'var(--success)' }}">
                        {{ $outstanding > 0 ? number_format($outstanding) : '✓ Done' }}
                    </strong>
                </div>
            </div>
        </div>

        {{-- Per-item progress bar --}}
        <div style="padding:8px 20px">
            <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden">
                <div style="background:{{ $itemPct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $itemPct }}%;height:100%;border-radius:999px"></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px">{{ $itemPct }}% fulfilled</div>
        </div>

        @if($dispatches->isEmpty())
            <div style="padding:12px 20px;font-size:11px;color:var(--text-muted)">
                <i class="fas fa-info-circle"></i> No issuances recorded for this item yet.
            </div>
        @else
            {{-- Dispatch history table --}}
            @php $runningTotal = 0; @endphp
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:#f0f9ff">
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Date Dispatched</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Stock No.</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Warehouse</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Qty</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">DR No.</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Unit Cost</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Value</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">ENGAS Unit Cost</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">ENGAS Total</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Cumulative</th>
                        <th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Available Stocks</th>
@if(auth()->user()->canWrite())
<th style="padding:8px 14px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Actions</th>
@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($dispatches as $diNum => $di)
                    @php
                        $runningTotal += $di->quantity_issued;
                        $cumulativePct = $ri->quantity_requested > 0
                            ? min(100, round($runningTotal / $ri->quantity_requested * 100))
                            : 0;
                        $engasTotal = $di->engas_unit_cost !== null ? ($di->quantity_issued * $di->engas_unit_cost) : null;
                    @endphp
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:10px 14px;text-align:center">
                            <strong>{{ $di->created_at?->format('M d, Y') }}</strong>
                            @if($di->expiration_date)
                                <div style="font-size:11px;color:var(--text-muted)">Exp. {{ $di->expiration_date->format('M d, Y') }}</div>
                            @endif
                            @if($di->item && $di->item->source_subsidy_status === 'deleted')
                            <div style="margin-top:4px">
                                @include('partials.subsidy-source-badge', [
                                    'status' => $di->item->source_subsidy_status,
                                    'ris'    => $di->item->sourceSubsidyReference(),
                                    'dr'     => $di->item->sourceDrReference(),
                                    'code'   => $di->item->sourceSubsidyCode(),
                                    'prefix' => 'RELATED TO',
                                ])
                            </div>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($di->item && $di->item->stock_number)
                                {{ $di->item->stock_number }}
                            @else
                                <span style="color:var(--text-muted);font-size:11px">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">{{ $di->item?->warehouse?->name ?? '—' }}</td>
                        <td style="padding:10px 14px;text-align:center;font-weight:700;color:var(--primary)">
                            +{{ number_format($di->quantity_issued) }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            <code style="font-size:11px">{{ $di->dr_number ?? '—' }}</code>
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            ₱{{ number_format($di->unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            ₱{{ number_format($di->quantity_issued * $di->unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($di->engas_unit_cost !== null)
                                <span style="color:#059669">₱{{ number_format($di->engas_unit_cost, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($engasTotal !== null)
                                <span style="color:#059669;font-weight:600">₱{{ number_format($engasTotal, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            <span style="font-weight:600">{{ number_format($runningTotal) }}</span>
                            <span style="font-size:11px;color:var(--text-muted);margin-left:4px">
                                / {{ number_format($ri->quantity_requested) }} ({{ $cumulativePct }}%)
                            </span>
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($di->item)
                                @if(($stockAvailability[$di->item->id] ?? 0) > 0)
                                    <span class="badge badge-success">Yes</span>
                                @else
                                    <span class="badge badge-secondary">No</span>
                                @endif
                            @else
                                <span style="color:var(--text-muted);font-size:11px">—</span>
                            @endif
                        </td>
                        @if(auth()->user()->canWrite())
                        <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                            @if($di->item)
                            <a href="{{ route('stock_cards.item_history', $di->item->id) }}"
                               class="btn btn-sm btn-outline btn-icon"
                               title="View Stock Card{{ $di->item->stock_number ? ': '.$di->item->stock_number : '' }}">
                                <i class="fas fa-book"></i>
                            </a>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline btn-icon"
                                    onclick="openDispatchEditModal({{ $di->id }})"
                                    title="Edit this issued item (warehouse, quantity, costs, DR, expiry)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline btn-icon dd-delete-btn"
                                    onclick="openDispatchDeleteModal({{ $di->id }})"
                                    data-dispatch-id="{{ $di->id }}"
                                    data-label="{{ trim(($di->item?->stock_number ? $di->item->stock_number.' · ' : '').($di->item?->description ?? ($ri->description ?? ''))) }}"
                                    data-qty="{{ $di->quantity_issued }}"
                                    title="Delete this issued item and return its quantity to the originating stock">
                                <i class="fas fa-trash" style="color:var(--danger)"></i>
                            </button>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
                        <td colspan="3" style="padding:10px 20px">Total Issued</td>
                        <td style="padding:10px 14px;text-align:center;color:var(--success)">
                            {{ number_format($ri->quantity_issued) }}
                        </td>
                        <td></td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:center">
                            ₱{{ number_format($dispatches->sum(fn($d) => $d->quantity_issued * $d->unit_cost), 2) }}
                        </td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:center">
                            @php $totalEngas = $dispatches->sum(fn($d) => $d->engas_unit_cost ? ($d->quantity_issued * $d->engas_unit_cost) : 0); @endphp
                            @if($totalEngas > 0)
                                <span style="color:#059669;font-weight:600">₱{{ number_format($totalEngas, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            <span style="color:{{ $outstanding > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $outstanding > 0 ? number_format($outstanding).' outstanding' : '✓ Fully issued' }}
                            </span>
                        </td>
                        <td></td>
                        @if(auth()->user()->canApprove())
                        <td></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
    @endforeach
</div>
@endif

<!-- Signatories -->
<div class="card">
    <div class="card-header">
        <h3>Signatories</h3>
        <a href="{{ route('requisitions.signatories', $requisition->id) }}" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i> Edit</a>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;font-size:13px;text-align:center">
            @foreach([
                ['label' => 'Requested By', 'name' => $requisition->requested_by_name, 'desig' => $requisition->requested_by_designation],
                ['label' => 'Approved By', 'name' => $requisition->approved_by_name, 'desig' => $requisition->approved_by_designation],
                ['label' => 'Issued By', 'name' => $requisition->issued_by_name, 'desig' => $requisition->issued_by_designation],
                ['label' => 'Received By', 'name' => $requisition->received_by_name, 'desig' => $requisition->received_by_designation],
            ] as $sig)
            <div style="border:1px solid var(--border);border-radius:8px;padding:16px">
                <div style="font-weight:700;color:var(--primary);margin-bottom:8px">{{ $sig['label'] }}</div>
                <div style="font-weight:600">{{ $sig['name'] ?? '—' }}</div>
                <div style="color:var(--text-muted)">{{ $sig['desig'] ?? '' }}</div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@if(auth()->user()->canWrite())
@include('requisitions._dispatch_edit_modal')
@include('requisitions._dispatch_delete_modal')
@include('requisitions._correct_ris_modal')
@endif
