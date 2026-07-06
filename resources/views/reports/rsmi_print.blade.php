<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RSMI — Report of Supplies and Materials Issued</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            font-size: 9.5pt;
            background: #fff;
            color: #000;
        }

        /* ── Toolbar (screen only) ── */
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

        /* ── Page wrapper ── */
        .pages-wrapper {
            margin-top: 60px;
        }

        /* ── Each RIS block ── */
        .ris-block {
            background: #fff;
            padding: 14mm 14mm 10mm;
            width: 210mm;
            margin: 0 auto 20px;
        }
        .ris-block.paper-legal {
            width: 216mm;
        }

        /* ── Page break between RIS blocks ── */
        .page-break {
            page-break-after: always;
            break-after: page;
        }

        /* ── Page header ── */
        .page-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 4px;
        }
        .appendix-note { font-size: 9.5pt; font-style: italic; }

        /* ── Title ── */
        .doc-title { text-align: center; margin: 6px 0 2px; }
        .doc-title h2 { font-size: 12.5pt; font-weight: bold; text-transform: uppercase; }
        .doc-title .period-line {
            border-bottom: 1px solid #000;
            width: 55%; margin: 3px auto 0;
            font-size: 9pt; text-align: center; padding-bottom: 1px;
        }

        /* ── Meta row ── */
        .meta-row {
            display: flex; justify-content: space-between;
            margin: 8px 0 4px; font-size: 9pt;
        }
        .meta-left  { display: flex; flex-direction: column; gap: 3px; }
        .meta-right { text-align: right; }
        .field-line {
            border-bottom: 1px solid #000;
            display: inline-block; min-width: 200px;
            padding-bottom: 1px;
        }
        .field-line.sm { min-width: 130px; }

        /* ── Main table ── */
        table.rsmi-table {
            width: 100%; border-collapse: collapse;
            font-size: 8.5pt;
        }
        table.rsmi-table th,
        table.rsmi-table td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
        }
        table.rsmi-table th {
            text-align: center; font-weight: bold;
            background: #fff; line-height: 1.3;
        }
        table.rsmi-table td      { text-align: center; }
        table.rsmi-table td.left  { text-align: left; }
        table.rsmi-table td.right { text-align: right; }
        tr.data-row  td { height: 15px; }
        tr.empty-row td { height: 14px; }
        .col-divider { border-right: 2px solid #000 !important; }
        .recap-header { font-weight: bold; font-size: 9pt; }

        /* ── Signature block ── */
        .sig-block {
            display: flex;
            border: 1px solid #000;
            border-top: none;
            font-size: 9pt;
        }
        .sig-left  { flex: 1; padding: 6px 10px; border-right: 1px solid #000; }
        .sig-right { flex: 1; padding: 6px 10px; }
        .sig-name-line {
            border-top: 1px solid #000;
            margin-top: 22px; padding-top: 2px;
            font-size: 8.5pt; text-align: center;
        }
        .sig-date-row {
            display: flex; gap: 10px;
            margin-top: 4px; font-size: 8.5pt;
        }
        .sig-date-row span {
            flex: 1; border-top: 1px solid #000;
            text-align: center; padding-top: 2px;
        }

        /* ── RIS label badge (screen only) ── */
        .ris-label-badge {
            display: inline-block;
            background: #ebf8ff; border: 1px solid #90cdf4;
            border-radius: 5px; padding: 3px 10px;
            font-size: 11px; font-weight: 600; color: #2b6cb0;
            margin-bottom: 6px;
        }

        /* ── Print overrides ── */
        @media print {
            .toolbar      { display: none !important; }
            .pages-wrapper { margin-top: 0; }
            .ris-block    { margin: 0 auto; padding: 10mm 12mm 8mm; }
            .ris-label-badge { display: none !important; }
            @page { margin: 0; }
            .page-break   { page-break-after: always; break-after: page; }
            /* Last block should NOT add an extra blank page */
            .ris-block:last-child .page-break { page-break-after: avoid; break-after: avoid; }
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
    <a class="btn-back" href="{{ route('rsmi_report', request()->query()) }}">← Back to RSMI</a>
    <span class="toolbar-sep"></span>
    <span style="color:#90cdf4;font-size:11px">
        RSMI — {{ $risGroups->count() }} RIS(es) · Appendix 64 Format
    </span>
</div>

<div class="pages-wrapper" id="pagesWrapper">

@if($risGroups->isEmpty())
    <div style="text-align:center;padding:60px;font-size:14px;color:#718096;margin-top:60px">
        <p>No issued items found for the selected period.</p>
        <a href="{{ route('rsmi_report') }}" style="color:#2b6cb0">← Back to RSMI Report</a>
    </div>
@else

{{-- ═══════════════════════════════════════════════════════════════════
     LOOP: one block per RIS number — each block prints on its own page
════════════════════════════════════════════════════════════════════ --}}
@foreach($risGroups as $groupIndex => $group)
@php
    /** @var \App\Models\Requisition $ris */
    $ris        = $group['ris'];
    $items      = $group['items'];   // Collection of RequisitionItem (qty_issued > 0)
    $recap      = $group['recap'];   // Collection of recap rows for this RIS
    $subtotal   = $group['subtotal'];

    $dataCount    = $items->count();
    $minDataRows  = 20;
    $recapCount   = $recap->count();
    $minRecapRows = 8;

    $isLast = $loop->last;
@endphp

<div class="ris-block" id="ris-block-{{ $groupIndex }}">

    {{-- Screen-only RIS identifier --}}
    <div class="ris-label-badge no-print">
        RIS #{{ $ris->ris_number }}
        @if(!$isLast)
        &nbsp;·&nbsp; <span style="color:#718096;font-weight:400">Page break follows</span>
        @endif
    </div>

    {{-- Page top --}}
    <div class="page-top">
        <div>
            <img src="{{ asset('images/logo.png') }}"
                 alt="DSWD Logo"
                 style="height:60px;width:auto;object-fit:contain;">
        </div>
        <div class="appendix-note"><em>Appendix 64</em></div>
    </div>

    {{-- Title --}}
    <div class="doc-title">
        <h2>Report of Supplies and Materials Issued</h2>
        <div class="period-line">{{ $dateLabel }}</div>
    </div>

    {{-- Meta --}}
    <div class="meta-row">
        <div class="meta-left">
            <div>
                <span style="font-size:8.5pt">Entity:&nbsp;</span>
                <span class="field-line">{{ $ris->entity_name ?? '&nbsp;' }}</span>
            </div>
            <div>
                <span style="font-size:8.5pt">Fund Cluster:&nbsp;</span>
                <span class="field-line">{{ $ris->fund_cluster ?? '&nbsp;' }}</span>
            </div>
        </div>
        <div class="meta-right">
            <div>Serial No. :&nbsp;
                <span class="field-line sm">{{ $serialNumber ?: '&nbsp;' }}</span>
            </div>
            <div style="margin-top:3px">Date :&nbsp;
                <span class="field-line sm">
                    {{ $ris->date_approved ? $ris->date_approved->format('F d, Y') : date('F d, Y') }}
                </span>
            </div>
        </div>
    </div>

    {{-- ── Main table ── --}}
    <table class="rsmi-table">
        <thead>
            <tr>
                <th colspan="6" class="col-divider" style="font-size:8pt;font-style:italic">
                    To be filled up by the Supply and/or Property Division/Unit
                </th>
                <th colspan="2" style="font-size:8pt;font-style:italic">
                    To be filled up by the Accounting Division/Unit
                </th>
            </tr>
            <tr>
                <th style="width:11%">RIS No.</th>
                <th style="width:10%">Responsibility<br>Center Code</th>
                <th style="width:11%">Stock No.</th>
                <th style="width:28%">Item</th>
                <th style="width:7%">Unit</th>
                <th style="width:9%" class="col-divider">Quantity<br>Issued</th>
                <th style="width:12%">Unit Cost</th>
                <th style="width:12%">Amount</th>
            </tr>
        </thead>
        <tbody>

            {{-- ── Data rows for this RIS ── --}}
            @foreach($items as $ri)
            <tr class="data-row">
                <td>{{ $ris->ris_number }}</td>
                <td>{{ $ris->warehouse->code ?? '' }}</td>
                <td>{{ $ri->item->stock_number ?? '' }}</td>
                <td class="left">{{ $ri->item->description ?? '' }}</td>
                <td>{{ $ri->item->unit ?? '' }}</td>
                <td class="right col-divider">{{ number_format($ri->quantity_issued, 2) }}</td>
                <td class="right">{{ number_format($ri->item->unit_cost ?? 0, 2) }}</td>
                <td class="right">{{ number_format($ri->quantity_issued * ($ri->item->unit_cost ?? 0), 2) }}</td>
            </tr>
            @endforeach

            {{-- Filler rows to reach minimum --}}
            @for($i = $dataCount; $i < $minDataRows; $i++)
            <tr class="empty-row">
                <td></td><td></td><td></td><td></td><td></td>
                <td class="col-divider"></td><td></td><td></td>
            </tr>
            @endfor

            {{-- ── Recapitulation ── --}}
            <tr>
                <td colspan="2" class="recap-header" style="border-top:2px solid #000;text-align:center">
                    Recapitulation:
                </td>
                <td style="border-top:2px solid #000"></td>
                <td style="border-top:2px solid #000"></td>
                <td style="border-top:2px solid #000"></td>
                <td class="col-divider" style="border-top:2px solid #000"></td>
                <td colspan="2" class="recap-header" style="border-top:2px solid #000;text-align:center">
                    Recapitulation:
                </td>
            </tr>
            <tr>
                <th colspan="2" style="text-align:center">Stock No.</th>
                <th style="text-align:center">Quantity</th>
                <td></td><td></td>
                <td class="col-divider"></td>
                <th style="text-align:center">Unit Cost</th>
                <th style="text-align:center">Total Cost</th>
            </tr>

            @foreach($recap as $r)
            <tr class="data-row">
                <td colspan="2">{{ $r['stock_no'] }}</td>
                <td class="right">{{ number_format($r['qty'], 2) }}</td>
                <td></td><td></td>
                <td class="col-divider"></td>
                <td class="right">{{ number_format($r['unit_cost'], 2) }}</td>
                <td class="right">{{ number_format($r['total_cost'], 2) }}</td>
            </tr>
            @endforeach

            {{-- Recap filler --}}
            @for($i = $recapCount; $i < $minRecapRows; $i++)
            <tr class="empty-row">
                <td colspan="2"></td><td></td><td></td><td></td>
                <td class="col-divider"></td><td></td><td></td>
            </tr>
            @endfor

            {{-- RIS subtotal --}}
            <tr>
                <td colspan="5" style="text-align:right;font-weight:bold;border-top:2px solid #000">
                    TOTAL:
                </td>
                <td class="col-divider" style="border-top:2px solid #000"></td>
                <td style="border-top:2px solid #000"></td>
                <td class="right" style="font-weight:bold;border-top:2px solid #000">
                    {{ number_format($subtotal, 2) }}
                </td>
            </tr>

        </tbody>
    </table>

    {{-- Signature block --}}
    <div class="sig-block">
        <div class="sig-left">
            <div>I hereby certify to the correctness of the above information.</div>
            <div class="sig-name-line">Signature over Printed Name of Supply and/or Property</div>
        </div>
        <div class="sig-right">
            <div>Posted by:</div>
            <div class="sig-date-row" style="margin-top:22px">
                <span>Signature over Printed Name of</span>
                <span style="max-width:70px">Date</span>
            </div>
        </div>
    </div>

    {{-- Page break after every block except the last --}}
    @if(!$isLast)
    <div class="page-break"></div>
    @endif

</div>{{-- end .ris-block --}}
@endforeach

@endif {{-- end @if $risGroups not empty --}}

</div>{{-- end .pages-wrapper --}}

<script>
function setPaper(size) {
    document.querySelectorAll('.ris-block').forEach(function(block) {
        block.classList.remove('paper-a4', 'paper-legal');
        block.classList.add('paper-' + size);
    });
    var style = document.getElementById('dynamic-page-style');
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
