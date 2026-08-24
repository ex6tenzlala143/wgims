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
        <a href="{{ route('requisitions.audit_log', $requisition->id) }}" class="btn btn-outline"><i class="fas fa-history"></i> Correction History</a>
        <button type="button" class="btn btn-primary" onclick="openCorrectRisModal()"><i class="fas fa-sync-alt"></i> Correct RIS</button>
        @endif
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
        <div class="card">
        <div class="card-header"><h3>RIS Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px">
                <div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap"><span style="color:var(--text-muted)">RIS ID:</span><strong style="font-family:monospace;color:var(--primary)">{{ $requisition->ris_code ?? $requisition->ris_id }}</strong> <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">(system)</span></div>
                <div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap"><span style="color:var(--text-muted)">RIS No.:</span><strong>{{ $requisition->ris_number }}</strong> <span style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">(official)</span></div>
                <div><span style="color:var(--text-muted)">Date Requested:</span><br>{{ $requisition->date_requested->format('F d, Y') }}</div>
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
            <span class="badge {{ $requisition->getStatusBadgeClass() }}" style="font-size:16px;padding:10px 20px">
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
                    This RIS draws from stock that traces back to a {{ $subSnapshot['status'] === 'deleted' ? 'deleted' : 'archived' }} Subsidy.
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
            <span style="font-weight:700;font-size:15px">
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
                <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalReq, 2) }}</div>
            </div>
            <div style="background:#f0fff4;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Issued</div>
                <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalIss, 2) }}</div>
            </div>
            <div style="background:{{ $isFullyIssued ? '#f0fff4' : '#fffff0' }};border-radius:8px;padding:12px;border:{{ $isFullyIssued ? 'none' : '1px solid #faf089' }}">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">
                    {{ $isFullyIssued ? 'Status' : 'Still Outstanding' }}
                </div>
                @if($isFullyIssued)
                    <div style="font-size:18px;font-weight:800;color:var(--success)"><i class="fas fa-check-circle"></i> Complete</div>
                @else
                    <div style="font-size:22px;font-weight:800;color:var(--warning)">{{ number_format($totalRem, 2) }}</div>
                    <div style="font-size:11px;color:var(--warning);margin-top:2px">units still outstanding</div>
                @endif
            </div>
        </div>
        @if(!$isFullyIssued && $totalIss > 0)
        <div style="margin-top:12px;padding:10px 14px;background:#fffff0;border:1px solid #faf089;border-radius:8px;font-size:13px;color:#744210">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Partially fulfilled</strong> — {{ number_format($totalRem, 2) }} units still outstanding.
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
                    <th>Stock No.</th>
                    <th>Unit</th>
                    <th>Description</th>
                    <th>Warehouse</th>
                    <th>Expiration Date</th>
                    <th style="text-align:right">Unit Cost</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Qty Requested</th>
                    <th style="text-align:right">Total Cost</th>
                    <th>Stock Available</th>
                    <th style="text-align:right">Qty Issued</th>
                    <th style="text-align:right">Outstanding</th>
                    <th>DR No.</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requisition->items as $ri)
                @php
                    // Group dispatch items by their unique cost combination (stock_number + unit_cost + engas_unit_cost)
                    // If there are multiple dispatches with different costs, we'll show them as separate rows
                    $dispatchGroups = $ri->dispatchItems->groupBy(function($di) {
                        return ($di->item?->stock_number ?? 'no-stock') . '|' . 
                               ($di->unit_cost ?? 0) . '|' . 
                               ($di->engas_unit_cost ?? 0) . '|' .
                               ($di->item?->warehouse_id ?? 0) . '|' .
                               ($di->expiration_date?->format('Y-m-d') ?? 'no-expiry');
                    });
                    
                    // If no dispatches yet, show the requisition item as-is
                    if ($dispatchGroups->isEmpty()) {
                        $dispatchGroups = collect([
                            'original' => collect() // Empty collection for the original request
                        ]);
                    }
                @endphp
                
                @foreach($dispatchGroups as $groupKey => $groupDispatches)
                @php
                    // For this group, get the representative dispatch item (first one) or fall back to RI
                    $representativeDispatch = $groupDispatches->first();
                    $isOriginal = $groupKey === 'original';
                    
                    // Resolve values: use dispatch data if available, otherwise fall back to RI or item
                    if ($representativeDispatch && !$isOriginal) {
                        $stockNumber = $representativeDispatch->item?->stock_number ?? '-';
                        $unit = $representativeDispatch->item?->unit ?? $ri->unit ?? '-';
                        $description = $representativeDispatch->item?->description ?? $ri->description ?? '-';
                        $warehouse = $representativeDispatch->item?->warehouse?->name ?? '-';
                        $expiryDate = $representativeDispatch->expiration_date ?? $representativeDispatch->item?->expiration_date;
                        $unitCost = $representativeDispatch->unit_cost;
                        $engasCost = $representativeDispatch->engas_unit_cost ?? $representativeDispatch->item?->engas_unit_cost;
                        $riSub = ($representativeDispatch->item && in_array($representativeDispatch->item->source_subsidy_status, ['deleted', 'archived'], true)) 
                            ? $representativeDispatch->item 
                            : null;
                        // For dispatched groups, show the quantity from this group's dispatches
                        $groupQtyIssued = $groupDispatches->sum('quantity_issued');
                        $showAsSubRow = $dispatchGroups->count() > 1; // Multiple cost groups
                    } else {
                        // Original requisition item (no dispatches or showing the undispatched portion)
                        $stockNumber = $ri->item?->stock_number ?? '-';
                        $unit = $ri->unit ?? $ri->item?->unit ?? '-';
                        $description = $ri->description ?? $ri->item?->description ?? '-';
                        $warehouse = $ri->warehouse?->name ?? $ri->item?->warehouse?->name ?? '-';
                        $expiryDate = $ri->expiration_date ?? $ri->item?->expiration_date;
                        $unitCost = ($ri->unit_cost !== null && $ri->unit_cost > 0) ? $ri->unit_cost : ($ri->item?->unit_cost ?? null);
                        $engasCost = $ri->engas_unit_cost ?? $ri->item?->engas_unit_cost;
                        $riSub = null;
                        if ($ri->item && in_array($ri->item->source_subsidy_status, ['deleted', 'archived'], true)) {
                            $riSub = $ri->item;
                        }
                        $groupQtyIssued = 0;
                        $showAsSubRow = false;
                    }

                    // Expiry display style
                    if ($expiryDate) {
                        $today    = \Carbon\Carbon::today();
                        $diffDays = $today->diffInDays($expiryDate, false);
                        if ($diffDays < 0) {
                            $expiryStyle = 'color:var(--danger);font-weight:700;text-decoration:line-through';
                            $expiryLabel = ' <span style="font-size:10px;background:var(--danger);color:#fff;border-radius:4px;padding:1px 5px;vertical-align:middle">Expired</span>';
                        } elseif ($diffDays <= 30) {
                            $expiryStyle = 'color:var(--danger);font-weight:600';
                            $expiryLabel = '';
                        } else {
                            $expiryStyle = 'color:var(--success)';
                            $expiryLabel = '';
                        }
                    } else {
                        $expiryStyle = 'color:var(--text-muted)';
                        $expiryLabel = '';
                    }

                    $totalCost = ($unitCost !== null) ? $unitCost * $ri->quantity_requested : null;
                    $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued);
                @endphp
                <tr style="{{ $showAsSubRow ? 'background:#f9fafb;border-left:3px solid var(--primary)' : '' }}">
                    <td>
                        @if($showAsSubRow)
                            <span style="color:var(--text-muted);margin-right:6px">└─</span>
                        @endif
                        <code>{{ $stockNumber }}</code>
                        @if($riSub)
                        <div style="margin-top:5px">
                            @include('partials.subsidy-source-badge', [
                                'status' => $riSub->source_subsidy_status,
                                'ris'    => $riSub->sourceSubsidyReference(),
                                'dr'     => $riSub->sourceDrReference(),
                                'code'   => $riSub->sourceSubsidyCode(),
                                'prefix' => 'RELATED TO',
                            ])
                        </div>
                        @endif
                    </td>
                    <td>{{ $unit }}</td>
                    <td>
                        @if($showAsSubRow)
                            <span style="color:var(--text-muted);font-size:12px">{{ $description }}</span>
                        @else
                            {{ $description }}
                        @endif
                    </td>
                    <td>
                        <span style="font-size:12px">{{ $warehouse }}</span>
                    </td>

                    {{-- Expiration Date --}}
                    <td>
                        @if($expiryDate)
                            <span style="{{ $expiryStyle }};font-size:13px">
                                {{ $expiryDate->format('M d, Y') }}{!! $expiryLabel !!}
                            </span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>

                    {{-- Unit Cost --}}
                    <td style="text-align:right;white-space:nowrap">
                        @if($unitCost !== null)
                            <span style="{{ $showAsSubRow ? 'font-weight:600;color:var(--primary)' : '' }}">
                                ₱&nbsp;{{ number_format($unitCost, 2) }}
                            </span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>

                    {{-- Engas Unit Cost + Total --}}
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right;white-space:nowrap">
                        @if($engasCost !== null)
                            <span style="{{ $showAsSubRow ? 'font-weight:600;' : '' }}color:#059669">
                                ₱&nbsp;{{ number_format($engasCost, 2) }}
                            </span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        @if($engasCost !== null && !$showAsSubRow)
                            <span style="color:#059669;font-weight:600">₱&nbsp;{{ number_format($ri->quantity_requested * $engasCost, 2) }}</span>
                        @elseif($engasCost !== null && $showAsSubRow)
                            <span style="color:#059669">₱&nbsp;{{ number_format($groupQtyIssued * $engasCost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif

                    {{-- Qty Requested - only show on first row of group --}}
                    <td style="text-align:right">
                        @if(!$showAsSubRow)
                            {{ number_format($ri->quantity_requested) }}
                        @else
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>

                    {{-- Total Cost --}}
                    <td style="text-align:right;white-space:nowrap;font-weight:600">
                        @if($totalCost !== null && !$showAsSubRow)
                            ₱&nbsp;{{ number_format($totalCost, 2) }}
                        @elseif($unitCost !== null && $showAsSubRow)
                            <span style="color:var(--text-muted);font-size:12px">
                                ₱&nbsp;{{ number_format($groupQtyIssued * $unitCost, 2) }}
                            </span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>

                    <td>
                        @if(!$showAsSubRow)
                            @if($ri->stock_available)
                            <span class="badge badge-success"><i class="fas fa-check"></i> Yes</span>
                            @else
                            <span class="badge badge-danger"><i class="fas fa-times"></i> No</span>
                            @endif
                        @else
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    
                    {{-- Qty Issued --}}
                    <td style="text-align:right">
                        @if(!$showAsSubRow)
                            {{ $ri->quantity_issued > 0 ? number_format($ri->quantity_issued) : '—' }}
                        @else
                            <span style="color:var(--success);font-weight:600">{{ number_format($groupQtyIssued) }}</span>
                        @endif
                    </td>
                    
                    {{-- Outstanding --}}
                    <td style="text-align:right">
                        @if(!$showAsSubRow)
                            @if($outstanding > 0)
                                <span style="color:var(--warning);font-weight:600">{{ number_format($outstanding, 2) }}</span>
                            @else
                                <span class="badge badge-success"><i class="fas fa-check"></i> Fulfilled</span>
                            @endif
                        @else
                            <span style="color:var(--text-muted);font-size:12px">—</span>
                        @endif
                    </td>
                    
                    {{-- DR No. --}}
                    <td>
                        @if($showAsSubRow && $groupDispatches->isNotEmpty())
                            @php $drList = $groupDispatches->pluck('dr_number')->filter()->unique()->values(); @endphp
                            @if($drList->isNotEmpty())
                                @foreach($drList as $dr)
                                <code style="font-size:11px;margin-right:4px">{{ $dr }}</code>
                                @endforeach
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        @elseif(!$showAsSubRow)
                            @php $drList = $ri->dispatchItems->pluck('dr_number')->filter()->unique()->values(); @endphp
                            @if($drList->isNotEmpty())
                                @foreach($drList as $dr)
                                <code style="font-size:12px;margin-right:4px">{{ $dr }}</code>
                                @endforeach
                            @elseif($ri->dr_number)
                                <code style="font-size:12px">{{ $ri->dr_number }}</code>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        @endif
                    </td>
                    
                    {{-- Remarks --}}
                    <td>
                        @if(!$showAsSubRow)
                            {{ $ri->remarks ?? '' }}
                        @else
                            <span style="color:var(--text-muted);font-size:11px">
                                {{ $groupDispatches->count() }} dispatch(es)
                            </span>
                        @endif
                    </td>
                </tr>
                @endforeach
                @endforeach
            </tbody>
            @php
                $grandTotal = $requisition->items->sum(function ($ri) {
                    $cost = ($ri->unit_cost !== null && $ri->unit_cost > 0)
                        ? $ri->unit_cost
                        : ($ri->item?->unit_cost ?? 0);
                    return $cost * $ri->quantity_requested;
                });
                $labelColspan = 7 + (auth()->user()->hasAdminAccess() ? 2 : 0);
            @endphp
            @if($grandTotal > 0)
            <tfoot>
                <tr>
                    <td colspan="{{ $labelColspan }}" style="text-align:right;font-weight:700;padding:10px 12px">Grand Total</td>
                    <td style="text-align:right;font-weight:800;white-space:nowrap;color:var(--primary)">
                        ₱&nbsp;{{ number_format($grandTotal, 2) }}
                    </td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

<!-- Partial Delivery Breakdown -->
@if($totalIss > 0)
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Partial Delivery Breakdown</h3>
        <span style="font-size:12px;color:var(--text-muted)">
            Detailed issuance history per item — each dispatch keeps its own warehouse, DR Number and costs
        </span>
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
                <strong style="font-size:14px">{{ $ri->description ?? ($ri->item?->description ?? '—') }}</strong>
                <span style="font-size:12px;color:var(--text-muted);margin-left:6px">{{ $ri->unit ?? '' }}</span>
                @if($ri->dispatchItems->pluck('item.warehouse.name')->filter()->unique()->isNotEmpty())
                    <span style="font-size:11px;background:#f0fff4;padding:1px 6px;border-radius:4px;color:var(--success);margin-left:6px">
                        <i class="fas fa-warehouse"></i> {{ $ri->dispatchItems->pluck('item.warehouse.name')->filter()->unique()->implode(', ') }}
                    </span>
                @endif
                @if($ri->dr_number)
                    <span style="font-size:11px;background:#eef2ff;padding:1px 6px;border-radius:4px;color:#4f46e5;margin-left:6px">
                        <i class="fas fa-file-alt"></i> DR {{ $ri->dr_number }}
                    </span>
                @endif
            </div>
            <div style="display:flex;gap:16px;font-size:12px;text-align:right">
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Requested</div>
                    <strong>{{ number_format($ri->quantity_requested) }}</strong>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Issued</div>
                    <strong style="color:var(--success)">{{ number_format($ri->quantity_issued) }}</strong>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Outstanding</div>
                    <strong style="color:{{ $outstanding > 0 ? 'var(--warning)' : 'var(--success)' }}">
                        {{ $outstanding > 0 ? number_format($outstanding, 2) : '✓ Done' }}
                    </strong>
                </div>
            </div>
        </div>

        {{-- Per-item progress bar --}}
        <div style="padding:8px 20px">
            <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden">
                <div style="background:{{ $itemPct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $itemPct }}%;height:100%;border-radius:999px"></div>
            </div>
            <div style="font-size:10px;color:var(--text-muted);margin-top:3px">{{ $itemPct }}% fulfilled</div>
        </div>

        @if($dispatches->isEmpty())
            <div style="padding:12px 20px;font-size:13px;color:var(--text-muted)">
                <i class="fas fa-info-circle"></i> No issuances recorded for this item yet.
            </div>
        @else
            {{-- Dispatch history table --}}
            @php $runningTotal = 0; @endphp
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f0f9ff">
                        <th style="padding:8px 20px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">#</th>
                        <th style="padding:8px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Date</th>
                        <th style="padding:8px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Stock No.</th>
                        <th style="padding:8px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Warehouse</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Qty</th>
                        <th style="padding:8px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">DR No.</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Unit Cost</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Value</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">ENGAS Unit Cost</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">ENGAS Total</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Cumulative</th>
                        @if(auth()->user()->canApprove())
                        <th style="padding:8px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Actions</th>
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
                        $engasTotal = $di->engas_unit_cost ? ($di->quantity_issued * $di->engas_unit_cost) : null;
                    @endphp
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:10px 20px;color:var(--text-muted);font-size:12px">{{ $diNum + 1 }}</td>
                        <td style="padding:10px 14px">
                            <strong>{{ $di->created_at?->format('M d, Y') }}</strong>
                            @if($di->expiration_date)
                                <div style="font-size:11px;color:var(--text-muted)">Exp. {{ $di->expiration_date->format('M d, Y') }}</div>
                            @endif
                            @if($di->item && in_array($di->item->source_subsidy_status, ['deleted', 'archived'], true))
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
                        <td style="padding:10px 14px">
                            @if($di->item && $di->item->stock_number)
                                <code style="font-size:11px;background:#f0fdf4;padding:2px 6px;border-radius:4px;color:var(--success)">
                                    {{ $di->item->stock_number }}
                                </code>
                            @else
                                <span style="color:var(--text-muted);font-size:11px">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px">{{ $di->item?->warehouse?->name ?? '—' }}</td>
                        <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--primary)">
                            +{{ number_format($di->quantity_issued) }}
                        </td>
                        <td style="padding:10px 14px">
                            <code style="font-size:12px">{{ $di->dr_number ?? '—' }}</code>
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($di->unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($di->quantity_issued * $di->unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            @if($di->engas_unit_cost)
                                <span style="color:#059669">₱{{ number_format($di->engas_unit_cost, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            @if($engasTotal)
                                <span style="color:#059669;font-weight:600">₱{{ number_format($engasTotal, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="font-weight:600">{{ number_format($runningTotal, 2) }}</span>
                            <span style="font-size:10px;color:var(--text-muted);margin-left:4px">
                                / {{ number_format($ri->quantity_requested) }} ({{ $cumulativePct }}%)
                            </span>
                        </td>
                        @if(auth()->user()->canWrite())
                        <td style="padding:10px 14px;white-space:nowrap">
                            <button type="button" class="btn btn-sm btn-outline btn-icon"
                                    onclick="openDispatchEditModal({{ $di->id }})"
                                    title="Edit this issued item (warehouse, quantity, costs, DR, expiry)">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
                        <td colspan="4" style="padding:10px 20px;font-size:13px">Total Issued</td>
                        <td style="padding:10px 14px;text-align:right;color:var(--success)">
                            {{ number_format($ri->quantity_issued) }}
                        </td>
                        <td></td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($dispatches->sum(fn($d) => $d->quantity_issued * $d->unit_cost), 2) }}
                        </td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right">
                            @php $totalEngas = $dispatches->sum(fn($d) => $d->engas_unit_cost ? ($d->quantity_issued * $d->engas_unit_cost) : 0); @endphp
                            @if($totalEngas > 0)
                                <span style="color:#059669;font-weight:600">₱{{ number_format($totalEngas, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="color:{{ $outstanding > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $outstanding > 0 ? number_format($outstanding, 2).' outstanding' : '✓ Fully issued' }}
                            </span>
                        </td>
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

<!-- Correction History -->
@if(auth()->user()->canWrite() && $requisition->auditLogs()->exists())
@php $recentCorrections = $requisition->auditLogs()->with('user')->take(5)->get(); @endphp
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Recent Corrections</h3>
        <a href="{{ route('requisitions.audit_log', $requisition->id) }}" class="btn btn-sm btn-outline">View Full History</a>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Corrected By</th>
                    <th>Changes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentCorrections as $log)
                <tr>
                    <td style="white-space:nowrap;font-size:12px">
                        {{ $log->created_at->format('M d, Y') }}<br>
                        <span style="color:var(--text-muted)">{{ $log->created_at->format('h:i A') }}</span>
                    </td>
                    <td style="font-size:13px">
                        <strong>{{ $log->user->name ?? '—' }}</strong><br>
                        <span style="font-size:11px;color:var(--text-muted)">{{ $log->user?->getRoleLabel() ?? '' }}</span>
                    </td>
                    <td style="font-size:12px">
                        @php
                            $lines = collect($log->changed_fields)->map(function ($change, $field) {
                                $label = match (true) {
                                    str_starts_with($field, 'items.') && str_ends_with($field, '.item') => 'Item',
                                    str_starts_with($field, 'items.') => 'Requested Qty',
                                    $field === 'total_requested' => 'Total Requested',
                                    $field === 'status' => 'RIS Status',
                                    default => ucfirst(str_replace('_', ' ', $field)),
                                };
                                return '<strong>'.$label.'</strong>: '
                                    .(is_numeric($change['old'] ?? null) ? number_format((float) $change['old'], 2) : ($change['old'] ?? '—'))
                                    .' → '
                                    .(is_numeric($change['new'] ?? null) ? number_format((float) $change['new'], 2) : ($change['new'] ?? '—'));
                            })->take(4);
                        @endphp
                        <span>{!! $lines->implode('<br>') !!}</span>
                        @if(count($log->changed_fields) > 4)
                        <a href="{{ route('requisitions.audit_log', $requisition->id) }}" style="display:block;margin-top:2px;font-size:11px">
                            + {{ count($log->changed_fields) - 4 }} more
                        </a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
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
@include('requisitions._correct_ris_modal')
@endif
