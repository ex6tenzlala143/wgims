<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transfer Slip — {{ $transfer->transfer_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; padding: 20px; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 12px; }
        .header h2 { font-size: 16px; text-transform: uppercase; letter-spacing: 1px; }
        .header h3 { font-size: 13px; margin-top: 4px; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .meta-box { border: 1px solid #ccc; padding: 10px; border-radius: 4px; }
        .meta-box .label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #666; margin-bottom: 4px; }
        .meta-box .value { font-size: 13px; font-weight: bold; }
        .warehouse-row { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; border: 1px solid #ccc; padding: 12px; border-radius: 4px; }
        .warehouse-box { flex: 1; text-align: center; }
        .warehouse-box .wh-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #666; }
        .warehouse-box .wh-name { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .arrow { font-size: 20px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { background: #1e2a3a; color: white; padding: 7px 10px; text-align: left; font-size: 11px; }
        td { padding: 7px 10px; border-bottom: 1px solid #ddd; font-size: 12px; }
        tr:last-child td { border-bottom: none; }
        .total-row td { font-weight: bold; background: #f5f5f5; border-top: 2px solid #333; }
        .text-right { text-align: right; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 32px; }
        .sig-line { border-top: 1px solid #000; padding-top: 6px; text-align: center; font-size: 11px; }
        .remarks-box { border: 1px solid #ccc; padding: 10px; margin-bottom: 16px; min-height: 40px; }
        @media print {
            body { padding: 10px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:16px">
    <button onclick="window.print()" style="padding:8px 16px;background:#1a56db;color:white;border:none;border-radius:4px;cursor:pointer;font-size:13px">
        🖨 Print
    </button>
    <a href="{{ route('transfers.show', $transfer) }}" style="margin-left:10px;font-size:13px;color:#1a56db">← Back to Detail</a>
</div>

<div class="header">
    <h2>Welfare Goods Inventory Management System</h2>
    <h3>STOCK TRANSFER SLIP</h3>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <div class="label">Transfer Number</div>
        <div class="value">{{ $transfer->transfer_number }}</div>
    </div>
    <div class="meta-box">
        <div class="label">Transfer Date</div>
        <div class="value">{{ $transfer->transfer_date->format('F d, Y') }}</div>
    </div>
    <div class="meta-box">
        <div class="label">Transferred By</div>
        <div class="value">{{ $transfer->transferredBy->name }}</div>
    </div>
    <div class="meta-box">
        <div class="label">Status</div>
        <div class="value">{{ strtoupper($transfer->status) }}</div>
    </div>
</div>

<div class="warehouse-row">
    <div class="warehouse-box">
        <div class="wh-label">Source Warehouse</div>
        <div class="wh-name">{{ $transfer->fromWarehouse->name }}</div>
        @if($transfer->fromWarehouse->place)
        <div style="font-size:11px;color:#666">{{ $transfer->fromWarehouse->place }}</div>
        @endif
    </div>
    <div class="arrow">→</div>
    <div class="warehouse-box">
        <div class="wh-label">Destination Warehouse</div>
        <div class="wh-name">{{ $transfer->toWarehouse->name }}</div>
        @if($transfer->toWarehouse->place)
        <div style="font-size:11px;color:#666">{{ $transfer->toWarehouse->place }}</div>
        @endif
    </div>
</div>

@if($transfer->remarks)
<div style="margin-bottom:12px">
    <strong style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Remarks:</strong>
    <div class="remarks-box">{{ $transfer->remarks }}</div>
</div>
@endif

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Description</th>
            <th>Unit</th>
            <th>Category</th>
            <th class="text-right">Qty Transferred</th>
            <th class="text-right">Unit Cost (₱)</th>
            <th class="text-right">Total Cost (₱)</th>
        </tr>
    </thead>
    <tbody>
        @php $grandTotal = 0; @endphp
        @foreach($transfer->items as $i => $line)
        @php $lineTotal = $line->quantity * $line->unit_cost; $grandTotal += $lineTotal; @endphp
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>
                {{ $line->sourceItem->description }}
                @if($line->sourceItem->ris_number) ({{ $line->sourceItem->ris_number }}) @endif
            </td>
            <td>{{ $line->sourceItem->unit }}</td>
            <td>{{ $line->sourceItem->getCategoryLabel() }}</td>
            <td class="text-right">{{ number_format($line->quantity, 4) }}</td>
            <td class="text-right">{{ number_format($line->unit_cost, 2) }}</td>
            <td class="text-right">{{ number_format($lineTotal, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="6" class="text-right">GRAND TOTAL:</td>
            <td class="text-right">{{ number_format($grandTotal, 2) }}</td>
        </tr>
    </tfoot>
</table>

<div class="signatures">
    <div>
        <div style="margin-bottom:40px;font-size:11px;color:#666">Prepared / Transferred By:</div>
        <div class="sig-line">
            <strong>{{ $transfer->transferredBy->name }}</strong><br>
            Signature over Printed Name
        </div>
    </div>
    <div>
        <div style="margin-bottom:40px;font-size:11px;color:#666">Received By:</div>
        <div class="sig-line">
            Signature over Printed Name
        </div>
    </div>
</div>

<div style="margin-top:24px;font-size:10px;color:#999;text-align:center">
    Generated by WGIMSv2 — {{ now()->format('F d, Y h:i A') }}
</div>

</body>
</html>
