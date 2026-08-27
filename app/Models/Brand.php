<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tagline',
        'identity',
    ];

    protected function casts(): array
    {
        return [
            'identity' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
