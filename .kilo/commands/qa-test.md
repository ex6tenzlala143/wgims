# qa-test

Run a QA test session on the WGIMS codebase.

## Usage
```
/qa-test [module]
```

## Arguments
- `module` (optional): `subsidies`, `ris`, `transfers`, `inventory`, `all` — defaults to `all`

## Steps
1. Load test scenarios from wgims-qa-workflow skill
2. Trace the relevant code paths
3. Identify edge cases specific to the module
4. Report findings in standard QA format

## Delegation
- Delegate to `wgims-qa-tester` for test execution
- Delegate to `wgims-bug-hunter` if bugs are found
