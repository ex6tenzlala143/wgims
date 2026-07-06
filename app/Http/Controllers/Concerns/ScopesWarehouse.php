<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\Warehouse;

/**
 * Shared warehouse-scoping helpers used by all controllers that need to
 * restrict data to the warehouses assigned to the logged-in user.
 *
 * A non-admin user may be assigned to multiple warehouses via the
 * user_warehouse pivot table AND/OR the legacy warehouse_id column.
 * All scoping must consider both sources.
 */
trait ScopesWarehouse
{
    /**
     * Returns the warehouse IDs the given user is allowed to see.
     *
     * - Admin / Warehouse Manager → null  (no restriction — sees all data)
     * - Others → array of IDs from the pivot table + the legacy warehouse_id column
     *            (may be empty if the user has no assignments at all)
     */
    protected function getUserWarehouseIds(User $user): ?array
    {
        if ($user->hasAdminAccess()) {
            return null; // null = no restriction
        }

        $ids = $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->toArray();

        if ($user->warehouse_id && ! in_array((int) $user->warehouse_id, $ids, true)) {
            $ids[] = (int) $user->warehouse_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Apply warehouse scoping to a query builder using a `warehouse_id` column.
     *
     * - Non-admin with assignments → whereIn('warehouse_id', [...])
     * - Non-admin with NO assignments → whereRaw('1 = 0')  (returns nothing)
     * - Admin with $filterWarehouseId → where('warehouse_id', $filterWarehouseId)
     * - Admin with no filter → no restriction
     *
     * Returns false when the user has no warehouse assignments (empty result set).
     */
    protected function applyWarehouseScope($query, User $user, ?int $filterWarehouseId = null): bool
    {
        $ids = $this->getUserWarehouseIds($user);

        if ($ids !== null) {
            // Non-admin path
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
                return false;
            }
            $query->whereIn('warehouse_id', $ids);
            return true;
        }

        // Admin path
        if ($filterWarehouseId) {
            $query->where('warehouse_id', $filterWarehouseId);
        }

        return true;
    }

    /**
     * Apply warehouse scoping for StockTransfers, which have two warehouse columns.
     * A non-admin sees a transfer if EITHER warehouse is one of their assigned ones.
     *
     * Returns false when the user has no warehouse assignments.
     */
    protected function applyTransferWarehouseScope($query, User $user): bool
    {
        $ids = $this->getUserWarehouseIds($user);

        if ($ids !== null) {
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
                return false;
            }
            $query->where(function ($q) use ($ids) {
                $q->whereIn('from_warehouse_id', $ids)
                  ->orWhereIn('to_warehouse_id', $ids);
            });
            return true;
        }

        return true;
    }

    /**
     * Check whether a non-admin user is allowed to access a specific warehouse.
     * Admins and warehouse managers always pass. Returns false if the user has no access.
     */
    protected function userCanAccessWarehouse(User $user, int $warehouseId): bool
    {
        if ($user->hasAdminAccess()) {
            return true;
        }

        $ids = $this->getUserWarehouseIds($user);
        return in_array($warehouseId, $ids ?? [], true);
    }

    /**
     * Returns a human-readable label for the user's warehouse scope.
     * Used in print/export headers.
     */
    protected function getCenterName(User $user, ?int $filterWarehouseId = null): string
    {
        if ($user->hasAdminAccess()) {
            if ($filterWarehouseId) {
                return optional(Warehouse::find($filterWarehouseId))->name ?? 'All Warehouses';
            }
            return 'All Warehouses';
        }

        $names = $user->warehouses()->pluck('name')->toArray();
        if (empty($names) && $user->warehouse) {
            $names = [$user->warehouse->name];
        }

        return implode(' / ', $names) ?: 'N/A';
    }
}
