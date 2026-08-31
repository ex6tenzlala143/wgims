# inventory-data-integrity

## Inventory Data Integrity Rules

This skill contains the rules for maintaining correct stock quantities, costs, warehouses, subsidies, dispatches, reversals, and transfers in WGIMS.

## Fundamental Rules

### 1. Stock Records Are Not Aggregate Quantities
Never treat inventory as a simple sum of "Item Name + Quantity". Each stock record has its own identity based on:
- warehouse_id
- description
- unit
- category
- unit_cost
- engas_unit_cost
- expiration_date
- source_subsidy_id

### 2. Do Not Merge Different Records
If two stock records differ in ANY identity field, they must remain separate. Merging them corrupts:
- Cost tracking
- Expiration tracking
- Subsidy lineage
- ENGAS accounting

### 3. Preserve Transaction Lineage
Every inventory movement must trace back to its origin:
- Subsidy deliveries create stock and must reference the original subsidy
- RIS dispatches consume stock and must reference the original requisition
- Stock transfers must reference both source and destination stock records

## Receiving (Subsidy Delivery)
When processing a delivery:
1. Create or find existing stock record using Item::findOrCreateByUnitCost()
2. Increase quantity by delivered amount
3. Create StockCardEntry with receipt_qty
4. Record unit_cost and engas_unit_cost on the delivery item

## Dispatching (RIS Approval)
When dispatching from a RIS:
1. Find exact stock record matching warehouse + description + unit_cost + engas + expiration + source_subsidy_id
2. Lock the stock record (prevent concurrent dispatch)
3. Decrease stock quantity by issued amount
4. Create StockCardEntry with issue_qty
5. Create RequisitionDispatchItem linking to the exact stock record
6. Update RIS fulfillment status

## Partial Delivery
A subsidy can have multiple deliveries:
- Each delivery creates/updates stock independently
- DeliveryItem carries its own unit_cost and engas_unit_cost
- qty_delivered on DeliverySubsidyItem tracks cumulative deliveries
- Status updates: pending → partial → fully_delivered

## Deleting a Dispatched Item
When deleting a dispatch:
1. Reverse the stock deduction (add back the issued quantity)
2. Delete the StockCardEntry
3. Recalculate running balances for the item
4. Recalculate RIS fulfillment status

## Editing a Dispatched Item
When editing a dispatch:
1. Reverse the old deduction
2. Apply the new deduction
3. Reconcile all affected StockCardEntries
4. Never change the RIS requested quantity

## Stock Transfers
When transferring stock between warehouses:
1. Deduct quantity from source item (exact stock record)
2. Create/increment destination item (may be new warehouse)
3. Propagate subsidy snapshot to destination
4. Create StockCardEntry for both source (issue) and destination (receipt)
5. Update transfer status: pending → partial → completed

## Inventory Balance
Inventory Balance is computed from current item quantities, grouped by:
- Warehouse → Category → Item Name
- Each stock record contributes its current quantity
- Different unit costs are shown separately

## RPCI (Report on Physical Count of Inventories)
- Lists items with account codes
- Shows quantity, unit cost, and total value
- Snapshot capability for historical records

## RSMI (Report of Supplies and Materials Issued)
- Groups issued items by RIS
- Shows dispatch-level costs
- Recapitulation by stock number

## Stock Card
- Shows all movements for a specific item
- Running balance after each entry
- FIFO batch view by unit cost
- Print capability

## Negative Inventory Prevention
- Validate available stock before dispatching
- Prevent dispatch quantity from exceeding available quantity
- Use database transactions for multi-step operations

## Numeric Precision
- Quantities: integers (no decimal places)
- Costs: float/decimal (2 decimal places)
- ENGAS costs: float/decimal (2 decimal places)

## Database Safety
- Use transactions for all multi-step inventory operations
- Never run migrate:fresh, db:wipe, or truncate on production data
- Never modify existing migrations that have been used in production
- Always explain what will change before any database operation
