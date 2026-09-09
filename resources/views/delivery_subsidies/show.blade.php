@extends('layouts.app')
@section('title', 'Delivery/Subsidy Details')
@section('page-title', 'Delivery/Subsidy Details')

@section('content')
<div class="page-header">
    <div>
        <h1>RIS #{{ $deliverySubsidy->ris_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('dashboard') }}">Dashboard</a> /
            <a href="{{ route('delivery_subsidies.index') }}">Delivery / Subsidies</a> / View
        </div>
    </div>
    <div style="display:flex;gap:8px">
        @if($deliverySubsidy->status !== 'fully_delivered' && $deliverySubsidy->status !== 'cancelled')
        <a href="{{ route('delivery_subsidies.delivery', $deliverySubsidy->id) }}" class="btn btn-success">
            <i class="fas fa-truck"></i> Record Delivery
        </a>
        @endif
        @if(auth()->user()->canWrite())
        <button type="button" class="btn btn-secondary" onclick="openEditModal({{ $deliverySubsidy->id }})">
            <i class="fas fa-edit"></i> Edit Subsidy
        </button>
        @endif
        @if(auth()->user()->isAdmin())
        <a href="{{ route('delivery_subsidies.audit_log', $deliverySubsidy->id) }}" class="btn btn-outline">
            <i class="fas fa-history"></i> Correction History
        </a>
        @endif
        @if(auth()->user()->canWrite())
        <form action="{{ route('delivery_subsidies.destroy', $deliverySubsidy->id) }}" method="POST"
            onsubmit="return confirm('Delete RIS #{{ $deliverySubsidy->ris_number }}?\n\nThis will permanently delete the record and reverse all delivered stock quantities. Related stock transfers will be preserved and flagged for review.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash"></i> Delete Subsidy
            </button>
        </form>
        @endif
        <button onclick="window.print()" class="btn btn-outline no-print">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

{{-- Subsidy Info + Status --}}
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Delivery/Subsidy Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px">
                <div><span style="color:var(--text-muted)">Subsidy ID</span><br><code style="font-weight:700;color:var(--primary)">{{ $deliverySubsidy->subsidy_code }}</code></div>
                <div><span style="color:var(--text-muted)">RIS No.</span><br><strong>{{ $deliverySubsidy->ris_number }}</strong></div>
                <div><span style="color:var(--text-muted)">Date</span><br>{{ $deliverySubsidy->date?->format('F d, Y') ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Supplier</span><br><strong>{{ $deliverySubsidy->supplier->name ?? '-' }}</strong></div>
            </div>
            @if($deliverySubsidy->remarks)
            <div style="margin-top:14px;padding:10px;background:#f7fafc;border-radius:6px;font-size:13px">
                <strong>Remarks:</strong> {{ $deliverySubsidy->remarks }}
            </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Status & Summary</h3></div>
        <div class="card-body" style="text-align:center">
            <span class="badge {{ $deliverySubsidy->getStatusBadgeClass() }}">
                {{ ucfirst(str_replace('_', ' ', $deliverySubsidy->status)) }}
            </span>
            <div style="margin-top:20px">
                <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Total Amount</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary)">₱{{ number_format($deliverySubsidy->total_amount, 2) }}</div>
            </div>
            <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
                <div style="background:#f7fafc;border-radius:8px;padding:10px">
                    <div style="color:var(--text-muted)">Items Ordered</div>
                    <div style="font-weight:700;font-size:18px">{{ $deliverySubsidy->items->count() }}</div>
                </div>
                <div style="background:#f7fafc;border-radius:8px;padding:10px">
                    <div style="color:var(--text-muted)">Shipments</div>
                    <div style="font-weight:700;font-size:18px">{{ $deliverySubsidy->deliveries->count() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Fulfilment Progress Bar --}}
@php
    $totalRequested = (float) $deliverySubsidy->quantity_requested;
    $totalDelivered = $deliverySubsidy->totalDelivered();
    $totalRemaining = max(0, $totalRequested - $totalDelivered);
    $pct            = $totalRequested > 0 ? min(100, round($totalDelivered / $totalRequested * 100)) : 0;
    $isComplete     = $totalRemaining <= 0 && $totalDelivered > 0;
@endphp
@if($totalRequested > 0)
<div class="card" style="margin-bottom:24px">
    <div class="card-body" style="padding:20px 24px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px">
            <span style="font-weight:700;font-size:14px">
                <i class="fas fa-truck" style="color:var(--primary);margin-right:6px"></i>
                Delivery Fulfilment
            </span>
            <span style="font-size:13px;color:var(--text-muted)">
                {{ $deliverySubsidy->deliveries->count() }} shipment(s) recorded
            </span>
        </div>

        {{-- Progress bar --}}
        <div style="background:var(--border);border-radius:999px;height:14px;overflow:hidden;margin-bottom:10px">
            <div style="background:{{ $isComplete ? 'var(--success)' : 'var(--primary)' }};width:{{ $pct }}%;height:100%;border-radius:999px;transition:width .4s;position:relative">
                @if($pct >= 15)
                <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10px;font-weight:700;color:white">{{ $pct }}%</span>
                @endif
            </div>
        </div>

        {{-- Stats row --}}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;text-align:center">
            <div style="background:var(--info-bg);border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Requested</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalRequested) }}</div>
            </div>
            <div style="background:#f0fff4;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Delivered</div>
                <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalDelivered) }}</div>
            </div>
            <div style="background:{{ $isComplete ? '#f0fff4' : '#fffff0' }};border-radius:8px;padding:12px;border:{{ $isComplete ? 'none' : '1px solid #faf089' }}">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">
                    {{ $isComplete ? 'Status' : 'Still Needed' }}
                </div>
                @if($isComplete)
                    <div style="font-size:18px;font-weight:800;color:var(--success)">
                        <i class="fas fa-check-circle"></i> Complete
                    </div>
                @else
                    <div style="font-size:22px;font-weight:800;color:var(--warning)">{{ number_format($totalRemaining) }}</div>
                    <div style="font-size:11px;color:var(--warning);margin-top:2px">more needed to complete</div>
                @endif
            </div>
        </div>

        @if(!$isComplete && $totalDelivered > 0)
        <div style="margin-top:12px;padding:10px 14px;background:#fffff0;border:1px solid #faf089;border-radius:8px;font-size:13px;color:#744210">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Partial delivery</strong> — {{ number_format($totalRemaining) }} units still outstanding.
            @if($deliverySubsidy->status !== 'cancelled')
            <a href="{{ route('delivery_subsidies.delivery', $deliverySubsidy->id) }}" style="color:var(--primary);font-weight:600;margin-left:6px">
                Record next shipment →
            </a>
            @endif
        </div>
        @elseif($totalDelivered == 0)
        <div style="margin-top:12px;padding:10px 14px;background:#f7fafc;border-radius:8px;font-size:13px;color:var(--text-muted)">
            <i class="fas fa-info-circle"></i> No deliveries recorded yet.
            @if($deliverySubsidy->status !== 'cancelled')
            <a href="{{ route('delivery_subsidies.delivery', $deliverySubsidy->id) }}" style="color:var(--primary);font-weight:600;margin-left:6px">
                Record first shipment →
            </a>
            @endif
        </div>
        @endif
    </div>
</div>
@endif

{{-- Ordered Line Items --}}
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> Ordered Items</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="text-align:center">Description</th>
                    <th style="text-align:center">Unit</th>
                    <th style="text-align:center">Ordered Qty</th>
                    <th style="text-align:center">Delivered Qty</th>
                    <th style="text-align:center">Remaining</th>
                    <th style="text-align:center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliverySubsidy->items as $poi)
                @php
                    $poiDelivered = (float) $poi->qty_delivered;
                    $poiComplete  = $poiDelivered >= (float) $poi->quantity - 0.0001;
                    $poiPending   = $poiDelivered <= 0;
                @endphp
                <tr>
                    <td style="text-align:center"><strong>{{ $poi->item->description ?? $poi->description ?? '-' }}</strong></td>
                    <td style="text-align:center">{{ $poi->item->unit ?? $poi->unit ?? '-' }}</td>
                    <td style="text-align:center">{{ number_format($poi->quantity) }}</td>
                    <td style="text-align:center">{{ number_format($poi->qty_delivered) }}</td>
                    <td style="text-align:center">
                        <span class="{{ ($poi->quantity - $poi->qty_delivered) > 0 ? 'badge badge-warning' : 'badge badge-success' }}">
                            {{ number_format($poi->quantity - $poi->qty_delivered) }}
                        </span>
                    </td>
                    <td style="text-align:center">
                        @if($poiComplete)
                            <span class="badge badge-success"><i class="fas fa-check"></i> Complete</span>
                        @elseif($poiPending)
                            <span class="badge badge-warning">Pending</span>
                        @else
                            <span class="badge badge-info">Partially Delivered</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Delivery History with generated stock numbers --}}
@if($deliverySubsidy->deliveries->count() > 0)

{{-- ── Per-item partial delivery breakdown ─────────────────────────────────── --}}
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Partial Delivery Breakdown by Item</h3>
    </div>
    @foreach($deliverySubsidy->items as $poi)
    @php
        // Collect every delivery_item row for this subsidy line, across all shipments
        $allDiForItem = $deliverySubsidy->deliveries
            ->flatMap(fn($d) => $d->items->where('delivery_subsidy_item_id', $poi->id)
                ->map(fn($di) => ['delivery' => $d, 'di' => $di]))
            ->values();

        $cumulativeQty  = 0;
        $orderedQty     = (float) $poi->quantity;
    @endphp

    <div style="border-bottom:1px solid var(--border)">
        {{-- Item header --}}
        <div style="padding:12px 20px;background:#f7fafc;display:flex;justify-content:space-between;align-items:center">
            <div>
                <strong style="font-size:11px">{{ $poi->item->description ?? $poi->description ?? '—' }}</strong>
            </div>
            <div style="display:flex;gap:16px;font-size:11px;text-align:right">
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Ordered</div>
                    <strong>{{ number_format($orderedQty) }}</strong>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Delivered</div>
                    <strong style="color:var(--success)">{{ number_format($poi->qty_delivered) }}</strong>
                </div>
                <div>
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Remaining</div>
                    @php $lineRem = max(0, $orderedQty - $poi->qty_delivered); @endphp
                    <strong style="color:{{ $lineRem > 0 ? 'var(--warning)' : 'var(--success)' }}">
                        {{ $lineRem > 0 ? number_format($lineRem) : '✓ Complete' }}
                    </strong>
                </div>
            </div>
        </div>

        @if($allDiForItem->isEmpty())
            <div style="padding:12px 20px;font-size:11px;color:var(--text-muted)">
                <i class="fas fa-info-circle"></i> No deliveries recorded for this item yet.
            </div>
        @else
            {{-- Progress bar --}}
            @php
                $itemPct = $orderedQty > 0 ? min(100, round($poi->qty_delivered / $orderedQty * 100)) : 0;
            @endphp
            <div style="padding:8px 20px">
                <div style="background:var(--border);border-radius:999px;height:8px;overflow:hidden">
                    <div style="background:{{ $itemPct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $itemPct }}%;height:100%;border-radius:999px"></div>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:3px">{{ $itemPct }}% fulfilled</div>
            </div>

            {{-- Shipment-by-shipment breakdown --}}
            <div class="table-wrapper">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="background:var(--surface-soft)">
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Shipment DR No.</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Stock No.</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Date</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Warehouse</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Expiration</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Qty This Shipment</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Cumulative</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Unit Cost</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Value</th>
                        @if(auth()->user()->hasAdminAccess())
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--primary)">ENGAS Unit Cost</th>
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--primary)">ENGAS Total Value</th>
                        @endif
                        <th style="padding:8px 14px;text-align:center;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allDiForItem as $shipNum => $row)
                    @php
                        $cumulativeQty += $row['di']->quantity_delivered;
                        $cumulativePct  = $orderedQty > 0 ? min(100, round($cumulativeQty / $orderedQty * 100)) : 0;
                    @endphp
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:10px 14px;text-align:center">
                            <strong>{{ $row['di']->dr_number ?? $row['delivery']->dr_number }}</strong>
                            @if($row['delivery']->batch_number)
                                <span style="font-size:11px;color:var(--text-muted);margin-left:4px">Batch: {{ $row['delivery']->batch_number }}</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($row['di']->item?->stock_number)
                                {{ $row['di']->item->stock_number }}
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center;color:var(--text-muted)">
                            {{ $row['delivery']->delivery_date->format('M d, Y') }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @php $shipWh = $row['di']->warehouse; @endphp
                            @if($shipWh)
                                <span style="font-weight:600;white-space:nowrap">
                                    {{ $shipWh->name }}
                                </span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            {{-- Expiration of the exact stock record (batch) this dispatch delivered into --}}
                            @php $shipExp = $row['di']->item?->expiration_date; @endphp
                            @if($shipExp)
                                <span style="white-space:nowrap">{{ $shipExp->format('M d, Y') }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center;font-weight:700;color:var(--primary)">
                            +{{ number_format($row['di']->quantity_delivered) }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            <span style="font-weight:600">{{ number_format($cumulativeQty) }}</span>
                            <span style="font-size:11px;color:var(--text-muted);margin-left:4px">/ {{ number_format($orderedQty) }} ({{ $cumulativePct }}%)</span>
                        </td>
                        <td style="padding:10px 14px;text-align:center">₱{{ number_format($row['di']->unit_cost, 2) }}</td>
                        <td style="padding:10px 14px;text-align:center">₱{{ number_format($row['di']->quantity_delivered * $row['di']->unit_cost, 2) }}</td>
                        @if(auth()->user()->hasAdminAccess())
                        <td style="padding:10px 14px;text-align:center">
                            @if($row['di']->engas_unit_cost !== null)
                                <span style="color:var(--primary)">₱{{ number_format($row['di']->engas_unit_cost, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            @if($row['di']->engas_total_value !== null)
                                <span style="color:var(--primary);font-weight:600">₱{{ number_format($row['di']->engas_total_value, 2) }}</span>
                            @else
                                <span style="color:var(--text-muted)">—</span>
                            @endif
                        </td>
                        @endif
                        <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                            {{-- Stock Card link --}}
                            @if($row['di']->item)
                                <a href="{{ route('stock_cards.item_history', $row['di']->item->id) }}"
                                   class="btn btn-sm btn-outline btn-icon"
                                   title="View Stock Card{{ $row['di']->item->stock_number ? ': ' . $row['di']->item->stock_number : '' }}">
                                    <i class="fas fa-book"></i>
                                </a>
                            @endif
                            {{-- Edit shipment --}}
                            @if(auth()->user()->canWrite())
                                <a href="{{ route('delivery_subsidies.edit_delivery', [$deliverySubsidy->id, $row['delivery']->id]) }}"
                                   class="btn btn-sm btn-outline btn-icon" title="Edit Shipment">
                                    <i class="fas fa-edit"></i>
                                </a>
                                {{-- Delete shipment --}}
                                <form method="POST"
                                      action="{{ route('delivery_subsidies.destroy_delivery', [$deliverySubsidy->id, $row['delivery']->id]) }}"
                                      style="display:inline"
                                      onsubmit="return confirm('Delete this shipment and reverse its stock movement? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Delete Shipment">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
                        <td colspan="5" style="padding:10px 20px">Total Delivered</td>
                        <td style="padding:10px 14px;text-align:center;color:var(--success)">
                            {{ number_format($poi->qty_delivered) }}
                        </td>
                        <td style="padding:10px 14px;text-align:center">
                            <span style="color:{{ $lineRem > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $lineRem > 0 ? number_format($lineRem).' remaining' : '✓ Fully delivered' }}
                            </span>
                        </td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:center">
                            ₱{{ number_format($allDiForItem->sum(fn($r) => $r['di']->quantity_delivered * $r['di']->unit_cost), 2) }}
                        </td>
                        @if(auth()->user()->hasAdminAccess())
                        <td></td>
                        <td style="padding:10px 14px;text-align:center;color:var(--primary)">
                            {{-- Cumulative ENGAS = Σ (each shipment's qty × that shipment's own ENGAS unit cost) --}}
                            @php $engasLineTotal = $allDiForItem->sum(fn($r) => $r['di']->engas_total_value); @endphp
                            {{ $engasLineTotal > 0 ? '₱'.number_format($engasLineTotal, 2) : '—' }}
                        </td>
                        @endif
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        @endif
    </div>
    @endforeach
</div>

{{-- Individual Shipment Records section intentionally removed. Per-shipment
     detail with edit/delete actions remains in Partial Delivery Breakdown
     by Item above. --}}
@endif

@if(auth()->user()->canWrite())
@include('delivery_subsidies._edit_form')
@endif
@endsection
