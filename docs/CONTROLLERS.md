# WGIMS Controllers Reference

## AuthController
**Purpose**: Authentication (login/logout)

| Method | Description |
|--------|-------------|
| showLogin | Show login page |
| login | Authenticate user (rate limited: 10/min per username+IP) |
| logout | Logout, invalidate session, clear bfcache |

**Key behaviors**:
- Login via `username` field (not email)
- Rate limiting via `RateLimiter`
- Deactivated accounts (`is_active = false`) rejected
- Non-admin users blocked from admin-only intended URLs

## DashboardController
**Purpose**: Dashboard home page

| Method | Description |
|--------|-------------|
| index | Show admin or warehouse user dashboard |

**Admin dashboard**:
- Account balances per warehouse/category (DB aggregation)
- Unliquidated subsidies (pending/partial)
- Stats: total items, total subsidies, pending RIS, total warehouses

**Warehouse dashboard**:
- Account balances for assigned warehouses
- Stats scoped to assigned warehouses

## DeliverySubsidyController
**Purpose**: Manage delivery subsidies (incoming shipments)

| Method | Description |
|--------|-------------|
| index | List subsidies with search/filter |
| create | Show create form (modal on index + standalone) |
| store | Create subsidy + line items |
| show | Show subsidy detail with deliveries |
| delivery | Show delivery recording form |
| storeDelivery | Record a shipment (DR#, batch, items, warehouses, costs) |
| edit | Redirect to index with edit modal open |
| editData | JSON data for edit modal |
| update | Edit subsidy (full edit if no deliveries, correction if deliveries exist) |
| destroy | Delete subsidy + reverse all inventory |
| editDelivery | Show edit form for single delivery |
| updateDelivery | Edit a delivery record (quantities, costs, warehouses, DR#) |
| destroyDelivery | Delete a single delivery and reverse its stock |
| auditLog | Show audit log for subsidy |

**Key behaviors**:
- Creation: items, quantities, supplier, RIS# — no unit cost or warehouse (decided at dispatch)
- Delivery recording: resolves items via `Item::findOrCreateByUnitCost()`, adds stock, creates stock cards
- Correction (deliveries exist): only header fields + per-line requested quantities; identity frozen
- Deletion: reverses all deliveries, flags transfers as "deleted subsidy", hard-deletes zero-quantity orphaned items
- Uses `DeliverySubsidyCascadeService` for cost cascading

## RequisitionController
**Purpose**: Manage Requisitions (RIS) and dispatch

| Method | Description |
|--------|-------------|
| index | List RIS with search/filter |
| create | Show create form |
| store | Create RIS + line items |
| show | Show RIS detail with dispatches |
| approve | Show approval/dispatch form |
| processApproval | Approve and dispatch items (deduct stock, create stock cards) |
| signatories | Show signatories page |
| updateSignatories | Update signatory names/designations |
| printRis | Print RIS |
| edit | Show edit form |
| update | Edit RIS (header + lines) |
| correctionData | JSON data for correction modal |
| correct | Apply correction (request changes only, no dispatch changes) |
| auditLog | Show audit log |
| destroy | Delete RIS + reverse all dispatches |
| dispatchEditData | JSON data for edit dispatch modal |
| updateDispatch | Edit a single dispatch (reverse old, apply new) |
| destroyDispatch | Delete a single dispatch (restore stock, remove stock card) |

**Key behaviors**:
- Creation: description-level items from catalog, no stock linkage
- Approval/dispatch: selects exact stock records, deducts quantity, creates stock card entries
- Edit: locked lines (issued) cannot change catalog item or reduce below issued
- Correction: only request changes; dispatches untouched
- Dispatch edit: reverse old deduction from old item, apply new to new item; stock cards moved
- Uses `lockForUpdate()` on exact stock records

## StockTransferController
**Purpose**: Manage stock transfers between warehouses

| Method | Description |
|--------|-------------|
| index | List transfers with search/filter |
| store | Create transfer request |
| show | Show transfer detail |
| print | Print transfer slip |
| dispatch | Show dispatch form |
| processDispatch | Record actual stock movement |
| edit | Show edit form |
| update | Edit transfer (delta applied to source/dest) |
| destroy | Delete transfer and reverse movement |

**Key behaviors**:
- Creation: creates destination item slots via `Item::findOrCreateByUnitCost()`
- Dispatch: moves stock with `lockForUpdate()`, creates transfer_out/transfer_in stock cards
- Edit: delta applied; guards ensure source has stock and dest has units to return
- Delete: checks for later usage on destination before allowing deletion
- Supports partial dispatches

## ReservationController
**Purpose**: Manage stock reservations

| Method | Description |
|--------|-------------|
| index | List reservations |
| create | Show create form |
| store | Create reservation (locks item, checks available quantity) |
| show | Show reservation detail |
| approve | Approve reservation (PENDING → RESERVED) |
| markReady | Mark as ready (RESERVED → READY_FOR_REQUISITION) |
| cancel | Cancel reservation |

**Key behaviors**:
- Available quantity = physical - reserved (from active reservations)
- LockForUpdate on item during creation
- No direct inventory movement — purely allocation

## StockCardController
**Purpose**: View stock cards

| Method | Description |
|--------|-------------|
| summary | Summary of all items with totals |
| index | Items by category |
| itemHistory | Full history for one item |
| itemHistoryByUnitCost | FIFO batch view (for display only) |
| printStockCard | Print stock card |

## ReportController
**Purpose**: Generate reports and snapshots

| Method | Description |
|--------|-------------|
| rpci | RPCI report (Report on Physical Count of Inventories) |
| printRpci | Print RPCI |
| exportRpci | Export RPCI to Excel |
| saveRpciSnapshot | Save RPCI snapshot |
| rsmi | RSMI report (Report of Supplies and Materials Issued) |
| printRsmi | Print RSMI |
| exportRsmi | Export RSMI to Excel |
| saveRsmiSnapshot | Save RSMI snapshot |
| inventoryBalance | Inventory balance report |
| exportInventoryBalance | Export to Excel |
| viewSnapshot | View saved snapshot |

## ItemController
**Purpose**: View items/inventory

| Method | Description |
|--------|-------------|
| index | List items with search/filter |
| show | Show item detail with stock card |

## WarehouseController
**Purpose**: Manage warehouses

| Method | Description |
|--------|-------------|
| index | List warehouses |
| create | Show create form |
| store | Create warehouse |
| edit | Show edit form |
| update | Update warehouse |

## SupplierController
**Purpose**: Manage suppliers

| Method | Description |
|--------|-------------|
| index | List suppliers |
| create | Show create form |
| store | Create supplier |
| edit | Show edit form |
| update | Update supplier |
| toggleActive | Activate/deactivate supplier |

## UserController
**Purpose**: Manage users

| Method | Description |
|--------|-------------|
| index | List users (admin only) |
| create | Show create form |
| store | Create user |
| edit | Show edit form |
| update | Update user |
| checkUsername | API: check username availability |

## ItemCategoryController
**Purpose**: Manage item categories

| Method | Description |
|--------|-------------|
| index | List categories |
| store | Create category |
| update | Update category |
| destroy | Delete category (if no items linked) |
| toggleActive | Activate/deactivate category |

## ItemCatalogItemController
**Purpose**: Manage catalog item names

| Method | Description |
|--------|-------------|
| store | Create catalog item |
| update | Update catalog item |
| destroy | Delete catalog item (if not used in subsidies) |

## NotificationController
**Purpose**: Manage system notifications

| Method | Description |
|--------|-------------|
| index | List notifications for current user |
| markRead | Mark single notification as read |
| markReadAjax | Mark as read via AJAX |
| markAllRead | Mark all as read |
| getUnread | API: get unread notifications JSON |

## ScopesWarehouse Trait
**Purpose**: Shared warehouse scoping for all controllers

| Method | Description |
|--------|-------------|
| getUserWarehouseIds($user) | Get allowed warehouse IDs (null = admin) |
| applyWarehouseScope($query, $user, $filterId) | Scope query by warehouse |
| applyTransferWarehouseScope($query, $user) | Scope transfer queries (from/to) |
| userCanAccessWarehouse($user, $warehouseId) | Check single warehouse access |
| getCenterName($user, $filterId) | Get display name for reports |
