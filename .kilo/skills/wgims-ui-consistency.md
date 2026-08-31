# wgims-ui-consistency

## WGIMS UI Consistency Standards

This skill defines the UI/UX standards that all agents should follow when working on WGIMS.

## Design System

### Framework
- Bootstrap 5 for layout and components (Paginator::useBootstrap)
- Tailwind CSS available (resources/css/app.css, vite.config.js)
- Vite for asset compilation

### Color Palette
- Use existing Bootstrap colors unless brand guidelines exist
- Consistent alert colors: success (green), danger (red), warning (yellow), info (blue)

### Typography
- Use Bootstrap default typography scale
- Consistent heading sizes across pages
- Monospace for codes (RIS numbers, DR numbers, subsidy codes)

## Components

### Sidebar
- Keep the sidebar navigation structure intact
- Consistent active state highlighting
- Proper collapse behavior on mobile

### Tables
- Bootstrap table classes (table, table-striped, table-hover)
- Responsive wrapper for overflow
- Action buttons aligned consistently
- Status badges with consistent colors
- Pagination at bottom of tables

### Modals
- Consistent modal header/footer structure
- Same close button placement
- Same size for similar content types
- Backdrop click to close where appropriate

### Dropdowns
- Writable/searchable for item/category selections
- Scrollable without closing (handle scroll events)
- Wide enough to display descriptions and codes
- Consistent empty state ("No results found")

### Buttons
- Primary: btn-primary
- Danger/Delete: btn-danger
- Secondary: btn-secondary
- Consistent sizing (btn-sm for tables, default for forms)
- Icon + text for clarity
- Loading state with spinner

### Alerts
- Consistent placement (top of page or inline)
- Auto-dismiss or manual close
- Same animation/transition

### Forms
- Consistent label placement (top of input)
- Required field asterisk
- Validation errors below each field
- Help text where needed
- Submit button at bottom right

## Pages

### List Pages
- Search bar at top
- Filters in a collapsible section or inline
- "Add New" button prominent
- Table with actions column on right
- Pagination at bottom

### Detail Pages
- Header with title and action buttons
- Tabbed layout for related data (items, deliveries, audit log)
- Print button where applicable

### Print Views
- Remove sidebar, navigation, action buttons
- Clean white background
- Proper page margins
- Page break control

## Loading States
- Skeleton loaders for tables
- Spinner for form submissions
- Disabled buttons during async operations
- No skeleton flash on back navigation

## Empty States
- Clear message when no data exists
- Call-to-action button to create first record
- Same design across all list pages

## Mobile Responsiveness
- Tables: horizontal scroll with sticky first column
- Modals: full-screen on small devices
- Sidebar: collapsible drawer
- Forms: single column on mobile

## Constraints
- **DO NOT** introduce new CSS frameworks
- **DO NOT** change business logic while fixing UI
- **DO NOT** remove existing features during UI refactoring
- Maintain backward compatibility with existing print templates
