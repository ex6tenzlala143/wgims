<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'address', 'tin', 'contact_person', 'phone', 'email', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function deliverySubsidies()
    {
        return $this->hasMany(DeliverySubsidy::class);
    }
}
