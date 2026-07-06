<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\DeliverySubsidy;
use App\Models\Requisition;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ScopesWarehouse;

    public function index()
    {
        $user = Auth::user();

        // ── Admin dashboard (admin + warehouse manager both get the full view) ──
        if ($user->hasAdminAccess()) {
            // Use DB-level aggregation instead of loading all items into memory.
            // Two targeted queries replace an unbounded collection load.
            $balanceRows = \Illuminate\Support\Facades\DB::table('items')
                ->join('warehouses', 'warehouses.id', '=', 'items.warehouse_id')
                ->where('items.is_active', true)
                ->where('warehouses.is_active', true)
                ->select(
                    'warehouses.id as warehouse_id',
                    'warehouses.name as warehouse_name',
                    'warehouses.code as warehouse_code',
                    'items.category',
                    \Illuminate\Support\Facades\DB::raw('SUM(items.quantity) as total_qty'),
                    \Illuminate\Support\Facades\DB::raw('SUM(items.quantity * items.unit_cost) as total_value')
                )
                ->groupBy('warehouses.id', 'warehouses.name', 'warehouses.code', 'items.category')
                ->orderBy('warehouses.name')
                ->orderBy('items.category')
                ->get();

            // Reshape into the structure the view expects
            $balances = [];
            foreach ($balanceRows->groupBy('warehouse_id') as $warehouseId => $rows) {
                $first = $rows->first();
                $accountBalances = [];
                foreach ($rows as $row) {
                    $cat = Item::getCategories()[$row->category] ?? null;
                    if (! $cat) continue;
                    $accountBalances[] = [
                        'account_code' => $cat['account_code'],
                        'label'        => $cat['label'],
                        'total_qty'    => (float) $row->total_qty,
                        'total_value'  => (float) $row->total_value,
                    ];
                }
                // Build a minimal warehouse object the view can use
                $warehouseObj = (object) [
                    'id'   => $first->warehouse_id,
                    'name' => $first->warehouse_name,
                    'code' => $first->warehouse_code,
                ];
                $grandTotal = array_sum(array_column($accountBalances, 'total_value'));
                $balances[] = [
                    'warehouse'        => $warehouseObj,
                    'account_balances' => $accountBalances,
                    'grand_total'      => $grandTotal,
                ];
            }

            $unliquidated = DeliverySubsidy::with('warehouse')
                ->whereIn('status', ['pending', 'partial'])
                ->get()
                ->groupBy('warehouse_id');

            $stats = [
                'total_items'      => Item::count(),
                'total_pos'        => DeliverySubsidy::count(),
                'pending_ris'      => Requisition::where('status', 'pending')->count(),
                'total_warehouses' => Warehouse::where('is_active', true)->count(),
            ];

            return view('dashboard.admin', compact('balances', 'unliquidated', 'stats'));
        }

        // ── Warehouse user dashboard ───────────────────────────────────────
        $warehouseIds = $this->getUserWarehouseIds($user);

        if (empty($warehouseIds)) {
            // No warehouse assigned — show a friendly error view instead of aborting
            return view('dashboard.no_warehouse');
        }

        // Load all assigned warehouses for display
        $assignedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_active', true)
            ->get();

        // Aggregate account balances — single DB query instead of one per category
        $balanceRows = DB::table('items')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('is_active', true)
            ->select(
                'category',
                DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(quantity * unit_cost) as total_value')
            )
            ->groupBy('category')
            ->get();

        $allCategories   = Item::getCategories();
        $accountBalances = [];
        foreach ($balanceRows as $row) {
            $cat = $allCategories[$row->category] ?? null;
            if (! $cat) continue;
            $accountBalances[] = [
                'account_code' => $cat['account_code'],
                'label'        => $cat['label'],
                'total_qty'    => (float) $row->total_qty,
                'total_value'  => (float) $row->total_value,
            ];
        }

        $stats = [
            'total_items' => Item::whereIn('warehouse_id', $warehouseIds)->count(),
            'total_pos'   => DeliverySubsidy::whereIn('warehouse_id', $warehouseIds)->count(),
            'pending_ris' => Requisition::whereIn('warehouse_id', $warehouseIds)
                                ->where('status', 'pending')->count(),
        ];

        // Keep backward-compat: pass the primary warehouse as $warehouse
        // (legacy warehouse_id first, then first pivot-assigned warehouse)
        $warehouse = $user->warehouse ?? $assignedWarehouses->first();

        // Safety: if somehow still null (all assigned warehouses are inactive), show no_warehouse
        if (! $warehouse) {
            return view('dashboard.no_warehouse');
        }

        return view('dashboard.warehouse', compact(
            'warehouse',
            'assignedWarehouses',
            'accountBalances',
            'stats'
        ));
    }
}
