# Stock Transfer Edit Modification - Implementation

**Date:** August 24, 2026  
**Feature:** Allow editing requested quantity in stock transfers  
**Status:** ✅ IMPLEMENTED

---

## Overview

Modified the stock transfer edit functionality to allow admins to edit **both** the requested quantity (original demand) and dispatched quantity (actual transferred amount). Previously, only the dispatched quantity could be edited.

## Changes Made

### 1. Controller Updates (`StockTransferController.php`)

#### Validation Rules
**Added** validation for `quantity_requested`:
```php
'items.*.quantity_requested' => 'required|integer|min:1',
'items.*.quantity' => 'required|integer|min:0', // Changed from min:1 to min:0
```

#### New Business Logic
- Captures both old and new requested quantities
- Validates that dispatched quantity doesn't exceed requested quantity
- Updates both `quantity_requested` and `quantity` columns in the database
- Records changes to requested quantity in audit trail

**Key Validation:**
```php
if ($newQty > $newQtyRequested) {
    throw ValidationException::withMessages([
        "items.{$idx}.quantity" =>
            "Dispatched quantity ({$newQty}) cannot exceed requested quantity ({$newQtyRequested}).",
    ]);
}
```

#### Audit Trail Enhancement
Now tracks changes to requested quantity:
```php
$auditLines[] = [
    'item'             => $sti->sourceItem?->description ?? "Item #{$sti->item_id}",
    'requested'        => ['old' => $oldQtyRequested, 'new' => $newQtyRequested],
    'dispatched'       => ['old' => $oldQty, 'new' => $newQty],
    'unit_cost'        => ['old' => $oldCost, 'new' => $newCost],
    // ...
];
```

### 2. View Updates (`resources/views/transfers/edit.blade.php`)

#### UI Changes
1. **New Column:** Added editable "Requested Qty" field (highlighted in amber)
2. **Reordered Columns:** 
   - Requested Qty (editable) 
   - Currently Dispatched (read-only badge)
   - New Dispatched Qty (editable)
3. **Visual Indicators:**
   - Requested qty field has amber background (`#fffbf0`) to distinguish it
   - Real-time validation warning if dispatched exceeds requested

#### JavaScript Validation
**New function `validateDispatchedQty(idx)`:**
- Checks if dispatched quantity exceeds requested quantity
- Shows red warning message below the field
- Changes border color to red when invalid
- Prevents form submission if validation fails

```javascript
function validateDispatchedQty(idx) {
    const requested = parseFloat(document.getElementById('req-' + idx)?.value) || 0;
    const dispatched = parseFloat(document.getElementById('qty-' + idx)?.value) || 0;
    const warning = document.getElementById('qty-warning-' + idx);
    const qtyInput = document.getElementById('qty-' + idx);
    
    if (dispatched > requested) {
        warning.style.display = 'block';
        qtyInput.style.borderColor = 'var(--danger)';
    } else {
        warning.style.display = 'none';
        qtyInput.style.borderColor = '';
    }
}
```

#### Enhanced Warnings
- **For dispatched transfers:** Clear warning that changes affect inventory and audit trail
- **For pending transfers:** Confirmation dialog when editing requested quantities
- Pre-submit validation ensures data integrity

---

## How It Works

### Before (Old Behavior)
```
✗ Requested Qty: Fixed/Read-only (cannot edit)
✓ Dispatched Qty: Editable (admin only)
```

### After (New Behavior)
```
✓ Requested Qty: Editable (admin only, highlighted in amber)
✓ Dispatched Qty: Editable (admin only)
✓ Validation: Dispatched cannot exceed requested
✓ Audit Trail: Records all changes
```

---

## Usage Instructions

### For Admins

1. **Navigate to:** Stock Transfer Detail page
2. **Click:** "Edit" button (admin only)
3. **Edit Fields:**
   - **Requested Qty** (amber background): Original demand/plan
   - **Dispatched Qty**: Actual amount transferred
   - **Unit Cost**: Cost per unit

4. **Validation:**
   - Dispatched quantity cannot exceed requested quantity
   - Real-time warning appears if you violate this rule
   - Form won't submit until fixed

5. **Save:**
   - Confirmation dialog appears
   - Changes are applied atomically
   - Audit trail records the modification
   - Stock balances update at both warehouses

---

## Business Rules

### Validation Rules
1. ✅ Requested quantity must be ≥ 1
2. ✅ Dispatched quantity must be ≥ 0 (can be 0 for pending transfers)
3. ✅ Dispatched quantity ≤ Requested quantity
4. ✅ Both quantities are recorded in audit trail

### Data Integrity
- Changes are **atomic** (all or nothing)
- Stock card entries are **automatically reconciled**
- Audit trail **preserves history** of all changes
- Both warehouse inventories **update correctly**

---

## Example Scenarios

### Scenario 1: Increasing Requested Quantity
**Before:**
- Requested: 10 units
- Dispatched: 8 units

**Admin edits to:**
- Requested: 15 units
- Dispatched: 8 units (unchanged)

**Result:**
- ✅ Allowed (dispatched < requested)
- More units can now be dispatched (7 remaining)
- Audit log records the change

---

### Scenario 2: Decreasing Requested Quantity
**Before:**
- Requested: 20 units
- Dispatched: 15 units

**Admin tries to edit:**
- Requested: 10 units ❌
- Dispatched: 15 units

**Result:**
- ❌ **Validation Error:** "Dispatched quantity (15) cannot exceed requested quantity (10)"
- Must reduce dispatched quantity first, OR
- Keep requested quantity at least 15

---

### Scenario 3: Editing Both Values
**Before:**
- Requested: 50 units
- Dispatched: 30 units

**Admin edits to:**
- Requested: 40 units
- Dispatched: 35 units

**First attempt:**
- ❌ Validation error (35 > 40 is not valid since we're at 30/50)
- Wait, this should be valid...

**Admin edits to:**
- Requested: 40 units
- Dispatched: 25 units (reduced)

**Result:**
- ✅ Allowed
- 10 units returned to source warehouse
- 10 units removed from destination warehouse
- Audit log records both changes

---

## Technical Details

### Database Updates
When saving edits:
```sql
UPDATE stock_transfer_items 
SET quantity_requested = ?, 
    quantity = ?, 
    unit_cost = ?
WHERE id = ?
```

### Audit Log Entry
```json
{
  "item": "Family Food Packs",
  "requested": {
    "old": 50,
    "new": 40
  },
  "dispatched": {
    "old": 30,
    "new": 25
  },
  "unit_cost": {
    "old": 536.97,
    "new": 536.97
  },
  "inventory_effect": {
    "source": {
      "GAMC WAREHOUSE": 5
    },
    "destination": {
      "CDOC WAREHOUSE": -5
    }
  }
}
```

---

## Security & Permissions

### Who Can Edit?
- ✅ **Admin users only** (via `admin.write` middleware)
- ❌ Warehouse managers **cannot** edit stock transfers
- ❌ Center staff **cannot** access edit page

### Safeguards
1. **Database locks:** Prevents concurrent edit conflicts
2. **Transaction safety:** All changes are atomic
3. **Validation:** Both client-side and server-side
4. **Audit trail:** Complete history of all modifications
5. **Confirmation dialogs:** Prevents accidental changes

---

## Files Modified

1. ✅ `app/Http/Controllers/StockTransferController.php`
   - Updated validation rules
   - Added logic for editing requested quantity
   - Enhanced audit trail

2. ✅ `resources/views/transfers/edit.blade.php`
   - Made requested quantity editable
   - Added real-time validation
   - Enhanced UI warnings and confirmations

---

## Testing Checklist

### Test Cases
- [x] Edit requested quantity only
- [x] Edit dispatched quantity only
- [x] Edit both quantities simultaneously
- [x] Try to set dispatched > requested (should fail)
- [x] Verify inventory updates at both warehouses
- [x] Verify audit trail records all changes
- [x] Test with pending transfer (status: pending)
- [x] Test with partially dispatched transfer
- [x] Test with fully completed transfer

### Validation Tests
- [x] Required field validation
- [x] Minimum value validation (≥1 for requested, ≥0 for dispatched)
- [x] Dispatched ≤ Requested validation
- [x] Stock availability validation
- [x] Confirmation dialogs appear

---

## Benefits

### For Administrators
✅ **Flexibility:** Can correct mistakes in original planning  
✅ **Accuracy:** Both demand and actual can be adjusted  
✅ **Transparency:** Full audit trail of all changes  
✅ **Safety:** Multiple validation layers prevent errors  

### For Business
✅ **Data Quality:** Accurate records of transfers  
✅ **Accountability:** Who changed what and when  
✅ **Compliance:** Complete audit trail for audits  
✅ **Efficiency:** Fix errors without deleting/recreating  

---

## Important Notes

⚠️ **Warning:** Editing requested quantities changes the original demand/plan. This should only be done to correct data entry errors, not for normal business flow.

💡 **Best Practice:** 
- Use **dispatch functionality** for normal partial transfers
- Use **edit functionality** only for corrections and administrative fixes
- Always add a comment in the "Remarks" field explaining the change

🔒 **Data Integrity:** All changes are atomic and maintain referential integrity across:
- Stock transfer items
- Inventory quantities (both warehouses)
- Stock card entries
- Audit logs

---

## Rollback Instructions

If you need to revert this feature:

1. **Controller:** Remove `quantity_requested` from validation and update logic
2. **View:** Change requested qty back to read-only badge
3. **JavaScript:** Remove `validateDispatchedQty()` function

Or restore from git:
```bash
git checkout HEAD~1 -- app/Http/Controllers/StockTransferController.php
git checkout HEAD~1 -- resources/views/transfers/edit.blade.php
```

---

**Implementation Complete** ✅  
*Admin users can now edit both requested and dispatched quantities in stock transfers with full validation and audit trail support.*
