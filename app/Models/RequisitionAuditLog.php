<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionAuditLog extends Model
{
    protected $fillable = [
        'requisition_id',
        'user_id',
        'action',
        'changed_fields',
    ];

    protected $casts = [
        'changed_fields' => 'array',
    ];

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
