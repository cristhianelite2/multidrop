<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    protected $fillable = [
        'code',
        'name',
        'region',
        'flag',
        'locale',
        'currency',
        'timezone',
        'tax_profile',
        'settings',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tax_profile' => 'array',
            'settings' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function regionLabel(): string
    {
        return match ($this->region) {
            'north_america' => 'América del Norte',
            'europe' => 'Europa',
            'oceania' => 'Oceanía',
            default => 'Otros',
        };
    }

    public function flagOrFallback(): string
    {
        if ($this->flag) {
            return $this->flag;
        }

        $iso = strtoupper($this->code === 'UK' ? 'GB' : $this->code);
        if (strlen($iso) !== 2) {
            return '🏳️';
        }

        $chars = str_split($iso);

        return mb_chr(0x1F1E6 + ord($chars[0]) - 65).mb_chr(0x1F1E6 + ord($chars[1]) - 65);
    }
}
