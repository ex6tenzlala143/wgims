# WGIMS System Overview

## What is WGIMS?

WGIMS (Welfare Goods Inventory Management System) is a comprehensive inventory management system designed for managing welfare goods distribution across multiple warehouses. It tracks the complete lifecycle of inventory from delivery/subsidy through requisition, stock transfers, and stock card management.

## Primary Users

- **Administrators** — Full system access, can edit/delete any records
- **Warehouse Managers** — Can create records but cannot edit/delete
- **Supply Custodians** — Can approve requisitions
- **Center Staff** — Read-only access, cannot create records
- **Center Heads** — Can approve requisitions

## Core Business Entities

1. **Warehouses** — Physical storage locations
2. **Items** — Stock records with unit cost, ENGAS cost, expiration, quantity
3. **Delivery Subsidies** — Incoming shipments from suppliers (DR-based)
4. **Requisitions (RIS)** — Internal requests for supplies
5. **Stock Transfers** — Movement of stock between warehouses
6. **Reservations** — Pre-allocation of stock for upcoming requisitions
7. **Stock Cards** — Per-item transaction history and running balances
8. **Suppliers** — External vendors delivering goods
9. **Item Categories** — Classification (Food, Non-Food, custom)
10. **Item Catalog** — Pre-defined item names per category

## Key Workflows

### 1. Delivery / Subsidy Workflow
1. Admin/Warehouse Manager creates a Delivery Subsidy request (RIS#, supplier, items, quantities)
2. Dispatcher records a shipment (DR#, batch, condition, per-item warehouse, unit cost, ENGAS)
3. Stock is received into the selected warehouse(s)
4. Stock cards are created for each received item
5. Status updates: pending → partial → fully_delivered

### 2. Requisition (RIS) Workflow
1. Admin/Warehouse Manager creates a RIS (purpose, items requested)
2. Approver (admin, custodian, center_head) approves and dispatches items
3. Dispatcher selects exact stock records, warehouses, quantities, DR#
4. Stock is deducted from source warehouse
5. Stock cards record the issuance
6. Status updates: pending → partially_approved → approved

### 3. Stock Transfer Workflow
1. Admin/Warehouse Manager creates a transfer request (source → destination warehouse, items, quantities)
2. Dispatcher processes the dispatch (actual quantities moved)
3. Stock is deducted from source and added to destination
4. Stock cards record transfer_out and transfer_in entries
5. Status updates: pending → partial → completed

### 4. Reservation Workflow
1. Admin creates a reservation for an item in a warehouse
2. Reserved quantity is set aside (available = physical - reserved)
3. Approver approves the reservation
4. Reservation can be marked ready, fulfilled, or cancelled

### 5. Reporting
- **RPCI** — Report on Physical Count of Inventories (snapshot-based)
- **RSMI** — Report of Supplies and Materials Issued (snapshot-based)
- **Inventory Balance** — Real-time inventory balance by warehouse/category

## Technical Characteristics

- **Multi-warehouse** — Stock is tracked per warehouse; same item can exist in multiple warehouses with different costs
- **Multi-cost** — Same item description can have different unit costs and ENGAS costs (distinct stock records)
- **Multi-subsidy lineage** — Stock records track their originating subsidy for audit and deletion safety
- **Partial operations** — Deliveries, dispatches, and transfers support partial execution
- **Audit trails** — All corrections and deletions are logged
- **Snapshot reports** — RPCI and RSMI use point-in-time snapshots for consistency

## Important Design Decisions

1. **No FIFO assumption** — Stock identity is based on exact matching of all fields; the system does not assume FIFO unless explicitly implemented in stock card history
2. **Descriptions are catalog-based** — Item names come from `item_catalog_items`, not free text
3. **Cost data lives on dispatch records** — Not on requisition items (migrated in 2026-08-24)
4. **Warehouse is derived from stock record** — Not stored redundantly on dispatch items
5. **Stock numbers are permanent** — Once assigned, never reused; format is `{WAREHOUSE_CODE}-{CATEGORY_PREFIX}-{NNNN}`
