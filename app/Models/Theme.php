<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Theme extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'preview_image',
        'source',
        'design',
        'created_from_store_id',
    ];

    protected function casts(): array
    {
        return [
            'design' => 'array',
        ];
    }

    public function createdFromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'created_from_store_id');
    }

    public function storeDesigns(): HasMany
    {
        return $this->hasMany(StoreDesign::class);
    }

    public function pagesCount(): int
    {
        $pages = data_get($this->design, 'pages', []);

        return is_array($pages) ? count($pages) : 0;
    }

    public function assetsCount(): int
    {
        $assets = data_get($this->design, 'assets', []);

        return is_array($assets) ? count($assets) : 0;
    }
}
