<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DeliverySubsidyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\StockCardController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\WarehouseController;
use App\Models\Item;
use App\Models\DeliverySubsidy;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth routes (no route-level throttle — handled in AuthController with RateLimiter)
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ── Item Categories (admin only) ──────────────────────────────────────────
    Route::middleware(['admin.only.strict'])->group(function () {
        Route::get('/item-categories',                          [ItemCategoryController::class, 'index'])->name('item_categories.index');
        Route::post('/item-categories',                         [ItemCategoryController::class, 'store'])->name('item_categories.store');
        Route::put('/item-categories/{itemCategory}',           [ItemCategoryController::class, 'update'])->name('item_categories.update');
        Route::delete('/item-categories/{itemCategory}',        [ItemCategoryController::class, 'destroy'])->name('item_categories.destroy');
        Route::patch('/item-categories/{itemCategory}/toggle',  [ItemCategoryController::class, 'toggleActive'])->name('item_categories.toggle');
    });

    // ── Items ─────────────────────────────────────────────────────────────────
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    // NOTE: /create must be before /{item} to avoid wildcard capture
    Route::middleware('admin.create')->group(function () {
        Route::get('/items/create',  [ItemController::class, 'create'])->name('items.create');
        Route::post('/items',        [ItemController::class, 'store'])->name('items.store');
    });
    Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
    // Edit / Delete — admin only
    Route::middleware('admin.write')->group(function () {
        Route::get('/items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
        Route::put('/items/{item}',      [ItemController::class, 'update'])->name('items.update');
        Route::delete('/items/{item}',   [ItemController::class, 'destroy'])->name('items.destroy');
    });

    // ── Delivery / Subsidies ──────────────────────────────────────────────────
    Route::get('/delivery-subsidies',                                  [DeliverySubsidyController::class, 'index'])->name('delivery_subsidies.index');
    Route::get('/delivery-subsidies/{deliverySubsidy}/delivery',       [DeliverySubsidyController::class, 'delivery'])->name('delivery_subsidies.delivery');
    // Create + record delivery — admin + warehouse manager
    // NOTE: /create must be registered BEFORE /{deliverySubsidy} to avoid wildcard capture
    Route::middleware('admin.create')->group(function () {
        Route::get('/delivery-subsidies/create',                                    [DeliverySubsidyController::class, 'create'])->name('delivery_subsidies.create');
        Route::post('/delivery-subsidies',                                           [DeliverySubsidyController::class, 'store'])->name('delivery_subsidies.store');
        Route::post('/delivery-subsidies/{deliverySubsidy}/delivery',               [DeliverySubsidyController::class, 'storeDelivery'])->name('delivery_subsidies.store_delivery');
    });
    Route::get('/delivery-subsidies/{deliverySubsidy}',                [DeliverySubsidyController::class, 'show'])->name('delivery_subsidies.show');
    // Edit / Delete — admin only
    Route::middleware('admin.write')->group(function () {
        Route::get('/delivery-subsidies/{deliverySubsidy}/edit',                    [DeliverySubsidyController::class, 'edit'])->name('delivery_subsidies.edit');
        Route::put('/delivery-subsidies/{deliverySubsidy}',                         [DeliverySubsidyController::class, 'update'])->name('delivery_subsidies.update');
        Route::delete('/delivery-subsidies/{deliverySubsidy}',                      [DeliverySubsidyController::class, 'destroy'])->name('delivery_subsidies.destroy');
        // Admin-only: edit/update individual delivery records + audit log
        Route::middleware('admin')->group(function () {
            Route::get('/delivery-subsidies/{deliverySubsidy}/deliveries/{delivery}/edit', [DeliverySubsidyController::class, 'editDelivery'])->name('delivery_subsidies.edit_delivery');
            Route::put('/delivery-subsidies/{deliverySubsidy}/deliveries/{delivery}',      [DeliverySubsidyController::class, 'updateDelivery'])->name('delivery_subsidies.update_delivery');
            Route::get('/delivery-subsidies/{deliverySubsidy}/audit-log',                  [DeliverySubsidyController::class, 'auditLog'])->name('delivery_subsidies.audit_log');
        });
    });

    // ── Requisitions ──────────────────────────────────────────────────────────
    Route::get('/requisitions',                                   [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('/requisitions/create',                            [RequisitionController::class, 'create'])->name('requisitions.create');
    Route::post('/requisitions',                                  [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('/requisitions/{requisition}',                     [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::get('/requisitions/{requisition}/approve',             [RequisitionController::class, 'approve'])->name('requisitions.approve');
    Route::post('/requisitions/{requisition}/approve',            [RequisitionController::class, 'processApproval'])->name('requisitions.process_approval');
    Route::get('/requisitions/{requisition}/signatories',         [RequisitionController::class, 'signatories'])->name('requisitions.signatories');
    Route::put('/requisitions/{requisition}/signatories',         [RequisitionController::class, 'updateSignatories'])->name('requisitions.update_signatories');
    Route::get('/requisitions/{requisition}/print',               [RequisitionController::class, 'printRis'])->name('requisitions.print');
    // Write routes — blocked for Warehouse Manager
    Route::middleware('admin.write')->group(function () {
        Route::get('/requisitions/{requisition}/edit',            [RequisitionController::class, 'edit'])->name('requisitions.edit');
        Route::put('/requisitions/{requisition}',                 [RequisitionController::class, 'update'])->name('requisitions.update');
    });
    // Delete — admin only (stricter than edit/update which allow any write-capable role)
    Route::middleware(['admin', 'admin.write'])->group(function () {
        Route::delete('/requisitions/{requisition}',              [RequisitionController::class, 'destroy'])->name('requisitions.destroy');
    });
    // API: items available in a warehouse (used by the RIS create/edit form)
    Route::get('/api/requisition-items', [RequisitionController::class, 'getItemsByWarehouse'])->name('requisitions.items_by_warehouse');

    // ── Stock Cards ───────────────────────────────────────────────────────────
    // Specific routes BEFORE the {category} wildcard
    Route::get('/stock-cards',                                    [StockCardController::class, 'summary'])->name('stock_cards.home');
    Route::get('/stock-cards/summary',                            [StockCardController::class, 'summary'])->name('stock_cards.summary');
    Route::get('/stock-cards/item/{item}/history',                [StockCardController::class, 'itemHistory'])->name('stock_cards.item_history');
    Route::get('/stock-cards/item/{item}/history-by-cost',        [StockCardController::class, 'itemHistoryByUnitCost'])->name('stock_cards.item_history_by_unit_cost');
    Route::get('/stock-cards/item/{item}/print',                  [StockCardController::class, 'printStockCard'])->name('stock_cards.print');
    Route::get('/stock-cards/{category}',                         [StockCardController::class, 'index'])->name('stock_cards.index');

    // ── Stock Transfers ───────────────────────────────────────────────────────
    Route::get('/transfers',                     [StockTransferController::class, 'index'])->name('transfers.index');
    Route::get('/transfers/{transfer}/print',    [StockTransferController::class, 'print'])->name('transfers.print');
    Route::get('/transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
    Route::get('/api/transfer-items',            [StockTransferController::class, 'itemsForWarehouse'])->name('transfers.items_for_warehouse');
    // Create + dispatch — admin + warehouse manager
    // NOTE: /create must be registered BEFORE /{transfer} to avoid wildcard capture
    Route::middleware('admin.create')->group(function () {
        Route::get('/transfers/create',               [StockTransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers',                     [StockTransferController::class, 'store'])->name('transfers.store');
        Route::post('/transfers/{transfer}/dispatch', [StockTransferController::class, 'processDispatch'])->name('transfers.process_dispatch');
    });
    Route::get('/transfers/{transfer}',          [StockTransferController::class, 'show'])->name('transfers.show');
    // Edit / Update / Delete — admin only
    Route::middleware('admin')->group(function () {
        Route::get('/transfers/{transfer}/edit', [StockTransferController::class, 'edit'])->name('transfers.edit');
        Route::put('/transfers/{transfer}',      [StockTransferController::class, 'update'])->name('transfers.update');
        Route::delete('/transfers/{transfer}',   [StockTransferController::class, 'destroy'])->name('transfers.destroy');
    });

    // ── Suppliers ─────────────────────────────────────────────────────────────
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    // Create — admin + warehouse manager
    Route::middleware('admin.create')->group(function () {
        Route::get('/suppliers/create',  [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('/suppliers',        [SupplierController::class, 'store'])->name('suppliers.store');
    });
    // Edit / Update / Toggle — admin only
    Route::middleware('admin.write')->group(function () {
        Route::get('/suppliers/{supplier}/edit',         [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::put('/suppliers/{supplier}',              [SupplierController::class, 'update'])->name('suppliers.update');
        Route::patch('/suppliers/{supplier}/toggle',     [SupplierController::class, 'toggleActive'])->name('suppliers.toggle');
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::get('/reports/rpci',                    [ReportController::class, 'rpci'])->name('rpci_report');
    Route::get('/reports/rpci/print',              [ReportController::class, 'printRpci'])->name('rpci_report.print');
    Route::get('/reports/rpci/export',             [ReportController::class, 'exportRpci'])->name('rpci_report.export');
    Route::post('/reports/rpci/snapshot',          [ReportController::class, 'saveRpciSnapshot'])->name('rpci_report.snapshot');
    Route::get('/reports/rsmi',                    [ReportController::class, 'rsmi'])->name('rsmi_report');
    Route::get('/reports/rsmi/print',              [ReportController::class, 'printRsmi'])->name('rsmi_report.print');
    Route::get('/reports/rsmi/export',             [ReportController::class, 'exportRsmi'])->name('rsmi_report.export');
    Route::post('/reports/rsmi/snapshot',          [ReportController::class, 'saveRsmiSnapshot'])->name('rsmi_report.snapshot');
    Route::get('/reports/inventory-balance',       [ReportController::class, 'inventoryBalance'])->name('inventory_balance_report');
    Route::get('/reports/inventory-balance/export',[ReportController::class, 'exportInventoryBalance'])->name('inventory_balance_report.export');
    Route::get('/reports/snapshot/{snapshot}',     [ReportController::class, 'viewSnapshot'])->name('reports.snapshot');

    // ── Warehouses ────────────────────────────────────────────────────────────
    // Create — admin + warehouse manager
    // NOTE: /create must be before /{warehouse} to avoid wildcard capture
    Route::middleware('admin.create')->group(function () {
        Route::get('/warehouses/create',  [WarehouseController::class, 'create'])->name('warehouses.create');
        Route::post('/warehouses',        [WarehouseController::class, 'store'])->name('warehouses.store');
    });
    // Edit / Update — admin only
    Route::middleware(['admin', 'admin.write'])->group(function () {
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
        Route::put('/warehouses/{warehouse}',      [WarehouseController::class, 'update'])->name('warehouses.update');
    });
    // Index accessible to all authenticated users with admin access
    Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');

    // ── Users ─────────────────────────────────────────────────────────────────
    // All user management is admin-only — warehouse managers cannot see or manage users
    Route::middleware(['admin', 'admin.write'])->group(function () {
        Route::get('/users/create',  [UserController::class, 'create'])->name('users.create');
        Route::post('/users',        [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}',  [UserController::class, 'update'])->name('users.update');
    });
    Route::middleware('admin.only.strict')->group(function () {
        Route::get('/users',              [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit',  [UserController::class, 'edit'])->name('users.edit');
    });

    // Username availability check
    Route::get('/api/check-username', [UserController::class, 'checkUsername'])->name('users.check_username');

    // ── API helpers ───────────────────────────────────────────────────────────
    Route::get('/api/check-dr', function (Request $req) {
        return response()->json(['exists' => DeliverySubsidy::where('dr_number', $req->string('dr_number'))->exists()]);
    })->name('ds.check_number');

    Route::get('/api/item-stock-card', function (Request $req) {
        $item = Item::find($req->integer('item_id'));
        if (! $item) {
            return response()->json(['found' => false, 'stock_number' => null, 'preview' => null]);
        }

        $cost = round((float) $req->input('unit_cost'), 2);
        $expiryDate = $req->input('expiration_date');
        if ($cost <= 0) {
            return response()->json(['found' => false, 'stock_number' => null, 'preview' => null]);
        }

        $query = Item::where('warehouse_id', $item->warehouse_id)
            ->where('description', $item->description)
            ->where('unit', $item->unit)
            ->where('category', $item->category)
            ->whereBetween('unit_cost', [$cost - 0.001, $cost + 0.001])
            ->whereNotNull('stock_number');

        if ($expiryDate) {
            $query->whereDate('expiration_date', $expiryDate);
        }

        $match = $query->first();

        if ($match) {
            return response()->json([
                'found'        => true,
                'stock_number' => $match->stock_number,
                'preview'      => $match->stock_number,
                'expiry_match' => true,
            ]);
        }

        $warehouse = Warehouse::find($item->warehouse_id);
        $preview   = Item::generateStockNumber($warehouse->code ?? 'XX', $item->category);

        return response()->json([
            'found'        => false,
            'stock_number' => null,
            'preview'      => $preview,
            'expiry_match' => false,
        ]);
    })->name('item.stock_card_lookup');

    // ── Notifications ─────────────────────────────────────────────────────────
    // read-all must come before the {notification} wildcard
    Route::get('/notifications',                                        [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all',                              [NotificationController::class, 'markAllRead'])->name('notifications.read_all');
    Route::post('/notifications/{notification}/read',                   [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/{notification}/read-ajax',              [NotificationController::class, 'markReadAjax'])->name('notifications.read_ajax');
    Route::get('/api/notifications/unread',                             [NotificationController::class, 'getUnread'])->name('notifications.unread');
});
