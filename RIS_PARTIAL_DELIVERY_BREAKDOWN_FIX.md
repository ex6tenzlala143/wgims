# RIS Partial Delivery Breakdown - Fix

**Date:** August 24, 2026  
**Issue:** Missing cost differentiation for same item name with different stock records  
**Status:** ✅ FIXED

---

## Problem Description

When the same item name/description appears multiple times in a requisition but comes from different stock records (different Item IDs), the Partial Delivery Breakdown was not showing enough detail to distinguish between them.

### What Was Missing

The breakdown table was missing critical columns that identify unique stock records:

1. ❌ **Stock Number** - Primary identifier for stock records
2. ❌ **ENGAS Unit Cost** - Government subsidy unit cost
3. ❌ **ENGAS Total Value** - Total ENGAS cost for the dispatch

### Example of the Problem

**Scenario:** Requisition for "Family Food Packs" with two different stock records:

```
Family Food Packs (Request: 150 units)
  
  Dispatch 1:
    - Stock: GAMC-FOO-0001
    - Unit Cost: ₱980.00
    - ENGAS Unit Cost: ₱980.00
    - Qty: 100
    
  Dispatch 2:
    - Stock: GAMC-FOO-0002  
    - Unit Cost: ₱950.00
    - ENGAS Unit Cost: ₱950.00
    - Qty: 50
```

**Before Fix:**
The table only showed one Unit Cost column, making it impossible to see that these were different stock records with different costs.

**After Fix:**
The table now shows Stock Number, both Unit Cost columns, and ENGAS columns, clearly distinguishing the two records.

---

## Root Cause Analysis

### Data Structure (Correct)

The backend data structure was **CORRECT**:

- ✅ Each `RequisitionDispatchItem` correctly references a specific `Item` (stock record)
- ✅ Each dispatch has its own `item_id`, `unit_cost`, `engas_unit_cost`
- ✅ Stock records with same name but different costs remain separate
- ✅ No inappropriate grouping or merging in the database

### View Issue (Fixed)

The **view template** was missing columns:

- ❌ Stock Number column not displayed
- ❌ ENGAS Unit Cost column not displayed  
- ❌ ENGAS Total column not displayed

This made it appear as though different stock records were being merged, when in reality they were separate but visually indistinguishable.

---

## Solution Implemented

### Added 3 New Columns to Breakdown Table

1. **Stock No.** Column
   - Displays `item.stock_number`
   - Shows unique identifier for each stock record
   - Formatted as badge: `GAMC-FOO-0001`

2. **ENGAS Unit Cost** Column
   - Displays `engas_unit_cost` from dispatch
   - Shows government subsidy unit price
   - Color: Green (#059669)

3. **ENGAS Total** Column
   - Calculated: `quantity_issued × engas_unit_cost`
   - Shows total ENGAS value for that dispatch
   - Color: Green (#059669)

### Updated Table Structure

**New Column Order:**
1. # (sequence)
2. Date (with expiry)
3. **Stock No.** ⭐ NEW
4. Warehouse
5. Qty (quantity issued)
6. DR No.
7. Unit Cost
8. Value (total cost)
9. **ENGAS Unit Cost** ⭐ NEW
10. **ENGAS Total** ⭐ NEW
11. Cumulative
12. Actions (edit button)

---

## Code Changes

### File Modified
**`resources/views/requisitions/show.blade.php`**

### Before (Missing Columns)
```blade
<th>Date</th>
<th>Warehouse</th>
<th>Qty</th>
<th>DR No.</th>
<th>Unit Cost</th>
<th>Value</th>
<th>Cumulative</th>
```

### After (Complete Information)
```blade
<th>Date</th>
<th>Stock No.</th>        <!-- NEW -->
<th>Warehouse</th>
<th>Qty</th>
<th>DR No.</th>
<th>Unit Cost</th>
<th>Value</th>
<th>ENGAS Unit Cost</th>  <!-- NEW -->
<th>ENGAS Total</th>       <!-- NEW -->
<th>Cumulative</th>
```

### Key Implementation Details

```blade
{{-- Stock Number Column --}}
<td style="padding:10px 14px">
    @if($di->item && $di->item->stock_number)
        <code style="font-size:11px;background:#f0fdf4;padding:2px 6px;border-radius:4px;color:var(--success)">
            {{ $di->item->stock_number }}
        </code>
    @else
        <span style="color:var(--text-muted);font-size:11px">—</span>
    @endif
</td>

{{-- ENGAS Unit Cost Column --}}
<td style="padding:10px 14px;text-align:right">
    @if($di->engas_unit_cost)
        <span style="color:#059669">₱{{ number_format($di->engas_unit_cost, 2) }}</span>
    @else
        <span style="color:var(--text-muted)">—</span>
    @endif
</td>

{{-- ENGAS Total Column --}}
<td style="padding:10px 14px;text-align:right">
    @if($engasTotal)
        <span style="color:#059669;font-weight:600">₱{{ number_format($engasTotal, 2) }}</span>
    @else
        <span style="color:var(--text-muted)">—</span>
    @endif
</td>
```

### Footer Totals Updated

The footer row now also shows:
- Total ENGAS value across all dispatches
- Proper colspan adjustments for new columns

```blade
<tfoot>
    <tr style="background:#f7fafc;font-weight:700;border-top:2px solid var(--border)">
        <td colspan="4">Total Issued</td>
        <td>{{ number_format($ri->quantity_issued) }}</td>
        <td></td>
        <td></td>
        <td>₱{{ number_format($dispatches->sum(...)) }}</td>
        <td></td>
        <td>
            <!-- Total ENGAS -->
            ₱{{ number_format($totalEngas, 2) }}
        </td>
        <td><!-- Outstanding --></td>
        <td></td>
    </tr>
</tfoot>
```

---

## Verification Checklist

### Visual Verification
- ✅ Stock Number column appears and shows correct values
- ✅ ENGAS Unit Cost column shows green-colored costs
- ✅ ENGAS Total column shows calculated totals
- ✅ All columns properly aligned
- ✅ Footer totals include ENGAS total

### Data Integrity Verification
- ✅ No changes to database queries
- ✅ No changes to grouping/aggregation logic
- ✅ Each dispatch still references its unique `item_id`
- ✅ Different stock records with same name remain separate
- ✅ Historical data unchanged

### Test Scenario
**Create two dispatches for same item name but different stock:**

1. Create requisition for "Family Food Packs" (150 units)
2. Dispatch 100 units from Stock A (₱980 unit cost, ₱980 ENGAS)
3. Dispatch 50 units from Stock B (₱950 unit cost, ₱950 ENGAS)
4. View requisition details
5. Verify breakdown shows:
   - Two separate rows
   - Different stock numbers
   - Different unit costs (₱980 vs ₱950)
   - Different ENGAS costs (₱980 vs ₱950)

---

## Benefits

### For Users
✅ **Clear Differentiation** - Can now see when same item comes from different stock  
✅ **Cost Transparency** - Both regular and ENGAS costs visible  
✅ **Stock Traceability** - Stock numbers identify source records  
✅ **Audit Compliance** - Complete cost information for audits  

### For Administrators
✅ **Data Accuracy** - No confusion about which stock was issued  
✅ **Cost Tracking** - ENGAS subsidies properly tracked  
✅ **Inventory Control** - Stock numbers link to inventory records  

---

## Important Notes

### What This Fix Does NOT Do

❌ **Does NOT change data structure** - All relationships remain the same  
❌ **Does NOT change grouping logic** - Each dispatch is already separate  
❌ **Does NOT affect inventory** - No changes to stock calculations  
❌ **Does NOT modify existing data** - Pure display enhancement  

### What This Fix DOES

✅ **Enhances display** - Shows previously hidden information  
✅ **Adds missing columns** - Stock Number and ENGAS costs  
✅ **Improves clarity** - Distinguishes similar items clearly  
✅ **Maintains accuracy** - All data was already correct  

---

## Key Business Rule Confirmed

**Same item name ≠ Same stock record**

The system correctly maintains that:
- Items with identical descriptions but different costs are **separate records**
- Each dispatch references a **specific Item ID** (not just item name)
- Stock Number + Unit Cost + ENGAS Cost + Expiration + Subsidy = **Unique identity**
- The breakdown **preserves this granularity**

---

## Example: Before vs After

### Before Fix
```
Family Food Packs
  Date          Warehouse    Qty   DR No.    Unit Cost    Value
  Dec 15, 2026  GAMC         100   DR-001    ₱980.00      ₱98,000
  Dec 16, 2026  GAMC         50    DR-002    ₱950.00      ₱47,500
```
❌ No way to see these are different stock records  
❌ Missing ENGAS costs  
❌ Missing stock numbers  

### After Fix
```
Family Food Packs
  Date          Stock No.       Warehouse  Qty  DR No.  Unit Cost  Value     ENGAS Unit  ENGAS Total
  Dec 15, 2026  GAMC-FOO-0001  GAMC       100  DR-001  ₱980.00    ₱98,000   ₱980.00     ₱98,000
  Dec 16, 2026  GAMC-FOO-0002  GAMC       50   DR-002  ₱950.00    ₱47,500   ₱950.00     ₱47,500
```
✅ Stock numbers clearly show different records  
✅ ENGAS costs visible for subsidy tracking  
✅ Complete cost information  

---

## Related System Behavior

### How Different Stock Records Are Created

Different Item records with the same name are created when:

1. **Different Unit Costs**
   ```
   Item A: Family Food Packs @ ₱980.00
   Item B: Family Food Packs @ ₱950.00
   ← Different unit_cost = Different record
   ```

2. **Different ENGAS Costs**
   ```
   Item A: @ ₱980.00 ENGAS
   Item B: @ ₱950.00 ENGAS
   ← Different engas_unit_cost = Different record
   ```

3. **Different Originating Subsidies**
   ```
   Item A: From RIS-GAMC-2026-001
   Item B: From RIS-CDOC-2026-002
   ← Different source_subsidy_id = Different record
   ```

4. **Different Expiration Dates**
   ```
   Item A: Expires Dec 2026
   Item B: Expires Jan 2027
   ← Different expiration_date = Different record
   ```

5. **Different Warehouses**
   ```
   Item A: In GAMC Warehouse
   Item B: In CDOC Warehouse
   ← Different warehouse_id = Different record
   ```

All of these scenarios are now properly visible in the breakdown table.

---

## Files Modified

1. ✅ `resources/views/requisitions/show.blade.php`
   - Added Stock No. column
   - Added ENGAS Unit Cost column
   - Added ENGAS Total column
   - Updated footer totals
   - Adjusted column spans

---

## Testing Performed

### Display Tests
- ✅ Breakdown table renders correctly with new columns
- ✅ Stock numbers display in all rows that have them
- ✅ ENGAS costs display when present
- ✅ Empty values show "—" placeholder
- ✅ Green color applied to ENGAS columns
- ✅ Footer totals calculate correctly

### Data Tests
- ✅ Each dispatch row shows its own stock number
- ✅ Each dispatch row shows its own ENGAS cost
- ✅ Different stock records remain visually distinct
- ✅ No data loss or corruption
- ✅ Historical requisitions display correctly

### Edge Cases
- ✅ Items without stock numbers show "—"
- ✅ Items without ENGAS costs show "—"
- ✅ Single dispatch per item works correctly
- ✅ Multiple dispatches per item work correctly
- ✅ Mixed scenarios (some with/without ENGAS) work

---

## Rollback Instructions

If needed, revert to previous version:

```bash
git checkout HEAD~1 -- resources/views/requisitions/show.blade.php
```

Or manually remove the three added columns:
1. Remove "Stock No." column
2. Remove "ENGAS Unit Cost" column
3. Remove "ENGAS Total" column
4. Adjust colspan in footer

---

**Fix Complete** ✅  
*The Partial Delivery Breakdown now shows complete cost information for each dispatch, properly distinguishing between different stock records even when they have the same item name.*
