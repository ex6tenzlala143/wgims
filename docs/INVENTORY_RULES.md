# WGIMS Inventory Rules

## Stock Identity (CRITICAL)

A stock record (Item) is uniquely identified by ALL of these fields matching:
1. `warehouse_id`
2. `description`
3. `unit`
4. `category`
5. `unit_cost` (±0.001 tolerance)
6. `engas_unit_cost` (±0.001 tolerance, null-safe)
7. `expiration_date` (date match, null-safe)
8. `source_subsidy_id` (null-safe)

**Two records with different values in ANY of these are DIFFERENT stock records. Never merge across different subsidy IDs, unit costs, ENGAS costs, expiration dates, or warehouses.**

## Item::findOrCreateByUnitCost()

This is the ONLY correct way to resolve or create a stock record during delivery.

### Algorithm
1. Search for existing active record matching all identity fields
2. If found → return it (possibly update account_code)
3. If not found → search for inactive placeholder (no stock_number) matching identity
4. If placeholder found → assign stock_number, activate it
5. If nothing found → create new record with stock_number via advisory lock

### Why This Matters
- Prevents duplicate stock records for the same physical inventory
- Handles partial deliveries to the same item
- Ensures stock number is assigned only once

## Stock Number Generation

### Format
`{WAREHOUSE_CODE}-{CATEGORY_PREFIX}-{NNNN}`
- Example: `GAMC-FOO-0001`
- Warehouse code: uppercase (e.g., GAMC)
- Category prefix: first 3 chars of category, uppercase (e.g., FOO for food)
- Number: zero-padded 4 digits

### Process
1. Advisory lock: `GET_LOCK('stock_number_{PREFIX}', 10)`
2. Find max numeric suffix for prefix
3. Increment and format
4. Double-check uniqueness
5. Release lock: `RELEASE_LOCK('stock_number_{PREFIX}')`

### Concurrency Safety
- Advisory lock prevents race conditions
- Lock timeout: 10 seconds
- Paranoid double-check after generation

## Quantity Rules

### Physical Quantity
- `items.quantity` — current physical stock level
- Updated by: deliveries (+), dispatches (-), transfers (±)

### Reserved Quantity
- Sum of `reserved_quantity` across active reservations
- Active statuses: PENDING, RESERVED, READY_FOR_REQUISITION, ALLOCATED

### Available Quantity
- `available_quantity` = `items.quantity` - `reserved_quantity`
- Computed via accessor
- Used when dispatching to ensure we don't over-issue

### Delivered Quantity
- `delivery_subsidy_items.qty_delivered` — cumulative dispatched against request
- Updated when delivery is recorded or edited
- Cannot exceed `quantity` (requested)

### Requested Quantity
- `requisition_items.quantity_requested` — requested quantity
- Cannot be reduced below `quantity_issued` (if line has been dispatched)

### Issued Quantity
- `requisition_items.quantity_issued` — cached sum of dispatch quantities
- Actually computed from `requisition_dispatch_items` sum
- Synced when dispatches change

### Transfer Quantities
- `stock_transfer_items.quantity_requested` — planned transfer qty
- `stock_transfer_items.quantity` — actually dispatched qty

## Cost Rules

### Unit Cost
- `items.unit_cost` — current unit cost on the item record
- Set during delivery (from dispatcher input)
- Can be changed via delivery edit (cascades through system)

### ENGAS Unit Cost
- `items.engas_unit_cost` — ENGAS unit cost (can be null)
- Part of stock identity
- Set during delivery or dispatch

### Cost in Reports
- **RPCI**: uses `items.unit_cost` (current)
- **RSMI**: uses per-dispatch `requisition_dispatch_items.unit_cost` (source of truth)
- **Inventory Balance**: uses `items.unit_cost` (current)

### Cost Data Location
- **Items table**: `unit_cost`, `engas_unit_cost`
- **Delivery items**: `unit_cost`, `engas_unit_cost`, `engas_total_cost`
- **Requisition dispatch items**: `unit_cost`, `engas_unit_cost`
- **Stock transfer items**: `unit_cost`

**Note**: `requisition_items.unit_cost` and `engas_unit_cost` columns were DROPPED in migration 2026_08_24_210730. Cost data now lives ONLY on `requisition_dispatch_items`.

## Expiration Rules

### Storage
- `items.expiration_date` — date or null
- `delivery_subsidy_items.expiration_date` — date or null
- `requisition_dispatch_items.expiration_date` — date or null

### Matching
- Part of stock identity (date match, null-safe)
- Two records with different expiration dates are DIFFERENT stock records

## Available vs Physical Quantity

### Physical Quantity
- What is physically in the warehouse
- Stored in `items.quantity`

### Available Quantity
- Physical quantity minus reserved quantity
- `available = max(0, physical - reserved)`
- Used during dispatch to prevent over-issuance
- Computed dynamically from `Reservation::reservedQuantityForItem()`

### Reserved Quantity
- Sum of `reserved_quantity` from active reservations
- Active: PENDING, RESERVED, READY_FOR_REQUISITION, ALLOCATED
- Not FULFILLED, CANCELLED, or EXPIRED

## Stock Card Balance Rules

### Recalculation
`StockCardEntry::recalculateBalancesForItem($itemId)`:
1. Load all entries for item, ordered by entry_date, then id
2. Initialize runningQty = 0
3. Seed runningUnitCost from item record
4. For each entry:
   - runningQty += receipt_qty - issue_qty
   - If receipt_qty > 0 and receipt_unit_cost > 0: runningUnitCost = receipt_unit_cost
   - Update balance_qty, balance_unit_cost, balance_total_cost
5. Wrapped in DB transaction

### When to Recalculate
- After any operation that adds/removes/moves stock card entries
- After editing/deleting deliveries
- After editing/deleting dispatches
- After editing/deleting transfers
- After deleting subsidies

## FIFO Note

The system does NOT assume FIFO by default. The `itemHistoryByUnitCost` view in StockCardController builds a FIFO batch view for display purposes only — it does not affect actual inventory operations.

Inventory operations always target the EXACT stock record chosen by the user/dispatcher. No automatic FIFO/LIFO selection occurs.

## When Records Merge vs Stay Separate

### Merge
- **Never** merge records with different subsidy IDs
- **Never** merge records with different unit costs
- **Never** merge records with different ENGAS costs
- **Never** merge records with different expiration dates
- **Never** merge records with different warehouses

### Stay Separate
- Same item name in different warehouses → separate records
- Same item name with different costs → separate records
- Same item name from different subsidies → separate records
- Same item name with different expiration → separate records

### Exception: findOrCreateByUnitCost
- If ALL identity fields match (including subsidy ID) → same record
- If subsidy ID differs → different record (never merged)

## Zero Quantity Handling

- `is_active` auto-managed: qty reaches 0 → deactivate; qty rises above 0 → reactivate
- Only applies to records with stock_number (not placeholders)
- Deactivated items hidden from dropdowns and active lists
- History preserved (stock cards, delivery lines, etc.)
