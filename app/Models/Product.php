<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'collection_id',
        'sku',
        'name',
        'slug',
        'image_url',
        'description',
        'price',
        'compare_at_price',
        'purchase_price',
        'currency',
        'status',
        'badge',
        'stock',
        'is_featured',
        'verified_data',
        'creative_data',
        'score',
        'score_band',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'is_featured' => 'boolean',
            'verified_data' => 'array',
            'creative_data' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Precio de compra según marketplace (verified_data), en moneda del producto.
     */
    public function marketplacePurchasePrice(): ?float
    {
        $verified = is_array($this->verified_data) ? $this->verified_data : [];
        $currency = strtoupper((string) ($this->currency ?: 'MXN'));
        $fx = app(\App\Services\Currency\CurrencyService::class);

        if ($this->isFromCj()) {
            $pricing = is_array($verified['pricing'] ?? null) ? $verified['pricing'] : [];
            $costUsd = data_get($pricing, 'cost_usd') ?? data_get($verified, 'cost_usd');
            if ($costUsd !== null && (float) $costUsd > 0) {
                return $fx->roundAmount($fx->convert((float) $costUsd, 'USD', $currency, false), $currency);
            }
        }

        if ($this->isFromAliExpress()) {
            $price = data_get($verified, 'price');
            if ($price !== null && (float) $price > 0) {
                $srcCur = strtoupper((string) (data_get($verified, 'currency') ?: 'USD'));

                return $fx->roundAmount($fx->convert((float) $price, $srcCur, $currency, false), $currency);
            }
        }

        return null;
    }

    public function isFromCj(): bool
    {
        return data_get($this->verified_data, 'source') === 'cj'
            && (string) data_get($this->verified_data, 'cj_pid', '') !== '';
    }

    public function cjPid(): ?string
    {
        $pid = (string) data_get($this->verified_data, 'cj_pid', '');

        return $pid !== '' ? $pid : null;
    }

    public function isFromAliExpress(): bool
    {
        $source = (string) data_get($this->verified_data, 'source', '');

        return in_array($source, ['aliexpress', 'aliexpress_es'], true)
            && (string) data_get($this->verified_data, 'aliexpress_product_id', '') !== '';
    }

    public function aliexpressProductId(): ?string
    {
        $id = (string) data_get($this->verified_data, 'aliexpress_product_id', '');

        return $id !== '' ? $id : null;
    }

    /**
     * @return array<string, array{name?: string, description?: string, badge?: string}>
     */
    public function translations(): array
    {
        $list = data_get($this->creative_data, 'translations', []);

        return is_array($list) ? $list : [];
    }

    public function translation(string $locale): array
    {
        $all = $this->translations();
        $row = $all[$locale] ?? [];

        return is_array($row) ? $row : [];
    }

    public function localizedName(?string $locale = null): string
    {
        $locale = $locale ?: (string) data_get($this->creative_data, 'default_locale', '');
        if ($locale !== '') {
            $name = trim((string) ($this->translation($locale)['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return (string) $this->name;
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        $locale = $locale ?: (string) data_get($this->creative_data, 'default_locale', '');
        if ($locale !== '') {
            $desc = trim((string) ($this->translation($locale)['description'] ?? ''));
            if ($desc !== '') {
                return $desc;
            }
        }

        return $this->description;
    }

    /**
     * Reseñas importadas (CJ u otras fuentes) desde verified_data.
     *
     * @return list<array<string, mixed>>
     */
    public function reviews(): array
    {
        $list = data_get($this->verified_data, 'reviews', []);
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['images'] = $this->normalizeReviewImages($row);
            $out[] = $row;
        }

        return $out;
    }

    public function reviewCount(): int
    {
        $count = data_get($this->verified_data, 'review_count');
        if ($count !== null && $count !== '') {
            return (int) $count;
        }

        return count($this->reviews());
    }

    /**
     * Comentarios de compradores (texto y/o fotos) importados desde CJ.
     *
     * @return list<array<string, mixed>>
     */
    public function comments(): array
    {
        $list = data_get($this->verified_data, 'comments', []);
        if (! is_array($list) || $list === []) {
            $list = array_values(array_filter(
                $this->reviews(),
                fn ($r) => trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images'])
            ));
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['images'] = $this->normalizeReviewImages($row);
            $out[] = $row;
        }

        return $out;
    }

    public function commentCount(): int
    {
        $count = data_get($this->verified_data, 'comment_count');
        if ($count !== null && $count !== '') {
            return (int) $count;
        }

        return count($this->comments());
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    public function details(): array
    {
        $list = data_get($this->verified_data, 'details', []);
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name !== '' && $value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function normalizeReviewImages(array $row): array
    {
        $raw = $row['images'] ?? $row['commentUrls'] ?? $row['comment_urls'] ?? $row['photos'] ?? $row['pics'] ?? $row['commentImage'] ?? [];
        $urls = [];
        $push = function ($value) use (&$urls, &$push) {
            if (is_string($value)) {
                $trim = trim($value);
                if ($trim === '') {
                    return;
                }
                if (str_starts_with($trim, '[')) {
                    $decoded = json_decode($trim, true);
                    if (is_array($decoded)) {
                        $push($decoded);

                        return;
                    }
                }
                if (preg_match('#https?://#', $trim) && (str_contains($trim, ',') || str_contains($trim, '|') || str_contains($trim, ';'))) {
                    foreach (preg_split('/[,|;]+/', $trim) ?: [] as $part) {
                        $push(trim($part));
                    }

                    return;
                }
                if (str_starts_with($trim, '//')) {
                    $trim = 'https:'.$trim;
                }
                if (str_starts_with($trim, 'http://') || str_starts_with($trim, 'https://')) {
                    $urls[] = $trim;
                }

                return;
            }
            if (is_array($value)) {
                foreach (['url', 'image', 'src', 'img'] as $key) {
                    if (! empty($value[$key])) {
                        $push($value[$key]);
                    }
                }
                foreach ($value as $item) {
                    if (is_array($item) || is_string($item)) {
                        $push($item);
                    }
                }
            }
        };
        $push($raw);

        $unique = [];
        $seen = [];
        foreach ($urls as $url) {
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $url;
        }

        return $unique;
    }

    /**
     * Galería completa (producto + variantes).
     *
     * @return list<string>
     */
    public function galleryImages(): array
    {
        $urls = [];
        $main = trim((string) ($this->image_url ?? ''));
        if ($main !== '') {
            $urls[] = $main;
        }
        $fromVerified = data_get($this->verified_data, 'images', []);
        if (is_array($fromVerified)) {
            foreach ($fromVerified as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $urls[] = trim($url);
                }
            }
        }
        foreach ($this->variants as $variant) {
            $img = trim((string) data_get($variant->options, 'image', ''));
            if ($img !== '') {
                $urls[] = $img;
            }
        }
        $fromCreative = data_get($this->creative_data, 'images', []);
        if (is_array($fromCreative)) {
            foreach ($fromCreative as $url) {
                if (is_string($url) && trim($url) !== '') {
                    $urls[] = trim($url);
                }
            }
        }
        $unique = [];
        $seen = [];
        foreach ($urls as $url) {
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $url;
        }

        return $unique;
    }

    public function ratingAvg(): ?float
    {
        $avg = data_get($this->verified_data, 'rating_avg');
        if ($avg === null || $avg === '') {
            $reviews = $this->reviews();
            $scores = [];
            foreach ($reviews as $r) {
                $s = (int) ($r['score'] ?? 0);
                if ($s >= 1 && $s <= 5) {
                    $scores[] = $s;
                }
            }
            if ($scores === []) {
                return null;
            }

            return round(array_sum($scores) / count($scores), 2);
        }

        return round((float) $avg, 2);
    }

    /**
     * Precios por moneda (fijos y/o con redondeo propio).
     *
     * @return array<string, array{price: float, compare_at_price: float|null, locked: bool, rounding: ?string}>
     */
    public function currencyPrices(): array
    {
        $list = data_get($this->creative_data, 'prices', []);
        if (! is_array($list)) {
            return [];
        }

        $allowed = array_keys(\App\Services\Currency\CurrencyService::ROUNDING_MODES);
        $out = [];
        foreach ($list as $code => $row) {
            $code = strtoupper((string) $code);
            if (! preg_match('/^[A-Z]{3}$/', $code) || ! is_array($row)) {
                continue;
            }
            $price = (float) ($row['price'] ?? 0);
            $mode = (string) ($row['rounding'] ?? '');
            if (! in_array($mode, $allowed, true)) {
                $mode = '';
            }
            if ($price <= 0 && $mode === '') {
                continue;
            }
            $compare = $row['compare_at_price'] ?? null;
            $out[$code] = [
                'price' => $price > 0 ? round($price, 2) : 0.0,
                'compare_at_price' => ($compare !== null && $compare !== '' && (float) $compare > 0)
                    ? round((float) $compare, 2)
                    : null,
                'locked' => $price > 0 && filter_var($row['locked'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'rounding' => $mode !== '' ? $mode : null,
            ];
        }

        return $out;
    }

    public function roundingFor(string $currency): string
    {
        $code = strtoupper(trim($currency));
        $saved = $this->currencyPrices()[$code]['rounding'] ?? null;
        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        return app(\App\Services\Currency\CurrencyService::class)->roundingFor($code);
    }

    /**
     * Precio a mostrar/cobrar en una moneda: override manual o FX + redondeo de plataforma.
     *
     * @return array{currency: string, price: float, compare_at_price: float|null, from: string, converted: bool, source: string}
     */
    public function quoteIn(string $currency, ?\App\Services\Currency\CurrencyService $fx = null): array
    {
        $fx = $fx ?? app(\App\Services\Currency\CurrencyService::class);
        $to = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $to)) {
            $to = strtoupper((string) ($this->currency ?: $fx->base()));
        }

        $overrides = $this->currencyPrices();
        $isCombo = (bool) data_get($this->creative_data, 'is_combo', false);
        if (! $isCombo && isset($overrides[$to]) && (float) ($overrides[$to]['price'] ?? 0) > 0) {
            return [
                'currency' => $to,
                'price' => (float) $overrides[$to]['price'],
                'compare_at_price' => $overrides[$to]['compare_at_price'],
                'from' => strtoupper((string) ($this->currency ?: $to)),
                'converted' => strtoupper((string) ($this->currency ?: $to)) !== $to,
                'source' => 'manual',
            ];
        }

        $out = $fx->convertProductPrices($this, $to, $isCombo ? 'none' : $this->roundingFor($to));
        $out['source'] = ! empty($out['converted']) ? 'fx' : 'base';

        return $out;
    }

    public function formattedPriceIn(string $currency, ?\App\Services\Currency\CurrencyService $fx = null): string
    {
        $q = $this->quoteIn($currency, $fx);

        return number_format($q['price'], 2).' '.$q['currency'];
    }

    public function lockCurrencyPrice(string $currency, float $price, ?float $compare = null): void
    {
        $fx = app(\App\Services\Currency\CurrencyService::class);
        $code = strtoupper($currency);
        if (! preg_match('/^[A-Z]{3}$/', $code) || $price <= 0) {
            return;
        }

        $map = $this->currencyPrices();
        $mode = $map[$code]['rounding'] ?? $fx->roundingFor($code);
        $map[$code] = [
            'price' => $fx->applyRounding($price, $mode),
            'compare_at_price' => ($compare !== null && $compare > 0) ? $fx->applyRounding($compare, $mode) : null,
            'locked' => true,
            'rounding' => $mode,
        ];
        $creative = is_array($this->creative_data) ? $this->creative_data : [];
        $creative['prices'] = $map;
        $this->creative_data = $creative;
    }
}
