# Quick Reference: Dropdown Width Settings

## Current Implementation (August 24, 2026)

### Inventory Item Dropdowns
**Width:** 520-700px (auto-adjusts)
- **Minimum:** 520px
- **Maximum:** 700px (or viewport width - 16px, whichever is smaller)

**Applied to:**
- RIS Approve → Item Selection
- Stock Transfer → Item Selection  
- Dispatch Edit → Stock Selection

**Display Format:**
```
Item Name
Qty: 500 · Unit Cost: ₱100.00 · ENGAS: ₱100.00 · Exp: Sep 05, 2026
```

### Standard Dropdowns
**Width:** 240-400px (auto-adjusts)
- **Minimum:** 240px
- **Maximum:** 400px (or viewport width - 16px, whichever is smaller)

**Applied to:**
- Warehouse selection
- Status filters
- Date filters
- Category dropdowns
- All other non-inventory dropdowns

---

## Auto-Detection Logic

The system automatically detects whether to use inventory or standard width by checking if option text contains newline characters (`\n`).

**JavaScript Code:**
```javascript
// Check first 10 options
var hasMultiline = false;
for (var i = 0; i < opts.length && i < 10; i++) {
    if (opts[i].text && opts[i].text.indexOf('\n') !== -1) {
        hasMultiline = true;
        break;
    }
}

// Apply appropriate width
var minWidth = hasMultiline ? 520 : 240;
var maxWidth = Math.min(vw - 16, hasMultiline ? 700 : 400);
```

---

## Comparison

| Feature | Before | After |
|---------|--------|-------|
| Inventory Min Width | 420px | **520px** |
| Inventory Max Width | 600px | **700px** |
| Standard Min Width | 240px | 240px (unchanged) |
| Standard Max Width | 400px | 400px (unchanged) |
| Expiration Visibility | **Truncated** | **Fully Visible** ✓ |

---

## Configuration Location

**File:** `resources/views/layouts/app.blade.php`

**Method:** `SearchableSelect.prototype.position()`

**Lines:** ~1259-1264

To modify widths in the future, edit these values:
```javascript
var minWidth = hasMultiline ? 520 : 240;  // Line ~1259
var maxWidth = Math.min(vw - 16, hasMultiline ? 700 : 400);  // Line ~1264
```

---

## Testing Quick Check

### ✓ Verify Wider Dropdowns:
1. Open RIS Approve view
2. Select warehouse
3. Click item dropdown
4. **Confirm:** Panel is visibly wider (~520-700px)
5. **Confirm:** Expiration date "Sep 05, 2026" is fully visible

### ✓ Verify Standard Dropdowns:
1. Open any page with warehouse dropdown
2. Click warehouse dropdown
3. **Confirm:** Panel maintains compact size (~240-400px)

---

## Responsive Behavior

### Desktop (1920px+)
- Inventory: 700px (max enforced)
- Standard: 400px (max enforced)

### Laptop (1366px-1920px)
- Inventory: 520-700px (responsive)
- Standard: 240-400px (responsive)

### Tablet (768px-1366px)
- Inventory: viewport - 16px (capped at 700px)
- Standard: viewport - 16px (capped at 400px)

### Mobile (<768px)
- Inventory: viewport - 16px
- Standard: viewport - 16px
- **Ensures no horizontal overflow**

---

## Troubleshooting

### Issue: Dropdown still appears narrow
**Solution:** Clear browser cache and hard reload (Ctrl+Shift+R)

### Issue: Dropdown overflows viewport on mobile
**Check:** Viewport constraint logic `Math.min(vw - 16, maxWidth)` is applied

### Issue: Expiration dates still truncated
**Possible Causes:**
1. Browser zoom level > 100%
2. Very long item names pushing details to edge
3. Font size override in browser settings

**Solution:** Test at 100% zoom with default font settings

---

## Version History

| Date | Version | Width Values | Notes |
|------|---------|-------------|-------|
| Aug 24, 2026 | 2.0 | 520-700px | Increased for expiration visibility |
| Aug 23, 2026 | 1.0 | 420-600px | Initial multiline implementation |

---

## Related Files

- `DROPDOWN_WIDTH_UPDATE.md` - Detailed implementation notes
- `ITEM_DROPDOWN_ENHANCEMENT.md` - Multiline format documentation
- `SUMMARY_ALL_DROPDOWN_CHANGES.md` - Complete project summary
- `TEST_WIDER_DROPDOWNS.md` - Full test plan
