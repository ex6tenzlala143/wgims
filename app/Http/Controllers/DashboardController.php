<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWarehouse;
use App\Models\Item;
use App\Models\DeliverySubsidy;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\Requisition;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ScopesWarehouse;

    public function index()
    {
        $user = Auth::user();

        // ── Admin dashboard (admin + warehouse manager both get the full view) ──
        if ($user->hasAdminAccess()) {
            $balanceRows = DB::table('items')
                ->join('warehouses', 'warehouses.id', '=', 'items.warehouse_id')
                ->where('items.is_active', true)
                ->where('warehouses.is_active', true)
                ->select(
                    'warehouses.id as warehouse_id',
                    'warehouses.name as warehouse_name',
                    'warehouses.code as warehouse_code',
                    'items.category',
                    DB::raw('SUM(items.quantity) as total_qty'),
                    DB::raw('SUM(items.quantity * items.unit_cost) as total_value')
                )
                ->groupBy('warehouses.id', 'warehouses.name', 'warehouses.code', 'items.category')
                ->orderBy('warehouses.name')
                ->orderBy('items.category')
                ->get();

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
                        'total_qty'    => (int) $row->total_qty,
                        'total_value'  => (float) $row->total_value,
                    ];
                }
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
                ->orderByDesc('date')
                ->limit(200)
                ->get()
                ->groupBy('warehouse_id');

            $stats = [
                'total_items'      => Item::count(),
                'total_subsidies'  => DeliverySubsidy::count(),
                'pending_ris'      => Requisition::where('status', 'pending')->count(),
                'total_warehouses' => Warehouse::where('is_active', true)->count(),
            ];

            // ── NEW: Calculate Requisition/Augmentation totals (actual dispatched) ──
            $rdiTotals = DB::table('requisition_dispatch_items')
                ->join('requisition_items', 'requisition_items.id', '=', 'requisition_dispatch_items.requisition_item_id')
                ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
                ->where('requisitions.status', '!=', 'cancelled')
                ->selectRaw('SUM(requisition_dispatch_items.quantity_issued) as total_qty, SUM(requisition_dispatch_items.quantity_issued * requisition_dispatch_items.unit_cost) as total_amt')
                ->first();

            $totalRequisitionQty = (float) ($rdiTotals->total_qty ?? 0);
            $totalRequisitionAmt = (float) ($rdiTotals->total_amt ?? 0);

            // ── NEW: Calculate Subsidy totals (actual delivered) ──
            $subsidyTotals = DB::table('delivery_items')
                ->selectRaw('SUM(quantity_delivered) as total_qty, SUM(quantity_delivered * unit_cost) as total_amt')
                ->first();

            $totalSubsidyQty = (float) ($subsidyTotals->total_qty ?? 0);
            $totalSubsidyAmt = (float) ($subsidyTotals->total_amt ?? 0);

            // ── NEW: Calculate Reservation totals (active only) ──
            // Active reservations: ACTIVE and PARTIALLY_DEPLOYED
            // Remaining = reserved - deployed
            $riTotals = DB::table('reservation_items')
                ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED'])
                ->selectRaw('COALESCE(SUM(reserved_quantity), 0) as total_reserved, COALESCE(SUM(deployed_quantity), 0) as total_deployed')
                ->first();
            $totalReservedQty = (float) ($riTotals->total_reserved ?? 0) - (float) ($riTotals->total_deployed ?? 0);

            // Reservation amount: sum of (remaining_qty * unit_cost) per stock record
            $reservationAmtQuery = DB::table('reservation_items')
                ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED'])
                ->selectRaw('SUM((reserved_quantity - deployed_quantity) * unit_cost) as total')
                ->first();
            $totalReservedAmt = (float) ($reservationAmtQuery->total ?? 0);

            // ── Chart data: Monthly activity comparison ──
            // Compare monthly: requisition dispatched, subsidy delivered, reservation deployed
            $chartData = $this->getMonthlyActivityChart();

            $reservationStats   = $this->getReservationStats(null);
            $recentReservations = $this->getRecentReservations(null);
            $reservedItems      = $this->getReservedItemsSummary(null);

            return view('dashboard.admin', compact(
                'balances', 'unliquidated', 'stats',
                'reservationStats', 'recentReservations', 'reservedItems',
                'totalRequisitionQty', 'totalRequisitionAmt',
                'totalSubsidyQty', 'totalSubsidyAmt',
                'totalReservedQty', 'totalReservedAmt',
                'chartData'
            ));
        }

        // ── Warehouse user dashboard ──────────────────────────────────────
        $warehouseIds = $this->getUserWarehouseIds($user);

        if (empty($warehouseIds)) {
            return view('dashboard.no_warehouse');
        }

        $assignedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_active', true)
            ->get();

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
                'total_qty'    => (int) $row->total_qty,
                'total_value'  => (float) $row->total_value,
            ];
        }

        $stats = [
            'total_items'     => Item::whereIn('warehouse_id', $warehouseIds)->count(),
            'total_subsidies' => DeliverySubsidy::whereHas('deliveries.items', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))->count(),
            'pending_ris'     => Requisition::where('status', 'pending')
                ->where(function ($q) use ($warehouseIds) {
                    $q->whereHas('items', fn ($i) => $i->whereIn('warehouse_id', $warehouseIds))
                      ->orWhereIn('warehouse_id', $warehouseIds);
                })
                ->count(),
        ];

        // ── NEW: Calculate Requisition/Augmentation totals (warehouse-scoped) ──
        $rdiTotals = DB::table('requisition_dispatch_items')
            ->join('requisition_items', 'requisition_items.id', '=', 'requisition_dispatch_items.requisition_item_id')
            ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
            ->join('items', 'items.id', '=', 'requisition_dispatch_items.item_id')
            ->where('requisitions.status', '!=', 'cancelled')
            ->whereIn('items.warehouse_id', $warehouseIds)
            ->selectRaw('SUM(requisition_dispatch_items.quantity_issued) as total_qty, SUM(requisition_dispatch_items.quantity_issued * requisition_dispatch_items.unit_cost) as total_amt')
            ->first();
        $totalRequisitionQty = (float) ($rdiTotals->total_qty ?? 0);
        $totalRequisitionAmt = (float) ($rdiTotals->total_amt ?? 0);

        // ── NEW: Calculate Subsidy totals (warehouse-scoped) ──
        $subsidyTotals = DB::table('delivery_items')
            ->whereIn('delivery_items.warehouse_id', $warehouseIds)
            ->selectRaw('SUM(delivery_items.quantity_delivered) as total_qty, SUM(delivery_items.quantity_delivered * delivery_items.unit_cost) as total_amt')
            ->first();
        $totalSubsidyQty = (float) ($subsidyTotals->total_qty ?? 0);
        $totalSubsidyAmt = (float) ($subsidyTotals->total_amt ?? 0);

        // ── NEW: Calculate Reservation totals (warehouse-scoped) ──
        $riTotals = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED'])
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('COALESCE(SUM(reserved_quantity), 0) as total_reserved, COALESCE(SUM(deployed_quantity), 0) as total_deployed')
            ->first();
        $totalReservedQty = (float) ($riTotals->total_reserved ?? 0) - (float) ($riTotals->total_deployed ?? 0);
        
        // Reservation amount: sum of (remaining_qty * unit_cost) per stock record
        $reservationAmtQuery = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED'])
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('SUM((reserved_quantity - deployed_quantity) * unit_cost) as total')
            ->first();
        $totalReservedAmt = (float) ($reservationAmtQuery->total ?? 0);

        // ── Chart data (warehouse-scoped) ──
        $chartData = $this->getMonthlyActivityChart($warehouseIds);

        $warehouse = $user->warehouse ?? $assignedWarehouses->first();
        if (! $warehouse) {
            return view('dashboard.no_warehouse');
        }

        $reservationStats   = $this->getReservationStats($warehouseIds);
        $recentReservations = $this->getRecentReservations($warehouseIds);
        $reservedItems      = $this->getReservedItemsSummary($warehouseIds);

        return view('dashboard.warehouse', compact(
            'warehouse', 'assignedWarehouses', 'accountBalances', 'stats',
            'reservationStats', 'recentReservations', 'reservedItems',
            'totalRequisitionQty', 'totalRequisitionAmt',
            'totalSubsidyQty', 'totalSubsidyAmt',
            'totalReservedQty', 'totalReservedAmt',
            'chartData'
        ));
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Aggregate reservation counts and totals.
     * $warehouseIds = null means no warehouse restriction (admin).
     * $warehouseIds = []  means no access at all.
     *
     * @param  int[]|null  $warehouseIds
     */
    private function getReservationStats(?array $warehouseIds): array
    {
        // ── Get counts ───────────────────────────────────────────────
        $countQuery = DB::table('reservations');

        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $countQuery->whereExists(function ($q) use ($warehouseIds) {
                $q->select(DB::raw(1))
                  ->from('reservation_items')
                  ->whereColumn('reservation_items.reservation_id', 'reservations.id')
                  ->whereIn('reservation_items.warehouse_id', $warehouseIds);
            });
        } elseif ($warehouseIds !== null && empty($warehouseIds)) {
            return $this->emptyReservationStats();
        }

        $counts = $countQuery->select(
            DB::raw("COUNT(*) as total"),
            DB::raw("SUM(CASE WHEN status IN ('PENDING','RESERVED','READY_FOR_REQUISITION','PARTIALLY_DEPLOYED') THEN 1 ELSE 0 END) as active"),
            DB::raw("SUM(CASE WHEN status = 'PARTIALLY_DEPLOYED' THEN 1 ELSE 0 END) as partially_deployed"),
            DB::raw("SUM(CASE WHEN status = 'DEPLOYED' THEN 1 ELSE 0 END) as deployed"),
            DB::raw("SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled"),
            DB::raw("SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END) as expired")
        )->first();

        // ── Total reserved quantity across active reservation_items ──
        $riQuery = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED']);
        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $riQuery->whereIn('warehouse_id', $warehouseIds);
        }
        $totalReserved = (float) $riQuery->sum('reserved_quantity');

        // ── Total deployed across active reservation_items ────────────
        $deployedQuery = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED']);
        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $deployedQuery->whereIn('warehouse_id', $warehouseIds);
        }
        $totalDeployed = (float) $deployedQuery->sum('deployed_quantity');

        // Nearing expiry (within 7 days, still active)
        $nearExpiryQuery = DB::table('reservations')
            ->whereIn('status', ['PENDING', 'RESERVED', 'READY_FOR_REQUISITION', 'PARTIALLY_DEPLOYED'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->where('expires_at', '>', now());
        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $nearExpiryQuery->whereExists(function ($q) use ($warehouseIds) {
                $q->select(DB::raw(1))
                  ->from('reservation_items')
                  ->whereColumn('reservation_items.reservation_id', 'reservations.id')
                  ->whereIn('reservation_items.warehouse_id', $warehouseIds);
            });
        }
        $nearExpiry = $nearExpiryQuery->count();

        $expiredQuery = DB::table('reservations')->where('status', 'EXPIRED');
        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $expiredQuery->whereExists(function ($q) use ($warehouseIds) {
                $q->select(DB::raw(1))
                  ->from('reservation_items')
                  ->whereColumn('reservation_items.reservation_id', 'reservations.id')
                  ->whereIn('reservation_items.warehouse_id', $warehouseIds);
            });
        }
        $expired = $expiredQuery->count();

        return [
            'total'              => (int) ($counts->total             ?? 0),
            'active'             => (int) ($counts->active            ?? 0),
            'partially_deployed' => (int) ($counts->partially_deployed ?? 0),
            'deployed'           => (int) ($counts->deployed           ?? 0),
            'cancelled'          => (int) ($counts->cancelled          ?? 0),
            'expired'            => (int) ($counts->expired            ?? 0),
            'total_reserved'     => $totalReserved,
            'total_deployed'     => $totalDeployed,
            'near_expiry'        => (int) $nearExpiry,
        ];
    }

    private function emptyReservationStats(): array
    {
        return [
            'total' => 0, 'active' => 0, 'partially_deployed' => 0,
            'deployed' => 0, 'cancelled' => 0, 'expired' => 0,
            'total_reserved' => 0, 'total_deployed' => 0, 'near_expiry' => 0,
        ];
    }

    /**
     * Recent reservations (last 5) with their item/warehouse summaries.
     *
     * @param  int[]|null  $warehouseIds
     */
    private function getRecentReservations(?array $warehouseIds): \Illuminate\Support\Collection
    {
        if ($warehouseIds !== null && empty($warehouseIds)) {
            return collect();
        }

        $query = Reservation::with(['items.warehouse', 'creator'])
            ->orderByDesc('created_at')
            ->limit(6);

        if ($warehouseIds !== null) {
            $query->whereHas('items', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds));
        }

        return $query->get()->map(function (Reservation $res) {
            $items       = $res->items;
            $warehouses  = $items->map(fn ($i) => $i->warehouse?->name)->filter()->unique()->values();
            $totalRes    = $items->sum('reserved_quantity');
            $totalDep    = $items->sum('deployed_quantity');
            return [
                'id'                 => $res->id,
                'reservation_number' => $res->reservation_number ?? '#' . $res->id,
                'purpose'            => $res->purpose,
                'status'             => $res->status,
                'status_label'       => $res->status_label,
                'status_badge'       => $res->status_badge_class,
                'created_at'         => $res->created_at,
                'created_by'         => $res->creator->name ?? '—',
                'item_count'         => $items->count(),
                'warehouses'         => $warehouses->implode(', ') ?: '—',
                'total_reserved'     => $totalRes,
                'total_deployed'     => $totalDep,
                'total_remaining'    => max(0, $totalRes - $totalDep),
                'expires_at'         => $res->expires_at,
            ];
        });
    }

    /**
     * Items that have active reservations — shows physical/reserved/available
     * per exact stock record (respects full stock identity, never merges).
     * Capped at 20 rows to keep the dashboard lightweight.
     *
     * @param  int[]|null  $warehouseIds
     */
    private function getReservedItemsSummary(?array $warehouseIds): \Illuminate\Support\Collection
    {
        if ($warehouseIds !== null && empty($warehouseIds)) {
            return collect();
        }

        // Find item IDs that have active reservation_items
        $riQuery = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED'])
            ->select('item_id', DB::raw('SUM(reserved_quantity) as reserved_qty'))
            ->groupBy('item_id');

        if ($warehouseIds !== null) {
            $riQuery->whereIn('warehouse_id', $warehouseIds);
        }

        $reserved = $riQuery->get()->keyBy('item_id');

        if ($reserved->isEmpty()) {
            return collect();
        }

        $itemIds = $reserved->keys()->toArray();

        $items = Item::with('warehouse')
            ->whereIn('id', $itemIds)
            ->where('is_active', true)
            ->orderBy('warehouse_id')
            ->orderBy('description')
            ->limit(20)
            ->get();

        return $items->map(function (Item $item) use ($reserved) {
            $resQty   = (float) ($reserved->get($item->id)->reserved_qty ?? 0);
            $availQty = max(0, $item->quantity - $resQty);
            return [
                'id'           => $item->id,
                'stock_number' => $item->stock_number,
                'description'  => $item->description,
                'unit'         => $item->unit,
                'warehouse'    => $item->warehouse->name ?? '—',
                'physical_qty' => $item->quantity,
                'reserved_qty' => $resQty,
                'available_qty'=> $availQty,
            ];
        });
    }

    /**
     * Get monthly activity chart data for dashboard visualization.
     * Compares requisition dispatched, subsidy delivered, and reservation deployed quantities.
     *
     * @param  int[]|null  $warehouseIds  Null means no restriction (admin), empty means no access
     */
    private function getMonthlyActivityChart(?array $warehouseIds = null): array
    {
        // Determine date range: last 12 months
        $endDate = now()->endOfMonth();
        $startDate = $endDate->copy()->subMonths(11)->startOfMonth();

        // Build base query for date range
        $dateRange = [];
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $dateRange[] = $current->format('Y-m');
            $current->addMonth();
        }

        // ── Requisition dispatched data ──────────────────────────────────────
        $reqQuery = DB::table('requisition_dispatch_items')
            ->join('requisition_items', 'requisition_items.id', '=', 'requisition_dispatch_items.requisition_item_id')
            ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
            ->where('requisitions.status', '!=', 'cancelled')
            ->whereBetween(DB::raw('DATE(requisition_dispatch_items.created_at)'), [
                $startDate->toDateString(),
                $endDate->toDateString()
            ]);

        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $reqQuery->join('items', 'items.id', '=', 'requisition_dispatch_items.item_id')
                ->whereIn('items.warehouse_id', $warehouseIds);
        }

        $reqData = $reqQuery->selectRaw(
            "DATE_FORMAT(DATE(requisition_dispatch_items.created_at), '%Y-%m') as month,
             SUM(requisition_dispatch_items.quantity_issued) as qty"
        )->groupBy('month')->get()->keyBy('month');

        // ── Subsidy delivered data ──────────────────────────────────────────
        $subQuery = DB::table('delivery_items')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->whereBetween(DB::raw('DATE(deliveries.delivery_date)'), [
                $startDate->toDateString(),
                $endDate->toDateString()
            ]);

        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $subQuery->whereIn('delivery_items.warehouse_id', $warehouseIds);
        }

        $subData = $subQuery->selectRaw(
            "DATE_FORMAT(DATE(deliveries.delivery_date), '%Y-%m') as month,
             SUM(delivery_items.quantity_delivered) as qty"
        )->groupBy('month')->get()->keyBy('month');

        // ── Reservation deployed data ───────────────────────────────────────
        $resQuery = DB::table('reservation_items')
            ->whereBetween(DB::raw('DATE(reservation_items.created_at)'), [
                $startDate->toDateString(),
                $endDate->toDateString()
            ]);

        if ($warehouseIds !== null && !empty($warehouseIds)) {
            $resQuery->whereIn('reservation_items.warehouse_id', $warehouseIds);
        }

        $resData = $resQuery->selectRaw(
            "DATE_FORMAT(DATE(reservation_items.created_at), '%Y-%m') as month,
             SUM(reservation_items.deployed_quantity) as qty"
        )->groupBy('month')->get()->keyBy('month');

        // Build chart series aligned to date range
        $labels = [];
        $reqSeries = [];
        $subSeries = [];
        $resSeries = [];

        foreach ($dateRange as $month) {
            $labels[] = \Carbon\Carbon::parse($month . '-01')->format('M Y');
            $reqSeries[] = (float) ($reqData->get($month)->qty ?? 0);
            $subSeries[] = (float) ($subData->get($month)->qty ?? 0);
            $resSeries[] = (float) ($resData->get($month)->qty ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Requisition Dispatched',
                    'data' => $reqSeries,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Subsidy Delivered',
                    'data' => $subSeries,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Reservation Deployed',
                    'data' => $resSeries,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                ],
            ],
        ];
    }
}
