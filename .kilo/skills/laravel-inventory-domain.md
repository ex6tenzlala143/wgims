# laravel-inventory-domain

## WGIMS Inventory Domain Knowledge

This skill contains the inventory rules and relationships that every WGIMS agent must understand.

## Core Entities

### Items (items table)
- Represents a physical stock record in a specific warehouse
- **Not** just a product catalog — each record has its own identity
- Key identity fields: stock_number, description, unit, category, account_code, warehouse_id, unit_cost, engas_unit_cost, expiration_date, source_subsidy_id
- quantity tracks current stock level
- is_active is automatically managed: deactivated when quantity = 0, reactivated when > 0

### Warehouses (warehouses table)
- Physical storage locations
- Users can be assigned to multiple warehouses via user_warehouse pivot
- Admin/Warehouse Manager sees all warehouses; other roles see only assigned warehouses

### Item Categories (item_categories table)
- Hierarchical classification: food (1040202000-01), non-food (1040202000-02)
- Catalog items (item_catalog_items) belong to categories
- Account codes are inherited from categories

### Suppliers (suppliers table)
- Source of delivery subsidies
- Can be toggled active/inactive

## Transaction Entities

### Delivery Subsidy (delivery_subsidies table)
- Represents a delivery of goods from a supplier
- Contains DR number, supplier, warehouse, dates, status (pending/partial/fully_delivered)
- Has subsidy_code (SUB-000001 format)
- Contains line items in delivery_subsidy_items
- When delivered, creates Delivery records with DeliveryItem entries

### Delivery (deliveries table)
- Represents an actual delivery event for a subsidy
- Has DR number, receiver, delivery date, condition status
- Contains delivered items in delivery_items
- Each delivery item has unit_cost, engas_unit_cost, warehouse_id, expiration_date

### Requisition / RIS (requisitions table)
- Request for items from a warehouse
- Has ris_code (RIS-000001 format), ris_number (RIS-YYYYMM-NNNN), entity_name, fund_cluster, purpose
- Statuses: pending, approved, partially_approved, cancelled
- Contains requisition_items with requested quantities

### Stock Transfer (stock_transfers table)
- Request to move stock between warehouses
- Has transfer_number (TRF-YYYY-NNNN)
- Snapshots source subsidy/DR/RIS lineage
- Contains stock_transfer_items with source and destination item references

## Stock Identity Rules

A stock record is uniquely identified by the combination of:
- warehouse_id
- description
- unit
- category
- unit_cost
- engas_unit_cost
- expiration_date
- source_subsidy_id

Two records with different values in ANY of these fields are DIFFERENT stock records.

The `Item::findOrCreateByUnitCost()` method implements the correct matching logic.

## Relationship Chains

### Subsidy Flow
```
DeliverySubsidy
  → DeliverySubsidyItem
    → Delivery (delivery event)
      → DeliveryItem
        → Item (stock record created/updated)
          → StockCardEntry
```

### RIS Flow
```
Requisition
  → RequisitionItem
    → RequisitionDispatchItem (when issued)
      → Item (stock record decremented)
        → StockCardEntry
```

### Transfer Flow
```
StockTransfer
  → StockTransferItem
    → Item (source stock decremented)
    → Item (destination stock incremented)
      → StockCardEntry
```

## Account Codes
- Auto-generated from item category
- Format: 1040202000-01 (food), 1040202000-02 (non-food)
- Used in reports (RPCI, RSMI)

## Stock Card Entry
- Records every receipt and issue
- Running balance is maintained
- recalculateBalancesForItem() recomputes all balances chronologically
- entry_type can be receipt or issue
