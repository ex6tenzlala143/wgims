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
        <a href="{{ route('delivery_subsidies.edit', $deliverySubsidy->id) }}" class="btn btn-secondary">
            <i class="fas fa-edit"></i> Edit PO
        </a>
        @endif
        @if(auth()->user()->isAdmin())
        <a href="{{ route('delivery_subsidies.audit_log', $deliverySubsidy->id) }}" class="btn btn-outline">
            <i class="fas fa-history"></i> Audit Log
        </a>
        @endif
        @if(auth()->user()->canWrite())
        <form action="{{ route('delivery_subsidies.destroy', $deliverySubsidy->id) }}" method="POST"
            onsubmit="return confirm('Delete RIS #{{ $deliverySubsidy->ris_number }}?\n\nThis will permanently delete the record and reverse all delivered stock quantities.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash"></i> Delete PO
            </button>
        </form>
        @endif
        <button onclick="window.print()" class="btn btn-outline no-print">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

{{-- PO Info + Status --}}
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-file-invoice-dollar" style="color:var(--primary)"></i> Delivery/Subsidy Information</h3></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:14px">
                <div><span style="color:var(--text-muted)">RIS No.</span><br><strong>{{ $deliverySubsidy->ris_number }}</strong></div>
                <div><span style="color:var(--text-muted)">Date</span><br>{{ $deliverySubsidy->date?->format('F d, Y') ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Supplier/Subsidy</span><br><strong>{{ $deliverySubsidy->supplier->name ?? '-' }}</strong></div>
                <div><span style="color:var(--text-muted)">Warehouse</span><br>{{ $deliverySubsidy->warehouse->name ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Place of Delivery</span><br>{{ $deliverySubsidy->place_of_delivery ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Date of Delivery</span><br>{{ $deliverySubsidy->date_of_delivery?->format('F d, Y') ?? '-' }}</div>
                <div><span style="color:var(--text-muted)">Date of Expiration</span><br>{{ $deliverySubsidy->date_of_expiration ?? '-' }}</div>
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
            <span class="badge {{ $deliverySubsidy->getStatusBadgeClass() }}" style="font-size:15px;padding:10px 22px">
                {{ ucfirst(str_replace('_', ' ', $deliverySubsidy->status)) }}
            </span>
            <div style="margin-top:20px">
                <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Total Amount</div>
                <div style="font-size:30px;font-weight:800;color:var(--primary)">₱{{ number_format($deliverySubsidy->total_amount, 2) }}</div>
            </div>
            <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">
                <div style="background:#f7fafc;border-radius:8px;padding:10px">
                    <div style="color:var(--text-muted)">Qty Requested</div>
                    <div style="font-weight:700;font-size:18px">{{ number_format($deliverySubsidy->quantity_requested, 2) }}</div>
                </div>
                <div style="background:#f7fafc;border-radius:8px;padding:10px">
                    <div style="color:var(--text-muted)">Qty Delivered</div>
                    <div style="font-weight:700;font-size:18px;color:var(--success)">{{ number_format($deliverySubsidy->totalDelivered(), 2) }}</div>
                </div>
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
            <span style="font-weight:700;font-size:15px">
                <i class="fas fa-truck" style="color:var(--primary);margin-right:6px"></i>
                Delivery Fulfilment
            </span>
            <span style="font-size:13px;color:var(--text-muted)">
                {{ $deliverySubsidy->deliveries->count() }} shipment(s) recorded
            </span>
        </div>

        {{-- Progress bar --}}
        <div style="background:#e2e8f0;border-radius:999px;height:14px;overflow:hidden;margin-bottom:10px">
            <div style="background:{{ $isComplete ? 'var(--success)' : 'var(--primary)' }};width:{{ $pct }}%;height:100%;border-radius:999px;transition:width .4s;position:relative">
                @if($pct >= 15)
                <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10px;font-weight:700;color:white">{{ $pct }}%</span>
                @endif
            </div>
        </div>

        {{-- Stats row --}}
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;text-align:center">
            <div style="background:#f0f9ff;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Requested</div>
                <div style="font-size:22px;font-weight:800;color:var(--primary)">{{ number_format($totalRequested, 2) }}</div>
            </div>
            <div style="background:#f0fff4;border-radius:8px;padding:12px">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:4px">Qty Delivered</div>
                <div style="font-size:22px;font-weight:800;color:var(--success)">{{ number_format($totalDelivered, 2) }}</div>
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
                    <div style="font-size:22px;font-weight:800;color:var(--warning)">{{ number_format($totalRemaining, 2) }}</div>
                    <div style="font-size:11px;color:var(--warning);margin-top:2px">more needed to complete</div>
                @endif
            </div>
        </div>

        @if(!$isComplete && $totalDelivered > 0)
        <div style="margin-top:12px;padding:10px 14px;background:#fffff0;border:1px solid #faf089;border-radius:8px;font-size:13px;color:#744210">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Partial delivery</strong> — {{ number_format($totalRemaining, 2) }} units still outstanding.
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
        <span style="font-size:12px;color:var(--text-muted)">Stock numbers are assigned upon delivery based on unit cost</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Unit</th>
                    <th style="text-align:right">Ordered Qty</th>
                    <th style="text-align:right">Delivered Qty</th>
                    <th style="text-align:right">Remaining</th>
                    <th style="text-align:right">Unit Cost (PO)</th>
                    @if(auth()->user()->hasAdminAccess())
                    <th style="text-align:right">Engas Unit Cost</th>
                    <th style="text-align:right">Engas Total Value</th>
                    @endif
                    <th style="text-align:right">Amount</th>
                    <th>Stock Card Assigned</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliverySubsidy->items as $poi)
                <tr>
                    <td><strong>{{ $poi->item->description ?? '-' }}</strong></td>
                    <td>{{ $poi->item->unit ?? '-' }}</td>
                    <td style="text-align:right">{{ number_format($poi->quantity, 2) }}</td>
                    <td style="text-align:right">{{ number_format($poi->qty_delivered, 2) }}</td>
                    <td style="text-align:right">
                        <span class="{{ ($poi->quantity - $poi->qty_delivered) > 0 ? 'badge badge-warning' : 'badge badge-success' }}">
                            {{ number_format($poi->quantity - $poi->qty_delivered, 2) }}
                        </span>
                    </td>
                    <td style="text-align:right">₱{{ number_format($poi->unit_cost, 2) }}</td>
                    @if(auth()->user()->hasAdminAccess())
                    <td style="text-align:right">
                        @if($poi->item && $poi->item->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱{{ number_format($poi->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        @if($poi->item && $poi->item->engas_unit_cost !== null)
                            <span style="color:var(--primary);font-weight:600">₱{{ number_format($poi->quantity * $poi->item->engas_unit_cost, 2) }}</span>
                        @else
                            <span style="color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    @endif
                    <td style="text-align:right">₱{{ number_format($poi->amount, 2) }}</td>
                    <td>
                        @if($poi->item && $poi->item->stock_number)
                            <a href="{{ route('stock_cards.item_history', $poi->item->id) }}"
                               style="font-family:monospace;font-size:12px;color:var(--primary);text-decoration:none;font-weight:600">
                                {{ $poi->item->stock_number }}
                            </a>
                        @else
                            <span style="color:var(--text-muted);font-size:12px;font-style:italic">Pending delivery</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f7fafc;font-weight:700">
                    <td colspan="6" style="text-align:right">TOTAL:</td>
                    <td style="text-align:right">₱{{ number_format($deliverySubsidy->total_amount, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- Delivery History with generated stock numbers --}}
@if($deliverySubsidy->deliveries->count() > 0)

{{-- ── Per-item partial delivery breakdown ─────────────────────────────────── --}}
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <h3><i class="fas fa-layer-group"></i> Partial Delivery Breakdown by Item</h3>
        <span style="font-size:12px;color:var(--text-muted)">
            Cumulative view — how each item's quantity was fulfilled across all shipments
        </span>
    </div>
    @foreach($deliverySubsidy->items as $poi)
    @php
        // Collect every delivery_item row for this PO line, across all shipments
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
                <strong style="font-size:14px">{{ $poi->item->description ?? '—' }}</strong>
                <span style="font-size:12px;color:var(--text-muted);margin-left:8px">{{ $poi->item->unit ?? '' }}</span>
                @if($poi->item && $poi->item->stock_number)
                    <code style="font-size:11px;background:#ebf4ff;padding:1px 6px;border-radius:4px;color:var(--primary);margin-left:6px">
                        {{ $poi->item->stock_number }}
                    </code>
                @endif
            </div>
            <div style="display:flex;gap:16px;font-size:13px;text-align:right">
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Ordered</div>
                    <strong>{{ number_format($orderedQty, 2) }}</strong>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Delivered</div>
                    <strong style="color:var(--success)">{{ number_format($poi->qty_delivered, 2) }}</strong>
                </div>
                <div>
                    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Remaining</div>
                    @php $lineRem = max(0, $orderedQty - $poi->qty_delivered); @endphp
                    <strong style="color:{{ $lineRem > 0 ? 'var(--warning)' : 'var(--success)' }}">
                        {{ $lineRem > 0 ? number_format($lineRem, 2) : '✓ Complete' }}
                    </strong>
                </div>
            </div>
        </div>

        @if($allDiForItem->isEmpty())
            <div style="padding:12px 20px;font-size:13px;color:var(--text-muted)">
                <i class="fas fa-info-circle"></i> No deliveries recorded for this item yet.
            </div>
        @else
            {{-- Progress bar --}}
            @php
                $itemPct = $orderedQty > 0 ? min(100, round($poi->qty_delivered / $orderedQty * 100)) : 0;
            @endphp
            <div style="padding:8px 20px">
                <div style="background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden">
                    <div style="background:{{ $itemPct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $itemPct }}%;height:100%;border-radius:999px"></div>
                </div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:3px">{{ $itemPct }}% fulfilled</div>
            </div>

            {{-- Shipment-by-shipment breakdown --}}
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f0f9ff">
                        <th style="padding:8px 20px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">#</th>
                        <th style="padding:8px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Shipment DR No.</th>
                        <th style="padding:8px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Date</th>
                        <th style="padding:8px 14px;text-align:right;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Qty This Shipment</th>
                        <th style="padding:8px 14px;text-align:right;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Cumulative</th>
                        <th style="padding:8px 14px;text-align:right;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Unit Cost</th>
                        <th style="padding:8px 14px;text-align:right;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Value</th>
                        <th style="padding:8px 14px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allDiForItem as $shipNum => $row)
                    @php
                        $cumulativeQty += $row['di']->quantity_delivered;
                        $cumulativePct  = $orderedQty > 0 ? min(100, round($cumulativeQty / $orderedQty * 100)) : 0;
                    @endphp
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:10px 20px;color:var(--text-muted);font-size:12px">{{ $shipNum + 1 }}</td>
                        <td style="padding:10px 14px">
                            <strong>{{ $row['delivery']->dr_number }}</strong>
                            @if($row['delivery']->batch_number)
                                <span style="font-size:11px;color:var(--text-muted);margin-left:4px">Batch: {{ $row['delivery']->batch_number }}</span>
                            @endif
                        </td>
                        <td style="padding:10px 14px;color:var(--text-muted)">
                            {{ $row['delivery']->delivery_date->format('M d, Y') }}
                        </td>
                        <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--primary)">
                            +{{ number_format($row['di']->quantity_delivered, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="font-weight:600">{{ number_format($cumulativeQty, 2) }}</span>
                            <span style="font-size:10px;color:var(--text-muted);margin-left:4px">/ {{ number_format($orderedQty, 2) }} ({{ $cumulativePct }}%)</span>
                        </td>
                        <td style="padding:10px 14px;text-align:right">₱{{ number_format($row['di']->unit_cost, 2) }}</td>
                        <td style="padding:10px 14px;text-align:right">₱{{ number_format($row['di']->quantity_delivered * $row['di']->unit_cost, 2) }}</td>
                        <td style="padding:10px 14px">
                            <span class="badge {{ $row['di']->condition === 'good' ? 'badge-success' : 'badge-warning' }}">
                                {{ ucfirst($row['di']->condition) }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
                        <td colspan="3" style="padding:10px 20px;font-size:13px">Total Delivered</td>
                        <td style="padding:10px 14px;text-align:right;color:var(--success)">
                            {{ number_format($poi->qty_delivered, 2) }}
                        </td>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="color:{{ $lineRem > 0 ? 'var(--warning)' : 'var(--success)' }}">
                                {{ $lineRem > 0 ? number_format($lineRem, 2).' remaining' : '✓ Fully delivered' }}
                            </span>
                        </td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right">
                            ₱{{ number_format($allDiForItem->sum(fn($r) => $r['di']->quantity_delivered * $r['di']->unit_cost), 2) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
    @endforeach
</div>

{{-- ── Individual shipment records ──────────────────────────────────────────── --}}
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> Shipment Records</h3>
        <span style="font-size:12px;color:var(--text-muted)">Each individual shipment with its stock card assignments</span>
    </div>

    @foreach($deliverySubsidy->deliveries as $delivery)
    <div style="border-bottom:1px solid var(--border)">
        {{-- Delivery header --}}
        <div style="padding:14px 20px;background:#f7fafc;display:flex;justify-content:space-between;align-items:center">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:36px;height:36px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px">
                    {{ $loop->iteration }}
                </div>
                <div>
                    <div style="font-weight:700;font-size:14px">
                        Delivery — {{ $delivery->delivery_date->format('F d, Y') }}
                    </div>
                    <div style="font-size:12px;color:var(--text-muted)">
                        Received by: {{ $delivery->receiver->name ?? '-' }}
                        &nbsp;·&nbsp; DR No.: <strong>{{ $delivery->dr_number ?? '-' }}</strong>
                        @if($delivery->batch_number)
                        &nbsp;·&nbsp; Batch: <strong>{{ $delivery->batch_number }}</strong>
                        @endif
                        &nbsp;·&nbsp; Qty Delivered: <strong>{{ number_format($delivery->quantity_delivered, 2) }}</strong>
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span class="badge {{ $delivery->condition_status == 'good' ? 'badge-success' : 'badge-warning' }}">
                    {{ ucfirst($delivery->condition_status) }}
                </span>
                @if(auth()->user()->canWrite())
                <a href="{{ route('delivery_subsidies.edit_delivery', [$deliverySubsidy->id, $delivery->id]) }}"
                   class="btn btn-sm btn-outline btn-icon" title="Edit Delivery">
                    <i class="fas fa-edit"></i>
                </a>
                @endif
            </div>
        </div>

        {{-- Delivery items with stock numbers --}}
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Generated Stock No.</th>
                        <th>Description</th>
                        <th>Unit</th>
                        <th style="text-align:right">Qty Delivered</th>
                        <th style="text-align:right">Unit Cost</th>
                        <th style="text-align:right">Total Value</th>
                        <th>Condition</th>
                        <th>Stock Card</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($delivery->items as $di)
                    <tr>
                        <td>
                            @if($di->item && $di->item->stock_number)
                                <code style="font-size:12px;background:#ebf4ff;padding:2px 6px;border-radius:4px;color:var(--primary)">
                                    {{ $di->item->stock_number }}
                                </code>
                                @if($di->item->stock_number !== ($di->deliverySubsidyItem->item->stock_number ?? null))
                                    <span class="badge badge-info" style="margin-left:4px;font-size:10px">New</span>
                                @endif
                            @else
                                <span style="color:var(--text-muted);font-size:12px">—</span>
                            @endif
                        </td>
                        <td>{{ $di->item->description ?? '-' }}</td>
                        <td>{{ $di->item->unit ?? '-' }}</td>
                        <td style="text-align:right">{{ number_format($di->quantity_delivered, 2) }}</td>
                        <td style="text-align:right">₱{{ number_format($di->unit_cost, 2) }}</td>
                        <td style="text-align:right">₱{{ number_format($di->quantity_delivered * $di->unit_cost, 2) }}</td>
                        <td>
                            <span class="badge {{ $di->condition == 'good' ? 'badge-success' : 'badge-warning' }}">
                                {{ ucfirst($di->condition) }}
                            </span>
                        </td>
                        <td>
                            @if($di->item && $di->item->stock_number)
                            <a href="{{ route('stock_cards.item_history', $di->item->id) }}"
                               class="btn btn-sm btn-outline btn-icon" title="View Stock Card">
                                <i class="fas fa-book"></i>
                            </a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f7fafc;font-weight:600">
                        <td colspan="5" style="text-align:right;font-size:13px">Delivery Total:</td>
                        <td style="text-align:right">
                            ₱{{ number_format($delivery->items->sum(fn($di) => $di->quantity_delivered * $di->unit_cost), 2) }}
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if($delivery->remarks)
        <div style="padding:8px 20px 12px;font-size:12px;color:var(--text-muted)">
            <i class="fas fa-comment"></i> {{ $delivery->remarks }}
        </div>
        @endif
    </div>
    @endforeach
</div>
@else
<div class="card">
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
        <i class="fas fa-truck" style="font-size:36px;margin-bottom:12px;display:block;opacity:.3"></i>
        No deliveries recorded yet. Stock numbers will appear here once items are delivered.
    </div>
</div>
@endif
@endsection
