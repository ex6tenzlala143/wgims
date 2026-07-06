<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySubsidyAuditLog extends Model
{
    protected $fillable = [
        'delivery_subsidy_id',
        'user_id',
        'action',
        'changed_fields',
        'cascade_summary',
    ];

    protected $casts = [
        'changed_fields'  => 'array',
        'cascade_summary' => 'array',
    ];

    public function deliverySubsidy()
    {
        return $this->belongsTo(DeliverySubsidy::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
