<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDesign extends Model
{
    protected $fillable = [
        'store_id',
        'theme_id',
        'name',
        'is_active',
        'design',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'design' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
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

    public function originLabel(): string
    {
        if ($this->theme) {
            return 'Copia de «'.$this->theme->name.'»';
        }

        return 'Personalización de esta tienda';
    }
}
