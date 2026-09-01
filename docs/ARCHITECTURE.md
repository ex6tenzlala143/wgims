# WGIMS Architecture

## Request Flow

```
Browser
  → Route
  → Middleware (auth, admin, admin.write, admin.create, admin.only.strict)
  → Controller
  → Service/Business Logic (inline or DeliverySubsidyCascadeService)
  → Model
  → Database
  → Response/View
```

## Layer Structure

### Routes (`routes/web.php`)
- All web routes defined in a single file
- Grouped by middleware: `auth`, `admin`, `admin.write`, `admin.create`, `admin.only.strict`
- API helpers throttled at 120 req/min per user
- Route model binding used extensively (e.g., `DeliverySubsidy $deliverySubsidy`)

### Middleware
- `AdminOnly` — admin + warehouse_manager
- `AdminWriteOnly` — admin only
- `AdminCreateOnly` — admin + warehouse_manager
- `AdminOnlyStrict` — admin only
- `EnsureUserIsActive` — deactivates deactivated accounts
- `NoCache` — cache control headers

### Controllers (`app/Http/Controllers/`)
- Thin controllers — business logic lives inline or in `DeliverySubsidyCascadeService`
- `ScopesWarehouse` trait used by most controllers for warehouse-based access control
- All inventory mutations wrapped in `DB::transaction()`
- Validation via `$request->validate()` or `ValidationException`

### Models (`app/Models/`)
- Eloquent models with fillable, casts, relationships
- Boot methods for auto-generated codes (subsidy_code, ris_code)
- Static methods for number generation with advisory locks
- Accessors for computed fields (available_quantity, reserved_quantity)

### Services
- `DeliverySubsidyCascadeService` — cascades cost/RIS/supplier changes through transfer chains

### Views (`resources/views/`)
- Blade templates
- Layout in `layouts/app.blade.php` — sidebar, topbar, notifications
- Custom CSS in layout (no separate CSS files)
- Vanilla JavaScript — no framework
- SearchableSelect component enhances all `<select>` elements
- Modal forms for create/edit operations

## Directory Structure

```
app/
  Http/
    Controllers/
      Concerns/
        ScopesWarehouse.php
    Middleware/
  Models/
  Services/
  Exceptions/
config/
database/
  migrations/
resources/
  views/
    layouts/
    partials/
    reports/
routes/
storage/
```

## Key Architectural Patterns

### Warehouse Scoping
All queries that return lists of records apply warehouse scoping:
- Admin/warehouse_manager → no restriction
- Others → limited to assigned warehouses (pivot + legacy column)
- Empty assignment → returns nothing

### Advisory Locks
Used for generating unique sequential numbers:
- Stock numbers: `GET_LOCK('stock_number_{PREFIX}')`
- RIS numbers: `GET_LOCK('ris_number_{YEAR}{MONTH}')`
- Transfer numbers: `GET_LOCK('trf_number_{YEAR}')`

### Stock Identity
Items are uniquely identified by the combination of:
warehouse_id + description + unit + category + unit_cost + engas_unit_cost + expiration_date + source_subsidy_id

### Source Subsidy Tracking
Items track their originating subsidy via snapshot columns:
- `source_subsidy_id`
- `source_subsidy_ris`
- `source_subsidy_dr`
- `source_subsidy_code`
- `source_subsidy_status` (active, deleted, archived)

This allows the system to:
- Flag items when their originating subsidy is deleted
- Trace stock through transfers to other warehouses
- Maintain audit trails even after subsidy deletion

### Cascade Service
`DeliverySubsidyCascadeService` handles cascading updates:
- Unit cost changes cascade to transfer chain destinations
- RIS number changes cascade through transfers
- Supplier name changes cascade to stock card entries
- Audit logging for all cascading operations
