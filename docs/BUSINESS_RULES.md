# WGIMS Business Rules

## 1. Delivery Subsidy Rules

### Creation
- RIS# is required and unique
- Supplier is required
- Date is required
- Items array is required (min 1)
- Each item: description, unit, category, quantity required
- Unit cost and warehouse are NOT captured at creation — decided at dispatch time
- `quantity_requested` = sum of all line item quantities
- DR# auto-generated from RIS# (with suffix if duplicate)

### Delivery Recording
- DR# (shipment-level) is optional
- Batch number and condition status required
- Per-item: warehouse, unit cost, ENGAS unit cost, DR#, quantity required (if dispatching)
- Cannot exceed remaining quantity (requested - already delivered)
- `Item::findOrCreateByUnitCost()` resolves or creates the stock record
- Stock is added to the warehouse
- Stock card entry created (delivery type)
- `qty_delivered` incremented on subsidy line
- Subsidy status recalculated

### Correction (Deliveries Exist)
- RIS# and supplier are FROZEN
- Only header fields (date, place, remarks) editable
- Per-line requested quantities editable
- Lines with deliveries locked: cannot change item, catalog_item, account_code
- Cannot set requested qty below already delivered qty
- No inventory changes — only request reclassification

### Deletion
- All deliveries reversed (stock subtracted, stock cards deleted)
- Related stock transfers flagged as "deleted subsidy"
- Items with qty=0 and no other references hard-deleted
- Stock card balances recalculated

## 2. Requisition (RIS) Rules

### Creation
- RIS# auto-generated if not provided (format: RIS-YYYYMM-NNNN)
- Purpose required
- Date requested required
- Items from catalog (item_catalog_items)
- No stock linkage at creation

### Approval/Dispatch
- Warehouse and exact stock record chosen by dispatcher
- Cannot issue more than requested
- Cannot issue more than available (physical - reserved)
- `lockForUpdate()` on exact stock record
- Stock deducted from item
- Stock card entry created (issuance type)
- `quantity_issued` incremented on requisition item
- RIS status recalculated

### Edit
- Lines already dispatched:
  - Cannot reduce requested qty below issued qty
  - Cannot change catalog item
  - Cannot change item_id
- Undispatched lines: fully editable

### Correction
- Same restrictions as edit
- Explicitly logged as "correction"
- Never touches dispatches

### Deletion
- All dispatches reversed (stock restored to exact records)
- Stock card entries deleted
- Stock card balances recalculated
- RIS and items deleted

## 3. Stock Transfer Rules

### Creation
- Source and destination warehouses must differ
- Items must belong to source warehouse
- Cannot request more than available at source
- Destination item slots created via `Item::findOrCreateByUnitCost()`
- Status starts as "pending"

### Dispatch
- Cannot exceed requested quantity
- Cannot exceed remaining (requested - already dispatched)
- `lockForUpdate()` on source and destination items
- Stock moved from source to destination
- Stock cards: transfer_out at source, transfer_in at destination
- Status recalculated

### Edit
- Delta applied to source and destination
- Increasing: source must have the extra units
- Decreasing: destination must still hold the units
- Stock cards reconciled across all partial dispatches
- Cost changes propagate to destination item

### Deletion
- Check: destination stock must not have been used by later transactions
- If blocked: show what to resolve first
- Reverse: source gets stock back, destination loses stock
- Stock cards deleted
- Balances recalculated

## 4. Reservation Rules

### Creation
- Item must exist in selected warehouse
- Cannot reserve more than available (physical - reserved)
- `lockForUpdate()` on item
- Status: PENDING

### Approval
- PENDING → RESERVED
- Requires canWrite()

### Ready
- RESERVED → READY_FOR_REQUISITION

### Cancel
- Cannot cancel FULFILLED, CANCELLED, or EXPIRED

### Fulfillment
- When a requisition dispatches from a reserved item, the reservation should be allocated
- `Reservation::allocate($qty)` increments allocated_quantity
- Status updates to ALLOCATED or FULFILLED

## 5. Item Category Rules

### Creation
- Key must be unique (lowercase, alphanumeric, hyphens, underscores)
- Auto-generated from label if not provided
- Account code required

### Deletion
- Cannot delete if items are linked
- Deactivate instead

### Catalog Items
- Unique within category
- Account code inherited from category
- Cannot delete if used in delivery/subsidy records

## 6. Stock Number Rules

### Format
`{WAREHOUSE_CODE}-{CATEGORY_PREFIX}-{NNNN}`
- Example: GAMC-FOO-0001

### Generation
- Advisory lock (`GET_LOCK`) per prefix
- Find max numeric suffix, increment
- Paranoid double-check for uniqueness
- Lock released in finally block

### Assignment
- Assigned when item first receives stock via delivery
- Never reused
- Placeholders (no stock_number) can exist with qty=0

## 7. Cost Rules

### Unit Cost
- Stored on Item record
- Updated when delivery is recorded or edited
- Cascaded through transfer chain via `DeliverySubsidyCascadeService`

### ENGAS Unit Cost
- Can be null
- Part of stock identity (with ±0.001 tolerance)
- Stored on delivery_items and requisition_dispatch_items
- Total = quantity × engas_unit_cost (computed server-side)

### Cost in Reports
- RPCI: uses current item.unit_cost
- RSMI: uses per-dispatch unit_cost (source of truth)
- Inventory Balance: uses current item.unit_cost

## 8. Source Subsidy Rules

### Tracking
Every item tracks its originating subsidy via snapshot columns:
- source_subsidy_id
- source_subsidy_ris
- source_subsidy_dr
- source_subsidy_code
- source_subsidy_status

### Propagation
- Set on delivery (Item::findOrCreateByUnitCost with subsidy ID)
- Propagated through stock transfers
- Follows stock across warehouses

### Deletion Handling
- When subsidy deleted: items marked with status 'deleted'
- Related transfers marked with status 'deleted'
- Stock is preserved (not reversed) — admin must review

## 9. Warehouse Rules

### Legacy vs Modern
- `warehouse_id` on users: legacy single warehouse
- `user_warehouse` pivot: modern multi-warehouse assignment
- `warehouse_id` on delivery_subsidies: legacy, often null
- `warehouse_id` on requisitions: legacy, often null
- Modern design: warehouse determined at line-item or dispatch level

### Scoping
- Admin/warehouse_manager: see all warehouses
- Others: limited to pivot + legacy assignments
- Empty assignment: see nothing

## 10. Notification Rules

### Triggers
- New subsidy created → notify all admins
- Delivery recorded → notify all admins
- New RIS submitted → notify admins, custodians, center_heads
- RIS fulfilled → notify creator

### Polling
- Unread count polled every 30 seconds
- Dropdown loads via AJAX

## 11. Audit Rules

### Delivery Subsidy
- Logged on every edit/correction
- Fields changed recorded with old/new values
- Cascade summary recorded for cost changes

### Requisition
- Logged on correction
- Fields changed recorded with old/new values

### Stock Transfer
- Logged on create, update, dispatch, reversal
- Transfer number snapshotted (survives deletion)

## 12. Report Rules

### RPCI (Report on Physical Count of Inventories)
- Snapshot-based
- Can save snapshots for specific periods
- Shows all active items with quantity > 0
- Grouped by category

### RSMI (Report of Supplies and Materials Issued)
- Snapshot-based
- Shows approved/partially_approved RIS
- Uses per-dispatch unit costs
- Grouped by RIS number

### Inventory Balance
- Real-time (not snapshot)
- Shows all active items with quantity > 0
- Can filter by warehouse, category, item, source subsidy status
- Does NOT merge items — shows each stock record separately
