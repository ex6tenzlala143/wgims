# WGIMS — AI Agent Instructions

> Deep-audit context: read `WGIMS_AI_CONTEXT.md` (v2, audited 2026-09-08 at HEAD `d4edc9e`)
> before any major change. It contains the full route map, schema, flows, and known gaps.
> Existing `docs/` (ARCHITECTURE, DATABASE_SCHEMA, BUSINESS_RULES, WORKFLOWS, SECURITY,
> KNOWN_ISSUES, TESTING_GUIDE) are secondary references.

## Technology Stack

- **PHP**: 8.2+
- **Laravel**: 12.0
- **Database**: SQLite (primary), supports MySQL
- **Frontend**: Blade templates, Tailwind CSS v4 (via Vite), vanilla JavaScript (no framework)
- **Build**: Vite 7 with Laravel Vite plugin
- **Auth**: Session-based (single web guard), login via `username` field
- **Authorization**: Custom middleware + Spatie Laravel Permission (roles only, no permissions used)
- **Excel**: PhpSpreadsheet (PhpOffice)
- **Packages**: spatie/laravel-permission, laravel/tinker, laravel/pint, laravel/pail

## Role/Permission System

Roles (stored in `users.role` column, NOT via Spatie roles):
- `admin` — full access
- `warehouse_manager` — can create but not edit/delete
- `supply_custodian` — can approve
- `center_staff` — read-only, cannot create
- `center_head` — can approve

Middleware:
- `admin` — admin + warehouse_manager (view access)
- `admin.write` — admin only (mutating actions)
- `admin.create` — admin + warehouse_manager (create actions)
- `admin.only.strict` — admin only (no warehouse_manager)

Nuances (verified in code — do not assume the middleware tells the whole story):
- Reservation approve/ready/cancel routes are `auth`-only but controllers require
  `canWrite()` = **admin-only**, even though `User::canApprove()` lists WM/head/custodian.
  Only RIS dispatch actually honors `canApprove()`.
- Transfers edit/update/delete routes use `admin` (allows WM) but controllers enforce
  `canWrite()` (admin-only). Treat them as admin-only; prefer tightening the route.
- `POST /reports/*/snapshot` are `auth`-only with no role check (any role can write).
- `POST /logout` sits outside the `auth` group. `welcome.blade.php` references a
  nonexistent `register` route (latent 500 if rendered).

## Critical Models & Relationships

```
Warehouse
  ├── users (legacy warehouse_id)
  ├── user_warehouse (pivot)
  ├── items
  ├── delivery_subsidies
  └── requisitions

DeliverySubsidy
  ├── supplier
  ├── warehouse (legacy, often null)
  ├── creator
  ├── items (delivery_subsidy_items)
  ├── deliveries
  └── auditLogs

DeliverySubsidyItem
  ├── delivery_subsidy
  ├── warehouse (per-line destination)
  ├── item (resolved inventory record)
  ├── catalog_item
  └── deliveryItems

Delivery
  ├── delivery_subsidy
  ├── receiver
  └── items (delivery_items)

DeliveryItem
  ├── delivery
  ├── delivery_subsidy_item
  ├── item (exact stock record)
  ├── warehouse (where stock landed)
  └── dr_number (per-item DR#)

Item (stock record)
  ├── warehouse
  ├── stockCardEntries
  ├── deliverySubsidyItems
  ├── requisitionItems
  ├── requisitionDispatchItems
  ├── sourceSubsidy
  ├── reservations
  └── activeReservations

Requisition (RIS)
  ├── warehouse (legacy, often null)
  ├── creator
  ├── approver
  ├── items
  └── auditLogs

RequisitionItem
  ├── requisition
  ├── item (representative, often null)
  ├── catalogItem
  ├── warehouse (per-line, often null)
  └── dispatchItems

RequisitionDispatchItem
  ├── requisitionItem
  ├── item (exact stock record dispatched from)
  ├── creator
  └── dr_number (per-dispatch DR#)

StockCardEntry
  ├── item
  └── dispatchItem (linked issuance)

StockTransfer
  ├── fromWarehouse
  ├── toWarehouse
  ├── transferredBy
  ├── deliverySubsidy (optional)
  ├── items
  └── auditLogs

StockTransferItem
  ├── transfer
  ├── sourceItem
  └── destinationItem

Reservation
  ├── warehouse
  ├── item
  ├── intendedRequisition
  ├── creator
  └── approver

ItemCategory
  └── catalogItems

ItemCatalogItem
  └── category

Supplier
  └── deliverySubsidies
```

## Inventory Rules (CRITICAL — READ BEFORE CHANGING ANY QUANTITY)

### Stock Identity
A stock record (Item) is uniquely identified by ALL of these fields matching:
- `warehouse_id`
- `description`
- `unit`
- `category`
- `unit_cost` (±0.001 tolerance)
- `engas_unit_cost` (±0.001 tolerance, null-safe)
- `expiration_date` (date match, null-safe)
- `source_subsidy_id` (null-safe)

**Two records with different values in ANY of these are DIFFERENT stock records. Never merge across different subsidy IDs, unit costs, ENGAS costs, expiration dates, or warehouses.**

### Item::findOrCreateByUnitCost()
This is the ONLY correct way to resolve or create a stock record during delivery. It:
1. Searches for an existing active record matching all identity fields
2. If found, returns it (possibly updating account_code)
3. If not found, looks for an inactive placeholder (no stock_number) matching identity
4. If placeholder found, assigns stock_number and activates it
5. If nothing found, creates a new record with stock_number generated via advisory lock

### Stock Number Generation
Format: `{WAREHOUSE_CODE}-{CATEGORY_PREFIX}-{NNNN}` (e.g., `GAMC-FOO-0001`)
- Uses MySQL `GET_LOCK()` / `RELEASE_LOCK()` advisory lock
- Race-safe for concurrent deliveries

### Quantity Rules
- `items.quantity` — current physical stock level
- Available quantity = `items.quantity` - reserved quantity (from active reservations)
- Reserved quantity = sum of `reserved_quantity` across active reservations
- `delivery_subsidy_items.qty_delivered` — cumulative dispatched against request
- `requisition_items.quantity_requested` — requested quantity
- `requisition_items.quantity_issued` — cached sum of dispatch quantities
- `stock_transfer_items.quantity_requested` — planned transfer qty
- `stock_transfer_items.quantity` — actually dispatched qty

### Cost Rules
- `unit_cost` — current unit cost on the item record
- `engas_unit_cost` — ENGAS unit cost (can be null)
- Cost data on requisition items was dropped; now lives ONLY on `requisition_dispatch_items`
- When editing a delivery, cost changes cascade through `DeliverySubsidyCascadeService`

## Route Reference (Key Routes)

| URL | Method | Name | Controller | Auth |
|-----|--------|------|------------|------|
| /login | GET | login | AuthController | none |
| /login | POST | login.post | AuthController | none |
| /logout | POST | logout | AuthController | auth |
| / | GET | dashboard | DashboardController | auth |
| /items | GET | items.index | ItemController | auth |
| /items/{item} | GET | items.show | ItemController | auth |
| /delivery-subsidies | GET | delivery_subsidies.index | DeliverySubsidyController | auth |
| /delivery-subsidies/create | GET | delivery_subsidies.create | DeliverySubsidyController | admin.create |
| /delivery-subsidies | POST | delivery_subsidies.store | DeliverySubsidyController | admin.create |
| /delivery-subsidies/{ds} | GET | delivery_subsidies.show | DeliverySubsidyController | auth |
| /delivery-subsidies/{ds}/delivery | GET | delivery_subsidies.delivery | DeliverySubsidyController | auth |
| /delivery-subsidies/{ds}/delivery | POST | delivery_subsidies.store_delivery | DeliverySubsidyController | admin.create |
| /delivery-subsidies/{ds}/edit | GET | delivery_subsidies.edit | DeliverySubsidyController | admin.write |
| /delivery-subsidies/{ds}/edit-data | GET | delivery_subsidies.edit_data | DeliverySubsidyController | admin.write |
| /delivery-subsidies/{ds} | PUT | delivery_subsidies.update | DeliverySubsidyController | admin.write |
| /delivery-subsidies/{ds} | DELETE | delivery_subsidies.destroy | DeliverySubsidyController | admin.write |
| /delivery-subsidies/{ds}/deliveries/{d}/edit | GET | delivery_subsidies.edit_delivery | DeliverySubsidyController | admin |
| /delivery-subsidies/{ds}/deliveries/{d} | PUT | delivery_subsidies.update_delivery | DeliverySubsidyController | admin |
| /delivery-subsidies/{ds}/deliveries/{d} | DELETE | delivery_subsidies.destroy_delivery | DeliverySubsidyController | admin |
| /delivery-subsidies/{ds}/audit-log | GET | delivery_subsidies.audit_log | DeliverySubsidyController | admin |
| /requisitions | GET | requisitions.index | RequisitionController | auth |
| /requisitions/create | GET | requisitions.create | RequisitionController | admin.create |
| /requisitions | POST | requisitions.store | RequisitionController | admin.create |
| /requisitions/{r} | GET | requisitions.show | RequisitionController | auth |
| /requisitions/{r}/approve | GET | requisitions.approve | RequisitionController | auth |
| /requisitions/{r}/approve | POST | requisitions.process_approval | RequisitionController | auth |
| /requisitions/{r}/print | GET | requisitions.print | RequisitionController | auth |
| /requisitions/{r}/edit | GET | requisitions.edit | RequisitionController | admin.write |
| /requisitions/{r} | PUT | requisitions.update | RequisitionController | admin.write |
| /requisitions/{r}/correct | PUT | requisitions.correct | RequisitionController | admin.write |
| /requisitions/{r}/audit-log | GET | requisitions.audit_log | RequisitionController | admin.write |
| /requisitions/{r} | DELETE | requisitions.destroy | RequisitionController | admin.write |
| /requisitions/dispatch/{d}/edit-data | GET | requisitions.dispatch_edit_data | RequisitionController | admin.write |
| /requisitions/dispatch/{d} | PUT | requisitions.dispatch_update | RequisitionController | admin.write |
| /requisitions/dispatch/{d} | DELETE | requisitions.dispatch_destroy | RequisitionController | admin.write |
| /api/requisition-items | GET | requisitions.items_by_warehouse | RequisitionController | auth |
| /api/requisition-description-items | GET | requisitions.description_items | RequisitionController | auth |
| /transfers | GET | transfers.index | StockTransferController | auth |
| /transfers | POST | transfers.store | StockTransferController | admin.create |
| /transfers/{t} | GET | transfers.show | StockTransferController | auth |
| /transfers/{t}/print | GET | transfers.print | StockTransferController | auth |
| /transfers/{t}/dispatch | GET | transfers.dispatch | StockTransferController | auth |
| /transfers/{t}/dispatch | POST | transfers.process_dispatch | StockTransferController | admin.create |
| /transfers/{t}/edit | GET | transfers.edit | StockTransferController | admin |
| /transfers/{t} | PUT | transfers.update | StockTransferController | admin |
| /transfers/{t} | DELETE | transfers.destroy | StockTransferController | admin |
| /api/transfer-items | GET | transfers.items_for_warehouse | StockTransferController | auth |
| /reservations | GET | reservations.index | ReservationController | auth |
| /reservations/create | GET | reservations.create | ReservationController | admin.create |
| /reservations | POST | reservations.store | ReservationController | admin.create |
| /reservations/{r} | GET | reservations.show | ReservationController | auth |
| /reservations/{r}/approve | POST | reservations.approve | ReservationController | admin.write |
| /reservations/{r}/ready | POST | reservations.ready | ReservationController | admin.write |
| /reservations/{r}/cancel | POST | reservations.cancel | ReservationController | admin.write |
| /reservations/items-by-warehouse | GET | reservations.items_by_warehouse | ReservationController | auth |
| /stock-cards | GET | stock_cards.home | StockCardController | auth |
| /stock-cards/summary | GET | stock_cards.summary | StockCardController | auth |
| /stock-cards/{category} | GET | stock_cards.index | StockCardController | auth |
| /stock-cards/item/{item}/history | GET | stock_cards.item_history | StockCardController | auth |
| /stock-cards/item/{item}/history-by-cost | GET | stock_cards.item_history_by_unit_cost | StockCardController | auth |
| /stock-cards/item/{item}/print | GET | stock_cards.print | StockCardController | auth |
| /reports/rpci | GET | rpci_report | ReportController | auth |
| /reports/rpci/print | GET | rpci_report.print | ReportController | auth |
| /reports/rpci/export | GET | rpci_report.export | ReportController | auth |
| /reports/rsmi | GET | rsmi_report | ReportController | auth |
| /reports/rsmi/print | GET | rsmi_report.print | ReportController | auth |
| /reports/rsmi/export | GET | rsmi_report.export | ReportController | auth |
| /reports/inventory-balance | GET | inventory_balance_report | ReportController | auth |
| /reports/inventory-balance/export | GET | inventory_balance_report.export | ReportController | auth |
| /suppliers | GET | suppliers.index | SupplierController | auth |
| /warehouses | GET | warehouses.index | WarehouseController | auth |
| /users | GET | users.index | UserController | admin.only.strict |
| /item-categories | GET | item_categories.index | ItemCategoryController | admin.only.strict |
| /notifications | GET | notifications.index | NotificationController | auth |
| /api/check-username | GET | users.check_username | UserController | auth |
| /api/check-dr | GET | ds.check_number | Closure | auth |

> Full map (~106 routes) + deltas: see `WGIMS_AI_CONTEXT.md` §8. Notable gaps:
> `/api/transfer-items` and `/api/item-stock-card` perform no warehouse-access check;
> `/api/requisition-description-items` aggregates global stock; snapshot POSTs lack role checks.

## Edit/Delete/Reversal Rules

### Delivery Subsidy
- **No deliveries yet**: Full edit (RIS#, supplier, lines, quantities)
- **Deliveries exist**: Correction only — header fields (date, place, remarks) + per-line requested quantities. RIS#, supplier, DR# frozen. Lines with deliveries locked to their item.
- **Delete**: Reverses all delivery quantities, deletes stock cards, marks related transfers as "deleted subsidy", rebuilds balances. Items with qty=0 and no other references are hard-deleted.

### Single Delivery (Shipment)
- **Edit**: Adjusts quantities, costs, warehouse, DR# per line. Same-item delta applied directly. Cross-warehouse move reverses old receipt and adds to new item. Stock cards reconciled. `qty_delivered` on subsidy line updated.
- **Delete**: Reverses quantities, decrements `qty_delivered`, deletes stock cards, recalculates subsidy status.

### Requisition (RIS)
- **Edit**: Header fields + line items. Dispatched lines cannot reduce below issued qty or change catalog item.
- **Correct**: Same as edit but explicitly logged as "correction". Never touches dispatches.
- **Delete**: Reverses all dispatch quantities to exact stock records, deletes stock cards and dispatch items, recalculates balances.

### Dispatch (RequisitionDispatchItem)
- **Edit**: Reverse old deduction, apply new deduction. Can change warehouse, stock record, qty, cost, DR#. Stock cards moved/updated.
- **Delete**: Restores quantity to exact stock record, deletes stock card entries, recalculates balances, recomputes RIS fulfilment status.

### Stock Transfer
- **Edit**: Delta applied to source and destination quantities. Guards ensure source has stock and destination has units to return. Stock cards reconciled across all partial dispatches.
- **Delete**: Only allowed if destination stock not consumed by later transactions. Reverses quantities, deletes stock cards.

## Important Patterns

### Warehouse Scoping
All list/index queries use `ScopesWarehouse` trait:
- Admin/warehouse_manager → sees all warehouses (`getUserWarehouseIds` returns null)
- Others → limited to pivot + legacy `warehouse_id`
- Empty assignment → sees nothing (`whereRaw('1 = 0')`)

### Stock Card Balance Recalculation
`StockCardEntry::recalculateBalancesForItem($itemId)` recomputes all running balances for an item from scratch. Called after any operation that adds/removes/moves stock card entries.

### Advisory Locks
Used for generating unique numbers:
- `Item::generateStockNumber()` — `GET_LOCK('stock_number_{PREFIX}')`
- `Requisition::generateRisNumber()` — `GET_LOCK('ris_number_{YEAR}{MONTH}')`
- `StockTransfer::generateTransferNumber()` — `GET_LOCK('trf_number_{YEAR}')`

### Database Transactions
All multi-step inventory operations wrapped in `DB::transaction()`.

### Row Locking
`lockForUpdate()` used on exact stock records before read-modify-write to prevent race conditions.

## UI Conventions

- **CSS**: Custom properties (no Bootstrap utility classes except for pagination)
- **Tables**: Compact, 11px font, hover rows, sticky headers in modals
- **Forms**: `.form-control`, `.form-row` grid, `.form-section-label`
- **Buttons**: `.btn`, `.btn-sm`, color variants (primary, success, warning, danger, secondary, outline)
- **Badges**: `.badge`, `.badge-success`, `.badge-warning`, `.badge-danger`, `.badge-info`, `.badge-secondary`
- **Modals**: Full-screen overlay with `.modal-overlay`, `.modal-shell`, `.modal-body` scrollable
- **Searchable Selects**: Custom `SearchableSelect` JS component enhances all `<select>` elements
- **Notifications**: Bell icon with dropdown, AJAX-loaded, 30s polling
- **Alerts**: `.alert`, `.alert-success`, `.alert-danger`, `.alert-warning`, `.alert-info`
- **Print**: `@media print` hides sidebar/topbar

## What an AI Agent MUST NOT Do

1. **Never run destructive DB commands**: `migrate:fresh`, `db:wipe`, `truncate`, `drop table` on production
2. **Never delete production data casually**
3. **Never modify existing migrations** that have been used in production
4. **Never merge stock records** with different subsidy IDs, unit costs, ENGAS costs, expiration dates, or warehouses
5. **Never change quantities without understanding the downstream impact** on stock cards, reservations, dispatches, and transfers
6. **Never assume a "small change" only affects one page** — inventory changes affect multiple modules
7. **Never bypass server-side authorization** — UI hiding is not security
8. **Never expose secrets or keys** in logs or responses
9. **Never use `no-store` on authenticated GETs** — it breaks bfcache and causes skeleton flash (use `private, no-cache, must-revalidate`)
10. **Never increment/decrement quantities directly without `lockForUpdate()`** on the exact stock record
11. **Never forget to call `StockCardEntry::recalculateBalancesForItem()`** after modifying stock card entries
12. **Never change `source_subsidy_id` without updating the snapshot columns** on the item
13. **Never write to dropped columns** (`requisition_items.unit_cost/engas/dr/expiry`) or call stale
    `DeliverySubsidyCascadeService::cascadeUnitCost()` — use `cascadeItemCost()`
14. **Never resolve merge conflicts in inventory views by blindly picking ours/theirs** — merge semantically
15. **Never change Inventory Balance logic** unless explicitly instructed (Dashboard ≠ Balance)

## Before Making Any Change

1. Read `WGIMS_AI_CONTEXT.md` first, then `AGENTS.md` rules and relevant `docs/`
2. Inspect the actual code involved
3. Understand the impact on related modules
4. Make the smallest safe change
5. Test the change
6. Report exactly what was changed
