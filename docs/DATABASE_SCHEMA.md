# WGIMS Database Schema

## Tables

### users
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| username | string | No | Unique, login field |
| name | string | No | |
| email | string | Yes | Unique |
| email_verified_at | timestamp | Yes | Dropped in 2026-08-30 |
| password | string | No | |
| role | string | No | Default: center_staff |
| warehouse_id | unsignedBigInteger | Yes | FK to warehouses, nullOnDelete |
| is_active | boolean | No | Default: true |
| remember_token | string | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### warehouses
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| name | string | No | |
| code | string(20) | No | Unique |
| place | string | Yes | |
| is_active | boolean | No | Default: true |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### user_warehouse (pivot)
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| user_id | unsignedBigInteger | No | FK to users, cascadeOnDelete |
| warehouse_id | unsignedBigInteger | No | FK to warehouses, cascadeOnDelete |
| **PK** | (user_id, warehouse_id) | | |

### items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| stock_number | string | Yes | Unique |
| description | string | No | |
| ris_number | string | Yes | |
| unit | string | No | |
| category | string | No | food, non-food, etc. |
| account_code | string(50) | No | |
| warehouse_id | unsignedBigInteger | No | FK to warehouses, cascadeOnDelete |
| unit_cost | decimal(15,2) | No | Default: 0 |
| engas_unit_cost | decimal(15,2) | Yes | |
| quantity | decimal(15,4) | No | Default: 0 |
| quantity_per_item | integer | Yes | |
| reorder_point | decimal(15,4) | No | Default: 0 |
| expiration_date | date | Yes | |
| is_active | boolean | No | Default: true |
| **source_subsidy_id** | unsignedBigInteger | Yes | FK to delivery_subsidies, nullOnDelete |
| **source_subsidy_ris** | string | Yes | Snapshot of RIS# |
| **source_subsidy_dr** | string | Yes | Snapshot of DR# |
| **source_subsidy_code** | string | Yes | Snapshot of SUB-XXXXXX |
| **source_subsidy_status** | string | Yes | active, deleted, archived |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Indexes**: stock_number (unique), warehouse_id, category

### suppliers
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| name | string | No | |
| address | text | Yes | |
| tin | string(50) | Yes | |
| contact_person | string(100) | Yes | |
| phone | string(50) | Yes | |
| email | string(100) | Yes | |
| is_active | boolean | No | Default: true |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### delivery_subsidies
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| subsidy_code | string | Yes | Unique, auto-generated SUB-XXXXXX |
| dr_number | string | No | Unique |
| supplier_id | unsignedBigInteger | No | FK to suppliers |
| warehouse_id | unsignedBigInteger | Yes | Legacy, often null |
| created_by | unsignedBigInteger | No | FK to users |
| date | date | No | |
| ris_number | string | Yes | Unique |
| place_of_delivery | string | Yes | |
| date_of_delivery | date | Yes | |
| date_of_expiration | string | Yes | |
| total_amount | decimal(15,2) | No | Default: 0 |
| quantity_requested | decimal(15,4) | No | Default: 0 |
| status | string | No | pending, partial, fully_delivered, cancelled |
| remarks | text | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Indexes**: warehouse_id + status (po_wh_status)

### delivery_subsidy_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| delivery_subsidy_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| item_id | unsignedBigInteger | Yes | FK to items, nullOnDelete |
| catalog_item_id | unsignedBigInteger | Yes | FK to item_catalog_items, nullOnDelete |
| warehouse_id | unsignedBigInteger | Yes | Per-line destination (nullable) |
| quantity | decimal(15,4) | No | Requested quantity |
| unit_cost | decimal(15,2) | Yes | |
| amount | decimal(15,2) | Yes | |
| qty_delivered | decimal(15,4) | No | Default: 0 |
| description | string | Yes | Snapshot |
| unit | string | Yes | Snapshot |
| category | string | Yes | Snapshot |
| account_code | string(50) | Yes | |
| expiration_date | date | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### deliveries
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| delivery_subsidy_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| dr_number | string | Yes | Shipment DR# |
| received_by | unsignedBigInteger | No | FK to users |
| delivery_date | date | No | |
| batch_number | string | Yes | |
| condition_status | string | No | good, damaged, partial |
| quantity_delivered | decimal(15,4) | No | Default: 0 |
| remarks | text | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### delivery_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| delivery_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| delivery_subsidy_item_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| item_id | unsignedBigInteger | No | FK to items |
| warehouse_id | unsignedBigInteger | Yes | Per-dispatch warehouse |
| quantity_delivered | decimal(15,4) | No | Default: 0 |
| unit_cost | decimal(15,2) | No | |
| engas_unit_cost | decimal(15,2) | Yes | |
| engas_total_cost | decimal(15,2) | Yes | |
| condition | string | No | Default: good |
| dr_number | string | Yes | Per-item DR# |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### requisitions
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| ris_code | string | Yes | Unique, auto-generated RIS-XXXXXX |
| ris_number | string | No | Unique |
| dr_number | string | Yes | Legacy, now nullable |
| warehouse_id | unsignedBigInteger | Yes | Legacy, often null |
| created_by | unsignedBigInteger | No | FK to users |
| approved_by | unsignedBigInteger | Yes | FK to users |
| entity_name | string | Yes | |
| fund_cluster | string | Yes | |
| office | string | Yes | |
| division | string | Yes | |
| province | string | Yes | |
| municipality | string | Yes | |
| responsibility_center_code | string | Yes | |
| purpose | text | No | |
| date_requested | date | No | |
| date_approved | date | Yes | |
| status | string | No | pending, approved, partially_approved, cancelled |
| requested_by_name | string | Yes | |
| requested_by_designation | string | Yes | |
| approved_by_name | string | Yes | |
| approved_by_designation | string | Yes | |
| issued_by_name | string | Yes | |
| issued_by_designation | string | Yes | |
| received_by_name | string | Yes | |
| received_by_designation | string | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### requisition_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| requisition_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| catalog_item_id | unsignedBigInteger | Yes | FK to item_catalog_items, nullOnDelete |
| item_id | unsignedBigInteger | Yes | Representative stock record (nullable) |
| description | string | Yes | Snapshot |
| unit | string | Yes | Snapshot |
| account_code | string(50) | Yes | |
| warehouse_id | unsignedBigInteger | Yes | Per-line warehouse (nullable) |
| quantity_requested | decimal(15,4) | No | |
| quantity_issued | decimal(15,4) | No | Default: 0 |
| stock_available | boolean | No | Default: false |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Note**: unit_cost, engas_unit_cost, expiration_date columns were removed from this table (migration 2026_08_24_210730). Cost data now lives exclusively on `requisition_dispatch_items`.

### requisition_dispatch_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| requisition_item_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| item_id | unsignedBigInteger | No | FK to items (exact stock record) |
| quantity_issued | decimal(15,4) | No | |
| unit_cost | decimal(15,4) | No | Default: 0 |
| engas_unit_cost | decimal(15,4) | Yes | |
| expiration_date | date | Yes | |
| dr_number | string | Yes | Per-dispatch DR# |
| created_by | unsignedBigInteger | Yes | FK to users |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### stock_card_entries
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| item_id | unsignedBigInteger | No | FK to items, cascadeOnDelete |
| entry_date | date | No | |
| reference | string | No | DR#, RIS#, or TRF# |
| reference_type | string | No | delivery, issuance, transfer_out, transfer_in |
| reference_id | string | Yes | delivery_id, requisition_id, transfer_id |
| dispatch_item_id | unsignedBigInteger | Yes | FK to requisition_dispatch_items, nullOnDelete |
| receipt_qty | decimal(15,4) | No | Default: 0 |
| receipt_unit_cost | decimal(15,2) | No | Default: 0 |
| receipt_total_cost | decimal(15,2) | No | Default: 0 |
| issue_qty | decimal(15,4) | No | Default: 0 |
| balance_qty | decimal(15,4) | No | Default: 0 |
| balance_unit_cost | decimal(15,2) | No | Default: 0 |
| balance_total_cost | decimal(15,2) | No | Default: 0 |
| no_of_days_to_consume | integer | Yes | |
| from_to | string | Yes | Supplier name or warehouse name |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### stock_transfers
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| transfer_number | string | No | Unique |
| delivery_subsidy_id | unsignedBigInteger | Yes | FK, nullOnDelete |
| source_ris_number | string | Yes | Snapshot |
| source_dr_number | string | Yes | Snapshot |
| source_subsidy_code | string | Yes | Snapshot |
| source_subsidy_status | string | Yes | null, deleted, archived |
| from_warehouse_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| to_warehouse_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| transfer_date | date | No | |
| transferred_by | unsignedBigInteger | No | FK to users, cascadeOnDelete |
| status | string | No | pending, partial, completed |
| remarks | text | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### stock_transfer_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| stock_transfer_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| item_id | unsignedBigInteger | No | FK to items (source) |
| destination_item_id | unsignedBigInteger | No | FK to items (destination) |
| quantity_requested | decimal(15,4) | No | Default: 0 |
| quantity | decimal(15,4) | No | Default: 0 (actually dispatched) |
| unit_cost | decimal(15,2) | No | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### reservations
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| warehouse_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| item_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| reserved_quantity | decimal(15,4) | No | Default: 0 |
| allocated_quantity | decimal(15,4) | No | Default: 0 |
| status | string(30) | No | Default: PENDING |
| purpose | string(255) | Yes | |
| intended_requisition_id | unsignedBigInteger | Yes | FK, nullOnDelete |
| created_by | unsignedBigInteger | No | FK to users |
| approved_by | unsignedBigInteger | Yes | FK, nullOnDelete |
| notes | text | Yes | |
| expires_at | timestamp | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Indexes**: item_id + status, warehouse_id + status

### item_categories
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| key | string | No | Unique, e.g., food, non-food |
| label | string | No | Display name |
| account_code | string(50) | No | |
| is_active | boolean | No | Default: true |
| sort_order | integer | No | Default: 0 |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### item_catalog_items
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| item_category_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| name | string | No | |
| account_code | string(50) | No | |
| is_active | boolean | No | Default: true |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Unique**: item_category_id + name
**Index**: account_code

### report_snapshots
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| report_type | string | No | rpci, rsmi |
| warehouse_id | unsignedBigInteger | Yes | FK, nullOnDelete |
| period_month | string | No | e.g., 2026-05 |
| serial_number | string | Yes | |
| data | longText | No | JSON snapshot |
| created_by | unsignedBigInteger | No | FK to users |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

### delivery_subsidy_audit_logs
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| delivery_subsidy_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| user_id | unsignedBigInteger | No | FK to users |
| action | string | No | update, correction, unit_cost_cascade, etc. |
| changed_fields | json | No | |
| cascade_summary | json | Yes | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Index**: delivery_subsidy_id + created_at

### requisition_audit_logs
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| requisition_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| user_id | unsignedBigInteger | No | FK to users |
| action | string | No | correction |
| changed_fields | json | No | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Index**: requisition_id + created_at

### stock_transfer_audit_logs
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| stock_transfer_id | unsignedBigInteger | Yes | FK, nullOnDelete |
| transfer_number | string | No | Snapshot |
| user_id | unsignedBigInteger | No | FK to users |
| action | string | No | create, update, dispatch, reversed_deleted |
| changed_fields | json | No | |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

**Index**: stock_transfer_id + created_at

### system_notifications
| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint | No | PK |
| user_id | unsignedBigInteger | No | FK, cascadeOnDelete |
| title | string | No | |
| message | text | No | |
| type | string | No | info, success, warning, danger, transfer |
| link | string | Yes | |
| is_read | boolean | No | Default: false |
| created_at | timestamp | No | |
| updated_at | timestamp | No | |

## Spatie Permission Tables (if used)
- permissions
- roles
- model_has_permissions
- model_has_roles
- role_has_permissions

**Note**: WGIMS primarily uses the `users.role` column for authorization, not Spatie's role-permission system. The Spatie tables exist but are not the primary authorization mechanism.
