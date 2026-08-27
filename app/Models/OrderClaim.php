<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderClaim extends Model
{
    public const STATUSES = [
        'open' => 'Abierto',
        'in_progress' => 'En proceso',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
    ];

    protected $fillable = [
        'order_id',
        'buyer_account_id',
        'store_id',
        'subject',
        'body',
        'status',
        'admin_note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(BuyerAccount::class, 'buyer_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
