<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferAuditLog extends Model
{
    protected $fillable = [
        'stock_transfer_id',
        'transfer_number',
        'user_id',
        'action',
        'changed_fields',
    ];

    protected $casts = [
        'changed_fields' => 'array',
    ];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
