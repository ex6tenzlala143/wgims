# security-check

Run a quick security audit on the WGIMS codebase.

## Usage
```
/security-check [area]
```

## Arguments
- `area` (optional): `auth`, `routes`, `controllers`, `all` — defaults to `all`

## Steps
1. Check middleware application on all routes
2. Verify session invalidation on logout
3. Check for IDOR vulnerabilities in controllers
4. Verify warehouse scoping enforcement
5. Check CSRF protection on forms
6. Verify no sensitive data exposure
7. Report findings with file paths and line numbers

## Delegation
- Delegate to `wgims-security` for deep analysis
