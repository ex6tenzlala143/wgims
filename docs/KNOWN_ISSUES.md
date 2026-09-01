# WGIMS Known Issues

## CRITICAL

### 1. Race Condition Potential in Number Generation
- **Area**: Item stock numbers, RIS numbers, Transfer numbers
- **Issue**: Advisory locks (`GET_LOCK`) are MySQL-specific. On SQLite (used in local/dev), these are no-ops.
- **Impact**: Concurrent deliveries on SQLite could generate duplicate stock numbers.
- **Mitigation**: Paranoid double-check after generation; production uses MySQL where locks work.

### 2. Stock Card Balance Drift on Direct DB Modification
- **Area**: StockCardEntry balances
- **Issue**: If entries are modified directly in DB (not via app), balances become stale.
- **Impact**: Incorrect running balances on stock cards.
- **Mitigation**: Always call `StockCardEntry::recalculateBalancesForItem()` after any programmatic change.

### 3. Subsidy Deletion Cascade Complexity
- **Area**: DeliverySubsidyController::destroy
- **Issue**: Multi-hop transfer lineage tracing is complex; edge cases with circular transfers not explicitly handled.
- **Impact**: Potential for orphaned snapshot data or missed lineage items.
- **Mitigation**: Cycle guard in `transferLineageItemIds()`.

## HIGH

### 4. N+1 Queries in Dashboard
- **Area**: DashboardController
- **Issue**: Loading all items for warehouse users could be heavy with large datasets.
- **Impact**: Slow dashboard load for users with many items.
- **Mitigation**: Currently uses DB aggregation for admin; warehouse path loads items directly.

### 5. Reservation Fulfillment Not Automatic
- **Area**: Reservation allocation during requisition dispatch
- **Issue**: When a requisition dispatches from a reserved item, the reservation is NOT automatically allocated.
- **Impact**: Reserved quantities may not be released, blocking future reservations.
- **Fix Needed**: Call `Reservation::allocate()` during `processApproval` when dispatching from a reserved item.

### 6. Cost Column Dropped from requisition_items
- **Area**: Migration 2026_08_24_210730
- **Issue**: `requisition_items.unit_cost` and `engas_unit_cost` were dropped. Cost data now lives only on `requisition_dispatch_items`.
- **Impact**: Legacy code or reports that read from `requisition_items.unit_cost` will get stale/zero values.
- **Mitigation**: All modern code reads from dispatch items.

### 7. warehouse_id Nullable on Many Tables
- **Area**: delivery_subsidies, requisitions, delivery_subsidy_items, requisition_items
- **Issue**: Legacy `warehouse_id` columns are nullable because warehouse is now determined at line-item/dispatch level.
- **Impact**: Queries that assume `warehouse_id` is always set may break.
- **Mitigation**: Code must check for null and fall back to line-item/dispatch warehouses.

## MEDIUM

### 8. Email Verification Column Dropped
- **Area**: Migration 2026_08_30_090953
- **Issue**: `email_verified_at` dropped from users table.
- **Impact**: Any email verification flow relying on this column will break.
- **Mitigation**: None needed if email verification not used.

### 9. Purchase Orders Table Exists but Unused
- **Area**: database/migrations/2026_05_05_100003_create_purchase_orders_table.php
- **Issue**: Table created but no model, controller, or references found.
- **Impact**: Dead code/migration.
- **Mitigation**: None — legacy artifact.

### 10. Spatie Permission Tables Created but Not Used
- **Area**: config/permission.php, migration 2026_06_21_102551
- **Issue**: Spatie permission tables exist but authorization uses `users.role` column directly.
- **Impact**: Unused tables, potential confusion.
- **Mitigation**: None — alternative to migrating to Spatie later.

### 11. Stock Transfer Item cascadeOnDelete
- **Area**: stock_transfer_items migration
- **Issue**: `item_id` and `destination_item_id` have `cascadeOnDelete`. If an item is deleted, transfer history referencing it is also deleted.
- **Impact**: Loss of transfer history when items are hard-deleted.
- **Mitigation**: Items are only hard-deleted when qty=0 and no other references exist.

### 12. Bfcache Session Validation
- **Area**: app.blade.php pageshow handler
- **Issue**: Validates session by calling notifications API on every bfcache restore.
- **Impact**: Extra API call on back/forward navigation.
- **Mitigation**: Lightweight endpoint; acceptable trade-off for security.

## LOW

### 13. Pagination Defaults
- **Area**: All list views
- **Issue**: Pagination fixed at 20 items per page.
- **Impact**: May not suit all users/contexts.
- **Mitigation**: None — simple and consistent.

### 14. No Image Upload for Logo
- **Area**: layouts/app.blade.php
- **Issue**: Logo referenced as `images/logo.png` but no upload mechanism found.
- **Impact**: Static asset only.
- **Mitigation**: None needed.

### 15. Hard-coded Admin Paths in AuthController
- **Area**: AuthController::login
- **Issue**: `$adminPaths` array hard-codes URL prefixes.
- **Impact**: Adding new admin routes requires updating this array.
- **Mitigation**: Consider using middleware groups or route names.

## OPTIMIZATION

### 16. Large JSON in Report Snapshots
- **Area**: ReportSnapshot::data
- **Issue**: Full item lists stored as JSON in longText column.
- **Impact**: Large snapshots may approach column limits.
- **Mitigation**: Consider compressing or splitting if snapshots grow very large.

### 17. No Query Result Caching
- **Area**: Various controllers
- **Issue**: Repeated queries for warehouses, categories, suppliers not cached.
- **Impact**: Minor — these tables are small.
- **Mitigation**: `ItemCategory::allActive()` uses 60s cache; could extend pattern.

### 18. Notification Polling Interval
- **Area**: layouts/app.blade.php
- **Issue**: 30-second polling for notifications.
- **Impact**: Unread count may be up to 30 seconds stale.
- **Mitigation**: Acceptable for non-critical notifications.
