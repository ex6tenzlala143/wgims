# Test Plan: Wider Dropdown Panels

## Test Date: August 24, 2026
## Tester: _______________

## Overview
This test plan verifies that inventory item dropdowns now display at 520-700px width to show full details including expiration dates, while standard dropdowns remain at their original size.

---

## Test 1: RIS Approve - Item Selection Dropdown

### Steps:
1. Navigate to Requisitions list
2. Find a requisition with status "Pending" or "Approved"
3. Click "Process Issuance" button
4. In the approve view, select a warehouse from the dropdown
5. Click on an item dropdown for any requested item

### Expected Results:
- ✓ Dropdown panel opens at **520-700px width** (wider than before)
- ✓ Item options show multiline format:
  ```
  Item Description
  Qty: XXX · Unit Cost: ₱XXX.XX · ENGAS: ₱XXX.XX · Exp: MMM DD, YYYY
  ```
- ✓ **Expiration date is fully visible** (e.g., "Sep 05, 2026" not cut off)
- ✓ All details are readable without horizontal scrolling
- ✓ Dropdown does not exceed screen width on normal monitors

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 2: Stock Transfer - Item Selection Dropdown

### Steps:
1. Navigate to Stock Transfers section
2. Click "Create Transfer" button
3. Fill in basic transfer details (source/destination warehouse, date)
4. In the items table, click "Add Item" or click on first item dropdown

### Expected Results:
- ✓ Dropdown panel opens at **520-700px width**
- ✓ Item options show full inventory details in multiline format
- ✓ Expiration dates are completely visible
- ✓ All text is readable and not truncated

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 3: Dispatch Edit - Stock Selection Dropdown

### Steps:
1. Navigate to a requisition that has been approved
2. Go to "Dispatch/Issuance" tab
3. Click "Edit" on an existing dispatch record
4. In the edit modal, click on any item dropdown

### Expected Results:
- ✓ Dropdown panel opens at **520-700px width**
- ✓ Stock options display full details including expiration
- ✓ No text truncation on expiration dates
- ✓ Dropdown width appropriate for content

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 4: Standard Dropdowns (Should NOT Change)

### Steps:
1. Test various standard dropdowns throughout the system:
   - Warehouse selection dropdown (RIS approve, transfers)
   - Status filter dropdowns (on list pages)
   - Date filter dropdowns
   - User role dropdowns
   - Any other non-inventory dropdowns

### Expected Results:
- ✓ These dropdowns remain at **standard width (240-400px)**
- ✓ No unnecessary extra space for simple text options
- ✓ Dropdowns fit naturally with their content

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 5: Responsive Behavior - Narrow Screens

### Steps:
1. Resize browser window to ~800px width (tablet size)
2. Test item dropdowns in RIS approve view
3. Test item dropdowns in stock transfer modal
4. Verify dropdowns don't overflow screen

### Expected Results:
- ✓ Dropdown width adjusts to available viewport
- ✓ Max width constrained by `Math.min(vw - 16, 700)`
- ✓ No horizontal scrollbar appears
- ✓ Content remains readable even on narrower screens
- ✓ Dropdown panels properly positioned within viewport

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 6: Ultra-Wide Screens

### Steps:
1. Test on a wide monitor (1920px+ width) or maximize browser
2. Open item dropdowns in various areas
3. Observe maximum width enforcement

### Expected Results:
- ✓ Dropdown width caps at **700px maximum**
- ✓ Does not stretch unnecessarily wide
- ✓ Maintains readability with appropriate width
- ✓ Properly centered or left-aligned based on available space

### Test Results:
- [ ] PASS
- [ ] FAIL - Notes: _______________________

---

## Test 7: Edge Cases

### Test 7a: Dropdown Near Right Edge
**Steps:** Open item dropdown when positioned near right edge of screen

**Expected:** Dropdown shifts left to stay within viewport, full width maintained

**Result:** [ ] PASS [ ] FAIL

### Test 7b: Dropdown Near Bottom
**Steps:** Open item dropdown near bottom of page

**Expected:** Dropdown opens upward if needed, width remains correct

**Result:** [ ] PASS [ ] FAIL

### Test 7c: Long Item Descriptions
**Steps:** Test with items that have very long descriptions

**Expected:** Primary line truncates with ellipsis, secondary line shows on next line

**Result:** [ ] PASS [ ] FAIL

---

## Visual Comparison

### Before (420-600px):
```
┌─────────────────────────────────────┐
│ Paracetamol 500mg                  │
│ Qty: 500 · Unit: ₱10.00 · ENGAS:│  <- Expiration cut off
└─────────────────────────────────────┘
```

### After (520-700px):
```
┌──────────────────────────────────────────────┐
│ Paracetamol 500mg                           │
│ Qty: 500 · Unit: ₱10.00 · ENGAS: ₱10.00 · Exp: Sep 05, 2026 │  <- Fully visible
└──────────────────────────────────────────────┘
```

---

## Browser Testing

Test in multiple browsers to ensure consistent behavior:

- [ ] Chrome/Edge (Chromium) - Version: _______
- [ ] Firefox - Version: _______
- [ ] Safari (if Mac available) - Version: _______

---

## Summary

**Total Tests:** 11 (6 main + 3 edge cases + comparison)

**Passed:** _______

**Failed:** _______

**Overall Result:** [ ] APPROVED [ ] NEEDS FIX

---

## Notes & Issues

Record any observations, bugs, or improvements:

_____________________________________________________________

_____________________________________________________________

_____________________________________________________________

---

## Sign-off

**Tester Name:** _______________________

**Date:** _______________________

**Approved for Production:** [ ] YES [ ] NO

**Reviewer Name:** _______________________

**Date:** _______________________
