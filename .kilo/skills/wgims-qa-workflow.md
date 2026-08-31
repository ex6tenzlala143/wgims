# wgims-qa-workflow

## WGIMS QA Testing Workflow

This skill defines the systematic workflow for testing WGIMS.

## Pre-Test Checklist
1. Understand the feature being tested
2. Identify the relevant controllers, models, and routes
3. Check existing data in the database
4. Identify edge cases specific to WGIMS

## Test Planning

### Module Test Matrix
For each module, test:

| Module | CRUD | Search | Filter | Pagination | Validation | Auth |
|--------|------|--------|--------|------------|------------|------|
| Users | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Items | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Categories | ✓ | ✓ | — | ✓ | ✓ | ✓ |
| Warehouses | ✓ | ✓ | — | ✓ | ✓ | ✓ |
| Suppliers | ✓ | ✓ | — | ✓ | ✓ | ✓ |
| Subsidies | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Deliveries | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Requisitions | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Stock Transfers | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Stock Cards | Read | ✓ | ✓ | — | — | ✓ |
| Reports | Read | ✓ | ✓ | — | ✓ | ✓ |

### Edge Case Scenarios

#### Subsidy/Delivery
1. Create subsidy with 100 units, deliver 50 (partial), deliver 50 (full) → status should be fully_delivered
2. Create subsidy, deliver 60, delete delivery → qty_delivered should return to 0
3. Edit subsidy after delivery → only header and requested qty editable
4. Create subsidy with no items → should fail validation
5. Deliver more than requested quantity → should be prevented

#### RIS/Requisition
1. Create RIS with 100 units, approve 50 (partial), approve 50 (full) → status should be partially_approved then fully approved
2. Approve more than requested → should be prevented
3. Edit RIS after dispatch → requested qty cannot go below issued qty
4. Delete RIS with dispatches → should reverse inventory
5. Correct RIS → should only change header and requested qty, not dispatches

#### Stock Transfers
1. Create transfer of 50 units, dispatch 30 (partial), dispatch 20 (full) → status completed
2. Transfer more than available → should be prevented
3. Transfer between same warehouse → should be handled or prevented
4. Delete transfer → should reverse inventory

#### Stock Identity
1. Same item, different subsidy → different stock records
2. Same item, different unit cost → different stock records
3. Same item, different expiration → different stock records
4. Same item, different warehouse → different stock records

#### Security
1. Admin logout → paste protected URL → should redirect to login
2. Warehouse user access another warehouse's data → should be blocked
3. Non-admin access admin routes → should 403
4. Deactivated user login → should be blocked

## Execution Protocol

### Step 1: Setup
- Clear browser cache
- Ensure test data exists
- Use incognito/private window for auth tests

### Step 2: Execute
- Follow reproduction steps exactly
- Note actual behavior vs expected
- Take screenshots of failures

### Step 3: Report
- Use standard report format (see wgims-qa-tester)
- Include file paths and line numbers when possible
- Do not modify code

## Regression Testing
After any fix:
1. Re-run the original failing scenario
2. Run related scenarios that could be affected
3. Verify no new failures introduced
