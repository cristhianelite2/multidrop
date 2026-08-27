<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouletteSlide extends Model
{
    protected $fillable = [
        'store_id',
        'kicker',
        'title',
        'text',
        'cta_label',
        'cta_url',
        'image_url',
        'theme_class',
        'sort_order',
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
}
