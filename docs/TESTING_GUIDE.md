# WGIMS Testing Guide

## Test Framework

- PHPUnit 11.5.50 (configured in composer.json)
- Tests directory: `tests/`
- Run: `php artisan test` or `vendor/bin/phpunit`

## Test Database

- SQLite in-memory or file-based for testing
- Migrations run automatically via `RefreshDatabase` or `migrate:fresh`

## Key Areas to Test

### 1. Authentication
- [ ] Login with valid credentials
- [ ] Login with invalid credentials
- [ ] Login with deactivated account
- [ ] Rate limiting (10 attempts)
- [ ] Logout clears session
- [ ] Remember me functionality

### 2. Authorization
- [ ] Admin sees all warehouses
- [ ] Warehouse manager sees all warehouses (view only)
- [ ] Center staff sees only assigned warehouses
- [ ] Center staff cannot create records
- [ ] Admin-only routes blocked for warehouse managers
- [ ] Write routes blocked for warehouse managers

### 3. Delivery Subsidy
- [ ] Create subsidy with multiple items
- [ ] Record partial delivery
- [ ] Record multiple deliveries to different warehouses
- [ ] Edit subsidy before any deliveries
- [ ] Edit subsidy after deliveries (correction mode)
- [ ] Cannot change RIS# or supplier after deliveries
- [ ] Cannot set requested qty below delivered qty
- [ ] Delete subsidy reverses all stock
- [ ] Stock cards created on delivery
- [ ] Stock card balances recalculated

### 4. Requisition (RIS)
- [ ] Create RIS with catalog items
- [ ] Approve and dispatch from single warehouse
- [ ] Partial fulfillment
- [ ] Full fulfillment
- [ ] Edit RIS before dispatch
- [ ] Edit RIS after dispatch (locked lines)
- [ ] Correct RIS
- [ ] Delete RIS reverses dispatches
- [ ] Stock deducted on dispatch
- [ ] Stock restored on dispatch delete
- [ ] Stock cards created on dispatch
- [ ] Stock cards deleted on dispatch delete

### 5. Stock Transfer
- [ ] Create transfer between warehouses
- [ ] Partial dispatch
- [ ] Full dispatch
- [ ] Edit transfer (increase/decrease quantities)
- [ ] Delete transfer reverses stock
- [ ] Transfer deletion blocked when destination stock consumed
- [ ] Stock cards for transfer_out and transfer_in
- [ ] Cost propagation to destination

### 6. Reservations
- [ ] Create reservation
- [ ] Cannot reserve more than available
- [ ] Approve reservation
- [ ] Mark ready
- [ ] Cancel reservation
- [ ] Fulfillment via requisition dispatch

### 7. Stock Cards
- [ ] Delivery creates stock card entry
- [ ] Issuance creates stock card entry
- [ ] Transfer creates stock card entries
- [ ] Balance recalculation after edits
- [ ] Running balances correct

### 8. Reports
- [ ] RPCI shows correct totals
- [ ] RSMI shows correct per-dispatch costs
- [ ] Inventory balance shows all active items
- [ ] Snapshots save and load correctly
- [ ] Excel exports work

### 9. Edge Cases
- [ ] Concurrent deliveries (advisory lock safety)
- [ ] Concurrent dispatches (lockForUpdate safety)
- [ ] Zero quantity items (is_active auto-management)
- [ ] Deleted subsidy items flagged correctly
- [ ] Multi-hop transfer lineage tracing
- [ ] Item with same name but different cost (separate records)
- [ ] Same item in multiple warehouses (separate records)

## Manual Testing Checklist

### Subsidy Flow
1. Create subsidy (admin)
2. Record delivery (warehouse manager)
3. Verify stock increased in correct warehouse
4. Verify stock card entries created
5. Edit subsidy (no deliveries) — verify full edit
6. Record another delivery
7. Edit subsidy (with deliveries) — verify correction mode
8. Delete subsidy — verify stock reversed

### RIS Flow
1. Create RIS (warehouse manager)
2. Approve and dispatch (custodian)
3. Verify stock decreased
4. Verify stock card entries created
5. Edit RIS — verify locked lines
6. Delete RIS — verify stock restored

### Transfer Flow
1. Create transfer (admin)
2. Dispatch transfer
3. Verify stock moved between warehouses
4. Edit transfer — verify delta applied
5. Delete transfer — verify stock reversed

## Known Testing Limitations

- No automated tests currently in the project (tests/ directory exists but may be empty)
- Manual testing via browser required
- Database transactions make rollback testing important
