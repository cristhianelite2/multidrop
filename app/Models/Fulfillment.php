<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fulfillment extends Model
{
    protected $fillable = [
        'order_id',
        'supplier_id',
        'external_order_id',
        'status',
        'tracking_number',
        'carrier',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
