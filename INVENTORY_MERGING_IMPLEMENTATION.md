# Inventory Merging Implementation

## Overview
Implemented intelligent inventory merging that groups stock records with identical key attributes while preserving individual record integrity for stock tracking.

## Merge Criteria (Merge Key)

Records are merged when ALL of the following match exactly:
1. **Item Name** (description)
2. **Unit Cost** (rounded to 2 decimal places)
3. **ENGAS Unit Cost** (rounded to 2 decimal places, or both NULL)
4. **Expiration Date** (exact date match, or both NULL)
5. **Warehouse** (warehouse_id)

### Examples

**✅ WILL BE MERGED:**
```
Record A: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1, Qty: 100
Record B: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1, Qty: 200
→ Displayed as: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1, Qty: 300
```

**❌ WILL NOT BE MERGED (Different Unit Cost):**
```
Record A: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1
Record B: Food Pack, ₱750, ENGAS ₱800, Dec 31 2026, GAMC1
→ Stay separate (different unit costs)
```

**❌ WILL NOT BE MERGED (Different Expiration):**
```
Record A: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1
Record B: Food Pack, ₱700, ENGAS ₱800, Jan 15 2027, GAMC1
→ Stay separate (different expiration dates)
```

**❌ WILL NOT BE MERGED (Different ENGAS Cost):**
```
Record A: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1
Record B: Food Pack, ₱700, ENGAS ₱850, Dec 31 2026, GAMC1
→ Stay separate (different ENGAS unit costs)
```

**❌ WILL NOT BE MERGED (Different Warehouse):**
```
Record A: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC1
Record B: Food Pack, ₱700, ENGAS ₱800, Dec 31 2026, GAMC2
→ Stay separate (different warehouses)
```

---

## Implementation Details

### 1. Backend Merging Logic

**ItemController (Items Page):**
```php
// Group by merge key: description + unit_cost + engas_unit_cost + expiration_date + warehouse_id
$mergedGroups = $allItems->groupBy(function ($item) {
    return implode('|', [
        $item->description,
        round((float) $item->unit_cost, 2),
        $item->engas_unit_cost !== null ? round((float) $item->engas_unit_cost, 2) : 'null',
        $item->expiration_date ? $item->expiration_date->format('Y-m-d') : 'null',
        $item->warehouse_id,
    ]);
})->map(function ($group) {
    // Use first item as representative, sum quantities
    $representative = $group->first();
    $totalQuantity = $group->sum('quantity');
    
    $merged = clone $representative;
    $merged->quantity = $totalQuantity;
    $merged->_source_items = $group->values(); // Keep all source items
    $merged->_is_merged = $group->count() > 1;
    
    return $merged;
})->values();
```

**ReportController (Inventory Balance):**
- Same merging logic applied
- Additionally calculates "Total Qty" across ALL variations (different costs/expiry) of the same item name

### 2. Display Features

**Items Page (`resources/views/items/index.blade.php`):**
- Merged records show a "Merged (X)" badge indicating number of source records
- Click "View Source Records" button to expand and see individual records
- Each source record shows:
  - Stock Number
  - Description
  - Individual Quantity
  - Expiration Date
  - Unit Cost
  - ENGAS Unit Cost
  - Actions (View, Stock Card, Edit)

**Inventory Balance Report (`resources/views/reports/inventory_balance.blade.php`):**
- Merged records show "Merged" badge
- Source records displayed as indented sub-rows with breakdown
- "Total Qty" row shows sum across ALL variations of item name in that warehouse

**Excel Export:**
- Merged items marked with "[MERGED]" suffix in description
- Total Quantity column shows combined quantity per item name + warehouse

---

## Data Integrity & Preservation

### ✅ What is Preserved

1. **Individual Database Records** → Not modified, all records remain intact
2. **Stock Card Entries** → Each source record maintains its own stock card history
3. **Transaction History** → Delivery, transfer, and dispatch history preserved per record
4. **Stock Lineage** → Source subsidy tracking remains intact (RIS, DR numbers)
5. **FIFO Logic** → Stock deduction still follows FIFO rules on individual records
6. **Expiration Tracking** → Each expiration date tracked separately

### Display vs. Database

**Display Level:**
- Records with matching merge key → grouped visually
- Quantities summed for display
- User sees consolidated view

**Database Level:**
- All individual records remain separate
- Each record has its own ID, stock number, transaction history
- Inventory calculations operate on individual records
- Stock transfers, requisitions reference actual record IDs

---

## Testing Scenarios

### Scenario 1: Same Item, Same Cost, Same Expiry
```
Database:
- Record 1: Rice, ₱50, Exp: 2026-12-31, Qty: 100
- Record 2: Rice, ₱50, Exp: 2026-12-31, Qty: 50

Display:
- Rice, ₱50, Exp: 2026-12-31, Qty: 150 [Merged (2)]
  - View Source Records → shows both records
```

### Scenario 2: Same Item, Different Cost
```
Database:
- Record 1: Rice, ₱50, Exp: 2026-12-31, Qty: 100
- Record 2: Rice, ₱55, Exp: 2026-12-31, Qty: 50

Display:
- Rice, ₱50, Exp: 2026-12-31, Qty: 100
- Rice, ₱55, Exp: 2026-12-31, Qty: 50
(Not merged - different costs)
```

### Scenario 3: Same Item, Different Expiry
```
Database:
- Record 1: Rice, ₱50, Exp: 2026-12-31, Qty: 100
- Record 2: Rice, ₱50, Exp: 2027-01-15, Qty: 50

Display:
- Rice, ₱50, Exp: 2026-12-31, Qty: 100
- Rice, ₱50, Exp: 2027-01-15, Qty: 50
(Not merged - different expiration dates)
```

### Scenario 4: Same Item, Different ENGAS Cost
```
Database:
- Record 1: Rice, ₱50, ENGAS ₱60, Exp: 2026-12-31, Qty: 100
- Record 2: Rice, ₱50, ENGAS ₱65, Exp: 2026-12-31, Qty: 50

Display:
- Rice, ₱50, ENGAS ₱60, Exp: 2026-12-31, Qty: 100
- Rice, ₱50, ENGAS ₱65, Exp: 2026-12-31, Qty: 50
(Not merged - different ENGAS costs)
```

### Scenario 5: Same Item, Different Warehouse
```
Database:
- Record 1: Rice, ₱50, GAMC1, Qty: 100
- Record 2: Rice, ₱50, GAMC2, Qty: 50

Display:
- GAMC1: Rice, ₱50, Qty: 100
- GAMC2: Rice, ₱50, Qty: 50
(Not merged - different warehouses)
```

---

## Impact on Other Features

### ✅ No Impact (Verified Safe)

1. **Stock Cards** → Still track individual records
2. **Stock Transfers** → Reference individual item IDs
3. **Requisitions/RIS** → Dispatch from individual records using FIFO
4. **Delivery Subsidies** → Create/update individual records
5. **Inventory Deductions** → Operate on individual records
6. **Source Tracking** → Subsidy lineage preserved per record
7. **Expiration Monitoring** → Each expiration tracked separately
8. **FIFO Logic** → Unchanged, operates on actual records

### User Experience Improvements

1. **Cleaner Display** → Less clutter when same item appears multiple times
2. **Better Overview** → Easier to see total available quantity
3. **Preserved Detail** → Can still drill down to individual records
4. **Accurate Reporting** → Totals reflect actual inventory
5. **Excel Export** → Clear indication of merged vs. individual records

---

## Key Implementation Points

### Pagination
Items are merged BEFORE pagination, so:
- Merged groups count as 1 item per page
- Page counts reflect merged view
- Query string preserved across pages

### Sorting
Merged records sorted by:
1. Active status (active first)
2. Total quantity (highest first)
3. Description (alphabetically)

### Filtering
Filters applied BEFORE merging:
- Search by description/stock number
- Filter by category
- Filter by warehouse
- Filter by stock status
- Filter by source subsidy status

Then results are merged and displayed.

### Actions on Merged Records
For merged items in the Items page:
- **View** → Expand to show source records
- **Edit** → Must expand and edit individual source records
- **Delete** → Must expand and delete individual source records
- **Stock Card** → Must access from individual source records

For non-merged items:
- All actions work normally (View, Edit, Delete, Stock Card)

---

## Files Modified

### Controllers
1. `app/Http/Controllers/ItemController.php`
   - Modified `index()` method to merge items before pagination
   - Added merge key logic
   - Preserved source items in merged object

2. `app/Http/Controllers/ReportController.php`
   - Modified `inventoryBalance()` method to merge items
   - Modified `exportInventoryBalance()` method for Excel export
   - Added merge indicators in export

### Views
1. `resources/views/items/index.blade.php`
   - Added merge badge display
   - Added source records expansion/collapse
   - Added JavaScript toggle function
   - Modified action buttons for merged items

2. `resources/views/reports/inventory_balance.blade.php`
   - Added merge badge display
   - Added source records breakdown
   - Modified display logic for merged groups

---

## Performance Considerations

### Memory
- Merging operates on already-fetched collections
- No additional database queries for merging
- Source items stored as references, not duplicates

### Speed
- Grouping operation is O(n) where n = number of items
- Pagination applied after merging reduces page load size
- No performance degradation observed

### Scalability
- Works efficiently with thousands of items
- Pagination ensures page size remains manageable
- Database queries unchanged (no JOIN overhead)

---

## Future Enhancements (Optional)

1. **User Preference** → Toggle to show merged vs. unmerged view
2. **Merge Indicator in Excel** → Color-code merged rows
3. **Quick Filter** → "Show only merged items"
4. **Merge Statistics** → Dashboard showing merge ratios
5. **Export Options** → Choose merged or detailed export

---

## Testing Checklist

- [x] Items page displays merged records correctly
- [x] Inventory Balance report displays merged records correctly
- [x] Source records expandable on Items page
- [x] Excel export includes merge indicators
- [x] Individual actions work from source records
- [x] Pagination works with merged view
- [x] Filters work correctly before merging
- [x] Stock cards unaffected
- [x] Stock transfers unaffected
- [x] Requisitions/dispatching unaffected
- [x] Delivery subsidies unaffected
- [x] Inventory calculations remain accurate

---

## Summary

The inventory merging feature successfully groups visually identical stock records for cleaner display while maintaining complete data integrity at the database level. All stock tracking, FIFO logic, expiration monitoring, and transaction history remain fully functional.

**Merge Key:** `Description + Unit Cost + ENGAS Unit Cost + Expiration Date + Warehouse`

**Result:** Users see consolidated inventory totals in the UI, but can drill down to individual records for detailed management and tracking.
