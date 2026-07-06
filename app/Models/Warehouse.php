<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['name', 'code', 'place', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * All users assigned to this warehouse via the pivot table.
     */
    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'user_warehouse');
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function deliverySubsidies()
    {
        return $this->hasMany(DeliverySubsidy::class);
    }

    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }
}
