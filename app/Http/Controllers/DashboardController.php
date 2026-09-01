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

            $reservationStats   = $this->getReservationStats(null);
            $recentReservations = $this->getRecentReservations(null);
            $reservedItems      = $this->getReservedItemsSummary(null);

            return view('dashboard.admin', compact(
                'balances', 'unliquidated', 'stats',
                'reservationStats', 'recentReservations', 'reservedItems'
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

        $warehouse = $user->warehouse ?? $assignedWarehouses->first();
        if (! $warehouse) {
            return view('dashboard.no_warehouse');
        }

        $reservationStats   = $this->getReservationStats($warehouseIds);
        $recentReservations = $this->getRecentReservations($warehouseIds);
        $reservedItems      = $this->getReservedItemsSummary($warehouseIds);

        return view('dashboard.warehouse', compact(
            'warehouse', 'assignedWarehouses', 'accountBalances', 'stats',
            'reservationStats', 'recentReservations', 'reservedItems'
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
        // Status counts — one query using conditional SUM
        $query = DB::table('reservations');

        if ($warehouseIds !== null) {
            if (empty($warehouseIds)) {
                return $this->emptyReservationStats();
            }
            // Scope: reservation has at least one item in these warehouses
            $query->whereExists(function ($q) use ($warehouseIds) {
                $q->select(DB::raw(1))
                  ->from('reservation_items')
                  ->whereColumn('reservation_items.reservation_id', 'reservations.id')
                  ->whereIn('reservation_items.warehouse_id', $warehouseIds);
            });
        }

        $counts = (clone $query)->select(
            DB::raw("COUNT(*) as total"),
            DB::raw("SUM(CASE WHEN status IN ('PENDING','RESERVED','READY_FOR_REQUISITION','PARTIALLY_DEPLOYED') THEN 1 ELSE 0 END) as active"),
            DB::raw("SUM(CASE WHEN status = 'PARTIALLY_DEPLOYED' THEN 1 ELSE 0 END) as partially_deployed"),
            DB::raw("SUM(CASE WHEN status = 'DEPLOYED' THEN 1 ELSE 0 END) as deployed"),
            DB::raw("SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled"),
            DB::raw("SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END) as expired")
        )->first();

        // Total reserved quantity across active reservation_items
        $riQuery = DB::table('reservation_items')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_DEPLOYED']);

        if ($warehouseIds !== null) {
            $riQuery->whereIn('warehouse_id', $warehouseIds);
        }

        $totalReserved = (float) $riQuery->sum('reserved_quantity');
        $totalDeployed = (float) (clone $riQuery)->sum('deployed_quantity');

        // Nearing expiry (within 7 days, still active)
        $nearExpiry = (clone $query)
            ->whereIn('status', ['PENDING', 'RESERVED', 'READY_FOR_REQUISITION', 'PARTIALLY_DEPLOYED'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(7))
            ->where('expires_at', '>', now())
            ->count();

        $expired = (clone $query)
            ->where('status', 'EXPIRED')
            ->count();

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
}
