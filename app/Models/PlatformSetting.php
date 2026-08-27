<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PlatformSetting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'is_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }

    public function setValueAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['value'] = null;

            return;
        }

        $this->attributes['value'] = ($this->attributes['is_secret'] ?? $this->is_secret)
            ? Crypt::encryptString((string) $value)
            : (string) $value;
    }

    public function getPlainValueAttribute(): ?string
    {
        $raw = $this->attributes['value'] ?? null;
        if ($raw === null) {
            return null;
        }

        if (! ($this->attributes['is_secret'] ?? false)) {
            return $raw;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        return $row->plain_value ?? $default;
    }

    public static function put(string $key, ?string $value, string $group = 'general', bool $secret = false): void
    {
        $row = static::query()->firstOrNew(['key' => $key]);
        $row->group = $group;
        $row->is_secret = $secret;

        if ($secret && ($value === null || $value === '') && $row->exists) {
            $row->save();

            return;
        }

        $row->value = $value;
        $row->save();
    }
}
