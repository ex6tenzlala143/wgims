@extends('layouts.app')
@section('title', 'RIS Details')
@section('page-title', 'RIS Details')

@section('content')
<div class="page-header">
    <div>
        <h1>RIS #{{ $requisition->ris_number }}</h1>
        <div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a> / <a href="{{ route('requisitions.index') }}">Requisitions</a> / View</div>
    </div>
    <div style="display:flex;gap:8px">
        @if(($requisition->status == 'pending' || $requisition->status == 'partially_approved') && auth()->user()->canApprove())
        <a href="{{ route('requisitions.approve', $requisition->id) }}" class="btn btn-success">
            <i class="fas fa-check"></i>
            {{ $requisition->status == 'partially_approved' ? 'Issue Remaining Items' : 'Approve' }}
        </a>
        @endif
        <a href="{{ route('requisitions.signatories', $requisition->id) }}" class="btn btn-secondary"><i class="fas fa-signature"></i> Signatories</a>
        <a href="{{ route('requisitions.print', $requisition->id) }}" class="btn btn-outline" target="_blank"><i class="fas fa-print"></i> Print RIS</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
    <div class="card">
        <div class="card-header"><h3>RIS Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px">
                <div><span style="color:var(--text-muted)">RIS Number:</span><br><strong>{{ $requisition->ris_number }}</strong></div>
                <div><span style="color:var(--text-muted)">Date Requested:</span><br>{{ $requisition->date_requested->format('F d, Y') }}</div>
                <div><span style="color:var(--text-muted)">Entity Name:</span><br>{{ $requisition->entity_name ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Fund Cluster:</span><br>{{ $requisition->fund_cluster ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">DR Number:</span><br><strong>{{ $requisition->dr_number ?? '-' }}</strong></div>
                <div><span style="color:var(--text-muted)">Office:</span><br>{{ $requisition->office ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Division:</span><br>{{ $requisition->division ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Warehouse:</span><br>{{ $requisition->warehouse->name ?? '-' }}</div>
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
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requisition->items as $ri)
                @php
                    // Resolve expiration date: prefer snapshot on the line item, fall back to live item
                    $expiryDate = $ri->expiration_date ?? $ri->item?->expiration_date;

                    // Expiry display style
                    if ($expiryDate) {
                        $today    = \Carbon\Carbon::today();
                        $diffDays = $today->diffInDays($expiryDate, false); // negative = past
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

                    // Unit cost: prefer snapshot, fall back to live item
                    $unitCost = ($ri->unit_cost !== null && $ri->unit_cost > 0)
                        ? $ri->unit_cost
                        : ($ri->item?->unit_cost ?? null);

                    $totalCost = ($unitCost !== null) ? $unitCost * $ri->quantity_requested : null;
                @endphp
                <tr>
                    <td><code>{{ $ri->item->stock_number ?? '-' }}</code></td>
                    <td>{{ $ri->item->unit ?? '-' }}</td>
                    <td>{{ $ri->item->description ?? '-' }}</td>

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
                            ₱&nbsp;{{ number_format($unitCost, 2) }}
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>

                    {{-- Engas Unit Cost + Total --}}
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right;white-space:nowrap">
                        @if($ri->item?->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱&nbsp;{{ number_format($ri->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        @if($ri->item?->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱&nbsp;{{ number_format($ri->quantity_requested * $ri->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif

                    <td style="text-align:right">{{ number_format($ri->quantity_requested, 2) }}</td>

                    {{-- Total Cost --}}
                    <td style="text-align:right;white-space:nowrap;font-weight:600">
                        @if($totalCost !== null)
                            ₱&nbsp;{{ number_format($totalCost, 2) }}
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>

                    <td>
                        @if($ri->stock_available)
                        <span class="badge badge-success"><i class="fas fa-check"></i> Yes</span>
                        @else
                        <span class="badge badge-danger"><i class="fas fa-times"></i> No</span>
                        @endif
                    </td>
                    <td style="text-align:right">{{ $ri->quantity_issued > 0 ? number_format($ri->quantity_issued, 2) : '—' }}</td>
                    <td style="text-align:right">
                        @php $outstanding = max(0, $ri->quantity_requested - $ri->quantity_issued); @endphp
                        @if($outstanding > 0)
                            <span style="color:var(--warning);font-weight:600">{{ number_format($outstanding, 2) }}</span>
                        @else
                            <span class="badge badge-success"><i class="fas fa-check"></i> Fulfilled</span>
                        @endif
                    </td>
                    <td>{{ $ri->remarks ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
            @php
                $grandTotal = $requisition->items->sum(function ($ri) {
                    $cost = ($ri->unit_cost !== null && $ri->unit_cost > 0)
                        ? $ri->unit_cost
                        : ($ri->item?->unit_cost ?? 0);
                    return $cost * $ri->quantity_requested;
                });
            @endphp
            @if($grandTotal > 0)
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right;font-weight:700;padding:10px 12px">Grand Total</td>
                    <td style="text-align:right;font-weight:800;white-space:nowrap;color:var(--primary)">
                        ₱&nbsp;{{ number_format($grandTotal, 2) }}
                    </td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

<!-- Partial Delivery Breakdown -->
@if($totalIss > 0)
@php
    // Load all issuance stock card entries for this requisition
    $issuanceEntries = \App\Models\StockCardEntry::with('item')
        ->where('reference_type', 'issuance')
        ->where('reference_id', $requisition->id)
        ->orderBy('entry_date')
        ->orderBy('id')
        ->get()
        ->groupBy('item_id');
@endphp
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Partial Delivery Breakdown</h3>
        <span style="font-size:12px;color:var(--text-muted)">
            Detailed issuance history per item — what was delivered and when
        </span>
    </div>

    @foreach($requisition->items as $ri)
    @php
        $itemEntries  = $issuanceEntries->get($ri->item_id, collect());
        $outstanding  = max(0, $ri->quantity_requested - $ri->quantity_issued);
        $itemPct      = $ri->quantity_requested > 0
            ? min(100, round($ri->quantity_issued / $ri->quantity_requested * 100))
            : 0;
    @endphp
    <div style="border-bottom:1px solid var(--border)">

        {{-- Item header --}}
        <div style="padding:12px 20px;background:#f7fafc;display:flex;justify-content:space-between;align-items:center">
            <div>
                <strong style="font-size:14px">{{ $ri->item->description ?? '—' }}</strong>
                <span style="font-size:12px;color:var(--text-muted);margin-left:6px">{{ $ri->item->unit ?? '' }}</span>
                @if($ri->item && $ri->item->stock_number)
                    <code style="font-size:11px;background:#ebf4ff;padding:1px 6px;border-radius:4px;color:var(--primary);margin-left:6px">
                        {{ $ri->item->stock_number }}
                    </code>
                @endif
            </div>
            <div style="display:flex;gap:16px;font-size:12px;text-align:right">
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Requested</div>
                    <strong>{{ number_format($ri->quantity_requested, 2) }}</strong>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Issued</div>
                    <strong style="color:var(--success)">{{ number_format($ri->quantity_issued, 2) }}</strong>
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

        @if($itemEntries->isEmpty())
            <div style="padding:12px 20px;font-size:13px;color:var(--text-muted)">
                <i class="fas fa-info-circle"></i> No issuances recorded for this item yet.
            </div>
        @else
            {{-- Issuance history table --}}
            @php $runningTotal = 0; @endphp
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f0f9ff">
                        <th style="padding:8px 20px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">#</th>
                        <th style="padding:8px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Date Issued</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Qty Issued</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Cumulative</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Unit Cost</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Value Issued</th>
                        <th style="padding:8px 14px;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:600">Balance After</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($itemEntries as $entryNum => $entry)
                    @php
                        $runningTotal += $entry->issue_qty;
                        $cumulativePct = $ri->quantity_requested > 0
                            ? min(100, round($runningTotal / $ri->quantity_requested * 100))
                            : 0;
                    @endphp
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:10px 20px;color:var(--text-muted);font-size:12px">{{ $entryNum + 1 }}</td>
                        <td style="padding:10px 14px">
                            <strong>{{ $entry->entry_date->format('M d, Y') }}</strong>
                        </td>
                        <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--primary)">
                            +{{ number_format($entry->issue_qty, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="font-weight:600">{{ number_format($runningTotal, 2) }}</span>
                            <span style="font-size:10px;color:var(--text-muted);margin-left:4px">
                                / {{ number_format($ri->quantity_requested, 2) }} ({{ $cumulativePct }}%)
                            </span>
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($entry->balance_unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($entry->issue_qty * $entry->balance_unit_cost, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right;color:var(--text-muted)">
                            {{ number_format($entry->balance_qty, 2) }} in stock
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
                        <td colspan="2" style="padding:10px 20px;font-size:13px">Total Issued</td>
                        <td style="padding:10px 14px;text-align:right;color:var(--success)">
                            {{ number_format($ri->quantity_issued, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="color:{{ $outstanding > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $outstanding > 0 ? number_format($outstanding, 2).' outstanding' : '✓ Fully issued' }}
                            </span>
                        </td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($itemEntries->sum(fn($e) => $e->issue_qty * $e->balance_unit_cost), 2) }}
                        </td>
                        <td></td>
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
