# WGIMS Workflows

## 1. Delivery / Subsidy Workflow

```
START: Admin/Warehouse Manager creates subsidy request
  ↓
USER ACTION: Fill form (RIS#, supplier, items, quantities)
  ↓
VALIDATION: items array required, each line has description/unit/category/quantity
  ↓
DATABASE CHANGE:
  - Create DeliverySubsidy (status: pending)
  - Create DeliverySubsidyItem for each line
  - quantity_requested = sum of line quantities
  ↓
INVENTORY CHANGE: None (stock not yet received)
  ↓
RESULT: Subsidy created, admins notified

---

START: Dispatcher records shipment
  ↓
USER ACTION: Select delivery date, DR#, batch, condition, per-item warehouse/unit cost/ENGAS/DR#
  ↓
VALIDATION:
  - Remaining qty check per line (cannot exceed requested - delivered)
  - Unit cost, ENGAS, warehouse, DR# required for dispatching lines
  ↓
DATABASE CHANGE:
  - Create Delivery record
  - Create DeliveryItem for each dispatched line
  - Increment qty_delivered on DeliverySubsidyItem
  ↓
INVENTORY CHANGE:
  - Item::findOrCreateByUnitCost() resolves or creates stock record
  - item.quantity += qty_delivered
  - item.unit_cost = actualUnitCost
  - item.ris_number = subsidy.ris_number
  - item.applySubsidySnapshot(subsidy)
  ↓
STOCK CARD:
  - Create StockCardEntry (reference_type: delivery)
  - receipt_qty = qty_delivered
  - issue_qty = 0
  ↓
RESULT:
  - Stock received into warehouse
  - Stock card updated
  - Subsidy status recalculated (pending → partial → fully_delivered)
  - Admins notified
```

## 2. Requisition / RIS Workflow

```
START: Admin/Warehouse Manager creates RIS
  ↓
USER ACTION: Fill form (purpose, items from catalog)
  ↓
VALIDATION:
  - purpose required
  - items array required, each from active catalog
  ↓
DATABASE CHANGE:
  - Create Requisition (status: pending)
  - Create RequisitionItem for each line (no stock linkage)
  - ris_number auto-generated if not provided
  ↓
INVENTORY CHANGE: None
  ↓
RESULT: RIS created, approvers notified

---

START: Approver approves and dispatches
  ↓
USER ACTION: Select warehouse, exact stock record, quantity, DR#, ENGAS per line
  ↓
VALIDATION:
  - Cannot issue more than requested
  - Cannot issue more than available (physical - reserved)
  - Exact stock record must exist in selected warehouse
  ↓
DATABASE CHANGE:
  - Create RequisitionDispatchItem for each dispatched line
  - Update requisition_item.quantity_issued += qty
  - Update requisition_item.item_id = exact stock record
  ↓
INVENTORY CHANGE:
  - item.quantity -= qty_issued (with lockForUpdate)
  ↓
STOCK CARD:
  - Create StockCardEntry (reference_type: issuance)
  - receipt_qty = 0
  - issue_qty = qty_issued
  ↓
RESULT:
  - Stock deducted from warehouse
  - Stock card updated
  - RIS status recalculated (pending → partially_approved → approved)
  - Creator notified
```

## 3. Stock Transfer Workflow

```
START: Admin/Warehouse Manager creates transfer
  ↓
USER ACTION: Select source warehouse, destination warehouse, items, quantities
  ↓
VALIDATION:
  - Source and destination must differ
  - Items must belong to source warehouse
  - Cannot request more than available at source
  ↓
DATABASE CHANGE:
  - Create StockTransfer (status: pending)
  - Create StockTransferItem for each line
  - destination_item created via Item::findOrCreateByUnitCost()
  - quantity_requested set, quantity = 0
  ↓
INVENTORY CHANGE: None yet (pending)
  ↓
RESULT: Transfer created, admins notified

---

START: Dispatcher processes dispatch
  ↓
USER ACTION: Enter dispatch date, actual quantities per line
  ↓
VALIDATION:
  - Cannot exceed requested quantity
  - Cannot exceed remaining (requested - already dispatched)
  ↓
DATABASE CHANGE:
  - Update stock_transfer_item.quantity += dispatched qty
  ↓
INVENTORY CHANGE:
  - Source: item.quantity -= dispatched qty
  - Destination: item.quantity += dispatched qty
  - Propagate subsidy snapshot to destination
  ↓
STOCK CARD:
  - Create StockCardEntry (transfer_out) at source
  - Create StockCardEntry (transfer_in) at destination
  ↓
RESULT:
  - Stock moved between warehouses
  - Stock cards updated
  - Transfer status recalculated (pending → partial → completed)
```

## 4. Reservation Workflow

```
START: Admin creates reservation
  ↓
USER ACTION: Select warehouse, item, reserved quantity, purpose
  ↓
VALIDATION:
  - Item must exist in selected warehouse
  - Cannot reserve more than available (physical - reserved)
  ↓
DATABASE CHANGE:
  - Create Reservation (status: PENDING)
  ↓
INVENTORY CHANGE: None (allocation only)
  ↓
RESULT: Reservation created

---

START: Approver approves reservation
  ↓
USER ACTION: Click approve
  ↓
VALIDATION: Status must be PENDING
  ↓
DATABASE CHANGE:
  - reservation.status = RESERVED
  - reservation.approved_by = user.id
  ↓
INVENTORY CHANGE: None
  ↓
RESULT: Reservation approved

---

START: Reservation fulfilled via requisition dispatch
  ↓
USER ACTION: Dispatcher issues from reserved item
  ↓
VALIDATION:
  - Stock available check
  ↓
DATABASE CHANGE:
  - Create RequisitionDispatchItem
  - Update reservation.allocated_quantity += qty
  - If allocated >= reserved: status = FULFILLED
  - Else: status = ALLOCATED
  ↓
INVENTORY CHANGE:
  - item.quantity -= qty_issued
  ↓
RESULT: Stock issued, reservation allocated/fulfilled
```

## 5. Edit/Delete Workflows

### Edit Delivery (Shipment)
```
OLD QTY → NEW QTY (delta)
  ↓
Same warehouse:
  - item.quantity += delta
  - ds_item.qty_delivered += delta
  - Update delivery stock card entry
Different warehouse:
  - Reverse old: old_item.quantity -= old_qty
  - Apply new: new_item.quantity += new_qty
  - Move stock card entry to new item
  ↓
Recalculate balances for affected items
  ↓
Update ds_item.unit_cost, amount
  ↓
Cascade cost changes via DeliverySubsidyCascadeService
```

### Delete Delivery
```
For each delivery item:
  - item.quantity -= qty_delivered (clamped to 0)
  - ds_item.qty_delivered -= qty_delivered
  - Delete stock card entries
  ↓
Delete delivery record
  ↓
Recalculate balances for affected items
  ↓
Update subsidy status
```

### Edit Subsidy (No Deliveries)
```
Full edit allowed:
  - RIS#, supplier, header fields
  - Add/remove/reorder line items
  - Change quantities
  ↓
Orphan lines deleted (if no deliveries)
```

### Edit Subsidy (Deliveries Exist)
```
Correction mode:
  - RIS# and supplier FROZEN
  - Only date, place, remarks editable
  - Per-line requested quantities editable
  - Delivered lines locked (cannot change item/catalog_item)
  - Cannot set requested < delivered
  ↓
No inventory changes
  ↓
Only request reclassification
```

### Delete Subsidy
```
For each delivery:
  For each delivery item:
    - Reverse stock: item.quantity -= qty_delivered
    - Apply subsidy snapshot (status: deleted)
    - Delete stock card entries
  ↓
Delete deliveries and delivery items
  ↓
Flag related stock transfers as "deleted subsidy"
  ↓
Propagate snapshot to transfer lineage items
  ↓
Delete subsidy and items
  ↓
Hard-delete orphaned items (qty=0, no references)
  ↓
Recalculate balances
```

### Edit Dispatch
```
OLD ITEM → NEW ITEM (delta)
  ↓
Reverse old: old_item.quantity += old_qty
Apply new: new_item.quantity -= new_qty
  ↓
Update dispatch row (item_id, qty, costs, DR#)
  ↓
Move/update stock card entry
  ↓
Recalculate balances for both items
  ↓
Update requisition item cache
  ↓
Recalculate RIS fulfilment status
```

### Delete Dispatch
```
Restore stock: item.quantity += qty_issued
  ↓
Delete stock card entries (dispatch_item_id or legacy)
  ↓
Recalculate balances
  ↓
Delete dispatch row
  ↓
Recalculate RIS fulfilment status from remaining dispatches
```

### Edit Transfer
```
OLD QTY → NEW QTY (delta)
  ↓
Delta > 0 (increase):
  - Source must have extra units
  - source.quantity -= delta
  - dest.quantity += delta
Delta < 0 (decrease):
  - Dest must still hold units
  - source.quantity += (-delta)
  - dest.quantity -= (-delta)
  ↓
Update transfer line
  ↓
Spread delta across stock card entries
  ↓
Recalculate balances
```

### Delete Transfer
```
Check blockers (later stock card movements on destination)
  ↓
If blocked: refuse deletion, show what to resolve
  ↓
For each line:
  - Reverse: source.quantity += dispatched
  - Reverse: dest.quantity -= dispatched (clamped)
  - Delete stock card entries
  ↓
Write audit log before deleting transfer
  ↓
Delete transfer
  ↓
Recalculate balances
```

## 6. Stock Card Recalculation

```
START: Any operation that modifies stock cards
  ↓
Load all entries for item (orderBy: entry_date, id)
  ↓
Initialize:
  - runningQty = 0
  - runningUnitCost = item.unit_cost
  ↓
For each entry:
  - runningQty += receipt_qty - issue_qty
  - If receipt_qty > 0: runningUnitCost = receipt_unit_cost
  - Update entry.balance_qty = runningQty
  - Update entry.balance_unit_cost = runningUnitCost
  - Update entry.balance_total_cost = runningQty * runningUnitCost
  ↓
Wrapped in DB transaction
  ↓
RESULT: All balances consistent
```
