# WGIMS — AI Context Document

## 1. SYSTEM OVERVIEW

WGIMS (Welfare Goods Inventory Management System) is a Laravel 12 application for managing warehouse inventory, delivery subsidies, requisitions/issuance slips (RIS), reservations, stock transfers, stock cards, and related reports. It supports multi-warehouse operations with strict stock-identity rules, cost tracking (standard + ENGAS), and role-based access control.

**Authoritative source files:**
- `AGENTS.md` — existing AI instructions (must be read before changes)
- `app/Http/Controllers/` — all business logic
- `app/Models/` — Eloquent models and relationships
- `routes/web.php` — route map
- `database/migrations/` — schema source of truth
- `resources/views/` — Blade UI

## 2. TECHNOLOGY STACK

| Layer | Technology |
|-------|------------|
| Language | PHP 8.2+ |
| Framework | Laravel 12.0 |
| Database | SQLite (primary/dev), MySQL (production) |
| Frontend | Blade templates, vanilla JavaScript (no framework) |
| CSS | Custom properties + Tailwind CSS v4 via Vite |
| Build | Vite 7 + Laravel Vite plugin |
| Auth | Session-based (single web guard), login via `username` |
| Authorization | Custom middleware (`admin`, `admin.write`, `admin.create`, `admin.only.strict`) + `ScopesWarehouse` trait |
| Excel | PhpSpreadsheet (PhpOffice) |
| Packages | spatie/laravel-permission (installed but roles unused — roles stored in `users.role`), laravel/tinker, laravel/pint, laravel/pail |
| Fonts | Inter (Google Fonts) |
| Icons | Font Awesome 6.5 |

## 3. LARAVEL VERSION

Laravel 12.0 (from `composer.json`). PHP 8.2+ required.

## 4. MODULES

| Module | Purpose |
|--------|---------|
| **Dashboard** | Admin and warehouse dashboards with inventory summaries, subsidy stats, reservation stats, recent activity |
| **Items** | Inventory stock listing, search/filter, item detail view |
| **Delivery Subsidies** | Create delivery requests, record shipments/receipts, edit/delete subsidies and individual deliveries |
| **Requisitions / RIS** | Create requisitions, approve/issue stock, edit/correct, delete, print RIS forms |
| **Stock Transfers** | Plan and execute inter-warehouse transfers, edit/delete transfers |
| **Reservations** | Reserve stock for future RIS, approve/ready/cancel reservations, deploy reserved items |
| **Stock Cards** | Per-item transaction history, running balances, print stock cards |
| **Reports** | RPCI (Report of Procurement and Inventory), RSMI (Report of Supplies and Materials Issued), Inventory Balance |
| **Item Categories** | Manage categories and item names (catalog items) used in dropdowns |
| **Warehouses** | Manage warehouses |
| **Users** | Manage users and roles |
| **Suppliers** | Manage suppliers for delivery subsidies |
| **Notifications** | System notifications (bell icon, AJAX polling) |

## 5. DATABASE STRUCTURE

### 5.1 Tables

| Table | Purpose |
|-------|---------|
| `users` | Authentication, role (`admin`, `warehouse_manager`, `supply_custodian`, `center_staff`, `center_head`), legacy `warehouse_id` |
| `warehouses` | Warehouse master data (`code`, `name`, `place`) |
| `user_warehouse` | Pivot table for multi-warehouse user assignments |
| `suppliers` | Supplier master data |
| `item_categories` | Category key/label/account_code for grouping |
| `item_catalog_items` | Pre-defined item names/descriptions under each category |
| `items` | **Inventory stock records** — the central table |
| `delivery_subsidies` | Delivery/subsidy headers |
| `delivery_subsidy_items` | Delivery/subsidy line items (requested quantities) |
| `deliveries` | Shipment/DR headers |
| `delivery_items` | Shipment/DR line items (actual received stock) |
| `requisitions` | RIS headers |
| `requisition_items` | RIS request lines |
| `requisition_dispatch_items` | RIS dispatch/issue lines (actual stock issued) |
| `stock_transfers` | Transfer headers |
| `stock_transfer_items` | Transfer line items |
| `reservations` | Reservation headers |
| `reservation_items` | Reservation lines (multi-item reservations) |
| `stock_card_entries` | Stock card ledger entries |
| `system_notifications` | User notifications |
| `report_snapshots` | Saved report snapshots |
| `delivery_subsidy_audit_logs` | Audit trail for subsidies |
| `requisition_audit_logs` | Audit trail for RIS |
| `stock_transfer_audit_logs` | Audit trail for transfers |

### 5.2 Key Relationships

- **Warehouse**: has many items, delivery subsidies, requisitions, transfers; belongsToMany users via `user_warehouse`
- **Item**: belongsTo warehouse; has many stock card entries, delivery subsidy items, requisition items, dispatch items, reservations
- **DeliverySubsidy**: belongsTo supplier, warehouse, creator; has many items, deliveries, audit logs
- **DeliverySubsidyItem**: belongsTo subsidy, warehouse, item, catalog item; has many delivery items
- **Delivery**: belongsTo subsidy, receiver; has many items
- **DeliveryItem**: belongsTo delivery, subsidy item, item, warehouse
- **Requisition**: belongsTo warehouse, creator, approver; has many items, audit logs
- **RequisitionItem**: belongsTo requisition, item, catalog item, warehouse; has many dispatch items
- **RequisitionDispatchItem**: belongsTo requisition item, item, creator; has stock card entry linkage
- **StockTransfer**: belongsTo from/to warehouses, transferredBy; has many items, audit logs; optional subsidy linkage
- **StockTransferItem**: belongsTo transfer, source item, destination item
- **Reservation**: belongsTo warehouse, item, intendedRequisition, creator, approver; has many items
- **ReservationItem**: belongsTo reservation, item; tracks `reserved_quantity`, `deployed_quantity`
- **StockCardEntry**: belongsTo item, optional dispatch item
- **ItemCategory**: has many catalog items
- **ItemCatalogItem**: belongsTo category; has many delivery subsidy items

### 5.3 Important Indexes

- `items.stock_number` — unique
- `items.warehouse_id + is_active + category` — composite
- `items.warehouse_id + unit + category + unit_cost` — composite for stock identity lookups
- `items.source_subsidy_id + warehouse_id` — composite
- `requisitions.ris_number` — unique
- `requisitions.ris_code` — unique
- `item_catalog_items.item_category_id + name` — unique

## 6. IMPORTANT MODELS

### Item (Stock Record)
- **Stock identity fields**: `warehouse_id`, `description`, `unit`, `category`, `unit_cost` (±0.001), `engas_unit_cost` (±0.001, null-safe), `expiration_date` (null-safe), `source_subsidy_id` (null-safe)
- **Quantities**: `quantity` (physical), `reserved_quantity` (computed from active reservations), `available_quantity` (computed: max(0, quantity - reserved))
- **Cost**: `unit_cost`, `engas_unit_cost`
- **Snapshot fields**: `source_subsidy_code`, `source_subsidy_ris`, `source_subsidy_dr`, `source_subsidy_status`
- **Auto-management**: `is_active` auto-set false when `quantity <= 0`, re-activated when `quantity > 0`
- **Static method**: `findOrCreateByUnitCost()` — authoritative way to resolve/create stock during delivery
- **Static method**: `generateStockNumber()` — race-safe via MySQL advisory lock

### Requisition (RIS)
- **Statuses**: `pending`, `approved`, `partially_approved`, `cancelled`
- **Dates**: `date_requested` (date), `date_approved` (date, nullable)
- **Computed**: `totalRequested()`, `totalIssued()`, `totalRemaining()`, `updateFulfilmentStatus()`
- **Warehouse scoping**: header `warehouse_id` often null; visibility based on line-item warehouses and dispatch warehouses

### RequisitionDispatchItem
- **The actual issuance record** — stores `unit_cost` and `engas_unit_cost` at dispatch time
- Stock-specific fields were moved FROM `requisition_items` TO here in migration `2026_08_24_210730`
- Links to exact `item_id` (stock record), `reservation_item_id` (optional)

### DeliverySubsidy
- **Statuses**: `pending`, `partial`, `fully_delivered`, `cancelled`
- **Fields**: `subsidy_code` (unique, auto-generated), `dr_number` (unique), `supplier_id`, `warehouse_id` (legacy, often null), `quantity_requested`, `total_amount`, `status`
- **Cost cascade**: `DeliverySubsidyCascadeService` propagates cost changes

### Reservation
- **Statuses**: `pending`, `reserved`, `ready_for_requisition`, `partially_deployed`, `deployed`, `cancelled`, `expired`
- **Multi-item**: supports multiple items per reservation via `ReservationItem`
- **Soft lock**: does NOT change `item.quantity`; reduces available quantity
- **Deployment**: when reservation items are used in RIS dispatch, `deployed_quantity` increments

### ItemCategory / ItemCatalogItem
- **ItemCategory**: key/label/account_code grouping
- **ItemCatalogItem**: item name/description under a category, used in dropdowns
- **Account code inheritance**: catalog items inherit account code from parent category

## 7. IMPORTANT CONTROLLERS

### DeliverySubsidyController (1793 lines)
- Largest controller — full subsidy lifecycle
- `storeDelivery()` — core inbound flow, creates stock via `Item::findOrCreateByUnitCost()`
- `updateDelivery()` — delta correction with cross-warehouse support
- `destroyDelivery()` — reverses quantities, deletes stock cards
- `destroy()` — full subsidy deletion with lineage tracing (`transferLineageItemIds()`)
- `update()` — merged edit/correction mode depending on whether deliveries exist

### RequisitionController (1690 lines)
- `processApproval()` — core outbound flow, decreases stock with `lockForUpdate()`
- `updateDispatch()` — edit single dispatch with cross-record support
- `destroyDispatch()` — reverses quantities, deletes stock cards
- `destroy()` — full RIS deletion with reservation reversal

### StockTransferController (1045 lines)
- `processDispatch()` — moves stock between warehouses
- `update()` — sophisticated delta correction with stock card reconciliation
- `destroy()` — reverse transfer with dependency checking (`destroyBlockers()`)

### ReservationController (525 lines)
- `store()` — creates reservations with available-quantity checks
- `approve()` / `markReady()` / `cancel()` — state transitions
- API endpoints for RIS dispatch integration

### ReportController
- RPCI, RSMI, Inventory Balance reports
- Export via PhpSpreadsheet
- Snapshot save/view

### ItemCategoryController / ItemCatalogItemController
- Category CRUD with auto-key generation
- Catalog item CRUD with uniqueness within category
- AJAX + redirect response handling

## 8. ROUTE STRUCTURE

### Web Routes (authenticated)
| URL | Method | Name | Controller | Auth |
|-----|--------|------|------------|------|
| `/` | GET | dashboard | DashboardController | auth |
| `/items` | GET | items.index | ItemController | auth |
| `/delivery-subsidies` | GET | delivery_subsidies.index | DeliverySubsidyController | auth |
| `/delivery-subsidies/create` | GET | delivery_subsidies.create | DeliverySubsidyController | admin.create |
| `/delivery-subsidies` | POST | delivery_subsidies.store | DeliverySubsidyController | admin.create |
| `/delivery-subsidies/{ds}/delivery` | GET | delivery_subsidies.delivery | DeliverySubsidyController | auth |
| `/delivery-subsidies/{ds}/delivery` | POST | delivery_subsidies.store_delivery | DeliverySubsidyController | admin.create |
| `/delivery-subsidies/{ds}/edit` | GET | delivery_subsidies.edit | DeliverySubsidyController | admin.write |
| `/delivery-subsidies/{ds}` | PUT | delivery_subsidies.update | DeliverySubsidyController | admin.write |
| `/delivery-subsidies/{ds}` | DELETE | delivery_subsidies.destroy | DeliverySubsidyController | admin.write |
| `/delivery-subsidies/{ds}/deliveries/{d}/edit` | GET | delivery_subsidies.edit_delivery | DeliverySubsidyController | admin |
| `/delivery-subsidies/{ds}/deliveries/{d}` | PUT | delivery_subsidies.update_delivery | DeliverySubsidyController | admin |
| `/delivery-subsidies/{ds}/deliveries/{d}` | DELETE | delivery_subsidies.destroy_delivery | DeliverySubsidyController | admin |
| `/requisitions` | GET | requisitions.index | RequisitionController | auth |
| `/requisitions/create` | GET | requisitions.create | RequisitionController | admin.create |
| `/requisitions` | POST | requisitions.store | RequisitionController | admin.create |
| `/requisitions/{r}` | GET | requisitions.show | RequisitionController | auth |
| `/requisitions/{r}/approve` | GET | requisitions.approve | RequisitionController | auth |
| `/requisitions/{r}/approve` | POST | requisitions.process_approval | RequisitionController | auth |
| `/requisitions/{r}/print` | GET | requisitions.print | RequisitionController | auth |
| `/requisitions/{r}/edit` | GET | requisitions.edit | RequisitionController | admin.write |
| `/requisitions/{r}` | PUT | requisitions.update | RequisitionController | admin.write |
| `/requisitions/{r}/correct` | PUT | requisitions.correct | RequisitionController | admin.write |
| `/requisitions/{r}/audit-log` | GET | requisitions.audit_log | RequisitionController | admin.write |
| `/requisitions/{r}` | DELETE | requisitions.destroy | RequisitionController | admin.write |
| `/requisitions/dispatch/{d}/edit-data` | GET | requisitions.dispatch_edit_data | RequisitionController | admin.write |
| `/requisitions/dispatch/{d}` | PUT | requisitions.dispatch_update | RequisitionController | admin.write |
| `/requisitions/dispatch/{d}` | DELETE | requisitions.dispatch_destroy | RequisitionController | admin.write |
| `/api/requisition-items` | GET | requisitions.items_by_warehouse | RequisitionController | auth |
| `/api/requisition-description-items` | GET | requisitions.description_items | RequisitionController | auth |
| `/transfers` | GET | transfers.index | StockTransferController | auth |
| `/transfers` | POST | transfers.store | StockTransferController | admin.create |
| `/transfers/{t}` | GET | transfers.show | StockTransferController | auth |
| `/transfers/{t}/print` | GET | transfers.print | StockTransferController | auth |
| `/transfers/{t}/dispatch` | GET | transfers.dispatch | StockTransferController | auth |
| `/transfers/{t}/dispatch` | POST | transfers.process_dispatch | StockTransferController | admin.create |
| `/transfers/{t}/edit` | GET | transfers.edit | StockTransferController | admin |
| `/transfers/{t}` | PUT | transfers.update | StockTransferController | admin |
| `/transfers/{t}` | DELETE | transfers.destroy | StockTransferController | admin |
| `/api/transfer-items` | GET | transfers.items_for_warehouse | StockTransferController | auth |
| `/reservations` | GET | reservations.index | ReservationController | auth |
| `/reservations/create` | GET | reservations.create | ReservationController | admin.create |
| `/reservations` | POST | reservations.store | ReservationController | admin.create |
| `/reservations/{r}` | GET | reservations.show | ReservationController | auth |
| `/reservations/{r}/approve` | POST | reservations.approve | ReservationController | admin.write |
| `/reservations/{r}/ready` | POST | reservations.ready | ReservationController | admin.write |
| `/reservations/{r}/cancel` | POST | reservations.cancel | ReservationController | admin.write |
| `/reservations/items-by-warehouse` | GET | reservations.items_by_warehouse | ReservationController | auth |
| `/stock-cards` | GET | stock_cards.home | StockCardController | auth |
| `/stock-cards/summary` | GET | stock_cards.summary | StockCardController | auth |
| `/stock-cards/{category}` | GET | stock_cards.index | StockCardController | auth |
| `/stock-cards/item/{item}/history` | GET | stock_cards.item_history | StockCardController | auth |
| `/stock-cards/item/{item}/history-by-cost` | GET | stock_cards.item_history_by_unit_cost | StockCardController | auth |
| `/stock-cards/item/{item}/print` | GET | stock_cards.print | StockCardController | auth |
| `/reports/rpci` | GET | rpci_report | ReportController | auth |
| `/reports/rpci/print` | GET | rpci_report.print | ReportController | auth |
| `/reports/rpci/export` | GET | rpci_report.export | ReportController | auth |
| `/reports/rsmi` | GET | rsmi_report | ReportController | auth |
| `/reports/rsmi/print` | GET | rsmi_report.print | ReportController | auth |
| `/reports/rsmi/export` | GET | rsmi_report.export | ReportController | auth |
| `/reports/inventory-balance` | GET | inventory_balance_report | ReportController | auth |
| `/reports/inventory-balance/export` | GET | inventory_balance_report.export | ReportController | auth |
| `/suppliers` | GET | suppliers.index | SupplierController | auth |
| `/warehouses` | GET | warehouses.index | WarehouseController | auth |
| `/users` | GET | users.index | UserController | admin.only.strict |
| `/item-categories` | GET | item_categories.index | ItemCategoryController | admin.only.strict |
| `/notifications` | GET | notifications.index | NotificationController | auth |
| `/api/check-username` | GET | users.check_username | UserController | auth |
| `/api/check-dr` | GET | ds.check_number | Closure | auth |

### Middleware Stack
- `auth` — all protected routes
- `admin` — admin + warehouse_manager
- `admin.write` — admin only (mutating actions)
- `admin.create` — admin + warehouse_manager (create actions)
- `admin.only.strict` — admin only (no warehouse_manager)
- `throttle:120,1` — API helper routes

## 9. INVENTORY FLOW

### 9.1 Core Principles

1. **Stock identity is sacrosanct**: Two items are the SAME stock record only if ALL of these match:
   - `warehouse_id`
   - `description`
   - `unit`
   - `category`
   - `unit_cost` (±0.001)
   - `engas_unit_cost` (±0.001, null-safe)
   - `expiration_date` (null-safe)
   - `source_subsidy_id` (null-safe)

2. **Never merge stock records** with different subsidy IDs, unit costs, ENGAS costs, expiration dates, or warehouses.

3. **Use `Item::findOrCreateByUnitCost()`** when resolving/creating stock during delivery.

4. **Use `lockForUpdate()`** on exact stock records before read-modify-write.

5. **Call `StockCardEntry::recalculateBalancesForItem()`** after modifying stock card entries.

### 9.2 Quantity Definitions

| Quantity | Meaning |
|----------|---------|
| `items.quantity` | Current physical stock level |
| `items.reserved_quantity` | Sum of active `reserved_quantity` across reservations |
| `items.available_quantity` | `max(0, quantity - reserved_quantity)` |
| `delivery_subsidy_items.qty_delivered` | Cumulative dispatched against request |
| `requisition_items.quantity_requested` | Requested quantity |
| `requisition_items.quantity_issued` | Cached sum of dispatch quantities |
| `stock_transfer_items.quantity_requested` | Planned transfer qty |
| `stock_transfer_items.quantity` | Actually dispatched qty |

### 9.3 Cost Definitions

| Cost | Meaning |
|------|---------|
| `unit_cost` | Current standard unit cost on the item record |
| `engas_unit_cost` | ENGAS unit cost (can be null) |
| `requisition_dispatch_items.unit_cost` | Standard cost at dispatch time |
| `requisition_dispatch_items.engas_unit_cost` | ENGAS cost at dispatch time |

### 9.4 Flow Diagram

```
DELIVERY SUBSIDY (Purchase/Receipt)
    ↓
Stock IN via DeliveryItem → Item::findOrCreateByUnitCost()
    ↓
Stock Card Entry (receipt) created
    ↓
Item quantity increases
    ↓
RESERVATION (soft lock)
    ↓
Available quantity decreases (physical unchanged)
    ↓
RIS / REQUISITION (request)
    ↓
APPROVAL / DISPATCH
    ↓
Stock OUT via RequisitionDispatchItem
    ↓
Item quantity decreases
    ↓
Stock Card Entry (issue) created
    ↓
Reservation deployed_quantity increases
    ↓
STOCK TRANSFER (inter-warehouse)
    ↓
Source quantity decreases, Destination quantity increases
    ↓
Stock Card Entries (transfer_out / transfer_in) created
    ↓
INVENTORY BALANCE / REPORTS
```

## 10. SUBSIDY FLOW

1. **Create Subsidy** (`store`): Header + line items. Warehouse NOT assigned yet. Unit cost NOT captured yet.
2. **Record Delivery** (`storeDelivery`): Per-line warehouse, unit cost, ENGAS cost, DR#, expiration. Stock IN via `Item::findOrCreateByUnitCost()`. Stock cards created. `qty_delivered` incremented. Subsidy status updated.
3. **Edit Delivery** (`updateDelivery`): Delta correction. Same warehouse: quantity adjusted. Cross-warehouse: reverse old receipt, add to new item. Cost cascade via `DeliverySubsidyCascadeService`.
4. **Delete Delivery** (`destroyDelivery`): Reverse quantities, decrement `qty_delivered`, delete stock cards, recalculate status.
5. **Edit Subsidy** (`update`): 
   - No deliveries: full edit
   - Deliveries exist: correction only (RIS#, supplier, DR# frozen; lines with deliveries locked)
6. **Delete Subsidy** (`destroy`): Marks related transfers, reverses deliveries, traces lineage, deletes stock cards, hard-deletes orphaned items (qty=0, no references), rebuilds balances.

## 11. RIS FLOW

1. **Create RIS** (`store`): Header + description-level line items. No stock record linked yet. Auto-generates `ris_code` if blank.
2. **Approve/Dispatch** (`processApproval`): Per-line exact stock record selected. `lockForUpdate()` on item. Available quantity computed (physical - reserved). Rejects if insufficient. Creates `RequisitionDispatchItem`. Decreases `item.quantity`. Creates stock card entry. Updates `quantity_issued` cache. Recomputes fulfilment status.
3. **Edit Dispatch** (`updateDispatch`): Reverse old deduction, apply new deduction. Can change warehouse, stock record, qty, cost, DR#. Stock cards reconciled. Reservation `deployed_quantity` adjusted.
4. **Delete Dispatch** (`destroyDispatch`): Restores quantity to exact stock record. Deletes stock card entry. Reverses reservation deployment. Recomputes fulfilment status.
5. **Edit RIS** (`update`): Pre-dispatch correction. Dispatched lines cannot reduce below issued qty or change catalog item.
6. **Correct RIS** (`correct`): Same as edit but explicitly logged as "correction". Never touches dispatches.
7. **Delete RIS** (`destroy`): Reverses all dispatch quantities, deletes stock cards and dispatch items, recalculates balances. Reverses `deployed_quantity` on linked reservation items.

## 12. RESERVATION FLOW

1. **Create Reservation** (`store`): Header + multi-item lines. Locks exact stock records. Checks `available_quantity` (physical - reserved). Creates `ReservationItem` with `reserved_quantity`, `deployed_quantity = 0`, status `ACTIVE`. Does NOT change `item.quantity`.
2. **Approve** (`approve`): `PENDING` → `RESERVED`
3. **Mark Ready** (`markReady`): `RESERVED` → `READY_FOR_REQUISITION`
4. **Cancel** (`cancel`): Releases reserved quantities. Status → `CANCELLED`.
5. **Deploy via RIS**: When a dispatch uses a `reservation_item_id`, the reservation's `deployed_quantity` increments. Status recomputed: `ACTIVE` → `PARTIALLY_DEPLOYED` → `DEPLOYED`.
6. **Delete Reservation** (`destroy`): Admin-only. Refused if any item has `deployed_quantity > 0`.

**Key rule**: `available_quantity = physical_quantity - reserved_quantity`. Reserved stock is NOT available for other dispatches.

## 13. STOCK TRANSFER FLOW

1. **Plan Transfer** (`store`): Source warehouse, destination warehouse, line items with `quantity_requested`. Creates destination item slot via `Item::findOrCreateByUnitCost()`. Transfer starts as `pending`.
2. **Dispatch** (`processDispatch`): For each line: `source.quantity -= dispatchQty`, `dest.quantity += dispatchQty`. Creates two stock card entries (`transfer_out` at source, `transfer_in` at destination). Status → `partial` → `completed`.
3. **Edit Transfer** (`update`): Delta correction with integrity guards:
   - Increasing: source must have extra units
   - Decreasing: destination must still hold the units
   - Stock card entries spread across delta (`spreadDeltaAcrossEntries`)
4. **Delete Transfer** (`destroy`): Checks for downstream usage (`destroyBlockers`). If safe: reverses quantities, deletes stock cards, recalculates balances.

## 14. STOCK CARD FLOW

- **Receipt**: Created during `storeDelivery` (subsidy) with `receipt_qty`, `receipt_unit_cost`
- **Issue**: Created during `processApproval` (RIS dispatch) with `issue_qty`, linked to `dispatch_item_id`
- **Transfer out**: Created during `processDispatch` (transfer) at source warehouse
- **Transfer in**: Created during `processDispatch` (transfer) at destination warehouse
- **Running balance**: `StockCardEntry::recalculateBalancesForItem($itemId)` recomputes all running balances from scratch after any modification
- **Balance recalculation** is called after every operation that adds/removes/moves stock card entries

## 15. INVENTORY BALANCE FLOW

- Dashboard and Inventory Balance report aggregate items by warehouse + category
- Uses `quantity * unit_cost` for total value
- Respects warehouse scoping via `ScopesWarehouse`
- Does NOT subtract reservations (it shows physical stock, not available)
- Separate from the Dashboard reserved-items summary

## 16. WAREHOUSE LOGIC

- Multi-warehouse system
- Every stock record (`items`) belongs to exactly one `warehouse_id`
- `warehouse_id` on `requisitions` and `delivery_subsidies` headers is legacy and often null
- Actual warehouse scoping happens at the line-item/dispatch level
- User access: admin/warehouse_manager see all; others see only assigned warehouses via pivot + legacy column
- Stock transfers explicitly move stock between warehouses
- Same description at different warehouses = separate stock records (different `warehouse_id`)

## 17. USER ROLES AND PERMISSIONS

| Role | Create | View | Edit/Write | Delete | Approve | Dispatch | Notes |
|------|--------|------|------------|--------|---------|----------|-------|
| `admin` | Yes | All | Yes | Yes | Yes | Yes | Full access |
| `warehouse_manager` | Yes | All | No | No | No | No | Can create but not edit/delete |
| `supply_custodian` | No | Yes | No | No | Yes | Yes | Can approve and dispatch |
| `center_staff` | No | Yes | No | No | No | No | Read-only |
| `center_head` | No | Yes | No | No | Yes | No | Can approve |

**Authorization implementation:**
- Route middleware: `admin`, `admin.write`, `admin.create`, `admin.only.strict`
- In-method checks: `hasAdminAccess()`, `canWrite()`, `canCreate()`, `canApprove()`, `isAdmin()`, `isCenterUser()`
- Data scoping: `ScopesWarehouse` trait
- **Spatie Laravel Permission is installed but NOT used for roles** — roles are in `users.role` column

## 18. IMPORTANT BUSINESS RULES

1. **Never merge stock records** with different subsidy IDs, unit costs, ENGAS costs, expiration dates, or warehouses.
2. **Never change quantities without understanding downstream impact** on stock cards, reservations, dispatches, transfers.
3. **Always use `lockForUpdate()`** on exact stock records before read-modify-write.
4. **Always call `StockCardEntry::recalculateBalancesForItem()`** after modifying stock card entries.
5. **Never expose secrets or keys** in logs or responses.
6. **UI hiding is not security** — always enforce authorization server-side.
7. **Available quantity = physical - reserved**. Reserved stock cannot be dispatched for non-reservation purposes.
8. **Cost data on requisition items was dropped**; now lives ONLY on `requisition_dispatch_items`.
9. **Editing a delivery** cascades cost changes through `DeliverySubsidyCascadeService`.
10. **Subsidy deletion** traces multi-hop transfer lineage via BFS.
11. **Stock transfer deletion** checks for downstream usage before allowing.
12. **Item names must be unique within a category**.
13. **Cannot delete item name** if referenced by `DeliverySubsidyItem` — deactivate instead.
14. **Cannot delete category** if it has items.
15. **Advisory locks** used for generating unique numbers: stock numbers, RIS numbers, transfer numbers.
16. **All multi-step inventory operations** wrapped in `DB::transaction()`.
17. **`is_active` auto-managed** on items: false when `quantity <= 0`, re-activated when `quantity > 0`.
18. **`items.quantity` cast to integer** in model, but DB stores 4 decimal places.

## 19. POTENTIAL BUGS / ISSUES IDENTIFIED

1. **`items.quantity` cast to integer** — DB column is `decimal(15,4)` but model casts to `int`. This truncates fractional quantities. If partial quantities are ever needed, this will cause data loss.
2. **Spatie permissions installed but unused** — dead code/package weight. `permissions` tables created then dropped.
3. **Complex inline `@json()` in Blade** — previously caused 500 errors in item categories view. Should use `@php` blocks for complex data.
4. **`date_requested` vs `created_at` confusion** — Days elapsed calculation initially used wrong field. Verified fix now uses `date_requested`.
5. **`ReportController::printRsmi`** previously computed ENGAS subtotal but passed only `$risGroups` to view; view tried to use bare `$engasSubtotal` variable. Fixed to use `$group['engas_subtotal']`.
6. **Warehouse scoping complexity** — Multi-warehouse model moved warehouse from header to line items. Some controllers have complex `orWhereHas` chains to compensate. This is intentional but fragile.

## 20. PERFORMANCE ISSUES

1. **N+1 queries in list views** — Controllers use eager loading (`with`, `withCount`) extensively, but some views still access nested relationships that may not be loaded.
2. **Large report queries** — Reports load all requisitions/subsidies into memory for grouping. Pagination exists but grouping happens before pagination.
3. **Stock card balance recalculation** — `recalculateBalancesForItem()` rebuilds all balances from scratch. Called frequently; could be heavy on high-volume items.

## 21. SECURITY ISSUES

1. **Authorization is custom and fragile** — Route middleware + in-method checks. Easy to miss a check when adding new methods.
2. **No API tokens** — All API endpoints are session-authenticated. No separate API auth.
3. **Username enumeration** — Login endpoint reveals whether username exists via rate-limiter key.
4. **CSRF protection** — Properly implemented on all POST/PUT/DELETE routes.

## 22. FILES THAT ARE MOST IMPORTANT TO UNDERSTAND

| File | Why Important |
|------|---------------|
| `app/Models/Item.php` | Central inventory model, stock identity, `findOrCreateByUnitCost()`, `generateStockNumber()` |
| `app/Models/Requisition.php` | RIS model, fulfilment status, warehouse names aggregation |
| `app/Models/RequisitionDispatchItem.php` | Where `unit_cost` and `engas_unit_cost` live for issued stock |
| `app/Models/DeliverySubsidy.php` | Subsidy lifecycle, status management |
| `app/Models/DeliverySubsidyItem.php` | Line items, `qty_delivered` tracking |
| `app/Models/Reservation.php` | Reservation lifecycle, status management |
| `app/Models/ReservationItem.php` | Multi-item reservations, `reserved_quantity`, `deployed_quantity` |
| `app/Http/Controllers/DeliverySubsidyController.php` | Largest and most complex controller — subsidy CRUD, delivery, lineage tracing |
| `app/Http/Controllers/RequisitionController.php` | RIS lifecycle, dispatch logic, stock deduction |
| `app/Http/Controllers/StockTransferController.php` | Transfer planning, dispatch, delta correction |
| `app/Http/Controllers/ReportController.php` | All reports, RPCI/RSMI generation, PhpSpreadsheet export |
| `app/Http/Controllers/ItemCategoryController.php` | Category management |
| `app/Http/Controllers/ItemCatalogItemController.php` | Item name management |
| `app/Http/Middleware/ScopesWarehouse.php` | Warehouse data scoping — affects EVERY list query |
| `routes/web.php` | Complete route map |
| `resources/views/layouts/app.blade.php` | Master layout, CSS variables, modal styles |
| `resources/views/item_categories/index.blade.php` | Category management UI (modals) |
| `database/migrations/2026_05_05_100005_create_requisitions_table.php` | RIS schema |
| `database/migrations/2026_05_05_100006_create_delivery_subsidies_table.php` | Subsidy schema |
| `database/migrations/2026_06_14_000001_add_indexes_to_items_table.php` | Stock identity indexes |
| `AGENTS.md` | Existing AI instructions |

## 23. RECOMMENDATIONS

1. **Fix `items.quantity` cast** — Change model cast from `integer` to `float` or remove it to preserve DB precision.
2. **Remove unused Spatie package** — Since roles are not used via Spatie, consider removing `spatie/laravel-permission` to reduce attack surface and complexity.
3. **Standardize JSON data passing in Blade** — Avoid complex inline `@json()` expressions; use `@php` blocks for complex data structures.
4. **Add integration tests** for critical flows: subsidy delivery, RIS dispatch, transfer, reservation deployment.
5. **Document the `ScopesWarehouse` trait** behavior in AGENTS.md more explicitly — it affects every list query.
6. **Consider renaming `item_categories.key`** to `slug` for clarity — it's a URL-safe identifier, not a database key.

## 24. DOCUMENTATION FILES CREATED

- `WGIMS_AI_CONTEXT.md` — this file
- `AGENTS.md` — already existed, contains AI instructions

---

**Last updated:** 2026-09-08  
**Analysis based on:** Commit `b6f6e02` (ENGAS RSMI print report update)
