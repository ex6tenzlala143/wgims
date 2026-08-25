# WGIMS — Complete Process Flow Documentation

**Welfare Goods Inventory Management System (DSWD Region X)**
Based on the actual Laravel codebase (routes, controllers, models, middleware, services, views).

> **Purpose of this document**
> - Understand how the entire system works end-to-end
> - Use as a testing/QA checklist (every decision point = a test case)
> - Locate where bugs occur (each box names the exact file that runs it)
> - Explain the system to users and support the user manual
> - Show exactly how inventory quantities move through the system

---

## How to read these diagrams

- **Diamond shapes** are decision points (questions the system asks).
- **Rectangles** are actions/steps.
- **Text in brackets** like `[AuthController.php:41]` tells you the exact file and line that performs the step — useful for QA and debugging.
- **Bold role names** show who is allowed to do each step.

### The 5 user roles and what they may do

| Role | Create documents (subsidy, delivery, RIS, transfer) | Approve/issue RIS | Edit master data (categories, suppliers, warehouses, users) | Correct / delete documents |
|---|---|---|---|---|
| **Admin** | ✅ | ✅ | ✅ | ✅ |
| **Warehouse Manager** | ✅ | ✅ | ❌ (view only) | ❌ |
| **Supply Custodian** | ❌ | ✅ | ❌ | ❌ |
| **Center Head** | ❌ | ✅ | ❌ | ❌ |
| **Center Staff** | ❌ | ❌ | ❌ | ❌ |

All non-admin/WM roles see **only the warehouses assigned to them** (`app/Http/Controllers/Concerns/ScopesWarehouse.php`).

---

# PART 1 — HIGH-LEVEL OVERALL SYSTEM FLOW

## 1.1 The big picture: how goods flow through WGIMS

```
 SUPPLIER
    │  delivers goods against a subsidy
    ▼
 SUBSIDY  (SUB-000001)          ← the "request/demand" document (what SHOULD arrive)
    │  shipped in one or more batches ("deliveries"/dispatches)
    ▼
 DELIVERY BATCH                 ← assigns warehouse + unit cost + ENGAS cost + expiry + DR#
    │  stock physically enters
    ▼
 STOCK RECORDS  (items table)   ← THE inventory: one record per warehouse per batch
    │                              (identity = subsidy + description + unit cost +
    │                               ENGAS cost + expiry date + warehouse)
    │
    ├──────────────► RIS  (RIS-000001) ── approved & issued ──► quantities LEAVE stock
    │                (requisition from a center/office)
    │
    ├──────────────► STOCK TRANSFER  (TRF-2026-0001) ── quantities MOVE between warehouses
    │                (source warehouse → destination warehouse)
    │
    ▼
 EVERY movement writes a STOCK CARD entry (receipt / issue / transfer in / transfer out)
    │
    ▼
 REPORTS:  RPCI (physical count) · RSMI (issued supplies) · Inventory Balance (live) · Stock Cards
```

## 1.2 Overall system process flow (flowchart)

```mermaid
flowchart TD
    START([User opens WGIMS]) --> LOGIN[Login screen<br/>username + password]
    LOGIN --> AUTH{Authentication<br/>valid & account active?}
    AUTH -- No, too many attempts --> BLOCK[Blocked: max 10 attempts/min<br/>per username+IP]
    BLOCK --> LOGIN
    AUTH -- No --> ERR[Error: invalid credentials<br/>or inactive account]
    ERR --> LOGIN
    AUTH -- Yes --> ROLECHECK{Role?}
    ROLECHECK -- Admin or<br/>Warehouse Manager --> DASHA[Admin Dashboard:<br/>all warehouses, global stats]
    ROLECHECK -- Center staff/custodian/head<br/>with assigned warehouses --> DASHW[Warehouse Dashboard:<br/>only their warehouses]
    ROLECHECK -- Assigned to no warehouse --> DASHN[No-warehouse notice page]

    DASHA --> MOD1
    DASHW --> MOD2

    subgraph MASTER [Master Data - Admin only]
        MOD1[Manage Item Categories<br/>& Item Catalog names]
        MOD1B[Manage Suppliers,<br/>Warehouses, Users]
    end

    subgraph OPERATIONS [Daily Operations - Admin + Warehouse Manager]
        MOD2[Create Subsidy<br/>SUB-XXXXXX]
        MOD3[Record Delivery batches:<br/>warehouse, unit cost, ENGAS cost,<br/>expiry date, DR number]
        MOD4[Create RIS Requisition<br/>RIS-XXXXXX]
        MOD5[Approve RIS & Issue items<br/>from EXACT stock records]
        MOD6[Stock Transfers TRF-YYYY-NNNN:<br/>warehouse A to B, partial dispatches]
    end

    MOD1 --> STOCKDB[(Stock Records<br/>per warehouse)]
    MOD1B --> MOD2
    MOD2 --> MOD3
    MOD3 --> STOCKDB
    MOD4 --> MOD5
    STOCKDB --> MOD5
    STOCKDB --> MOD6

    MOD3 --> LEDGER[(Stock Card Ledger<br/>auto-written entries)]
    MOD5 --> LEDGER
    MOD6 --> LEDGER

    LEDGER --> REPORTS[Reports:<br/>RPCI - physical count<br/>RSMI - issued supplies<br/>Inventory Balance - live<br/>Stock Card printouts]
    STOCKDB --> REPORTS

    REPORTS --> AUDIT[Audit trails:<br/>subsidy / requisition / transfer logs<br/>append-only history]

    DASHA --> OUT([Logout: session destroyed,<br/>back button protected])
    DASHW --> OUT
```

## 1.3 Module relationship map (how documents link together)

```mermaid
flowchart LR
    SUP[Supplier] --> DS[DeliverySubsidy<br/>SUB-000001<br/>status: pending / partial /<br/>fully_delivered]
    DS --> DSI[DeliverySubsidyItem<br/>requested lines:<br/>qty, description, category]
    DS --> DLV[Delivery<br/>one per shipment batch]
    DLV --> DI[DeliveryItem<br/>per line: warehouse_id,<br/>unit_cost, engas_unit_cost,<br/>expiration_date, dr_number]
    DI --> IT[Item = Stock Record<br/>stock number, quantity,<br/>unit_cost, engas_unit_cost,<br/>expiry, source_subsidy snapshot]

    RQ[Requisition<br/>ris_code RIS-000001<br/>ris_number RIS-YYYYMM-####] --> RQI[RequisitionItem<br/>quantity_requested]
    RQI --> RDI[RequisitionDispatchItem<br/>quantity_issued FROM<br/>exact item_id]
    IT --> RDI

    ST[StockTransfer<br/>TRF-YYYY-NNNN] --> STI[StockTransferItem<br/>source item -> destination item,<br/>requested vs dispatched qty]
    IT -- source --> STI
    STI -- destination_item_id --> IT

    DI -- receipt --> SCE[StockCardEntry<br/>running balance ledger]
    RDI -- issue --> SCE
    STI -- transfer in/out --> SCE
    SCE --> IT
```

**Key idea for non-programmers:** every quantity in WGIMS lives on an *Item row* (a stock record). Documents never hold stock themselves — they only move quantities onto or off of stock records, and every movement is journalled into a Stock Card entry so the running balance can always be replayed and verified.

---

# PART 2 — USER LOGIN & AUTHENTICATION

## 2.1 Login flowchart

```mermaid
flowchart TD
    A([User visits any page]) --> B{Already logged in?}
    B -- Yes --> HOMEGO[Redirect to Dashboard]
    B -- No --> C[Show login form<br/>auth/login.blade.php]
    C --> D[Submit username + password]
    D --> E{Fields present?}
    E -- No --> BACK1[Back with validation errors]
    BACK1 --> C
    E -- Yes --> F{Login attempts for this<br/>username+IP under 10 per minute?}
    F -- No --> G["Blocked: 'Too many login attempts.<br/>Please try again in N seconds.'"]
    G --> C
    F -- Yes --> H{Username exists AND password correct<br/>AND account is_active = true?}
    H -- No --> I["Error: 'Invalid credentials or<br/>account is inactive.' + attempt counted"]
    I --> C
    H -- Yes --> J[Clear attempt counter<br/>Regenerate session ID<br/>prevents session-fixation attacks]
    J --> K{Was user trying to reach<br/>a specific page before login?}
    K -- Yes, but page is admin area<br/>and user is not Admin --> L[Send to Dashboard instead<br/>non-admins can't be bounced into admin pages]
    K -- Yes, other page --> M[Go to intended page]
    K -- No --> N[Go to Dashboard]
    L & M & N --> O[Dashboard chosen by role<br/>DashboardController::index]
```

## 2.2 What happens on every request after login

```mermaid
flowchart TD
    REQ([Every page request]) --> ACTIVE{Is logged-in user's<br/>account still active?}
    ACTIVE -- "No (admin deactivated them)" --> FORCE[Force logout immediately:<br/>session destroyed, sent to login]
    ACTIVE -- Yes --> ROUTE{Which route?}
    ROUTE -- "Admin area routes<br/>(middleware: admin)" --> R1{Role is Admin OR<br/>Warehouse Manager?}
    R1 -- No --> DENY1[403 Access denied]
    R1 -- Yes --> OK
    ROUTE -- "Master-data edit/delete<br/>(middleware: admin.write)" --> R2{Role is Admin only?}
    R2 -- No --> DENY2[403 read-only access message]
    R2 -- Yes --> OK
    ROUTE -- "Document creation<br/>(middleware: admin.create)" --> R3{Role is Admin OR<br/>Warehouse Manager?}
    R3 -- No --> DENY3[403 Access denied]
    R3 -- Yes --> OK
    ROUTE -- "Users & categories screens<br/>(middleware: admin.only.strict)" --> R4{Role is Admin only?}
    R4 -- No --> DENY4[403 administrators only]
    R4 -- Yes --> OK
    ROUTE -- Any other route --> OK[Proceed]
    OK --> SCOPE{Non-admin user?<br/>then all data queries are filtered<br/>to ONLY their assigned warehouses}
    SCOPE --> PAGE([Page rendered with<br/>no-cache headers so the back<br/>button cannot show stale data])
```

## 2.3 Logout & session protection

```mermaid
flowchart TD
    L([POST Logout]) --> L1[Destroy login session]
    L1 --> L2[Regenerate CSRF token]
    L2 --> L3["Instruct browser to clear cached<br/>site data Clear-Site-Data header"]
    L3 --> L4([Back to login form])
```

Protection summary:
- Login rate limit: **10 attempts / minute** per username+IP `[AuthController.php:28-33]`
- Inactive accounts fail login exactly like wrong passwords and are also **logged out mid-session** if deactivated later `[EnsureUserIsActive.php:20-31]`
- Session ID regenerated at login; CSRF token regenerated at logout `[AuthController.php:48-50, 82-83]`
- All pages sent with `no-store` cache headers → browser back-button cannot redisplay protected pages `[NoCache.php:29-51]`

## 2.4 Files behind each step

| Flow step | File(s) |
|---|---|
| Routes `/login`, `/logout`, dashboard | `routes/web.php:24-30` |
| Login logic, rate limiting, redirects | `app/Http/Controllers/AuthController.php` (login: lines 18–77, logout: 79–91) |
| Role checks on every request | `app/Http/Middleware/AdminOnly.php` (`admin`), `AdminWriteOnly.php` (`admin.write`), `AdminCreateOnly.php` (`admin.create`), `AdminOnlyStrict.php` (`admin.only.strict`), `EnsureUserIsActive.php`, `NoCache.php` |
| Middleware registration | `bootstrap/app.php:14-28` |
| Role helper methods | `app/Models/User.php` (`isAdmin`, `isWarehouseManager`, `hasAdminAccess`, `canWrite`, `canCreate`, `canApprove`, `isWarehouseUser`) |
| Per-role dashboards | `app/Http/Controllers/DashboardController.php` + views `resources/views/dashboard/admin.blade.php`, `dashboard/warehouse.blade.php`, `dashboard/no_warehouse.blade.php` |
| Warehouse scoping of all queries | `app/Http/Controllers/Concerns/ScopesWarehouse.php` |

---

# PART 3 — ITEM & ITEM CATEGORY MANAGEMENT (Master Data)

## 3.1 Flowchart

```mermaid
flowchart TD
    A([Admin opens Item Categories]) --> B[Category list with account codes<br/>item_categories/index.blade.php]
    B --> C[Create category: label + account code<br/>unique key auto-generated from label]
    C --> D{Duplicate key?}
    D -- Yes --> D1[Suffix -1, -2 ... added automatically]
    D -- No --> E[Saved as active]
    D1 --> E
    B --> F[Add item names to catalog:<br/>description name under a category]
    F --> G{Name unique within<br/>its category?}
    G -- No --> G1[Rejected]
    G -- Yes --> H[Account code inherited<br/>from parent category automatically]
    B --> I{Delete category?}
    I -- Has stock items using it --> J["BLOCKED: 'it has items assigned to it.<br/>Deactivate it instead.'"]
    I -- Unused --> K[Deleted]
    B --> L{Delete catalog item name?}
    L -- Used by any subsidy line --> M["BLOCKED: 'already used by<br/>delivery/subsidy records.'"]
    L -- Unused --> N[Deleted]
    E & H --> O[Active categories feed the dropdowns used when<br/>creating subsidies and RIS requests]
```

## 3.2 How stock records (Items) relate to the catalog

The catalog (category + item name + account code) is only the *vocabulary*. Actual inventory rows are created automatically when a delivery is recorded (see Part 4). One catalog item can therefore have **many** stock records — one per warehouse, per unit cost, per expiry date, per originating subsidy.

Display rule: identical-looking records (same description + unit cost + ENGAS cost + expiry + warehouse) are grouped in the Items list with a **“Merged (N)”** badge showing summed quantity `[ItemController.php:92-114]`.

## 3.3 Files behind each step

| Flow step | File(s) |
|---|---|
| Category CRUD (admin only) | `app/Http/Controllers/ItemCategoryController.php` (store: 23–61, destroy guard: 83–96) |
| Catalog item CRUD (admin only) | `app/Http/Controllers/ItemCatalogItemController.php` (delete guard: 61–72) |
| Stock record list / merged badge / create / delete guards | `app/Http/Controllers/ItemController.php` (index grouping: 92–114, manual create: 135–199, delete guards: 260–294) |
| Models | `app/Models/ItemCategory.php` (60s-cached active list), `app/Models/ItemCatalogItem.php`, `app/Models/Item.php` |
| Views | `resources/views/item_categories/index.blade.php`, `resources/views/items/*.blade.php` |

---

# PART 4 — SUPPLIER / SUBSIDY / DELIVERY FLOW (detailed)

This is the **inbound** pipeline: goods arriving from a supplier into warehouses.

## 4.1 Stage 1 — Create the Subsidy *(Admin or Warehouse Manager)*

```mermaid
flowchart TD
    A([Subsidies list > New Subsidy]) --> B[Enter RIS information:<br/>RIS number, supplier, delivery date,<br/>place of delivery, remarks]
    B --> C[Add requested item lines:<br/>description from catalog, unit, category,<br/>quantity requested, optional expiry date]
    C --> D{At least 1 line and<br/>quantities valid?}
    D -- No --> B
    D -- Yes --> E{DR number already used?}
    E -- Yes --> F[System appends suffix:<br/>DR becomes RIS-1, RIS-2 ...]
    E -- No --> G[Save]
    F --> G
    G --> H[Permanent ID generated:<br/>SUB-000001 style, from database id,<br/>never reused]
    H --> I[Status = PENDING<br/>no warehouse, no unit costs yet -<br/>those are decided when goods arrive]
    I --> J[Notification sent to all admins]
```

**Important:** `quantity_requested` is the original demand and is **never recalculated** later by delivered amounts. Delivered quantities are tracked separately beside it. `[routes note: DeliverySubsidyController::store lines 143–236]`

## 4.2 Stage 2 — Record a Delivery / Dispatch batch *(Admin or Warehouse Manager)*

One subsidy can be fulfilled by **multiple partial deliveries**.

```mermaid
flowchart TD
    A([Open subsidy > Record Delivery]) --> B{Subsidy archived?}
    B -- Yes --> B1[BLOCKED - frozen subsidy]
    B -- No --> C[For EACH line being shipped enter:<br/>quantity delivered, destination WAREHOUSE,<br/>UNIT COST, ENGAS UNIT COST,<br/>EXPIRATION DATE, DR NUMBER]
    C --> D{Delivering more than still<br/>outstanding on any line?}
    D -- Yes --> D1[REJECTED: over-delivery not allowed<br/>checked per batch AND across combined batches]
    D -- No --> E{Another user submitting the same<br/>line at the same moment?}
    E -- Yes, remaining changed --> E1[Re-checked under row lock -<br/>excess rejected]
    E -- No --> F[Save delivery batch]
    F --> FORMLINE[[For each delivered line the system does:]]
    FORMLINE --> G1["Find-or-create the STOCK RECORD:<br/>reuse existing stock only if ALL match:<br/>same warehouse + description + unit + category<br/>+ unit cost ±0.001 + ENGAS cost ±0.001<br/>+ expiration date + originating subsidy"]
    G1 --> G2{Matching stock record found?}
    G2 -- Yes --> G3[ADD quantity to it]
    G2 -- No --> G4[Create new stock record:<br/>new stock number generated safely under a<br/>database lock e.g. CDO-FGP-0001]
    G3 & G4 --> H[Stamp stock record with subsidy lineage:<br/>source subsidy id/code, RIS no., DR no., status active]
    H --> I[Write STOCK CARD RECEIPT entry:<br/>reference type delivery, DR number,<br/>supplier in From/To column, new running balance]
    I --> J[Accumulate qty_delivered on the requested line]
    J --> K{Recalculate subsidy status:<br/>total delivered vs requested}
    K -- Nothing yet --> PENDING[Status stays PENDING]
    K -- Some but not all --> PARTIAL[Status = PARTIAL]
    K -- All requested delivered --> FULL[Status = FULLY_DELIVERED]
```

## 4.3 Stage 3 — Corrections and reversals *(Admin only)*

```mermaid
flowchart TD
    A([Edit subsidy]) --> B{Does it already have deliveries?}
    B -- No, full edit mode --> C[RIS number, supplier, lines freely editable;<br/>status limited to pending/cancelled]
    B -- Yes, correction mode --> D[Identity FROZEN: RIS number and supplier<br/>cannot change even from tampered requests]
    D --> E{Requested qty dropped below<br/>already-delivered?}
    E -- Yes --> E1[BLOCKED]
    E -- No --> F[Only date/place/remarks and line<br/>quantities adjusted - status recalculated]

    G([Edit one delivery batch]) --> H[Delta math per line]
    H --> I{Line moved to a different warehouse?}
    I -- Yes --> I1[Deduct old qty from old stock record,<br/>create/find record in NEW warehouse, add there]
    I -- No --> I2[Adjust quantity on same record,<br/>clamped so stock never goes negative]
    I1 & I2 --> J{Unit cost or ENGAS cost changed?}
    J -- Yes --> K[CASCADE the new cost through everything<br/>downstream: RIS lines, dispatch records,<br/>and recursively through ALL transfer chains]
    J -- No --> L[Update the stock card receipt entry<br/>in place or recreate/repoint it]
    K --> L
    L --> M[Replay running balances chronologically<br/>for every affected stock record]
    M --> N[Delivery header total forced to sum of lines;<br/>subsidy status recalculated; audit log written]

    O([Delete one delivery batch]) --> P[Reverse quantities clamped at zero,<br/>delete its stock card receipts,<br/>decrease delivered totals,<br/>resync subsidy status]
    Q([Delete whole subsidy]) --> R[Mark related transfers as FROM DELETED SUBSIDY]
    R --> S[Reverse ALL stock quantities clamped at zero]
    S --> T[Stamp descendant stock records through<br/>recursive transfer chains with deleted flag]
    T --> U[Delete now-orphaned stock records ONLY if<br/>zero quantity AND zero references anywhere]
    U --> V[Replay balances; audit trail survives]
```

Archive/Restore: archiving freezes a subsidy (**blocks new deliveries**) and flags its transfers/items “FROM ARCHIVED SUBSIDY”; restore clears the flags. Nothing is ever silently lost — lineage snapshots keep traceability even after deletion or archiving.

## 4.4 Files behind each step

| Flow step | File(s) |
|---|---|
| Routes | `routes/web.php:62-87` |
| Create subsidy | `DeliverySubsidyController::store` (lines 143–236); `SUB-` code in `app/Models/DeliverySubsidy.php:23-29`; DR duplicate check API `routes/web.php:216-218` |
| Status recalculation pending/partial/fully_delivered | `app/Models/DeliverySubsidy.php:updateDeliveryStatus` (lines 100–117) |
| Record delivery batch | `DeliverySubsidyController::storeDelivery` (lines 1113–1418) |
| Find-or-create stock record (identity rules) | `app/Models/Item.php:findOrCreateByUnitCost` (lines 265–365) |
| Safe stock-number generation | `app/Models/Item.php:generateStockNumber` (lines 367–417, MySQL GET_LOCK) |
| Auto-deactivate empty stock | `app/Models/Item.php` saving hook (lines 41–54) |
| Subsidy lineage snapshots | `app/Models/Item.php:applySubsidySnapshot` (lines 197–214) |
| Edit subsidy (correction vs full edit) | `DeliverySubsidyController::update` (lines 329–628) |
| Edit delivery batch + cascade | `DeliverySubsidyController::updateDelivery` (lines 1457–1832); cascade engine `app/Services/DeliverySubsidyCascadeService.php` |
| Delete delivery / subsidy reversal | `destroyDelivery` (1842–1900), `destroy` (630–768) |
| Archive / restore | `archive` (836–873), `restore` (880–935) |
| Audit trail view | `auditLog` action + `resources/views/delivery_subsidies/audit_log.blade.php` |
| Views | `resources/views/delivery_subsidies/*.blade.php` (create, delivery, show, edit, edit_delivery, audit_log) |

---

# PART 5 — REQUISITIONS / RIS FLOW (detailed)

RIS = *Requisition and Issue Slip* (official DSWD form). This is the **outbound** pipeline: centers/offices requesting goods. (The menu label “Augmentations” refers to this same module — there is no separate augmentation entity.)

## 5.1 Stage 1 — Create the RIS *(Admin or Warehouse Manager)*

```mermaid
flowchart TD
    A([Requisitions > New RIS]) --> B[Enter purpose + date requested]
    B --> C[Add requested item lines from catalog:<br/>description + quantity_requested]
    C --> D[Permanent ID auto-generated:<br/>ris_code RIS-000001 style]
    C --> E[Human document number:<br/>auto RIS-YYYYMM-#### format<br/>or custom unique number]
    D & E --> F[NO warehouse selected yet<br/>NO stock availability check yet -<br/>availability is validated at approval time]
    F --> G[Status = PENDING]
    G --> H[Notify all admins, center heads,<br/>supply custodians]
```

## 5.2 Stage 2 — Approve & Issue *(Admin, Warehouse Manager, Supply Custodian, Center Head — NOT Center Staff)*

```mermaid
flowchart TD
    A([Open RIS > Approve]) --> B{User allowed to approve?}
    B -- No --> B1[403 blocked]
    B -- Yes --> C[Approval screen lists each requested line<br/>with outstanding quantity]
    C --> D[Per line choose: quantity to issue NOW,<br/>the exact WAREHOUSE, the exact STOCK RECORD,<br/>and a DR NUMBER]
    D --> E{Any line issuing more than zero?}
    E -- Yes --> F[Warehouse + exact stock record +<br/>DR number REQUIRED for those lines]
    E -- No --> SKIP[Lines skipped - pure approval without issuance]
    F --> G{"Does the chosen stock record belong<br/>to the chosen warehouse?"}
    G -- No --> G1[REJECTED: record does not belong to warehouse]
    G -- Yes --> H{"Enough quantity ON THAT EXACT RECORD?<br/>available = record quantity"}
    H -- No --> I["REJECTED: 'Insufficient stock on the selected<br/>record... No other unit-cost record will be used.'<br/>system NEVER borrows a different-cost record"]
    H -- Yes --> J{Issuing more than still<br/>outstanding on the RIS?}
    J -- Yes --> J1[REJECTED]
    J -- No --> SAVE[[Save the dispatch]]
    SAVE --> K[Create dispatch record pinning the EXACT stock record<br/>with its unit cost + ENGAS cost + expiry + DR number]
    K --> L[Deduct quantity from that stock record]
    L --> M[Write STOCK CARD ISSUE entry:<br/>reference type issuance, linked to the dispatch,<br/>issuing office shown in From/To, running balance updated]
    M --> N[Recalculate RIS status from issued sums]
    N --> O1{All lines fully issued?}
    O1 -- Yes --> APPROVED[Status APPROVED - Fully Fulfilled]
    O1 -- No, some issued --> PARTIAL[Status PARTIALLY_APPROVED -<br/>displayed Partially Fulfilled]
    O1 -- None issued --> PEND[Status stays PENDING]
    APPROVED & PARTIAL & PEND --> SIG[Capture signatories:<br/>Requested By / Approved By / Issued By / Received By<br/>each with name + designation]
    SIG --> PRINT([Print official RIS form<br/>with 4 signature blocks])
```

Partial fulfillment: repeat Stage 2 anytime — each run issues additional quantities from whatever exact records are chosen.

## 5.3 Stage 3 — Corrections and reversals *(Admin only)*

```mermaid
flowchart TD
    A([Correct RIS request data]) --> B{New requested quantity below<br/>already-issued quantity?}
    B -- Yes --> B1[BLOCKED - fix dispatches first]
    B -- No --> C[Header/line corrections saved;<br/>dispatched lines cannot switch catalog item;<br/>dispatches, stock cards, inventory NEVER touched]
    C --> D[Status recomputed; audit log correction entry]

    E([Edit ONE dispatched item]) --> F{Same stock record or different one?}
    F -- Same --> G[Single net adjustment on that record]
    F -- Different --> H[Credit OLD record back, debit NEW record<br/>with availability re-checked including self-credit]
    G & H --> I[Stock card issue entry updated,<br/>or deleted-and-recreated if record moved]
    I --> J[Balances replayed both sides;<br/>fulfilment status resynced]

    K([Delete ONE dispatched item]) --> L[Quantity restored to the EXACT record it came from]
    L --> M[Its issuance stock card entries deleted]
    M --> N[Audit written BEFORE the row disappears<br/>so the trail survives]
    N --> O[Fulfilment status recomputed from remaining dispatches]

    P([Delete whole RIS]) --> Q[Reverse EVERY dispatch independently to its own record]
    Q --> R[Delete all issuance entries + rows]
    R --> S[Balances replayed]
```

## 5.4 Files behind each step

| Flow step | File(s) |
|---|---|
| Routes | `routes/web.php:89-127` |
| Number generation | `ris_code` in `app/Models/Requisition.php:27-33`; `ris_number` RIS-YYYYMM-#### in `generateRisNumber` (245–262) |
| Create RIS | `RequisitionController::store` (204–329) |
| Approval gate (who may approve) | `User::canApprove` + controller lines 856–889 |
| Issue/approve processing | `processApproval` (880–1073): exact-record lock 944–956, availability check 962–970, dispatch creation 985–994, stock card 998–1013, status 1027, signatories 1030–1039 |
| Status recalculation | `app/Models/Requisition.php:updateFulfilmentStatus` (212–243) |
| Request-only correction | `correct()` (636–811) |
| Dispatch edit/delete | `updateDispatch` (1154–1341), `destroyDispatch` (1351–1437), whole-RIS `destroy` (511–567) |
| Print RIS | `printRis` (1461–1468) + `resources/views/requisitions/print.blade.php` |
| Helper APIs | `/api/requisition-items` (items in a warehouse), `/api/requisition-description-items` — `routes/web.php:125-127` |
| Audit | `app/Models/RequisitionAuditLog.php`; view `requisitions/audit_log.blade.php` |
| Views | `resources/views/requisitions/*.blade.php` |

---

# PART 6 — STOCK TRANSFER FLOW (detailed)

Moving stock between warehouses. Two steps by design: **Request** then **Dispatch** (partial dispatches allowed).

## 6.1 Stage 1 — Create transfer request *(Admin or Warehouse Manager)*

```mermaid
flowchart TD
    A([Transfers > New Transfer modal]) --> B[Select SOURCE warehouse]
    B --> C[Select DESTINATION warehouse]
    C --> D{Source different from destination?}
    D -- No --> D1[REJECTED: must be different]
    D -- Yes --> E[Pick specific stock records belonging to the SOURCE<br/>via /api/transfer-items]
    E --> F[Enter quantity per item + unit cost]
    F --> G{Quantity available on the source record?}
    G -- No --> G1[REJECTED with explicit message]
    G -- Yes --> H[Resolve subsidy lineage:<br/>transfer inherits the originating subsidy<br/>snapshots survive even if subsidy is later deleted]
    H --> I[Transfer number TRF-YYYY-NNNN generated unique]
    I --> J[Destination slot pre-created preserving<br/>FULL stock identity incl. subsidy + cost + expiry]
    J --> K[Status = PENDING DISPATCH<br/>nothing has moved yet - dispatched qty starts at 0]
    K --> L[Admins notified]
```

## 6.2 Stage 2 — Dispatch *(Admin or Warehouse Manager)*

```mermaid
flowchart TD
    A([Open transfer > Dispatch]) --> B{Transfer already completed?<br/>double-checked again inside transaction}
    B -- Yes --> B1[BLOCKED]
    B -- No --> C{Dispatcher controls<br/>source warehouse?}
    C -- No --> C1[403 blocked]
    C -- Yes --> D[Enter dispatch date and quantity per line<br/>partial quantities allowed - 0 skips a line]
    D --> E{Quantity above remaining?}
    E -- Yes, capped silently --> F[Dispatch capped at remaining amount]
    E -- No --> F
    F --> LOCK[[Both stock records locked]]
    LOCK --> G[Deduct from SOURCE record<br/>never below zero]
    G --> H[Add to DESTINATION record<br/>subsidy identity re-propagated]
    H --> I[Accumulate dispatched quantity on the line]
    I --> J[STOCK CARD TRANSFER OUT on source:<br/>From/To shows destination warehouse]
    J --> K[STOCK CARD TRANSFER IN on destination:<br/>at the SAME unit cost, From/To shows source warehouse]
    K --> L{Recalculate status from dispatched sums}
    L -- Nothing moved --> PEND[PENDING]
    L -- Some moved --> PARTIAL[PARTIAL - Partially Dispatched]
    L -- All moved --> DONE[COMPLETED]
```

Multiple partial dispatches are supported — each creates another pair of transfer-out/transfer-in entries.

## 6.3 Stage 3 — Correction / Deletion *(Admin only)*

```mermaid
flowchart TD
    A([Edit transferred quantity]) --> B{Increasing: does SOURCE physically<br/>still hold the extra units?}
    B -- No --> B1[BLOCKED]
    B -- Yes --> C{Decreasing: does DESTINATION still hold<br/>the units being returned?}
    C -- No --> C1[BLOCKED - stock was consumed onward]
    C -- Yes --> D[Adjust BOTH sides atomically:<br/>unit cost travels with the stock]
    D --> E[Reconcile ALL transfer out/in card entries of this line:<br/>delta spread newest-first across multiple<br/>partial-dispatch entries, never negative]
    E --> F[Balances replayed at both warehouses]
    F --> G[Status resynced; audit log corrected with<br/>old/new values and inventory effect]

    H([Delete transfer]) --> I{Was any of the transferred stock<br/>ALREADY ISSUED onward from destination?}
    I -- Yes --> I1[DELETION BLOCKED with explanation<br/>system detects later movements on the<br/>destination record after its last transfer-in]
    I -- No --> J[Delete this transfer's card entries]
    J --> K[Reverse once under locks:<br/>source gets stock back, destination reduced clamped at zero]
    K --> L[Audit reversed_deleted written BEFORE deletion]
    L --> M[Balances replayed both sides]
```

## 6.4 Files behind each step

| Flow step | File(s) |
|---|---|
| Routes | `routes/web.php:138-154`; item picker API `/api/transfer-items` line 142 |
| Number generation TRF-YYYY-NNNN | `app/Models/StockTransfer.php:187-202` |
| Create request + lineage resolution + pre-created destination slot | `StockTransferController::store` (111–272) |
| Dispatch (both-side moves + dual card entries) | `dispatch`/`processDispatch` (277–440) |
| Status pending/partial/completed | `app/Models/StockTransfer.php:updateTransferStatus` (84–104) |
| Two-sided correction with newest-first card reconciliation | `update` (582–816; delta spread helper 828–853) |
| Deletion blocker + reversal | `destroy` (874–977), blocker check `destroyBlockers` (985–1022) |
| Audit | `app/Models/StockTransferAuditLog.php` |
| Views | `resources/views/transfers/*.blade.php` (index + create modal, dispatch, show, edit, print) |

---

# PART 7 — INVENTORY / STOCK MANAGEMENT & STOCK CARDS (detailed)

## 7.1 What a “stock record” is (the heart of the system)

Each row in the `items` table = one physical batch in one warehouse:

| Field | Meaning |
|---|---|
| `stock_number` | Official card number, e.g. `CDO-FGP-0001` ({WAREHOUSE}-{CATEGORY}-####) |
| `quantity` | Current on-hand quantity |
| `unit_cost` / `engas_unit_cost` | Both travel together everywhere (reports show Engas Total Value = qty × ENGAS cost) |
| `expiration_date` | Optional; part of identity |
| `source_subsidy_*` columns | Lineage: which subsidy/RIS/DR this stock came from — survives transfers and subsidy deletion |
| `is_active` | Auto-deactivates when quantity reaches 0, reactivates when stock returns |

**Identity rule:** two records are “the same stock” only if warehouse + description + unit + category + unit cost + ENGAS cost + expiry + originating subsidy ALL match. Otherwise they stay separate batches. `[Item::findOrCreateByUnitCost, Item.php:265-365]`

## 7.2 Quantity movement map — every way stock changes

```mermaid
flowchart TD
    subgraph IN [Quantity IN]
        R1[Delivery received<br/>against subsidy] --> |"+ qty"| REC[(Stock Record)]
        T1[Transfer IN from<br/>another warehouse] --> |"+ qty"| REC
        RV1[Admin reversal:<br/>deleted RIS dispatch] --> |"qty restored"| REC
        RV2[Admin reversal:<br/>deleted transfer] --> |"qty restored to source"| REC
    end
    subgraph OUT [Quantity OUT]
        REC --> |"- qty"| I1[RIS issued to office/center]
        REC --> |"- qty"| T2[Transfer OUT to another warehouse]
        REC --> |"reversed clamped at 0"| D1[Deleted delivery /<br/>deleted subsidy]
    end
    REC --> Z{Quantity reached 0?}
    Z -- Yes --> DEACT[Record auto-deactivated<br/>reactivates automatically when stock returns]
    Z -- No --> LIVE[Stays active]
```

**There are NO manual stock adjustments.** Only real movements write to inventory.

## 7.3 Stock Card engine (automatic ledger)

```mermaid
flowchart TD
    M([Any real movement happens]) --> W[Write ONE immutable entry:]
    W --> E1["RECEIPT side: delivery, transfer_in<br/>(qty, unit cost, total, From/To counterparty)"]
    W --> E2["ISSUE side: issuance, transfer_out<br/>(qty, remaining balance, reference document)"]
    E1 & E2 --> LINK[Entry carries reference_type + reference_id<br/>linking back to its source document;<br/>RIS issues also carry the exact dispatch-item link]
    LINK --> BAL[Running balance stored on the entry]
    BAL --> X{Later edit or delete<br/>of a movement?}
    X -- Yes --> Y[Affected entries updated/deleted then<br/>recalculateBalancesForItem replays ALL entries<br/>chronologically inside a transaction:<br/>balance += receipts − issues;<br/>running unit cost = latest receipt's cost]
    X -- No --> OK[Ledger untouched]
    Y --> VERIFIED([Ledger always reconciles to actual stock])

    V([Viewing]) --> V1[Stock Cards home: summary across categories]
    V1 --> V2[Category tab: all cards in that category]
    V2 --> V3[Card history: chronological entries]
    V2 --> V4[By-unit-cost view: FIFO batch reconstruction<br/>shows which batch each issue consumed]
    V2 --> V5[Printable official stock card]
```

Warehouse protection: viewing a card requires access to that card’s warehouse (403 otherwise) `[StockCardController.php:43-45, 61, 118]`.

## 7.4 Inventory Balance report (live) *(Admin + Warehouse Manager only)*

```mermaid
flowchart TD
    A([Reports > Inventory Balance]) --> B{Admin or WM?}
    B -- No --> B1[403]
    B -- Yes --> C[Gather active stock records with quantity above zero<br/>filtered to viewer's warehouses]
    C --> D[Group hierarchy:<br/>WAREHOUSE -> CATEGORY -> ITEM DESCRIPTION -> individual stock records]
    D --> E["Total Quantity per description sums across ALL cost/expiry variants<br/>WITHIN the same warehouse only -<br/>different warehouses are NEVER combined"]
    E --> F[Each individual record still listed separately<br/>with its own stock number, cost, ENGAS cost, expiry<br/>deliberately NOT merged for traceability]
    F --> G[Category totals: qty + value]
    G --> H[Grand total per warehouse]
    H --> I([Optional Excel export])
```

## 7.5 Files behind each step

| Flow step | File(s) |
|---|---|
| Stock record identity/creation/deactivation | `app/Models/Item.php` |
| Stock card ledger model + balance replay | `app/Models/StockCardEntry.php:recalculateBalancesForItem` (38–65) |
| Who writes entries | `DeliverySubsidyController::storeDelivery` (delivery receipts), `RequisitionController::processApproval` (issuance), `StockTransferController::processDispatch` (transfer_in/out) |
| Stock card screens | `app/Http/Controllers/StockCardController.php` (summary 131–158, index 17–38, itemHistory 40–55, FIFO by-unit-cost 57–112, print 114–129) + `resources/views/stock_cards/*.blade.php` |
| Inventory Balance | `ReportController::inventoryBalance` (282–363) + export (580–688) + `resources/views/reports/inventory_balance.blade.php` |

---

# PART 8 — REPORTS

## 8.1 Report flows

```mermaid
flowchart TD
    A([Reports menu]) --> B[RPCI - Physical Count of Inventories]
    A --> C[RSMI - Supplies and Materials Issued]
    A --> D[Inventory Balance - live rollup]
    A --> E[Stock Cards]

    B --> B1[Choose warehouse + category + period]
    B1 --> B2[On-screen list grouped by category with account codes]
    B2 --> B3{Output choice}
    B3 --> B4[Printable official RPCI form]
    B3 --> B5[Excel .xlsx export]
    B3 --> B6["FREEZE SNAPSHOT: saves period + serial number + JSON data<br/>into report_snapshots so past reports never change"]

    C --> C1[Pick month/period; lists only RIS with status<br/>approved or partially_approved in range<br/>scoped by dispatch origin warehouses]
    C1 --> C2[Per RIS: qty REQUESTED vs ISSUED vs OUTstanding<br/>+ unit cost + ENGAS cost + amounts]
    C2 --> C3{Output choice}
    C3 --> C4[Printable RSMI + recapitulation by stock number]
    C3 --> C5[Excel export]
    C3 --> C6[Freeze RSMI snapshot]

    D --> D1[Live warehouse->category->item rollup<br/>Part 7.4]

    E --> E1[Chronological card / FIFO view / print]

    SNAP([View saved snapshot]) --> SNAPCHK{Snapshot belongs to a<br/>warehouse you can access?}
    SNAPCHK -- No, non-admin --> SNAPDENY[403]
    SNAPCHK -- Yes --> SNAPVIEW[Rendered frozen copy]
```

## 8.2 Files behind each step

| Report | Controller methods | Views |
|---|---|---|
| RPCI (list/print/export/snapshot) | `ReportController::rpci` (19–43), `printRpci` (45–64), `saveRpciSnapshot` (66–100), `exportRpci` (367–465) | `reports/rpci.blade.php`, `reports/rpci_print.blade.php`, `reports/snapshot.blade.php` |
| RSMI (list/print/export/snapshot) | `rsmi` (102–164), `printRsmi` (166–230), `saveRsmiSnapshot` (232–280), `exportRsmi` (467–578) | `reports/rsmi.blade.php`, `reports/rsmi_print.blade.php` |
| Inventory Balance (+export) | `inventoryBalance` (282–363), `exportInventoryBalance` (580–688) | `reports/inventory_balance.blade.php` |
| Snapshot viewing | `viewSnapshot` (690–705) | `reports/snapshot.blade.php` |
| Model | `app/Models/ReportSnapshot.php` (`report_type` rpci/rsmi, `period_month`, JSON payload) | |

---

# PART 9 — AUDIT & DATA INTEGRITY

## 9.1 Three append-only audit trails

| Log table | Recorded actions | Written when |
|---|---|---|
| `delivery_subsidy_audit_logs` | `update`, `correction`, `archive`, `restore` (+cascade summaries) | Subsidy edited/corrected/archived/restored, delivery edited |
| `requisition_audit_logs` | `correction`, `dispatch_deleted` | RIS corrected; dispatched item deleted |
| `stock_transfer_audit_logs` | `corrected`, `reversed_deleted`, `subsidy_archived`, `subsidy_restored`, `subsidy_deleted` | Transfer corrections/deletions and subsidy state propagation |

Design guarantee: audit rows about deletions are written **BEFORE** the parent row disappears, so the trail survives the deletion. Each row stores changed fields as JSON (transfers/subsidies also store the inventory effect).

## 9.2 Data-integrity rules the system enforces (QA checklist)

| # | Rule | Where enforced |
|---|---|---|
| 1 | Requested quantities are NEVER overwritten by delivered/issued/transferred amounts | Separation throughout controllers; statuses derived from sums |
| 2 | Over-delivery of a subsidy line impossible (single batch and combined batches, tolerance ε=0.0001) | `storeDelivery` 1201–1221; re-checked under row lock 1256–1262 |
| 3 | RIS issuance only from the EXACT stock record; insufficient → reject, never borrow another cost record | `processApproval` 944–970 |
| 4 | RIS availability validated at approval time, not at request time | Same block |
| 5 | Stock can never go negative — every deduction/reversal clamped ≥ 0 | All reversal paths |
| 6 | Transfer source ≠ destination | Validation + DB-level check `store` L123–134 |
| 7 | Transfer edits adjust BOTH warehouses atomically; increases require physical stock at source; decreases require units still held at destination | `StockTransferController::update` 615–671 |
| 8 | Deleting a transfer blocked if its stock was already issued onward | `destroyBlockers` 985–1022 |
| 9 | Every movement writes an immutable stock card entry; NO manual adjustments exist | Parts 4–7 pipelines |
| 10 | After any edit/delete, running balances are replayed chronologically inside a transaction | `StockCardEntry::recalculateBalancesForItem` |
| 11 | Stock keeps lineage (originating subsidy + snapshots) through transfers, subsidy deletion, and archiving (“FROM DELETED/ARCHIVED SUBSIDY” review badges) | `applySubsidySnapshot` + cascade service BFS walk |
| 12 | Deleting a subsidy hard-deletes orphan stock records only if zero quantity AND zero references | `destroy` 719–736 |
| 13 | Categories/catalog items/suppliers can’t be deleted while referenced — deactivate instead | ItemCategoryController 83–96; ItemCatalogItemController 61–72; Supplier toggle only |
| 14 | Non-admin users see/mutate ONLY their assigned warehouses — server-side on every query incl. AJAX | `ScopesWarehouse` trait used by all controllers |
| 15 | Unauthorized direct URL access returns 403 (middleware + controller gates), never silent failure | Middleware chain (Part 2.2) |
| 16 | Cost changes cascade downstream through RIS lines, dispatches, and recursive transfer chains | `DeliverySubsidyCascadeService::cascadeItemCost` |
| 17 | Unit cost and ENGAS unit cost stay separate values everywhere (never collapsed) | Identity keys, dispatch lines, cards, reports |
| 18 | Concurrent submissions serialized with row locks (`lockForUpdate`) and advisory lock for stock numbers | storeDelivery, processApproval, processDispatch, generateStockNumber |

---

# APPENDIX — Quick file index by module

| Module | Route definitions | Controller | Key models/services | Main views |
|---|---|---|---|---|
| Auth | `routes/web.php:24-26` | `AuthController` | `User`, middleware `EnsureUserIsActive`, `NoCache` | `auth/login.blade.php` |
| Dashboard | `routes/web.php:30` | `DashboardController` | — | `dashboard/{admin,warehouse,no_warehouse}.blade.php` |
| Item categories & catalog | `web.php:33-44` | `ItemCategoryController`, `ItemCatalogItemController` | `ItemCategory`, `ItemCatalogItem` | `item_categories/index.blade.php` |
| Items (stock records) | `web.php:47-59` | `ItemController` | `Item` | `items/*.blade.php` |
| Suppliers | `web.php:156-168` | `SupplierController` | `Supplier` | `suppliers/*.blade.php` |
| Warehouses | `web.php:183-196` | `WarehouseController` | `Warehouse` | `warehouses/*.blade.php` |
| Users | `web.php:198-211` | `UserController` | `User` | `users/*.blade.php` |
| Subsidies/Deliveries | `web.php:62-87` | `DeliverySubsidyController` | `DeliverySubsidy(+Item)`, `Delivery(+Item)`, `DeliverySubsidyAuditLog`, `DeliverySubsidyCascadeService` | `delivery_subsidies/*.blade.php` |
| Requisitions/RIS | `web.php:89-127` | `RequisitionController` | `Requisition(+Item,+DispatchItem)`, `RequisitionAuditLog` | `requisitions/*.blade.php` |
| Stock transfers | `web.php:138-154` | `StockTransferController` | `StockTransfer(+Item)`, `StockTransferAuditLog` | `transfers/*.blade.php` |
| Stock cards | `web.php:129-136` | `StockCardController` | `StockCardEntry` | `stock_cards/*.blade.php` |
| Reports | `web.php:170-181` | `ReportController` | `ReportSnapshot` | `reports/*.blade.php` |
| Notifications | `web.php:267-273` | `NotificationController` | `SystemNotification` | `notifications/index.blade.php` |
| AJAX helpers (throttled 120/min) | `web.php:213-265` | closures + `RequisitionController`, `StockTransferController` | — | — |

---

*Generated from the codebase: routes/web.php, bootstrap/app.php, all controllers under app/Http/Controllers, models under app/Models, services under app/Services, middleware under app/Http/Middleware, and resources/views.*
