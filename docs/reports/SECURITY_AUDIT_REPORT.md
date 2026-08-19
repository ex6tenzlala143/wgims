# WGIMS Security Audit — Authentication & Logout System

**Date:** 2026-08-19
**Scope:** Authentication, session management, logout, role-based authorization, direct-URL access, admin security
**Result:** 3 fixes applied, 20 automated security tests added (432 assertions, all passing), live-server scenario verified.

---

## 1. How access control works in this system

- Every application route except `/login` (GET/POST) and `/logout` (POST) lives inside `Route::middleware('auth')` in `routes/web.php`.
- Four role middleware aliases are registered in `bootstrap/app.php`:
  | Alias | Allows | Blocks |
  |---|---|---|
  | `admin` | Admin + Warehouse Manager (view) | center roles → 403 |
  | `admin.write` | Admin only (mutations) | Warehouse Manager → 403 |
  | `admin.create` | Admin + Warehouse Manager (create only) | center roles → 403 |
  | `admin.only.strict` | Admin only | Warehouse Manager → 403 |
- Every role middleware redirects an **unauthenticated** user to the Login page (`redirect()->route('login')`) and returns **403** to an authenticated user with the wrong role.
- Controllers add a second layer of checks (`abort_unless(canWrite(), 403)`, `abort(403)`, warehouse-scope checks) — authorization is enforced server-side, never by hiding UI.
- No CSRF exclusions exist (`withoutMiddleware` / CSRF `except` lists: none). All state-changing routes are POST/PUT/PATCH/DELETE under the CSRF-protected web group.
- There is no separate API route file; the JSON helper endpoints (`/api/*`) are web routes under `auth` and therefore session + CSRF protected.

## 2. Protected routes found (all under `auth`)

- Dashboard `/`
- Items `/items` (+ create/edit/delete)
- Item Categories `/item-categories` (+ catalog items) — `admin.only.strict`
- Delivery / Subsidies `/delivery-subsidies` (+ create/edit/delete/archive/restore/delivery/audit-log)
- Requisitions / RIS / Augmentations `/requisitions` (+ create/approve/signatories/print/edit/correct/dispatch/audit-log)
- Stock Cards `/stock-cards`, `/stock-cards/summary`, item history, by-unit-cost, print
- Stock Transfers `/transfers` (+ print/dispatch/edit/delete)
- Suppliers `/suppliers` (+ create/edit/toggle)
- Warehouses `/warehouses` (+ create/edit)
- Reports `/reports/rpci`, `/reports/rsmi`, `/reports/inventory-balance` (+ print/export/snapshot)
- Users `/users` (+ create/edit) — `admin.only.strict` / `['admin','admin.write']`
- Notifications `/notifications` (+ read/read-all/read-ajax)
- JSON helpers: `/api/requisition-items`, `/api/requisition-description-items`, `/api/transfer-items`, `/api/check-username`, `/api/check-dr`, `/api/item-stock-card`, `/api/notifications/unread`
- Health probe `/up` is intentionally public (no data exposed).

## 3. Routes missing authentication protection

**None.** Every application route is behind `auth`. `GET /login`, `POST /login`, and `POST /logout` are the only non-`auth` routes, by design. `/logout` is POST-only (a GET logout would allow CSRF-forced logouts).

## 4. Routes with incorrect role protection

**None found.** The `admin` / `admin.write` / `admin.create` / `admin.only.strict` placement was verified route-by-route:
- Warehouse Manager can view (`admin`) and create (`admin.create`) but cannot edit/delete/write (`admin.write` blocks) — verified by test.
- Warehouse Manager cannot reach Users management (`admin.only.strict` blocks) — verified by test.
- Center roles (staff/head/custodian) are blocked from all admin write routes and item-category/user pages — verified by test.
- Delete of requisitions/warehouses/users is additionally locked behind `['admin', 'admin.write']`.

Controllers also enforce warehouse-level scope for center users (e.g., `StockTransferController::userCanAccessWarehouse`, `RequisitionController` checks), so a center user cannot view records from warehouses they are not assigned to.

## 5. Issues found & changes made

### 5.1 Deactivated accounts kept working (HIGH) — FIXED
**Problem:** `Auth::attempt()` checks `is_active` only at login time. A user deactivated by the Admin:
1. Kept their existing session (could continue using the system for up to the session lifetime), and
2. Could be silently re-authenticated later by their "Remember me" cookie — Laravel's remember-token lookup does not check `is_active`.

**Fix:**
- New middleware `app/Http/Middleware/EnsureUserIsActive.php` — on every web request, if the authenticated user is no longer active, the server logs them out, invalidates the session, regenerates the CSRF token, and redirects to login **before any controller runs**.
- `UserController::update()` now purges the user's `remember_token` when `is_active` is set to false, so the remembered cookie can never authenticate them again.

### 5.2 Browser Back button / cached pages after logout (MEDIUM) — FIXED
**Problem:** Responses carried no `Cache-Control` headers. After logout, pressing Back (or opening a bookmark) could show a stale protected page from the browser cache/bfcache, even though the server session was gone.

**Fix:**
- New middleware `app/Http/Middleware/NoCache.php` — every web response now gets `Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0`, `Pragma: no-cache`, and `Expires: 1970`. A logged-out user who presses Back is forced to re-request the page, and the server redirects them to Login. Also adds `X-Frame-Options: SAMEORIGIN` (clickjacking hardening).

### 5.3 Middleware registration
Both middleware were appended to the web group in `bootstrap/app.php` (`$middleware->web(append: [...])`) — they run after the session middleware, so they cover **every** route in the system, including all current and future controllers.

## 6. Logout / session security — verified

`AuthController::logout()` performs the full termination sequence:
```php
Auth::logout();                       // clears guard + cycles the remember token
$request->session()->invalidate();    // destroys session data + new session id
$request->session()->regenerateToken(); // fresh CSRF token
```
Verified by test: after logout the user is guest, the old session id is dead, and every subsequent request to a protected URL (including the previously visited one) is redirected to Login.

Login is also fixation-safe: `Auth::attempt()` is followed by `session()->regenerate()` (verified by test — session id changes on login). Other protections already in place: login rate limiting (10/min per username+IP), `http_only` + `SameSite=Lax` session cookie, bcrypt hashing, `is_active` checked at login.

## 7. Direct-URL tests performed (automated, `tests/Feature/AuthSecurityAuditTest.php`)

Ran against the real MySQL schema (`DatabaseTransactions`, 20 tests / 432 assertions — all passing):

- **Every protected GET route (45 URLs)** as a guest → `302 → /login`, no content.
- **All JSON endpoints** as guest → `401`.
- **The reported scenario:** login → visit `/requisitions` (200 + content) → logout → paste the copied URL → `302 → /login`, content absent.
- Same pasted-URL test repeated for: Dashboard, Items, Delivery/Subsidies, Requisitions, Stock Cards, Transfers, Suppliers, Inventory Balance, RPCI, RSMI, Warehouses, Users, Item Categories, Notifications.
- Refresh (F5) after logout → redirected to Login.
- Bookmarked deep links (`/requisitions/approve`, `/items/1/edit`, `/transfers/1/edit`, `/delivery-subsidies/1/edit`) after logout → redirected to Login.
- Back button scenario: protected pages are served `no-store` while logged in, and the post-logout request is redirected to Login.
- **Write actions (35 POST/PUT/PATCH/DELETE endpoints)** as a guest — create Subsidy, create RIS, approve, dispatch items, transfer stock, edit/delete records, modify inventory, modify users, archive/restore, toggle, snapshots, notification reads — all rejected (`302 → /login`), including JSON writes (`401`).
- Logout terminates the session; the old session id cannot be reused; login regenerates the session id.

## 8. Admin security tests performed (automated)

- **Admin → admin pages** (`/users`, `/users/{id}/edit`, `/item-categories`, plus all core pages) → allowed (200).
- **Warehouse Manager → admin pages and admin writes** (users list/create/edit, item categories, delete RIS, edit/delete items, delete subsidies, delete transfers, edit warehouses, edit users, edit transfers) → `403` — while WM's legitimate access (dashboard, requisitions, items, subsidies, transfers, warehouses, suppliers) still returns 200.
- **Center roles (staff / head / custodian) → admin pages and admin writes** → `403` (delete RIS → 403), while their legitimate pages (requisitions, subsidies, transfers, dashboard) return 200.
- **Deactivated user:** cannot log in; an existing session is terminated on the very next request (redirected to Login, stays terminated); deactivation purges the "Remember me" token.

## 9. Live-server verification (real browser-style HTTP session)

Using curl with a real cookie jar against the live app (`http://10.182.119.105/wgims/public`):
- Login → 302 → Dashboard. Requisitions page → 200 with protected content.
- Logout (POST + CSRF token) → 302.
- **Paste copied `/requisitions` URL → `302 Found → Location: /login`** ✓
- **Paste `/users` (admin) URL → `302 Found → Location: /login`** ✓
- AJAX call after logout → redirected (no data).
- Login page + all pages now serve `Cache-Control: no-store, no-cache, must-revalidate, private` and `Pragma: no-cache`.
- Temporary audit user created for the test was removed afterwards.

## 10. Remaining security concerns (not fixed, recommendations)

1. **`APP_DEBUG=true`** in `.env` (served at `http://10.182.119.105/wgims/public`). If this installation is reachable by anyone other than the operators, set `APP_DEBUG=false` — error pages can otherwise leak stack traces, paths, and environment details to visitors. **Recommended to change before broader rollout.**
2. **No HTTPS.** `SESSION_SECURE_COOKIE` is unset (session cookie can travel over plain HTTP), and the login form is served over HTTP on the intranet. Deploying behind HTTPS and setting `SESSION_SECURE_COOKIE=true` would encrypt credentials and cookies in transit.
3. **Minor existence oracle:** Laravel resolves route model binding before route-level role middleware, so probing a non-existent record id returns 404 while an existing one returns 403 for a blocked role. No data is ever exposed (both cases deny access); controllers already re-check permissions.
4. `X-Frame-Options: SAMEORIGIN` added; a full Content-Security-Policy header would be a further hardening step.
5. Session lifetime is 120 minutes (`SESSION_LIFETIME`) with no absolute timeout; sessions end on logout or expiry. Consider `SESSION_EXPIRE_ON_CLOSE=true` or a shorter lifetime for shared computers.
6. The pre-existing sqlite `dropForeign` migration issue remains (test-infrastructure only — it prevents the sqlite in-memory suite from migrating; the MySQL path and live app are unaffected).

## 11. Files changed

| File | Change |
|---|---|
| `app/Http/Middleware/EnsureUserIsActive.php` | NEW — terminates sessions of deactivated accounts on every request |
| `app/Http/Middleware/NoCache.php` | NEW — no-store/no-cache headers + X-Frame-Options on all web responses |
| `bootstrap/app.php` | Register both middleware in the web group |
| `app/Http/Controllers/UserController.php` | Purge `remember_token` when an account is deactivated |
| `tests/Feature/AuthSecurityAuditTest.php` | NEW — 20 security tests (432 assertions), all passing |