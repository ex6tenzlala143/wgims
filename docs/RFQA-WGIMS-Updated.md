# REQUEST FOR SOFTWARE QUALITY ASSESSMENT
## FO X

---

**App Name:** Welfare Goods Inventory Management System (WGIMS v2 · DSWD)
**Version / Build:** V 2.0 · Laravel 12.x · PHP 8.2.12 · MySQL / SQLite
**Test Environment:** http://172.31.168.48/wgims/public
**Alternate URL:** http://172.31.168.247/wgims/public

---

## Test Accounts

| Role | Username | Password |
|------|----------|----------|
| Administrator | admin | password123 |
| Warehouse Manager | jhukdong | password123 |

---

## Feature / Module List

1. Dashboard
2. Stock Cards
3. Items / Inventory
4. Subsidies / Deliveries
5. Requisitions / Augmentations (RIS)
6. Stock Transfers
7. Suppliers
8. Warehouses
9. Item Categories
10. Users
11. RPCI Report
12. RSMI Report
13. Inventory Balance

---

## Role Permission Matrix

| Action | Administrator | Warehouse Manager |
|--------|:---:|:---:|
| View all modules | ✅ | ✅ |
| Create records (items, subsidies, RIS, transfers, suppliers, warehouses) | ✅ | ✅ |
| Edit / Update records | ✅ | ❌ |
| Delete records | ✅ | ❌ |
| User management | ✅ | ❌ |
| Item categories | ✅ | ❌ |
| Correction History / Audit Logs | ✅ | ❌ |
| Archive / Restore Subsidies | ✅ | ❌ |
| Edit / Delete individual deliveries | ✅ | ❌ |
| Edit dispatched RIS items | ✅ | ❌ |
| Edit / Delete Stock Transfers | ✅ | ❌ |
| Inventory Balance Report | ✅ | ✅ |
| RPCI / RSMI Reports | ✅ | ✅ |

---

## Module Feature Specifications

### 1 · User Login

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| User Authentication | Authenticates via **username** (not email) and password. Only `is_active = true` accounts pass. Inactive users receive "Invalid credentials or account is inactive." Rate-limited to 10 attempts per 60 seconds per username+IP combination. | As a user, I want to log in with my username and password so I can access the system securely and be blocked if my account is inactive. | All |
| Secure Logout | Invalidates session, regenerates CSRF token, sets `Clear-Site-Data` header to purge bfcache, redirects to login. | As a user, I want to log out securely so my session cannot be reused after I leave. | All |
| No-Cache & Active Check | Authenticated pages use `private, no-cache, must-revalidate` headers to allow Back/Forward cache while preventing disk caching. Login/logout use `no-store`. `EnsureUserIsActive` middleware terminates sessions of newly deactivated users on their next request. | As a user, I want cached pages to be inaccessible after logout so my data stays private. | All |
| Session Security | Login regenerates the session ID (session fixation protection). Old session IDs cannot be reused after logout. | As a user, I want my session to be secure so it cannot be hijacked. | All |

---

### 2 · Dashboard

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| Admin Dashboard | System-wide inventory value per warehouse and category. Global stats: Total Items, Delivery/Subsidies, Pending RIS, Active Warehouses. Uses aggregated DB queries (no N+1). | As an Administrator, I want a system-wide overview of inventory values and pending transactions so I can monitor operations. | Admin, Warehouse Manager |
| Warehouse User Dashboard | Shows logged-in user's warehouse inventory balance per account code, pending RIS count, and item/subsidy totals for assigned warehouses. Scoped to assigned warehouses only. | As a warehouse user, I want to see my warehouse balances and pending requests so I know my stock status. | All |
| Unliquidated Subsidies Summary | Lists subsidies with status Pending or Partial, grouped by warehouse. | As an Admin, I want to see which subsidies are not fully delivered so I can follow up. | Admin, Warehouse Manager |
| Role-Based View | Dashboard content and layout differ by role. Users with no warehouse assigned see "You have no warehouses assigned. Please contact an administrator." | As a user, I want a dashboard relevant to my role and warehouses so I only see applicable information. | All |

---

### 3 · Item Categories

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Item Categories | Displays all categories with label, account code, status (Active/Inactive). | As an Admin, I want to view all item categories so I can manage the classification. | Admin only |
| Create Category | Adds a new category with label and account code. Account code is automatically inherited by all catalog items under the category. | As an Admin, I want to add categories so items can be grouped correctly. | Admin only |
| Edit Category | Updates label and/or account code. All catalog items under the category automatically inherit the updated account code. | As an Admin, I want to correct category details so records stay accurate. | Admin only |
| Add / Edit Catalog Item Names | Manages item description names under each category. Account code always inherited from parent category (not independently configurable). Duplicate names within the same category are rejected. | As an Admin, I want to manage the approved item name list so dispatchers see consistent descriptions. | Admin only |
| Deactivate / Delete Category | Cannot delete if category has items assigned ("Cannot delete … it has items assigned. Deactivate it instead."). Toggle active/inactive to hide from dropdowns. | As an Admin, I want to retire categories without breaking history so the list stays clean. | Admin only |

---

### 4 · Item Management

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Items | Displays active items with search by description/stock number, filter by category, warehouse, status, subsidy source. Paginated (20/page). Shows Merged badge for identical records, Out of Stock, Below Reorder Point. | As a user, I want to search and filter items so I can quickly find a specific supply. | All authenticated |
| Create Item | Adds item with description, unit, category, unit cost, ENGAS unit cost, expiration date, warehouse, reorder point. Message: "Stock number will be assigned when a delivery/subsidy is delivered." | As an Admin/Warehouse Manager, I want to add items so they can be included in subsidies and RIS. | Admin, Warehouse Manager |
| View Item Detail | Shows full record: stock number, description, unit, category/account code, warehouse, costs, expiry, source Subsidy ID (SUB-NNNNNN), qty on hand, stock card history with Print. | As a user, I want to view an item's full details and history so I can check its status. | All authenticated |
| Edit Item | Update description, unit, category, costs, expiry, reorder point. Warehouse and quantity not directly editable (use transfer/delivery). | As an Admin, I want to correct item details so inventory records remain accurate. | Admin only |
| Delete Item | Blocked if referenced by deliveries, RIS, dispatches, or stock transfers ("Cannot delete … it is referenced"). Permanently deletes stock card entries only when item is unreferenced. | As an Admin, I want to remove unused items with safeguards so history is not broken. | Admin only |
| Item Identity Rules | Items with the same description are **not** the same stock record. Identity is determined by: Stock Number + Source Subsidy + Warehouse + Unit Cost + ENGAS Unit Cost + Expiration Date. Records with different costs or subsidies are never merged. | — | System rule |

---

### 5 · Supplier Management

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Suppliers | Displays suppliers with name, address, status (Active/Inactive). Search by name. Scoped to assigned warehouses for non-admins. | As a user, I want to view suppliers so I can select one when creating a subsidy. | All authenticated |
| Create Supplier | Adds supplier with name (required), TIN, address, contact person, phone, email. | As a user, I want to register suppliers so they can be linked to subsidies. | Admin, Warehouse Manager |
| Edit Supplier | Updates name/contact details. | As an Admin, I want to update supplier information so contact data stays current. | Admin only |
| Active/Inactive | Toggle active; deactivated suppliers blocked for new deliveries ("not active") but history preserved. Delete blocked if referenced by deliveries. | As an Admin, I want to deactivate unused suppliers without losing history. | Admin only |

---

### 6 · Warehouse Management

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Warehouses | Displays warehouses with name, code, place, status, assigned users and items counts. Admin/Manager view. Scoped to assigned warehouses for others. | As a user, I want to see my assigned warehouses so I know where I can transact. | All authenticated |
| Create Warehouse | Adds warehouse with unique name, code, place. | As a user, I want to register warehouses so stock can be assigned. | Admin, Warehouse Manager |
| Edit Warehouse | Updates name, code, place, active status. Deactivated warehouses hidden from new record dropdowns but history kept. | As an Admin, I want to update warehouse details so records stay accurate. | Admin only |
| User Assignment | Warehouses assigned per user at creation/edit. Warehouse Managers are assigned to all warehouses by default. | As an Admin, I want to assign users to warehouses so data access is properly scoped. | Admin only |

---

### 7 · User Management

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Users | Displays users with username, name, role, warehouses, status. Search by name/username, filter by role. | As an Admin, I want to search and filter users so I can manage accounts quickly. | Admin only |
| Create User | Creates account with username (unique), name, email, role, warehouse assignments, password + confirmation. | As an Admin, I want to create accounts with correct roles and warehouses so staff have proper access. | Admin only |
| Edit User | Updates name, email, role, warehouses, active status. Optional new password (blank keeps current). Shows "User updated." | As an Admin, I want to edit roles/warehouses and deactivate users so access stays current. | Admin only |
| Role Assignment | Two primary roles: **Administrator** (full access) and **Warehouse Manager** (create/view only — no edit/delete). | As an Admin, I want to assign roles so each person has the correct permission level. | Admin only |
| Username Availability Check | Real-time API `GET /api/check-username` confirms uniqueness as admin types. | As an Admin, I want immediate feedback if a username is taken so I don't submit a duplicate. | Admin only |
| Password & Status | Passwords bcrypt-hashed. Deactivating immediately terminates active sessions via `EnsureUserIsActive` middleware. No delete — deactivate to preserve audit trail. | As an Admin, I want to reset passwords and deactivate accounts securely without breaking audit history. | Admin only |

---

### 8 · Subsidies / Deliveries

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Subsidies / Deliveries | Displays subsidies with **Subsidy ID** (SUB-000001, permanent), supplier, date, warehouse, total cost, status (Pending / Partial / Fully Delivered / Archived). Search and filters, paginated. | As a user, I want to view and filter subsidies so I can track incoming goods. | All authenticated |
| Create Subsidy | Creates header with date, supplier dropdown, and Requested Items (catalog-based descriptions). Auto-assigns permanent **SUB-NNNNNN** ID on creation. No warehouse or unit cost at creation — these are assigned at delivery time. | As a Manager/Admin, I want to create a subsidy request so deliveries can be recorded against it. | Admin, Warehouse Manager |
| Record Delivery / Shipment | Dispatches goods to warehouse(s). Dispatcher selects: warehouse, exact quantity, unit cost, ENGAS unit cost, expiration date, DR number. Stock is added to the correct warehouse item record. Stock card receipt entry created. "Shipment recorded and stock updated." | As a Manager, I want to record shipments so stock is added to the correct warehouse. | Admin, Warehouse Manager |
| Multi-Cost / Multi-Warehouse Delivery | Same item description can be delivered at different unit costs to different warehouses. Each creates a **separate stock record** (never merged). Identity: Subsidy + Description + Unit Cost + ENGAS Cost + Expiry + Warehouse. | System rule — ensures cost traceability | System rule |
| View Subsidy Detail | Shows Subsidy ID, supplier, requested vs delivered vs remaining per item, shipment records (date, warehouse, qty, DR, costs per individual dispatch), Correction History, and actions. | As a user, I want to see a subsidy's delivery progress so I know what is still outstanding. | All authenticated |
| Edit Subsidy | Changes header/requested lines. Lines with delivered stock are locked ("This item has already been delivered and cannot be changed. Correct the request quantity only."). Historical identity (RIS number, supplier, DR number) frozen once any delivery exists. | As an Admin, I want to correct a subsidy before delivery completion. | Admin only |
| Edit Delivery (Shipment) | Corrects warehouse, quantity, unit cost, ENGAS cost, expiry of a recorded shipment. Stock auto-adjusted at both old and new locations. Cost changes cascade to all downstream items (via transfer chain), dispatch items, and stock card entries. "Shipment updated and stock adjusted." | As an Admin, I want to correct a recorded shipment so stock and cards are recalculated correctly. | Admin only |
| Delete Delivery (Shipment) | Removes individual shipment record. Stock reversed at the affected warehouse. Stock card entries deleted. Running balances recalculated for remaining entries. | As an Admin, I want to remove duplicate and incorrect entries. | Admin only |
| Delete Subsidy | Fully reverses all delivered stock across all warehouses. Marks related Stock Transfers as "Related to Deleted Subsidy" for review (never auto-deletes them). Items created solely by this subsidy are hard-deleted. Items referenced by other transactions are preserved with a "FROM DELETED SUBSIDY" flag. | As an Admin, I want to fully reverse a subsidy and have downstream items clearly flagged. | Admin only |
| Archive / Restore Subsidy | Archive freezes subsidy and flags related transfers/items with "Archived" status badge. Restore clears the flag. Both actions written to Audit Log. | As an Admin, I want to freeze a subsidy for review without deleting it. | Admin only |
| Correction / Audit Log | Full change history per subsidy: who, when, what changed (header fields, line quantities, cascaded cost updates). | As an Admin, I want an audit trail so corrections are traceable. | Admin only |

---

### 9 · Requisitions / Augmentations (RIS)

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| List Requisitions | Displays RIS with **RIS ID** (RIS-NNNNNN, auto-assigned), **RIS Number**, date, warehouse, office, purpose, status (Pending / Partially Fulfilled / Approved / Cancelled). Filter by status, search RIS#/DR#/purpose, warehouse-scoped. | As a user, I want to view and filter RIS so I can track augmentations. | All authenticated |
| Create RIS | Creates RIS with: Date Requested, Requested By/Designation, Entity Name, Fund Cluster, Responsibility Center Code, Office, Division, Province/Municipality, Purpose, and Requested Items via searchable dropdown (catalog-based descriptions). **No warehouse or stock record assigned at creation** — allocation happens at dispatch time only. | As a user, I want to create a requisition so I can request and record augmentations. | Admin, Warehouse Manager |
| View RIS Detail | Shows RIS info, status badge, fulfilment progress (Requested / Issued / Outstanding), Requested Items table with: Stock No., Unit Cost, ENGAS Unit Cost, Stock Available, Qty Issued, DR No. Items dispatched from multiple stocks at different costs show **separate cost rows per stock record** (never merged). Partial Delivery Breakdown table below, Signatories section. | As a user, I want full RIS details so I can review request vs issuance. | All authenticated |
| Dispatch Items (Approve/Issue) | Dispatcher selects warehouse → stock record (shows stock number, description, cost, available qty, ENGAS cost, expiry) → quantity → DR number → ENGAS unit cost. Multiple dispatches allowed against the same RIS line (partial fulfilment). **Requested quantity is never changed when dispatching.** | As a user, I want to dispatch items for augmentations. | Admin, Warehouse Manager, Custodian, Center Head |
| Dispatch Edit | Corrects warehouse, exact stock record, quantity, costs, expiry, DR Number of an issued dispatch item. Stock reversed at old record and re-applied at new record. Stock card entry moved/updated. Running balances recalculated. | As an Admin, I want to correct an issued dispatch item. | Admin only |
| Delete Dispatched Item | Reverses a single dispatch: restores quantity to the exact stock record, deletes the stock card issuance entry, recalculates running balances, updates RIS fulfilment status. | As an Admin, I want to delete an incorrect dispatch and restore stock. | Admin only |
| Edit RIS | Correct RIS modal changes header fields and requested quantities only. Dispatches, DR numbers, and inventory balances are **never touched** by editing. Locked lines (already dispatched) cannot change item or go below issued quantity. Changes written to Correction History / Audit Log. | As an Admin, I want to correct request details without touching issued stock. | Admin only |
| Signatory Management | Requested By / Approved By / Issued By / Received By names and designations editable. Requires `canApprove()` role (Admin, Warehouse Manager, Custodian, Center Head). "Signatories updated." | As a custodian, I want to manage signatories so the printed RIS has correct authorizations. | Admin, Warehouse Manager, Custodian, Center Head |
| Print RIS | Generates official RIS form with all dispatch details, signatories, and quantities. | As a user, I want to print the official form. | All authenticated |
| Notifications | New RIS triggers notification to all approvers. Requester notified on fully or partially fulfilled. | As a user, I want to be notified of status changes. | System |
| Requested vs Dispatched Separation | Editing dispatched quantity **never modifies requested quantity**. These are permanently independent fields. | Critical business rule | System rule |
| Partial Delivery Breakdown | Items dispatched from different stock records with different unit costs are shown as **separate rows**, never merged. Each row shows: Stock Number, Unit Cost, ENGAS Unit Cost, ENGAS Total, Quantity Issued, Total Cost, DR Number. | As a user, I want full cost visibility per dispatch. | All authenticated |
| Correction History | Full audit log of all RIS corrections: who, when, what changed. | As an Admin, I want a traceable record of all changes. | Admin only |

---

### 10 · Stock Transfers

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| Create Transfer Request | Creates transfer with Transfer Date, Source Warehouse, Destination Warehouse (must differ — "Source warehouse and destination warehouse must be different."), and items (searchable dropdown shows stock number, description, cost, available qty, ENGAS cost, expiry). Auto-assigns **TRF-YYYY-NNNN** number. Source Subsidy lineage is tracked and preserved on destination records. | As a Manager/Admin, I want to request moving stock between warehouses. | Admin, Warehouse Manager |
| Dispatch Items | Dispatches transfer in one or more shipments via "Dispatch Items": enter Qty This Dispatch per line (capped at remaining and available). "Dispatch recorded and stock updated." Subsidy lineage propagated to destination items. Stock card transfer_out / transfer_in entries created. | As a Manager, I want to dispatch shipments so stock moves in parts if needed. | Admin, Warehouse Manager |
| View Transfer Detail | Shows TRF number, date, Source → Destination, status (Pending / Partial / Completed), requested/dispatched/remaining per line, dispatch breakdown, and deleted/archived Subsidy review panel (flagged transfers). | As a user, I want to track transfer progress and history. | All authenticated |
| Edit Transfer (Admin Correction) | Admin only: correct date, remarks, requested quantity, and **dispatched quantity** with full inventory reconciliation. Increasing: source must hold the extra units. Decreasing: destination must still hold the units being returned. Stock card entries spread delta newest-first. Running balances rebuilt for both warehouses. Audit trail written. | As an Admin, I want to correct or reverse transfers safely so stock never goes out of balance. | Admin only |
| Delete Transfer | Reverses all dispatched stock to source warehouse. Blocked if destination stock was already consumed by a later RIS or subsequent transfer ("cannot be deleted yet because …"). Audit trail written before deletion. | As an Admin, I want to reverse a transfer when it was made in error. | Admin only |
| Requested vs Dispatched Separation | `quantity_requested` (planned) and `quantity` (dispatched) are independent. Editing dispatched quantity does not automatically change requested quantity — admin sets both explicitly. | Critical business rule | System rule |

---

### 11 · Stock Cards

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| Stock Card Summary by Category | Home page shows summary per category (item count, quantities). Click category to list items with current balances. Warehouse-scoped for non-admins. | As a custodian, I want to browse stock cards by category so I can review groups of items. | All authenticated |
| Item Stock Card History | Full chronological ledger per item: receipt entries (deliveries), issue entries (RIS dispatches), transfer_in/transfer_out entries. Shows running balance quantity, unit cost, total value. FIFO-style cost tracking. | As a custodian, I want to see a complete movement history for each item. | All authenticated |
| Stock Card by Unit Cost | View grouped by cost variant for items with multiple unit costs. | As a custodian, I want to see cost-separated ledger entries. | All authenticated |
| Print Stock Card | Generates print-optimized ledger in government format per item/batch. | As a custodian, I want to print stock cards for audit or inspection. | All authenticated |
| Running Balance Accuracy | Running balance (`balance_qty`, `balance_unit_cost`, `balance_total_cost`) is recalculated from the full history after every edit/delete operation to ensure correctness. Initial unit cost seeded from the item record when no prior receipt exists. | Inventory correctness rule | System rule |

---

### 12 · Reports

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| RPCI On-Screen View | Report on Physical Count of Inventories grouped by category/account code. Filterable by warehouse and category. Shows: Stock No., Description, Unit, Qty on Hand, Unit Cost, ENGAS Unit Cost, Total Value, category headers, GRAND TOTAL. | As an Admin/custodian, I want to view RPCI on screen so I can review counts before printing. | Admin, Warehouse Manager |
| Print RPCI | Generates official "Report on the Physical Count of Inventories" form with Per Card/Per Count qty columns, Unit Value, Total Value, Remarks. Paper size A4/Legal. | As an Admin, I want to print the official RPCI for COA submission. | Admin, Warehouse Manager |
| Export RPCI to Excel | Downloads formatted `.xlsx` with category groupings, account codes, and grand total. | As an Admin, I want to export RPCI to Excel for sharing/archiving. | Admin, Warehouse Manager |
| RSMI On-Screen View | Report of Supplies and Materials Issued from approved/partially-approved RIS records. Filterable by warehouse and date range. Shows: Stock No., Description, Qty Issued, **Unit Cost from dispatch records** (accurate per-dispatch costs, not item record costs), ENGAS costs, Amount. Grand total. | As an Admin/custodian, I want to view RSMI to review issuances for a period. | Admin, Warehouse Manager |
| Print RSMI | Generates official "Report of Supplies and Materials Issued" form (RIS No., Responsibility Center Code, Stock No., qty issued, **dispatch-level cost**, amount). Per-RIS Print RSMI button. Recapitulation section. | As an Admin, I want to print the official RSMI for reporting. | Admin, Warehouse Manager |
| Export RSMI to Excel | Downloads RSMI as formatted `.xlsx` with RIS details, quantities, **dispatch-level costs**, totals. | As an Admin, I want to export RSMI digitally. | Admin, Warehouse Manager |
| RSMI Cost Accuracy | Unit costs in RSMI are taken from each individual dispatch record (`requisition_dispatch_items.unit_cost`), **not** from the current item price. This ensures RSMI reflects the actual cost at the time of issuance, including multi-cost dispatches from the same item description. | Critical reporting accuracy rule | System rule |
| Inventory Balance Report | Current stock grouped by warehouse → category → description with qty, unit cost, total value, category subtotals and grand total. Each cost variant shown as a separate row (never merged). Warehouse filter + Excel Export. | As an Admin, I want a live inventory balance so I can monitor financial value. | Admin, Warehouse Manager |
| Save Report Snapshot | Saves point-in-time JSON copy of RPCI/RSMI for a period/warehouse with Serial No. and saver name. Snapshot is frozen — stock movements after save do not alter it. | As an Admin, I want to archive report snapshots for end-of-month records. | Admin, Warehouse Manager |
| View Saved Snapshot | Retrieves and displays a previously saved snapshot without regeneration. | As an Admin, I want to review historical snapshots for audit purposes. | Admin, Warehouse Manager |

---

### 13 · Notifications

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| Notification Bell | Topbar bell icon with red count of unread notifications, updated via API polling (`GET /api/notifications/unread`). | As a user, I want a badge so I know when something needs attention. | All authenticated |
| Notification List | All notifications for logged-in user in reverse chronological order, paginated. | As a user, I want to view all notifications so I can review past alerts. | All authenticated |
| Mark as Read | Marks a single notification as read (`POST /notifications/{id}/read` and `read-ajax`). | As a user, I want to mark a notification as read so I can track acted alerts. | All authenticated |
| Mark All as Read | Marks all unread as read in one action (`POST /notifications/read-all`). | As a user, I want to clear all unread at once to reset my count. | All authenticated |

---

### 14 · API Helpers (Internal)

| Endpoint | Description | Throttle | Access |
|----------|-------------|----------|--------|
| `GET /api/check-dr` | Checks if a DR number already exists before form submission. Returns `{ exists: bool }`. | 120/min | Authenticated |
| `GET /api/item-stock-card` | Checks if an item already has a stock record for a given unit cost/expiry combination. Previews the stock number that would be generated. | 120/min | Authenticated |
| `GET /api/requisition-items` | Returns active items with stock in a given warehouse for the RIS dispatch form (includes stock number, costs, qty, expiry). | — | Authenticated |
| `GET /api/requisition-description-items` | Returns catalog-level item descriptions for the RIS create form. | — | Authenticated |
| `GET /api/transfer-items` | Returns active items with stock in a given warehouse for the stock transfer create form. | — | Authenticated |
| `GET /api/check-username` | Real-time username uniqueness check. | — | Authenticated |
| `GET /api/notifications/unread` | Returns unread notification count and list for the notification bell. | — | Authenticated |

---

### 15 · Searching, Filtering & Pagination

| Feature | Description | User Story | Access |
|---------|-------------|------------|--------|
| Search & Filters | Every list has search boxes, dropdown filters (status, warehouse, category, subsidy source, date range). Filters retained across pagination. 20 records per page by default. | As a user, I want consistent search and filter so I can narrow large lists quickly. | All authenticated |
| Searchable Dropdowns (Comboboxes) | Type to filter, arrow keys + Enter to select, Esc to close. Shows "N more — keep typing" for long lists and "No matching options" when none found. Used on: item selection, warehouse selection, supplier selection, catalog item selection. | As a user, I want to find items/suppliers/warehouses by typing so I don't scroll long lists. | All authenticated |

---

## Critical Business Rules Summary

| Rule | Description |
|------|-------------|
| **Item Identity** | Items are identified by the combination of: Originating Subsidy + Description + Unit Cost + ENGAS Unit Cost + Expiration Date + Warehouse. Same name ≠ same record. |
| **No Cost Merging** | Stock records from different subsidies or with different costs are **never merged**, even when description and unit match. |
| **Requested vs Dispatched** | `quantity_requested` and dispatched quantity are permanently independent. Editing dispatched quantity never modifies the original request. |
| **Cost Traceability** | RSMI and Partial Delivery Breakdown use per-dispatch unit costs, not current item prices. |
| **Cascade on Edit** | Editing a delivery's unit cost cascades to: the item record, all downstream transfer items, stock card entries, and dispatch items — atomically in a single database transaction. |
| **Delete Safety** | Deleting a subsidy/transfer/RIS reverses all inventory effects atomically. Blocks if downstream consumption would result in negative stock. |
| **Quantity Display** | Quantities shown as whole numbers. Costs may show decimal places (unit cost, ENGAS unit cost, total cost, ENGAS total cost). |
| **Session Security** | Deactivated users are immediately logged out on their next request. Sessions are invalidated on logout. Protected pages use no-store headers after logout. |

---

## Technical Specifications

| Component | Value |
|-----------|-------|
| Framework | Laravel 12.x |
| PHP | 8.2.12 |
| Database | MySQL (production) / SQLite (testing) |
| Frontend | Blade templates + Alpine.js |
| Build Tool | Vite 7.x |
| Test Suite | PHPUnit (178 tests, 2300 assertions, 0 failures) |
| Authentication | Custom username-based auth (no Spatie) |
| Roles | admin, warehouse_manager (2 active roles) |
| Migrations | 50 migrations (schema + data) |
| Routes | 109 named routes |
| Controllers | 15 controllers |
| Models | 21 models |

---

## Prepared and Reviewed By

| Role | Name |
|------|------|
| Prepared By | JUN CARLO L. ABRAGAN — Social Welfare Assistant |
| Noted By | MELPE JEAN B. MAGHANOY, CPA — Financial Management Division Chief |
| Recommending Approval | JOHN PAUL BENEDICT U. ERQUITA — ITO II-RICTMS Project Manager |
| Approved By | RONIEL P. TABAR — ITO II-RICTMS Section Head |

---

*Document updated to reflect WGIMS v2.0 post-QA audit state — August 2026*
