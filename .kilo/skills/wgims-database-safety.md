# wgims-database-safety

## WGIMS Database Safety Rules

This skill contains the rules for migrations, database changes, transactions, backups, and data preservation in WGIMS.

## Production Data Protection

### NEVER Do These
- **NEVER** run `php artisan migrate:fresh` — this destroys all data
- **NEVER** run `php artisan db:wipe` — this destroys all data
- **NEVER** truncate tables
- **NEVER** drop tables
- **NEVER** delete production data casually
- **NEVER** delete users/employees
- **NEVER** modify existing data to make tests pass
- **NEVER** create unnecessary migrations
- **NEVER** change existing migrations that have been used in production without careful evaluation

## Before Any Database Change

### Required Pre-Change Analysis
1. **What will change?** — Describe the schema or data modification
2. **Why is it required?** — Business or technical justification
3. **Is existing data affected?** — Identify which rows/tables are impacted
4. **Is a migration necessary?** — Could this be done with a seed or script instead?
5. **How can the change be reversed?** — Rollback plan

### Migration Rules
- New migrations must be additive (add columns, add tables)
- Do NOT modify columns that contain production data without data migration plan
- Do NOT drop columns or tables that contain production data
- If data migration is needed, create a separate migration with up/down methods
- Always test rollback: `php artisan migrate:rollback --step=1`

## Data Modification Rules

### Transaction Requirements
Use database transactions for ALL multi-step inventory operations:
```php
DB::transaction(function () {
    // Step 1: Update stock
    // Step 2: Create stock card entry
    // Step 3: Update related records
});
```

### Verification Before Modification
Before modifying any inventory data:
1. Check for dependent records (dispatches, stock cards, transfers)
2. Verify the change won't create negative inventory
3. Verify the change won't break transaction chains
4. Document what will be reversed if the operation fails

### Safe Operations
- Reading data: Always safe
- Creating new records: Generally safe
- Updating non-critical fields (name, description): Safe
- Updating inventory quantities: Requires transaction + verification
- Deleting records: Requires dependency check

## Backup Requirements
- Before any data migration, export affected tables
- Before any mass update, create a rollback script
- Keep backup files with timestamps

## Stock Record Integrity
When modifying stock-related data:
1. Never merge records with different identity fields
2. Never change source_subsidy_id on existing records
3. Never change warehouse_id on records with existing stock cards
4. Never delete stock records without reversing dependent transactions

## Common Safe Patterns

### Adding a Column
```php
Schema::table('table_name', function (Blueprint $table) {
    $table->string('new_column')->nullable()->after('existing_column');
});
```

### Adding a Non-Nullable Column (with default)
```php
Schema::table('table_name', function (Blueprint $table) {
    $table->string('new_column')->default('')->after('existing_column');
});
```

### Data Migration
```php
DB::transaction(function () {
    // 1. Back up affected data
    // 2. Perform transformation
    // 3. Verify results
    // 4. Log changes
});
```
