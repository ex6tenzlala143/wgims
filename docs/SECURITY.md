# WGIMS Security

## Authentication

- Session-based (single web guard)
- Login via `username` field (not email)
- Password hashing via Laravel's Hash facade
- Rate limiting: 10 attempts per minute per username+IP
- Remember token support

## Authorization

### Role-Based Access Control
- `admin` — full access
- `warehouse_manager` — create only (no edit/delete)
- `supply_custodian` — can approve
- `center_staff` — read-only, cannot create
- `center_head` — can approve

### Middleware
- `admin` — admin + warehouse_manager
- `admin.write` — admin only
- `admin.create` — admin + warehouse_manager
- `admin.only.strict` — admin only

### Warehouse Scoping
- Non-admin users scoped to assigned warehouses
- Empty assignment → sees nothing
- Admin sees all warehouses

### Route-Level Authorization
- All mutating routes protected by appropriate middleware
- Controller methods double-check with `abort_unless()`
- Warehouse-specific access checked via `ScopesWarehouse` trait

## Session Security

- `EnsureUserIsActive` middleware deactivates sessions for deactivated accounts
- `NoCache` middleware sets cache control headers
- Logout clears session, regenerates CSRF token, sets `Clear-Site-Data`
- bfcache handled with `pageshow` event + session validation

## Input Validation

- All requests validated via `$request->validate()`
- ValidationException thrown for business rule violations
- Server-side checks prevent bypass (e.g., remaining quantity, warehouse assignment)
- `lockForUpdate()` prevents race conditions on stock records

## SQL Injection Prevention

- Eloquent ORM used throughout (parameterized queries)
- Raw queries only for advisory locks (GET_LOCK/RELEASE_LOCK)
- No user input concatenated into SQL

## XSS Prevention

- `escapeHtml()` used for notification content
- Blade auto-escaping enabled
- No raw `{!! !!}` output for user data

## CSRF Protection

- CSRF token in meta tag
- All POST/PUT/DELETE requests include CSRF token
- AJAX requests include `X-CSRF-TOKEN` header

## Rate Limiting

- Login: 10 attempts per minute per username+IP
- Username check: 30 checks per minute per IP
- API helpers: 120 requests per minute per user

## Data Protection

- No secrets or keys exposed in responses
- Error messages logged server-side, generic messages shown to users
- Audit logs record all corrections and deletions

## Dangerous Operations Guarded

- Stock mutations wrapped in DB transactions
- `lockForUpdate()` on exact stock records before read-modify-write
- Delete operations check for downstream dependencies
- Transfer deletion blocked if destination stock consumed by later transactions
- Subsidy deletion flags related transfers instead of auto-deleting
