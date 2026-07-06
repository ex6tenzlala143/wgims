@extends('layouts.app')
@section('title', 'Edit Transfer — ' . $transfer->transfer_number)
@section('page-title', 'Edit Stock Transfer')

@section('content')
<div class="page-header">
    <div>
        <h1>Edit {{ $transfer->transfer_number }}</h1>
        <div class="breadcrumb">
            <a href="{{ route('transfers.index') }}">Stock Transfers</a> ›
            <a href="{{ route('transfers.show', $transfer) }}">{{ $transfer->transfer_number }}</a> ›
            Edit
        </div>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:20px">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
        <strong>Admin Edit Mode.</strong>
        Changing quantities adjusts stock at both the source and destination warehouse.
        The delta (new − old) is applied — stock is never double-counted.
    </div>
</div>

<form action="{{ route('transfers.update', $transfer) }}" method="POST">
    @csrf @method('PUT')

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px">
        <div>
            {{-- Transfer header --}}
            <div class="card" style="margin-bottom:20px">
                <div class="card-header"><h3><i class="fas fa-exchange-alt" style="color:var(--primary)"></i> Transfer Details</h3></div>
                <div class="card-body">
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Transfer Number</label>
                            <input type="text" class="form-control" value="{{ $transfer->transfer_number }}"
                                   readonly style="background:#f7fafc">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Transfer Date <span style="color:red">*</span></label>
                            <input type="date" name="transfer_date" class="form-control"
                                   value="{{ old('transfer_date', $transfer->transfer_date->format('Y-m-d')) }}" required>
                            @error('transfer_date')<div style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label class="form-label">Source Warehouse</label>
                            <input type="text" class="form-control"
                                   value="{{ $transfer->fromWarehouse->name }}" readonly style="background:#f7fafc">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Destination Warehouse</label>
                            <input type="text" class="form-control"
                                   value="{{ $transfer->toWarehouse->name }}" readonly style="background:#f7fafc">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" class="form-control" rows="2">{{ old('remarks', $transfer->remarks) }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Line items --}}
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Transferred Items</h3>
                    <span style="font-size:12px;color:var(--text-muted)">Adjust quantities and unit costs. Stock at both warehouses updates automatically.</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Item (Source)</th>
                                <th>Destination Item</th>
                                <th>Unit</th>
                                <th style="text-align:right">Original Qty</th>
                                <th style="width:130px">New Qty <span style="color:red">*</span></th>
                                <th style="width:130px">Unit Cost (₱) <span style="color:red">*</span></th>
                                <th style="text-align:right">New Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transfer->items as $idx => $line)
                            <input type="hidden" name="items[{{ $idx }}][sti_id]" value="{{ $line->id }}">
                            <tr>
                                <td>
                                    <strong>{{ $line->sourceItem->description }}</strong>
                                    @if($line->sourceItem->stock_number)
                                    <div style="font-size:11px"><code>{{ $line->sourceItem->stock_number }}</code></div>
                                    @endif
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        Current stock: <strong>{{ number_format($line->sourceItem->quantity, 2) }}</strong>
                                        @ {{ $transfer->fromWarehouse->name }}
                                    </div>
                                </td>
                                <td>
                                    @if($line->destinationItem)
                                    <strong>{{ $line->destinationItem->description }}</strong>
                                    @if($line->destinationItem->stock_number)
                                    <div style="font-size:11px"><code>{{ $line->destinationItem->stock_number }}</code></div>
                                    @endif
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        Current stock: <strong>{{ number_format($line->destinationItem->quantity, 2) }}</strong>
                                        @ {{ $transfer->toWarehouse->name }}
                                    </div>
                                    @else
                                    <span style="color:var(--text-muted)">—</span>
                                    @endif
                                </td>
                                <td>{{ $line->sourceItem->unit }}</td>
                                <td style="text-align:right">
                                    <span class="badge badge-secondary">{{ number_format($line->quantity, 4) }}</span>
                                </td>
                                <td>
                                    <input type="number"
                                           name="items[{{ $idx }}][quantity]"
                                           id="qty-{{ $idx }}"
                                           class="form-control"
                                           value="{{ old("items.{$idx}.quantity", $line->quantity) }}"
                                           min="0.0001" step="0.0001" required
                                           oninput="recalcRow({{ $idx }})">
                                    @error("items.{$idx}.quantity")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td>
                                    <input type="number"
                                           name="items[{{ $idx }}][unit_cost]"
                                           id="cost-{{ $idx }}"
                                           class="form-control"
                                           value="{{ old("items.{$idx}.unit_cost", $line->unit_cost) }}"
                                           min="0.01" step="0.01" required
                                           oninput="recalcRow({{ $idx }})">
                                    @error("items.{$idx}.unit_cost")
                                    <div style="color:var(--danger);font-size:11px;margin-top:2px">{{ $message }}</div>
                                    @enderror
                                </td>
                                <td style="text-align:right">
                                    <span id="total-{{ $idx }}" style="font-weight:600">
                                        ₱{{ number_format($line->quantity * $line->unit_cost, 2) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="background:#f7fafc;font-weight:700">
                                <td colspan="6" style="text-align:right;padding:10px 14px">Grand Total:</td>
                                <td style="text-align:right;padding:10px 14px" id="grand-total">
                                    ₱{{ number_format($transfer->items->sum(fn($l) => $l->quantity * $l->unit_cost), 2) }}
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
                <div class="card-header"><h3>Summary</h3></div>
                <div class="card-body" style="font-size:14px">
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">Transfer #</span><br>
                        <strong>{{ $transfer->transfer_number }}</strong>
                    </div>
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">From</span><br>
                        {{ $transfer->fromWarehouse->name }}
                    </div>
                    <div style="margin-bottom:10px">
                        <span style="color:var(--text-muted)">To</span><br>
                        {{ $transfer->toWarehouse->name }}
                    </div>
                    <div style="margin-bottom:20px">
                        <span style="color:var(--text-muted)">Transferred By</span><br>
                        {{ $transfer->transferredBy->name }}
                    </div>
                    <div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:8px;padding:12px;font-size:12px;margin-bottom:16px">
                        <i class="fas fa-info-circle" style="color:var(--danger)"></i>
                        <strong>Stock Impact:</strong><br>
                        Increasing qty → more leaves source, more arrives at dest.<br>
                        Decreasing qty → stock is returned to source, removed from dest.
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="{{ route('transfers.show', $transfer) }}"
                       class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:8px">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
function recalcRow(idx) {
    const qty  = parseFloat(document.getElementById('qty-'  + idx)?.value) || 0;
    const cost = parseFloat(document.getElementById('cost-' + idx)?.value) || 0;
    const el   = document.getElementById('total-' + idx);
    if (el) {
        el.textContent = '₱\u00a0' + (qty * cost).toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }
    recalcGrand();
}

function recalcGrand() {
    let grand = 0;
    document.querySelectorAll('[id^="total-"]').forEach(el => {
        grand += parseFloat(el.textContent.replace(/[^\d.]/g, '')) || 0;
    });
    const el = document.getElementById('grand-total');
    if (el) {
        el.textContent = '₱\u00a0' + grand.toLocaleString('en-PH', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }
}
</script>
@endpush
@endsection
