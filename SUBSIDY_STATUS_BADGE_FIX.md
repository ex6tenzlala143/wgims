# "FROM ACTIVE SUBSIDY" Badge Investigation & Fix

## Investigation Summary

### Problem Reported
The user saw a "FROM ACTIVE SUBSIDY" badge on inventory items and was confused because:
1. They did not delete or deactivate the subsidy
2. The wording sounded like a warning or error
3. It was unclear why this badge was being displayed

### Root Cause Discovered

After investigating the codebase, I found that the `source_subsidy_status` field on items can have these values:
- `null` - Normal item (no special status)
- `'deleted'` - Item came from a deleted subsidy
- `'archived'` - Item came from an archived subsidy
- `'active'` - Item was from an archived subsidy that was later **restored**

**The Issue:**
When an admin **restores** an archived subsidy, the system was marking all related items with `source_subsidy_status = 'active'`. This was intended to indicate "this item was previously flagged as archived, but the subsidy is now active again."

However, displaying "FROM ACTIVE SUBSIDY" as a badge was **confusing** because:
- It looks like a warning that requires action
- It's unclear what "active" means in this context
- Users expect badges only for actual problems (deleted/archived)
- Items from normal active subsidies don't show any badge, so why would restored ones?

---

## Business Logic Explanation

### Subsidy Lifecycle

1. **Normal Subsidy** → Items created with `source_subsidy_status = null`
2. **Subsidy Archived** → Items marked with `source_subsidy_status = 'archived'`
3. **Subsidy Restored** → Items were marked with `source_subsidy_status = 'active'` ❌
4. **Subsidy Deleted** → Items marked with `source_subsidy_status = 'deleted'`

### Why Track Subsidy Status?

The system tracks which items came from deleted/archived subsidies so administrators can:
- Identify stock that may need review
- Understand the history of problematic deliveries
- Track items across stock transfers (the status follows the stock)

### The Problem with 'active' Status

When a subsidy is **restored** from archived state:
- The subsidy itself returns to normal operation
- Related items should also return to normal
- Marking them as 'active' creates a **permanent badge** that serves no purpose
- It confuses users who see what looks like a warning

---

## Solution Implemented

### Change 1: Hide Badge for 'active' Status

**File:** `resources/views/partials/subsidy-source-badge.blade.php`

**Before:**
```blade
@if($status)
```

**After:**
```blade
@if($status && in_array($status, ['deleted', 'archived']))
```

**Result:** Badge now only shows for `deleted` and `archived`, not for `active`.

---

### Change 2: Clear Status Instead of Setting 'active'

**File:** `app/Http/Controllers/DeliverySubsidyController.php`

**Function:** `markItemsWithSubsidyState()`

**Before:**
```php
foreach (Item::whereIn('id', $lineageIds)->get() as $item) {
    if ($state === 'active' && $item->source_subsidy_status !== 'archived') {
        continue;
    }
    
    $item->applySubsidySnapshot(
        $deliverySubsidy->id,
        $deliverySubsidy->ris_number,
        $deliverySubsidy->dr_number,
        $state  // ← Sets 'active'
    );
}
```

**After:**
```php
foreach (Item::whereIn('id', $lineageIds)->get() as $item) {
    if ($state === 'active') {
        if ($item->source_subsidy_status === 'archived' && 
            $item->source_subsidy_id === $deliverySubsidy->id) {
            // Clear the status by setting to null
            $item->applySubsidySnapshot(
                $deliverySubsidy->id,
                $deliverySubsidy->ris_number,
                $deliverySubsidy->dr_number,
                null  // ← Clears status instead
            );
        }
        continue;
    }
    
    // For deleted/archived states, apply the status
    $item->applySubsidySnapshot(...);
}
```

**Result:** When restoring an archived subsidy, items return to normal status (null) instead of being marked as 'active'.

---

### Change 3: Allow Null Status in applySubsidySnapshot

**File:** `app/Models/Item.php`

**Method:** `applySubsidySnapshot()`

**Before:**
```php
public function applySubsidySnapshot(?int $subsidyId, ?string $ris, ?string $dr, string $status): void
```

**After:**
```php
public function applySubsidySnapshot(?int $subsidyId, ?string $ris, ?string $dr, ?string $status): void
```

**Result:** Method now accepts `null` as a valid status value, allowing us to clear the status.

---

### Change 4: Clean Up Existing Data

**File:** `database/migrations/2026_08_17_110426_clear_active_subsidy_status_from_items.php`

**SQL Executed:**
```sql
UPDATE items 
SET source_subsidy_status = NULL 
WHERE source_subsidy_status = 'active'
```

**Result:** All existing items with 'active' status are now cleared (set to null).

**Migration Status:** ✅ Successfully executed

---

## New Behavior

### Status Flow

| Scenario | Previous Status | New Status | Badge Shown? |
|----------|----------------|------------|--------------|
| Normal subsidy item | `null` | `null` | ❌ No |
| Subsidy archived | `null` | `'archived'` | ⚠️ Yes - "FROM ARCHIVED SUBSIDY" |
| Archived subsidy restored | `'archived'` | ~~`'active'`~~ → `null` | ❌ No |
| Subsidy deleted | `null` or `'archived'` | `'deleted'` | 🚫 Yes - "FROM DELETED SUBSIDY" |

### Badge Display Logic

**Badges Only Show For:**
- ✅ `'deleted'` - Red badge with danger icon
- ✅ `'archived'` - Orange badge with warning icon

**No Badge For:**
- ❌ `null` - Normal item
- ❌ `'active'` - (No longer used; cleared to null)

---

## Testing Results

### Before Fix
```
Item: Family Food Packs
Status: active
Badge: "🟡 FROM ACTIVE SUBSIDY"  ← Confusing!
```

### After Fix
```
Item: Family Food Packs
Status: null
Badge: (none)  ← Clean!
```

### Test Scenarios

#### Scenario 1: Item from Normal Subsidy
- **Status:** `null`
- **Badge:** None ✅
- **Correct:** Item from active subsidy shows no warning

#### Scenario 2: Item from Archived Subsidy
- **Status:** `'archived'`
- **Badge:** "FROM ARCHIVED SUBSIDY" ✅
- **Correct:** Shows warning that subsidy is archived

#### Scenario 3: Item from Restored Subsidy (Previously Archived)
- **Status:** `null` (cleared when restored)
- **Badge:** None ✅
- **Correct:** Subsidy is active again, no warning needed

#### Scenario 4: Item from Deleted Subsidy
- **Status:** `'deleted'`
- **Badge:** "FROM DELETED SUBSIDY" ✅
- **Correct:** Shows critical warning that subsidy was deleted

---

## Files Modified

1. ✅ `resources/views/partials/subsidy-source-badge.blade.php`
   - Hide badge for 'active' status
   
2. ✅ `app/Http/Controllers/DeliverySubsidyController.php`
   - Clear status instead of setting 'active' when restoring
   
3. ✅ `app/Models/Item.php`
   - Allow null status in applySubsidySnapshot method
   
4. ✅ `database/migrations/2026_08_17_110426_clear_active_subsidy_status_from_items.php`
   - Clean up existing 'active' statuses

---

## Why This Solution is Correct

### Business Logic Perspective
- **Archived subsidy** = Problem that needs attention → Show badge ✅
- **Deleted subsidy** = Critical issue → Show badge ✅
- **Restored subsidy** = Problem resolved → No badge ✅
- **Normal subsidy** = No issue → No badge ✅

### User Experience Perspective
- Users only see badges for actual problems
- "FROM ACTIVE SUBSIDY" was confusing and looked like an error
- Clearing the status when restoring makes logical sense
- Badge system is now intuitive and actionable

### Technical Perspective
- Status values are now semantically correct
- `null` = normal (no special status)
- `'archived'` = temporarily frozen
- `'deleted'` = permanently removed
- ~~`'active'`~~ = removed (not needed)

---

## Verification Steps

### 1. Check Database
```sql
-- Should return 0 rows
SELECT * FROM items WHERE source_subsidy_status = 'active';

-- Should show items with deleted/archived status only
SELECT stock_number, description, source_subsidy_status 
FROM items 
WHERE source_subsidy_status IS NOT NULL;
```

### 2. Check UI
1. Go to **Inventory** → **Items**
2. Look for any "FROM ACTIVE SUBSIDY" badges
3. **Expected:** None should appear
4. **Expected:** Only "FROM DELETED SUBSIDY" or "FROM ARCHIVED SUBSIDY" badges

### 3. Test Archive/Restore Flow
1. Archive a subsidy
2. Check items → Should show "FROM ARCHIVED SUBSIDY"
3. Restore the subsidy
4. Check items → Badge should **disappear**

---

## Status: ✅ COMPLETED

**Date:** August 17, 2026  
**Issue:** Confusing "FROM ACTIVE SUBSIDY" badge  
**Root Cause:** Items marked as 'active' when restoring archived subsidies  
**Solution:** Clear status to null instead of setting 'active'  
**Migration:** Successfully executed  
**Breaking Changes:** None  
**User Impact:** Positive - removes confusing badge  

The badge system now accurately reflects only problematic subsidies (deleted/archived) and doesn't show misleading warnings for items from restored or normal subsidies.
