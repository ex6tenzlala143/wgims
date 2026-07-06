<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\ReportSnapshot;
use App\Models\Requisition;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    use ScopesWarehouse;

    public function rpci(Request $request)
    {
        $user = Auth::user();
        $query = Item::with('warehouse')->where('is_active', true);

        $hasAccess = $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->category) {
            $query->where('category', $request->category);
        }

        $items = $query->orderBy('category')->orderBy('description')->get();
        $warehouses = $user->hasAdminAccess() ? Warehouse::where('is_active', true)->get() : collect();
        $noWarehouseAssigned = ! $hasAccess;

        // Snapshots — non-admins see snapshots for any of their assigned warehouses
        $snapshotQuery = ReportSnapshot::where('report_type', 'rpci');
        $userWarehouseIds = $this->getUserWarehouseIds($user);
        if ($userWarehouseIds !== null) {
            $snapshotQuery->whereIn('warehouse_id', $userWarehouseIds);
        }
        $snapshots = $snapshotQuery->orderByDesc('period_month')->get();

        return view('reports.rpci', compact('items', 'warehouses', 'snapshots', 'noWarehouseAssigned'));
    }

    public function printRpci(Request $request)
    {
        $user = Auth::user();
        $query = Item::with('warehouse')->where('is_active', true);

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->category) {
            $query->where('category', $request->category);
        }

        $items = $query->orderBy('category')->orderBy('description')->get();
        $grouped = $items->groupBy('category');

        $asOf = $request->as_of ? date('F d, Y', strtotime($request->as_of)) : date('F d, Y');
        $serialNumber = $request->serial_number ?? '';
        $centerName = $this->getCenterName($user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        return view('reports.rpci_print', compact('grouped', 'asOf', 'centerName', 'serialNumber'));
    }

    public function saveRpciSnapshot(Request $request)
    {
        $user = Auth::user();
        $request->validate(['period_month' => 'required|string']);

        $query = Item::with('warehouse')->where('is_active', true);
        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        $items = $query->get()->map(fn ($i) => [
            'stock_number' => $i->stock_number,
            'description' => $i->description,
            'unit' => $i->unit,
            'quantity' => $i->quantity,
            'unit_cost' => $i->unit_cost,
            'total_value' => $i->quantity * $i->unit_cost,
            'warehouse' => $i->warehouse->name ?? '',
            'category' => $i->category,
        ]);

        // For non-admins with multiple warehouses, store null as warehouse_id
        // (the snapshot covers all their assigned warehouses)
        $snapshotWarehouseId = $request->warehouse_id
            ?? ($user->isCenterUser() ? $user->warehouse_id : null);

        ReportSnapshot::create([
            'report_type' => 'rpci',
            'warehouse_id' => $snapshotWarehouseId,
            'period_month' => $request->period_month,
            'serial_number' => $request->serial_number,
            'data' => json_encode($items),
            'created_by' => $user->id,
        ]);

        return back()->with('success', 'RPCI snapshot saved.');
    }

    public function rsmi(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'items.item'])
            ->whereIn('status', ['approved', 'partially_approved']);

        $hasAccess = $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->date_from) {
            $query->whereDate('date_approved', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('date_approved', '<=', $request->date_to);
        }

        $requisitions = $query->orderBy('date_approved')->get();

        // Group by RIS number — same structure used by the print view
        $risGroups = $requisitions->map(function (Requisition $ris) {
            $issuedItems = $ris->items->filter(fn ($ri) => $ri->quantity_issued > 0)->values();
            $subtotal    = $issuedItems->sum(fn ($ri) => $ri->quantity_issued * ($ri->item->unit_cost ?? 0));

            return [
                'ris'      => $ris,
                'items'    => $issuedItems,
                'subtotal' => $subtotal,
            ];
        })->filter(fn ($g) => $g['items']->isNotEmpty())->values();

        $grandTotal = $risGroups->sum('subtotal');

        $warehouses          = $user->hasAdminAccess() ? Warehouse::where('is_active', true)->get() : collect();
        $noWarehouseAssigned = ! $hasAccess;

        $snapshotQuery   = ReportSnapshot::where('report_type', 'rsmi');
        $userWarehouseIds = $this->getUserWarehouseIds($user);
        if ($userWarehouseIds !== null) {
            $snapshotQuery->whereIn('warehouse_id', $userWarehouseIds);
        }
        $snapshots = $snapshotQuery->orderByDesc('period_month')->get();

        return view('reports.rsmi', compact(
            'risGroups', 'grandTotal', 'requisitions',
            'warehouses', 'snapshots', 'noWarehouseAssigned'
        ));
    }

    public function printRsmi(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'items.item'])
            ->whereIn('status', ['approved', 'partially_approved']);

        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);

        if ($request->date_from) {
            $query->whereDate('date_approved', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('date_approved', '<=', $request->date_to);
        }

        $requisitions = $query->orderBy('date_approved')->get();

        // Group by RIS number — each group is one printed block / page
        // Each group contains the Requisition model (for header info) and its issued line items
        $risGroups = $requisitions->map(function (Requisition $ris) {
            $issuedItems = $ris->items->filter(fn ($ri) => $ri->quantity_issued > 0)->values();

            $subtotal = $issuedItems->sum(fn ($ri) => $ri->quantity_issued * ($ri->item->unit_cost ?? 0));

            // Recapitulation: group items by stock number within this RIS
            $recap = $issuedItems->groupBy(fn ($ri) => $ri->item->stock_number ?? '')
                ->map(function ($group) {
                    $first = $group->first();
                    return [
                        'stock_no'   => $first->item->stock_number ?? '',
                        'qty'        => $group->sum('quantity_issued'),
                        'unit_cost'  => $first->item->unit_cost ?? 0,
                        'total_cost' => $group->sum(fn ($ri) => $ri->quantity_issued * ($ri->item->unit_cost ?? 0)),
                    ];
                })->values();

            return [
                'ris'        => $ris,
                'items'      => $issuedItems,
                'subtotal'   => $subtotal,
                'recap'      => $recap,
            ];
        })->filter(fn ($g) => $g['items']->isNotEmpty())->values();

        $grandTotal = $risGroups->sum('subtotal');

        $serialNumber = $request->serial_number ?? '';
        $dateLabel = $request->date_from
            ? date('F d, Y', strtotime($request->date_from))
              .($request->date_to ? ' – '.date('F d, Y', strtotime($request->date_to)) : '')
            : date('F d, Y');

        return view('reports.rsmi_print', compact('risGroups', 'grandTotal', 'serialNumber', 'dateLabel'));
    }

    public function saveRsmiSnapshot(Request $request)
    {
        $user = Auth::user();
        $request->validate(['period_month' => 'required|string']);

        $query = Requisition::with(['warehouse', 'items.item'])
            ->whereIn('status', ['approved', 'partially_approved']);
        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);
        if ($request->date_from) {
            $query->whereDate('date_approved', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('date_approved', '<=', $request->date_to);
        }

        $data = $query->get()->flatMap(fn ($r) => $r->items->map(fn ($ri) => [
            'ris_number' => $r->ris_number,
            'warehouse_code' => $r->warehouse->code ?? '',
            'stock_number' => $ri->item->stock_number ?? '',
            'description' => $ri->item->description ?? '',
            'unit' => $ri->item->unit ?? '',
            'quantity_issued' => $ri->quantity_issued,
            'unit_cost' => $ri->item->unit_cost ?? 0,
            'amount' => $ri->quantity_issued * ($ri->item->unit_cost ?? 0),
        ]));

        ReportSnapshot::create([
            'report_type' => 'rsmi',
            'warehouse_id' => $request->warehouse_id ?? ($user->isCenterUser() ? $user->warehouse_id : null),
            'period_month' => $request->period_month,
            'serial_number' => $request->serial_number,
            'data' => json_encode($data),
            'created_by' => $user->id,
        ]);

        return back()->with('success', 'RSMI snapshot saved.');
    }

    public function inventoryBalance(Request $request)
    {
        if (! Auth::user()->hasAdminAccess()) {
            abort(403);
        }

        $warehouseId = $request->warehouse_id ? (int) $request->warehouse_id : null;
        $categoryKey = $request->category ?: null;
        $itemId      = $request->item_id ? (int) $request->item_id : null;

        // Fetch items with warehouse eager-loaded
        $items = Item::with('warehouse')
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->forWarehouse($warehouseId)
            ->forCategory($categoryKey)
            ->forItem($itemId)
            ->orderBy('warehouse_id')
            ->orderBy('category')
            ->orderBy('description')
            ->get();

        // Group: warehouse → category → items
        $balances = [];
        foreach ($items->groupBy('warehouse_id') as $wid => $warehouseItems) {
            $warehouse   = $warehouseItems->first()->warehouse;
            $catBalances = [];

            foreach ($warehouseItems->groupBy('category') as $catKey => $catItems) {
                $cat = Item::getCategories()[$catKey] ?? ['label' => ucfirst($catKey), 'account_code' => ''];
                $catBalances[] = [
                    'category'     => $catKey,
                    'label'        => $cat['label'],
                    'account_code' => $cat['account_code'],
                    'total_qty'    => $catItems->sum('quantity'),
                    'total_value'  => $catItems->sum(fn ($i) => $i->quantity * $i->unit_cost),
                    'items'        => $catItems->values(),
                ];
            }

            $balances[] = [
                'warehouse'   => $warehouse,
                'categories'  => $catBalances,
                'grand_total' => collect($catBalances)->sum('total_value'),
            ];
        }

        // Data for filter dropdowns
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();
        $allItems   = Item::where('is_active', true)
            ->forWarehouse($warehouseId)
            ->orderBy('description')
            ->get(['id', 'description', 'warehouse_id']);

        return view('reports.inventory_balance', compact(
            'balances', 'warehouses', 'allItems',
            'warehouseId', 'categoryKey', 'itemId'
        ));
    }

    // ── Excel Exports ──────────────────────────────────────────────────────

    public function exportRpci(Request $request)
    {
        $user = Auth::user();
        $query = Item::with('warehouse')->where('is_active', true);
        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);
        if ($request->category) {
            $query->where('category', $request->category);
        }
        $items = $query->orderBy('category')->orderBy('description')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RPCI');

        // Title rows
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A1', 'REPORT ON THE PHYSICAL COUNT OF INVENTORIES');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        $sheet->mergeCells('A2:J2');
        $sheet->setCellValue('A2', 'As of '.($request->as_of ? date('F d, Y', strtotime($request->as_of)) : date('F d, Y')));
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

        $sheet->mergeCells('A3:J3');
        $centerLabel = $this->getCenterName($user, $request->warehouse_id ? (int) $request->warehouse_id : null);
        $sheet->setCellValue('A3', $centerLabel);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal('center');

        // Headers
        $headers = ['#', 'Stock Number', 'Description', 'Unit', 'Unit Cost', 'Engas Unit Cost', 'Qty Per Card', 'Qty Per Count', 'Total Value', 'Remarks'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col.'5', $h);
            $sheet->getStyle($col.'5')->getFont()->setBold(true);
            $sheet->getStyle($col.'5')->getFill()->setFillType('solid')->getStartColor()->setRGB('D0D8E4');
            $col++;
        }

        $row = 6;
        $no = 1;
        $allCategories = Item::getCategories();
        $currentCat = '';
        $grandTotal = 0;

        foreach ($items as $item) {
            if ($currentCat !== $item->category) {
                $currentCat = $item->category;
                $catLabel = $allCategories[$item->category]['label'] ?? $item->category;
                $acctCode = $allCategories[$item->category]['account_code'] ?? '';
                $sheet->mergeCells("A{$row}:J{$row}");
                $sheet->setCellValue("A{$row}", "{$catLabel}  (Account Code: {$acctCode})");
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}")->getFill()->setFillType('solid')->getStartColor()->setRGB('EEF2F7');
                $row++;
            }

            $totalValue = $item->quantity * $item->unit_cost;
            $grandTotal += $totalValue;

            $sheet->setCellValue("A{$row}", $no++);
            $sheet->setCellValue("B{$row}", $item->stock_number);
            $sheet->setCellValue("C{$row}", $item->description.($item->ris_number ? " ({$item->ris_number})" : ''));
            $sheet->setCellValue("D{$row}", $item->unit);
            $sheet->setCellValue("E{$row}", $item->unit_cost);
            $sheet->setCellValue("F{$row}", $item->engas_unit_cost ?? '');
            $sheet->setCellValue("G{$row}", $item->quantity);
            $sheet->setCellValue("H{$row}", $item->quantity);
            $sheet->setCellValue("I{$row}", $totalValue);
            $sheet->setCellValue("J{$row}", '');

            $sheet->getStyle("E{$row}:I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }

        // Grand total
        $sheet->setCellValue("D{$row}", 'GRAND TOTAL:');
        $sheet->setCellValue("I{$row}", $grandTotal);
        $sheet->getStyle("D{$row}:I{$row}")->getFont()->setBold(true);
        $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A5:J{$row}")->getBorders()->getAllBorders()->setBorderStyle('thin');

        // Column widths
        foreach (['A' => 6, 'B' => 16, 'C' => 36, 'D' => 8, 'E' => 12, 'F' => 14, 'G' => 12, 'H' => 12, 'I' => 14, 'J' => 14] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'RPCI_'.date('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function exportRsmi(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['warehouse', 'items.item'])
            ->whereIn('status', ['approved', 'partially_approved']);
        $this->applyWarehouseScope($query, $user, $request->warehouse_id ? (int) $request->warehouse_id : null);
        if ($request->date_from) {
            $query->whereDate('date_approved', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('date_approved', '<=', $request->date_to);
        }

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('ris_number', 'like', "%{$search}%")
                  ->orWhere('office', 'like', "%{$search}%")
                  ->orWhere('purpose', 'like', "%{$search}%");
            });
        }

        $requisitions = $query->orderBy('date_approved')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RSMI');

        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'REPORT OF SUPPLIES AND MATERIALS ISSUED');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        $dateRange = '';
        if ($request->date_from || $request->date_to) {
            $dateRange = ($request->date_from ? date('F d, Y', strtotime($request->date_from)) : '').
                         ($request->date_to ? ' to '.date('F d, Y', strtotime($request->date_to)) : '');
        } else {
            $dateRange = 'All Dates';
        }
        $sheet->mergeCells('A2:I2');
        $sheet->setCellValue('A2', $dateRange);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

        $headers = ['RIS No.', 'Resp. Center Code', 'Stock No.', 'Item Description', 'Unit', 'Qty Issued', 'Unit Cost', 'Engas Unit Cost', 'Amount'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col.'4', $h);
            $sheet->getStyle($col.'4')->getFont()->setBold(true);
            $sheet->getStyle($col.'4')->getFill()->setFillType('solid')->getStartColor()->setRGB('D0D8E4');
            $col++;
        }

        $row = 5;
        $grandTotal = 0;

        foreach ($requisitions as $ris) {
            foreach ($ris->items as $ri) {
                if ($ri->quantity_issued <= 0) {
                    continue;
                }
                $amount = $ri->quantity_issued * ($ri->item->unit_cost ?? 0);
                $grandTotal += $amount;

                $sheet->setCellValue("A{$row}", $ris->ris_number);
                $sheet->setCellValue("B{$row}", $ris->warehouse->code ?? '');
                $sheet->setCellValue("C{$row}", $ri->item->stock_number ?? '');
                $sheet->setCellValue("D{$row}", $ri->item->description ?? '');
                $sheet->setCellValue("E{$row}", $ri->item->unit ?? '');
                $sheet->setCellValue("F{$row}", $ri->quantity_issued);
                $sheet->setCellValue("G{$row}", $ri->item->unit_cost ?? 0);
                $sheet->setCellValue("H{$row}", $ri->item->engas_unit_cost ?? '');
                $sheet->setCellValue("I{$row}", $amount);

                $sheet->getStyle("F{$row}:I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $row++;
            }
        }

        $sheet->setCellValue("H{$row}", 'TOTAL:');
        $sheet->setCellValue("I{$row}", $grandTotal);
        $sheet->getStyle("H{$row}:I{$row}")->getFont()->setBold(true);
        $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A4:I{$row}")->getBorders()->getAllBorders()->setBorderStyle('thin');

        foreach (['A' => 14, 'B' => 14, 'C' => 16, 'D' => 36, 'E' => 8, 'F' => 12, 'G' => 12, 'H' => 14, 'I' => 14] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'RSMI_'.date('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function exportInventoryBalance(Request $request)
    {
        if (! Auth::user()->hasAdminAccess()) {
            abort(403);
        }

        $warehouseId = $request->warehouse_id ? (int) $request->warehouse_id : null;
        $categoryKey = $request->category ?: null;
        $itemId      = $request->item_id ? (int) $request->item_id : null;

        $items = Item::with('warehouse')
            ->where('is_active', true)
            ->where('quantity', '>', 0)
            ->forWarehouse($warehouseId)
            ->forCategory($categoryKey)
            ->forItem($itemId)
            ->orderBy('warehouse_id')
            ->orderBy('category')
            ->orderBy('description')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventory Balance');

        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', 'INVENTORY BALANCE REPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        $sheet->mergeCells('A2:I2');
        $sheet->setCellValue('A2', 'As of '.date('F d, Y'));
        $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');

        $headers = ['Warehouse', 'Account Code', 'Category', 'Description', 'Unit', 'Quantity', 'Unit Cost', 'Engas Unit Cost', 'Total Value'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col.'4', $h);
            $sheet->getStyle($col.'4')->getFont()->setBold(true);
            $sheet->getStyle($col.'4')->getFill()->setFillType('solid')->getStartColor()->setRGB('D0D8E4');
            $col++;
        }

        $row = 5;
        $grandTotal = 0;

        foreach ($items->groupBy('warehouse_id') as $warehouseItems) {
            foreach ($warehouseItems->groupBy('category') as $catKey => $catItems) {
                $cat = Item::getCategories()[$catKey] ?? ['label' => ucfirst($catKey), 'account_code' => ''];
                foreach ($catItems as $item) {
                    $value = $item->quantity * $item->unit_cost;
                    $grandTotal += $value;

                    $sheet->setCellValue("A{$row}", $item->warehouse->name ?? '');
                    $sheet->setCellValue("B{$row}", $cat['account_code']);
                    $sheet->setCellValue("C{$row}", $cat['label']);
                    $sheet->setCellValue("D{$row}", $item->description);
                    $sheet->setCellValue("E{$row}", $item->unit);
                    $sheet->setCellValue("F{$row}", $item->quantity);
                    $sheet->setCellValue("G{$row}", $item->unit_cost);
                    $sheet->setCellValue("H{$row}", $item->engas_unit_cost ?? '');
                    $sheet->setCellValue("I{$row}", $value);
                    $sheet->getStyle("F{$row}:I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                    $row++;
                }
            }
        }

        $sheet->setCellValue("H{$row}", 'GRAND TOTAL:');
        $sheet->setCellValue("I{$row}", $grandTotal);
        $sheet->getStyle("H{$row}:I{$row}")->getFont()->setBold(true);
        $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("A4:I{$row}")->getBorders()->getAllBorders()->setBorderStyle('thin');

        foreach (['A' => 28, 'B' => 16, 'C' => 32, 'D' => 36, 'E' => 8, 'F' => 12, 'G' => 14, 'H' => 14, 'I' => 16] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'InventoryBalance_'.date('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function viewSnapshot(ReportSnapshot $snapshot)
    {
        $user = Auth::user();

        // Non-admins can only view snapshots belonging to their assigned warehouses
        if (! $user->hasAdminAccess() && $snapshot->warehouse_id !== null) {
            $userWarehouseIds = $this->getUserWarehouseIds($user);
            if (! in_array($snapshot->warehouse_id, $userWarehouseIds ?? [])) {
                abort(403);
            }
        }

        $data = json_decode($snapshot->data, true);

        return view('reports.snapshot', compact('snapshot', 'data'));
    }
}

