# Requirements Document

## Introduction

This feature ensures that both Administrators and standard Users can see an item's **Unit Cost** and **Expiration Date** immediately upon selecting or viewing an item description within the requisition module of the Laravel-based WGInventory Management System.

Currently, the **Create RIS** form already renders unit cost and expiry columns in the item table, but the **Edit RIS** form is missing those columns entirely, and the **View RIS (show)** page does not display unit cost or expiration date alongside each item. Additionally, the `requisition_items` database table does not store snapshot values for `unit_cost` or `expiration_date`, meaning historical requisitions would silently reflect updated master-inventory prices rather than the values at the time of creation.

This feature closes those gaps by:
1. Persisting snapshot `unit_cost` and `expiration_date` values on `requisition_items` at creation/update time.
2. Auto-populating those fields in the Edit RIS form when an item is selected.
3. Displaying both fields clearly in the View RIS (show) page.

---

## Glossary

- **RIS**: Requisition and Issue Slip — the document that records a request for items from a warehouse.
- **Requisition_Module**: The set of Laravel controllers, models, and Blade views that manage RIS creation, editing, viewing, and approval (`RequisitionController`, `Requisition`, `RequisitionItem`, and the `requisitions.*` views).
- **RequisitionItem**: A single line item within an RIS, stored in the `requisition_items` table, linking a `Requisition` to an `Item` with quantities and status.
- **Item_Master**: The `items` table and `Item` Eloquent model, which holds the current `unit_cost`, `expiration_date`, and `expiration_year` for each inventory item.
- **Snapshot_Value**: A copy of a master-data field (e.g., `unit_cost`, `expiration_date`) captured at the moment a `RequisitionItem` is saved, so that subsequent changes to the `Item_Master` do not alter historical records.
- **Edit_Mode**: The state of the RIS form when an Admin is editing an existing requisition via the `requisitions.edit` / `requisitions.update` routes.
- **View_Mode**: The read-only display of a completed or pending RIS via the `requisitions.show` route, accessible to both Admins and standard Users.
- **Admin**: A user with `role = 'admin'`, who has full access to create, edit, approve, and delete requisitions.
- **User**: A non-admin user (e.g., `center_head`, `supply_custodian`, or standard user) who can create and view requisitions for their assigned warehouses.
- **Item_Description_Selector**: The `<select>` dropdown in the RIS item row that lets the user choose an inventory item by description and stock number.
- **Unit_Cost**: The per-unit monetary value of an item at the time the requisition line is saved.
- **Expiration_Date**: The date (or year) on which the selected item batch expires, sourced from the `Item_Master` at selection time.
- **getItemsByWarehouse_API**: The existing JSON endpoint (`GET /api/requisition-items?warehouse_id=X`) in `RequisitionController@getItemsByWarehouse` that returns item data including `unit_cost`, `expiry_date`, and `expiry_year`.

---

## Requirements

### Requirement 1: Persist Snapshot Values on RequisitionItem

**User Story:** As a system administrator, I want unit cost and expiration date to be stored on each requisition line item at the time of creation or update, so that historical requisitions always reflect the prices and batch dates that were current when the RIS was submitted.

#### Acceptance Criteria

1. THE `requisition_items` table SHALL contain a `unit_cost` column of type `DECIMAL(15,4)` with a default of `0`.
2. THE `requisition_items` table SHALL contain an `expiration_date` column of type `DATE` that is nullable.
3. WHEN a `RequisitionItem` is created via `RequisitionController@store`, THE `Requisition_Module` SHALL populate `unit_cost` with the value of `Item_Master.unit_cost` and `expiration_date` with the value of `Item_Master.expiration_date` (storing `NULL` if `Item_Master.expiration_date` is `NULL`) at the time the record is persisted to the database.
4. WHEN a `RequisitionItem` is updated via `RequisitionController@update`, THE `Requisition_Module` SHALL re-populate `unit_cost` and `expiration_date` from the current `Item_Master` values for the selected item at the time the record is persisted (storing `NULL` for `expiration_date` if `Item_Master.expiration_date` is `NULL`).
5. WHEN the `Item_Master` `unit_cost` or `expiration_date` is changed after a `RequisitionItem` has been saved, THE `Requisition_Module` SHALL return the original persisted `unit_cost` and `expiration_date` values when the `RequisitionItem` is retrieved, reflecting no change from the `Item_Master` update.

---

### Requirement 2: Auto-Populate Unit Cost and Expiration Date in Edit Mode

**User Story:** As an Admin editing a requisition, I want the unit cost and expiration date to be automatically populated when I select an item description, so that I can verify the correct values without manually looking them up.

#### Acceptance Criteria

1. WHEN an Admin opens the Edit RIS form, THE `Requisition_Module` SHALL display a "Unit Cost" column and an "Expiration Date" column in the item table, in addition to the Stock No., Item Description, Unit, Available Stock, and Qty Requested columns already present.
2. WHEN an Admin selects an item from the `Item_Description_Selector` in Edit Mode, THE `Requisition_Module` SHALL auto-fill the "Unit Cost" field for that row with the `unit_cost` value returned by the `getItemsByWarehouse_API` for the selected item.
3. WHEN an Admin selects an item from the `Item_Description_Selector` in Edit Mode, THE `Requisition_Module` SHALL auto-fill the "Expiration Date" field for that row with the `expiry_date` value returned by the `getItemsByWarehouse_API` for the selected item, displayed in `YYYY-MM-DD` format.
4. WHILE the Edit RIS form is loaded with existing items, THE `Requisition_Module` SHALL pre-populate the "Unit Cost" and "Expiration Date" columns for each existing line item by fetching the item's data from the `getItemsByWarehouse_API` using the existing item's `item_id`.
5. WHILE the Edit RIS form is active, THE "Expiration Date" field SHALL be read-only and SHALL NOT accept direct keyboard or pointer input from the Admin.
6. WHILE the Edit RIS form is active, THE "Unit Cost" field SHALL be read-only and SHALL NOT accept direct keyboard or pointer input from the Admin; its value SHALL only change when the Admin selects a different item from the `Item_Description_Selector`.
7. WHEN an Admin changes the selected item in an existing row in Edit Mode, THE `Requisition_Module` SHALL replace the displayed "Unit Cost" and "Expiration Date" with the values for the newly selected item from the `getItemsByWarehouse_API`.
8. WHEN the Edit RIS form is loaded, THE `Requisition_Module` SHALL load available items via the `getItemsByWarehouse_API` for the requisition's current warehouse, so that `unit_cost` and `expiry_date` data attributes are available for auto-population.
9. WHEN the `getItemsByWarehouse_API` returns a `null` `unit_cost` or `null` `expiry_date` for a selected item, THE `Requisition_Module` SHALL display an empty value in the corresponding "Unit Cost" or "Expiration Date" field for that row.

---

### Requirement 3: Display Unit Cost and Expiration Date in View Mode

**User Story:** As an Admin or User viewing an existing requisition, I want to see the unit cost and expiration date next to each item description, so that I can review the financial and batch details of the RIS without navigating to the inventory module.

#### Acceptance Criteria

1. WHEN a User or Admin opens the View RIS page (`requisitions.show`), THE `Requisition_Module` SHALL display a "Unit Cost" column in the items table showing the persisted `unit_cost` value stored on each `RequisitionItem`.
2. WHEN a User or Admin opens the View RIS page, THE `Requisition_Module` SHALL display an "Expiration Date" column in the items table showing the persisted `expiration_date` value stored on each `RequisitionItem`, formatted as `MMM DD, YYYY` (e.g., `Jan 15, 2026`).
3. WHEN a `RequisitionItem` has a `null` `unit_cost`, THE `Requisition_Module` SHALL display `—` in the "Unit Cost" column.
4. WHEN a `RequisitionItem` has a `unit_cost` of `0`, THE `Requisition_Module` SHALL display `₱ 0.00` in the "Unit Cost" column.
5. THE "Unit Cost" value in View Mode SHALL be formatted as Philippine Peso using the `₱` symbol, comma as thousands separator, and two decimal places (e.g., `₱ 1,250.00`).
6. WHEN a `RequisitionItem` has a `null` `expiration_date`, THE `Requisition_Module` SHALL display `—` in the "Expiration Date" column without applying any danger or warning styling.
7. WHEN a `RequisitionItem` `expiration_date` is within 30 days of the current date (i.e., 0 < days remaining ≤ 30), THE `Requisition_Module` SHALL render the expiration date in a visually distinct danger style (e.g., red text) to alert the viewer.
8. WHEN a `RequisitionItem` `expiration_date` has already passed (i.e., the date is before today), THE `Requisition_Module` SHALL render the expiration date in a danger style with strikethrough text and an "Expired" label, and this styling SHALL take precedence over the near-expiry danger style defined in criterion 7.

---

### Requirement 4: Consistent Column Layout Across All RIS Views

**User Story:** As a User or Admin, I want the item table columns to be consistent across the Create, Edit, and View RIS pages, so that I can quickly orient myself regardless of which page I am on.

#### Acceptance Criteria

1. THE Create RIS item table and Edit RIS item table SHALL each include the following columns in the same order: Stock No., Item Description, Unit, Available Stock, Expiration Date, Unit Cost, Qty Requested. THE View RIS item table SHALL include the following columns in the same order: Stock No., Item Description, Unit, Qty Issued, Expiration Date, Unit Cost, Qty Requested.
2. WHEN the Edit RIS form is submitted, THE `Requisition_Module` SHALL include the `unit_cost` snapshot value in the form submission so that the server-side `update` method can persist it to `requisition_items`.
3. THE View RIS page SHALL display a "Total Cost" value per line item, calculated as `unit_cost × quantity_requested`, formatted as `₱ X,XXX.XX` (Philippine Peso with `₱` symbol, comma thousands separator, and two decimal places).
4. IF a `RequisitionItem` `unit_cost` is `null`, THEN THE `Requisition_Module` SHALL display `—` for the "Total Cost" cell.
5. IF a `RequisitionItem` `unit_cost` is `0`, THEN THE `Requisition_Module` SHALL display `₱ 0.00` for the "Total Cost" cell.
