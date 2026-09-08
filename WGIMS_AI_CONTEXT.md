# WGIMS — AI Context Document (Deep Audit v2)

> Deep read/analyze/audit of the entire codebase. No functional code was changed to produce this.
> **Audit date:** 2026-09-08 · **HEAD:** `d4edc9e` · **Installed:** Laravel 12.58.0, PHP 8.2.12 CLI
> **Method:** full read of `app/`, `routes/`, `database/migrations/` (57 files), `app/Models/` (23),
> `resources/views/`, `config/`, `composer.json`, `package.json`, `.env.example`, `docs/`.
> Start here before any major change. Companion: `AGENTS.md` (agent rules).

---

## 1. System overview

WGIMS (Welfare Goods Inventory Management System) is a multi-warehouse Laravel app that tracks
welfare goods from supplier delivery through stock, reservation, issuance (RIS), inter-warehouse
transfer, and ledger/reporting. Core idea: **every physical stock pile is an exact `items` record
identified by warehouse + description + unit + category + costs + expiry + origin subsidy.**
All movements (receipts, issues, transfers) write `stock_card_entries` and recompute running balances.
Roles are a plain `users.role` string enforced by custom middleware + a warehouse-scoping trait.

Authoritative sources: `routes/web.php` (sole route file, ~106 routes) · `app/Http/Controllers/`
(16 controllers + `Concerns/ScopesWarehouse` trait) · `app/Models/` (23) ·
`app/Services/DeliverySubsidyCascadeService.php` (only service) · `database/migrations/` (57) ·
`resources/views/layouts/app.blade.php` (sole layout, all CSS/JS inline) · `docs/` (ARCHITECTURE,
DATABASE_SCHEMA, BUSINESS_RULES, WORKFLOWS, SECURITY, KNOWN_ISSUES, etc. — read them too).

---

## 2. Laravel version

- **Installed:** Laravel Framework **12.58.0** (`php artisan --version`); **requires** `laravel/framework ^12.0`, `php ^8.2` (`composer.json`).
- No `app/Http/Kernel.php` (correct for Laravel 11/12 — middleware registered in `bootstrap/app.php`).
- No `routes/api.php`. All `/api/*` URIs are **session-authenticated web routes**, not stateless APIs.

## 3. Technology stack

| Layer | Technology (verified, not guessed) |
|---|---|
| Language | PHP 8.2+ (CLI observed 8.2.12) |
| Framework | Laravel 12 |
| Database | **SQLite default** (`DB_CONNECTION=sqlite`, DB sessions/cache/queue); MySQL supported via `config/database.php` |
| Sessions/Cache/Queue | Database drivers (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`) |
| Frontend | Blade only. **No Alpine/Livewire/Vue/React.** `resources/js/app.js` = `import './bootstrap'` (axios + X-Requested-With) |
| CSS | Custom properties in layout `<style>` (`--primary:#0284c7`, `.btn/.badge/.alert/.card`, 11px tables). `resources/css/app.css` is only Tailwind v4 import; **layout never calls `@vite`**, so the Tailwind build is unused at runtime. Only `auth/login.blade.php` uses Tailwind (CDN) and `welcome.blade.php` uses `@vite` |
| Icons/Fonts | Font Awesome 6.5 CDN, Inter (Google Fonts); Chart.js CDN on admin dashboard only |
| Excel | PhpSpreadsheet (`phpoffice/phpspreadsheet ^5.7`) for RPCI/RSMI/Balance exports |
| Packages | `spatie/laravel-permission` **installed but 100% unused** (no `HasRoles` trait, no role/permission middleware calls, permission tables created then **dropped** in `2026_08_13_100001`); `laravel/tinker`, `laravel/pint`, `laravel/pail`, `laravel/sail` |
| Auth | Single `web` session guard, login via **`username`** (`User::getAuthIdentifierName()` returns `username`), `is_active=true` required, `remember_token` supported |
| AuthZ | Custom middleware `admin` / `admin.write` / `admin.create` / `admin.only.strict` + `ScopesWarehouse` trait + in-method `canWrite()/canApprove()/hasAdminAccess()` checks |
| Mail/Broadcast | `log` drivers (no real mail). Scheduler: only `inspire` + `reservations:expire` daily 00:05 |

## 4. Module overview

| Module | What it does |
|---|---|
| **Dashboard** (`DashboardController`) | Dual view: `dashboard.admin` (all warehouses: balance by warehouse×category, unliquidated pending/partial, totals, monthly activity chart, reservation stats) vs `dashboard.warehouse` (scoped). Inventory Balance is a **separate** report — not the same as dashboard |
| **Items** (`ItemController`, read-only) | Stock listing (filters: search/category/warehouse/stock_status/source_subsidy_status, paginate 20) + detail with stock cards. **No create/edit/delete routes — stock enters only via deliveries/transfers** |
| **Delivery Subsidies** (`DeliverySubsidyController`, 1793 lines, largest) | Create request (`store`, no cost/warehouse/stock yet) → record shipments (`storeDelivery`, stock IN) → edit/delete subsidy or single delivery, audit log |
| **Requisitions / RIS** (`RequisitionController`, 1690 lines) | Create description-level request (no stock linked) → approve/dispatch against **exact** stock records (`processApproval`) → edit/correct/delete, per-dispatch edit/delete, signatories, print (Appendix 63) |
| **Stock Transfers** (`StockTransferController`, 1045 lines) | Two-step: plan (`store`, `quantity_requested`, dest slot pre-created) → dispatch (`processDispatch`, `quantity` moved, `transfer_out/in` cards) → edit (delta) / delete (blocker-checked) |
| **Reservations** (`ReservationController`, multi-item) | Soft-lock stock (`reservation_items`, no qty change, no cards) → approve → ready → deploy **only via RIS dispatch with `reservation_item_id`** → cancel/expire/delete |
| **Stock Cards** (`StockCardController`) | Per-item ledger reads + PHP FIFO-by-cost view + print (Appendix 9). Writes happen only in subsidy/RIS/transfer controllers |
| **Reports** (`ReportController`) | RPCI (active items snapshot), RSMI (dispatch-based, per-RIS groups + ENGAS subtotals), Inventory Balance (admin/WM only, one row per stock record, **no merging**), PhpSpreadsheet exports, JSON snapshots |
| **Item Categories** (`ItemCategoryController` + `ItemCatalogItemController`, admin-only) | `item_categories` (key/label/account_code) → `item_catalog_items` (name unique per category, account_code inherited). Block delete if referenced |
| **Warehouses** (`WarehouseController`) | `warehouses` CRUD (no destroy route). `code` unique → used in stock-number prefix |
| **Users** (`UserController`, admin-only) | 5 roles, pivot `user_warehouse` assignments; admin/WM get `warehouse_id=null` + empty pivot (means ALL) |
| **Suppliers** (`SupplierController`) | Supplier master for subsidies |
| **Notifications** (`NotificationController`) | Own notifications, AJAX top-10 + 30s polling, mark-read (ownership-checked) |

## 5. Database architecture

- **57 migrations.** **No soft deletes anywhere** (no `deleted_at`, no `SoftDeletes` trait). **No native ENUMs** — all statuses are plain strings with app-level constants.
- **Key tables:** `warehouses` · `user_warehouse` (composite PK, no timestamps) · `suppliers` · `item_categories` · `item_catalog_items` (unique `category+name`) · `items` (central stock) · `delivery_subsidies` · `delivery_subsidy_items` (`qty_delivered` cumulative) · `deliveries` · `delivery_items` (per-item DR, ENGAS) · `requisitions` (`ris_number` user ref + `ris_code` system id, both unique) · `requisition_items` (**cost columns dropped** in `2026_08_24_210730` — costs live only on dispatches) · `requisition_dispatch_items` (exact `item_id`, per-dispatch DR/costs, `reservation_item_id`, `dispatch_item_id` link from stock cards) · `stock_transfers` (+ subsidy snapshot columns surviving delete) · `stock_transfer_items` (`quantity_requested` planned vs `quantity` dispatched) · `reservations` (legacy single-item cols kept nullable) · `reservation_items` (authoritative lines) · `stock_card_entries` · `system_notifications` · `report_snapshots` · 3 audit-log tables (subsidy logs use `nullOnDelete` so delete-audits outlive the subsidy; transfer logs snapshot `transfer_number`) · Laravel `sessions/cache/jobs` tables.
- **Dropped/removed:** Spatie permission tables, `password_reset_tokens`, `users.email_verified_at`, `delivery_subsidies.is_archived`, `requisition_dispatch_items.warehouse_id` (warehouse derived via `item`), `requisition_items.unit_cost/engas/dr/expiry`.
- **FK behavior that matters:** `items.warehouse_id` **cascade**; `delivery_subsidy_items.item_id` / `requisition_items.item_id` **nullOnDelete**; `stock_transfer_items.item_id/destination_item_id` **cascade**; `stock_card_entries.item_id` **cascade** but `dispatch_item_id` **nullOnDelete**; `reservations`/`reservation_items` cascade to warehouse/item; `stock_transfers.transferred_by` **cascade** (deleting a user deletes their transfers — flag).
- **Indexes:** unique `stock_number/ris_number/ris_code/transfer_number/dr_number/subsidy_code/reservation_number`; composites `items(warehouse,is_active,category)`, `items(warehouse,unit,category,unit_cost)`, `items(source_subsidy,warehouse)`; `sce(item,entry_date,id)`, `sce(reference_type,reference_id)`; `notif(user,is_read,created_at)`.
- **Seeders:** only `DatabaseSeeder` (4 warehouses `RX-MO/CFA/RC/YC`, 4 users incl. `admin`, 2 suppliers). Categories seeded via migration.

## 6. Important models

`Item` (identity, `findOrCreateByUnitCost`, `generateStockNumber` via `GET_LOCK`, `available_quantity = quantity − reserved`, auto `is_active` by qty) · `DeliverySubsidy` (`updateDeliveryStatus`, boot `SUB-000001`) · `DeliverySubsidyItem` (per-warehouse+cost dispatch summary accessors) · `Delivery/DeliveryItem` (ENGAS total derived) · `Requisition` (`updateFulfilmentStatus` recomputes `quantity_issued` from dispatch sums, `generateRisNumber` via `GET_LOCK RIS-YYYYMM`, `warehouseNames/Ids` from dispatches) · `RequisitionItem` (no costs — see §5) · `RequisitionDispatchItem` (**cost source of truth**) · `StockTransfer` (`updateTransferStatus`, `generateTransferNumber` via lock) · `StockTransferItem` (requested vs dispatched) · `Reservation/ReservationItem` (UPPERCASE statuses; `reservedQuantityForItem` sums **full** `reserved_quantity` for ACTIVE+PARTIALLY_DEPLOYED — conservative over-lock after partial deploy) · `StockCardEntry` (`recalculateBalancesForItem`: chronological replay in own transaction; receipt sets running cost) · `User` (role helpers; `admin/WM→null` = all warehouses) · `ItemCategory` (cached `allActive`, string-key join to items — **no DB FK**) · `ItemCatalogItem` · `Warehouse/Supplier/SystemNotification/ReportSnapshot`/3 audit models.
Missing inverses (do not assume): `ItemCatalogItem` has no `requisitionItems` relation; `StockCardEntry` has no `dispatchItem()` relation despite the FK.

## 7. Important controllers

`DeliverySubsidyController` (subsidy+delivery lifecycle, BFS `transferLineageItemIds` on delete, `downstreamUsageWarning`) · `RequisitionController` (exact-record issuance, reservation credit-back, fulfilment recompute) · `StockTransferController` (two-step transfer, `spreadDeltaAcrossEntries` newest-first, `destroyBlockers`) · `ReservationController` (lock-checked create, state machine, dispatch-prefill APIs) · `ReportController` (RPCI/RSMI/Balance + exports + snapshots) · `DashboardController` (dual dashboard, reservation stats, chart) · `StockCardController` (reads + PHP FIFO view) · `Item/Category/Catalog/Supplier/Warehouse/User/Notification/Auth` as §4.
Only service: `DeliverySubsidyCascadeService` (transfer-chain-aware cost/RIS/supplier propagation; `cascadeUnitCost` is **stale** — still writes dropped `requisition_items.unit_cost`, use `cascadeItemCost`).

## 8. Route structure

Single `routes/web.php` (~106 routes + `/up` health). Global `web` = `EnsureUserIsActive` + `NoCache` (authed GETs use `private,no-cache,must-revalidate` — bfcache-safe). Route order is correct (statics before wildcards). Key map: see `AGENTS.md` table. Deltas found in audit:
- Transfers edit/update/delete use **`admin`** (allows WM) with controller `canWrite()` (admin-only) as the real gate — tighten route to `admin.write`.
- Reservation approve/ready/cancel + destroy-item are `auth`-only routes gated by controller `canWrite()` = **admin-only**, contradicting `User::canApprove()` (which advertises WM/head/custodian). RIS dispatch is the only place `canApprove` actually opens doors.
- `POST /reports/*/snapshot` are `auth`-only with **no role check** — read-only `center_staff` can write snapshots.
- `POST /logout` sits **outside** the `auth` group. `welcome.blade.php` references a **nonexistent `register` route** (latent 500 if rendered).
- View-vs-submit splits confuse approvers: `GET delivery`/`GET transfers/dispatch` allow head/custodian but their POSTs are `admin.create` (403 on submit); RIS dispatch *correction* is admin-only while dispatch itself allows approvers.
- `AuthController` non-admin intended-URL allowlist is incomplete (bookmarks → 403 instead of clean redirect; not a bypass).

## 9. Inventory flow

```
SUBSIDY request (no stock) → DELIVERY dispatch (stock IN via findOrCreate, receipt card)
  → RESERVATION (soft lock, no qty change, no card)
  → RIS request (no stock) → RIS DISPATCH (stock OUT, issue card, deployed+=)
  → TRANSFER plan (no move) → TRANSFER dispatch (source−/dest+, out/in cards)
  → STOCK CARDS (replayed balances) → BALANCE/RPCI/RSMI (reads)
```

## 10. Subsidy flow

`store` (validates request lines only; `dr_number = ris_number` + `-N` loop, **no lock — race**; header `warehouse_id=null,total=0`; `SUB-` code via boot) → `storeDelivery` (per-line warehouse/cost/DR required only for remaining lines; DSI **locked + remaining rechecked**; stock via `findOrCreateByUnitCost` then **exact item re-locked**; `warehouse_id` set **only on first dispatch**; `unit_cost/ris_number` **overwritten** on shared record; receipt card `reference=per-item DR`; `total=Σamount`; status pending/partial/fully_delivered) → `updateDelivery` (same-item delta **no availability check**, decreases `max(0,…)`-clamped; cross-warehouse reverses+recreates; `cascadeItemCost`; cards moved; both items recalculated) → `destroyDelivery` (clamped reversal) → `update` (no deliveries = full rebuild; else correction-only, delivered lines locked `≥qty_delivered`, RIS/supplier/DR frozen) → `destroy` (flags transfers `deleted`, clamped reversals, snapshots `deleted`, deletes cards/deliveries/lines, hard-deletes zero-qty unreferenced items, recalcs; `downstreamUsageWarning` is per-item queries — N+1).

## 11. RIS flow

`store` (catalog lines only; unit from any same-description stock for display; **no stock check, no cost, no reservation link**; `intended_requisition_id` never written — dead link; `RIS-YYYYMM-NNNN` via lock + `RIS-000001` boot) → `processApproval` (**exact-record** `lockForUpdate`, no FIFO fallback; `stillNeeded` guard; `available = physical − reserved` **+ credit-back** of linked reservation line; creates dispatch with per-line costs/DR, deducts, issues card with `dispatch_item_id`, `deployed+=` under lock, `quantity_issued` cache, fulfilment pending/partially_approved/approved) → `updateDispatch` (reverse-old/apply-new; availability formula **ignores reservations** — inconsistent with create; card moved; deployed delta) → `destroyDispatch` (restores exact record, deletes by `dispatch_item_id`, reverses deployed) → `update`/`correct` (dispatched lines `≥issued`, no catalog change; never touch dispatches) → `destroy` (reverses to exact records, deletes issue cards, reverses deployed).

## 12. Reservation flow

`store` (exact item locked; `available = qty − reservedQuantityForItem`; cost/expiry **snapshotted**; header PENDING, lines ACTIVE, `RES-000001` via lock) → `approve` PENDING→RESERVED → `markReady` →READY_FOR_REQUISITION → consumed via RIS dispatch `reservation_item_id` (`deployed+=`, ACTIVE→PARTIALLY_DEPLOYED→DEPLOYED; header all-deployed→DEPLOYED etc.) → `cancel`/`expire` (scheduled `reservations:expire` 00:05) → `destroy` (admin, refused if any `deployed>0`). RIS-side prefill APIs (`active`, `{id}/items` with `max_deployable=min(remaining, physical−reserved)`, `for-dispatch?description=` with full identity) are frontend-only — **creation has no reservation link**. Gaps: **no check that `reservation_item_id` matches dispatched `item_id`/warehouse**; **no over-deploy check vs `remaining`**; credit-back read **unlocked**; `reservedQuantityForItem` over-locks after partial deploy (sums full reserved, not net). Rule: `AVAILABLE = ON_HAND − RESERVED`; reserved stock is invisible to non-reservation dispatches.

## 13. Stock transfer flow

`store` (validates `from≠to`, item∈source, `qty ≤ physical` on an **unlocked read ignoring reservations — TOCTOU**; `TRF-YYYY-NNNN` via lock; subsidy link + snapshots; dest slot pre-created via `findOrCreateByUnitCost`; `requested=qty, quantity=0`; no move, no cards) → `processDispatch` (`dispatchQty=min(submitted,remaining)` **silent cap**; source+dest locked; `source=max(0,−qty)` **no availability check**, dest+=; `transfer_out/in` cards `reference=transfer_number`; status pending/partial/completed) → `update` (real both-side guards; `spreadDeltaAcrossEntries` newest-first; dest `unit_cost` overwritten; **ENGAS not editable here**) → `destroy` (`destroyBlockers` refuses if any later dest card exists; else reverses with locks, `max(0,…)` on dest). Costs inherit source cost/ENGAS/expiry at creation.

## 14. Stock card flow

Written **only** by real movements: delivery receipt (`reference=per-item DR`, `from_to=supplier`) · RIS issuance (`reference=ris_number` + `dispatch_item_id`) · transfer out/in (`reference=transfer_number`). Every edit/delete path deletes/moves entries then calls `recalculateBalancesForItem` (own transaction, `entry_date,id` order, `running += receipt − issue`, cost seeded from item then last receipt). Never create manual/duplicate entries. FIFO-by-cost view is PHP display-only.

## 15. Inventory Balance logic

`ReportController@inventoryBalance` (+export), **admin/WM only**. Read-only: `is_active + quantity>0` items with filters, eager warehouse, **one row per stock record (never merged)**, PHP groups warehouse→category→description with per-name `Σqty`, `Σqty×unit_cost`, grand total. **Physical only (reservations ignored); ENGAS shown per row, never totaled; full table into memory (no pagination — scale risk).** Dashboard ≠ Balance: dashboard aggregates + reservation stats + charts; Balance is the auditable ledger view. Do not change Balance unless explicitly instructed.

## 16. Warehouse logic

Every `items` row belongs to exactly one `warehouse_id`. Header `warehouse_id` on subsidies/RIS is legacy/often-null — **scoping happens at line/dispatch level** via complex `orWhereHas` chains. `ScopesWarehouse`: admin/WM → `null` (all); others → pivot + legacy id; empty → `1=0` (nothing). Same name + different warehouse/cost/expiry/subsidy = **different records**. Transfers are the only sanctioned cross-warehouse move (with snapshot propagation).

## 17. Stock identity rules

`Item::findOrCreateByUnitCost` is the ONLY sanctioned resolver. Identity = `warehouse + description + unit + category + unit_cost(±0.001) + engas(null-safe) + expiry(null-safe, applied only when the new line has one) + source_subsidy(null-safe)`; active record (`stock_number NOT NULL`) → inactive placeholder (activate + number) → create (`{WH_CODE}-{CAT3}-NNNN` via `GET_LOCK`). Account code synced, never identity. Caveats: first search **ignores ENGAS** (placeholder branch adds it); number generation is locked but find-then-create is not (race); AGENTS.md statement is the normative rule — never merge across differing subsidy/cost/expiry/warehouse.

## 18. Cost calculation rules

`items.unit_cost` (current) + `engas_unit_cost` (nullable); delivery lines carry both + `engas_total = qty×engas` (server-computed); dispatch rows are the **sole** RIS cost store (per-dispatch DR/cost/expiry); transfer lines carry `unit_cost` only; RSMI recap uses weighted-average costs per dispatched stock_no. Cost edits on deliveries propagate via `DeliverySubsidyCascadeService::cascadeItemCost` (dispatch snapshots + transfer chain). `items.quantity` model cast is **integer** while DB is `decimal(15,4)` — fractional precision is truncated in PHP.

## 19. Quantity calculation rules

`items.quantity` = physical (single writer pattern under `lockForUpdate`) · `available = quantity − Σ active reservation_items.reserved_quantity` (4+ implementations — keep consistent; edit-dispatch path omits reservations) · `qty_delivered` cumulative per subsidy line (remaining = requested − delivered) · `quantity_issued` cached per RIS line (recomputed from dispatch sums on fulfilment sync) · transfer `quantity_requested` (plan) vs `quantity` (moved). Negative-stock posture: RIS-create and reservation-create **reject**; transfer-edit **guards**; delivery-edit/delete, subsidy-delete, transfer-dispatch use **`max(0,…)` silent flooring** instead of reject — do not rely on clamps as validation.

## 20. User roles and permissions

| Role | View (assigned WH) | Create | Edit/Delete | Approve RIS | Reservation approve/ready/cancel | Notes |
|---|---|---|---|---|---|---|
| `admin` | all | Y | Y | Y | Y (only role — `canWrite`) | full access; users + categories |
| `warehouse_manager` | all | Y | N | Y | N | create, no mutate |
| `supply_custodian` | scoped | N | N | Y | N | dispatch RIS, no correction |
| `center_head` | scoped | N | N | Y | N | approve, no dispatch submit |
| `center_staff` | scoped | N (+explicit blocks) | N | N | N | read-only (but can write report snapshots — §8 gap) |

`Gate 'admin-only'` defined, never used. Balance/RPCI-export need `hasAdminAccess` (admin/WM). `canApprove` = admin/WM/head/custodian.

## 21. UI conventions

Sole layout `layouts/app.blade.php` (~1615 lines, inline CSS/JS): sidebar 190px (Core/Inventory/Procurement/Reports/Admin sections, off-canvas ≤1024px) → 40px sticky topbar (title, warehouse pill, notif bell) → 12px page content. Conventions: 11px compact tables + hover + sticky modal theads + mobile card-mode via `data-label`; full-screen `.modal-overlay/.modal-shell/.modal-body(scroll)/.modal-footer` (+480–640px small + 460px subsidy-detail variants); `.form-control/.form-row.cols-2/3/4/.form-section-label/.req/.hint`; `.btn(.btn-sm)+variants`, `.badge-*/.alert-*/.card/.stat-card`; global **SS combobox** enhances every `<select>` (portal panel, 100-render cap, `SS.sync/refresh`, `MutationObserver`) + separate autocomplete for description inputs; notifications `fetch(unread)` after 2s + 30s poll; validation via session alerts + `@error/is-invalid` (+ modal reopen on `$errors`); standalone print docs (RIS/ledger/transfer/RPCI/RSMI) with paper-size toolbar + `@media print` chrome-hiding; custom Bootstrap-4 pagination partial used in 11 indexes.

## 22. Important business rules

1. Never merge across differing warehouse/subsidy/unit-cost/ENGAS/expiry (see §17).
2. `available = physical − reserved`; reserved stock serves only its reservation.
3. Stock enters only via delivery/transfer-dispatch; leaves only via RIS-dispatch/transfer-dispatch.
4. Exact-record issuance — no FIFO fallback on write (FIFO view is display-only).
5. Every multi-step mutation in `DB::transaction` + `lockForUpdate` on exact records + `recalculateBalancesForItem` after card changes.
6. Statuses recomputed, never set manually (subsidy pending/partial/fully_delivered; RIS pending/partially_approved/approved; transfer pending/partial/completed; reservation UPPER/lowercase chains per §6).
7. Header warehouse fields are legacy; line/dispatch warehouses are authoritative.
8. DR numbers are per-item (delivery) / per-dispatch (RIS); RIS header DR stays null.
9. Deletion = reversal (restore exact records, delete cards, recompute) with guards: subsidy traces transfer lineage; transfer refuses on downstream use; reservation refuses if deployed; catalog/category refuse if referenced.
10. Advisory `GET_LOCK`s for stock/RIS/transfer/reservation numbers; DR-number generation has **no lock**.
11. `is_active` auto-managed by quantity (placeholder rows have no stock_number).

## 23. Known bugs/issues

1. `max(0,…)` silent flooring (delivery edit/delete, subsidy delete, transfer dispatch) hides over-draws — prefer reject with message.
2. Transfer dispatch: no source-availability check, ignores reservations; `min()` silently caps over-dispatch.
3. Transfer `store` pre-checks unlocked + ignore reservations (TOCTOU).
4. Dispatch edit availability ignores reservations (inconsistent with create).
5. No `reservation_item_id ↔ item_id`/warehouse match validation; no over-deploy vs `remaining` check; credit-back read unlocked.
6. `reservedQuantityForItem` over-locks after partial deployment (sums full reserved, not net of deployed).
7. `cascadeUnitCost` stale — writes dropped `requisition_items.unit_cost`; use `cascadeItemCost`.
8. Transfer edit cannot change ENGAS.
9. DR-number generation race (check-then-insert, max 100 tries, no lock); `findOrCreateByUnitCost` find-then-create race.
10. `items.quantity` int-cast truncates `decimal(15,4)` fractions.
11. Auth gaps (§8): `/api/transfer-items` + `/api/item-stock-card` lack warehouse checks; `/api/check-dr` leaks existence; description-items aggregates globally; snapshot POSTs role-free; `transferred_by cascade` deletes transfers with users.
12. `welcome.blade.php` → missing `register` route (latent 500); `POST /logout` outside `auth`.

## 24. Important dependencies

`laravel/framework ^12.0` · `php ^8.2` · `phpoffice/phpspreadsheet ^5.7` (exports) · `spatie/laravel-permission ^6.25` (**unused — removal candidate**) · dev: `phpunit ^11.5.50`, `pint`, `pail`, `sail`, `faker`, `collision`, `mockery` · npm: `vite ^7`, `tailwindcss ^4` (+vite plugin, effectively unused at runtime), `axios`, `concurrently` · CDN: FA 6.5, Chart.js, Tailwind (login only).

## 25. Testing procedures

- Suite: `tests/Feature/` — **23 tests** covering subsidy deletion reversal, delivery edit, dispatch edit, exact-record issuance, transfer correction/requested-vs-dispatched, subsidy lineage, reservation/RIS isolation, balance report, auth audit.
- Run: `composer test` (= `config:clear` + `artisan test`) or `php artisan test`. DB safety: tests use their own DB handling — never run `migrate:fresh/db:wipe/truncate` against the dev/prod SQLite file.
- Manual QA per change: exercise create→dispatch→edit→delete for the touched flow; verify stock cards + balances + reservation availability + warehouse scoping for a non-admin user; check logs (`storage/logs/laravel.log`) + browser console.

## 26. Things an AI agent MUST NOT do

1. Run `migrate:fresh` / `migrate:refresh` / `db:wipe` / `truncate` / `drop table` / destructive seeders on any real database.
2. Modify existing migrations that have run in production — new migration only.
3. Merge/split stock records outside `findOrCreateByUnitCost` semantics (§17).
4. Write to dropped columns (`requisition_items.unit_cost/engas/dr/expiry`) or call stale `cascadeUnitCost`.
5. Change quantities without `lockForUpdate` + transaction + `recalculateBalancesForItem` + downstream check (cards, reservations/deployed, dispatches, transfers, reports).
6. Touch Inventory Balance logic unless explicitly instructed (Dashboard ≠ Balance).
7. Bypass server-side authZ (UI hiding ≠ security); respect §20 matrix incl. reservation-admin-only nuance.
8. Add `no-store` to authed GETs (breaks bfcache — use `private,no-cache,must-revalidate`).
9. Change `source_subsidy_id` without its snapshot columns (`_code/_ris/_dr/_status`).
10. Resolve merge conflicts by blindly picking ours/theirs — overlapping inventory views need semantic merge.
11. Expose secrets/keys in logs or responses; invent relationships, rules, or numbers — mark `UNKNOWN — REQUIRES VERIFICATION`.
12. Modify unrelated modules in one change; install/upgrade/downgrade dependencies unasked.

## 27. Things an AI agent SHOULD reuse

`Item::findOrCreateByUnitCost()` · `generateStockNumber/RisNumber/TransferNumber/ReservationNumber` · `StockCardEntry::recalculateBalancesForItem()` · `updateDeliveryStatus/updateFulfilmentStatus/updateTransferStatus/updateOverallStatus` · `ScopesWarehouse` trait · `DeliverySubsidyCascadeService::cascadeItemCost + recordAudit` · audit-log models · `downstreamUsageWarning/transferLineageItemIds/destroyBlockers` reversal guards · SS combobox + modal/form/table/alert/print conventions (§21) · pagination partial · notification helper · `docs/` (BUSINESS_RULES, WORKFLOWS, DATABASE_SCHEMA, SECURITY, KNOWN_ISSUES) · existing 23 feature tests as behavior specs.

## 28. Important files and their responsibilities

| File | Responsibility |
|---|---|
| `app/Models/Item.php` | Stock identity, find-or-create, stock numbers, available-qty |
| `app/Models/Requisition.php` + `RequisitionDispatchItem.php` | Fulfilment, RIS numbers; sole RIS cost store |
| `app/Models/DeliverySubsidy.php` + `DeliverySubsidyItem.php` | Subsidy status, `qty_delivered`, dispatch summaries |
| `app/Models/Reservation.php` + `ReservationItem.php` | Reservation state, deployed tracking |
| `app/Models/StockCardEntry.php` | Balance replay |
| `app/Models/StockTransfer.php` + `StockTransferItem.php` | Transfer status/numbers, requested vs dispatched |
| `app/Http/Controllers/DeliverySubsidyController.php` | Subsidy/delivery lifecycle + lineage delete |
| `app/Http/Controllers/RequisitionController.php` | RIS lifecycle + exact-record dispatch |
| `app/Http/Controllers/StockTransferController.php` | Transfer plan/dispatch/correct/delete |
| `app/Http/Controllers/ReservationController.php` | Reservation lifecycle + dispatch-prefill APIs |
| `app/Http/Controllers/ReportController.php` | RPCI/RSMI/Balance/exports/snapshots |
| `app/Http/Controllers/DashboardController.php` | Dual dashboard + charts |
| `app/Services/DeliverySubsidyCascadeService.php` | Cost/RIS/supplier cascade (use `cascadeItemCost`) |
| `app/Http/Controllers/Concerns/ScopesWarehouse.php` | Warehouse scoping for every list query |
| `app/Http/Middleware/Admin*.php` + `EnsureUserIsActive.php` + `NoCache.php` | Role gates, active-check, cache headers |
| `routes/web.php` | Full route map (~106) |
| `resources/views/layouts/app.blade.php` | Sole layout: CSS system, SS combobox, modal/table/form/print patterns |
| `database/migrations/` (57) | Schema truth (no soft deletes, string statuses) |
| `tests/Feature/` (23) | Behavior specs for critical flows |
| `docs/` | ARCHITECTURE, DATABASE_SCHEMA, BUSINESS_RULES, WORKFLOWS, SECURITY, TESTING_GUIDE, user manual |
| `AGENTS.md` | Agent rules (read with this file) |

---

**Gaps marked UNKNOWN — REQUIRES VERIFICATION:** production `DB_CONNECTION` value (repo default is sqlite; confirm host `.env` before any MySQL-specific SQL such as `GET_LOCK` behavior on SQLite); whether global aggregation on `/api/requisition-description-items` is intentional disclosure; whether `center_staff` snapshot-writing is intentional.
