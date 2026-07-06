# Design Document: Warehouse-to-Warehouse Stock Transfer

## Overview

The Warehouse Transfer feature adds the ability to formally record the physical movement of inventory items between warehouse locations within the DSWD Region X WGIMS network. When goods are relocated from one warehouse to another, the system atomically deducts the transferred quantity from the source warehouse's stock card and credits it to the destination warehouse's stock card, maintaining a complete and auditable ledger at both locations.

This feature follows the same transactional patterns already established in the codebase:
- `PurchaseOrderController::storeDelivery` — DB transaction + StockCardEntry creation pattern
- `RequisitionController::processApproval` — stock deduction + StockCardEntry pattern
- `Item::findOrCreateByUnitCost()` — destination item resolution

The feature is intentionally simple: transfers are created once and immediately committed as `completed`. There is no draft/approval workflow — the act of saving a transfer is the act of completing it.

---

## Architecture

The feature follows the existing MVC structure of the Laravel 11 application with no new architectural patterns introduced.

```
┌─────────────────────────────────────────────────────────────────┐
│  HTTP Layer                                                      │
│  StockTransferController (index, create, store, show, print)    │
└────────────────────────┬────────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────────┐
│  Domain / Business Logic                                         │
│  DB::transaction {                                               │
│    StockTransfer::create()                                       │
│    foreach line:                                                 │
│      validate item belongs to source warehouse                   │
│      validate quantity <= source item quantity                   │
│      source_item.quantity -= qty  (clamped to 0)                │
│      dest_item = Item::findOrCreateByUnitCost(...)               │
│      dest_item.quantity += qty                                   │
│      StockCardEntry::create(transfer_out for source)             │
│      StockCardEntry::create(transfer_in for dest)                │
│      StockTransferItem::create()                                 │
│    }                                                             │
│    SystemNotification::create() for admins + warehouse users     │
│  }                                                               │
└────────────────────────┬────────────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────────────┐
│  Data Layer                                                      │
│  stock_transfers, stock_transfer_items                           │
│  items (quantity updated), stock_card_entries (new rows)         │
│  system_notifications (new rows)                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Request Flow

```
POST /transfers
  → StockTransferController::store()
    → Validate request (Laravel validation)
    → Authorization check (role + warehouse scope)
    → DB::transaction()
      → Generate TRF-YYYY-NNNN number
      → StockTransfer::create()
      → Per-line: validate, adjust quantities, create StockCardEntries, create StockTransferItems
      → Notify admins and warehouse users
    → Redirect to show page
```

---

## Components and Interfaces

### New Models

#### `StockTransfer`

```php
// app/Models/StockTransfer.php
class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_number', 'from_warehouse_id', 'to_warehouse_id',
        'transfer_date', 'transferred_by', 'status', 'remarks',
    ];

    protected $casts = ['transfer_date' => 'date'];

    public function fromWarehouse(): BelongsTo  // Warehouse
    public function toWarehouse(): BelongsTo    // Warehouse
    public function transferredBy(): BelongsTo  // User
    public function items(): HasMany            // StockTransferItem

    public static function generateTransferNumber(): string
    // Pattern: TRF-YYYY-NNNN (sequential within year, race-condition guarded)
}
```

#### `StockTransferItem`

```php
// app/Models/StockTransferItem.php
class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'item_id', 'destination_item_id',
        'quantity', 'unit_cost',
    ];

    public function transfer(): BelongsTo       // StockTransfer
    public function sourceItem(): BelongsTo     // Item (item_id)
    public function destinationItem(): BelongsTo // Item (destination_item_id)
}
```

### New Controller

#### `StockTransferController`

| Method | Route | Description |
|--------|-------|-------------|
| `index` | `GET /transfers` | Paginated list, scoped by user role |
| `create` | `GET /transfers/create` | Form to create a new transfer |
| `store` | `POST /transfers` | Validate, execute transaction, redirect |
| `show` | `GET /transfers/{transfer}` | Detail view |
| `print` | `GET /transfers/{transfer}/print` | Printable slip |

**Authorization rules enforced in controller:**
- `center_staff` → `abort(403)` on `create` and `store`
- `supply_custodian` / `center_head` → `from_warehouse_id` must equal `auth()->user()->warehouse_id`
- `center_staff` / `supply_custodian` / `center_head` → `show` and `print` require `from_warehouse_id == user.warehouse_id OR to_warehouse_id == user.warehouse_id`

### New Views

| View | Path |
|------|------|
| Index | `resources/views/transfers/index.blade.php` |
| Create | `resources/views/transfers/create.blade.php` |
| Show | `resources/views/transfers/show.blade.php` |
| Print | `resources/views/transfers/print.blade.php` |

All views extend `layouts.app` and use the existing card/table/form CSS classes.

### Routes

```php
// In routes/web.php, inside the auth middleware group
Route::get('/transfers', [StockTransferController::class, 'index'])->name('transfers.index');
Route::get('/transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
Route::post('/transfers', [StockTransferController::class, 'store'])->name('transfers.store');
Route::get('/transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
Route::get('/transfers/{transfer}/print', [StockTransferController::class, 'print'])->name('transfers.print');
```

### Sidebar Navigation

A new entry is added under the **Inventory** section in `resources/views/layouts/app.blade.php`, between Requisitions (RIS) and Stock Cards:

```blade
<a href="{{ route('transfers.index') }}" class="nav-item {{ request()->routeIs('transfers*') ? 'active' : '' }}">
    <i class="fas fa-exchange-alt"></i> Stock Transfers
</a>
```

---

## Data Models

### `stock_transfers` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | Auto-increment |
| `transfer_number` | varchar unique | `TRF-YYYY-NNNN` |
| `from_warehouse_id` | bigint FK → warehouses | Source warehouse |
| `to_warehouse_id` | bigint FK → warehouses | Destination warehouse |
| `transfer_date` | date | User-provided date |
| `transferred_by` | bigint FK → users | Initiating user |
| `status` | varchar | Always `'completed'` after commit |
| `remarks` | text nullable | Optional free-text |
| `created_at` / `updated_at` | timestamps | |

### `stock_transfer_items` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | Auto-increment |
| `stock_transfer_id` | bigint FK → stock_transfers | Parent transfer |
| `item_id` | bigint FK → items | Source item |
| `destination_item_id` | bigint FK → items | Destination item (resolved via `findOrCreateByUnitCost`) |
| `quantity` | decimal(15,4) | Transferred quantity |
| `unit_cost` | decimal(15,2) | Unit cost at time of transfer |
| `created_at` / `updated_at` | timestamps | |

### Transfer Number Generation

Follows the same pattern as `Requisition::generateRisNumber()`:

```php
public static function generateTransferNumber(): string
{
    $year = date('Y');
    $base = static::whereYear('created_at', $year)->count();
    $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);

    $attempts = 0;
    while (static::where('transfer_number', $candidate)->exists() && $attempts < 20) {
        $base++;
        $attempts++;
        $candidate = 'TRF-' . $year . '-' . str_pad($base + 1, 4, '0', STR_PAD_LEFT);
    }

    return $candidate;
}
```

### Stock Card Entry Fields for Transfers

**Source item (transfer_out):**

| Field | Value |
|-------|-------|
| `item_id` | source item ID |
| `entry_date` | transfer date |
| `reference` | transfer number (e.g. `TRF-2025-0001`) |
| `reference_type` | `'transfer_out'` |
| `reference_id` | stock_transfer.id |
| `receipt_qty` | `0` |
| `receipt_unit_cost` | `0` |
| `receipt_total_cost` | `0` |
| `issue_qty` | transferred quantity |
| `balance_qty` | source item quantity after deduction |
| `balance_unit_cost` | source item unit_cost |
| `balance_total_cost` | balance_qty × unit_cost |
| `from_to` | destination warehouse name |

**Destination item (transfer_in):**

| Field | Value |
|-------|-------|
| `item_id` | destination item ID |
| `entry_date` | transfer date |
| `reference` | transfer number |
| `reference_type` | `'transfer_in'` |
| `reference_id` | stock_transfer.id |
| `receipt_qty` | transferred quantity |
| `receipt_unit_cost` | destination item unit_cost |
| `receipt_total_cost` | quantity × unit_cost |
| `issue_qty` | `0` |
| `balance_qty` | destination item quantity after addition |
| `balance_unit_cost` | destination item unit_cost |
| `balance_total_cost` | balance_qty × unit_cost |
| `from_to` | source warehouse name |

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Transfer number uniqueness and format

*For any* set of transfer records created across any number of years, every transfer number must be unique and must match the pattern `TRF-YYYY-NNNN` where `YYYY` is a four-digit year and `NNNN` is a zero-padded four-digit sequence number.

**Validates: Requirements 1.1**

---

### Property 2: Source warehouse scoping for non-admin users

*For any* supply_custodian or center_head user with an assigned warehouse W, submitting a transfer where `from_warehouse_id` ≠ W.id must be rejected (403 or validation error), regardless of the destination warehouse or item selection.

**Validates: Requirements 1.5**

---

### Property 3: Over-quantity transfer rejection

*For any* source item with current quantity Q, submitting a transfer line requesting quantity T where T > Q must be rejected with a validation error identifying the item and available quantity.

**Validates: Requirements 2.3**

---

### Property 4: Quantity conservation across transfer

*For any* valid transfer of quantity T from source item S to destination item D:
- `S.quantity_after = S.quantity_before − T` (and `S.quantity_after ≥ 0`)
- `D.quantity_after = D.quantity_before + T`

The total inventory value across both warehouses for the transferred item is conserved (assuming same unit cost).

**Validates: Requirements 3.2, 3.4, 3.6**

---

### Property 5: Stock card entry correctness

*For any* valid transfer with transfer number N, transfer date D, quantity T, source item S (warehouse W_src), and destination item Dest (warehouse W_dst):

- A `StockCardEntry` exists for S with `reference_type='transfer_out'`, `issue_qty=T`, `balance_qty=S.quantity_after`, `from_to=W_dst.name`, `reference=N`, `reference_id=transfer.id`, `entry_date=D`, `balance_unit_cost=S.unit_cost`, `balance_total_cost=S.quantity_after × S.unit_cost`
- A `StockCardEntry` exists for Dest with `reference_type='transfer_in'`, `receipt_qty=T`, `balance_qty=Dest.quantity_after`, `from_to=W_src.name`, `reference=N`, `reference_id=transfer.id`, `entry_date=D`, `receipt_unit_cost=Dest.unit_cost`, `receipt_total_cost=T × Dest.unit_cost`, `balance_unit_cost=Dest.unit_cost`, `balance_total_cost=Dest.quantity_after × Dest.unit_cost`

**Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6**

---

### Property 6: Transfer listing visibility scoping

*For any* center user (supply_custodian, center_head, or center_staff) with assigned warehouse W, every transfer returned in the listing must satisfy: `from_warehouse_id = W.id OR to_warehouse_id = W.id`. No transfer involving only other warehouses should appear in their list.

**Validates: Requirements 5.2**

---

### Property 7: Transfer detail and print access control

*For any* center user with assigned warehouse W, attempting to access the detail or print view of a transfer where `from_warehouse_id ≠ W.id AND to_warehouse_id ≠ W.id` must return a 403 Forbidden response.

**Validates: Requirements 6.2, 9.2**

---

### Property 8: Completed status on successful save

*For any* transfer that is successfully stored (transaction committed without error), the resulting `StockTransfer.status` must equal `'completed'`.

**Validates: Requirements 7.1**

---

### Property 9: Notification coverage on transfer

*For any* successfully saved transfer involving source warehouse W_src and destination warehouse W_dst:
- Every user with `role='admin'` must have a SystemNotification created referencing the transfer number, W_src.name, and W_dst.name.
- Every user with `role IN ('supply_custodian', 'center_head')` whose `warehouse_id = W_src.id OR warehouse_id = W_dst.id` must also have a SystemNotification created.

**Validates: Requirements 8.1, 8.2**

---

## Error Handling

### Validation Errors (422 / redirect back with errors)

| Condition | Error Message |
|-----------|---------------|
| `from_warehouse_id == to_warehouse_id` | "Source and destination warehouse must be different." |
| No transfer lines submitted | "At least one transfer line is required." |
| Transfer line quantity ≤ 0 | "Quantity must be greater than zero." |
| Transfer line unit_cost ≤ 0 | "Unit cost must be greater than zero." |
| Item does not belong to source warehouse | "Item '{description}' does not belong to the selected source warehouse." |
| Transfer quantity > available quantity | "Insufficient stock for '{description}': requested {T}, available {Q}." |

### Authorization Errors (403)

| Condition | Response |
|-----------|----------|
| `center_staff` attempts create/store | `abort(403)` |
| Non-admin submits with `from_warehouse_id ≠ user.warehouse_id` | `abort(403)` |
| Center user accesses show/print for unrelated transfer | `abort(403)` |

### Transaction Rollback

If any exception is thrown inside `DB::transaction()`, Laravel automatically rolls back all changes. The controller catches the exception and returns the user to the form with a generic error message: "Transfer could not be saved. Please try again."

### Quantity Clamping

As a defensive measure (belt-and-suspenders after validation), the source item quantity is clamped to a minimum of zero:

```php
$newSourceQty = max(0, $sourceItem->quantity - $line['quantity']);
```

---

## Testing Strategy

### Unit Tests (PHPUnit)

Unit tests cover specific examples, edge cases, and authorization rules:

- `StockTransfer::generateTransferNumber()` returns correct format
- Same-warehouse validation rejects the transfer
- `center_staff` role receives 403 on create/store
- Non-admin user with wrong warehouse receives 403 on store
- Over-quantity transfer line is rejected with correct error message
- Zero/negative quantity is rejected
- Transfer with no lines is rejected
- Center user cannot access show/print for unrelated transfer
- Successful transfer sets status to `'completed'`
- Successful transfer creates correct StockCardEntry rows (transfer_out and transfer_in)
- Successful transfer adjusts source and destination item quantities correctly
- Notifications are created for all admin users on successful transfer
- Notifications are created for supply_custodian/center_head users at both warehouses

### Property-Based Tests (PestPHP + `spatie/pest-plugin-test-time` or raw PHPUnit with a PBT library)

The project uses PHPUnit (see `phpunit.xml`). Property-based tests will use the **[`eris/eris`](https://github.com/giorgiosironi/eris)** library for PHP, which integrates with PHPUnit and provides generators for random data.

Each property test runs a minimum of **100 iterations**.

Tag format: `Feature: warehouse-transfer, Property {N}: {property_text}`

**Property 1 — Transfer number uniqueness and format**
```
// Feature: warehouse-transfer, Property 1: transfer number uniqueness and format
// Generate N random transfers in the same year, verify all numbers are unique
// and match /^TRF-\d{4}-\d{4}$/
```

**Property 2 — Source warehouse scoping**
```
// Feature: warehouse-transfer, Property 2: source warehouse scoping for non-admin users
// Generate random supply_custodian users with random warehouse assignments,
// generate random from_warehouse_id != user.warehouse_id,
// verify store() returns 403
```

**Property 3 — Over-quantity rejection**
```
// Feature: warehouse-transfer, Property 3: over-quantity transfer rejection
// Generate random items with random quantities Q > 0,
// generate transfer quantity T where T > Q,
// verify validation error is returned
```

**Property 4 — Quantity conservation**
```
// Feature: warehouse-transfer, Property 4: quantity conservation across transfer
// Generate random source item with quantity Q, random transfer quantity T (0 < T <= Q),
// execute transfer, verify source.quantity = Q - T, dest.quantity = dest_before + T,
// source.quantity >= 0
```

**Property 5 — Stock card entry correctness**
```
// Feature: warehouse-transfer, Property 5: stock card entry correctness
// Generate random valid transfers, execute them,
// verify both StockCardEntry rows have all correct field values
```

**Property 6 — Transfer listing visibility scoping**
```
// Feature: warehouse-transfer, Property 6: transfer listing visibility scoping
// Generate random center users and random sets of transfers across multiple warehouses,
// verify that the listing for each user contains only transfers involving their warehouse
```

**Property 7 — Transfer detail and print access control**
```
// Feature: warehouse-transfer, Property 7: transfer detail and print access control
// Generate random center users and random transfers not involving their warehouse,
// verify show() and print() return 403
```

**Property 8 — Completed status on successful save**
```
// Feature: warehouse-transfer, Property 8: completed status on successful save
// Generate random valid transfer inputs, execute store(),
// verify the resulting StockTransfer.status = 'completed'
```

**Property 9 — Notification coverage**
```
// Feature: warehouse-transfer, Property 9: notification coverage on transfer
// Generate random sets of admin users and warehouse users,
// execute a transfer, verify all admins and relevant warehouse users received notifications
```

### Integration Tests

- End-to-end: POST /transfers with valid data → verify DB state (transfer record, item quantities, stock card entries, notifications)
- Rollback: simulate DB failure mid-transaction → verify no partial state persists
- `Item::findOrCreateByUnitCost()` correctly resolves or creates destination item with matching attributes

### Manual / Smoke Tests

- Print view renders correctly in browser print mode
- Sidebar nav entry appears and highlights correctly for all transfer routes
- Pagination works on the index page with many transfers
