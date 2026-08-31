# wgims-security-audit

## WGIMS Security Audit Checklist

This skill contains the security checklist for the WGIMS application.

## Authentication
- [ ] Login uses username (not email) as primary identifier
- [ ] Passwords are hashed (bcrypt)
- [ ] Rate limiting: 10 attempts per minute per username+IP
- [ ] Session regenerated on login
- [ ] Logout invalidates session, clears cache, clears bfcache
- [ ] EnsureUserIsActive middleware blocks deactivated users
- [ ] No password reset functionality (table was dropped — this is intentional)

## Authorization
- [ ] All routes have appropriate middleware
- [ ] admin.only.strict used for truly admin-only operations
- [ ] admin.write used for write operations
- [ ] admin.create used for creation operations
- [ ] Warehouse scoping enforced via ScopesWarehouse trait
- [ ] Non-admin users with no warehouse assignments see nothing

## Session Security
- [ ] After logout, direct URL access returns to login
- [ ] No cached pages accessible after logout (NoCache middleware)
- [ ] Session data is server-side only
- [ ] remember_token cleared when user is deactivated

## Input Validation
- [ ] All form inputs validated in controllers
- [ ] No mass assignment vulnerabilities (fillable arrays defined)
- [ ] No raw SQL with user input
- [ ] File uploads (if any) validated for type and size

## CSRF Protection
- [ ] All POST/PUT/DELETE forms have @csrf
- [ ] API helper routes have throttle middleware
- [ ] State-changing operations require POST/PUT/DELETE

## Data Exposure
- [ ] Passwords never returned in API responses
- [ ] Sensitive fields hidden in User model
- [ ] Error messages don't expose sensitive system information
- [ ] Stack traces not shown to users (APP_DEBUG=false in production)

## IDOR Prevention
- [ ] Show/edit routes verify user can access the resource
- [ ] Warehouse scoping prevents cross-warehouse data access
- [ ] Deleting records checks for dependent data

## Configuration
- [ ] .env not in repository
- [ ] APP_KEY set and secure
- [ ] Database credentials not hardcoded
- [ ] Debug mode disabled in production

## Common Vulnerability Patterns in WGIMS
1. Controller show methods using route model binding without warehouse check
2. API endpoints returning data without auth check
3. Warehouse scoping bypass in custom queries
4. Audit log exposure to unauthorized users
