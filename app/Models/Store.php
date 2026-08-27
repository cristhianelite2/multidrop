<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Store extends Model
{
    protected $fillable = [
        'brand_id',
        'parent_id',
        'market_id',
        'name',
        'slug',
        'sector',
        'store_type',
        'status',
        'theme',
        'settings',
    ];

    /** Atributos solo en memoria (UI árbol); no existen como columnas. */
    protected array $runtimeOnly = [
        'tree_depth',
    ];

    /** @var array<string, mixed> */
    protected array $runtimeStash = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $store) {
            $store->runtimeStash = [];
            foreach ($store->runtimeOnly as $key) {
                if (array_key_exists($key, $store->attributes)) {
                    $store->runtimeStash[$key] = $store->attributes[$key];
                    unset($store->attributes[$key]);
                }
            }
        });

        static::saved(function (self $store) {
            foreach ($store->runtimeStash as $key => $value) {
                $store->attributes[$key] = $value;
            }
            $store->runtimeStash = [];
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function designs(): HasMany
    {
        return $this->hasMany(StoreDesign::class);
    }

    public function activeDesign(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StoreDesign::class)->where('is_active', true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function marketingCampaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class);
    }

    public function marketingPrompts(): HasMany
    {
        return $this->hasMany(MarketingPrompt::class);
    }

    public function marketingVideos(): HasMany
    {
        return $this->hasMany(MarketingVideo::class);
    }

    public function isMega(): bool
    {
        return $this->store_type === 'mega';
    }

    public function isMini(): bool
    {
        return $this->store_type === 'mini';
    }

    /**
     * Color de avatar en el admin (settings.identity.color).
     */
    public function identityColor(): string
    {
        $c = trim((string) data_get($this->settings, 'identity.color', ''));
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $c)) {
            return strtoupper($c);
        }

        return $this->isMega() ? '#0284C7' : '#0F766E';
    }

    /**
     * Icono corto (emoji o 1–2 letras). Vacío → iniciales del nombre.
     */
    public function identityIcon(): string
    {
        $icon = trim((string) data_get($this->settings, 'identity.icon', ''));
        if ($icon !== '') {
            return mb_substr($icon, 0, 4);
        }

        $name = trim((string) $this->name);
        if ($name === '') {
            return $this->isMega() ? 'MG' : 'MN';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    public function identityInk(): string
    {
        $hex = ltrim($this->identityColor(), '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luma > 0.62 ? '#0f172a' : '#ffffff';
    }

    /**
     * Locale configurado (sin override de vitrina).
     */
    public function configuredLocale(): string
    {
        $locale = trim((string) data_get($this->siteSettings(), 'default_locale', ''));
        if ($locale === '') {
            $locale = (string) ($this->market?->locale ?: 'es_MX');
        }

        return str_replace('-', '_', $locale);
    }

    public function localeFlagIso(): string
    {
        $locale = $this->configuredLocale();
        if (preg_match('/_([A-Za-z]{2})$/', $locale, $m)) {
            $iso = strtolower($m[1]);

            return $iso === 'uk' ? 'gb' : $iso;
        }
        $code = strtolower((string) ($this->market?->code ?? ''));

        return $code === 'uk' ? 'gb' : $code;
    }

    public function currencyFlagIso(): string
    {
        return \App\Services\Currency\CurrencyService::isoForCurrency($this->configuredCurrency());
    }

    /**
     * IDs de tiendas hijas (cualquier profundidad). Evita ciclos al mover.
     *
     * @param  Collection<int, self>|null  $all
     * @return list<int>
     */
    public function descendantIds(?Collection $all = null): array
    {
        $all = $all ?? static::query()->get(['id', 'parent_id']);
        $byParent = $all->groupBy(fn (self $s) => (int) ($s->parent_id ?? 0));
        $ids = [];
        $walk = function (int $pid) use (&$walk, $byParent, &$ids): void {
            $kids = $byParent->get($pid) ?? $byParent->get((string) $pid) ?? collect();
            foreach ($kids as $child) {
                $ids[] = (int) $child->id;
                $walk((int) $child->id);
            }
        };
        $walk((int) $this->id);

        return $ids;
    }

    /**
     * ID del producto estrella de la tienda (especialmente mini-tiendas).
     * Ancla promociones, combos, upsell, urgencia y prueba social.
     */
    public function starProductId(): ?int
    {
        $id = (int) data_get($this->settings, 'star_product_id', 0);

        return $id > 0 ? $id : null;
    }

    public function starProduct(): ?Product
    {
        $id = $this->starProductId();
        if ($id) {
            $product = Product::query()
                ->where('store_id', $this->id)
                ->where('id', $id)
                ->whereIn('status', ['live', 'draft', 'paused'])
                ->first();
            if ($product) {
                return $product;
            }
        }

        // Fallback: destacado → primer live/draft
        return Product::query()
            ->where('store_id', $this->id)
            ->whereIn('status', ['live', 'draft'])
            ->orderByDesc('is_featured')
            ->orderBy('id')
            ->first();
    }

    public function setStarProductId(?int $productId): void
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        if ($productId && $productId > 0) {
            $owns = Product::query()
                ->where('store_id', $this->id)
                ->where('id', $productId)
                ->exists();
            if (! $owns) {
                return;
            }
            $settings['star_product_id'] = $productId;
            Product::query()
                ->where('store_id', $this->id)
                ->where('id', $productId)
                ->update(['is_featured' => true]);
        } else {
            unset($settings['star_product_id']);
        }
        $this->settings = $settings;
        $this->save();
    }

    public function isStarProduct(int|Product $product): bool
    {
        $id = $product instanceof Product ? (int) $product->id : (int) $product;
        $starId = $this->starProductId();
        if ($starId) {
            return $starId === $id;
        }
        $fallback = $this->starProduct();

        return $fallback && (int) $fallback->id === $id;
    }

    public function depth(): int
    {
        $depth = 0;
        $node = $this->parent;
        while ($node && $depth < 20) {
            $depth++;
            $node = $node->relationLoaded('parent') ? $node->parent : $node->parent()->first();
        }

        return $depth;
    }

    public function breadcrumbLabel(): string
    {
        $parts = [$this->name];
        $node = $this->parent;
        $guard = 0;
        while ($node && $guard < 20) {
            array_unshift($parts, $node->name);
            $node = $node->relationLoaded('parent') ? $node->parent : $node->parent()->first();
            $guard++;
        }

        return implode(' › ', $parts);
    }

    /**
     * Orden jerárquico: padres antes que hijos, preservando anidación mini→mini.
     */
    public static function flattenTree(Collection $stores): Collection
    {
        $byParent = $stores->groupBy(fn (self $s) => $s->parent_id ?? 0);
        $flat = collect();

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, $flat): void {
            $children = ($byParent[$parentId] ?? collect())->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($children as $store) {
                $store->setAttribute('tree_depth', $depth);
                $flat->push($store);
                $walk($store->id, $depth + 1);
            }
        };

        $roots = ($byParent[0] ?? collect())
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->sortBy(fn (self $s) => $s->isMega() ? 0 : 1);

        foreach ($roots as $store) {
            $store->setAttribute('tree_depth', 0);
            $flat->push($store);
            $walk($store->id, 1);
        }

        return $flat->values();
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeMini($query)
    {
        return $query->where('store_type', 'mini');
    }

    public function scopeMega($query)
    {
        return $query->where('store_type', 'mega');
    }

    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function serviceEnabled(string $key): bool
    {
        $catalog = config('multidrop.services', []);
        if (! isset($catalog[$key])) {
            return false;
        }
        $default = (bool) ($catalog[$key]['default'] ?? true);
        $raw = data_get($this->settings, 'services.'.$key);

        return $raw === null ? $default : (bool) $raw;
    }

    public function pluginEnabled(string $key): bool
    {
        $flags = $this->pluginDeviceFlags($key);

        return $flags['desktop'] || $flags['mobile'];
    }

    /**
     * Visibilidad del plugin en PC y en móvil (General de la tienda).
     *
     * @return array{desktop: bool, mobile: bool}
     */
    public function pluginDeviceFlags(string $key): array
    {
        $catalog = config('multidrop.plugins', []);
        if (! isset($catalog[$key])) {
            return ['desktop' => false, 'mobile' => false];
        }
        $defaultOn = (bool) ($catalog[$key]['default'] ?? true);
        $devices = data_get($this->settings, 'plugin_devices.'.$key);
        if (is_array($devices)) {
            return [
                'desktop' => $this->settingTruthy($devices['desktop'] ?? true),
                'mobile' => $this->settingTruthy($devices['mobile'] ?? true),
            ];
        }

        $raw = data_get($this->settings, 'plugins.'.$key);
        $on = $raw === null ? $defaultOn : $this->settingTruthy($raw);

        return ['desktop' => $on, 'mobile' => $on];
    }

    /**
     * @return array<string, array{desktop: bool, mobile: bool}>
     */
    public function pluginDevices(): array
    {
        $out = [];
        foreach (array_keys(config('multidrop.plugins', [])) as $key) {
            $out[$key] = $this->pluginDeviceFlags($key);
        }

        return $out;
    }

    public function pluginVisibleOn(string $key, string $device): bool
    {
        $flags = $this->pluginDeviceFlags($key);

        return $device === 'mobile' ? $flags['mobile'] : $flags['desktop'];
    }

    protected function settingTruthy(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw !== 0;
        }
        if (is_string($raw)) {
            return ! in_array(strtolower(trim($raw)), ['0', 'false', 'off', 'no', ''], true);
        }

        return (bool) $raw;
    }

    /**
     * @return array<string, bool>
     */
    public function serviceFlags(): array
    {
        $out = [];
        foreach (array_keys(config('multidrop.services', [])) as $key) {
            $out[$key] = $this->serviceEnabled($key);
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    public function pluginFlags(): array
    {
        $out = [];
        foreach (array_keys(config('multidrop.plugins', [])) as $key) {
            $out[$key] = $this->pluginEnabled($key);
        }

        return $out;
    }

    public function commerceEnabled(): bool
    {
        return $this->serviceEnabled('commerce');
    }

    public function paymentsEnabled(): bool
    {
        return (bool) data_get($this->settings, 'payments.enabled', false);
    }

    public function paymentGateway(): ?string
    {
        if (! $this->paymentsEnabled()) {
            return null;
        }

        $gateway = data_get($this->settings, 'payments.gateway');

        return is_string($gateway) && $gateway !== '' ? $gateway : null;
    }

    public function designSettings(): array
    {
        $design = data_get($this->settings, 'design', []);

        return is_array($design) ? $design : [];
    }

    public function checkoutColors(): array
    {
        $checkout = data_get($this->designSettings(), 'checkout', []);

        return array_merge([
            'primary' => '#0f766e',
            'accent' => '#f59e0b',
            'button' => '#0f766e',
            'bg' => '#ffffff',
            'text' => '#0f172a',
        ], is_array($checkout) ? $checkout : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function siteSettings(): array
    {
        $site = data_get($this->settings, 'site', []);

        return is_array($site) ? $site : [];
    }

    public function defaultLocale(): string
    {
        if (app()->bound('storefront.locale_override')) {
            $override = trim((string) app('storefront.locale_override'));
            if ($override !== '') {
                return $override;
            }
        }

        $locale = (string) data_get($this->siteSettings(), 'default_locale', '');
        if ($locale !== '') {
            return $locale;
        }

        return (string) ($this->market?->locale ?: 'es_MX');
    }

    /**
     * Idiomas habilitados en la tienda (incluye siempre el default).
     *
     * @return list<string>
     */
    public function enabledLocales(): array
    {
        $raw = data_get($this->siteSettings(), 'locales', []);
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $loc) {
                $loc = trim((string) $loc);
                if ($loc !== '') {
                    $list[] = $loc;
                }
            }
        }
        $default = $this->defaultLocale();
        if ($default !== '' && ! in_array($default, $list, true)) {
            array_unshift($list, $default);
        }

        return array_values(array_unique($list));
    }

    public function currency(): string
    {
        if (app()->bound('storefront.currency_override')) {
            $override = strtoupper(trim((string) app('storefront.currency_override')));
            if (preg_match('/^[A-Z]{3}$/', $override)) {
                return $override;
            }
        }

        return $this->configuredCurrency();
    }

    /**
     * Moneda configurada de la tienda (sin override del visitante).
     * Cupones fixed / magic fixed / min_subtotal se definen en esta moneda.
     */
    public function configuredCurrency(): string
    {
        $code = strtoupper((string) data_get($this->siteSettings(), 'currency', ''));
        if ($code === '') {
            $code = strtoupper((string) data_get($this->siteSettings(), 'default_currency', ''));
        }
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        return strtoupper((string) ($this->market?->currency ?: 'MXN'));
    }

    /**
     * Monedas compatibles de la vitrina (incluye siempre la default).
     *
     * @return list<string>
     */
    public function enabledCurrencies(): array
    {
        $raw = data_get($this->siteSettings(), 'currencies', []);
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $code) {
                $code = strtoupper(trim((string) $code));
                if (preg_match('/^[A-Z]{3}$/', $code)) {
                    $list[] = $code;
                }
            }
        }
        $default = $this->configuredCurrency();
        if ($default !== '' && ! in_array($default, $list, true)) {
            array_unshift($list, $default);
        }

        return array_values(array_unique($list));
    }

    public function publicUrl(): string
    {
        $url = trim((string) data_get($this->siteSettings(), 'public_url', ''));
        if ($url !== '') {
            return $url;
        }

        $domain = $this->relationLoaded('domains')
            ? $this->domains->firstWhere('is_primary', true) ?? $this->domains->first()
            : ($this->domains()->where('is_primary', true)->first() ?: $this->domains()->first());

        if ($domain && $domain->host) {
            $scheme = str_starts_with($domain->host, 'localhost') || str_starts_with($domain->host, '127.')
                ? 'http'
                : 'https';
            $base = $scheme.'://'.$domain->host;
            $prefix = trim((string) $domain->path_prefix, '/');

            return $prefix !== '' ? $base.'/'.$prefix : $base;
        }

        return url('/s/'.$this->slug);
    }

    /**
     * @return list<string>
     */
    public function displayCountries(): array
    {
        $countries = data_get($this->siteSettings(), 'countries', []);
        if (! is_array($countries) || $countries === []) {
            $code = strtoupper((string) ($this->market?->code ?? ''));

            return $code !== '' ? [$code] : [];
        }

        return array_values(array_unique(array_map(
            fn ($c) => strtoupper((string) $c),
            $countries
        )));
    }

    public function servesCountry(?string $countryCode): bool
    {
        $code = strtoupper((string) $countryCode);
        if ($code === '') {
            return true;
        }
        if ($code === 'UK') {
            $code = 'GB';
        }

        $allowed = $this->displayCountries();
        if ($allowed === []) {
            return true;
        }

        return in_array($code, $allowed, true);
    }

    /**
     * Ganancia sobre el flete CJ / tabla. Default 10%.
     */
    public function shippingMarkupPercent(): float
    {
        $raw = data_get($this->settings, 'shipping.markup_percent');
        if ($raw === null || $raw === '') {
            return 10.0;
        }

        return max(0, min(100, (float) $raw));
    }

    public function catalogPerPage(): int
    {
        $allowed = [8, 12, 16, 24, 36, 48];
        $n = (int) data_get($this->siteSettings(), 'catalog_per_page', 12);

        return in_array($n, $allowed, true) ? $n : 12;
    }

    /**
     * @return array{title: ?string, description: ?string, og_image: ?string}
     */
    public function seoMeta(): array
    {
        $seo = data_get($this->settings, 'seo', []);
        if (! is_array($seo)) {
            $seo = [];
        }

        return [
            'title' => $this->firstSeo((string) ($seo['title'] ?? ''), (string) $this->name),
            'description' => $this->firstSeo((string) ($seo['description'] ?? ''), (string) ($this->tagline ?? '')),
            'og_image' => $this->firstSeo((string) ($seo['og_image'] ?? ''), (string) data_get($this->siteSettings(), 'og_image', '')),
        ];
    }

    protected function firstSeo(string $preferred, string $fallback): ?string
    {
        $preferred = trim($preferred);
        if ($preferred !== '') {
            return $preferred;
        }
        $fallback = trim($fallback);

        return $fallback !== '' ? $fallback : null;
    }
}
