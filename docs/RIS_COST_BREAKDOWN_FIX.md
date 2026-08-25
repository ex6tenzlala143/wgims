# RIS Partial Delivery Breakdown - Cost Separation Fix

## Issue Summary
When a requisition (RIS) had the same item name dispatched from multiple stock records with **different unit costs**, the "Requested Items" section was incorrectly showing:
- Only ONE unit cost and ENGAS unit cost
- Comma-separated stock numbers (e.g., "STOCK-001, STOCK-002")
- No way to distinguish which cost belonged to which stock

**Example:**
- Family Kits from Stock A: ₱2,658.00
- Family Kits from Stock B: ₱1,418.00
- Table showed only: ₱2,658.00 (missing Stock B's cost)

## Root Cause
The `requisitions/show.blade.php` view was grouping dispatch items by `RequisitionItem` (item name) only, without considering that **each dispatch item could reference a different stock record with different costs**.

## Solution Implemented

### Backend (No Changes Required)
The backend data structure was already correct:
- `RequisitionItem`: 1 per item name requested
- `RequisitionDispatchItem`: multiple records, each linked to specific `Item` (stock record)
- Each `Item` has its own: `stock_number`, `unit_cost`, `engas_unit_cost`, `warehouse_id`, `expiration_date`

### Frontend Changes
Modified `resources/views/requisitions/show.blade.php` to **expand rows by unique cost combinations**:

#### Key Implementation
```blade
// Group dispatch items by unique cost combination
$dispatchGroups = $ri->dispatchItems->groupBy(function($di) {
    return ($di->item?->stock_number ?? 'no-stock') . '|' . 
           ($di->unit_cost ?? 0) . '|' . 
           ($di->engas_unit_cost ?? 0) . '|' .
           ($di->item?->warehouse_id ?? 0) . '|' .
           ($di->expiration_date?->format('Y-m-d') ?? 'no-expiry');
});
```

#### Display Logic
1. **Single cost group** (normal case): Show as regular row
2. **Multiple cost groups**: 
   - Show main row with total `quantity_requested`
   - Show sub-rows (indented with `└─`) for each unique cost combination
   - Each sub-row displays:
     - Stock Number
     - Unit Cost (highlighted in primary color)
     - ENGAS Unit Cost (highlighted in green)
     - ENGAS Total (for this cost group)
     - Quantity Issued (for this cost group only)
     - Total Cost (for this cost group)

#### Visual Styling
Sub-rows have:
- Background: `#f9fafb`
- Left border: `3px solid var(--primary)`
- Indent symbol: `└─` in muted gray
- Description: smaller, muted text
- Costs: bold, color-highlighted

## Testing Instructions

### 1. Find or Create Test Requisition
You need a requisition with the same item name dispatched from multiple stocks with different costs.

**Option A - Use Existing:**
Navigate to the requisition you mentioned (with Family Kits at ₱2,658 and ₱1,418)

**Option B - Create New:**
1. Create 2 different stock records with same description but different costs:
   ```
   Item A:
   - Stock No: TEST-001
   - Description: "Test Family Kits"
   - Unit Cost: ₱2,658.00
   - ENGAS Unit Cost: ₱2,658.00
   - Warehouse: Central Warehouse
   
   Item B:
   - Stock No: TEST-002
   - Description: "Test Family Kits"
   - Unit Cost: ₱1,418.00
   - ENGAS Unit Cost: ₱1,418.00
   - Warehouse: Regional Warehouse
   ```

2. Create a requisition requesting "Test Family Kits" (quantity: 100)

3. Dispatch from both stocks:
   - 50 units from TEST-001 (₱2,658.00)
   - 50 units from TEST-002 (₱1,418.00)

### 2. Verify Display

**Navigate to:** Requisitions → View (eye icon) → Scroll to "Requested Items" section

**Expected Result:**

```
Stock No.    Unit    Description         Warehouse    Exp Date    Unit Cost    ENGAS Cost    ENGAS Total    Qty Req    Total Cost    Stock?    Qty Issued    Outstanding
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
TEST-001     pcs     Test Family Kits    [warehouse]  [date]      [total]      [total]       [total]        100        [total]       ✓ Yes     100           0
└─ TEST-001  pcs     Test Family Kits    Central      [date]      ₱ 2,658.00   ₱ 2,658.00    ₱ 132,900.00   —          ₱ 132,900.00  —         50            —
└─ TEST-002  pcs     Test Family Kits    Regional     [date]      ₱ 1,418.00   ₱ 1,418.00    ₱ 70,900.00    —          ₱ 70,900.00   —         50            —
```

**Key Checks:**
✅ **Two sub-rows** appear (indented with └─)
✅ Each sub-row shows **different stock number**
✅ Each sub-row shows **different unit cost** (₱2,658 vs ₱1,418)
✅ Each sub-row shows **different ENGAS unit cost**
✅ Each sub-row shows **different ENGAS total** (calculated per cost group)
✅ Each sub-row shows **quantity issued for that cost group** (50 each)
✅ Main row shows **total quantities** (100 requested, 100 issued, 0 outstanding)
✅ Sub-rows have **light gray background** with **blue left border**

### 3. Edge Cases to Test

#### Case 1: Undispatched Requisition
- Create requisition, don't dispatch yet
- **Expected:** Show as single row (no sub-rows)

#### Case 2: Same Cost, Different Stock Numbers
- Dispatch from 2 stocks with identical costs but different stock numbers
- **Expected:** Show as single row (costs are same, no need to split)

#### Case 3: Partial Dispatch with Multiple Costs
- Request 100, dispatch only 30 from Stock A (₱2,658) and 20 from Stock B (₱1,418)
- **Expected:** 
  - Main row shows: 100 requested, 50 issued, 50 outstanding
  - Two sub-rows showing 30 and 20 issued respectively

#### Case 4: Admin vs Regular User
- Admin users see ENGAS columns
- Regular users don't
- **Expected:** Both user types see correct cost breakdowns (just different columns)

### 4. Verify "Partial Delivery Breakdown" Section
Scroll down to the **"Partial Delivery Breakdown"** section (bottom table) and verify:

✅ Each dispatch is listed as **separate row**
✅ Stock Number column shows correctly
✅ Unit Cost shows correctly
✅ ENGAS Unit Cost column exists (for admin users)
✅ ENGAS Total column exists (for admin users)
✅ Quantities match the "Requested Items" sub-rows above

### 5. Verify No Side Effects

**Important:** This change is display-only. Verify:
- ✅ Inventory quantities unchanged
- ✅ Stock card entries unchanged
- ✅ Transaction history unchanged
- ✅ Totals calculate correctly
- ✅ Other requisitions display normally
- ✅ Export/print functionality still works

## Files Modified

### Primary File
- `resources/views/requisitions/show.blade.php`
  - Lines ~180-380: "Requested Items" table rendering logic
  - Added cost group detection and sub-row rendering

### No Database Changes
- No migrations required
- No model changes required
- No controller changes required

## Technical Details

### Grouping Key
The grouping key combines:
1. Stock Number
2. Unit Cost
3. ENGAS Unit Cost
4. Warehouse ID
5. Expiration Date

This ensures that items are only merged if **all cost-relevant attributes are identical**.

### Performance Impact
- Minimal: Grouping is done in-memory on already-loaded relationships
- No additional database queries
- Only affects requisitions with partial dispatches from multiple stock records

## Rollback Instructions
If issues arise, revert the commit that modified `resources/views/requisitions/show.blade.php`:

```bash
git log --oneline resources/views/requisitions/show.blade.php
git revert <commit-hash>
```

Or manually restore the previous version where `$dispatchGroups` logic didn't exist.

## Business Rules Reinforced

**Key Rule:** Same item name ≠ Same stock record

- Never merge different stock records just because their description/name is the same
- Each stock record has its own cost lineage (from subsidy, transfer, or delivery)
- Costs must remain traceable to their source transaction
- Display must respect the granularity of the underlying data

## Notes for Future Development

1. **Consider backend optimization**: If this pattern is needed elsewhere, create a `RequisitionItem::getDispatchCostGroups()` method
2. **Export consistency**: Ensure PDF/Excel exports also show cost breakdowns
3. **Similar views**: Check if Stock Transfers or other modules need similar treatment
4. **User feedback**: Monitor if users find the sub-row display intuitive or need adjustments

---

**Status:** ✅ Implementation Complete  
**Date:** 2026-08-24  
**Tested:** Pending user verification  
**Server:** Running at http://127.0.0.1:8000
