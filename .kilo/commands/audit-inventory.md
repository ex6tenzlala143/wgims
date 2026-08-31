# audit-inventory

Run a quick inventory integrity audit on the WGIMS codebase.

## Usage
```
/audit-inventory [scope]
```

## Arguments
- `scope` (optional): `all`, `subsidies`, `ris`, `transfers`, `stock-cards` — defaults to `all`

## Steps
1. Read the relevant controller methods for the scope
2. Check that stock quantities are correctly updated
3. Verify stock card entries are created for all movements
4. Check that stock records are not incorrectly merged
5. Verify negative inventory is prevented
6. Report findings with file paths and line numbers

## Delegation
- Delegate to `wgims-inventory-integrity` for deep analysis
- Delegate to `wgims-bug-hunter` if bugs are found
