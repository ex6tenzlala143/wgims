@extends('layouts.app')
@section('title', 'Dispatch Transfer')
@section('page-title', 'Dispatch Transfer')

@section('content')

@php
    $totalRequested  = $transfer->items->sum('quantity_requested');
    $totalTransferred = $transfer->items->sum('quantity');
    $totalRemaining  = max(0, $totalRequested - $totalTransferred);
    $pct             = $totalRequested > 0 ? min(100, round($totalTransferred / $totalRequested * 100)) : 0;
@endphp

<div class="page-header">
    <div>
        <h1>Dispatch — {{ $transfer->transfer_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('transfers.index') }}">Stock Transfers</a> ›
            <a href="{{ route('transfers.show', $transfer) }}">{{ $transfer->transfer_number }}</a> ›
            Dispatch
        </div>
    </div>
</div>

{{-- Progress bar --}}
<div class="card" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px 20px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px">
            <strong>Dispatch Progress — {{ $transfer->transfer_number }}</strong>
            <span>
                <strong style="color:var(--success)">{{ number_format($totalTransferred, 2) }}</strong>
                of <strong>{{ number_format($totalRequested, 2) }}</strong> planned
                &nbsp;—&nbsp;
                <strong style="color:{{ $totalRemaining > 0 ? 'var(--warning)' : 'var(--success)' }}">
                    {{ $totalRemaining > 0 ? number_format($totalRemaining, 2).' still needed' : '✓ Fully dispatched' }}
                </strong>
            </span>
        </div>
        <div style="background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden">
            <div style="background:{{ $pct >= 100 ? 'var(--success)' : 'var(--primary)' }};width:{{ $pct }}%;height:100%;border-radius:999px"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
            {{ $pct }}% dispatched
            &nbsp;·&nbsp;
            <i class="fas fa-warehouse" style="color:var(--danger)"></i>
            {{ $transfer->fromWarehouse->name }}
            <i class="fas fa-arrow-right" style="margin:0 6px;color:var(--primary)"></i>
            <i class="fas fa-warehouse" style="color:var(--success)"></i>
            {{ $transfer->toWarehouse->name }}
        </div>
    </div>
</div>

<div class="alert alert-info" style="font-size:13px">
    <i class="fas fa-info-circle"></i>
    <strong>Partial dispatch supported.</strong>
    Enter how many of each item you are sending in this shipment.
    Leave at 0 to skip an item. You can dispatch again later for any remaining quantities.
</div>

<form action="{{ route('transfers.process_dispatch', $transfer) }}" method="POST" id="dispatch-form">
@csrf

<input type="hidden" name="dispatch_date" value="{{ date('Y-m-d') }}">

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">

    {{-- Left column --}}
    <div>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-boxes"></i> Items to Dispatch</h3>
                <span style="font-size:12px;color:var(--text-muted)">
                    Dispatch Date: <strong>{{ \Carbon\Carbon::today()->format('F d, Y') }}</strong>
                </span>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Item Description</th>
                            <th>Unit</th>
                            <th style="text-align:right">Qty Planned</th>
                            <th style="text-align:right">Already Sent</th>
                            <th style="text-align:right;color:var(--warning)">Still Needed</th>
                            <th style="text-align:right">Available at Source</th>
                            <th style="width:120px">Qty This Dispatch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transfer->items as $sti)
                        @php
                            $remaining   = max(0, $sti->quantity_requested - $sti->quantity);
                            $available   = $sti->sourceItem->quantity ?? 0;
                            $isDone      = $remaining <= 0;
                            $canDispatch = min($remaining, $available);
                        @endphp
                        <tr style="{{ $isDone ? 'background:#f7fafc;opacity:.6' : '' }}">
                            <td>
                                <strong>{{ $sti->sourceItem->description ?? '—' }}</strong>
                                @if($isDone)
                                    <span class="badge badge-success" style="font-size:10px;margin-left:4px">
                                        <i class="fas fa-check"></i> Done
                                    </span>
                                @endif
                                <input type="hidden" name="items[{{ $loop->index }}][sti_id]" value="{{ $sti->id }}">
                            </td>
                            <td>{{ $sti->sourceItem->unit ?? '—' }}</td>
                            <td style="text-align:right;font-weight:600">{{ number_format($sti->quantity_requested, 2) }}</td>
                            <td style="text-align:right;color:var(--success)">{{ number_format($sti->quantity, 2) }}</td>
                            <td style="text-align:right">
                                @if($isDone)
                                    <span class="badge badge-success">—</span>
                                @else
                                    <span style="font-weight:700;color:var(--warning)">{{ number_format($remaining, 2) }}</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <span class="{{ $available >= $remaining ? 'badge badge-success' : 'badge badge-danger' }}">
                                    {{ number_format($available, 2) }}
                                </span>
                            </td>
                            <td>
                                <input type="number"
                                       name="items[{{ $loop->index }}][quantity]"
                                       class="form-control item-qty"
                                       min="0" step="0.0001"
                                       value="{{ $isDone ? 0 : $canDispatch }}"
                                       placeholder="0"
                                       {{ $isDone ? 'disabled' : '' }}
                                       oninput="recalcTotal()"
                                       style="{{ $isDone ? '' : 'border:2px solid var(--primary);font-weight:700' }}">
                                @if(!$isDone)
                                    <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                                        max {{ number_format($canDispatch, 2) }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background:#f0f9ff;font-weight:700">
                            <td style="padding:10px 14px">Totals</td>
                            <td></td>
                            <td style="text-align:right;padding:10px 14px">{{ number_format($totalRequested, 2) }}</td>
                            <td style="text-align:right;padding:10px 14px;color:var(--success)">{{ number_format($totalTransferred, 2) }}</td>
                            <td style="text-align:right;padding:10px 14px;color:var(--warning)">{{ number_format($totalRemaining, 2) }}</td>
                            <td></td>
                            <td style="padding:10px 14px">
                                <span id="dispatch-total" style="font-size:16px;font-weight:800;color:var(--primary)">0.00</span>
                                <div style="font-size:10px;color:var(--text-muted)">this dispatch</div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div>
        <div class="card" style="position:sticky;top:80px">
            <div class="card-header"><h3>Dispatch Summary</h3></div>
            <div class="card-body">
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">Transfer No.</div>
                    <strong>{{ $transfer->transfer_number }}</strong>
                </div>
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">From</div>
                    <strong>{{ $transfer->fromWarehouse->name }}</strong>
                </div>
                <div style="margin-bottom:10px;font-size:13px">
                    <div style="color:var(--text-muted);font-size:11px;margin-bottom:2px">To</div>
                    <strong>{{ $transfer->toWarehouse->name }}</strong>
                </div>

                <hr style="border-color:var(--border);margin:12px 0">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;text-align:center">
                    <div style="background:#f0f9ff;border-radius:8px;padding:10px">
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-bottom:2px">Planned</div>
                        <div style="font-size:20px;font-weight:800;color:var(--primary)">{{ number_format($totalRequested, 2) }}</div>
                    </div>
                    <div style="background:#f0fff4;border-radius:8px;padding:10px">
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-bottom:2px">Sent So Far</div>
                        <div style="font-size:20px;font-weight:800;color:var(--success)">{{ number_format($totalTransferred, 2) }}</div>
                    </div>
                </div>

                {{-- This dispatch qty --}}
                <div style="background:#fff;border:2px solid var(--primary);border-radius:8px;padding:12px;margin-bottom:12px;text-align:center">
                    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">
                        This Dispatch
                    </div>
                    <div id="sidebar-qty" style="font-size:28px;font-weight:800;color:var(--primary)">0.00</div>
                </div>

                {{-- After dispatch projection --}}
                <div style="background:#f7fafc;border-radius:8px;padding:12px;font-size:13px;margin-bottom:16px">
                    <div style="color:var(--text-muted);font-size:11px;font-weight:600;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">After this dispatch</div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span>Total sent</span>
                        <strong id="sidebar-cumul">{{ number_format($totalTransferred, 2) }}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px">
                        <span>Still remaining</span>
                        <strong id="sidebar-remain" style="color:var(--warning)">{{ number_format($totalRemaining, 2) }}</strong>
                    </div>
                    <div id="sidebar-status" style="text-align:center;font-weight:600;padding:8px;border-radius:6px;font-size:12px"></div>
                </div>

                <button type="submit" class="btn btn-success" style="width:100%;justify-content:center;margin-bottom:8px">
                    <i class="fas fa-paper-plane"></i> Dispatch & Update Stock
                </button>
                <a href="{{ route('transfers.show', $transfer) }}"
                   class="btn btn-secondary" style="width:100%;justify-content:center">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </div>

</div>
</form>

@push('scripts')
<script>
const _alreadySent    = {{ $totalTransferred }};
const _totalPlanned   = {{ $totalRequested }};

function recalcTotal() {
    let sum = 0;
    document.querySelectorAll('.item-qty').forEach(function(el) {
        sum += parseFloat(el.value) || 0;
    });

    document.getElementById('dispatch-total').textContent = sum.toFixed(2);
    document.getElementById('sidebar-qty').textContent    = sum.toFixed(2);

    const cumul     = _alreadySent + sum;
    const remaining = Math.max(0, _totalPlanned - cumul);
    const epsilon   = 0.0001;

    document.getElementById('sidebar-cumul').textContent  = cumul.toFixed(2);
    document.getElementById('sidebar-remain').textContent = remaining.toFixed(2);
    document.getElementById('sidebar-remain').style.color = remaining > 0 ? 'var(--warning)' : 'var(--success)';

    const statusEl = document.getElementById('sidebar-status');
    if (sum <= 0) {
        statusEl.textContent      = '';
        statusEl.style.background = 'transparent';
    } else if (cumul >= _totalPlanned - epsilon) {
        statusEl.innerHTML        = '<i class="fas fa-check-circle"></i> Will be Fully Dispatched';
        statusEl.style.color      = 'var(--success)';
        statusEl.style.background = '#f0fff4';
    } else {
        statusEl.innerHTML        = '<i class="fas fa-exclamation-triangle"></i> Will remain Partial — '
                                    + remaining.toFixed(2) + ' still needed';
        statusEl.style.color      = '#744210';
        statusEl.style.background = '#fffff0';
    }
}

document.addEventListener('DOMContentLoaded', recalcTotal);
</script>
@endpush
@endsection
