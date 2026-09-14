<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Warehouses
        $warehouses = [
            ['name' => 'DSWD Region X - Main Office', 'code' => 'RX-MO', 'place' => 'Cagayan de Oro City'],
            ['name' => 'Center for the Aged (CFA)', 'code' => 'CFA', 'place' => 'Cagayan de Oro City'],
            ['name' => 'Rehabilitation Center (RC)', 'code' => 'RC', 'place' => 'Cagayan de Oro City'],
            ['name' => 'Youth Center (YC)', 'code' => 'YC', 'place' => 'Cagayan de Oro City'],
        ];

        foreach ($warehouses as $w) {
            Warehouse::firstOrCreate(['code' => $w['code']], $w);
        }

        $mainWarehouse = Warehouse::where('code', 'RX-MO')->first();

        // Admin user
        User::firstOrCreate(['username' => 'admin'], [
            'name'      => 'System Administrator',
            'email'     => 'admin@wgims.com',
            'password'  => Hash::make('password123'),
            'role'      => 'admin',
            'is_active' => true,
        ]);

            // Warehouse users
            User::firstOrCreate(['username' => 'manager1'], [
                'name'         => 'Warehouse Manager',
                'email'        => 'manager@wgims.com',
                'password'     => Hash::make('Password@1'),
                'role'         => 'warehouse_manager',
                'warehouse_id' => $mainWarehouse->id,
                'is_active'    => true,
            ]);

            User::firstOrCreate(['username' => 'updater1'], [
                'name'         => 'Delivery Updater',
                'email'        => 'updater@wgims.com',
                'password'     => Hash::make('Password@1'),
                'role'         => 'delivery_updater',
                'warehouse_id' => $mainWarehouse->id,
                'is_active'    => true,
            ]);

        // Sample suppliers
        $suppliers = [
            ['name' => 'ABC Office Supplies', 'address' => 'Cagayan de Oro City', 'tin' => '123-456-789', 'contact_person' => 'Ana Reyes', 'phone' => '09171234567', 'email' => 'abc@supplier.com'],
            ['name' => 'XYZ Medical Supplies', 'address' => 'Cagayan de Oro City', 'tin' => '987-654-321', 'contact_person' => 'Ben Cruz', 'phone' => '09189876543', 'email' => 'xyz@supplier.com'],
        ];

        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(['name' => $s['name']], $s);
        }
    }
}
