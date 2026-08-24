# Test Dropdown Width - Debugging Guide

## Issue: Dropdown panel still appears narrow

### Steps to Force Browser to Use New Code

1. **Clear Laravel Caches** (Already done):
   ```bash
   php artisan view:clear
   php artisan cache:clear
   ```

2. **Hard Refresh Your Browser**:
   - **Chrome/Edge**: Press `Ctrl + Shift + R` (Windows) or `Cmd + Shift + R` (Mac)
   - **Firefox**: Press `Ctrl + F5` (Windows) or `Cmd + Shift + R` (Mac)
   - Or: Open DevTools (F12) → Right-click refresh button → "Empty Cache and Hard Reload"

3. **Clear Browser Cache Completely**:
   - Chrome/Edge: Settings → Privacy → Clear browsing data → Cached images and files
   - Firefox: Options → Privacy → Clear Data → Cached Web Content

### How to Verify the Code is Running

Open the page and:

1. **Open Browser DevTools** (Press F12)
2. Go to **Console** tab
3. **Paste this code** and press Enter:

```javascript
// Check if SearchableSelect exists
console.log('SearchableSelect exists:', typeof SearchableSelect !== 'undefined');

// Check the position function
if (typeof SearchableSelect !== 'undefined' && SearchableSelect.prototype.position) {
    console.log('position function:', SearchableSelect.prototype.position.toString().substring(0, 500));
}
```

This will show you if the JavaScript is loaded and what the position function looks like.

### Check Panel Width in Real-Time

1. Open RIS Approve page
2. Select a warehouse
3. **Before clicking** Stock Record dropdown, open DevTools (F12)
4. Go to **Elements** tab
5. Click the Stock Record dropdown
6. In Elements tab, find `<div class="ss-panel">` (it appears at bottom of `<body>`)
7. Look at the **Styles** panel on the right
8. Check `width:` value - it should say `850px` or close to it

### Manual Fix (If Cache Won't Clear)

If hard refresh doesn't work, you can manually verify the file content:

1. Open: `resources/views/layouts/app.blade.php`
2. Find line ~1260
3. Verify it contains:
   ```javascript
   var w = maxWidth;  // For multiline, use full width
   ```

### Alternative: Add Inline Style Override

If nothing works, add this to `resources/views/requisitions/approve.blade.php` at the bottom before `@endpush`:

```html
<style>
/* Force wide panels for testing */
.ss-panel {
    min-width: 850px !important;
}
</style>
```

This will force ALL dropdowns to be 850px wide (not ideal, but will confirm if it's a caching issue).

### Check Network Tab

1. Open DevTools (F12)
2. Go to **Network** tab
3. Reload the page
4. Find `approve` in the list (the HTML file)
5. Click it → **Response** tab
6. Search for "maxWidth" in the response
7. Verify the JavaScript code matches what we changed

### Current Code (Should Be):

Around line 1260 in `layouts/app.blade.php`:

```javascript
var minWidth = hasMultiline ? 600 : 240;
var maxWidth = Math.min(vw - 16, hasMultiline ? 850 : 400);
var w = maxWidth;  // For multiline, use full width; adjusted below for standard
// For standard dropdowns (no multiline), match button width within range
if (!hasMultiline) {
    w = Math.max(r.width, minWidth);
    w = Math.min(w, maxWidth);
}
```

### If Still Not Working

The issue might be that `hasMultiline` is evaluating to `false`. Let's verify:

Add this temporary debugging code around line 1258:

```javascript
if (opts[i].text && opts[i].text.indexOf('\n') !== -1) {
    hasMultiline = true;
    console.log('Multiline detected!', opts[i].text.substring(0, 100));  // DEBUG
    break;
}
```

Then reload and check the console when you click the dropdown.

### Expected Console Output:

When you click Stock Record dropdown, you should see:
```
Multiline detected! Family Food Packs
Qty: 300 · ₱95.00 · ENGAS ₱95.00 · Exp: Dec 15, 2026
```

If you DON'T see this, then the display_text format isn't being applied by the backend.

### Check Backend API

Test the API directly:

1. In browser, open DevTools → Network tab
2. Select a warehouse in RIS approve
3. Find the XHR request to `/api/requisition-items?warehouse_id=X`
4. Click it → **Preview** tab
5. Expand an item
6. Check `display_text` field - it should contain `\n` characters

**Example:**
```json
{
  "id": 123,
  "description": "Family Food Packs",
  "display_text": "Family Food Packs\nQty: 300 · ₱95.00 · ENGAS ₱95.00 · Exp: Dec 15, 2026",
  ...
}
```

If `display_text` doesn't have `\n`, the backend changes didn't apply!

### Checklist:

- [ ] Cleared Laravel caches (`php artisan view:clear`, `php artisan cache:clear`)
- [ ] Hard refreshed browser (`Ctrl + Shift + R`)
- [ ] Verified JavaScript code in DevTools → Sources tab
- [ ] Checked panel width in DevTools → Elements tab
- [ ] Verified `display_text` has `\n` in API response
- [ ] Checked console for "Multiline detected!" message
- [ ] Tried different browser (to rule out cache)

### Still Not Working?

Reply with:
1. Screenshot of DevTools → Elements showing `<div class="ss-panel">` with its width
2. Screenshot of DevTools → Console after running the verification script
3. Screenshot of DevTools → Network showing the API response with `display_text`

This will help identify where the issue is!
