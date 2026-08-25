# Dropdown Width Enhancement - UPDATED

## Overview
Increased the width of SearchableSelect dropdown panels and simplified the display format to ensure all inventory details are fully visible without truncation.

## Changes Made - Version 2.1 (August 24, 2026)

### File: `resources/views/layouts/app.blade.php`

#### Updated Width Parameters
**Version 1.0 (Initial):**
- Multiline dropdowns: minWidth=420px, maxWidth=600px
- Standard dropdowns: minWidth=240px, maxWidth=400px

**Version 2.0:**
- Multiline dropdowns: minWidth=520px, maxWidth=700px
- Standard dropdowns: minWidth=240px, maxWidth=400px

**Version 2.1 (Current):**
- Multiline dropdowns: **minWidth=600px, maxWidth=850px** ← Increased again
- Standard dropdowns: minWidth=240px, maxWidth=400px (unchanged)

### Files: Backend Controllers

#### Updated Display Format
**File 1:** `app/Http/Controllers/RequisitionController.php` (getItemsByWarehouse method)
**File 2:** `app/Http/Controllers/StockTransferController.php` (itemsForWarehouse method)

**Previous Format:**
```
Item Name
Qty: 300 · Unit Cost: ₱95.00 · ENGAS: ₱95.00 · Exp: Dec 15, 2026
```

**New Format (Simplified):**
```
Item Name
Qty: 300 · ₱95.00 · ENGAS ₱95.00 · Exp: Dec 15, 2026
```

**Changes:**
- Removed "Unit Cost:" label → now just "₱95.00"
- Kept "ENGAS" label → "ENGAS ₱95.00"
- Removed "ENGAS:" colon → "ENGAS ₱95.00"

**Reasoning:**
- More compact display saves horizontal space
- The ₱ symbol clearly indicates it's a cost
- "ENGAS" label retained for clarity since it's a special cost type

#### Implementation Details
The `SearchableSelect.prototype.position()` method automatically detects multiline content by checking if any option text contains a newline character (`\n`). When multiline content is detected (inventory items with qty, costs, expiration), it applies the wider panel dimensions.

```javascript
// Auto-detection logic
var hasMultiline = false;
var opts = this.select.options;
for (var i = 0; i < opts.length && i < 10; i++) {
    if (opts[i].text && opts[i].text.indexOf('\n') !== -1) {
        hasMultiline = true;
        break;
    }
}

// Width assignment
var minWidth = hasMultiline ? 520 : 240;
var maxWidth = Math.min(vw - 16, hasMultiline ? 700 : 400);
```

## Affected Areas

### Inventory Item Dropdowns
The following dropdowns will now display at 520-700px width:

1. **RIS Approve View** - Item selection dropdown
   - Path: `/requisitions/{id}/approve`
   - Shows: Item Description, Qty, Unit Cost, ENGAS Cost, Expiration Date

2. **Stock Transfer Modal** - Item selection dropdown
   - Path: `/transfers` (Create Transfer modal)
   - Shows: Item Description, Qty, Unit Cost, ENGAS Cost, Expiration Date

3. **Dispatch Edit Modal** - Stock selection dropdown
   - Path: `/requisitions/{id}/dispatch/edit`
   - Shows: Item Description, Qty, Unit Cost, ENGAS Cost, Expiration Date

### Standard Dropdowns
All other dropdowns (warehouse selection, status filters, etc.) remain at their standard width (240-400px).

## Benefits

1. **Better Readability**: Expiration dates like "Sep 05, 2026" are now fully visible
2. **Complete Information**: All inventory details visible without horizontal scrolling
3. **Auto-Responsive**: Width automatically adjusts based on content type
4. **Viewport-Aware**: Max width respects available screen space (vw - 16px)

## Display Format Example

**Current Format (Version 2.1):**
```
Family Food Packs
Qty: 300 · ₱95.00 · ENGAS ₱95.00 · Exp: Dec 15, 2026
```

With the new 600-850px width range and simplified format, all details including the full expiration date are clearly visible in the dropdown options.

## Testing Checklist

- [ ] Test RIS item selection dropdown shows full details at wider width
- [ ] Test stock transfer item dropdown shows full details at wider width  
- [ ] Test dispatch edit stock dropdown shows full details at wider width
- [ ] Verify standard dropdowns (warehouse, filters) remain at normal width
- [ ] Test on narrow screens to verify viewport constraint logic
- [ ] Test on ultra-wide screens to verify max width cap

## Technical Notes

- Width detection runs on every dropdown open
- Only checks first 10 options for performance
- Viewport-constrained: `maxWidth = Math.min(vw - 16, 700)` ensures no overflow
- Positioning logic handles edge cases (near viewport boundaries)
- Compatible with existing SearchableSelect implementation

## Related Documentation

- See `ITEM_DROPDOWN_ENHANCEMENT.md` for details on multiline dropdown format
- See `FIX_PREMATURE_STOCK_DATA.md` for RIS stock data architecture changes
