# WGIMS Model Relationships

## Warehouse

```
Warehouse
  ├── hasMany → User (legacy warehouse_id)
  ├── belongsToMany → User (user_warehouse pivot)
  ├── hasMany → Item
  ├── hasMany → DeliverySubsidy (legacy, often null)
  ├── hasMany → Requisition (legacy, often null)
```

**Important**: A warehouse can have items, delivery subsidies, and requisitions. However, in the modern multi-warehouse design, `delivery_subsidies.warehouse_id` and `requisitions.warehouse_id` are often null because the actual warehouse is determined at the line-item or dispatch level.

## User

```
User
  ├── belongsTo → Warehouse (legacy warehouse_id)
  ├── belongsToMany → Warehouse (user_warehouse pivot)
  ├── hasMany → SystemNotification
  ├── hasMany → DeliverySubsidy (as creator)
  ├── hasMany → Requisition (as creator)
  ├── hasMany → RequisitionDispatchItem (as creator)
  ├── hasMany → StockTransfer (as transferredBy)
  ├── hasMany → Reservation (as creator)
  ├── hasMany → Reservation (as approver)
```

## Item (Stock Record)

```
Item
  ├── belongsTo → Warehouse
  ├── belongsTo → DeliverySubsidy (source_subsidy_id)
  ├── hasMany → StockCardEntry
  ├── hasMany → DeliverySubsidyItem
  ├── hasMany → RequisitionItem
  ├── hasMany → RequisitionDispatchItem
  ├── hasMany → Reservation
  ├── hasMany → Reservation (activeReservations)
  ├── belongsToMany → Warehouse (through user_warehouse, via User)
```

**Key accessors**:
- `reserved_quantity` — sum of active reservation reserved_quantity for this item
- `available_quantity` — quantity - reserved_quantity

## DeliverySubsidy

```
DeliverySubsidy
  ├── belongsTo → Supplier
  ├── belongsTo → Warehouse (legacy, often null)
  ├── belongsTo → User (creator)
  ├── hasMany → DeliverySubsidyItem
  ├── hasMany → Delivery
  ├── hasMany → DeliverySubsidyAuditLog
```

**Key methods**:
- `totalDelivered()` — sum of deliveries.quantity_delivered
- `updateDeliveryStatus()` — pending / partial / fully_delivered

## DeliverySubsidyItem

```
DeliverySubsidyItem
  ├── belongsTo → DeliverySubsidy
  ├── belongsTo → Warehouse (per-line destination)
  ├── belongsTo → Item (resolved inventory record)
  ├── belongsTo → ItemCatalogItem
  ├── hasMany → DeliveryItem
```

**Key accessors**:
- `dispatchWarehouses` — distinct warehouses from deliveryItems
- `assignedStockCards` — distinct Item records from deliveryItems
- `dispatchSummary` — per-warehouse/cost summary of deliveries

## Delivery

```
Delivery
  ├── belongsTo → DeliverySubsidy
  ├── belongsTo → User (receiver)
  └── hasMany → DeliveryItem
```

## DeliveryItem

```
DeliveryItem
  ├── belongsTo → Delivery
  ├── belongsTo → DeliverySubsidyItem
  ├── belongsTo → Item (exact stock record)
  ├── belongsTo → Warehouse (where stock landed)
```

**Key accessor**:
- `engasTotalValue` — quantity_delivered × engas_unit_cost

## Requisition (RIS)

```
Requisition
  ├── belongsTo → Warehouse (legacy, often null)
  ├── belongsTo → User (creator)
  ├── belongsTo → User (approver)
  ├── hasMany → RequisitionItem
  └── hasMany → RequisitionAuditLog
```

**Key methods**:
- `totalRequested()` — sum of items.quantity_requested
- `totalIssued()` — sum of items.quantity_issued
- `totalRemaining()` — max(0, totalRequested - totalIssued)
- `updateFulfilmentStatus()` — pending / partially_approved / approved

**Key accessors**:
- `warehouseNames` — all warehouses from line items + dispatches
- `warehouseIds` — all warehouse IDs from line items + dispatches

## RequisitionItem

```
RequisitionItem
  ├── belongsTo → Requisition
  ├── belongsTo → Item (representative, often null)
  ├── belongsTo → ItemCatalogItem
  ├── belongsTo → Warehouse (per-line, often null)
  └── hasMany → RequisitionDispatchItem
```

**Note**: Cost data (unit_cost, engas_unit_cost) was removed from this table. It now lives exclusively on `RequisitionDispatchItem`.

## RequisitionDispatchItem

```
RequisitionDispatchItem
  ├── belongsTo → RequisitionItem
  ├── belongsTo → Item (exact stock record dispatched from)
  ├── belongsTo → User (creator)
```

## StockCardEntry

```
StockCardEntry
  ├── belongsTo → Item
  └── belongsTo → RequisitionDispatchItem (dispatch_item_id, nullable)
```

**Key static method**:
- `recalculateBalancesForItem($itemId)` — recomputes all running balances from scratch

## StockTransfer

```
StockTransfer
  ├── belongsTo → Warehouse (fromWarehouse)
  ├── belongsTo → Warehouse (toWarehouse)
  ├── belongsTo → User (transferredBy)
  ├── belongsTo → DeliverySubsidy (optional)
  ├── hasMany → StockTransferItem
  └── hasMany → StockTransferAuditLog
```

**Key methods**:
- `totalRequested()` — sum of items.quantity_requested
- `totalTransferred()` — sum of items.quantity
- `totalRemaining()` — max(0, totalRequested - totalTransferred)
- `updateTransferStatus()` — pending / partial / completed

## StockTransferItem

```
StockTransferItem
  ├── belongsTo → StockTransfer
  ├── belongsTo → Item (sourceItem)
  └── belongsTo → Item (destinationItem)
```

## Reservation

```
Reservation
  ├── belongsTo → Warehouse
  ├── belongsTo → Item
  ├── belongsTo → Requisition (intendedRequisition)
  ├── belongsTo → User (creator)
  └── belongsTo → User (approver)
```

**Key static methods**:
- `reservedQuantityForItem($itemId)` — sum of reserved_quantity for active reservations
- `availableQuantityForItem($item)` — max(0, item.quantity - reservedQuantity)

**Statuses**: PENDING, RESERVED, READY_FOR_REQUISITION, ALLOCATED, FULFILLED, CANCELLED, EXPIRED

**Active statuses**: PENDING, RESERVED, READY_FOR_REQUISITION, ALLOCATED

## ItemCategory

```
ItemCategory
  ├── hasMany → ItemCatalogItem
  └── hasMany → Item (via category key match)
```

## ItemCatalogItem

```
ItemCatalogItem
  └── belongsTo → ItemCategory
```

## Supplier

```
Supplier
  └── hasMany → DeliverySubsidy
```

## ReportSnapshot

```
ReportSnapshot
  ├── belongsTo → Warehouse
  └── belongsTo → User (creator)
```

## SystemNotification

```
SystemNotification
  └── belongsTo → User
```
