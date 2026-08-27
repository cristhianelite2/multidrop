<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Combo extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'name',
        'slug',
        'description',
        'images',
        'strategy',
        'qty_min',
        'discount_type',
        'discount_value',
        'is_active',
        'publish_as_product',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'qty_min' => 'integer',
            'discount_value' => 'decimal:2',
            'is_active' => 'boolean',
            'publish_as_product' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverImage(): ?string
    {
        $images = $this->images;
        if (is_array($images) && isset($images[0]) && is_string($images[0]) && trim($images[0]) !== '') {
            return trim($images[0]);
        }

        return $this->product?->image_url;
    }

    public static function uniqueSlug(int $storeId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'combo';
        $slug = $base;
        $i = 2;
        while (
            static::query()
                ->where('store_id', $storeId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
