# WGIMS UI Conventions

## Layout

### Sidebar
- Fixed width: 190px
- Brand/logo at top
- Navigation sections: Core System, Inventory, Procurement, Reports, Administration
- Footer with user info and logout button
- Collapsible on mobile (< 1024px) with hamburger toggle
- Overlay when open on mobile

### Topbar
- Height: 40px
- Sticky at top
- Contains: page title, warehouse label, notification bell
- Warehouse label shows all assigned warehouses

### Page Content
- Padding: 12px
- Overflow-x: auto for wide tables
- Background: #f0f4f8

## Components

### Cards
- `.card` — white background, 1px border, 8px radius
- `.card-header` — padding 10px 14px, border-bottom
- `.card-body` — padding 12px
- `.card-footer` — padding 8px 14px, background #f7fafc

### Stats Cards
- `.stats-grid` — auto-fit grid, minmax(140px, 1fr)
- `.stat-card` — icon + value + label
- Icon colors: blue, green, yellow, red

### Buttons
- `.btn` — base button
- `.btn-sm` — smaller variant
- Colors: primary, success, warning, danger, secondary, outline
- Icon support: `.btn-icon`

### Tables
- Font size: 11px
- Compact padding: 6px 10px
- Hover rows
- Sticky headers in modals
- Header: uppercase, 10px, letter-spacing 0.5px

### Badges
- `.badge` — inline-flex, rounded-full
- Sizes: success, warning, danger, info, secondary, primary
- Font: 9px, uppercase, letter-spacing 0.5px

### Forms
- `.form-control` — width 100%, padding 5px 7px, border 1px solid #e2e8f0
- `.form-label` — block, 11px, font-weight 600, margin-bottom 4px
- `.form-row` — grid layout
- `.form-row.cols-2/3/4` — 2/3/4 column grids
- `.form-section-label` — 12px, font-weight 700, color #0284c7, dashed border-bottom

### Alerts
- `.alert` — padding 8px 12px, border-radius 6px
- Types: success, danger, warning, info
- Icon prefix

### Pagination
- Custom styled (not Bootstrap)
- Centered, wrapped
- 28px min-width/height
- Active: primary color background

## Modals

### Structure
- `.modal-overlay` — fixed, full-screen, rgba(15,23,42,0.55), backdrop-filter blur
- `.modal-shell` — 95% width, max-width 1560px, height calc(100vh - 32px)
- `.modal-header` — padding 12px 16px, gradient background
- `.modal-body` — flex: 1, overflow-y: auto, padding 12px 14px
- `.modal-footer` — padding 8px 14px, flex-end

### Line Items in Modals
- Sticky headers (`position: sticky; top: 0`)
- `.batch-row` — individual batch within shipment
- `.shipment-item-card` — card per item in edit delivery
- `.ris-item-card` — card per line in RIS

## Searchable Selects

### Component
- Custom `SearchableSelect` JavaScript class
- Enhances every `<select>` into searchable dropdown
- Native select stays in DOM (hidden) for form submission
- Panel portal to `<body>`
- Keyboard navigation (arrow keys, enter, escape)
- Mouse click selection
- Search input filters options
- Multiline support (`\n` in option text)

### CSS Classes
- `.ss` — wrapper
- `.ss-native` — hidden native select
- `.ss-btn` — custom button
- `.ss-value` — selected value display
- `.ss-caret` — dropdown arrow
- `.ss-panel` — dropdown panel
- `.ss-search` — search input
- `.ss-list` — options list
- `.ss-item` — individual option

## Notifications

### Bell Icon
- Top-right of topbar
- Badge shows unread count (red background)
- Dropdown on click

### Dropdown
- Width: 330px
- Max-height: 300px for list
- Header with "Mark all read"
- Footer with "View all notifications"
- Polling every 30 seconds
- AJAX-loaded

## Print

### Styles
- `@media print`:
  - Hide sidebar, topbar, no-print elements
  - Remove left margin from main wrapper
  - White background

### Print Pages
- RPCI print
- RSMI print
- Stock card print
- Transfer slip print
- RIS print

## Responsive Breakpoints

- 1024px: Sidebar collapses, hamburger appears
- 820px: Form rows go single column
- 640px: Further compact spacing

## Color Tokens (CSS Variables)

```css
--primary: #0284c7
--primary-dark: #0369a1
--primary-soft: #f0f9ff
--bg: #f0f4f8
--surface: #ffffff
--surface-hover: #f3f4f6
--surface-soft: #f7fafc
--border: #e2e8f0
--border-strong: #cbd5e0
--text: #1a202c
--text-muted: #718096
--success: #38a169
--warning: #d69e2e
--danger: #e53e3e
--info: #0284c7
```

## JavaScript Conventions

- Vanilla JS, no framework
- `SearchableSelect` class for all `<select>` elements
- `guardFormSubmit()` to prevent double-submission
- `escapeHtml()` for safe HTML insertion
- Notification polling with `setInterval`
- bfcache handling with `pageshow` event
- CSRF token from meta tag
