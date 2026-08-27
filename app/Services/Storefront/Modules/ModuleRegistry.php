<?php

namespace App\Services\Storefront\Modules;

class ModuleRegistry
{
    public const COMMERCIAL_TYPES = ['landing', 'catalog', 'product', 'cart', 'checkout'];

    public const OVERLAYS = ['roulette', 'social_proof', 'upsell', 'newsletter', 'atc_modal', 'cookies'];

    /** @var array<string, string|null> key => plugin flag (null = always) */
    public const CATALOG = [
        'header' => null,
        'footer' => null,
        'hero_star' => null,
        'product_grid' => null,
        'pdp' => null,
        'cart' => null,
        'checkout' => null,
        'static' => null,
        'urgency' => 'urgency',
        'upsell' => 'upsell',
        'cross_sell' => 'cross_sell',
        'roulette' => 'roulette',
        'social_proof' => 'social_proof',
        'newsletter' => 'newsletter',
        'atc_modal' => null,
        'cookies' => 'cookies',
    ];

    public function __construct(
        protected TwigStorefrontRenderer $twig
    ) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * @return list<string>
     */
    public function defaultLayout(string $pageType): array
    {
        return match ($pageType) {
            'landing' => ['header', 'urgency', 'hero_star', 'product_grid', 'footer'],
            'catalog' => ['header', 'product_grid', 'footer'],
            'product' => ['header', 'urgency', 'pdp', 'footer'],
            'cart' => ['header', 'cart', 'footer'],
            'checkout' => ['header', 'checkout', 'footer'],
            default => ['header', 'static', 'footer'],
        };
    }

    public function isCommercial(string $pageType): bool
    {
        return in_array($pageType, self::COMMERCIAL_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function pageUsesModules(array $page): bool
    {
        $mods = $page['modules'] ?? null;

        return is_array($mods) && $mods !== [];
    }

    /**
     * @param  mixed  $item
     * @return array{key: string, desktop: bool, mobile: bool}|null
     */
    public function normalizeEntry(mixed $item): ?array
    {
        if (is_string($item)) {
            $key = trim($item);
            if ($key === '' || ! array_key_exists($key, self::CATALOG)) {
                return null;
            }

            return ['key' => $key, 'desktop' => true, 'mobile' => true];
        }

        if (! is_array($item)) {
            return null;
        }

        $key = trim((string) ($item['key'] ?? ''));
        if ($key === '' || ! array_key_exists($key, self::CATALOG)) {
            return null;
        }

        return [
            'key' => $key,
            'desktop' => $this->flagOn($item['desktop'] ?? true),
            'mobile' => $this->flagOn($item['mobile'] ?? true),
        ];
    }

    /**
     * @param  list<mixed>|null  $modules
     * @return list<array{key: string, desktop: bool, mobile: bool}>
     */
    public function normalizeEntries(?array $modules): array
    {
        if (! is_array($modules) || $modules === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($modules as $item) {
            $entry = $this->normalizeEntry($item);
            if ($entry === null || isset($seen[$entry['key']])) {
                continue;
            }
            $seen[$entry['key']] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $modules
     * @return list<string>
     */
    public function keysOf(array $modules): array
    {
        return array_values(array_map(fn (array $e) => $e['key'], $this->normalizeEntries($modules)));
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array{key: string, desktop: bool, mobile: bool}>
     */
    public function entriesFor(array $page): array
    {
        $mods = $page['modules'] ?? null;
        if (! is_array($mods) || $mods === []) {
            return $this->normalizeEntries($this->defaultLayout((string) ($page['type'] ?? 'page')));
        }

        $entries = $this->normalizeEntries($mods);

        return $entries !== []
            ? $entries
            : $this->normalizeEntries($this->defaultLayout((string) ($page['type'] ?? 'page')));
    }

    /**
     * Visibilidad PC/móvil viene de General (payload.plugin_devices), no de la página.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, array{desktop?: bool, mobile?: bool}>|null  $pluginDevices
     */
    public function visibleOn(array $page, string $key, string $device, ?array $pluginDevices = null): bool
    {
        $plugin = self::CATALOG[$key] ?? null;
        if ($plugin === null) {
            return true;
        }
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        $flags = (is_array($pluginDevices) && is_array($pluginDevices[$plugin] ?? null))
            ? $pluginDevices[$plugin]
            : null;
        if ($flags === null) {
            return true;
        }

        return $device === 'mobile'
            ? (bool) ($flags['mobile'] ?? true)
            : (bool) ($flags['desktop'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyDeviceFlags(array $payload, string $visit): array
    {
        $payload['visit'] = $visit === 'mobile' ? 'mobile' : 'desktop';
        $mods = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];
        $devices = is_array($payload['plugin_devices'] ?? null) ? $payload['plugin_devices'] : [];
        foreach (self::CATALOG as $plugin) {
            if ($plugin === null) {
                continue;
            }
            if (! $this->pluginDeviceOn($plugin, $payload['visit'], $devices)) {
                $mods[$plugin] = false;
            }
        }
        $payload['modules'] = $mods;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $pluginDevices
     */
    public function pluginDeviceOn(string $plugin, string $device, array $pluginDevices): bool
    {
        $flags = (is_array($pluginDevices) && is_array($pluginDevices[$plugin] ?? null))
            ? $pluginDevices[$plugin]
            : null;
        if ($flags === null) {
            return true;
        }

        return $device === 'mobile'
            ? (bool) ($flags['mobile'] ?? true)
            : (bool) ($flags['desktop'] ?? true);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<string>
     */
    public function layoutFor(array $page, ?string $device = null): array
    {
        $mods = $page['modules'] ?? null;
        if (! is_array($mods) || $mods === []) {
            return $this->defaultLayout((string) ($page['type'] ?? 'page'));
        }
        $keys = $this->keysOf($mods);

        return $keys !== []
            ? $keys
            : $this->defaultLayout((string) ($page['type'] ?? 'page'));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonFor(string $key, RenderContext $ctx): array
    {
        $base = [
            'store' => $ctx->store(),
            'urls' => $ctx->urls(),
            'i18n' => $ctx->i18n(),
            'locale' => (string) ($ctx->payload['locale'] ?? 'es'),
            'locales' => array_values(array_filter(array_map('strval', $ctx->payload['locales'] ?? []))),
            'currency' => (string) ($ctx->payload['currency'] ?? ''),
            'currencies' => array_values(array_filter(array_map('strval', $ctx->payload['currencies'] ?? []))),
            'page' => $ctx->payload['page'] ?? $ctx->page,
        ];

        return match ($key) {
            'header' => $base + [
                'cart_count' => (int) ($ctx->cart()['count'] ?? 0),
                'logo' => $this->logoUrl($ctx),
                'nav_links' => $this->navLinks($ctx),
            ],
            'footer' => $base + [
                'logo' => $this->logoUrl($ctx),
                'nav_links' => $this->navLinks($ctx),
                'cookies_enabled' => $ctx->pluginOn('cookies'),
            ],
            'hero_star' => $base + [
                'star' => $ctx->star() ?? [],
                'hero_image' => $this->heroImage($ctx),
            ],
            'product_grid' => $base + [
                'products' => $ctx->products(),
                'title' => (($ctx->page['type'] ?? '') === 'catalog') ? 'Catálogo' : '',
                'featured_only' => ($ctx->page['type'] ?? '') === 'landing',
            ],
            'pdp' => $base + [
                'product' => $ctx->product() ?? [],
            ],
            'cart' => $base + [
                'cart' => $ctx->cart(),
            ],
            'checkout' => $base + [
                'cart' => $ctx->cart(),
                'checkout' => is_array($ctx->payload['checkout'] ?? null) ? $ctx->payload['checkout'] : [],
                'cross_sell' => is_array($ctx->payload['cross_sell'] ?? null) ? $ctx->payload['cross_sell'] : [],
                'newsletter' => is_array($ctx->payload['newsletter'] ?? null) ? $ctx->payload['newsletter'] : [],
                'shipping_countries' => is_array($ctx->payload['shipping_countries'] ?? null) ? $ctx->payload['shipping_countries'] : [],
            ],
            'static' => $base + [
                'body' => $ctx->staticHtml,
                'title' => (string) ($ctx->page['title'] ?? ''),
            ],
            'urgency' => $base + [
                'urgency' => is_array($ctx->payload['urgency'] ?? null) ? $ctx->payload['urgency'] : [],
                'star' => $ctx->star() ?? [],
            ],
            'upsell' => $base + [
                'upsell' => is_array($ctx->payload['upsell'] ?? null) ? $ctx->payload['upsell'] : [],
            ],
            'cross_sell' => $base + [
                'cross_sell' => is_array($ctx->payload['cross_sell'] ?? null) ? $ctx->payload['cross_sell'] : [],
            ],
            'roulette' => $base + [
                'roulette' => is_array($ctx->payload['roulette'] ?? null) ? $ctx->payload['roulette'] : [],
            ],
            'social_proof' => $base + [
                'social_proof' => is_array($ctx->payload['social_proof'] ?? null) ? $ctx->payload['social_proof'] : [],
                'star' => $ctx->star() ?? [],
            ],
            'newsletter' => $base + [
                'newsletter' => is_array($ctx->payload['newsletter'] ?? null) ? $ctx->payload['newsletter'] : [],
            ],
            'atc_modal' => $base,
            'cookies' => $base + [
                'cookies' => is_array($ctx->payload['cookies'] ?? null) ? $ctx->payload['cookies'] : [],
            ],
            default => $base,
        };
    }

    public function shouldRender(string $key, RenderContext $ctx): bool
    {
        $devices = is_array($ctx->payload['plugin_devices'] ?? null) ? $ctx->payload['plugin_devices'] : [];
        if (! $this->visibleOn($ctx->page, $key, $ctx->visit(), $devices)) {
            return false;
        }

        $plugin = self::CATALOG[$key] ?? null;
        if ($plugin === null) {
            return true;
        }

        return $ctx->pluginOn($plugin);
    }

    public function render(string $key, RenderContext $ctx): string
    {
        if (! array_key_exists($key, self::CATALOG) || ! $this->shouldRender($key, $ctx)) {
            return '';
        }

        $data = $this->jsonFor($key, $ctx);
        $html = trim($this->twig->renderFile($key.'.twig', $data));
        if ($html === '') {
            return '';
        }
        if (in_array($key, self::OVERLAYS, true)) {
            return $html;
        }

        return '<div id="md-module-'.$key.'" class="md-module md-module--'.$key.'" data-md-mod="'.$key.'">'.$html.'</div>';
    }

    /**
     * @param  list<string>|null  $layout
     */
    public function assemble(RenderContext $ctx, ?array $layout = null): string
    {
        $layout = $layout ?? $this->layoutFor($ctx->page, $ctx->visit());
        $parts = [];
        foreach ($layout as $key) {
            if (in_array($key, self::OVERLAYS, true)) {
                continue;
            }
            $html = $this->render($key, $ctx);
            if ($html !== '') {
                $parts[] = $html;
            }
        }
        foreach (self::OVERLAYS as $key) {
            $html = $this->render($key, $ctx);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  list<string>|null  $layout
     * @return list<array{key: string, plugin: ?string, enabled: bool, overlay: bool, json: array<string, mixed>, html: string}>
     */
    public function inspect(RenderContext $ctx, ?array $layout = null): array
    {
        $layout = $layout ?? $this->layoutFor($ctx->page, $ctx->visit());
        $seen = [];
        $rows = [];
        foreach (array_merge($layout, self::OVERLAYS) as $key) {
            if (isset($seen[$key]) || ! array_key_exists($key, self::CATALOG)) {
                continue;
            }
            $seen[$key] = true;
            $plugin = self::CATALOG[$key];
            $devices = is_array($ctx->payload['plugin_devices'] ?? null) ? $ctx->payload['plugin_devices'] : [];
            $enabled = $this->shouldRender($key, $ctx);
            $rows[] = [
                'key' => $key,
                'plugin' => $plugin,
                'enabled' => $enabled,
                'desktop' => $plugin ? $this->visibleOn($ctx->page, $key, 'desktop', $devices) : null,
                'mobile' => $plugin ? $this->visibleOn($ctx->page, $key, 'mobile', $devices) : null,
                'overlay' => in_array($key, self::OVERLAYS, true),
                'json' => $this->jsonFor($key, $ctx),
                'html' => $enabled ? $this->twig->renderFile($key.'.twig', $this->jsonFor($key, $ctx)) : '',
            ];
        }

        return $rows;
    }

    public function renderStaticBody(string $html, RenderContext $ctx): string
    {
        return $this->twig->renderString($html, [
            'store' => $ctx->store(),
            'urls' => $ctx->urls(),
            'page' => $ctx->page,
        ]);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function pageIsRenderable(array $page): bool
    {
        if ($this->pageUsesModules($page)) {
            return true;
        }

        return trim((string) ($page['html'] ?? '')) !== '';
    }

    public function platformCss(): string
    {
        $path = resource_path('css/storefront/modules/platform.css');

        return is_file($path)
            ? ((string) file_get_contents($path))."\n".$this->platformMobileCss()
            : $this->platformMobileCss();
    }

    protected function platformMobileCss(): string
    {
        $path = resource_path('css/storefront/modules/mobile.css');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    protected function logoUrl(RenderContext $ctx): string
    {
        return $this->assetUrlByHint($ctx, 'logo');
    }

    protected function heroImage(RenderContext $ctx): string
    {
        $star = $ctx->star() ?? [];
        $fromStar = trim((string) ($star['image'] ?? ''));
        if ($fromStar !== '') {
            return $fromStar;
        }

        return $this->assetUrlByHint($ctx, 'hero');
    }

    /**
     * @return list<array{title: string, url: string}>
     */
    protected function navLinks(RenderContext $ctx): array
    {
        $home = rtrim((string) ($ctx->urls()['home'] ?? ''), '/');
        $out = [];
        foreach ($ctx->design['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            if (($page['type'] ?? '') !== 'page') {
                continue;
            }
            if (($page['status'] ?? 'live') === 'draft') {
                continue;
            }
            $handle = trim((string) ($page['handle'] ?? ''));
            if ($handle === '' || in_array($handle, ['page', 'index', 'product', 'catalog', 'cart', 'checkout'], true)) {
                continue;
            }
            $title = (string) ($page['title'] ?? $handle);
            if (str_contains($title, '{{') || str_contains($title, '{%')) {
                continue;
            }
            $out[] = [
                'title' => $title,
                'url' => $home !== '' ? $home.'/pages/'.$handle : '#',
            ];
        }

        return $out;
    }

    protected function assetUrlByHint(RenderContext $ctx, string $hint): string
    {
        $hint = strtolower($hint);
        $pools = [
            is_array($ctx->payload['assets'] ?? null) ? $ctx->payload['assets'] : [],
            is_array($ctx->design['assets'] ?? null) ? $ctx->design['assets'] : [],
        ];
        foreach ($pools as $assets) {
            foreach ($assets as $asset) {
                if (! is_array($asset)) {
                    continue;
                }
                $hay = strtolower((string) ($asset['name'] ?? '').' '.(string) ($asset['url'] ?? ''));
                if (str_contains($hay, $hint)) {
                    return (string) ($asset['url'] ?? '');
                }
            }
        }

        return '';
    }

    protected function flagOn(mixed $value): bool
    {
        if (is_array($value)) {
            $value = end($value);
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '' || $value === null) {
            return false;
        }

        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'on'
            || $value === 'true';
    }
}
