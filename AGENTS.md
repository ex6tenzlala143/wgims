# WGIMS — Agent Instructions

This file contains the permanent project rules that every AI coding agent must understand before working on this codebase.

## System Overview

WGIMS (Welfare Goods Inventory Management System) is a Laravel 12 PHP inventory management system for tracking welfare goods across multiple warehouses. It manages the complete lifecycle of inventory from subsidy delivery to requisition dispatch and stock transfers.

## Laravel Architecture

- **Laravel 12** — no `app/Http/Kernel.php`. Middleware registered in `bootstrap/app.php`.
- **Single web guard** using session driver (no API guard).
- **Pagination** uses Bootstrap 5 (`Paginator::useBootstrap` in `AppServiceProvider`).
- **No policies directory** — authorization via middleware and gates.
- **No Form Request classes** — validation handled directly in controllers.
- **No observers/events** — side effects handled in controller methods and model boot methods.

### Middleware Stack
- `admin` — allows admins and warehouse managers
- `admin.write` — allows admins only (read-only for warehouse managers)
- `admin.create` — allows admins and warehouse managers to create
- `admin.only.strict` — allows admins only (no warehouse managers)
- `EnsureUserIsActive` — logs out deactivated users
- `NoCache` — prevents caching of authenticated pages

### Auth System
- Login via `username` (not email)
- Rate limited: 10 attempts/minute per username+IP
- Session regenerated on login
- Logout invalidates session, clears cache and bfcache
- Users have roles: admin, warehouse_manager, custodian, staff, head

## Database Schema

### Core Tables
| Table | Purpose |
|-------|---------|
| users | User accounts with role and warehouse assignments |
| warehouses | Physical storage locations |
| user_warehouse | Pivot table for multi-warehouse user assignments |
| suppliers | Source of delivery subsidies |
| items | Stock records — NOT a simple catalog |
| item_categories | Food/non-food classification |
| item_catalog_items | Catalog items under categories |

### Transaction Tables
| Table | Purpose |
|-------|---------|
| delivery_subsidies | Subsidy/delivery header records |
| delivery_subsidy_items | Line items for subsidies |
| deliveries | Actual delivery events |
| delivery_items | Items delivered in each event |
| requisitions | RIS (Requisition and Issue Slip) headers |
| requisition_items | Line items for RIS |
| requisition_dispatch_items | Dispatch records linking RIS to stock |
| stock_transfers | Transfer requests between warehouses |
| stock_transfer_items | Line items for transfers |
| stock_card_entries | Transaction history for each stock record |
| delivery_subsidy_audit_logs | Audit trail for subsidy changes |
| requisition_audit_logs | Audit trail for RIS changes |
| stock_transfer_audit_logs | Audit trail for transfer changes |
| report_snapshots | Saved report snapshots |
| system_notifications | User notifications |

### Key Database Columns
- `items.stock_number` — system-generated unique identifier
- `items.quantity` — integer, current stock level
- `items.unit_cost` — float, cost per unit
- `items.engas_unit_cost` — float, ENGAS cost per unit
- `items.expiration_date` — nullable date
- `items.source_subsidy_id` — links to originating subsidy
- `delivery_subsidies.subsidy_code` — SUB-000001 format
- `requisitions.ris_code` — RIS-000001 format
- `stock_transfers.transfer_number` — TRF-YYYY-NNNN format

## Important Business Rules

### Stock Identity
A stock record is uniquely identified by the combination of:
- warehouse_id
- description
- unit
- category
- unit_cost
- engas_unit_cost
- expiration_date
- source_subsidy_id

**Two records with different values in ANY of these fields are DIFFERENT stock records.**

The `Item::findOrCreateByUnitCost()` method implements the correct matching logic.

### Stock Merging Rules
- DO NOT merge records with different subsidy IDs
- DO NOT merge records with different unit costs
- DO NOT merge records with different ENGAS costs
- DO NOT merge records with different expiration dates
- DO NOT merge records with different warehouses
- Only merge when ALL identity fields match

### Warehouse Rules
- Admin/Warehouse Manager sees all warehouses
- Other roles see only assigned warehouses (via user_warehouse pivot)
- Non-admin users with no warehouse assignments see nothing
- Stock transfers require both source and destination warehouse access
- Warehouse scoping is enforced via `ScopesWarehouse` trait

### Subsidy Rules
- A subsidy represents a delivery from a supplier
- Subsidies can have partial deliveries (status: pending → partial → fully_delivered)
- Each delivery creates/updates stock records
- DR numbers must be unique per delivery event
- Deleting a subsidy reverses inventory and removes stock cards
- Editing a subsidy with existing deliveries is restricted to header fields and requested quantities

### RIS Rules
- RIS numbers are auto-generated (RIS-YYYYMM-NNNN format with advisory lock)
- RIS statuses: pending, approved, partially_approved, cancelled
- Dispatches lock exact stock records (by identity fields)
- Deleting a RIS reverses all dispatches and stock cards
- Editing a RIS with dispatches cannot reduce requested quantity below issued quantity
- Correcting a RIS only changes header and requested quantities — never touches dispatches

### Dispatch Rules
- A dispatch deducts stock from a warehouse
- Creates a `requisition_dispatch_items` record linking to exact stock
- Creates a `stock_card_entries` issue record
- Deleting a dispatch reverses the deduction and deletes the stock card entry
- Editing a dispatch reverses old deduction, applies new one, reconciles stock cards

### Stock Transfer Rules
- Transfers move stock between warehouses
- Snapshots source subsidy/DR/RIS lineage
- Status: pending → partial → completed
- Deleting a transfer reverses inventory on both warehouses
- Updating a transfer handles partial dispatches and reconciles stock cards

### Stock Card Rules
- Every receipt and issue creates a stock card entry
- Running balance is maintained chronologically
- `recalculateBalancesForItem()` recomputes all balances
- Deleting a transaction requires deleting its stock card entries
- Stock cards are the audit trail for all inventory movements

### Account Code Rules
- Auto-generated from item category
- Food: 1040202000-01
- Non-food: 1040202000-02
- Used in RPCI and RSMI reports

## Security Rules

- **NEVER** rely on hiding UI elements for security — verify authorization server-side
- **NEVER** allow direct URL access after logout
- **NEVER** weaken security to make functionality work
- All route protection must use middleware
- All POST/PUT/DELETE operations must have CSRF tokens
- All user input must be validated
- All database queries must use parameter binding
- All output must be escaped in Blade views

## Database Safety Rules

- **NEVER** run `migrate:fresh`, `db:wipe`, `truncate`, or `drop table` on production data
- **NEVER** delete production data casually
- **NEVER** modify existing migrations that have been used in production
- **NEVER** create unnecessary migrations
- **NEVER** modify existing data to make tests pass
- Use database transactions for all multi-step inventory operations
- Before any database change, explain: what will change, why, data impact, migration necessity, reversibility

## Coding Conventions

- **Controllers**: Keep methods focused. Extract complex logic to services.
- **Models**: Use relationships, not raw queries. Define fillable arrays.
- **Migrations**: Additive only. Never modify production tables without data migration.
- **Blade**: Use components for repeated patterns. Escape all output.
- **Validation**: Validate in controllers. No Form Request classes exist yet.
- **Naming**: Use snake_case for database columns, camelCase for PHP variables.
- **Comments**: No comments unless explicitly requested.

## Testing Requirements

- Test from a real user's perspective
- Verify cross-module impact before fixing bugs
- Never modify data to make tests pass
- Report reproduction steps, expected vs actual results
- Test edge cases: partial deliveries, multiple dispatches, different costs, different warehouses

## UI Consistency Rules

- Keep sidebar navigation intact
- Use consistent modal designs across all pages
- Use consistent delete confirmation dialogs
- Dropdowns should be writable/searchable where appropriate
- Large dropdowns must be scrollable without closing unexpectedly
- Dropdowns should be wide enough for descriptions and codes
- Tables must remain usable on laptop screens
- Avoid unnecessary horizontal overflow
- Fix skeleton/loading flashes on back navigation
- Maintain consistent spacing, typography, borders, and colors
- Do not change business logic while fixing UI

## File Paths Reference

| Component | Path |
|-----------|------|
| Routes | `routes/web.php` |
| Controllers | `app/Http/Controllers/` |
| Models | `app/Models/` |
| Migrations | `database/migrations/` |
| Middleware | `app/Http/Middleware/` |
| Views | `resources/views/` |
| Config | `config/` |
| Tests | `tests/` |

## Agent Collaboration

For complex issues, agents should delegate in this order:
1. `wgims-architect` — analyze the architecture first
2. `wgims-inventory-integrity` — verify inventory correctness
3. `wgims-bug-hunter` — find the root cause
4. `wgims-security` — check for security implications
5. `wgims-code-reviewer` — review the implementation
6. `wgims-ui-ux` — verify UI consistency
7. `wgims-optimizer` — optimize after implementation
8. `wgims-qa-tester` — test the final result

## Assumptions

This file is based on the actual WGIMS codebase as of the inspection date. If the codebase changes (new models, new routes, new business rules), update this file accordingly.
