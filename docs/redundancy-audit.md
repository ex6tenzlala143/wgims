# WGIMS Redundancy Audit Report

Date: 2026-08-20
Scope: Every page, table, modal, form, card, detail view, and report in the system.

## How findings were classified

- **Safe to remove** — genuinely duplicate display with no information loss.
- **Needs correction** — fields/labels suggest different meanings but display the same data; the data mapping or display must be fixed, not just hidden.
- **Important — keep** — looks similar but is distinct traceability information (Subsidy ID / RIS No. / DR No. / Stock Transfer No. / Stock No. are all distinct identifiers).
- **Not actually redundant** — values may coincide but each field has its own meaning.

## Findings

| Page | File:line | Element 1 | Element 2 | Problem | Category | Recommended Action |
|---|---|---|---|---|---|---|
| Items (detail) | `items/show.blade.php:36` vs `:52-58` | RIS No. row (`item->ris_number`) | Source Subsidy row shows `sourceSubsidyReference()` (the originating subsidy's RIS) | The item's `ris_number` is always copied from the originating subsidy's `ris_number` at delivery (`DeliverySubsidyController.php:1433,1483,1488`), so the same RIS string is displayed twice under two different labels. | Needs correction | Source Subsidy must identify the **Subsidy** (SUB-xxxxxx) + DR. Remove the duplicated RIS display from the Source Subsidy row. **DONE** |
| Subsidy (detail) | `delivery_subsidies/show.blade.php:103-119` | "Status & Summary" card: Qty Requested / Qty Delivered tiles | "Delivery Fulfilment" card: Qty Requested / Qty Delivered stats (lines 156-178) | Same two quantities displayed twice on the same page in two cards. | Safe to remove | Remove the Qty Requested / Qty Delivered tiles from the Status & Summary card; the Fulfilment card is the single source. **DONE** |
| Requisition (edit) | `requisitions/edit.blade.php:189` | `ris-item-meta` strip: "Requested X" | "Requested Quantity" input pre-filled with the same value (line 159-166) | Same value shown twice within the same item card. | Safe to remove | Remove the "Requested" span from the meta strip; keep Unit and Dispatched From. **DONE** |
| Transfer (detail) | `transfers/show.blade.php:121-123` vs `:189` | Status badge in "Transfer Information" card | Status badge in "Dispatch Progress" card header | Same status badge shown twice on one page. | Safe to remove | Keep the badge in Transfer Information; remove from Dispatch Progress header. **DONE** |
| Transfer (detail) | `transfers/show.blade.php:242-246` | "Dispatch remaining →" link in route line | "Dispatch Items"/"Dispatch Remaining" button in page header (line 15-20) | Duplicate dispatch action on the same page. | Safe to remove | Remove the CTA link from the route line; keep the header button. **DONE** |
| RSMI report | `reports/rsmi.blade.php:123-125` | Warehouse badge (`warehouse_names` or code) | Plain-text warehouse (`warehouse_names` or name) | Same warehouse displayed twice in one header line. | Needs correction | Show the warehouse once (badge with name/names). **DONE** |
| RSMI report | `reports/rsmi.blade.php:139-141` vs `:221-228` | Card header: "X item(s) · ₱subtotal" | Table footer: "RIS Sub-Total: ₱subtotal" | Subtotal displayed twice in the same card. | Safe to remove | Keep item count in header; subtotal lives in the table footer. **DONE** |
| Record Shipment | `delivery_subsidies/delivery.blade.php:305-314` | Sidebar "Requested" / "Delivered" tiles | Fulfilment Progress card (lines 28-49) shows the same two figures | Same requested/delivered totals displayed twice; only the sidebar's "After this shipment" box is dynamic. | Safe to remove | Remove the static Requested/Delivered tiles from the sidebar; keep the live "This Shipment" + "After this shipment" boxes. **DONE** |
| Correct Subsidy modal | `delivery_subsidies/_correct_subsidy_modal.blade.php:410-411` | "X already delivered" microcopy under Requested Qty input | "Delivered" stat column (line 415-417) | Delivered quantity shown twice per line. | Safe to remove | Keep the Delivered column; keep only the "cannot go below" hint for locked lines. **DONE** |
| Correct RIS modal | `requisitions/_correct_ris_modal.blade.php:411-412` | "X already issued" microcopy under Requested Qty input | "Issued" stat column (line 415-418) | Issued quantity shown twice per line. | Safe to remove | Keep the Issued column; keep only the "cannot go below" hint for locked lines. **DONE** |
| New Stock Transfer modal | `transfers/_create_modal.blade.php:514` | Item option label embeds "(unit) | Qty: X" | Dedicated "Unit" and "Available" columns in the same row | Unit and available qty duplicated per row (inside the closed select AND in the columns). | Safe to remove | Shorten the option label to "description — stock number". **DONE** |
| Edit Shipment | `delivery_subsidies/edit_delivery.blade.php:24,96,290-297` | Top alert: "delta ... no stock is double-counted" | Item Detail card note: "the delta is applied, never double-counted" + sidebar "Stock Impact" box | The delta/stock-impact explanation appears three times on one page. | Safe to remove | Keep the sidebar Stock Impact box; drop the delta sentence from the alert and the card note. **DONE** |
| Edit Transfer | `transfers/edit.blade.php:22` vs `:184-189` | Top alert: "The delta (new − old) is applied — stock is never double-counted" | Sidebar "Stock Impact" box | Same explanation repeated twice on one page. | Safe to remove | Keep the sidebar box; drop the sentence from the alert. **DONE** |
| New Delivery/Subsidy modal | `delivery_subsidies/_create_form.blade.php:50` vs `:161-164` | Modal subtitle about unit cost/warehouse being chosen at dispatch | Summary note with the same message | Same informational message shown twice in the modal. | Safe to remove | Remove the summary note; keep the subtitle. **DONE** |
| Edit Delivery/Subsidy modal | `delivery_subsidies/_edit_form.blade.php:50` vs `:157-160` | Modal subtitle | Summary note with the same message | Same informational message shown twice in the modal. | Safe to remove | Remove the summary note; keep the subtitle and the cascade warning. **DONE** |
| New Requisition modal | `requisitions/_create_form.blade.php:11` vs `:140-143` | Modal subtitle | Summary note with the same message | Same informational message shown twice in the modal. | Safe to remove | Remove the summary note; keep the subtitle. **DONE** |

## Reviewed and deliberately NOT changed

These look similar but are distinct, important traceability data — do not remove:

| Page | Element | Why it is kept |
|---|---|---|
| Items / Stock cards / Transfers | Subsidy ID (SUB-xxxxxx), RIS No., DR No., Stock No. | Each is a different reference in the chain Subsidy → Stock → Warehouse → Stock Transfer → RIS → Dispatch → Stock Card. |
| Items index/show, stock cards, inventory balance | "FROM deleted/archived subsidy" badges | Alert + traceability for stock from deleted/archived subsidies (opens the details modal with Subsidy ID, RIS, DR, status). |
| Requisition show | Per-item "Partial Delivery Breakdown" header (Requested/Issued/Outstanding) | Repeats the summary table per item but anchors the per-dispatch history rows below; aids navigation on a long page. |
| Subsidy show | Ordered Items table vs. Partial Delivery Breakdown vs. Shipment Records | Three granularity levels (line summary → per-item per-shipment → per-shipment with stock cards); each level adds data the other lacks. |
| Transfer show | Warehouse Movement card vs. route line in Dispatch Progress | Names+places vs. codes+progress context; route line also carries the dispatch link. (Duplicate CTA removed.) |
| Dashboard admin | Warehouse name shown as section header and in "Warehouse Total" rows | Section grouping, not a duplicate column. |
| Transfer dispatch page | Transfer # in header, progress bar, and sidebar | Standard form-context repetition (header identity + sticky summary). |
| Forms with sticky summary sidebars | Sidebar repeating form values (Transfer #, From/To, dates) | Standard form UX pattern; the sidebar is the live summary while filling the form. |
| RPCI print | "Per Card" and "Per Count" quantity columns | COA Form 103 mandates both columns; the data model has one quantity. Printing the same value in both is a form-design concern, not UI redundancy — left as-is. |
| Print pages (RIS, RSMI, stock card) | Filler/blank rows, repeated headers on each page | Print layout requirements. |

## Round 2 — Source Subsidy vs. RIS vs. DR disambiguation (2026-08-20)

User reported the Item Details page still showed a RIS-based reference under "Source Subsidy":
`Source Subsidy: DR: RIS-GAMC-BEG-2026-001-4`.

**Investigation — the data mapping was already correct:**
- All 29 subsidies have valid `SUB-000001`-style codes (auto-generated `SUB-` + id padded to 6, `DeliverySubsidy.php:23-29`).
- All 41 sourced items carry `source_subsidy_code`, `source_subsidy_ris`, `source_subsidy_dr` snapshots; verified 0 missing codes, 0 snapshot/subsidy mismatches, 0 item-RIS mismatches.
- The "duplicate" the user saw was the **DR line rendered inside the Source Subsidy cell**. The DR is `delivery_subsidies.dr_number`, which the system defaults to the RIS number (+ `-N` suffix when duplicated, `DeliverySubsidyController.php:153-158`). It is a DR (delivery receipt/TXN reference), not a second RIS — but showing it under "Source Subsidy" made it read as RIS duplication.

**Fix applied — `items/show.blade.php`:**
- Source Subsidy row now shows ONLY the Subsidy ID (`SUB-xxxxxx`), or the FROM-deleted/archived badge.
- The DR is no longer rendered in the Source Subsidy cell; it lives where a DR belongs — the transaction history: the item page's "Recent Stock Movements" Reference column (per-delivery DR, e.g. `DR-GAMC2-2026-001`), the Stock Card history, and the Subsidy page's "Shipment DR No." records.

**Audit of the other pages (all verified correct, no change needed):**
| Page | Display | Verdict |
|---|---|---|
| Items index | Badge shows `SUB-xxxxxx` only (deleted/archived) | Correct |
| Inventory Balance | Badge shows `SUB-xxxxxx` only (deleted/archived) | Correct |
| Stock Cards (index, item history) | Badge shows `SUB-xxxxxx`; Reference column shows DR per receipt | Correct |
| Stock Transfers (index, show) | Subsidy ID (`SUB-xxxxxx`), "Original Subsidy/RIS Reference", and DR shown as three labeled rows | Correct |
| Subsidies (index, show) | Subsidy ID + RIS No. in header; per-shipment "Shipment DR No." in records | Correct |
| RIS/Augmentations | Badges for deleted/archived only; per-dispatch DR labeled "DR No." | Correct |

**New-transaction mapping verified (simulated with cleanup):**
- New subsidy → `SUB-000069` auto-code; DR defaults to RIS.
- `recordDelivery` snapshot → item gets `source_subsidy_code`, `source_subsidy_ris`, `source_subsidy_dr`.
- Transfer creation → `source_subsidy_code`, `source_ris_number`, `source_dr_number` carried.
- All test records removed.

## Root cause notes

- **Items' `ris_number` ≡ Source Subsidy RIS.** At delivery, `DeliverySubsidyController.php` writes `item.ris_number = deliverySubsidy.ris_number` (line 1483) and snapshots the same value into `source_subsidy_ris` (line 1488). They are intentionally the same data; the UI must show the RIS once (RIS No.) and the Subsidy ID + DR under Source Subsidy.
- **Subsidy DR may equal RIS.** `DeliverySubsidyController.php:153-158` defaults `dr_number` to the `ris_number` when creating a subsidy. DR and RIS are still distinct concepts (DR can be corrected independently); no code change made.
- **Warehouse duplication in RSMI** came from a badge + text pair rendering the same attribute; fixed by rendering once.