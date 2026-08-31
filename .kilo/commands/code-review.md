# code-review

Run a code review on the WGIMS codebase.

## Usage
```
/code-review [area]
```

## Arguments
- `area` (optional): `controllers`, `models`, `views`, `database`, `all` — defaults to `all`

## Steps
1. Identify the target files based on the area
2. Check for duplication, N+1 queries, missing indexes
3. Check for dead code and unused imports
4. Verify naming consistency
5. Report findings with file paths and line numbers

## Delegation
- Delegate to `wgims-code-reviewer` for deep analysis
- Delegate to `wgims-optimizer` for performance findings
