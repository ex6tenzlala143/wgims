# WGIMS User Manual

**Welfare Goods Inventory Management System (WGIMSv2 · DSWD)**

*Version 2.0 — End-User Guide*

---

## About This Manual

This manual explains how to use WGIMS, the inventory system used to track welfare goods
(deliveries, subsidies, requisitions, dispatches, stock transfers, and reports) at DSWD.

It is written for **non-technical users**. Every step is described click-by-click, and every
screen is described using the **exact labels** that appear on the system, so you can follow
along while you work.

**Notes on accuracy**

- A green check (✅) means the feature is available to that role. A warning sign (⚠️) means
  the feature is limited or has conditions. A cross (❌) means the feature is **not**
  available.
- This manual does not include screenshots. All descriptions match the current system
  screen-for-screen (button names, column headers, and messages are quoted verbatim).

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [System Overview](#2-system-overview)
3. [User Roles and Permissions](#3-user-roles-and-permissions)
4. [Logging In](#4-logging-in)
5. [The Dashboard](#5-the-dashboard)
6. [Item Categories](#6-item-categories)
7. [Suppliers](#7-suppliers)
8. [Warehouses](#8-warehouses)
9. [Subsidies / Deliveries](#9-subsidies--deliveries)
10. [Items / Inventory](#10-items--inventory)
11. [Requisitions / Augmentations (RIS)](#11-requisitions--augmentations-ris)
12. [Dispatching and Issuing Items](#12-dispatching-and-issuing-items)
13. [Stock Transfers](#13-stock-transfers)
14. [Stock Cards](#14-stock-cards)
15. [Inventory Balance Report](#15-inventory-balance-report)
16. [Other Reports (RPCI and RSMI)](#16-other-reports-rpci-and-rsmi)
17. [Users (Administrator Only)](#17-users-administrator-only)
18. [Searching, Filtering, and Pagination](#18-searching-filtering-and-pagination)
19. [Editing and Correcting Records](#19-editing-and-correcting-records)
20. [Deleting Records](#20-deleting-records)
21. [Notifications and Validation Messages](#21-notifications-and-validation-messages)
22. [Common Problems and Troubleshooting](#22-common-problems-and-troubleshooting)
23. [Frequently Asked Questions](#23-frequently-asked-questions)
24. [End-to-End Example](#24-end-to-end-example)
25. [Quick Reference Guide](#25-quick-reference-guide)

---

## 1. Introduction

WGIMS ("Welfare Goods Inventory") is the DSWD system for managing the inventory of welfare
goods: rice, family food packs, hygiene kits, and other supplies that arrive as **deliveries /
subsidies**, are kept in **warehouses**, and are issued to beneficiaries through
**requisitions (RIS)**.

The system replaces paper stock ledgers with **stock cards** that are updated automatically.
Every time stock enters, leaves, or moves between warehouses, the system records it and
recalculates the remaining balance. This keeps reports such as the **RPCI** (physical count)
and **Inventory Balance** accurate and up to date.

**Who uses WGIMS?**

- **Administrators** — run and maintain the system, fix records, manage users.
- **Warehouse Managers** — add deliveries and create requisitions, view all records.
- **Supply Custodians** — approve and issue items against requisitions.
- **Center Staff** — request items and view records for their warehouse(s).
- **Center Heads** — approve and issue items against requisitions.

---

## 2. System Overview

### 2.1 The main menu

After logging in, you will see the **sidebar** (left side of the screen) with the brand
**"Welfare Goods Inventory WGIMSv2 · DSWD"** at the top. The menu contains the following
items (exact labels):

| Menu item | What it is for |
|---|---|
| **Dashboard** | Summary of stock and pending work |
| **Items** | The item records (stock records) in every warehouse |
| **Subsidies / Deliveries** | Incoming shipments of goods and their subsidy information |
| **Requisitions / Augmentations** | Requests for items (RIS) and their issuance |
| **Stock Transfers** | Moving stock from one warehouse to another |
| **Stock Cards** | The running ledger of every item's movements |
| **Suppliers** | The list of suppliers / subsidy sources |
| **RPCI Report** | The official physical count report |
| **RSMI Report** | The official issued-supplies report |
| **Inventory Balance** | Current stock balances by warehouse (Administrator / Warehouse Manager) |
| **Item Categories** | The item categories (Administrator only) |
| **Users** | User accounts (Administrator only) |

The **notification bell** (with a red count) appears at the top of the sidebar. The
**logout button** (sign-out icon) is at the bottom of the sidebar.

### 2.2 Important concepts

**Stock record (Item).** A single line of inventory: an item description with its unit,
category, warehouse, unit cost, ENGAS unit cost, expiration date, and quantity. Each stock
record has its own **Stock Number**.

**Stock numbers.** Each stock record gets a unique stock number. Items you create manually
do not receive a stock number until they are included in a delivery — the system shows
`"Item created. Stock number will be assigned when a delivery / subsidy is delivered."`

**Subsidy ID.** Every subsidy (delivery source) is permanently identified by a **Subsidy ID**
in the format **SUB-000001** (SUB- followed by six digits). The ID is never reused, even if
a subsidy is deleted or archived. You will see the Subsidy ID on the Subsidies pages, on
Items, Stock Cards, Transfers, and Requisitions. It lets you trace exactly **where stock
came from**.

**Inventory grouping rule (very important).** Stock is managed per exact stock record. Two
records are considered "the same item" only when **all** of the following match:

- the same **originating Subsidy ID**
- the same **item description**
- the same **unit cost**
- the same **ENGAS unit cost**
- the same **expiration date** (only when an expiration date is recorded)
- the same **warehouse**

If any of these differ (for example, the goods came from a *different subsidy*), the system
keeps them as **separate records** — it never merges stock from different subsidies, and it
never guesses which subsidy stock came from. When identical records exist side by side, the
listings show them **grouped with a "Merged" badge** so the display stays readable.

**Documents vs. records.** The system prints official DSWD forms (RIS, RPCI, RSMI, stock
transfer slips). The forms are generated **from** the records — you do not type the forms
themselves. Any correction is made in the records first, then the form is printed again.

### 2.3 What the system does automatically

- Generates the **RIS number** (e.g. `RIS-202608-0001`) and **transfer numbers** (e.g.
  `TRF-…`) for you.
- Deducts stock when items are **issued** and when stock is **transferred out**.
- Adds stock when **deliveries** arrive and when stock is **transferred in**.
- Updates **stock cards** (running balances) after every movement.
- Recalculates a requisition's status (Pending → Partially Fulfilled → Approved) from the
  quantities actually issued.
- Sends **notifications** (bell icon) when a new RIS is submitted and when it is fulfilled.

---

## 3. User Roles and Permissions

There are five roles. What you can see and do depends on your role **and** on the warehouses
assigned to your account (the Administrator assigns both).

### 3.1 The five roles

| Role | Who is it for |
|---|---|
| **Administrator** (`admin`) | System administrator. Full access to everything, including correcting records, deleting, and managing users. |
| **Warehouse Manager** (`warehouse_manager`) | In charge of one or more warehouses. Can **view** and **create** records, but **cannot edit or delete**. |
| **Supply Custodian** (`supply_custodian`) | Custodian of supplies. Can **approve and issue** items against requisitions for their warehouses, and view their records. |
| **Center Staff** (`center_staff`) | Staff at a center. Can **create requisitions** and **view** records for their warehouses. Cannot approve or issue. |
| **Center Head** (`center_head`) | Head of a center. Can **approve and issue** items, create requisitions, and view their records. |

### 3.2 What each role can do (module by module)

| Capability | Admin | Warehouse Mgr | Supply Custodian | Center Head | Center Staff |
|---|---|---|---|---|---|
| **Dashboard** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **View items, stock cards, transfers, requisitions** (own warehouses) | ✅ (all) | ✅ (all) | ✅ | ✅ | ✅ |
| **Create items** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Record deliveries / subsidies** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Create requisitions (RIS)** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Approve / issue items (RIS dispatch)** | ✅ | ✅ | ✅ | ✅ | ❌ |
| **Request stock transfers** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Edit / correct / archive / delete any record** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **RPCI / RSMI reports** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Inventory Balance report** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Item Categories** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Suppliers / Warehouses / Users management** | ✅ | ❌ | ❌ | ❌ | ❌ |

### 3.3 Warehouse Manager at a glance

| Action | Availability |
|---|---|
| Log in and view the dashboard | ✅ |
| View items, subsidies, requisitions, transfers, stock cards, reports | ✅ |
| Create items, record deliveries, create requisitions, request transfers | ✅ |
| Edit existing records | ❌ |
| Correct or archive records | ❌ |
| Delete records | ❌ |
| Manage users, suppliers, warehouses, categories | ❌ |

If a Warehouse Manager needs a record corrected or removed, they ask the **Administrator**.

---

## 4. Logging In

1. Open your web browser and go to the system web address (provided by your administrator).
2. Type your **Username** in the username field.
3. Type your **Password** in the password field.
4. Click the **Sign In** button.

**What you will see**

- If the username or password is wrong — or your account was deactivated — the system shows:
  `"Invalid credentials or account is inactive."` Check your spelling, or ask the
  Administrator to reset your password or re-activate the account.
- After several wrong attempts within one minute, the system temporarily blocks more
  attempts; wait a minute and try again.

**Important — there is NO "Forgot Password" link.** The system intentionally does not offer
password recovery. If you forget your password, ask the **Administrator** to reset it for
you. Never share your password with anyone; every action you make is recorded with your
name.

**Logging out:** click the **logout (sign-out) icon** at the bottom of the sidebar. Always
log out when you leave a shared computer.

---

## 5. The Dashboard

The Dashboard is the first screen after login. It gives you a quick picture of the inventory:

**For Administrators**

- Four statistic cards: **Total Items**, **Delivery / Subsidies**, **Pending Requisitions**,
  and **Active Warehouses**.
- An **inventory balance** table grouped by warehouse, with each account (category) showing
  its **Total Qty** and **Total Value (₱)**, a **Warehouse Total:** row per warehouse, and
  an overall grand total.
- An **unliquidated subsidies** card: subsidies still pending or partially delivered,
  grouped by warehouse, with links to their detail pages.

**For warehouse-level users (Warehouse Manager, Custodian, Center Head, Center Staff)**

- Three statistic cards limited to your assigned warehouses: **Items in Warehouse**,
  **Delivery / Subsidies**, and **Pending RIS**.
- An **account balances** table for your warehouse(s): each category with its Total Qty and
  Total Value (₱).

If your account has **no warehouse assigned**, the system shows:
`"You have no warehouses assigned. Please contact an administrator."`

The Dashboard is read-only — nothing on it can be changed from here. It is a starting point
for navigation: use the sidebar menu to go to any module.

---

## 6. Item Categories

**Availability:** ✅ Administrator only. All other roles (❌) cannot open this page.

Item categories organize stock (for example, Food, Non-Food, Medical). Each category has a
**label** (name) and an **Account Code** used in the RPCI report.

### Viewing categories

1. Click **Item Categories** in the sidebar.
2. The page lists every category with its label, account code, and status
   (Active / Inactive).

### Adding a category

1. Click the **New Category** button (or similar "add" button on the page).
2. Enter the category **Label** and **Account Code**.
3. Save. The system confirms: `Category "…" created successfully.`

### Editing a category

1. Click the **edit** (pencil) button next to the category.
2. Change the label and/or account code.
3. Save. The system confirms: `Category "…" updated.`

### Deactivating / reactivating a category

A category that already has items assigned **cannot be deleted** — the system replies:
`Cannot delete "…" — it has items assigned to it. Deactivate it instead.`
Use the **toggle active** button to deactivate it instead. Deactivated categories no longer
appear in dropdowns for new items.

### Deleting a category

A category with **no items** can be deleted (trash icon, Administrator only). The system
confirms: `Category "…" deleted.`

---

## 7. Suppliers

**Availability:** ✅ View — all roles. ✅ Create — Administrator and Warehouse Manager.
❌ Edit / delete / deactivate — Administrator only.

Suppliers (also called "Supplier/Subsidy" sources) are the organizations that provide the
goods, e.g. the National Food Authority or a local government unit. The Supplier list is
used when recording deliveries: every delivery is linked to a **Supplier/Subsidy**.

### Viewing suppliers

1. Click **Suppliers** in the sidebar.
2. The table shows: supplier **name**, **address**, **Status** (Active / Inactive), and
   more details depending on your role.
3. Use the **search box** to find a supplier by name.

### Adding a supplier

1. Click **Add Supplier** on the page (page title: **Add New Supplier**).
2. Fill in the **Supplier Details** form:
   - **Supplier Name** (required)
   - **TIN Number** (optional)
   - **Address** (optional)
   - **Contact Person** (optional)
   - **Phone** (optional)
   - **Email** (optional)
3. Click **Save Supplier**. The system confirms: `Supplier added successfully.`

### Editing a supplier

1. Click the **edit** (pencil) button next to the supplier.
2. Change the name or contact details.
3. Click **Save**. The system confirms: `Supplier updated.`

### Deactivating / reactivating

Use the **toggle active** button. The system confirms: `Supplier "…" activated.` or
`Supplier "…" deactivated.` Deactivated suppliers cannot be selected for new deliveries
(the system rejects them with a "not active" message), but existing delivery records are
kept.

### Deleting a supplier

⚠️ A supplier that is already used by deliveries **cannot** be deleted — the system blocks
it and tells you the supplier is referenced by delivery records. Only a supplier that has
never been used can be deleted. Deactivating is the recommended way to "retire" a supplier.

---

## 8. Warehouses

**Availability:** ✅ View — all roles (limited to your assigned warehouses). ✅ Create —
Administrator and Warehouse Manager. ❌ Edit / toggle / delete — Administrator only.

Warehouses are the storage locations (e.g. "GAMC1", "GAMC2", "Lapu-Lapu warehouse"). Every
item record belongs to exactly one warehouse, and every user is assigned to one or more
warehouses.

### Viewing warehouses

1. Click the **Warehouses** link in the sidebar.
2. The table shows: **Warehouse Name**, **Code**, **Place**, and **Status**
   (Active / Inactive). Administrators and Warehouse Managers also see **Assigned Users**
   and **Items** counts and the **Actions** column.
3. If your account has no warehouse assigned, the page shows:
   *"You have no warehouses assigned to your account. Please contact an administrator."*

### Adding a warehouse

1. Click **Add Warehouse** (page title: **Add New Warehouse**).
2. Enter the **Warehouse Name** (required), a unique **Code** (required), and the
   **Place** (optional).
3. Click **Save Warehouse**. The system confirms: `Warehouse created.`

### Editing a warehouse

1. Click the **edit** (pencil) button next to the warehouse.
2. Change the name, code, or place, and optionally untick the **Active** checkbox to
   deactivate the warehouse (this screen also shows the users assigned to it).
3. Click **Update Warehouse**. The system confirms: `Warehouse updated.`

⚠️ Deactivated warehouses no longer appear when creating new records, but all existing
records that reference them are kept.

**Note for users:** you only see data for the warehouses assigned to your account. If a
warehouse is missing from your screens, ask the Administrator to assign it to you.

---

## 9. Subsidies / Deliveries

**Availability:** ✅ View — all roles (own warehouses for non-admins). ✅ Create and record
deliveries — Administrator and Warehouse Manager. ❌ Edit, correct, archive, delete —
Administrator only.

This module records **incoming goods**: the subsidy/source, its delivery details (DR
number), and the shipment of items into warehouses. When you record a delivery, the system
**adds the items to the warehouse's stock** and writes the receipt into the stock cards.

### 9.1 The Subsidies / Deliveries list

1. Click **Subsidies / Deliveries** in the sidebar.
2. The table shows one row per subsidy with: **Subsidy ID** (e.g. **SUB-000001**),
   **Supplier/Subsidy**, **Date**, **DR No.**, **Warehouse**, **Items**, **Total Cost**,
   **Status** (e.g. Pending / Fully Delivered / Partially Delivered / Archived), and
   **Actions**.
3. Use the search box and the status/source filters to narrow the list (see
   [Section 18](#18-searching-filtering-and-pagination)).

### 9.2 Creating a new delivery / subsidy

1. On the **Subsidies / Deliveries** page, click **New Delivery/Subsidy**.
   A form opens with the reminder:
   *"Complete the subsidy details below. Unit cost and destination warehouse are chosen
   later, when the delivery is dispatched."*
2. Fill in:
   - **Date** (required) — the date of the subsidy/delivery.
   - **Supplier/Subsidy** (required) — choose from the searchable dropdown
     (placeholder: **"— Select Supplier/Subsidy —"**).
   - The remaining header fields shown on the form (e.g. DR number details if applicable).
3. Add the items in the **Requested Items** section using the **Add Item** button. Each
   line needs an **item description** (from the searchable dropdown) and the **quantity**.
4. Click **Save Subsidy** (the button on the form).
   The system confirms: `Delivery / Subsidy created successfully.`

Your new subsidy receives its permanent **Subsidy ID** (e.g. `SUB-000005`) right away.

### 9.3 Recording a delivery (shipment) into a warehouse

A subsidy may arrive in one or several shipments. Each shipment is dispatched to a
warehouse, and at that point you choose the exact stock record, unit cost, and destination
warehouse.

1. Open the subsidy (click **View**).
2. Click **Record Delivery** (the page title is **Record Shipment**).
3. For each item line, choose:
   - the **warehouse** that will receive the goods,
   - the **quantity** to deliver for this shipment,
   - the **unit cost** (and **ENGAS unit cost** when applicable),
   - the **expiration date** (when applicable).
4. Submit the form. The system confirms: `Shipment recorded and stock updated.`

The delivered quantities are added to the warehouse's stock records and a **receipt** entry
appears in the stock cards. ⚠️ Every delivery is counted against the subsidy; a subsidy
cannot be marked **Fully Delivered** unless the delivered quantity reaches the requested
quantity (the system rejects it with a "Status cannot be set…" message otherwise).

### 9.4 Viewing a subsidy

The detail page shows:

- **Subsidy ID** (e.g. `SUB-000001`) and supplier/source information.
- The requested items and, for each, what was **already delivered**, the **unit cost**,
  **ENGAS unit cost**, and **remaining** quantity.
- The **Shipment Records**: date, warehouse, quantity, DR number, unit cost, and totals.
- Buttons: **Record Delivery**, **Edit Subsidy**, **Correct Subsidy**, **Audit Log**,
  **Archive/Restore**, **Print** (depending on your role). Each shipment row also has an
  **Edit Delivery** action.

### 9.5 Archiving and restoring

⚠️ Administrator only. Archiving removes a subsidy from normal lists and flags any related
stock transfers for review (nothing is deleted). Confirm text:
*"Archive RIS #…? Related stock transfers will be flagged for review — nothing is
deleted."*

Restoring reverses it — confirm text: *"Restore RIS #…? Related stock transfer flags will
be cleared."*

⚠️ An archived subsidy **cannot receive new deliveries** — the system replies:
`This Subsidy is archived. Restore it before recording new deliveries.`

---

## 10. Items / Inventory

**Availability:** ✅ View — all roles. ✅ Create — Administrator and Warehouse Manager.
❌ Edit / delete — Administrator only.

### 10.1 The Items list

1. Click **Items** in the sidebar.
2. The table shows: **Stock No.**, **Description**, **Unit**, **Category**, **Warehouse**,
   **Unit Cost**, **Engas Unit Cost**, **Expiration Date**, **Qty on Hand**, the **source
   Subsidy** (Subsidy ID), **Status**, and **Actions**.
3. Use the filters at the top (see [Section 18](#18-searching-filtering-and-pagination)):
   - **Category** dropdown
   - **Warehouse** dropdown
   - **Status** dropdown
   - **Search** box (by stock number or description)
   - **Subsidy Source** dropdown: *All* / *Related to Deleted/Archived Subsidy* /
     *Not Related to Deleted/Archived Subsidy*

**Badges you may see on items**

| Badge | Meaning |
|---|---|
| **Merged (N)** | The listing groups identical records (same description, unit cost, ENGAS, expiration, and warehouse) into one row; "N" is the number of records grouped. |
| **Out of Stock** | Quantity on hand is 0. |
| **Below Reorder Point** | Quantity is below the item's reorder point. |
| **From Deleted/Archived Subsidy** | The item's source subsidy has been deleted or archived. |

Click **View** on any row to open the item's detail page.

### 10.2 Creating an item

1. On the **Items** page, click **Add Item** (page title: **Add New Item**).
2. Fill in the form:
   - **Description** (required)
   - **Unit** (required), e.g. box, sack, piece
   - **Category** (from the searchable dropdown)
   - **Unit Cost** (₱)
   - **ENGAS Unit Cost** (₱, optional)
   - **Expiration Date** (optional)
   - **Quantity** (starting quantity, optional — usually 0 and stock comes in via
     deliveries)
   - **Warehouse** (required — from the searchable dropdown)
   - **Reorder Point** (optional)
3. Click **Save Item**. The system confirms:
   `Item created. Stock number will be assigned when a delivery / subsidy is delivered.`

### 10.3 Item detail page

The item page shows the full record: description, unit, category, account code, warehouse,
unit costs, expiration date, **source Subsidy ID**, quantity on hand, reorder point, when
it was created/updated, and the **current stock balance**. Below it you can view the
item's **stock card history** and **print** it.

### 10.4 Editing an item

⚠️ Administrator only.

1. On the Items page, click the **edit** (pencil) button.
2. Change the description, unit, category, costs, expiration, or reorder point.
3. Click **Update Item**. The system confirms: `Item updated successfully.`

**What cannot be edited here:**

- The **warehouse** — to move stock to another warehouse, use a **Stock Transfer**
  ([Section 13](#13-stock-transfers)).
- The **quantity** — quantities change only through deliveries, issues, and transfers
  (all recorded movements).

### 10.5 Deleting an item

⚠️ Administrator only, and only for items that have never been used. If the item is
referenced anywhere, deletion is blocked with one of these messages:

- `Cannot delete "…" — it is referenced by one or more Delivery / Subsidies.`
- `Cannot delete "…" — it is referenced by one or more Requisitions.`
- `Cannot delete "…" — it has already been dispatched against a Requisition.`

If the item is unused, deleting it shows `Item deleted.` — the item and its stock card
entries are permanently removed, so make sure the item truly is not needed before
deleting.

---

## 11. Requisitions / Augmentations (RIS)

**Availability:** ✅ View — all roles (own warehouses). ✅ Create — all roles (the
**New RIS** button appears for Administrators and Warehouse Managers; center roles should
coordinate with them if the button is not shown). ✅ Approve / issue — Administrator,
Warehouse Manager, Supply Custodian, Center Head. ❌ Edit / correct / delete —
Administrator only.

A Requisition and Issue Slip (**RIS**) is the official DSWD form used to request and issue
items. In WGIMS, one RIS contains:

- the **request** (what is wanted, and how much), and
- the **issuance** (what was actually given, from which warehouse and stock record, with
  the DR number).

### 11.1 Creating a new RIS

1. Click **Requisitions / Augmentations** in the sidebar.
2. Click **New RIS**. A form opens with the reminder:
   *"Complete the requisition details below. The warehouse, unit cost, expiry and DR number
   are set later, when the items are dispatched."*
3. In the **RIS Header** section, fill in:
   - **Date Requested** (required, defaults to today)
   - **Requested By** (defaults to your name) and **Requested By Designation**
   - **Entity Name** (defaults to "DSWD Region X"), **Fund Cluster**,
     **Responsibility Center Code**, **Office**, **Division**
   - **Purpose** (required)
4. In the **Requesting LGU** section, optionally enter the **Province** and **Municipality**.
5. In the **Requested Items** section:
   - Click **Add Item**.
   - Click into the **Item Description** field — a searchable dropdown appears. Type to
     search by item name or account code. Each result shows the item's account code, unit,
     and **"Available: …"** total stock (informational only).
   - Enter the **Requested Quantity** in the next column.
   - Add more lines with **Add Item**, or remove a line with its **remove** button.
6. Review the summary (**Requested Quantity** total, number of line items), then click
   **Save RIS**.

The system confirms: `Requisition created successfully.` and sends a **notification**
("RIS #… requires approval.") to the approvers.

**Note:** at the request stage there is **no stock check** — you may request more than is
currently available; the stock check happens at issuance time.

### 11.2 RIS numbers and statuses

- The **RIS number** is generated automatically in the format
  `RIS-YYYYMM-####` (e.g. `RIS-202608-0001`).
- A RIS is always in one of these statuses:

| Status | Meaning |
|---|---|
| **Pending** | Created; nothing issued yet. |
| **Partially Fulfilled** | Some items (or some of an item's quantity) have been issued. |
| **Approved** | Everything requested has been issued. |
| **Cancelled** | Manually cancelled by an Administrator (via Edit). |

The status is **recalculated automatically** from the issued quantities — you never set it
by hand (except Cancelled).

### 11.3 Viewing a RIS

The detail page shows:

- **RIS Information** card — RIS Number, Date Requested, Entity Name, Fund Cluster, Office,
  Division, Requesting LGU, Warehouse, Resp. Center Code, Purpose.
- **Status** card — the status badge, and "Approved on … by …" once approved.
- **Fulfilment Progress** — Qty Requested / Qty Issued / Still Outstanding, with messages
  such as `Partially fulfilled — X.XX units still outstanding.` or `No items issued yet.`
- **Requested Items** table — Stock No., Unit, Description, Warehouse, Expiration Date,
  Unit Cost, Engas Unit Cost, Engas Total Value, Qty Requested, Total Cost,
  Stock Available, Qty Issued, Outstanding, DR No., Remarks.
- **Partial Delivery Breakdown** — every issuance (dispatch) recorded for the RIS:
  date, warehouse, quantity, DR number, unit cost, value, cumulative total.
- **Signatories** — Requested By / Approved By / Issued By / Received By (name +
  designation each).
- Action buttons: **Approve** / **Issue Remaining Items**, **Signatories**,
  **Print RIS**, **Correction History**, **Correct RIS** (Administrator).

### 11.4 Signatories

Open the **Signatories** page from the RIS detail page. It has eight fields:
**Requested By — Name / Designation**, **Approved By — Name / Designation**,
**Issued By — Name / Designation**, **Received By — Name / Designation**.

⚠️ Administrator can edit these (button **Save Signatories**; the system confirms
`Signatories updated.`). Other roles can only view them. The printed RIS shows these four
signatories with blank **Signature** lines.

---

## 12. Dispatching and Issuing Items

**Availability:** ✅ Administrator, Warehouse Manager, Supply Custodian, Center Head.
❌ Center Staff.

Issuing stock **happens on the RIS approval screen**. When you approve a RIS, you also
choose exactly which stock to issue for each line. There is no separate "issue" menu.

### 12.1 Approving a RIS and issuing items

1. Open the RIS and click **Approve** (or **Issue Items**, or **Issue Remaining Items**
   for a partially fulfilled RIS).
   The screen opens with this reminder:
   *"Review the requested quantities. Choose the warehouse and exact stock record (item +
   unit cost) to issue each line from. Issuance is deducted only from that record — it will
   never fall back to another unit-cost/FIFO record, and each dispatch keeps its own
   warehouse and DR Number."*
2. For each line that still has an **Outstanding** quantity:
   - **Warehouse** — select the issuing warehouse from the dropdown
     (**"— Select Warehouse —"** first option). Only your assigned warehouses are listed
     (Administrators see all active warehouses).
   - **Stock Record** — select the exact record to issue from. The dropdown shows options
     like `{description} [{stock number}] · ₱{unit cost} · {quantity} {unit}`, with a hint
     `Available on this record: {qty}`. Select a warehouse first — the record list loads
     from it (**"— Loading stock records… —"** while loading).
   - **Quantity to Issue Now** — enter the quantity for this issuance (you may issue a line
     in parts, from different warehouses, on different dates).
   - **Unit Cost** — filled in automatically from the record (read-only).
   - **ENGAS Unit Cost** — filled in automatically; you may adjust it.
   - **Expiration Date** — filled in automatically if the record has one.
   - **DR Number** (required when you issue more than 0) — the Delivery Receipt number for
     this dispatch (placeholder `e.g. DR-001`).
   - Lines already fully issued show the **Fulfilled** badge instead of the fields.
3. In the **Approval Signatories** card, check the pre-filled names:
   **Approved By (Name)** and **Issued By (Name)** are required; **Received By** defaults
   to the requester.
4. Review the **RIS Summary** on the side (RIS number, warehouse(s), purpose, items,
   fulfilment bar), then click **Process Approval**.

The system confirms: `Requisition processed successfully.` and notifies the requester
("Your RIS #… has been fully fulfilled." / "…partially fulfilled. Some items are still
outstanding." / "…has been updated.").

### 12.2 What happens when you process approval

- The chosen quantity is **deducted from the exact stock record** you picked — only from
  that record, never from another record with a different unit cost.
- A **stock card "issue" entry** is written for the item.
- A **dispatch record** is saved with the warehouse, quantity, unit costs, expiration
  date, and DR number you entered.
- The RIS status recalculates (Pending → Partially Fulfilled → Approved).

### 12.3 Rules and error messages at issuance

The system checks every line before saving. Common rejections (verbatim):

| Situation | Message |
|---|---|
| You try to issue from a warehouse you are not assigned to | `You are not assigned to this warehouse.` |
| The stock record does not belong to the chosen warehouse | `The selected item does not belong to the selected warehouse.` |
| More issued than available on that record | `Insufficient stock on the selected record "…" (… · ₱…): only … available. No other unit-cost record will be used.` |
| More issued than still outstanding | `Cannot issue more than the outstanding quantity (…) for "…".` |

### 12.4 Editing an issued item (dispatch)

⚠️ Administrator only. If a dispatch must be corrected (wrong record, quantity, cost, DR
number, or warehouse):

1. Open the RIS and find the **Partial Delivery Breakdown**.
2. Click the **edit (pencil)** button on the dispatch row.
3. In the **Edit Issued Item** dialog, change the Warehouse, Stock Record, Quantity Issued,
   Unit Cost, ENGAS Unit Cost, Expiration Date, or DR Number.
   The dialog explains: *"Stock is reversed from the old record and re-applied to the exact
   new record — never double-counted or lost."* and shows a summary of the changes
   ("Changes to be applied:").
4. Confirm. The stock deduction moves from the old record to the new one, and the stock
   cards are recalculated.

---

## 13. Stock Transfers

**Availability:** ✅ View — all roles (own warehouses). ✅ Create and dispatch —
Administrator and Warehouse Manager. ❌ Edit / delete — Administrator only.

A **stock transfer** moves stock from one warehouse (**source**) to another
(**destination**). Transfers are recorded in **two steps**: first the *request*, then the
*dispatch* (which can be done in several shipments).

### 13.1 Creating a transfer request

1. Click **Stock Transfers** in the sidebar.
2. Click **New Transfer** (opens the **New Stock Transfer** form).
3. Fill in:
   - **Transfer Date** (defaults to today).
   - **Source Warehouse** — where the stock leaves from.
   - **Destination Warehouse** — where it arrives. ⚠️ These must be different; otherwise
     the system rejects it with `Source warehouse and destination warehouse must be
     different.`
4. In the items table, click **Add Item**, then:
   - Choose the **item** from the searchable dropdown (lists the source warehouse's stock
     records with stock number, description, unit cost, and available quantity).
   - Enter the **quantity** to transfer.
   - Add more lines as needed, or remove a line.
5. Click **Save Transfer**.

The system confirms:
`Transfer request created. Use "Dispatch Items" to send stock in one or more shipments.`
The transfer gets its automatic **transfer number** (e.g. `TRF-2026-0001`).

### 13.2 Dispatch items

1. On the **Stock Transfers** page, open the transfer (**View**), then click
   **Dispatch Items** (or **Dispatch Remaining** when partially dispatched).
2. On the **Dispatch Transfer** screen, check the **Dispatch Date** and, for each item,
   enter the **Qty This Dispatch** (the remaining amount and the stock available are shown;
   you may send it in parts).
3. Click **Dispatch & Update Stock**. The system confirms:
   `Dispatch recorded and stock updated.`

**What happens:**

- The dispatched quantity is **deducted from the source warehouse's stock record**.
- A stock record for the same item (same description, unit cost, and subsidy origin) is
  **created or increased in the destination warehouse** — the destination keeps the exact
  subsidy identity, so stock remains traceable to its Subsidy ID.
- The transfer status recalculates: **Pending** → **Partial** (some dispatched) →
  **Completed** (all dispatched).

⚠️ Once a transfer is fully dispatched, further dispatches are blocked:
`This transfer is already fully completed.`

### 13.3 Editing a transfer

⚠️ Administrator only. Open the transfer and click **Edit Transfer** (the page shows
**Admin Edit Mode**). You can change the **Transfer Date**, the **remarks**, and each
line's **quantity** and **unit cost** — the source and destination stock is adjusted
automatically by the difference. The system confirms: `Transfer updated and stock adjusted.`

⚠️ The source and destination warehouses are fixed once the transfer is created. Note also
that changing the quantity changes stock in both warehouses by the difference — the stock
ledger is always kept in balance.

### 13.4 Deleting a transfer

⚠️ Administrator only. When a transfer is deleted, the dispatched stock is returned to the
source warehouse — the system confirms: `Transfer … deleted and stock reversed.`

However, if the transferred stock has **already been used** (e.g. issued to a RIS or
transferred onward), the system blocks the deletion:
`This transfer cannot be deleted yet because the transferred stock has since been used by: …
Resolve those transactions first, then delete this transfer.`

### 13.5 Viewing a transfer

The transfer detail page shows the transfer number, date, **Source → Destination**
warehouses, status, requested items (Stock No., Description, Unit, Unit Cost, Requested,
Dispatched, Remaining), the **dispatch breakdown** per shipment, and — when the transfer
relates to a deleted/archived subsidy — a review panel about the source subsidy.

---

## 14. Stock Cards

**Availability:** ✅ All roles (own warehouses). Read-only.

Stock cards are the running ledgers of every item. WGIMS updates them automatically after
every receipt, issue, and transfer.

### 14.1 Finding an item's stock card

1. Click **Stock Cards** in the sidebar.
2. You first see a **summary by category** (each category with its item count and
   quantities). Click a category to open its page.
3. The category page lists the items with their balances. Click an item to open its
   **item history**.

### 14.2 Reading the item history

The history page shows every movement of that item, newest first (or oldest first —
see the display options on the page):

| Column | Meaning |
|---|---|
| **Date** | When the movement happened |
| **Reference** | The document that caused it (e.g. a RIS number, DR number, or transfer number) |
| **Movement** | **Receipt** (delivery in), **Issue** (issued to a RIS), or **Transfer In / Transfer Out** |
| **Qty** | The quantity received or issued |
| **Running Balance** | The remaining quantity after that movement |

The page also shows totals and the **current balance**, and lets you **print** the card.
Items can be viewed **by unit cost** (separate batches per cost) so that each batch's
movements are tracked independently — the same item at a different unit cost is shown as a
separate batch.

### 14.3 Keeping stock cards accurate

You cannot type on a stock card. The only way to change a balance is through a real
movement: a **delivery** (adds), an **issue/dispatch** (deducts), or a **transfer**
(moves). If a card looks wrong, check the underlying movements first, then ask the
Administrator to correct the cause (never "adjust" quantities directly — the system has no
manual adjustment; corrections are done through the correct/edit functions described in
[Section 19](#19-editing-and-correcting-records)).

---

## 15. Inventory Balance Report

**Availability:** ✅ Administrator and Warehouse Manager only. ❌ Other roles.

1. Click **Inventory Balance** in the sidebar.
2. The report shows current stock grouped by **warehouse → category → description**, with
   quantity on hand, unit cost, and total value per item, category subtotals, and a grand
   total.
3. Use the **Warehouse** filter (and the page's other filters) to narrow the report.
4. Use the **Export** (Excel) button to download, or **Print** for a printable copy.

Because WGIMS updates stock cards after every movement, this report is always current. In
the RPCI era of the example in [Section 24](#24-end-to-end-example), the Inventory Balance
is the "living" version of the RPCI snapshot.

---

## 16. Other Reports (RPCI and RSMI)

**Availability:** ✅ All roles (warehouse-scoped for non-admins). Read-only (plus print and
Excel export).

Both reports are the official DSWD report forms. WGIMS generates them from the records, so
**do not type the forms by hand** — print them from the system.

### 16.1 RPCI — Report on Physical Count of Inventories

1. Click **RPCI Report** in the sidebar.
2. Set the filters:
   - **Warehouse** (Administrator / Warehouse Manager see all warehouses; others see their
     own). ⚠️ All users can filter by warehouse.
   - **Category** (**All Categories** or a specific one).
3. The report lists only **active** items, ordered by category and description. Columns:
   **Stock No., Description, Unit, Category, Warehouse** (admin view), **Qty on Hand,
   Unit Cost, Engas Unit Cost, Engas Total Value** (admin view), **Total Value**.
   Categories appear as header rows (e.g. `Food — Account Code: 50201010`), and the report
   ends with a **GRAND TOTAL**.
4. Click **Print Official RPCI** to open the printable official form
   ("Report on the Physical Count of Inventories" with Per Card / Per Count quantity
   columns, Unit Value, Total Value, and Remarks — choose **Paper Size:** A4 or Legal, then
   **Print**).
5. Click **Export Excel** to download the data as a spreadsheet.

### 16.2 RSMI — Report of Supplies and Materials Issued

1. Click **RSMI Report** in the sidebar.
2. Filter by **search** ("Search RIS No., office, purpose…"), **Warehouse**, and
   **Date From / Date To**.
3. The table shows: **Stock No., Description, Unit, Qty Requested, Qty Issued,
   Outstanding, Unit Cost (₱), Engas Unit Cost (₱), Engas Total Value (₱), Amount (₱)** —
   i.e. what was issued against each RIS line in the period.
4. Click **Print All RSMI** to open the printable official form ("Report of Supplies and
   Materials Issued" with columns RIS No., Responsibility Center Code, Stock No., Item,
   Unit, Quantity Issued, Unit Cost, Amount; choose Paper Size and print), or **Export
   Excel** for a spreadsheet download. Each RIS card on the page also has its own
   **Print RSMI** button to print that single RIS's report.

**Snapshots.** Both reports allow saving a **snapshot** (a frozen copy for a period, with a
Serial No. and the name of who saved it). A saved snapshot stays unchanged even if stock
later moves, which is useful for end-of-month reporting.

---

## 17. Users (Administrator Only)

**Availability:** ❌ All non-admin roles. ✅ Administrator only.

1. Click **Users** in the sidebar.
2. The table lists every account: **Username, Name, Role, Warehouses, Status, Actions**.
3. Click the **view** button on a user to see their details, including their assigned
   warehouses and role description.

### 17.1 Creating a user account

1. Click **Add User** (page title: **Add New User**).
2. Fill in:
   - **Username** (required, unique — the system checks whether it is already taken)
   - **Name** (required)
   - **Email** (optional)
   - **Role** — one of the five roles (see [Section 3](#3-user-roles-and-permissions))
   - **Warehouses** — tick the warehouses this user may access. ⚠️ For Supply Custodian,
     Center Staff, and Center Head accounts, **at least one warehouse is required**
     (the system rejects an empty selection with `Please select at least one warehouse.`).
     Administrator and Warehouse Manager accounts see all warehouses without needing
     assignments.
   - **Password** (minimum 8 characters) — give it to the user securely
3. Click **Create User**. The system confirms: `User created successfully.`

### 17.2 Editing a user

1. Click the **edit** (pencil) button.
2. Change the name, email, role, assigned warehouses, or **Active** status. To set a new
   password, type one in the **New Password** field (leave blank to keep the current
   password).
3. Click **Update User**. The system confirms: `User updated.`

**What happens when you deactivate a user (Active = off):** that user can no longer sign
in (`"Invalid credentials or account is inactive."`), but all their past records remain
in the system with their name.

⚠️ There is **no delete** for users — accounts are deactivated instead, so the audit trail
of who did what is never broken.

---

## 18. Searching, Filtering, and Pagination

Every list page in WGIMS follows the same pattern:

### 18.1 Search boxes

- Type a few letters into the search box and click **Search** (or press **Enter**).
- Searches match partial words. For example, on the Requisitions page the placeholder is
  "Search RIS#, DR#, office, purpose..." — typing `BEG` finds every RIS containing "BEG"
  in any of those fields.
- Click **Clear** (or **Reset**) to remove the search and show everything again.

### 18.2 Dropdown filters

- Pages such as Items, Subsidies, Requisitions, Transfers, and the reports have filter
  dropdowns (e.g. Status, Warehouse, Category, Subsidy Source, Date From / Date To).
- Pick a value and click **Filter**. "All" or "All Status" shows everything.
- ❌ Items with no active record may be hidden from some dropdowns by design (e.g.
  deactivated suppliers and categories do not appear for new records).

### 18.3 Pagination

- Lists show **20 records per page** by default, with **Previous / Next** links (and page
  numbers) at the bottom.
- The search and filter settings are kept as you move between pages, so the pages you
  click still respect your filters.

### 18.4 Searchable dropdowns (comboboxes)

Many forms use **searchable dropdowns** for items, suppliers, categories, and warehouses.
They behave like this:

- Click the field, then **start typing** — the list narrows to matching options.
- Use the **arrow keys** to move and **Enter** to select (or click an option).
- **Escape** closes the list.
- If the list is long, it shows the first options plus a hint like "N more — keep typing".
- If nothing matches, it shows **"No matching options"**.
- The list stays open while you scroll through it.

---

## 19. Editing and Correcting Records

WGIMS separates the *request* from the *movement*. This is important:

- Editing the **request** (e.g. the requested quantity on a RIS, or the requested items on
  a subsidy) **never changes stock**.
- Correcting a **movement** (an issued dispatch, a recorded shipment, a transfer dispatch)
  **does change stock**, and the stock cards are recalculated automatically.

### 19.1 Editing vs. Correcting

| Module | Edit (request/header) | Correct (movement) |
|---|---|---|
| **Subsidies / Deliveries** | **Edit Subsidy** changes the header and requested line items (Administrator). Lines already delivered cannot be changed — only their requested quantity (`This item has already been delivered and cannot be changed. Correct the request quantity only.`). | **Edit Delivery** (on each row in the Shipment Records; page **Edit Shipment**) fixes an issued shipment — stock is adjusted automatically (`Shipment updated and stock adjusted.`). |
| **Requisitions (RIS)** | **Edit Requisition** changes header and requested quantities (Administrator). A line's requested quantity cannot go below what was already issued (`Quantity requested cannot be less than the already issued quantity (…).`). | **Correct RIS** changes only the request — the modal explains: *"Fix the request itself — header and requested quantities. Existing dispatches, DR numbers and stock deductions stay untouched."* To fix an issued shipment, use **Edit Issued Item** in the Partial Delivery Breakdown instead. |
| **Transfers** | **Edit Transfer** (**Admin Edit Mode**) changes the transfer date, remarks, and each line's quantity and unit cost — source and destination stock is adjusted automatically (`Transfer updated and stock adjusted.`). | There is no separate dispatch edit; a dispatch's stock is corrected by editing the transfer itself or by deleting it and starting over. |
| **Items** | **Edit item** changes description, unit, category, costs, expiration, reorder point (Administrator). Warehouse and quantity cannot be edited. | No direct quantity edit — use delivery, issue, or transfer movements. |

### 19.2 The Correct RIS workflow (example)

1. Open the RIS and click **Correct RIS** (Administrator).
2. Read the modal reminder — corrections **never** touch issued stock:
   *"Inventory protection: this correction only changes the request. Issued stock, dispatch
   records (warehouse, quantity, unit cost, ENGAS, DR, expiry) and stock cards are left
   exactly as they are. To correct an issued shipment, use the Edit Dispatch action in the
   Partial Delivery Breakdown."*
3. If the RIS is already complete, tick the acknowledgment checkbox:
   *"I understand — I only want to correct the request details, not change issued stock."*
   (The Save button stays disabled until you tick it.)
4. Change the header fields and/or requested quantities. Lines already issued are marked
   **locked** ("This line has already been issued — its item cannot be changed") and their
   quantity cannot go below what was issued (`Requested quantity (X.XX) cannot be less than
   the Y.YY already issued. Correct the issued dispatch(s) first, then fix the request.`).
5. Click **Save Correction**. Confirm when asked:
   *"Save this correction? The request will be corrected and the RIS status recalculated.
   Dispatches, DR numbers and inventory are NOT changed. Continue?"*
6. Every change is written to the **Correction History** (open via **Correction History**
   on the RIS page) with the date, the person who corrected, and the old → new values.

### 19.3 Who may edit or correct

- **Administrator:** all edit and correct functions.
- **Warehouse Manager, Custodian, Center Head, Center Staff:** ❌ editing and correcting.

---

## 20. Deleting Records

Deletion is **Administrator only**, and WGIMS protects your records. A record can be
deleted only when it is not needed for history — and stock is never silently lost.

### 20.1 The rules of deletion

| Record | What happens | Blocked when |
|---|---|---|
| **Item** | Permanently deleted together with its stock card entries (Administrator). | Referenced by deliveries, requisitions, or dispatched against a RIS (messages in [Section 10.5](#105-deleting-an-item)). |
| **Supplier** | Deleted only if never used. | Already referenced by delivery records. Deactivate instead. |
| **Category** | Deleted only if it has no items. | Has items assigned — deactivate instead. |
| **Subsidy / Delivery** | Deleted by Administrator; **all delivered stock is reversed** (subtracted back), the delivery's stock-card receipts are removed, related stock transfers are **preserved and flagged** as "Related to Deleted Subsidy" for review, and items that existed only because of this subsidy (now empty and unreferenced) are removed. Confirm text: `Delete RIS #…? This will reverse all delivered stock quantities. Related stock transfers will be preserved and flagged for review.` Success: `DR #… deleted and stock reversed. … related stock transfer(s) were preserved and flagged as "Related to Deleted Subsidy" for review.` | When in doubt, **Archive** instead of Delete — archiving keeps the subsidy visible for review. |
| **RIS (Requisition)** | Deleted with a warning; **any stock already issued is reversed back into inventory**. Confirm text: `Delete RIS #…? This will permanently delete the requisition. Any stock that was already issued will be reversed back to inventory.` | Non-administrators (403: `Only administrators can delete requisitions.`). |
| **Transfer** | Deleted; **dispatched stock is reversed to the source warehouse** (`Transfer … deleted and stock reversed.`). | The transferred stock has since been used (issued or transferred onward): `This transfer cannot be deleted yet because the transferred stock has since been used by: … Resolve those transactions first, then delete this transfer.` |

### 20.2 Best practice

- Prefer **deactivating** (categories, suppliers) or **archiving** (subsidies) over
  deleting — history stays intact for reporting and audit.
- Deleting a RIS or transfer **moves stock back** — double-check before you confirm; every
  confirm dialog tells you exactly what will happen.

---

## 21. Notifications and Validation Messages

### 21.1 Notifications (bell icon)

- A **red number badge** on the bell shows unread notifications; it refreshes about every
  30 seconds.
- Click the bell to open the notifications list, then click a notification to go to the
  related record.
- Use **Mark as read** on a single notification (`Notification marked as read.`) or
  **Mark all as read** (`All notifications marked as read.`).
- You are notified when: a **new RIS is submitted** ("RIS #… requires approval." — to
  approvers), a RIS you requested is **fulfilled / partially fulfilled / updated**
  ("Your RIS #… has been fully fulfilled." etc.), and for similar system events.

### 21.2 Success messages

After every successful save, a green confirmation appears at the top of the page. Common
ones:

| Action | Message |
|---|---|
| Subsidy created | `Delivery / Subsidy created successfully.` |
| Shipment recorded | `Shipment recorded and stock updated.` |
| Shipment corrected | `Shipment updated and stock adjusted.` |
| Item created | `Item created. Stock number will be assigned when a delivery / subsidy is delivered.` |
| Item updated / deleted | `Item updated successfully.` / `Item deleted.` |
| RIS created | `Requisition created successfully.` |
| RIS processed (issued) | `Requisition processed successfully.` |
| RIS updated / corrected | `Requisition updated successfully.` |
| RIS deleted | `RIS #… deleted and any issued stock has been reversed.` |
| Signatories | `Signatories updated.` |
| Transfer created | `Transfer request created. Use "Dispatch Items" to send stock in one or more shipments.` |
| Transfer dispatched | `Dispatch recorded and stock updated.` |
| Transfer updated | `Transfer updated and stock adjusted.` |
| Transfer deleted | `Transfer … deleted and stock reversed.` |
| Supplier / category / warehouse / user | `Supplier added successfully.`, `Category "…" created successfully.`, `Warehouse created.`, `User created successfully.` and their update variants. |
| Report snapshot | `RPCI snapshot saved.` / `RSMI snapshot saved.` |

### 21.3 Validation and error messages

When a form cannot be saved, a red banner appears with the heading
**"Please fix the following errors:"** listing each problem. Fields with errors are also
highlighted. The messages are written in plain language, for example:

- `The purpose field is required.`
- `The selected item is no longer available.`
- `Source warehouse and destination warehouse must be different.`
- `Insufficient stock on the selected record "…" (… · ₱…): only … available. No other
  unit-cost record will be used.`

### 21.4 The "no changes were made" message

If the system hits an unexpected problem while saving, it protects your data and shows:

`The transaction could not be completed. No changes were made. Please try again.`

No partial changes are saved. Retry, and if it keeps happening, tell the Administrator.

---

## 22. Common Problems and Troubleshooting

| Problem | Cause and what to do |
|---|---|
| **I cannot sign in.** | Wrong username/password, or the account is deactivated: `Invalid credentials or account is inactive.` Ask the Administrator to reset the password or reactivate the account. There is no "Forgot Password" — see [Section 4](#4-logging-in). |
| **I keep getting blocked after failed attempts.** | The system limits attempts per minute. Wait a minute, then try again. |
| **A page says "403" / "You are not allowed".** | You do not have permission for that action (e.g. editing as a non-administrator) or the record is outside your assigned warehouses. Ask the Administrator. |
| **"You are not assigned to this warehouse."** | You tried to issue/transfer from a warehouse that is not assigned to your account. |
| **"Insufficient stock on the selected record…"** | The exact stock record you chose does not have enough quantity. Choose a different record (a different unit-cost record is a *different* record) or issue less. |
| **"Cannot issue more than the outstanding quantity…"** | You tried to issue more than what remains outstanding on the RIS line. |
| **"No items are being dispatched — enter a quantity for at least one item with remaining stock."** | You submitted a delivery/dispatch with all quantities at zero. |
| **I don't see a button that this manual mentions.** | The button depends on your role (e.g. **New RIS** shows for Administrators and Warehouse Managers; **Edit/Delete** shows only for Administrators) and on the record's status. |
| **I cannot find a record.** | Use the search/filters ([Section 18](#18-searching-filtering-and-pagination)). Archived or deactivated records are hidden from normal lists — an Administrator can restore them. |
| **The quantity on an item looks wrong.** | Quantities change only through movements. Open the item's **Stock Card** to see every receipt/issue/transfer. If it is still wrong, the Administrator corrects the underlying movement — there is no manual quantity field. |
| **Two similar items do not combine / merge.** | They come from different **Subsidies** (or have different unit cost/ENGAS/expiration) — see the inventory grouping rule in [Section 2.2](#22-important-concepts). They are intentionally separate. |
| **I cannot delete a record.** | Deletion is Administrator-only and blocked for referenced records. Deactivate or archive instead ([Section 20](#20-deleting-records)). |
| **"The selected item is no longer available."** | The catalog item you picked was deactivated. Pick a different item. |
| **"Quantity requested cannot be less than the already issued quantity…"** | You cannot reduce a request below what was already issued. Correct the issued dispatch first, then the request. |
| **"This item has already been delivered and cannot be changed…"** | On a subsidy, delivered lines can only have their requested quantity corrected — the item itself is fixed. |
| **"This transfer cannot be deleted yet…"** | The transferred stock has already been used. Resolve those transactions first ([Section 13.4](#134-deleting-a-transfer)). |
| **"This Subsidy is archived…"** | Restore the subsidy before recording new deliveries ([Section 9.5](#95-archiving-and-restoring)). |
| **The printed form looks different from the screen.** | The print views are the official DSWD forms (A4 or Legal); screens are for data entry. Choose the paper size in the print toolbar. |
| **"The transaction could not be completed…"** | Unexpected system error; no changes were saved. Retry; if it persists, contact the Administrator. |
| **I can't reach the system at all.** | Check your internet/VPN connection and the web address. If the server is down, only the Administrator can restart it. |

---

## 23. Frequently Asked Questions

**Q: How do I know which subsidy my stock came from?**
A: Every stock record shows its **Subsidy ID** (SUB-######) on the Items list, item detail,
stock cards, transfers, and requisitions. The ID is permanent and never reused, so you can
always trace the origin.

**Q: When do stock records merge into one?**
A: Records with the **same Subsidy ID, description, unit cost, ENGAS unit cost, expiration
date, and warehouse** are shown grouped (with a "Merged" badge). If anything differs — most
commonly a different subsidy — they stay separate forever.

**Q: Can I issue an item without choosing a warehouse?**
A: No. Every issuance requires a **warehouse** and an exact **stock record** (item + unit
cost). This is deliberate — each dispatch keeps its own warehouse, DR number, and costs,
and stock is deducted only from the record you chose.

**Q: Can one RIS line be issued from different warehouses?**
A: Yes. A line "may be issued in parts from different warehouses" — each part is its own
dispatch with its own DR number.

**Q: Does approving a RIS immediately deduct stock?**
A: Yes. Stock is deducted **when you process approval** (the issuance), not when the RIS is
created.

**Q: What if I requested more than the stock available?**
A: At request time there is no limit. At issuance time the system checks the exact record
and refuses to issue more than is available on it.

**Q: I made a mistake on an issued dispatch. What do I do?**
A: Ask the Administrator to use **Edit Issued Item** (in the RIS Partial Delivery
Breakdown) or the **Edit Delivery** function — stock is reversed from the old record and
applied to the new one, and the stock cards recalculate automatically.

**Q: Can I fix a RIS request without touching stock?**
A: Yes — **Correct RIS** changes only the request (header + requested quantities); it never
touches dispatches, DR numbers, or inventory (see [Section 19.2](#192-the-correct-ris-workflow-example)).

**Q: Can I move stock between warehouses without a RIS?**
A: Yes — use **Stock Transfers** ([Section 13](#13-stock-transfers)). The destination
warehouse gets a new record with the same subsidy identity.

**Q: Why is the Inventory Balance menu missing for me?**
A: That report is available only to Administrators and Warehouse Managers
([Section 15](#15-inventory-balance-report)).

**Q: Who sees my RIS?**
A: Approvers (Administrator, Warehouse Manager, Supply Custodian, Center Head) get a
notification; users of the same warehouse can view it; everyone can see it if they have
warehouse access to its lines.

**Q: Can I delete my own user account?**
A: No. Accounts are managed (and deactivated, not deleted) by the Administrator
([Section 17](#17-users-administrator-only)).

**Q: I forgot my password.**
A: The system intentionally has **no "Forgot Password"**. Ask the Administrator to set a
new one for you.

---

## 24. End-to-End Example

Follow one welfare good (canned goods) through the whole system. This is the same workflow
you will use in real operations.

**Setup (done once by the Administrator)**
1. Administrator creates the category **Food** with its account code.
2. Administrator creates the supplier **Example LGU**.
3. Administrator creates warehouses **GAMC1** and **GAMC2**, and assigns users to them.

**Step 1 — Record the subsidy**
4. Warehouse Manager opens **Subsidies / Deliveries** → **New Delivery/Subsidy**.
5. Date = today, Supplier/Subsidy = **Example LGU**, requested items: *Canned Goods*, 500 boxes.
6. Save. The system shows `Delivery / Subsidy created successfully.` with Subsidy ID
   **SUB-000001**.

**Step 2 — Receive the goods**
7. Open SUB-000001 → **Record Delivery**.
8. Warehouse = **GAMC1**, Quantity = 500, Unit Cost = ₱250.00, Expiration Date = next year.
9. Save → `Shipment recorded and stock updated.` Stock at GAMC1 is now **500 boxes**.
   A receipt appears in the stock card.

**Step 3 — Move part of it (optional)**
10. **Stock Transfers** → **New Transfer**: Source = GAMC1, Destination = GAMC2,
    Canned Goods × 100. Save →
    `Transfer request created. Use "Dispatch Items" to send stock in one or more shipments.`
11. Open the transfer → **Dispatch Items** → dispatch 100. Save →
    `Dispatch recorded and stock updated.` Now: GAMC1 = 400, GAMC2 = 100. Both records
    still show Subsidy ID SUB-000001.

**Step 4 — Request the goods**
12. **Requisitions / Augmentations** → **New RIS**.
13. Purpose = "Feeding program", items = Canned Goods × 200. Save →
    `Requisition created successfully.` Status = **Pending**; approvers get a notification.

**Step 5 — Approve and issue**
14. Approver opens the RIS → **Approve**.
15. For the line: Warehouse = **GAMC1**, Stock Record = the SUB-000001 record at
    ₱250.00, Quantity to Issue = 200, DR Number = DR-2026-001. Confirm signatories →
    **Process Approval** → `Requisition processed successfully.`
16. GAMC1 is now at **200 boxes**. Status = **Approved**. The requester gets
    "Your RIS #RIS-202608-0001 has been fully fulfilled."

**Step 6 — Check the records**
17. **Stock Cards** → Canned Goods shows: Receipt +500 (GAMC1), Transfer Out −100 (GAMC1),
    Transfer In +100 (GAMC2), Issue −200 (GAMC1); running balances always correct.
18. **Inventory Balance** (Admin/WM) shows GAMC1 = 200 boxes (₱50,000.00) and
    GAMC2 = 100 boxes (₱25,000.00).
19. **RPCI Report** → **Print Official RPCI** produces the physical count form with
    Per Card = Per Count = 200 for GAMC1.

**Step 7 — Monthly close**
20. On the RPCI and RSMI pages, **save a snapshot** for the month so the period's figures
    are frozen even after new movements.

---

## 25. Quick Reference Guide

### Login and logout
- Sign in with **Username + Password** → **Sign In**. No "Forgot Password"; ask the
  Administrator. Log out via the **sidebar sign-out icon**.

### Everyday workflows (one-line summaries)
- **Receive goods:** Subsidies / Deliveries → New Delivery/Subsidy → Record Delivery.
- **Request items:** Requisitions / Augmentations → New RIS → fill header + items → Save RIS.
- **Issue items:** open RIS → Approve → choose warehouse, stock record, quantity, DR # →
  Process Approval.
- **Move stock:** Stock Transfers → New Transfer → Dispatch Items.
- **Check a balance:** Stock Cards → category → item, or Inventory Balance (Admin/WM).
- **Print a report:** RPCI Report / RSMI Report → filter → Print (choose paper size) or
  Export Excel.
- **Fix a request:** open record → Correct/Edit (Administrator).
- **Fix a movement:** RIS → Partial Delivery Breakdown → Edit Issued Item (Administrator);
  Subsidy → Edit Delivery (Administrator).

### Number formats
| Document | Format | Example |
|---|---|---|
| Subsidy ID | `SUB-` + 6 digits | `SUB-000001` |
| RIS number | `RIS-YYYYMM-####` | `RIS-202608-0001` |
| Transfer number | `TRF-YYYY-NNNN` | `TRF-2026-0001` |
| Stock number | auto-generated per record | — |
| DR number | typed by the user at dispatch | `DR-001` |

### Status meanings (quick)
| Badge | Meaning |
|---|---|
| Pending | Requested, not yet issued |
| Partially Fulfilled | Some items issued |
| Approved | Fully issued |
| Cancelled | Cancelled by Administrator |
| Active / Inactive | On / off (items, suppliers, categories, warehouses, users) |
| Merged (N) | Identical records grouped for display |
| Out of Stock / Below Reorder Point | Quantity warnings on items |
| From Deleted/Archived Subsidy | Source subsidy no longer active |

### Role quick card
| Role | Creates | Approves/Issues | Edits/Deletes |
|---|---|---|---|
| Administrator | ✅ everything | ✅ | ✅ everything |
| Warehouse Manager | ✅ items, deliveries, RIS, transfers | ✅ | ❌ |
| Supply Custodian | ✅ RIS | ✅ | ❌ |
| Center Head | ✅ RIS | ✅ | ❌ |
| Center Staff | ✅ RIS | ❌ | ❌ |

### When in doubt
- Green messages = saved. Red banner "Please fix the following errors:" = fix and resave.
- `The transaction could not be completed. No changes were made. Please try again.` =
  nothing was saved; retry, then call the Administrator.
- Stock only changes through **movements** (delivery, issue, transfer) — never by typing
  a quantity.
- **Ask the Administrator** for: password resets, corrections of issued stock, deletions,
  new user accounts, and warehouse assignments.
