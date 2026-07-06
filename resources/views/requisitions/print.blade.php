<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RIS #{{ $requisition->ris_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 9.5pt;
            background: #fff;
            color: #000;
        }

        /* ── Toolbar ── */
        .toolbar {
            position: fixed; top: 0; left: 0; right: 0;
            background: #1e2a3a;
            padding: 8px 16px;
            display: flex; align-items: center; gap: 10px;
            z-index: 999;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .toolbar label { color: #c8d6e5; font-size: 12px; }
        .toolbar select, .toolbar button, .toolbar a {
            padding: 6px 14px; border-radius: 5px;
            font-size: 12px; cursor: pointer; border: none;
        }
        .toolbar select { background: #2d3f55; color: #fff; }
        .btn-print { background: #1a56db; color: #fff; font-weight: 600; }
        .btn-back  { background: #4a5568; color: #fff; text-decoration: none; }
        .toolbar-sep { flex: 1; }

        /* ── Print page ── */
        .print-page {
            margin: 60px auto 30px;
            background: #fff;
            padding: 14mm 14mm 10mm;
        }
        .paper-a4    { width: 210mm; }
        .paper-legal { width: 216mm; }

        /* ── Appendix note ── */
        .appendix-note { text-align: right; font-size: 9pt; font-style: italic; margin-bottom: 4px; }

        /* ── Title ── */
        .doc-title { text-align: center; margin-bottom: 8px; }
        .doc-title h2 { font-size: 12pt; font-weight: bold; text-transform: uppercase; }

        /* ── Header info table ── */
        table.header-table {
            width: 100%; border-collapse: collapse;
            font-size: 9pt; margin-bottom: 4px;
        }
        table.header-table td { padding: 2px 4px; vertical-align: top; }
        .underline-field {
            border-bottom: 1px solid #000;
            display: inline-block;
            min-width: 140px;
            padding-bottom: 1px;
        }
        .underline-field.wide { min-width: 220px; }
        .underline-field.sm   { min-width: 80px; }

        /* ── Bordered info block ── */
        table.info-block {
            width: 100%; border-collapse: collapse;
            font-size: 9pt; margin-bottom: 0;
        }
        table.info-block td, table.info-block th {
            border: 1px solid #000;
            padding: 3px 6px;
            vertical-align: top;
        }

        /* ── Main items table ── */
        table.ris-table {
            width: 100%; border-collapse: collapse;
            font-size: 8.5pt;
        }
        table.ris-table th,
        table.ris-table td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
        }
        table.ris-table th { text-align: center; font-weight: bold; }
        table.ris-table td { text-align: center; }
        table.ris-table td.left  { text-align: left; }
        table.ris-table td.right { text-align: right; }
        tr.item-row td { height: 15px; }
        tr.empty-row td { height: 14px; }

        /* ── Purpose box ── */
        .purpose-box {
            border: 1px solid #000;
            border-top: none;
            padding: 4px 6px;
            font-size: 9pt;
        }

        /* ── Signature table ── */
        table.sig-table {
            width: 100%; border-collapse: collapse;
            font-size: 8.5pt; margin-top: 0;
        }
        table.sig-table th,
        table.sig-table td {
            border: 1px solid #000;
            padding: 3px 6px;
            text-align: center;
            vertical-align: top;
        }
        table.sig-table .sig-space { height: 28px; }

        @media print {
            .toolbar { display: none !important; }
            .print-page { margin: 0 auto; padding: 10mm 12mm 8mm; }
            @page { margin: 0; }
        }
    </style>
</head>
<body>

{{-- ── Toolbar ── --}}
<div class="toolbar">
    <label>Paper Size:</label>
    <select id="paperSize" onchange="setPaper(this.value)">
        <option value="a4">A4 (210 × 297 mm)</option>
        <option value="legal">Legal / Long Bond (216 × 356 mm)</option>
    </select>
    <button class="btn-print" onclick="window.print()">🖨&nbsp; Print</button>
    <a class="btn-back" href="{{ route('requisitions.show', $requisition->id) }}">← Back</a>
    <span class="toolbar-sep"></span>
    <span style="color:#90cdf4;font-size:11px">RIS #{{ $requisition->ris_number }} — Appendix 63</span>
</div>

{{-- ── Print page ── --}}
<div class="print-page paper-a4" id="printPage">

    <div class="appendix-note">Appendix 63</div>

    <div class="doc-title">
        <h2>Requisition and Issue Slip (RIS) Form</h2>
    </div>

    {{-- Header row: Entity Name / Date --}}
    <table class="header-table">
        <tr>
            <td style="width:55%">
                Entity Name&nbsp;
                <span class="underline-field wide">{{ $requisition->entity_name ?? 'DSWD Region X' }}</span>
            </td>
            <td style="text-align:right">
                Date:&nbsp;
                <span class="underline-field sm">{{ $requisition->date_requested->format('m/d/Y') }}</span>
            </td>
        </tr>
        <tr>
            <td>
                Fund Cluster:&nbsp;
                <span class="underline-field">{{ $requisition->fund_cluster ?? '' }}</span>
            </td>
            <td></td>
        </tr>
    </table>

    {{-- Office / RIS info block --}}
    <table class="info-block">
        <tr>
            <td style="width:55%">
                Office: {{ $requisition->office ?? '' }}
                @if($requisition->division)
                &nbsp;/&nbsp; Division: {{ $requisition->division }}
                @endif
            </td>
            <td>
                Responsibility Center Code:&nbsp;
                <span class="underline-field sm">{{ $requisition->responsibility_center_code ?? '' }}</span>
                <br>
                RIS Number:&nbsp;
                <strong>{{ $requisition->ris_number }}</strong>
                @if($requisition->dr_number)
                <br>
                DR Number:&nbsp;
                <strong>{{ $requisition->dr_number }}</strong>
                @endif
            </td>
        </tr>
    </table>

    {{-- Items table --}}
    @php
        $items = $requisition->items;
        $minRows = 20;
        $filled  = $items->count();
    @endphp

    <table class="ris-table">
        <thead>
            <tr>
                <th colspan="3" style="border-bottom:none">Requisition</th>
                <th colspan="2">Stocks<br>Available</th>
                <th colspan="2">Issue</th>
            </tr>
            <tr>
                <th style="width:12%">Stock No.</th>
                <th style="width:8%">Unit</th>
                <th style="width:32%">Description</th>
                <th style="width:8%">Qty</th>
                <th style="width:6%">Yes</th>
                <th style="width:6%">No</th>
                <th style="width:8%">Qty</th>
                <th style="width:20%">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $ri)
            <tr class="item-row">
                <td>{{ $ri->item->stock_number ?? '' }}</td>
                <td>{{ $ri->item->unit ?? '' }}</td>
                <td class="left">{{ $ri->item->description ?? '' }}</td>
                <td class="right">{{ number_format($ri->quantity_requested, 2) }}</td>
                <td>{{ $ri->stock_available ? '✓' : '' }}</td>
                <td>{{ !$ri->stock_available ? '✓' : '' }}</td>
                <td class="right">{{ $ri->quantity_issued > 0 ? number_format($ri->quantity_issued, 2) : '' }}</td>
                <td class="left">{{ $ri->remarks ?? '' }}</td>
            </tr>
            @endforeach

            {{-- Empty filler rows --}}
            @for($i = $filled; $i < $minRows; $i++)
            <tr class="empty-row">
                <td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td>
            </tr>
            @endfor
        </tbody>
    </table>

    {{-- Purpose box --}}
    <div class="purpose-box">
        <strong>Purpose:</strong> {{ $requisition->purpose }}
    </div>

    {{-- Signature table --}}
    <table class="sig-table">
        <thead>
            <tr>
                <th style="width:5%"></th>
                <th style="width:23.75%">Requested By</th>
                <th style="width:23.75%">Approved By</th>
                <th style="width:23.75%">Issued By</th>
                <th style="width:23.75%">Received By</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align:left;font-size:8pt">Signature:</td>
                <td class="sig-space"></td>
                <td class="sig-space"></td>
                <td class="sig-space"></td>
                <td class="sig-space"></td>
            </tr>
            <tr>
                <td style="text-align:left;font-size:8pt">Printed<br>Name:</td>
                <td><strong>{{ $requisition->requested_by_name ?? '' }}</strong></td>
                <td><strong>{{ $requisition->approved_by_name ?? '' }}</strong></td>
                <td><strong>{{ $requisition->issued_by_name ?? '' }}</strong></td>
                <td><strong>{{ $requisition->received_by_name ?? '' }}</strong></td>
            </tr>
            <tr>
                <td style="text-align:left;font-size:8pt">Designation:</td>
                <td>{{ $requisition->requested_by_designation ?? '' }}</td>
                <td>{{ $requisition->approved_by_designation ?? '' }}</td>
                <td>{{ $requisition->issued_by_designation ?? '' }}</td>
                <td>{{ $requisition->received_by_designation ?? '' }}</td>
            </tr>
            <tr>
                <td style="text-align:left;font-size:8pt">Date:</td>
                <td>{{ $requisition->date_requested->format('m/d/Y') }}</td>
                <td>{{ $requisition->date_approved ? $requisition->date_approved->format('m/d/Y') : '' }}</td>
                <td></td>
                <td></td>
            </tr>
        </tbody>
    </table>

</div>

<script>
function setPaper(size) {
    const page = document.getElementById('printPage');
    page.className = 'print-page paper-' + size;
    let style = document.getElementById('dynamic-page-style');
    if (!style) {
        style = document.createElement('style');
        style.id = 'dynamic-page-style';
        document.head.appendChild(style);
    }
    style.textContent = size === 'legal'
        ? '@media print { @page { size: 216mm 356mm portrait; } }'
        : '@media print { @page { size: A4 portrait; } }';
}
</script>
</body>
</html>
