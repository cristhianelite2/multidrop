<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrossSellRule extends Model
{
    protected $fillable = [
        'store_id',
        'trigger_product_id',
        'offer_product_id',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function triggerProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'trigger_product_id');
    }

    public function offerProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'offer_product_id');
    }
}
