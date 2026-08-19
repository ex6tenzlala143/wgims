<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Warehouse;
use App\Models\Item;
use App\Models\Delivery;
use App\Models\Requisition;
use App\Models\StockTransfer;
use App\Models\Supplier;

echo "========================================\n";
echo "  WGIMS Cleanup Verification Report\n";
echo "========================================\n\n";

echo "PRESERVED DATA (Should have records):\n";
echo "-------------------------------------\n";

$users = User::all();
echo "✓ Users: " . $users->count() . " accounts\n";
foreach ($users as $user) {
    echo "  - {$user->username} ({$user->name}) - Role: {$user->role}\n";
}

$warehouses = Warehouse::all();
echo "\n✓ Warehouses: " . $warehouses->count() . " locations\n";
foreach ($warehouses as $warehouse) {
    echo "  - {$warehouse->code} - {$warehouse->name}\n";
}

echo "\n\nCLEANED DATA (Should be empty):\n";
echo "-------------------------------------\n";
echo "✓ Items: " . Item::count() . " records\n";
echo "✓ Deliveries: " . Delivery::count() . " records\n";
echo "✓ Requisitions: " . Requisition::count() . " records\n";
echo "✓ Stock Transfers: " . StockTransfer::count() . " records\n";
echo "✓ Suppliers: " . Supplier::count() . " records\n";

echo "\n========================================\n";
echo "  Verification Complete!\n";
echo "========================================\n";
