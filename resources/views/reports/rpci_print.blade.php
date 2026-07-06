<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RPCI — Report on the Physical Count of Inventories</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
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

        /* ── COA Header ── */
        .coa-header {
            text-align: center;
            margin-bottom: 6px;
        }
        .coa-header .republic { font-size: 9pt; }
        .coa-header .agency   { font-size: 9.5pt; font-weight: bold; text-transform: uppercase; }
        .coa-header .office   { font-size: 9pt; }

        .report-title {
            text-align: center;
            margin: 8px 0 4px;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 4px 0;
        }
        .report-title h2 {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .report-title .subtitle { font-size: 9pt; margin-top: 2px; }

        /* ── Meta info ── */
        .meta-section {
            display: flex;
            justify-content: space-between;
            margin: 6px 0;
            font-size: 9pt;
        }
        .meta-left  { flex: 1; }
        .meta-right { text-align: right; }
        .field-row  { margin-bottom: 3px; }
        .field-label { display: inline; }
        .field-value {
            border-bottom: 1px solid #000;
            display: inline-block;
            min-width: 160px;
            padding-bottom: 1px;
        }
        .field-value.wide { min-width: 220px; }
        .field-value.sm   { min-width: 100px; }

        /* ── Main table ── */
        table.rpci-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
        }
        table.rpci-table th,
        table.rpci-table td {
            border: 1px solid #000;
            padding: 2px 5px;
            vertical-align: middle;
        }
        table.rpci-table th {
            text-align: center;
            font-weight: bold;
            background: #fff;
            line-height: 1.4;
        }
        table.rpci-table td { text-align: center; }
        table.rpci-table td.left  { text-align: left; }
        table.rpci-table td.right { text-align: right; }

        /* Category group header row */
        tr.cat-header td {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 8.5pt;
            text-align: left;
            padding: 3px 5px;
            border-top: 1.5px solid #000;
        }

        tr.data-row  td { height: 16px; }
        tr.empty-row td { height: 15px; }

        /* subtotal row */
        tr.subtotal-row td {
            font-weight: bold;
            border-top: 1px solid #000;
        }

        /* grand total */
        tr.grand-total td {
            font-weight: bold;
            font-size: 9pt;
            border-top: 2px solid #000;
        }

        /* ── Certification block ── */
        .cert-block {
            margin-top: 10px;
            font-size: 9pt;
        }
        .cert-row {
            display: flex;
            gap: 20px;
            margin-top: 6px;
        }
        .cert-col {
            flex: 1;
            text-align: center;
        }
        .cert-col .sig-line {
            border-top: 1px solid #000;
            margin-top: 28px;
            padding-top: 2px;
            font-size: 8.5pt;
        }
        .cert-col .sig-name {
            font-weight: bold;
            font-size: 9pt;
        }
        .cert-col .sig-desig {
            font-size: 8pt;
        }

        /* ── Footer ── */
        .page-footer {
            margin-top: 8px;
            font-size: 8pt;
            display: flex;
            justify-content: space-between;
        }

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
    <a class="btn-back" href="{{ route('rpci_report', request()->query()) }}">← Back to RPCI</a>
    <span class="toolbar-sep"></span>
    <span style="color:#90cdf4;font-size:11px">RPCI — COA Format</span>
</div>

{{-- ── Compute totals ── --}}
@php
    use App\Models\Item;

    $grandTotalQty   = 0;
    $grandTotalValue = 0;

    // Per-category subtotals
    $catTotals = [];
    foreach ($grouped as $catKey => $catItems) {
        $qty   = $catItems->sum('quantity');
        $value = $catItems->sum(fn($i) => $i->quantity * $i->unit_cost);
        $catTotals[$catKey] = ['qty' => $qty, 'value' => $value];
        $grandTotalQty   += $qty;
        $grandTotalValue += $value;
    }

    $minRows = 5; // minimum empty rows per category
@endphp

{{-- ── Print page ── --}}
<div class="print-page paper-a4" id="printPage">

    {{-- COA Header --}}
    <div class="coa-header">
        <div class="republic">Republic of the Philippines</div>
        <div class="agency">Department of Social Welfare and Development</div>
        <div class="office">Field Office X — Northern Mindanao</div>
        <div class="office">{{ $centerName }}</div>
    </div>

    {{-- Report Title --}}
    <div class="report-title">
        <h2>Report on the Physical Count of Inventories</h2>
        <div class="subtitle">(Appendix 66)</div>
    </div>

    {{-- Meta info --}}
    <div class="meta-section">
        <div class="meta-left">
            <div class="field-row">
                <span class="field-label">Type of Inventory Item: </span>
                <span class="field-value wide">Supplies and Materials</span>
            </div>
            <div class="field-row">
                <span class="field-label">Fund Cluster: </span>
                <span class="field-value">01 — Regular Agency Fund</span>
            </div>
        </div>
        <div class="meta-right">
            <div class="field-row">
                <span class="field-label">As at: </span>
                <span class="field-value sm">{{ $asOf }}</span>
            </div>
            @if($serialNumber)
            <div class="field-row" style="margin-top:3px">
                <span class="field-label">Sheet No.: </span>
                <span class="field-value sm">{{ $serialNumber }}</span>
            </div>
            @endif
        </div>
    </div>

    {{-- Main RPCI Table --}}
    <table class="rpci-table">
        <thead>
            <tr>
                <th rowspan="2" style="width:5%">Article<br>No.</th>
                <th rowspan="2" style="width:12%">Stock<br>Number</th>
                <th rowspan="2" style="width:28%">Description</th>
                <th rowspan="2" style="width:7%">Unit of<br>Issue</th>
                <th rowspan="2" style="width:10%">Unit<br>Value</th>
                <th colspan="2">Quantity</th>
                <th rowspan="2" style="width:10%">Total<br>Value</th>
                <th rowspan="2" style="width:14%">Remarks</th>
            </tr>
            <tr>
                <th style="width:7%">Per<br>Card</th>
                <th style="width:7%">Per<br>Count</th>
            </tr>
        </thead>
        <tbody>
            @php $articleNo = 1; @endphp

            @forelse($grouped as $catKey => $catItems)
            @php
                $catInfo = Item::getCategories()[$catKey] ?? ['label' => $catKey, 'account_code' => ''];
            @endphp

            {{-- Category header --}}
            <tr class="cat-header">
                <td colspan="9">
                    {{ $catInfo['label'] }}
                    &nbsp;&nbsp;
                    <span style="font-weight:normal;font-size:8pt">(Account Code: {{ $catInfo['account_code'] }})</span>
                </td>
            </tr>

            {{-- Items --}}
            @foreach($catItems as $item)
            <tr class="data-row">
                <td>{{ $articleNo++ }}</td>
                <td class="left" style="font-size:8pt">{{ $item->stock_number }}</td>
                <td class="left">{{ $item->description }}@if($item->ris_number) <span style="font-size:7.5pt;color:#555">({{ $item->ris_number }})</span>@endif</td>
                <td>{{ $item->unit }}</td>
                <td class="right">{{ number_format($item->unit_cost, 2) }}</td>
                <td class="right">{{ number_format($item->quantity, 2) }}</td>
                <td class="right">{{ number_format($item->quantity, 2) }}</td>
                <td class="right">{{ number_format($item->quantity * $item->unit_cost, 2) }}</td>
                <td></td>
            </tr>
            @endforeach

            {{-- Empty filler rows per category --}}
            @for($i = 0; $i < $minRows; $i++)
            <tr class="empty-row">
                <td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td>
            </tr>
            @endfor

            {{-- Category subtotal --}}
            <tr class="subtotal-row">
                <td colspan="7" style="text-align:right;font-size:8.5pt">
                    Sub-Total — {{ $catInfo['label'] }}:
                </td>
                <td class="right">{{ number_format($catTotals[$catKey]['value'], 2) }}</td>
                <td></td>
            </tr>

            @empty
            <tr>
                <td colspan="9" style="text-align:center;padding:20px">No inventory items found.</td>
            </tr>
            @endforelse

            {{-- Grand Total --}}
            <tr class="grand-total">
                <td colspan="5" style="text-align:right">GRAND TOTAL:</td>
                <td class="right">{{ number_format($grandTotalQty, 2) }}</td>
                <td class="right">{{ number_format($grandTotalQty, 2) }}</td>
                <td class="right">{{ number_format($grandTotalValue, 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    {{-- Certification block --}}
    <div class="cert-block">
        <div style="margin-bottom:6px;font-size:9pt">
            I hereby certify that the above inventory was taken by me/us on
            <span style="border-bottom:1px solid #000;display:inline-block;min-width:120px;padding-bottom:1px">{{ $asOf }}</span>
            and that the same is a true and correct statement of the inventories on hand as of the date shown above.
        </div>

        <div class="cert-row">
            <div class="cert-col">
                <div class="sig-line">
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-desig">Inventory Committee Member</div>
                </div>
            </div>
            <div class="cert-col">
                <div class="sig-line">
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-desig">Inventory Committee Member</div>
                </div>
            </div>
            <div class="cert-col">
                <div class="sig-line">
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-desig">Inventory Committee Chairperson</div>
                </div>
            </div>
        </div>

        <div style="margin-top:14px;font-size:9pt">
            Verified by:
        </div>
        <div class="cert-row">
            <div class="cert-col" style="flex:0 0 40%">
                <div class="sig-line">
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-desig">Accountant / Authorized Representative</div>
                </div>
            </div>
            <div class="cert-col" style="flex:0 0 40%">
                <div class="sig-line">
                    <div class="sig-name">&nbsp;</div>
                    <div class="sig-desig">Date</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Page footer --}}
    <div class="page-footer">
        <span>COA Form No. 103 — Revised 2014</span>
        <span>Printed: {{ date('F d, Y h:i A') }}</span>
    </div>

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
