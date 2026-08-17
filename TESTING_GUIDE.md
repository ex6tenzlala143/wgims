# Testing Guide - Stock Transfer Modal Fix

## Quick Test (5 minutes)

### 1. Open Stock Transfers Page
1. Navigate to **Inventory → Stock Transfers**
2. **Expected**: Page loads with Transfer Records table
3. **Expected**: "New Transfer" button visible (top-right)

### 2. Test Modal Opening
1. Click **"New Transfer"** button
2. **Expected**: Modal opens as centered popup
3. **Expected**: Background darkened/blurred
4. **Expected**: ONE close button (X) in top-right of modal
5. **Expected**: Modal has header "New Stock Transfer"

### 3. Test Modal Closing
1. Click the **X button** → Modal closes
2. Open again, click **Cancel** button → Modal closes
3. Open again, press **ESC key** → Modal closes
4. Open again, click **outside modal** (on dark background) → Modal closes

### 4. Test Form Basics
1. Open modal
2. Select **Source Warehouse**
3. Select **Destination Warehouse** (different from source)
4. Try selecting **same warehouse** for both → Should be prevented
5. Click **"Add Item"** → New row appears
6. Click **remove button (X)** → Row deleted

### 5. Test Form Submission
1. Fill in all required fields:
   - Source Warehouse
   - Destination Warehouse
   - Transfer Date
   - At least one item with quantity and cost
2. Click **"Save Transfer"**
3. **Expected**: Redirects to transfer detail page
4. **Expected**: Success message displayed
5. **Expected**: Source inventory decreased
6. **Expected**: Destination inventory increased

---

## Detailed Testing Checklist

### Modal Display & Behavior

#### Initial State
- [ ] Page loads without showing modal
- [ ] No form elements visible inline
- [ ] No broken X button visible on page
- [ ] "New Transfer" button present and styled correctly

#### Opening Modal
- [ ] Click "New Transfer" → Modal appears smoothly
- [ ] Modal is centered on screen
- [ ] Modal has white background
- [ ] Background is darkened (semi-transparent overlay)
- [ ] Background has blur effect
- [ ] Body scroll is disabled (page doesn't scroll)

#### Modal Structure
- [ ] Header: "New Stock Transfer" with icon
- [ ] Subtitle: "Pre-position inventory by transferring stock between warehouses"
- [ ] ONE close button (X) in top-right corner
- [ ] Close button is red on hover
- [ ] Form sections have proper spacing
- [ ] Footer has Cancel and Save buttons

#### Closing Modal
- [ ] X button closes modal
- [ ] Cancel button closes modal
- [ ] ESC key closes modal
- [ ] Clicking overlay (dark background) closes modal
- [ ] Closing modal re-enables body scroll
- [ ] Modal disappears smoothly (animation)

### Form Functionality

#### Warehouse Selection (Admin Users)
- [ ] Source warehouse dropdown populates
- [ ] Destination warehouse dropdown populates
- [ ] Both dropdowns show all active warehouses
- [ ] Selecting source disables that option in destination
- [ ] Selecting destination disables that option in source
- [ ] Warning appears if trying to select same for both
- [ ] Destination cleared if matches source

#### Warehouse Selection (Warehouse Manager)
- [ ] Source warehouse is fixed (readonly)
- [ ] Source warehouse shows manager's warehouse
- [ ] Destination warehouse dropdown works
- [ ] Cannot select manager's warehouse as destination

#### Transfer Details
- [ ] Transfer date field defaults to today
- [ ] Can change transfer date
- [ ] Remarks field accepts text (optional)
- [ ] Remarks field allows multiple lines

#### Line Items
- [ ] "Add Item" button adds new row
- [ ] Item dropdown populates with source warehouse items
- [ ] Unit field auto-fills when item selected
- [ ] Available quantity displays correctly
- [ ] ENGAS cost displays (or "Not set" if missing)
- [ ] Unit cost pre-fills from selected item
- [ ] Can change unit cost manually
- [ ] Quantity field accepts decimals (4 decimal places)
- [ ] Total calculates: Quantity × Unit Cost
- [ ] Grand Total sums all row totals
- [ ] Remove button (X) deletes row
- [ ] Grand Total updates after row removal
- [ ] Can add multiple items

#### Validation
- [ ] Cannot submit without source warehouse
- [ ] Cannot submit without destination warehouse
- [ ] Cannot submit without transfer date
- [ ] Cannot submit without at least one item
- [ ] Cannot submit with quantity > available
- [ ] Alert shows if quantity exceeds available
- [ ] Cannot submit with zero quantity
- [ ] Cannot submit with negative quantity
- [ ] Cannot submit with zero cost
- [ ] Cannot submit with negative cost

#### Form Submission
- [ ] "Save Transfer" button works
- [ ] Button shows loading state while saving
- [ ] Button text changes to "Saving..."
- [ ] Button disabled during save (prevents double-submit)
- [ ] Form submits to correct route
- [ ] Redirects to transfer detail on success
- [ ] Success message displayed
- [ ] Modal stays open on validation error
- [ ] Validation errors display in modal
- [ ] Form fields retain values after error

### Responsive Design

#### Desktop (1920×1080)
- [ ] Modal centered with margins
- [ ] All fields visible without scrolling
- [ ] Table shows all columns
- [ ] Modal max-width applied (1400px)

#### Laptop (1366×768)
- [ ] Modal fits screen
- [ ] Internal scrolling works
- [ ] All buttons accessible
- [ ] Table readable

#### Tablet (768px)
- [ ] Modal takes most of screen width
- [ ] Form fields stack appropriately
- [ ] Table converts to card layout
- [ ] All functionality accessible
- [ ] Touch scrolling works

#### Mobile (375px)
- [ ] Modal takes full width with small margins
- [ ] All form fields stacked vertically
- [ ] Table shows card layout
- [ ] Each row shows data labels
- [ ] All buttons reachable
- [ ] Remove buttons work
- [ ] Soft keyboard doesn't break layout

### Backend Processing

#### Inventory Updates
- [ ] Source warehouse quantity decreased
- [ ] Destination warehouse quantity increased
- [ ] Decrease equals increase (no stock lost)
- [ ] Unit costs preserved correctly
- [ ] ENGAS costs propagated
- [ ] Expiration dates transferred

#### Stock Cards
- [ ] transfer_out entry created at source
- [ ] transfer_in entry created at destination
- [ ] Both entries reference same transfer number
- [ ] Quantities match transfer form
- [ ] Unit costs recorded correctly
- [ ] Balance quantities updated
- [ ] from_to warehouse names recorded

#### Transfer Record
- [ ] StockTransfer record created
- [ ] Unique transfer number generated
- [ ] Status set to "pending"
- [ ] Source/destination warehouses saved
- [ ] Transfer date saved
- [ ] Remarks saved (if provided)
- [ ] Transferred by user recorded

#### Transfer Items
- [ ] StockTransferItem records created
- [ ] One record per form line
- [ ] quantity_requested = form quantity
- [ ] quantity = 0 (not dispatched yet)
- [ ] unit_cost from form
- [ ] source item_id correct
- [ ] destination item_id correct

#### Subsidy Lineage
- [ ] If source has RIS number, lineage tracked
- [ ] source_ris_number saved on transfer
- [ ] source_dr_number saved if applicable
- [ ] delivery_subsidy_id linked if found
- [ ] Destination item inherits subsidy trail

#### Notifications
- [ ] Notifications sent to all admins
- [ ] Notification shows transfer number
- [ ] Notification shows warehouses
- [ ] Notification links to transfer detail

#### Audit Log
- [ ] Audit entry created on save
- [ ] User ID recorded
- [ ] Action = "created"
- [ ] Changed fields logged

### Edge Cases

#### Empty States
- [ ] No items in source warehouse → Cannot add items
- [ ] No source warehouse selected → Item dropdown empty
- [ ] Removed all rows → Cannot submit

#### Data Edge Cases
- [ ] Very small quantities (0.0001) work
- [ ] Very large quantities work
- [ ] Very long descriptions don't break layout
- [ ] Items with no ENGAS cost display correctly
- [ ] Items with no stock number display correctly
- [ ] Expired items can still be transferred

#### Permission Edge Cases
- [ ] Admin can select any source/destination
- [ ] Warehouse Manager limited to their warehouse
- [ ] Staff users don't see "New Transfer" button
- [ ] canCreate() properly restricts access

#### Browser Compatibility
- [ ] Works in Chrome
- [ ] Works in Firefox
- [ ] Works in Edge
- [ ] Works in Safari
- [ ] Modal animations smooth in all browsers
- [ ] Backdrop blur works (or graceful fallback)

### Performance

#### Loading
- [ ] Modal opens quickly (<500ms)
- [ ] Item dropdown populates quickly
- [ ] No lag when typing in fields
- [ ] Calculations update instantly

#### Large Forms
- [ ] 10+ line items → Form still responsive
- [ ] 20+ line items → Scrolling smooth
- [ ] 50+ line items → No browser freeze
- [ ] Grand total calculates correctly with many rows

---

## Common Issues & Solutions

### Modal doesn't open
**Possible causes:**
- JavaScript error in console
- `openTransferModal()` function missing
- Modal ID mismatch

**Check:**
1. Open browser console (F12)
2. Look for JavaScript errors
3. Verify function exists: Type `openTransferModal` in console
4. Check modal has `id="createTransferModal"`

### Modal shows inline instead of popup
**Possible causes:**
- CSS not loaded
- `.modal-overlay` missing `visibility: hidden`
- Duplicate CSS blocks

**Check:**
1. Inspect element → Check computed styles
2. `.modal-overlay` should have `visibility: hidden` by default
3. Check for duplicate `@push('styles')` blocks in modal file

### Form doesn't submit
**Possible causes:**
- Validation errors
- CSRF token missing
- Route not found

**Check:**
1. Browser console for errors
2. Network tab shows POST request
3. Response shows validation errors
4. CSRF token present in form

### Items don't load
**Possible causes:**
- No warehouse selected
- API route broken
- Items query returns empty

**Check:**
1. Select source warehouse first
2. Console shows API call to `/api/transfer-items`
3. API response contains items array
4. Items have quantity > 0

### Validation errors disappear
**Possible causes:**
- Modal not staying open after submit
- `$createModalOpen` not passed correctly
- Errors not displayed in modal

**Check:**
1. Controller returns `$errors` to view
2. Modal included with `'createModalOpen' => $errors->any()`
3. Validation error messages rendered in form

---

## Regression Testing

After any changes to Stock Transfer functionality, re-test:

### Core Transfer Flow
1. Create new transfer with 1 item
2. Verify stock decreased at source
3. Verify stock increased at destination
4. Verify stock cards created correctly
5. View transfer detail page
6. Print transfer slip
7. Dispatch the transfer
8. Verify status updates to "completed"

### Related Features
1. Create requisition from destination warehouse
2. Issue stock that came from transfer
3. Verify FIFO still works
4. Verify subsidy lineage preserved
5. Delete a transfer (admin)
6. Verify stock reversal works

---

## Test Data Setup

### Prerequisites
- At least 2 active warehouses
- Items with stock in source warehouse
- Admin user account
- Warehouse Manager user account

### Sample Test Transfer
```
Source Warehouse: Central Warehouse
Destination Warehouse: Branch Warehouse
Transfer Date: Today
Items:
  - Rice (50kg bag) × 10 bags @ ₱2,500.00
  - Canned Goods (can) × 100 cans @ ₱45.00
  - Water (1L bottle) × 50 bottles @ ₱20.00
Total: ₱29,500.00
```

---

## Automated Test Script (Manual)

```
1. Login as Admin
2. Navigate to /transfers
3. Click "New Transfer"
4. Wait for modal to open (1 second)
5. Select "Central Warehouse" as source
6. Select "Branch Warehouse" as destination
7. Set date to today
8. Click "Add Item"
9. Select first item in dropdown
10. Enter quantity: 5
11. Verify total calculates
12. Click "Save Transfer"
13. Wait for redirect (2 seconds)
14. Verify URL is /transfers/{id}
15. Verify success message displayed
16. Navigate back to /transfers
17. Verify new transfer in table
```

---

## Sign-Off Checklist

Before marking as complete:
- [ ] All quick tests pass
- [ ] All detailed tests pass
- [ ] No console errors
- [ ] No visual glitches
- [ ] Responsive on mobile
- [ ] Works for Admin users
- [ ] Works for Warehouse Managers
- [ ] Backend processing correct
- [ ] Stock cards accurate
- [ ] Subsidy lineage preserved
- [ ] Documentation updated
- [ ] Code reviewed
- [ ] Ready for production

---

**Last Updated**: After Stock Transfer Modal Fix  
**Status**: Ready for Testing  
**Priority**: High (User-Facing Feature)
