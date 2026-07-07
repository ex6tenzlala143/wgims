<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSnapshot extends Model
{
    protected $fillable = ['report_type', 'warehouse_id', 'period_month', 'serial_number', 'data', 'created_by'];

    protected $casts = [
        'data' => 'array',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
