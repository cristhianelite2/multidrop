<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'store_id',
        'email',
        'source',
        'status',
        'confirm_token',
        'confirmed_at',
        'coupon_id',
        'coupon_code',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
