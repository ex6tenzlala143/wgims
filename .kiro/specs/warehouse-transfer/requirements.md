# Requirements Document

## Introduction

The Warehouse-to-Warehouse Stock Transfer feature enables WGIMS to record the movement of inventory items between warehouse locations within the DSWD Region X network. When stock is physically relocated from one warehouse to another, the system must atomically deduct the transferred quantity from the source warehouse and credit it to the destination warehouse, maintaining accurate stock card histories at both locations. This feature follows the same transactional patterns already established for deliveries and issuances.

## Glossary

- **WGIMS**: Warehouse Goods Inventory Management System — the Laravel-based inventory system for DSWD Region X.
- **Transfer**: A recorded movement of a specific quantity of an item from a source warehouse to a destination warehouse.
- **Transfer_Document**: The system record that captures the header information of a transfer (reference number, date, source warehouse, destination warehouse, remarks, status, and the user who initiated it).
- **Transfer_Line**: A single line within a Transfer_Document representing one item, the quantity transferred, and the unit cost at the time of transfer.
- **Source_Warehouse**: The warehouse from which stock is being removed.
- **Destination_Warehouse**: The warehouse to which stock is being added.
- **Source_Item**: The Item record at the Source_Warehouse that will have its quantity decremented.
- **Destination_Item**: The Item record at the Destination_Warehouse that will have its quantity incremented. May be an existing record or one created via `Item::findOrCreateByUnitCost()`.
- **Transfer_Number**: A system-generated unique reference identifier for a Transfer_Document (e.g., `TRF-2025-0001`).
- **Stock_Card_Entry**: An immutable ledger record in the `stock_card_entries` table that records a receipt or issuance event for an item.
- **Admin**: A user with the `admin` role who has full access across all warehouses.
- **Supply_Custodian**: A user with the `supply_custodian` role scoped to a single warehouse.
- **Center_Head**: A user with the `center_head` role scoped to a single warehouse.
- **Center_Staff**: A user with the `center_staff` role scoped to a single warehouse with read-only access.

---

## Requirements

### Requirement 1: Initiate a Stock Transfer

**User Story:** As a supply_custodian or admin, I want to create a stock transfer from my warehouse to another warehouse, so that the physical relocation of goods is formally recorded in the system.

#### Acceptance Criteria

1. THE Transfer_Document SHALL have a unique, system-generated Transfer_Number following the format `TRF-YYYY-NNNN` where `YYYY` is the current year and `NNNN` is a zero-padded sequential counter.
2. WHEN a user submits a new transfer, THE Transfer_Document SHALL record the source warehouse, destination warehouse, transfer date, at least one Transfer_Line, and the initiating user.
3. IF the source warehouse and destination warehouse are the same, THEN THE System SHALL reject the transfer and return a validation error.
4. IF a Center_Staff user attempts to create a transfer, THEN THE System SHALL return a 403 Forbidden response.
5. WHEN a Supply_Custodian or Center_Head creates a transfer, THE System SHALL restrict the Source_Warehouse to the warehouse assigned to that user.
6. WHEN an Admin creates a transfer, THE System SHALL allow any active warehouse to be selected as the Source_Warehouse.
7. THE Transfer_Document SHALL support an optional free-text remarks field.

---

### Requirement 2: Transfer Line Validation

**User Story:** As a supply_custodian or admin, I want the system to validate each transfer line before saving, so that invalid or over-quantity transfers are prevented.

#### Acceptance Criteria

1. WHEN a transfer is submitted, THE System SHALL require at least one Transfer_Line with a valid item, a positive quantity, and a unit cost greater than zero.
2. WHEN a Transfer_Line is submitted, THE System SHALL verify that the referenced Source_Item belongs to the Source_Warehouse.
3. IF the requested transfer quantity for a Transfer_Line exceeds the current `quantity` of the Source_Item, THEN THE System SHALL reject the transfer and return a validation error identifying the item and the available quantity.
4. IF the requested transfer quantity for any Transfer_Line is zero or negative, THEN THE System SHALL reject the transfer and return a validation error.
5. THE System SHALL accept transfer quantities expressed as decimal numbers to support fractional units.

---

### Requirement 3: Atomic Stock Adjustment

**User Story:** As a supply_custodian or admin, I want the stock quantities and ledger entries to be updated atomically when a transfer is saved, so that the inventory records are always consistent.

#### Acceptance Criteria

1. WHEN a transfer is saved, THE System SHALL execute all stock adjustments and Stock_Card_Entry creation within a single database transaction.
2. WHEN a transfer is saved, THE System SHALL decrement the Source_Item quantity by the transferred quantity.
3. WHEN a transfer is saved, THE System SHALL use `Item::findOrCreateByUnitCost()` to resolve or create the Destination_Item at the Destination_Warehouse using the source item's description, unit, category, unit cost, brand, expiration date, and expiration year.
4. WHEN a transfer is saved, THE System SHALL increment the Destination_Item quantity by the transferred quantity.
5. IF any step within the database transaction fails, THEN THE System SHALL roll back all changes and leave both the Source_Item and Destination_Item quantities unchanged.
6. THE System SHALL clamp the Source_Item quantity to a minimum of zero and SHALL NOT produce a negative balance.

---

### Requirement 4: Stock Card Ledger Entries

**User Story:** As a supply_custodian or admin, I want the stock card to reflect the transfer as both an outbound entry at the source and an inbound entry at the destination, so that the full movement history is traceable.

#### Acceptance Criteria

1. WHEN a transfer is saved, THE System SHALL create a Stock_Card_Entry for the Source_Item with `reference_type` set to `'transfer_out'`, `issue_qty` equal to the transferred quantity, `balance_qty` equal to the post-transfer source balance, and `from_to` set to the Destination_Warehouse name.
2. WHEN a transfer is saved, THE System SHALL create a Stock_Card_Entry for the Destination_Item with `reference_type` set to `'transfer_in'`, `receipt_qty` equal to the transferred quantity, `balance_qty` equal to the post-transfer destination balance, and `from_to` set to the Source_Warehouse name.
3. THE Stock_Card_Entry for both the source and destination SHALL record the Transfer_Number as the `reference` field and the Transfer_Document id as the `reference_id`.
4. THE Stock_Card_Entry for both the source and destination SHALL record the `entry_date` as the transfer date provided by the user.
5. THE Stock_Card_Entry for the source SHALL record `balance_unit_cost` and `balance_total_cost` based on the Source_Item unit cost after the transfer.
6. THE Stock_Card_Entry for the destination SHALL record `receipt_unit_cost`, `receipt_total_cost`, `balance_unit_cost`, and `balance_total_cost` based on the Destination_Item unit cost after the transfer.

---

### Requirement 5: Transfer Listing and Visibility

**User Story:** As any authenticated user, I want to view a list of transfers relevant to my warehouse, so that I can monitor stock movements.

#### Acceptance Criteria

1. THE System SHALL provide a paginated transfer listing page showing Transfer_Number, transfer date, source warehouse, destination warehouse, status, and initiating user.
2. WHEN a Center_Staff, Supply_Custodian, or Center_Head views the transfer list, THE System SHALL display only transfers where the Source_Warehouse or Destination_Warehouse matches the user's assigned warehouse.
3. WHEN an Admin views the transfer list, THE System SHALL display transfers across all warehouses.
4. THE System SHALL allow filtering the transfer list by source warehouse, destination warehouse, and date range.

---

### Requirement 6: Transfer Detail View

**User Story:** As any authenticated user, I want to view the full details of a transfer, so that I can verify what was moved, when, and by whom.

#### Acceptance Criteria

1. THE System SHALL provide a transfer detail page showing the Transfer_Number, transfer date, source warehouse, destination warehouse, remarks, initiating user, and all Transfer_Lines with item description, unit, category, quantity, and unit cost.
2. WHEN a Center_Staff, Supply_Custodian, or Center_Head attempts to view a transfer where neither the Source_Warehouse nor the Destination_Warehouse matches the user's assigned warehouse, THEN THE System SHALL return a 403 Forbidden response.
3. THE System SHALL display the current status of the Transfer_Document on the detail page.

---

### Requirement 7: Transfer Status Lifecycle

**User Story:** As a supply_custodian or admin, I want transfers to have a clear status, so that I can distinguish completed transfers from drafts or cancelled ones.

#### Acceptance Criteria

1. WHEN a transfer is successfully saved and all stock adjustments are committed, THE Transfer_Document status SHALL be set to `'completed'`.
2. THE System SHALL record the Transfer_Document status as `'completed'` only after the wrapping database transaction has been committed without error.

---

### Requirement 8: Notifications

**User Story:** As an admin, I want to be notified when a transfer is recorded, so that I can monitor cross-warehouse stock movements.

#### Acceptance Criteria

1. WHEN a transfer is successfully saved, THE System SHALL create a SystemNotification for every user with the `admin` role containing the Transfer_Number, source warehouse name, and destination warehouse name.
2. WHEN a transfer involves a warehouse assigned to a Supply_Custodian or Center_Head, THE System SHALL create a SystemNotification for those users at both the source and destination warehouses.

---

### Requirement 9: Printable Transfer Slip

**User Story:** As a supply_custodian or admin, I want to print a transfer slip for a completed transfer, so that a physical document can accompany the goods during transport.

#### Acceptance Criteria

1. THE System SHALL provide a print view for a Transfer_Document that includes the Transfer_Number, transfer date, source warehouse, destination warehouse, all Transfer_Lines (description, unit, quantity, unit cost, total cost), remarks, and the name of the initiating user.
2. WHEN a Center_Staff, Supply_Custodian, or Center_Head attempts to print a transfer slip for a transfer where neither warehouse matches their assigned warehouse, THEN THE System SHALL return a 403 Forbidden response.
