# WGIMS QA Survival Guide

> **Purpose:** You are the system owner facing QA. They will click features and ask
> *"what does this do?"*, *"where is that code?"*, *"what happens if…?"*.
> This guide maps **every function → its exact code location → a plain-English answer**.
> Companion (concepts): `WGIMS_CODE_EXPLAINED.md`. Technical depth: `WGIMS_AI_CONTEXT.md`.
> Line numbers verified at HEAD `a4db5e2`. If code moved since, search the function name —
> names rarely change.

---

## 0. How a QA session usually goes (and your game plan)

1. They ask you to **demo the happy path**: receive goods → request → issue → transfer → reports. (Demo script in §5.)
2. They test **guards**: over-issue, delete things, wrong warehouse, expired logins.
3. They ask **"where is this implemented?"** → answer with file + function from §2 tables.
4. They ask **"what if…?"** → most answers are in §3. The honest fallback (totally acceptable):
   *"That path is covered by our automated test <name> — 204 tests, all passing. I can show you the test and the code it exercises."*

**Three golden sentences that answer 80% of questions:**
- *"Stock only moves on delivery (up), RIS issue (down), and transfer dispatch (sideways) — everything else is paperwork."* → `DeliverySubsidyController@storeDelivery:974`, `RequisitionController@processApproval:962`, `StockTransferController@processDispatch:302`
- *"Every multi-step change runs all-or-nothing in a database transaction with row locks, then rebuilds the stock-card balances."* → `DB::transaction` + `lockForUpdate()` in those same three functions; `StockCardEntry::recalculateBalancesForItem` (`app/Models/StockCardEntry.php:38`)
- *"Available = on-hand minus reserved; reserved goods can't be issued to anyone else."* → `Item.php:163`, `ReservationItem.php:87`

---

## 1. Find any code in 30 seconds (cheat sheet)

| QA asks about… | You open… |
|---|---|
| A page / button / form | `resources/views/<module>/` (same name as the menu, e.g. `requisitions/approve.blade.php`) |
| What happens on click | `routes/web.php` — find the address → it names `Controller@method` |
| The logic | `app/Http/Controllers/<Name>Controller.php` — find the method (§2 gives line numbers) |
| The data rules | `app/Models/<Thing>.php` — status changes, totals, code generation |
| Who is allowed | The route's middleware word (`admin.create` etc.) + the controller's first lines (`canWrite()` / `canApprove()`) |
| Proof it works | `tests/Feature/` — file names say what they prove (e.g. `ReservationDispatchGuardsTest.php`) |

---

## 2. Function → code map (the core of this guide)

Format: **Function** — one-line meaning → `route name` → `Controller@method:line` → model/view/test.

### Login, home, stock viewing

| Function | Route | Code | View / Test |
|---|---|---|---|
| Login / logout | `login`, `login.post`, `logout` | `AuthController@login` (10 tries/min lockout, needs active account) | `auth/login.blade.php` |
| Dashboard (admin + warehouse versions) | `dashboard` | `DashboardController@index` — 4×4 stat rows + bar chart | `dashboard/admin.blade.php`, `dashboard/warehouse.blade.php` / `WarehouseDashboardTest.php` |
| Browse / search stock | `items.index` | `ItemController@index` — filters, never edits | `items/index.blade.php` |
| Open one pile + its diary | `items.show` | `ItemController@show` (+ warehouse access check) | `items/show.blade.php` |

### Subsidies & deliveries (stock IN)

| Function | Route | Code | View / Test |
|---|---|---|---|
| Create subsidy expectation | `delivery_subsidies.store` | `DeliverySubsidyController@store:153` — header + lines only, **no stock yet** | `_create_form.blade.php` |
| Record arrival (stock goes UP) | `delivery_subsidies.store_delivery` | `DeliverySubsidyController@storeDelivery:974` — locks line, finds/creates exact pile via `Item.php:292`, writes receipt card, updates `qty_delivered` + status via `DeliverySubsidy.php:90` | `delivery.blade.php` / `ShipmentEditGuardsTest.php` |
| Correct a subsidy | `delivery_subsidies.update` | `DeliverySubsidyController@update:330` — full rebuild if nothing arrived; correction-only (frozen supplier/RIS/DR) once arrivals exist | `edit.blade.php` + `_edit_form` |
| Correct one shipment line | `delivery_subsidies.update_delivery` | `DeliverySubsidyController@updateDelivery:1346` — single-row (`?di=`), same-pile delta or reverse-and-recreate on warehouse move, cost cascade only when qty > 0 (protects shared cost) | `edit_delivery.blade.php` / `ShipmentEditGuardsTest.php` |
| Delete one shipment | `delivery_subsidies.destroy_delivery` | `DeliverySubsidyController@destroyDelivery:1771` — **blocked** if any stock was already issued/transferred/reserved from it | guard test in `ShipmentEditGuardsTest.php` |
| Delete whole subsidy | `delivery_subsidies.destroy` | `DeliverySubsidyController@destroy:631` — **BLOCKED with named reasons if any lineage stock was RIS-issued, transfer-moved, or reservation-locked**; otherwise reverses arrivals, flags planned-only transfers, rebuilds balances | `SubsidyDeletionBlockTest.php` (3 block proofs), `InventoryDeletionReversalTest.php` |

### Requisitions / RIS (stock OUT)

| Function | Route | Code | View / Test |
|---|---|---|---|
| Create request slip | `requisitions.store` | `RequisitionController@store:215` — description lines only, **no stock linked, no stock check** | `_create_form.blade.php` / `RequisitionCreationStockIsolationTest.php` |
| Approve & issue goods (stock goes DOWN) | `requisitions.process_approval` | `RequisitionController@processApproval:962` — locks exact pile, checks `available = on-hand − reserved (+credit if reservation used)`, refuses over-issue, writes handover row + issue card, counts reservation usage, recomputes status via `Requisition.php:238` | `approve.blade.php` / `RequisitionExactRecordIssuanceTest.php`, `ReservationDispatchGuardsTest.php` |
| Edit slip (before/after issue) | `requisitions.update` / `requisitions.correct` | `RequisitionController@update:389` / `correct:724` — issued lines can't go below issued, catalog can't change, **status recomputed never typed in** | `edit.blade.php`, `_correct_ris_modal` / `RequisitionCorrectionTest.php` |
| Edit one handover | `requisitions.dispatch_update` | `RequisitionController@updateDispatch:1359` — reverses old pile, deducts new pile; reservation-linked rows can't switch piles | `_dispatch_edit_modal` / `RequisitionDispatchEditTest.php` (10 proofs) |
| Delete one handover | `requisitions.dispatch_destroy` | `RequisitionController@destroyDispatch:1635` — stock back to exact pile, card erased, reservation usage un-counted (cancel-safe) | `AdminDispatchDeletionTest.php` (6 proofs) |
| **Delete whole RIS (admin)** | `requisitions.destroy` | `RequisitionController@destroy:556` — **every issued qty returns to its exact pile**, cards + handovers deleted, reservation usage reversed, balances rebuilt | `DeliverySubsidyPropagationTest::test_deleting_requisition_recalculates_stock_card_balances` |
| Signatories / print | `requisitions.update_signatories`, `requisitions.print` | `RequisitionController@updateSignatories:1735`, `printRis:1764` | `signatories.blade.php`, `print.blade.php` |
| Completion date ("Date Fully Delivered") | shown on index | `Requisition.php:219` — latest handover date, **null unless fully issued** | `requisitions/index.blade.php` |
| **Confirm delivery (delivery updater)** | `requisitions.dispatch_confirm_delivery` / `..._unconfirm_delivery` | `RequisitionController@confirmDelivery` — stamps date/by/notes per line, notifies admins + WMs (explicit "fully delivered" on last line); touches NO stock/costs/status. Withdraw via `unconfirmDelivery` | `requisitions/show.blade.php` (Delivery card + per-line buttons) / `DeliveryConfirmationTest.php` (7 proofs) |

### Reservations (the "reserved" signs)

| Function | Route | Code | View / Test |
|---|---|---|---|
| Create reservation | `reservations.store` | `ReservationController@store:76` — locks pile, checks available, snapshots price/expiry, **stock untouched** | `_create_modal.blade.php` |
| Approve → mark ready | `reservations.approve`, `reservations.ready` | `ReservationController@approve:197`, `markReady:211` — PENDING→RESERVED→READY (**admin only**) | `show.blade.php` |
| Cancel / expire / delete | `reservations.cancel`, `destroy`, `items.destroy` | `cancel:225`, `destroy:248` (refused if anything was used), `destroyItem:276` | `ReservationDispatchGuardsTest.php` |
| Use in an issue (deploy) | via `process_approval` with `reservation_item_id` | `RequisitionController@processApproval:962` — guards: ACTIVE reservation, matching description, `wanted ≤ remaining`; header status via `Reservation.php:245` (incl. fall-back to READY when all lines revert to unused) | `approve.blade.php` + reservation picker APIs |

### Transfers (moves between warehouses)

| Function | Route | Code | View / Test |
|---|---|---|---|
| Plan a move (nothing moves yet) | `transfers.store` | `StockTransferController@store:111` — checks source has stock, pre-creates destination pile, `requested=qty, moved=0` | `_create_modal.blade.php` |
| Execute the move | `transfers.process_dispatch` | `StockTransferController@processDispatch:302` — locks both piles, **rejects over-planned amounts** (no silent cut), source−/destination+, writes out+in cards, status via `StockTransfer.php:84` | `dispatch.blade.php` / `StockTransferRequestedVsDispatchedSeparationTest.php` |
| Correct / cancel a move | `transfers.update`, `transfers.destroy` | `update:616` (delta both sides, guarded), `destroy:905` (refused if destination already used the goods) | `edit.blade.php` / `StockTransferAdminCorrectionTest.php`, `InventoryDeletionReversalTest.php` |

### Diaries, reports, admin

| Function | Route | Code | View / Test |
|---|---|---|---|
| Pile diary + print | `stock_cards.item_history`, `stock_cards.print` | `StockCardController` — reads only; balances rebuilt by `StockCardEntry.php:38` after every change | `stock_cards/` views |
| RPCI (what we have) / RSMI (what we gave) | `rpci_report`, `rsmi_report` (+`.print`, `+ .export`, `+ .snapshot`) | `ReportController@rpci:19`, `@rsmi:103` (searchable; Requested/Issued/Outstanding), exports via Excel tool | `reports/` views / `InventoryBalanceReportTest.php` |
| Inventory Balance (admin/WM) | `inventory_balance_report` | `ReportController@inventoryBalance:431` — one row per exact pile, never merged, physical counts | `inventory_balance.blade.php` |
| Categories + item names | `item_categories.*`, `item_catalog_items.*` | `ItemCategoryController`, `ItemCatalogItemController` — delete blocked when used | `item_categories/index.blade.php` (modals) |
| Warehouses / Suppliers / Users | `warehouses.*`, `suppliers.*`, `users.*` | `WarehouseController` (no delete — by design), `SupplierController`, `UserController` (admin only; role + warehouse assignment) | respective `index/edit/create` views |
| Notifications (bell) | `notifications.*` | `NotificationController` — your messages only, 30-sec refresh | top bar + `notifications/index.blade.php` |

---

## 3. Questions QA will likely ask (with your answers)

**"If admin deletes an RIS, do stocks go back?"**
Yes — every issued quantity returns to its exact original pile, diary lines are erased,
reservation usage is un-counted, balances rebuilt. Proven by
`DeliverySubsidyPropagationTest::test_deleting_requisition_recalculates_stock_card_balances`
and 6 tests in `AdminDispatchDeletionTest`. Code: `RequisitionController@destroy:556`.

**"Can stock go negative?"**
No for issues and reservations — both refuse with the numbers shown (`processApproval:962`,
`ReservationController@store:76`). Transfer dispatch clamps at zero (known minor gap, §23
of the technical doc) — edits there are guarded instead.

**"Can someone issue reserved goods to the wrong request?"**
No — issues check `available = on-hand − reserved`, and reservation-linked issues additionally
require an ACTIVE reservation, matching description, and `wanted ≤ remaining`
(`ReservationDispatchGuardsTest`: dead/wrong-description/over-deploy all rejected).

**"Same item, different warehouse or price — merged?"**
Never. A pile is unique by warehouse + name + unit + category + price + ENGAS + expiry +
origin (`Item.php:292`). Different in any one = different row. Exact-record returns on delete.

**"Free/donated goods with ₱0 price?"**
Supported — zero is a real price (`nullable|min:0` validations); displays use `!==null` so
₱0.00 shows instead of disappearing. Covered by `ShipmentEditGuardsTest`.

**"Who can delete?"**
Admin only — enforced twice: the route middleware (`admin.write`) and the controller check.
Warehouse managers can create/record/issue but never edit or delete.

**"Where's the audit trail?"**
Background history notes remain for corrections; the old delete-history viewer pages were
intentionally removed in the latest version (tests assert they stay gone). Current truth is
the stock cards + balances, which every reversal rebuilds from scratch.

**"How do you know it all works?"**
30 automated inspector files, 204 checks, all passing (`php artisan test`, ~45 seconds, runs
against a separate `wgims_test` database — production data untouched). Plus: every money/stock
change runs all-or-nothing in a transaction with row locks.

**"What are the known weak spots?"** (honesty impresses QA)
Transfer-dispatch source-availability check, unlocked pre-checks when planning a transfer,
integer-cast of fractional quantities, and two API helpers lacking warehouse checks. All are
documented in `WGIMS_AI_CONTEXT.md` §23 with file references — none affect the tested flows.

---

## 4. If you don't know an answer (say this)

1. *"Good question — let me show you exactly where that lives."* (Open §2, find the row.)
2. *"The rule for that is written in our agent manual here…"* (Open `AGENTS.md`.)
3. *"That path has an automated test — let me run just that one for you."*
   (`php artisan test --filter=TestName` — takes seconds.)
4. Never guess. *"I'll verify that in the code and get back to you"* beats a wrong answer.

---

## 5. Suggested 10-minute demo script (happy path)

1. Login as admin → Dashboard (point out the 4 stat rows + chart).
2. Subsidies → create expectation → Record Delivery (stock UP, show the pile + diary).
3. Reservations → set some aside (show Available drop while on-hand stays).
4. Requisitions → create slip → Approve/Issue using the reservation (stock DOWN, reservation DEPLOYED).
5. Transfers → plan + dispatch (source down, destination up).
6. Reports → RSMI (shows the issue), RPCI/Balance (shows remainder).
7. Delete the RIS → show the stock bounce back (the money moment).

Keep MySQL running in XAMPP first (the app's database lives there), and you're set. Good luck — you know this system better than you think. 💪
