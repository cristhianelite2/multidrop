<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'options',
        'price',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function saleAmount(): ?float
    {
        return $this->price !== null ? (float) $this->price : null;
    }

    public function purchaseAmount(): ?float
    {
        if ($this->cost !== null && (float) $this->cost > 0) {
            return (float) $this->cost;
        }
        $fromOpt = data_get($this->options, 'purchase_price');
        if ($fromOpt !== null && $fromOpt !== '' && (float) $fromOpt > 0) {
            return (float) $fromOpt;
        }

        return null;
    }

    public function compareAmount(): ?float
    {
        $fromOpt = data_get($this->options, 'compare_at_price');
        if ($fromOpt !== null && $fromOpt !== '' && (float) $fromOpt > 0) {
            return (float) $fromOpt;
        }

        return null;
    }
}
