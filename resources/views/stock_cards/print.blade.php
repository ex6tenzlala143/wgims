<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Card — {{ $item->description }}</title>
    <style>
        :root {
            --border: #000;
            --font: Arial, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            font-size: 9.5pt;
            background: #fff;
            color: #000;
        }

        /* ── Toolbar (no-print) ── */
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

        /* A4 default */
        .print-page { width: 210mm; }

        /* ── Document header ── */
        .doc-header { text-align: center; margin-bottom: 6px; }
        .doc-header h2 { font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; }
        .doc-header .agency-line {
            border-bottom: 1px solid #000;
            width: 55%; margin: 3px auto 1px;
            font-size: 8.5pt; text-align: center; padding-bottom: 1px;
        }
        .doc-header .agency-label { font-size: 8pt; color: #333; }
        .appendix-note { text-align: right; font-size: 9pt; font-style: italic; margin-bottom: 4px; }

        /* ── Item info row ── */
        .item-info {
            display: flex; justify-content: space-between;
            margin-bottom: 3px; font-size: 9.5pt;
        }
        .item-info .left { flex: 1; }
        .item-info .right { text-align: right; }
        .info-line { margin-bottom: 2px; }
        .info-line span { border-bottom: 1px solid #000; display: inline-block; min-width: 160px; padding-bottom: 1px; }

        /* ── Main table ── */
        table.sc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-top: 4px;
        }
        table.sc-table th,
        table.sc-table td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
        }
        table.sc-table th {
            text-align: center;
            font-weight: bold;
            font-style: italic;
            background: #fff;
            line-height: 1.3;
        }
        table.sc-table td { text-align: center; }
        table.sc-table td.left-align { text-align: left; }
        table.sc-table td.right-align { text-align: right; }
        tr.data-row td { height: 15px; }
        tr.empty-row td { height: 14px; }

        /* group headers */
        th.group-header { font-size: 9pt; font-style: italic; }

        /* ── Footer note ── */
        .footer-note { margin-top: 6px; font-size: 8pt; color: #333; }

        /* ── Print media ── */
        @media print {
            .toolbar { display: none !important; }
            .print-page { margin: 0 auto; padding: 10mm 12mm 8mm; }
            @page { margin: 0; }
        }

        /* Paper size classes applied via JS */
        .paper-a4   { width: 210mm; }
        .paper-legal { width: 216mm; }
    </style>
</head>
<body>

{{-- ── Toolbar ── --}}
<div class="toolbar no-print">
    <label>Paper Size:</label>
    <select id="paperSize" onchange="setPaper(this.value)">
        <option value="a4">A4 (210 × 297 mm)</option>
        <option value="legal">Legal / Long Bond (216 × 356 mm)</option>
    </select>
    <button class="btn-print" onclick="window.print()">🖨&nbsp; Print</button>
    <a class="btn-back" href="{{ route('stock_cards.item_history', $item->id) }}">← Back</a>
    <span class="toolbar-sep"></span>
    <span style="color:#90cdf4;font-size:11px">Stock Card — Appendix 9 Format</span>
</div>

{{-- ── Print page ── --}}
<div class="print-page paper-a4" id="printPage">

    <div class="appendix-note">Appendix 9</div>

    <div class="doc-header">
        <h2>Supplies Ledger Card</h2>
        @php
            $agencyName = auth()->user()->warehouse?->name
                ?? auth()->user()->warehouses()->value('name')
                ?? 'DSWD Region X';
        @endphp
        <div class="agency-line">{{ $agencyName }}</div>
        <div class="agency-label">Agency</div>
    </div>

    <div class="item-info" style="margin-top:8px">
        <div class="left">
            <div class="info-line">Item:&nbsp; <span>{{ $item->description }}</span></div>
            <div class="info-line">RIS No.:&nbsp; <span>{{ $item->ris_number ?? '' }}</span></div>
        </div>
        <div class="right">
            <div class="info-line">Stock No.:&nbsp; <span>{{ $item->stock_number }}</span></div>
            <div class="info-line">Re-order Point:&nbsp; <span>{{ number_format($item->reorder_point, 2) }}</span></div>
        </div>
    </div>

    <table class="sc-table">
        <thead>
            <tr>
                <th rowspan="2" style="width:9%">Date</th>
                <th rowspan="2" style="width:12%">Reference</th>
                <th colspan="3" class="group-header">Receipt</th>
                <th rowspan="2" style="width:7%"><em>Issuance</em><br>Qty.</th>
                <th colspan="3" class="group-header">Balance</th>
                <th rowspan="2" style="width:8%">No. of Days<br>to Consume</th>
            </tr>
            <tr>
                <th style="width:7%">Qty.</th>
                <th style="width:9%">Unit<br>Cost</th>
                <th style="width:9%">Total<br>Cost</th>
                <th style="width:7%">Qty.</th>
                <th style="width:9%">Unit<br>Cost</th>
                <th style="width:9%">Total<br>Cost</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
            <tr class="data-row">
                <td>{{ $entry->entry_date->format('m/d/Y') }}</td>
                <td class="left-align">{{ $entry->reference }}</td>
                <td class="right-align">{{ $entry->receipt_qty > 0 ? number_format($entry->receipt_qty, 2) : '' }}</td>
                <td class="right-align">{{ $entry->receipt_unit_cost > 0 ? number_format($entry->receipt_unit_cost, 2) : '' }}</td>
                <td class="right-align">{{ $entry->receipt_total_cost > 0 ? number_format($entry->receipt_total_cost, 2) : '' }}</td>
                <td class="right-align">{{ $entry->issue_qty > 0 ? number_format($entry->issue_qty, 2) : '' }}</td>
                <td class="right-align">{{ number_format($entry->balance_qty, 2) }}</td>
                <td class="right-align">{{ number_format($entry->balance_unit_cost, 2) }}</td>
                <td class="right-align">{{ number_format($entry->balance_total_cost, 2) }}</td>
                <td>{{ $entry->no_of_days_to_consume ?? '' }}</td>
            </tr>
            @empty
            @endforelse

            {{-- Pad to minimum rows --}}
            @php $filled = $entries->count(); $minLines = 30; @endphp
            @for($i = $filled; $i < $minLines; $i++)
            <tr class="empty-row">
                <td></td><td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td>
            </tr>
            @endfor
        </tbody>
    </table>

    <div class="footer-note">
        <em>For Accounting Office Use</em><br>
        AO 5-14-02
    </div>
</div>

<script>
function setPaper(size) {
    const page = document.getElementById('printPage');
    page.className = 'print-page paper-' + size;

    // Update @page size for print
    let style = document.getElementById('dynamic-page-style');
    if (!style) {
        style = document.createElement('style');
        style.id = 'dynamic-page-style';
        document.head.appendChild(style);
    }
    if (size === 'legal') {
        style.textContent = '@media print { @page { size: 216mm 356mm portrait; } }';
    } else {
        style.textContent = '@media print { @page { size: A4 portrait; } }';
    }
}
</script>
</body>
</html>
