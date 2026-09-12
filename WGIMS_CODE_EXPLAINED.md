# WGIMS Explained in Plain English

> **Who is this for?** The owner of this system who wants to understand what every part
> of the code does — no programming experience required.
> If a sentence ever confuses you, jump to **Part 9: Glossary** and then come back.

---

## Part 0 — The 5-minute primer (please read this first)

**What is this system?** WGIMS (Welfare Goods Inventory Management System) is a website that
keeps track of relief goods (food packs, hygiene kits, etc.): what came in, where it is stored,
what was given out, and what is left. Think of it as a **digital stockroom ledger**.

**What is Laravel?** Just the toolbox the website was built with (like how a house can be
"made of wood" — the website is "made with Laravel"). You don't need to learn it.

**How does any click work?** Every click follows the same 4-step journey:

1. **You click something** (a button or link on a page).
2. **The address book (routes)** looks at the web address and decides *who* should handle it.
3. **The manager (controller)** does the thinking: checks your permission, reads/writes data.
4. **The filing cabinet (database)** stores everything permanently, and a **page (view)**
   shows you the result.

Example: you click "Delete RIS" → address book sends it to the Requisition manager → the
manager puts the stock back, erases the issue records, fixes the running totals → you see
the updated list. That's all any feature ever is: click → manager → filing cabinet → page.

**What is a database table?** A spreadsheet. Each table is one spreadsheet (e.g. "Items",
"Users"), each row is one record (e.g. one sack of rice, one user account).

---

## Part 1 — The big picture: the journey of relief goods

Goods move through your system in this order. Everything in the code exists to support
one of these steps:

1. **A subsidy/request is recorded** — "We expect 1,000 food packs from Supplier X."
   (Nothing is in stock yet. It's just a plan on paper.)
2. **Goods arrive (delivery)** — the truck comes, staff encode what arrived, and the
   stock numbers go up. Each arrival gets a **DR number** (Delivery Receipt — the paper
   that proves goods arrived).
3. **Goods are reserved (optional)** — "Set aside 200 packs for the coming typhoon
   response." The packs stay on the shelf, but the system marks them as *spoken for*,
   so nobody else can accidentally give them away.
4. **A request slip (RIS) is made** — a local office asks for goods: "We need 300 packs
   for Barangay Y." At this point it's only a request — nothing leaves yet.
5. **Goods are issued (dispatch)** — staff approve the RIS and hand over the goods.
   Stock goes down. This is recorded with its own DR number.
6. **Goods move between warehouses (transfer)** — "Send 100 packs from Warehouse A to
   Warehouse B." One side goes down, the other goes up.
7. **Reports** — the system prints official summaries: what we have (RPCI), what we gave
   out (RSMI), and the per-warehouse balance (Inventory Balance).
8. **Stock cards** — every single item has a diary: "Jan 5: +500 arrived. Jan 9: −200
   issued. Balance: 300." This diary is automatic — staff never type it by hand.

**The golden rule of the whole system:** stock only goes UP when goods arrive, and only
goes DOWN when goods are issued or transferred. Everything else (requests, reservations,
edits) is paperwork around those two movements.

---

## Part 2 — The folders: what lives where

Think of the project folder as an office building. Here's what's on each floor:

| Folder / File | Plain-English meaning |
|---|---|
| `app/Http/Controllers/` | The **managers** — one per job (deliveries, requests, transfers…). They do all the thinking. 16 managers total. |
| `app/Models/` | The **definitions of things** — what an "Item", a "User", a "Requisition" is, and how they're related. 23 definitions. |
| `app/Services/` | A **specialist assistant** (only 1): keeps costs in sync when a delivery is corrected. |
| `app/Http/Middleware/` | The **security guards** — they check your role before letting you into a page or action. |
| `routes/web.php` | The **address book** — the full list of every page address and which manager handles it (~104 addresses). |
| `resources/views/` | The **pages you see** — every screen, table, form, and printout. |
| `resources/views/layouts/app.blade.php` | The **master template** — the sidebar, top bar, colors, and buttons shared by every page. |
| `database/migrations/` | The **construction history** — 57 step-by-step instructions that built the filing cabinet. (Never re-run old ones; they're history.) |
| `database/seeders/` | The **starter kit** — creates the first warehouses and users on a fresh install. |
| `config/` | **Settings drawers** — database connection, app name, time zone, etc. |
| `.env` | **The secret settings card** — passwords and environment (local vs live). Never share or post this file online. |
| `.env.example` | A **blank copy** of the settings card showing which settings exist (no secrets). |
| `composer.json` / `package.json` | **Shopping lists** of helper toolboxes the project needs (Laravel itself, Excel export tool, etc.). |
| `tests/Feature/` | **27 automatic inspectors** — little robots that test the system (187 checks) to make sure nothing is broken. |
| `docs/` | **Older manuals** about architecture, rules, and workflows. |
| `AGENTS.md` / `WGIMS_AI_CONTEXT.md` | **Instruction manuals for AI assistants** (like me) so we don't break your rules. |
| `public/` | The **front door** — the files the internet can directly see (logo, built styles). |
| `storage/` | The **back room** — logs (error diaries), cached pages, uploaded files. |

---

## Part 3 — The managers (controllers): who does what

Each manager handles one area. Under each, the everyday actions explained:

**AuthController — the receptionist.** Shows the login page, checks your username/password,
locks out guessers after 10 wrong tries, logs you out safely.

**DashboardController — the bulletin board.** Shows the home page: totals, charts, recent
activity. Admins see everything; warehouse staff see only their warehouses.

**ItemController — the stock viewer (look, don't touch).** Shows the stock list and the
detail page of one item. There is deliberately NO "add/edit/delete stock" here — stock
only changes through deliveries, issues, and transfers (that's what keeps the numbers honest).

**DeliverySubsidyController — the receiving department (biggest manager).** Creates the
expectation ("we await 1,000 packs"), records actual arrivals (this is where stock goes UP),
corrects a recorded arrival, deletes a whole subsidy (puts everything back carefully), and
shows the history of one subsidy.

**RequisitionController — the releasing department.** Creates request slips (RIS), approves
and issues goods (this is where stock goes DOWN), corrects a slip, edits or deletes a
single issuance, deletes a whole RIS (puts issued goods BACK on the shelf), manages the
people who sign the form, and prints the official RIS paper.

**StockTransferController — the movers.** Plans a move between warehouses, executes it
(source goes down, destination goes up), corrects a move, or cancels one (only if the
receiving side hasn't used the goods yet).

**ReservationController — the "reserved" sign maker.** Sets packs aside for a future
operation (stock stays, but marked spoken-for), approves/marks-ready/cancels reservations,
and lists which reservations can be used when issuing goods.

**StockCardController — the diary reader.** Shows an item's automatic diary (every arrival,
issue, and transfer with running balance) and prints it.

**ReportController — the report printer.** Produces the three official reports (RPCI = what
we have, RSMI = what we gave out, Inventory Balance = per-warehouse ledger), exports them
to Excel, and saves monthly snapshots.

**ItemCategoryController + ItemCatalogItemController — the label makers (admin only).**
Manage the categories (Food, Non-food…) and the list of allowed item names under each, so
everyone picks from the same dropdown instead of typing different spellings.

**WarehouseController — the building manager.** Lists and edits warehouses (no delete —
deleting a warehouse would orphan all its stock, so it's forbidden by design).

**UserController — the HR office (admin only).** Creates accounts, assigns roles and
warehouses, activates/deactivates people.

**SupplierController — the address book of suppliers.** Who delivered what.

**NotificationController — the mailbox.** The bell icon: tells you when things happen
(new request, delivery recorded…), marks messages as read.

---

## Part 4 — The things (models): what each spreadsheet means

| Thing | What it is, in plain words |
|---|---|
| **Warehouse** | A storage building (Main Office, CFA, RC, YC). Every stock record belongs to exactly one. |
| **User** | An account. Has a role (see Part 8) and assigned warehouses. |
| **Supplier** | A company/person who delivers goods. |
| **Item (the star of the show)** | One pile of identical goods: same name + same warehouse + same price + same expiry + same origin. "500 Family Food Packs at ₱700 in Warehouse A from Subsidy #12" is ONE Item row. A different price or warehouse = a DIFFERENT row. Never merged — that's the system's most important rule. |
| **ItemCategory** | A group label like "Food" with its accounting code. |
| **ItemCatalogItem** | An allowed item name under a category (the dropdown choices). |
| **DeliverySubsidy** | The "we expect goods" paper: who supplies, what was requested, total. |
| **DeliverySubsidyItem** | One line on that paper: "1,000 × Family Food Pack". Tracks how many actually arrived (`qty_delivered`). |
| **Delivery** | One truck arrival (one DR number, one date). |
| **DeliveryItem** | One line of one arrival: "500 packs at ₱700, expiry Dec 2026, to Warehouse A". *This* is what increases stock. |
| **Requisition (RIS)** | A request slip: who asks, why, what they need. Starts as just a request. |
| **RequisitionItem** | One line on the slip: "300 × Family Food Pack requested". Tracks how many were actually handed out (`quantity_issued`). |
| **RequisitionDispatchItem** | One actual handover: "200 packs taken from [exact pile], DR #123". *This* is what decreases stock. Also stores the price at handover time. |
| **StockTransfer** | A move order between two warehouses, with a tracking number. |
| **StockTransferItem** | One line of the move: what pile, how many planned vs actually moved. |
| **Reservation** | A "reserved" sign: purpose + expiry. A header for the lines below. |
| **ReservationItem** | One reserved pile: how many set aside, how many already used. Does NOT change stock — only marks it spoken-for. |
| **StockCardEntry** | One diary line for one pile: arrival (+), issue (−), transfer in/out, and the running balance after it. |
| **ReportSnapshot** | A frozen copy of a monthly report (so history can't change later). |
| **SystemNotification** | One bell-icon message for one user. |
| ***AuditLog (3 kinds)** | History books. Note: the *viewer pages* for these were removed — only quiet background notes remain. |

---

## Part 5 — The pages (views): what each screen does

All pages share one master template: **sidebar** (menu: Dashboard, Inventory, Procurement,
Reports, Administration) + **top bar** (page title, your warehouse, bell icon) + **content**.

| Screen | What you do there |
|---|---|
| **Dashboard** | See totals, charts, recent activity at a glance. Different for admins vs warehouse staff. |
| **Items** | Search/filter all stock; open one pile to see its diary and details. |
| **Delivery Subsidies (list/create/show)** | See all expectations; create a new one; open one to see requested vs arrived per line. |
| **Record Delivery** | Encode a truck arrival: per line, how many, what price, expiry, which warehouse, DR number. |
| **Requisitions (list/create/show)** | See all request slips; create one; open one to see requested vs handed-out per line. |
| **Approve / Issue (approve page)** | Pick the exact pile for each requested line, set quantity + DR number, hand over. |
| **Print RIS** | The official paper form with signature boxes. |
| **Transfers (list/dispatch/show/print)** | Plan moves, execute them, print the move order. |
| **Reservations (list/create/show)** | Set goods aside, approve/mark-ready/cancel, watch them get used. |
| **Stock Cards** | Per-pile diary, a cost-breakdown view, and printout. |
| **RPCI / RSMI / Inventory Balance** | The three official reports + Excel export + print versions. |
| **Item Categories** | Manage groups and allowed item names (small pop-up windows). |
| **Warehouses / Suppliers / Users** | Simple lists with Add/Edit buttons (admin areas). |
| **Notifications** | Your full mailbox of system messages. |
| **Login** | The front door. Username + password. |

Pop-up windows (**modals**) are used everywhere for Add/Edit forms so you never leave the page.

---

## Part 6 — Everyday walkthroughs: what the code does when you…

**…record a delivery:** The manager locks the request line (so two people can't encode the
same arrival twice), finds-or-creates the exact pile matching warehouse+name+price+expiry+
origin, adds the quantity, writes a diary line (+500), updates "arrived so far", and tells
the admins. All-or-nothing: if any step fails, everything rolls back.

**…issue goods for an RIS:** The manager locks the exact pile, computes
Available = On-hand − Reserved, refuses if you ask for more than available (and tells you
the numbers), subtracts the quantity, writes a diary line (−200), stamps the price/DR on
the handover record, counts it toward the reservation if one was used, and updates the
slip's status (Pending → Partially issued → Fully issued).

**…delete an RIS (your question!):** The manager gives EVERY handed-out quantity back to
its exact original pile, erases the diary lines for those handovers, un-counts any
reservation usage (a reservation that becomes unused again goes back to READY), deletes
the handover records and the slip, and recomputes everything. Tested automatically —
yes, stocks revert. (Only an admin can do this.)

**…transfer goods:** Step 1 (plan) only writes the order — no stock moves. Step 2 (dispatch)
subtracts from the source pile, adds to the destination pile (creating it if needed), and
writes two diary lines (out + in). If you try to send more than planned, it now refuses
instead of silently capping.

**…reserve goods:** The manager checks Available, writes "reserved" lines (stock untouched),
and later issues count against them. Cancel/expire releases them.

---

## Part 7 — Users and permissions (who may do what)

| Role | May… |
|---|---|
| **Admin** | Everything, including editing, deleting, users, and categories. |
| **Warehouse Manager** | Everything operational (create/record/issue/transfer) but CANNOT edit or delete. Sees all warehouses. |
| **Supply Custodian / Center Head** | View their warehouses + approve/issue requests. Cannot correct or delete. |
| **Center Staff** | View only (read-only). |

Two safety nets: the menu hides buttons you can't use, AND the manager re-checks your role
on every action (hiding alone would not stop a clever user — the re-check does).

---

## Part 8 — Money and numbers, simply

- **Unit cost:** the price of one piece. **ENGAS cost:** a second, separate price tracked
  for reporting (some goods have it, some don't).
- **Free/donated goods:** price `0` is allowed and means "free". *Empty* means "unknown".
  The system treats those differently — that's why you sometimes see `₱0.00`.
- **Total value** = quantity × unit cost. Reports add these up.
- **On-hand** = what's physically there. **Reserved** = spoken-for. **Available** =
  On-hand − Reserved = what you may still give away.
- **Requested vs issued:** requested is what the slip asks; issued is what actually left.
  **Ordered vs delivered:** ordered is what the paper expects; delivered is what the truck
  brought. The system always shows both so nothing hides.

---

## Part 9 — Glossary: scary words, translated

| Word | Means |
|---|---|
| Route | One address in the address book (`routes/web.php`). |
| Controller / Manager | The code that thinks and acts for one area. |
| Model / Thing | The definition of one spreadsheet + its rules. |
| View / Page | A screen you see (built from `resources/views/` files). |
| Blade | The page-writing language (normal text + a few `{{ }}` placeholders). |
| Migration | One construction step that built the database (history — don't touch). |
| Seeder | Starter-kit data for a fresh install. |
| Middleware / Guard | Security checks that run before a page/action. |
| DR number | Delivery Receipt number — the paper proving goods moved. |
| RIS | Requisition and Issue Slip — the request form. |
| RPCI | Report of what we HAVE (physical count report). |
| RSMI | Report of what we GAVE OUT (issuance report). |
| Stock card | The automatic diary of one pile. |
| ENGAS | The second price tracked for reporting. |
| Stock identity | The rule that a pile is unique by warehouse+name+price+expiry+origin — never merged. |
| Transaction | All-or-nothing: either every step succeeds or everything rolls back. |
| Lock (`lockForUpdate`) | "Nobody touch this row until I'm done" — prevents two encoders from clashing. |
| N+1 | A slowness bug (asking the cabinet 100 times instead of once). Mostly fixed. |
| Test (Feature test) | A robot inspector that clicks through a feature and checks the numbers (27 inspectors, 187 checks, all passing). |
| `.env` | Secret settings card. Never share it. |

---

## Part 10 — How to work with your AI assistant from here

You don't need to learn coding — just describe WHAT you want in everyday words, for example:

- "When issuing goods, also show the expiry date in the table."
- "Warehouse managers should also be able to correct a dispatch."
- "Add a report that shows all expired goods per warehouse."

The assistant (me) will figure out which manager, page, and spreadsheet are involved, check
the business rules in `AGENTS.md`, make the smallest safe change, test it with the robot
inspectors, and tell you exactly what changed. If a request would break an important rule
(like merging two different piles), I will warn you first instead of just doing it.

**One habit that protects you:** before any change, I read the real code — never guess.
If I can't verify something, I'll say so plainly.

---

*Written for the WGIMS owner. Technical companion docs: `AGENTS.md` (rules for AI),
`WGIMS_AI_CONTEXT.md` (deep technical reference), `docs/` (older manuals).*
