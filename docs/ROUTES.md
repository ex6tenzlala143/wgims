# WGIMS Routes Reference

## Authentication
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /login | login | AuthController | none | — |
| POST | /login | login.post | AuthController | none | RateLimiter (10/min) |
| POST | /logout | logout | AuthController | auth | — |

## Core
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | / | dashboard | DashboardController | auth | — |

## Items
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /items | items.index | ItemController | auth | — |
| GET | /items/{item} | items.show | ItemController | auth | — |

## Delivery Subsidies
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /delivery-subsidies | delivery_subsidies.index | DeliverySubsidyController | auth | — |
| GET | /delivery-subsidies/create | delivery_subsidies.create | DeliverySubsidyController | auth | admin.create |
| POST | /delivery-subsidies | delivery_subsidies.store | DeliverySubsidyController | auth | admin.create |
| GET | /delivery-subsidies/{ds} | delivery_subsidies.show | DeliverySubsidyController | auth | — |
| GET | /delivery-subsidies/{ds}/delivery | delivery_subsidies.delivery | DeliverySubsidyController | auth | — |
| POST | /delivery-subsidies/{ds}/delivery | delivery_subsidies.store_delivery | DeliverySubsidyController | auth | admin.create |
| GET | /delivery-subsidies/{ds}/edit | delivery_subsidies.edit | DeliverySubsidyController | auth | admin.write |
| GET | /delivery-subsidies/{ds}/edit-data | delivery_subsidies.edit_data | DeliverySubsidyController | auth | admin.write |
| PUT | /delivery-subsidies/{ds} | delivery_subsidies.update | DeliverySubsidyController | auth | admin.write |
| DELETE | /delivery-subsidies/{ds} | delivery_subsidies.destroy | DeliverySubsidyController | auth | admin.write |
| GET | /delivery-subsidies/{ds}/deliveries/{d}/edit | delivery_subsidies.edit_delivery | DeliverySubsidyController | auth | admin |
| PUT | /delivery-subsidies/{ds}/deliveries/{d} | delivery_subsidies.update_delivery | DeliverySubsidyController | auth | admin |
| DELETE | /delivery-subsidies/{ds}/deliveries/{d} | delivery_subsidies.destroy_delivery | DeliverySubsidyController | auth | admin |
| GET | /delivery-subsidies/{ds}/audit-log | delivery_subsidies.audit_log | DeliverySubsidyController | auth | admin |

## Requisitions (RIS)
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /requisitions | requisitions.index | RequisitionController | auth | — |
| GET | /requisitions/create | requisitions.create | RequisitionController | auth | admin.create |
| POST | /requisitions | requisitions.store | RequisitionController | auth | admin.create |
| GET | /requisitions/{r} | requisitions.show | RequisitionController | auth | — |
| GET | /requisitions/{r}/approve | requisitions.approve | RequisitionController | auth | — |
| POST | /requisitions/{r}/approve | requisitions.process_approval | RequisitionController | auth | — |
| GET | /requisitions/{r}/signatories | requisitions.signatories | RequisitionController | auth | — |
| GET | /requisitions/{r}/print | requisitions.print | RequisitionController | auth | — |
| GET | /requisitions/{r}/edit | requisitions.edit | RequisitionController | auth | admin.write |
| PUT | /requisitions/{r} | requisitions.update | RequisitionController | auth | admin.write |
| PUT | /requisitions/{r}/signatories | requisitions.update_signatories | RequisitionController | auth | admin.write |
| GET | /requisitions/{r}/correction-data | requisitions.correction_data | RequisitionController | auth | admin.write |
| PUT | /requisitions/{r}/correct | requisitions.correct | RequisitionController | auth | admin.write |
| GET | /requisitions/{r}/audit-log | requisitions.audit_log | RequisitionController | auth | admin.write |
| DELETE | /requisitions/{r} | requisitions.destroy | RequisitionController | auth | admin.write |
| GET | /requisitions/dispatch/{d}/edit-data | requisitions.dispatch_edit_data | RequisitionController | auth | admin.write |
| PUT | /requisitions/dispatch/{d} | requisitions.dispatch_update | RequisitionController | auth | admin.write |
| DELETE | /requisitions/dispatch/{d} | requisitions.dispatch_destroy | RequisitionController | auth | admin.write |
| GET | /api/requisition-items | requisitions.items_by_warehouse | RequisitionController | auth | throttle:120,1 |
| GET | /api/requisition-description-items | requisitions.description_items | RequisitionController | auth | throttle:120,1 |

## Stock Transfers
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /transfers | transfers.index | StockTransferController | auth | — |
| POST | /transfers | transfers.store | StockTransferController | auth | admin.create |
| GET | /transfers/{t} | transfers.show | StockTransferController | auth | — |
| GET | /transfers/{t}/print | transfers.print | StockTransferController | auth | — |
| GET | /transfers/{t}/dispatch | transfers.dispatch | StockTransferController | auth | — |
| POST | /transfers/{t}/dispatch | transfers.process_dispatch | StockTransferController | auth | admin.create |
| GET | /transfers/{t}/edit | transfers.edit | StockTransferController | auth | admin |
| PUT | /transfers/{t} | transfers.update | StockTransferController | auth | admin |
| DELETE | /transfers/{t} | transfers.destroy | StockTransferController | auth | admin |
| GET | /api/transfer-items | transfers.items_for_warehouse | StockTransferController | auth | throttle:120,1 |

## Reservations
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /reservations | reservations.index | ReservationController | auth | — |
| GET | /reservations/create | reservations.create | ReservationController | auth | admin.create |
| POST | /reservations | reservations.store | ReservationController | auth | admin.create |
| GET | /reservations/{r} | reservations.show | ReservationController | auth | — |
| POST | /reservations/{r}/approve | reservations.approve | ReservationController | auth | admin.write |
| POST | /reservations/{r}/ready | reservations.ready | ReservationController | auth | admin.write |
| POST | /reservations/{r}/cancel | reservations.cancel | ReservationController | auth | admin.write |
| GET | /reservations/items-by-warehouse | reservations.items_by_warehouse | ReservationController | auth | — |

## Stock Cards
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /stock-cards | stock_cards.home | StockCardController | auth | — |
| GET | /stock-cards/summary | stock_cards.summary | StockCardController | auth | — |
| GET | /stock-cards/{category} | stock_cards.index | StockCardController | auth | — |
| GET | /stock-cards/item/{item}/history | stock_cards.item_history | StockCardController | auth | — |
| GET | /stock-cards/item/{item}/history-by-cost | stock_cards.item_history_by_unit_cost | StockCardController | auth | — |
| GET | /stock-cards/item/{item}/print | stock_cards.print | StockCardController | auth | — |

## Reports
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /reports/rpci | rpci_report | ReportController | auth | — |
| GET | /reports/rpci/print | rpci_report.print | ReportController | auth | — |
| GET | /reports/rpci/export | rpci_report.export | ReportController | auth | — |
| POST | /reports/rpci/snapshot | rpci_report.snapshot | ReportController | auth | — |
| GET | /reports/rsmi | rsmi_report | ReportController | auth | — |
| GET | /reports/rsmi/print | rsmi_report.print | ReportController | auth | — |
| GET | /reports/rsmi/export | rsmi_report.export | ReportController | auth | — |
| POST | /reports/rsmi/snapshot | rsmi_report.snapshot | ReportController | auth | — |
| GET | /reports/inventory-balance | inventory_balance_report | ReportController | auth | — |
| GET | /reports/inventory-balance/export | inventory_balance_report.export | ReportController | auth | — |
| GET | /reports/snapshot/{snapshot} | reports.snapshot | ReportController | auth | — |

## Suppliers
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /suppliers | suppliers.index | SupplierController | auth | — |
| GET | /suppliers/create | suppliers.create | SupplierController | auth | admin.create |
| POST | /suppliers | suppliers.store | SupplierController | auth | admin.create |
| GET | /suppliers/{supplier}/edit | suppliers.edit | SupplierController | auth | admin.write |
| PUT | /suppliers/{supplier} | suppliers.update | SupplierController | auth | admin.write |
| PATCH | /suppliers/{supplier}/toggle | suppliers.toggle | SupplierController | auth | admin.write |

## Warehouses
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /warehouses | warehouses.index | WarehouseController | auth | — |
| GET | /warehouses/create | warehouses.create | WarehouseController | auth | admin.create |
| POST | /warehouses | warehouses.store | WarehouseController | auth | admin.create |
| GET | /warehouses/{warehouse}/edit | warehouses.edit | WarehouseController | auth | admin.write |
| PUT | /warehouses/{warehouse} | warehouses.update | WarehouseController | auth | admin.write |

## Users
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /users | users.index | UserController | auth | admin.only.strict |
| GET | /users/create | users.create | UserController | auth | admin.write |
| POST | /users | users.store | UserController | auth | admin.write |
| GET | /users/{user}/edit | users.edit | UserController | auth | admin.only.strict |
| PUT | /users/{user} | users.update | UserController | auth | admin.write |
| GET | /api/check-username | users.check_username | UserController | auth | throttle:120,1 |

## Item Categories
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /item-categories | item_categories.index | ItemCategoryController | auth | admin.only.strict |
| POST | /item-categories | item_categories.store | ItemCategoryController | auth | admin.only.strict |
| PUT | /item-categories/{itemCategory} | item_categories.update | ItemCategoryController | auth | admin.only.strict |
| DELETE | /item-categories/{itemCategory} | item_categories.destroy | ItemCategoryController | auth | admin.only.strict |
| PATCH | /item-categories/{itemCategory}/toggle | item_categories.toggle | ItemCategoryController | auth | admin.only.strict |
| POST | /item-categories/catalog-items | item_catalog_items.store | ItemCatalogItemController | auth | admin.only.strict |
| PUT | /item-categories/catalog-items/{catalogItem} | item_catalog_items.update | ItemCatalogItemController | auth | admin.only.strict |
| DELETE | /item-categories/catalog-items/{catalogItem} | item_catalog_items.destroy | ItemCatalogItemController | auth | admin.only.strict |

## Notifications
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /notifications | notifications.index | NotificationController | auth | — |
| POST | /notifications/read-all | notifications.read_all | NotificationController | auth | — |
| POST | /notifications/{notification}/read | notifications.read | NotificationController | auth | — |
| POST | /notifications/{notification}/read-ajax | notifications.read_ajax | NotificationController | auth | — |
| GET | /api/notifications/unread | notifications.unread | NotificationController | auth | throttle:120,1 |

## API Helpers
| Method | URL | Name | Controller | Auth | Middleware |
|--------|-----|------|------------|------|------------|
| GET | /api/check-dr | ds.check_number | Closure | auth | throttle:120,1 |
| GET | /api/item-stock-card | item.stock_card_lookup | Closure | auth | throttle:120,1 |
