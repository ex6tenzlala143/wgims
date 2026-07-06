<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'username', 'name', 'email', 'password', 'role', 'warehouse_id', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['is_active' => 'boolean'];

    // Roles
    const ROLE_ADMIN = 'admin';
    const ROLE_WAREHOUSE_MANAGER = 'warehouse_manager';
    const ROLE_CUSTODIAN = 'supply_custodian';
    const ROLE_STAFF = 'center_staff';
    const ROLE_HEAD = 'center_head';

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * All warehouses this user is assigned to (many-to-many via pivot).
     * Use this for access-control checks and multi-warehouse features.
     */
    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse');
    }

    /**
     * Returns true if the user is assigned to the given warehouse,
     * checking both the legacy warehouse_id column and the pivot table.
     * Uses a targeted DB query instead of loading the full collection.
     */
    public function hasWarehouse(int $warehouseId): bool
    {
        if ($this->warehouse_id === $warehouseId) {
            return true;
        }

        return $this->warehouses()->where('warehouses.id', $warehouseId)->exists();
    }

    public function systemNotifications()
    {
        return $this->hasMany(SystemNotification::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isWarehouseManager(): bool
    {
        return $this->role === self::ROLE_WAREHOUSE_MANAGER;
    }

    /** True for roles that get admin-level view access (read-only for warehouse manager). */
    public function hasAdminAccess(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_WAREHOUSE_MANAGER]);
    }

    /** True only for the full admin who can write/delete. */
    public function canWrite(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** True for admin and warehouse manager — can create new records but not edit/delete. */
    public function canCreate(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_WAREHOUSE_MANAGER]);
    }

    // Alias so controllers referencing ->isCenterUser() keep working
    public function isCenterUser(): bool
    {
        return $this->isWarehouseUser();
    }

    public function isWarehouseUser(): bool
    {
        return in_array($this->role, [self::ROLE_CUSTODIAN, self::ROLE_STAFF, self::ROLE_HEAD]);
    }

    public function canApprove(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_WAREHOUSE_MANAGER, self::ROLE_HEAD, self::ROLE_CUSTODIAN]);
    }

    public function getRoleLabel(): string
    {
        return match($this->role) {
            self::ROLE_ADMIN             => 'Administrator',
            self::ROLE_WAREHOUSE_MANAGER => 'Warehouse Manager',
            self::ROLE_CUSTODIAN         => 'Supply Custodian',
            self::ROLE_STAFF             => 'Warehouse Staff',
            self::ROLE_HEAD              => 'Warehouse Head',
            default => ucfirst($this->role),
        };
    }

    public function getAuthIdentifierName(): string
    {
        return 'username';
    }
}
