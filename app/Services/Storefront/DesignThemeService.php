<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StoreDesign;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Theme multi-pÃ¡gina Multidrop: pages tipadas + CSS/JS globales + prompt de diseÃ±o.
 */
class DesignThemeService
{
    public const PAGE_TYPES = [
        'landing' => 'Landing / Inicio',
        'catalog' => 'CatÃ¡logo',
        'product' => 'Producto (PDP)',
        'cart' => 'Carrito',
        'checkout' => 'Checkout',
        'page' => 'PÃ¡gina libre',
    ];

    public function defaults(): array
    {
        return [
            'enabled' => false,
            'prompt_notes' => '',
            'locale' => '',
            'default_locale' => '',
            'locales' => [],
            'currency' => '',
            'default_currency' => '',
            'currencies' => [],
            'global_css' => '',
            'modules_css' => '',
            'mobile_css' => '',
            'global_js' => '',
            'html' => '',
            'css' => '',
            'js' => '',
            'checkout' => [
                'primary' => '#0f766e',
                'accent' => '#f59e0b',
                'button' => '#0f766e',
                'bg' => '#ffffff',
                'text' => '#0f172a',
            ],
            'pages' => [],
            'assets' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeDesign(array $raw, string $fallbackName = 'Plantilla'): array
    {
        $design = array_merge($this->defaults(), $raw);
        $design['checkout'] = array_merge(
            $this->defaults()['checkout'],
            is_array($raw['checkout'] ?? null) ? $raw['checkout'] : []
        );

        $pages = is_array($design['pages'] ?? null) ? array_values($design['pages']) : [];
        $pages = array_values(array_filter($pages, fn ($p) => is_array($p) && ! empty($p['id'])));

        if ($pages === [] && trim((string) ($design['html'] ?? '')) !== '') {
            $pages[] = $this->makePage([
                'type' => 'landing',
                'handle' => 'index',
                'title' => 'Inicio',
                'html' => (string) $design['html'],
                'css' => (string) ($design['css'] ?? ''),
                'js' => (string) ($design['js'] ?? ''),
                'status' => 'live',
            ]);
            if (trim((string) ($design['css'] ?? '')) !== '' && trim((string) $design['global_css']) === '') {
                $design['global_css'] = (string) $design['css'];
            }
            if (trim((string) ($design['js'] ?? '')) !== '' && trim((string) $design['global_js']) === '') {
                $design['global_js'] = (string) $design['js'];
            }
        }

        if ($pages === []) {
            $pages[] = $this->makePage([
                'type' => 'landing',
                'handle' => 'index',
                'title' => 'Inicio',
                'html' => $this->starterHtml('landing', $fallbackName),
                'css' => '',
                'js' => '',
                'status' => 'draft',
            ]);
        }

        $design['pages'] = array_map(fn ($p) => $this->sanitizePage($p), $pages);
        $design['assets'] = is_array($design['assets'] ?? null) ? array_values($design['assets']) : [];

        $locales = [];
        if (is_array($design['locales'] ?? null)) {
            foreach ($design['locales'] as $loc) {
                $loc = trim((string) $loc);
                if ($loc !== '') {
                    $locales[] = $loc;
                }
            }
        }
        $defaultLocale = trim((string) ($design['default_locale'] ?? $design['locale'] ?? ''));
        if ($defaultLocale === '' && $locales !== []) {
            $defaultLocale = $locales[0];
        }
        if ($defaultLocale !== '' && ! in_array($defaultLocale, $locales, true)) {
            $locales[] = $defaultLocale;
        }
        $design['locales'] = array_values(array_unique($locales));
        $design['default_locale'] = $defaultLocale;
        $design['locale'] = $defaultLocale;

        $currencies = [];
        if (is_array($design['currencies'] ?? null)) {
            foreach ($design['currencies'] as $code) {
                $code = strtoupper(trim((string) $code));
                if (preg_match('/^[A-Z]{3}$/', $code)) {
                    $currencies[] = $code;
                }
            }
        }
        $defaultCurrency = strtoupper(trim((string) ($design['default_currency'] ?? $design['currency'] ?? '')));
        if ($defaultCurrency === '' && $currencies !== []) {
            $defaultCurrency = $currencies[0];
        }
        if ($defaultCurrency !== '' && preg_match('/^[A-Z]{3}$/', $defaultCurrency) && ! in_array($defaultCurrency, $currencies, true)) {
            $currencies[] = $defaultCurrency;
        }
        $design['currencies'] = array_values(array_unique($currencies));
        $design['default_currency'] = $defaultCurrency;
        $design['currency'] = $defaultCurrency;

        return $design;
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(Store $store): array
    {
        return $this->normalizeDesign($this->rawDesignForStore($store), $store->name);
    }

    /**
     * Reescribe hosts de /storage al request actual (artisan serve vs APP_URL).
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function forDisplay(array $design): array
    {
        $design['global_css'] = DesignAssetUrl::localize((string) ($design['global_css'] ?? ''));
        $design['modules_css'] = DesignAssetUrl::localize((string) ($design['modules_css'] ?? ''));
        $design['mobile_css'] = DesignAssetUrl::localize((string) ($design['mobile_css'] ?? ''));
        $design['global_js'] = DesignAssetUrl::localize((string) ($design['global_js'] ?? ''));

        $pages = [];
        foreach ($design['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $page['html'] = DesignAssetUrl::localize((string) ($page['html'] ?? ''));
            $page['css'] = DesignAssetUrl::localize((string) ($page['css'] ?? ''));
            $page['js'] = DesignAssetUrl::localize((string) ($page['js'] ?? ''));
            $pages[] = $page;
        }
        $design['pages'] = $pages;

        $assets = [];
        foreach ($design['assets'] ?? [] as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            if (! empty($asset['path'])) {
                $asset['url'] = DesignAssetUrl::fromPath((string) $asset['path']);
            } elseif (! empty($asset['url'])) {
                $asset['url'] = DesignAssetUrl::localize((string) $asset['url']);
            }
            $assets[] = $asset;
        }
        $design['assets'] = $assets;

        return $design;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawDesignForStore(Store $store): array
    {
        if (Schema::hasTable('store_designs')) {
            $active = StoreDesign::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->first();
            if ($active && is_array($active->design) && $active->design !== []) {
                return $active->design;
            }
        }

        $raw = data_get($store->settings, 'design', []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function save(Store $store, array $design, bool $syncStoreDesign = true): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $settings['design'] = $design;
        $store->settings = $settings;
        if (! empty($design['enabled'])) {
            $store->theme = 'custom_html';
        } elseif ($store->theme === 'custom_html') {
            $store->theme = 'default';
        }
        $store->save();

        if ($syncStoreDesign && Schema::hasTable('store_designs')) {
            $active = StoreDesign::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->first();
            if ($active) {
                $active->design = $design;
                $active->save();
            } else {
                StoreDesign::create([
                    'store_id' => $store->id,
                    'name' => 'DiseÃ±o actual',
                    'is_active' => true,
                    'design' => $design,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function makePage(array $input = []): array
    {
        $type = (string) ($input['type'] ?? 'page');
        if (! isset(self::PAGE_TYPES[$type])) {
            $type = 'page';
        }

        $handle = Str::slug((string) ($input['handle'] ?? $type));
        if ($handle === '') {
            $handle = $type === 'landing' ? 'index' : $type;
        }
        if ($type === 'landing') {
            $handle = 'index';
        }

        return $this->sanitizePage([
            'id' => (string) ($input['id'] ?? (string) Str::uuid()),
            'type' => $type,
            'handle' => $handle,
            'title' => (string) ($input['title'] ?? (self::PAGE_TYPES[$type] ?? 'PÃ¡gina')),
            'html' => (string) ($input['html'] ?? ''),
            'css' => (string) ($input['css'] ?? ''),
            'js' => (string) ($input['js'] ?? ''),
            'modules' => $input['modules'] ?? null,
            'status' => in_array(($input['status'] ?? 'draft'), ['draft', 'live'], true) ? $input['status'] : 'draft',
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public function sanitizePage(array $page): array
    {
        $type = (string) ($page['type'] ?? 'page');
        if (! isset(self::PAGE_TYPES[$type])) {
            $type = 'page';
        }
        $handle = Str::slug((string) ($page['handle'] ?? 'page')) ?: 'page';
        if ($type === 'landing') {
            $handle = 'index';
        }

        $modules = [];
        $registry = app(\App\Services\Storefront\Modules\ModuleRegistry::class);
        if (is_array($page['modules'] ?? null)) {
            $modules = $registry->keysOf($page['modules']);
        }
        if ($modules === []) {
            $modules = $registry->defaultLayout($type);
        }

        return [
            'id' => (string) ($page['id'] ?? (string) Str::uuid()),
            'type' => $type,
            'handle' => $handle,
            'title' => $this->sanitizePageTitle((string) ($page['title'] ?? ''), (string) ($page['handle'] ?? '')),
            'html' => (string) ($page['html'] ?? ''),
            'css' => (string) ($page['css'] ?? ''),
            'js' => $this->neutralizeThemeCheckoutJs((string) ($page['js'] ?? '')),
            'modules' => $modules,
            'status' => $this->sanitizePageStatus($page, $type, $handle),
            'updated_at' => (string) ($page['updated_at'] ?? now()->toIso8601String()),
        ];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>|null
     */
    public function findPage(array $design, string $id): ?array
    {
        foreach ($design['pages'] ?? [] as $page) {
            if (is_array($page) && (string) ($page['id'] ?? '') === $id) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>|null
     */
    public function findPageByHandle(array $design, string $handle, bool $liveOnly = true): ?array
    {
        $handle = Str::slug($handle) ?: $handle;
        foreach ($design['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            if ((string) ($page['handle'] ?? '') !== $handle) {
                continue;
            }
            if ($liveOnly && ($page['status'] ?? '') !== 'live') {
                continue;
            }

            return $page;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>|null
     */
    public function findPageByType(array $design, string $type, bool $liveOnly = true): ?array
    {
        foreach ($design['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            if ((string) ($page['type'] ?? '') !== $type) {
                continue;
            }
            if ($liveOnly && ($page['status'] ?? '') !== 'live') {
                continue;
            }

            return $page;
        }

        return null;
    }

    /**
     * AÃ±ade hooks PDP (galerÃ­a, variantes, video, reseÃ±as, comentarios, desc) si faltan.
     * Los bloques sociales van antes del footer, no al final del documento.
     */
    public function ensurePdpHooksInHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = $this->stripGarbageDescriptionBinds($html);
        $html = preg_replace(
            '/\n*<section class="md-section"><h2>DescripciÃ³n<\/h2><div class="md-pdp-long" data-md-bind="product.description_long"><\/div><\/section>/u',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/\n*<section class="md-section"><h2>ReseÃ±as<\/h2><div data-md-reviews><\/div><\/section>/u',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/\n*<section class="md-section"><h2>Comentarios<\/h2><div data-md-comments><\/div><\/section>/u',
            '',
            $html
        ) ?? $html;

        $html = $this->placeVariantsHook($html);
        $html = $this->ensureMediaCarouselInHtml($html);

        $append = '';
        $hasLong = str_contains($html, 'data-md-bind="product.description_long"')
            || str_contains($html, 'data-md-bind="product.description_html"');
        if (! $hasLong) {
            $append .= "\n<section class=\"md-section md-container md-pdp-block\"><h2>DescripciÃ³n</h2><div class=\"md-pdp-long\" data-md-bind=\"product.description_long\"></div></section>";
        }
        if (! str_contains($html, 'data-md-reviews')) {
            $append .= "\n<section class=\"md-section md-container md-pdp-block\"><h2>ReseÃ±as</h2><div data-md-reviews></div></section>";
        }
        if (! str_contains($html, 'data-md-comments')) {
            $append .= "\n<section class=\"md-section md-container md-pdp-block\"><h2>Comentarios</h2><div data-md-comments></div></section>";
        }
        if ($append === '') {
            return $html;
        }

        return $this->insertBeforeFooter($html, $append."\n");
    }

    /**
     * Dos carruseles PDP: fotos (stage + thumbs) y videos aparte (player + thumbs).
     */
    public function ensureMediaCarouselInHtml(string $html): string
    {
        $html = preg_replace(
            '/(<p class="md-lede"[^>]*data-md-bind="(?:product\.)?description"[^>]*>)(.*?)(<\/p>)/is',
            '$1$3',
            $html
        ) ?? $html;

        $videoBlock = <<<'HTML'
        <div class="md-video-carousel" data-md-video-carousel hidden>
          <div class="md-video-carousel__head">Videos</div>
          <div class="md-video-carousel__stage">
            <video data-md-video-player controls playsinline preload="metadata"></video>
            <button type="button" class="md-video-carousel__nav md-video-carousel__prev" data-md-video-prev aria-label="Video anterior">â€¹</button>
            <button type="button" class="md-video-carousel__nav md-video-carousel__next" data-md-video-next aria-label="Video siguiente">â€º</button>
            <span class="md-video-carousel__count" data-md-video-count></span>
          </div>
          <div class="md-video-carousel__thumbs" data-md-video-thumbs></div>
        </div>
HTML;

        if (! str_contains($html, 'data-md-media-carousel')) {
            $replacement = <<<'HTML'
        <div class="md-media-carousel" data-md-media-carousel>
        <div class="md-product__main-media md-bracket md-media-carousel__stage">
          <img data-md-bind="image" data-md-gallery-main alt="">
        </div>
        <div class="md-product__thumbs md-media-carousel__thumbs" data-md-gallery data-md-gallery-thumbs></div>
        </div>
HTML;
            $pattern = '/<div class="md-product__main-media[^"]*"[\s\S]*?<div class="md-product__thumbs"[^>]*>\s*<\/div>\s*(?:<div[^>]*data-md-product-video[^>]*>[\s\S]*?<\/div>\s*)?/i';
            $next = preg_replace($pattern, $replacement, $html, 1);
            if (is_string($next) && $next !== $html) {
                $html = $next;
            }
        }

        // Plantillas tipo Axiom: un solo .md-product__media con <img>
        if (! str_contains($html, 'data-md-media-carousel')) {
            $replacementSimple = <<<'HTML'
        <div class="md-media-carousel md-product__media" data-md-media-carousel>
          <div class="md-media-carousel__stage">
            <img data-md-bind="product.image" data-md-gallery-main alt="" data-md-bind-attr="alt:product.name">
          </div>
          <div class="md-media-carousel__thumbs" data-md-gallery data-md-gallery-thumbs></div>
        </div>
HTML;
            $patternSimple = '/<div class="md-product__media">\s*<img[^>]*>\s*<\/div>/i';
            $next = preg_replace($patternSimple, $replacementSimple, $html, 1);
            if (is_string($next) && $next !== $html) {
                $html = $next;
            }
        }

        // Plantillas tipo Hogar: .md-pdp__gallery / .md-pdp__main
        if (! str_contains($html, 'data-md-media-carousel')) {
            $replacementPdp = <<<'HTML'
    <div class="md-pdp__gallery">
      <div class="md-media-carousel" data-md-media-carousel>
        <div class="md-media-carousel__stage md-pdp__main">
          <img data-md-bind="product.image" data-md-gallery-main alt="" data-md-bind-attr="alt:product.name">
        </div>
        <div class="md-media-carousel__thumbs md-pdp__thumbs" data-md-gallery data-md-gallery-thumbs></div>
      </div>
      <div class="md-video-carousel" data-md-video-carousel hidden>
        <div class="md-video-carousel__head">Videos</div>
        <div class="md-video-carousel__stage">
          <video data-md-video-player controls playsinline preload="metadata"></video>
          <button type="button" class="md-video-carousel__nav md-video-carousel__prev" data-md-video-prev aria-label="Video anterior">â€¹</button>
          <button type="button" class="md-video-carousel__nav md-video-carousel__next" data-md-video-next aria-label="Video siguiente">â€º</button>
          <span class="md-video-carousel__count" data-md-video-count></span>
        </div>
        <div class="md-video-carousel__thumbs" data-md-video-thumbs></div>
      </div>
    </div>
HTML;
            $patternPdp = '/<div class="md-pdp__gallery">[\s\S]*?<\/div>\s*(?=<div class="md-pdp__info")/i';
            $next = preg_replace($patternPdp, $replacementPdp."\n\n    ", $html, 1);
            if (is_string($next) && $next !== $html) {
                $html = $next;
            }
        }

        if (! str_contains($html, 'data-md-video-carousel')) {
            $injected = preg_replace(
                '/(<div[^>]*(?:data-md-gallery-thumbs|data-md-gallery)[^>]*>\s*<\/div>)/i',
                '$1'."\n".$videoBlock,
                $html,
                1
            );
            if (is_string($injected) && str_contains($injected, 'data-md-video-carousel')) {
                $html = $injected;
            }
        }

        return $html;
    }

    /**
     * Quita JSON/HTML escapado que el diseÃ±ador pegÃ³ dentro de data-md-bind de descripciÃ³n.
     */
    public function stripGarbageDescriptionBinds(string $html): string
    {
        $copy = app(ProductDescriptionHtml::class);

        return preg_replace_callback(
            '/<(p|div|span|article|section)(\b[^>]*\sdata-md-bind="(?:product\.)?description[^"]*"[^>]*)>(.*?)<\/\1>/is',
            function (array $m) use ($copy) {
                if (! $copy->isGarbageCopy($m[3])) {
                    return $m[0];
                }

                return '<'.$m[1].$m[2].'></'.$m[1].'>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Variantes fuera de la fila qty+comprar, con etiqueta de opciÃ³n.
     */
    public function placeVariantsHook(string $html): string
    {
        $html = preg_replace(
            '/<div class="md-product__variants">\s*<div class="md-product__variants-label">[\s\S]*?<\/div>\s*<div[^>]*data-md-variants[^>]*>\s*<\/div>\s*<\/div>/i',
            '',
            $html
        ) ?? $html;
        $html = preg_replace('/<div[^>]*data-md-variants[^>]*>\s*<\/div>/i', '', $html) ?? $html;

        $block = '<div class="md-product__variants">'."\n"
            .'          <div class="md-product__variants-label">Option Â· <strong data-md-variant-chosen></strong></div>'."\n"
            .'          <div data-md-variants></div>'."\n"
            .'        </div>'."\n        ";

        if (preg_match('/<div class="md-product__buybar">/', $html)) {
            return (string) preg_replace('/<div class="md-product__buybar">/', $block.'<div class="md-product__buybar">', $html, 1);
        }
        if (preg_match('/<div class="md-pdp__row">/', $html)) {
            return (string) preg_replace('/<div class="md-pdp__row">/', $block.'<div class="md-pdp__row">', $html, 1);
        }
        if (preg_match('/<div class="md-product__buy">/', $html)) {
            return (string) preg_replace('/<div class="md-product__buy">/', $block.'<div class="md-product__buy">', $html, 1);
        }
        if (preg_match('/<button[^>]*data-md-add-to-cart/i', $html)) {
            return (string) preg_replace('/(<button[^>]*data-md-add-to-cart)/i', $block.'$1', $html, 1);
        }

        return $html;
    }

    protected function insertBeforeFooter(string $html, string $block): string
    {
        if (preg_match('/<footer\b/i', $html)) {
            return (string) preg_replace('/<footer\b/i', $block.'<footer', $html, 1);
        }
        if (preg_match('/<[^>]*class="[^"]*\bmd-footer\b/i', $html)) {
            return (string) preg_replace('/(<[^>]*class="[^"]*\bmd-footer\b)/i', $block.'$1', $html, 1);
        }
        if (preg_match('/<\/main>/i', $html)) {
            return (string) preg_replace('/<\/main>/i', $block.'</main>', $html, 1);
        }

        return rtrim($html)."\n".$block;
    }

    /**
     * Actualiza pÃ¡ginas tipo product en un design array.
     *
     * @param  array<string, mixed>  $design
     * @return array{design: array<string, mixed>, changed: bool}
     */
    public function upgradeProductPages(array $design): array
    {
        $changed = false;
        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $i => $page) {
            if (! is_array($page) || ($page['type'] ?? '') !== 'product') {
                continue;
            }
            $original = (string) ($page['html'] ?? '');
            $originalJs = (string) ($page['js'] ?? '');
            $mods = is_array($page['modules'] ?? null) ? $page['modules'] : [];
            $usesPdpModule = in_array('pdp', app(\App\Services\Storefront\Modules\ModuleRegistry::class)->keysOf($mods), true);
            if ($usesPdpModule) {
                $next = '';
            } else {
                $html = preg_replace(
                    '/\n*<section class="md-section"><h2>DescripciÃ³n<\/h2><div class="md-pdp-long" data-md-bind="product.description_long"><\/div><\/section>/u',
                    '',
                    $original
                ) ?? $original;
                $next = $this->ensurePdpHooksInHtml($html);
            }
            $nextJs = $this->neutralizeThemeGalleryJs($originalJs);
            $nextJs = $this->neutralizeThemeCheckoutJs($nextJs);
            if ($next !== $original || $nextJs !== $originalJs) {
                $pages[$i]['html'] = $next;
                $pages[$i]['js'] = $nextJs;
                $changed = true;
            }
        }
        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $beforeJs = (string) ($pages[$i]['js'] ?? '');
            $beforeStatus = (string) ($pages[$i]['status'] ?? '');
            $beforeTitle = (string) ($pages[$i]['title'] ?? '');
            $sanitized = $this->sanitizePage($pages[$i]);
            $pages[$i]['js'] = $sanitized['js'];
            $pages[$i]['status'] = $sanitized['status'];
            $pages[$i]['title'] = $sanitized['title'];
            if (
                $pages[$i]['js'] !== $beforeJs
                || $pages[$i]['status'] !== $beforeStatus
                || $pages[$i]['title'] !== $beforeTitle
            ) {
                $changed = true;
            }
        }
        $design['pages'] = $pages;

        $globalJs = (string) ($design['global_js'] ?? '');
        $nextGlobal = $this->neutralizeThemeGalleryJs($globalJs);
        $nextGlobal = $this->neutralizeThemeCheckoutJs($nextGlobal);
        if ($nextGlobal !== $globalJs) {
            $design['global_js'] = $nextGlobal;
            $changed = true;
        }

        return ['design' => $design, 'changed' => $changed];
    }

    /**
     * El JS del theme no debe volver a pintar thumbs si el runtime ya montÃ³ el carrusel.
     */
    public function neutralizeThemeGalleryJs(string $js): string
    {
        if ($js === '' || str_contains($js, 'data-md-gallery-locked')) {
            return $js;
        }

        return str_replace(
            'if (product && thumbsWrap && mainImg) {',
            'if (product && thumbsWrap && mainImg && !thumbsWrap.hasAttribute(\'data-md-gallery-locked\')) {',
            $js
        );
    }

    /**
     * checkout.js del theme pintaba líneas con "Cantidad: N" y pisa syncCheckoutSummary().
     */
    public function neutralizeThemeCheckoutJs(string $js): string
    {
        $js = trim($js);
        if ($js === '' || str_contains($js, 'md-checkout-neutralized')) {
            return $js;
        }
        if (
            str_contains($js, 'function renderSummary')
            && str_contains($js, 'md-checkout-summary__line-qty')
        ) {
            return "/* md-checkout-neutralized: el resumen lo renderiza Multidrop (syncCheckoutSummary) */\n";
        }

        return $js;
    }

    protected function sanitizePageTitle(string $title, string $handle): string
    {
        $title = mb_substr(trim($title) ?: 'Página', 0, 120);
        if (str_contains($title, '{{') || str_contains($title, '{%')) {
            return Str::headline($handle !== '' && $handle !== 'page' ? $handle : 'Página');
        }

        return $title;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    protected function sanitizePageStatus(array $page, string $type, string $handle): string
    {
        $status = in_array(($page['status'] ?? 'draft'), ['draft', 'live'], true) ? $page['status'] : 'draft';
        if ($type === 'page' && $handle === 'page') {
            $rawTitle = trim((string) ($page['title'] ?? ''));
            $html = (string) ($page['html'] ?? '');
            if (
                str_contains($rawTitle, '{{')
                || str_contains($html, '{{page.title}}')
                || str_contains($html, '{{page.content}}')
            ) {
                return 'draft';
            }
        }

        return $status;
    }

    /**
     * @return array{themes: int, stores: int}
     */
    public function upgradeStoredProductPages(): array
    {
        $themes = 0;
        $stores = 0;
        foreach (\App\Models\Theme::query()->cursor() as $theme) {
            $raw = is_array($theme->design) ? $theme->design : [];
            $out = $this->upgradeProductPages($raw);
            if ($out['changed']) {
                $theme->design = $out['design'];
                $theme->save();
                $themes++;
            }
        }
        foreach (\App\Models\StoreDesign::query()->cursor() as $row) {
            $raw = is_array($row->design) ? $row->design : [];
            $out = $this->upgradeProductPages($raw);
            if ($out['changed']) {
                $row->design = $out['design'];
                $row->save();
                $stores++;
            }
        }

        return compact('themes', 'stores');
    }

    public function starterHtml(string $type, string $storeName = 'Tienda'): string
    {
        return match ($type) {
            'landing' => <<<'HTML'
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
  <nav>
    <a href="{{urls.home}}">Inicio</a>
    <a href="{{urls.catalog}}">CatÃ¡logo</a>
    <a href="{{urls.cart}}">Carrito</a>
  </nav>
</header>

<section class="md-hero" data-md-star-product>
  <h1>{{star.name}}</h1>
  <p class="md-price">{{star.price_formatted}}</p>
  <p>Producto estrella de {{store.name}}. El resto del catÃ¡logo apoya esta oferta.</p>
  <a class="md-btn" href="{{star.url}}">Ver producto estrella</a>
</section>

<section class="md-section">
  <h2>TambiÃ©n te puede gustar</h2>
  <div data-md-products data-md-featured data-md-limit="8" class="md-grid"></div>
</section>
HTML,
            'catalog' => <<<'HTML'
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
  <nav>
    <a href="{{urls.home}}">Inicio</a>
    <a href="{{urls.catalog}}">CatÃ¡logo</a>
    <a href="{{urls.cart}}">Carrito</a>
  </nav>
</header>

<section class="md-section">
  <h1>CatÃ¡logo</h1>
  <p>{{products.count}} productos</p>
  <div data-md-products class="md-grid"></div>
</section>
HTML,
            'product' => <<<'HTML'
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
  <nav>
    <a href="{{urls.catalog}}">CatÃ¡logo</a>
    <a href="{{urls.cart}}">Carrito</a>
  </nav>
</header>

<section class="md-section md-pdp" data-md-product>
  <div class="md-pdp-media md-media-carousel" data-md-media-carousel>
    <div class="md-media-carousel__stage">
      <img data-md-bind="product.image" data-md-gallery-main alt="">
    </div>
    <div data-md-gallery class="md-media-carousel__thumbs"></div>
  </div>
  <div class="md-video-carousel" data-md-video-carousel hidden>
    <div class="md-video-carousel__head">Videos</div>
    <div class="md-video-carousel__stage">
      <video data-md-video-player controls playsinline preload="metadata"></video>
    </div>
    <div class="md-video-carousel__thumbs" data-md-video-thumbs></div>
  </div>
  <div class="md-pdp-info">
    <p class="md-badge" data-md-bind="product.badge"></p>
    <h1 data-md-bind="product.name">Producto</h1>
    <p class="md-price" data-md-bind="product.price_formatted"></p>
    <p class="md-pdp-short" data-md-bind="product.description_short"></p>
    <div data-md-variants></div>
    <button type="button" class="md-btn" data-md-add-to-cart>Agregar al carrito</button>
  </div>
</section>

<section class="md-section">
  <h2>DescripciÃ³n</h2>
  <div class="md-pdp-long" data-md-bind="product.description_long"></div>
</section>

<section class="md-section">
  <h2>ReseÃ±as</h2>
  <div data-md-reviews></div>
</section>

<section class="md-section">
  <h2>Comentarios</h2>
  <div data-md-comments></div>
</section>
HTML,
            'cart' => <<<'HTML'
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
  <nav>
    <a href="{{urls.catalog}}">CatÃ¡logo</a>
    <a href="{{urls.cart}}">Carrito <span data-md-cart-count>0</span></a>
  </nav>
</header>
<section class="md-section">
  <h1>Carrito</h1>
  <div class="md-empty" data-md-cart-empty>
    <p>Tu carrito estÃ¡ vacÃ­o.</p>
    <a class="md-btn" href="{{urls.catalog}}">Ver catÃ¡logo</a>
  </div>
  <div data-md-cart class="md-cart md-hide">
    <div data-md-cart-items></div>
    <aside data-md-cart-summary>
      <p>Subtotal: <span data-md-cart-subtotal>$0.00</span></p>
      <p>Total: <strong data-md-cart-total>$0.00</strong></p>
      <a class="md-btn" href="{{urls.checkout}}" data-md-cart-checkout>Ir al checkout</a>
    </aside>
  </div>
</section>
HTML,
            'checkout' => <<<'HTML'
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
</header>
<section class="md-section md-checkout">
  <h1>Checkout</h1>
  <form data-md-checkout-form class="md-checkout-box" style="background:var(--md-checkout-bg);color:var(--md-checkout-text);border:1px solid #e2e8f0;border-radius:16px;padding:24px;display:grid;gap:10px;max-width:520px">
    <input name="name" placeholder="Nombre" required>
    <input name="email" type="email" placeholder="Email" required>
    <input name="phone" placeholder="TelÃ©fono">
    <input name="address" placeholder="DirecciÃ³n" required>
    <input name="city" placeholder="Ciudad" required>
    <input name="state" placeholder="Estado">
    <input name="zip" placeholder="CP">
    <input name="country" placeholder="PaÃ­s" value="MX">
    <div data-md-cart></div>
    <div data-md-coupon-form class="md-coupon"><input name="code" placeholder="CupÃ³n"><button type="button" data-md-coupon-apply class="md-btn">Aplicar</button></div>
    <p data-md-checkout-totals></p>
    <button type="submit" class="md-btn" style="background:var(--md-checkout-button);color:#fff;border:0;padding:12px 18px;border-radius:12px;">Pagar</button>
    <p data-md-checkout-msg></p>
  </form>
</section>
HTML,
            default => <<<HTML
<header class="md-nav">
  <a href="{{urls.home}}" class="md-logo">{{store.name}}</a>
</header>
<section class="md-section">
  <h1>PÃ¡gina</h1>
  <p>Contenido libre para {$storeName}.</p>
</section>
HTML,
        };
    }

    public function starterGlobalCss(): string
    {
        return <<<'CSS'
:root {
  --md-primary: #0f766e;
  --md-ink: #0f172a;
  --md-muted: #64748b;
  --md-line: #e2e8f0;
  --md-bg: #f8fafc;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, sans-serif; color: var(--md-ink); background: var(--md-bg); }
a { color: inherit; }
.md-nav { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:16px 20px; border-bottom:1px solid var(--md-line); background:#fff; }
.md-nav nav { display:flex; gap:14px; font-size:.9rem; }
.md-logo { font-weight:800; text-decoration:none; }
.md-hero { padding:56px 20px 32px; text-align:center; }
.md-hero h1 { margin:0 0 8px; font-size:2.2rem; }
.md-hero p { margin:0 0 18px; color:var(--md-muted); }
.md-section { width:min(1100px, calc(100% - 32px)); margin:0 auto 48px; }
.md-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
.md-card { background:#fff; border:1px solid var(--md-line); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:block; }
.md-card img { width:100%; aspect-ratio:1; object-fit:cover; background:#eee; }
.md-card .meta { padding:12px; }
.md-card h3 { margin:0 0 6px; font-size:.95rem; }
.md-card .price { font-weight:700; color:var(--md-primary); }
.md-btn { display:inline-block; background:var(--md-primary); color:#fff; text-decoration:none; padding:12px 18px; border-radius:12px; font-weight:700; border:0; cursor:pointer; }
.md-hide { display:none !important; }
.md-pdp { display:grid; grid-template-columns:1.1fr 1fr; gap:28px; align-items:start; }
.md-pdp-media img { width:100%; border-radius:18px; background:#eee; }
.md-pdp-short { color:var(--md-muted); line-height:1.5; }
.md-pdp-long { line-height:1.65; font-size:.95rem; }
.md-pdp-long h3 { margin:1.2em 0 .45em; font-size:1.02rem; }
.md-pdp-long ul, .md-pdp-long ol { margin:0 0 1em; padding-left:1.15em; }
.md-pdp-long li { margin:.35em 0; }
.md-pdp-long dl { display:grid; grid-template-columns:minmax(8rem,max-content) 1fr; gap:.35em 1rem; margin:0 0 1em; }
.md-pdp-long dt { color:var(--md-muted); font-weight:650; }
.md-pdp-long dd { margin:0; }
.md-pdp-long p { margin:0 0 .75em; }
.md-pdp-long img { display:none; }
.md-gallery { display:flex; flex-wrap:nowrap; gap:8px; margin-top:10px; overflow-x:auto; }
.md-gallery button { position:relative; flex:0 0 64px; width:64px; height:64px; padding:0; border:1px solid var(--md-line); border-radius:10px; overflow:hidden; background:var(--md-panel, #fff); cursor:pointer; }
.md-gallery button.is-active { outline:2px solid var(--md-primary); }
.md-gallery img { width:100%; height:100%; object-fit:cover; border-radius:0; }
.md-variants { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0; }
.md-variant { display:flex; align-items:center; gap:8px; border:1px solid var(--md-line); background:#fff; border-radius:12px; padding:6px 10px 6px 6px; cursor:pointer; }
.md-variant.is-selected { border-color:var(--md-primary); box-shadow:0 0 0 2px color-mix(in srgb, var(--md-primary) 25%, transparent); }
.md-variant img { width:36px; height:36px; object-fit:cover; border-radius:8px; }
.md-reviews, .md-comments { display:grid; gap:12px; }
.md-review, .md-comment {
  background: var(--md-panel, color-mix(in srgb, currentColor 8%, transparent));
  color: inherit;
  border: 1px solid var(--md-line, color-mix(in srgb, currentColor 14%, transparent));
  border-radius: var(--md-radius, 14px);
  padding: 14px 16px;
}
.md-review p, .md-comment p { margin:0; color: inherit; }
.md-review strong, .md-comment strong { color: inherit; }
.md-review__meta, .md-comment__meta { display:flex; flex-wrap:wrap; gap:8px; font-size:.85rem; color:var(--md-muted, #8D9797); margin-bottom:6px; }
.md-review__stars { color: var(--md-amber, #f59e0b); letter-spacing:1px; }
.md-review__photos, .md-comment__photos { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.md-review__photos a, .md-comment__photos a { display:block; cursor:zoom-in; border-radius:10px; overflow:hidden; }
.md-review__photos img, .md-comment__photos img { width:72px; height:72px; object-fit:cover; border-radius:10px; }
.md-photo-lightbox { position:fixed; inset:0; z-index:12000; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(15,23,42,.78); }
.md-photo-lightbox[hidden] { display:none !important; }
.md-photo-lightbox__inner { position:relative; max-width:min(960px,100%); max-height:min(90vh,100%); }
.md-photo-lightbox__img { display:block; max-width:100%; max-height:min(82vh,900px); margin:0 auto; border-radius:12px; object-fit:contain; background:#0f172a; }
.md-photo-lightbox__close, .md-photo-lightbox__nav { position:absolute; border:0; cursor:pointer; color:#fff; background:rgba(15,23,42,.72); border-radius:999px; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; }
.md-photo-lightbox__close { top:-12px; right:-12px; font-size:22px; }
.md-photo-lightbox__nav { top:50%; transform:translateY(-50%); font-size:28px; }
.md-photo-lightbox__nav[hidden] { display:none !important; }
.md-photo-lightbox__prev { left:-8px; }
.md-photo-lightbox__next { right:-8px; }
.md-photo-lightbox__counter { position:absolute; left:50%; bottom:-28px; transform:translateX(-50%); color:#fff; font-size:12px; opacity:.85; }
.md-price { font-size:1.4rem; font-weight:800; color:var(--md-primary); }
.md-price-row { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px 10px; margin:0 0 8px; }
.md-hero .md-price-row { margin:8px 0 16px; }
.md-card__price .md-price-row { margin:0; gap:6px; }
.md-card__price .md-price { font-size:1rem; }
.md-price-was { text-decoration:line-through; opacity:.55; font-weight:600; font-size:.78em; }
.md-price-save { display:inline-flex; font-size:.72em; font-weight:800; color:#fff; background:#dc2626; border-radius:999px; padding:2px 8px; }
@media (max-width:800px){ .md-pdp{ grid-template-columns:1fr; } }
CSS;
    }

    /**
     * CSS de mÃ³dulos de conversiÃ³n (usa tokens de theme.css / checkout).
     * Editable en DiseÃ±o â†’ pestaÃ±a MÃ³dulos.
     */
    public function starterModulesCss(): string
    {
        return <<<'CSS'
/* MÃ³dulos Multidrop â€” hereda --md-primary / --md-checkout-* del theme */
:root {
  --md-mod-primary: var(--md-primary, var(--md-checkout-primary, #0f766e));
  --md-mod-accent: var(--md-accent, var(--md-checkout-accent, #f59e0b));
  --md-mod-button: var(--md-checkout-button, var(--md-mod-primary));
  --md-mod-bg: var(--md-panel, var(--md-checkout-bg, #ffffff));
  --md-mod-text: var(--md-ink, var(--md-checkout-text, #0f172a));
  --md-mod-muted: var(--md-muted, #64748b);
  --md-mod-line: var(--md-line, #e2e8f0);
  --md-mod-radius: 14px;
  --md-mod-font: inherit;
}

.md-mod-bar {
  font-family: var(--md-mod-font);
  font-size: .82rem;
  line-height: 1.45;
  color: var(--md-mod-text, inherit);
  padding: 10px 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
}
.md-mod-urgency {
  background: color-mix(in srgb, var(--md-mod-accent) 82%, #000 18%);
  justify-content: center;
  text-align: center;
  color: var(--md-mod-text, inherit);
}
.md-mod-urgency [data-md-urgency-copy] {
  display: inline;
  width: auto;
  text-align: center;
}
.md-mod-roulette {
  /* legado barra â€” la ruleta real usa overlay */
  display: none !important;
}
.md-mod-roulette-fab-wrap {
  position: fixed;
  left: 16px;
  bottom: 16px;
  z-index: 99991;
  max-width: min(240px, calc(100vw - 32px));
  transition: bottom .25s ease;
}
.md-mod-roulette-fab {
  border: 0;
  cursor: pointer;
  font: 800 .78rem/1.1 inherit;
  letter-spacing: .02em;
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  background: linear-gradient(135deg, var(--md-mod-accent), var(--md-mod-primary));
  box-shadow:
    0 0 0 3px color-mix(in srgb, var(--md-mod-accent) 35%, transparent),
    0 10px 28px color-mix(in srgb, var(--md-mod-primary) 45%, transparent);
  animation: md-roulette-fab-pulse 1.6s ease-in-out infinite;
}
.md-mod-roulette-fab.md-hide { display: none !important; }
@keyframes md-roulette-fab-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}
.md-mod-roulette-won {
  background: linear-gradient(145deg, var(--md-mod-primary), color-mix(in srgb, var(--md-mod-accent) 70%, var(--md-mod-primary)));
  color: #fff;
  border-radius: 12px;
  padding: 7px 9px;
  box-shadow:
    0 0 0 3px color-mix(in srgb, var(--md-mod-accent) 30%, transparent),
    0 10px 24px color-mix(in srgb, var(--md-mod-primary) 40%, transparent);
  font: 600 .7rem/1.25 inherit;
}
.md-mod-roulette-won-kicker {
  opacity: .8;
  font-size: .58rem;
  text-transform: uppercase;
  letter-spacing: .03em;
  margin-bottom: 0;
  line-height: 1.2;
}
.md-mod-roulette-won strong {
  display: block;
  font-size: .8rem;
  font-weight: 800;
  margin-bottom: 4px;
  line-height: 1.15;
}
.md-mod-roulette-won-code-row {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 4px;
  flex-wrap: nowrap;
}
.md-mod-roulette-won-code-row code {
  flex: 1;
  min-width: 0;
  background: rgba(0,0,0,.22);
  border: 1px dashed rgba(255,255,255,.4);
  border-radius: 7px;
  padding: 4px 7px;
  font: 800 .72rem/1.1 ui-monospace, monospace;
  letter-spacing: .03em;
  overflow: hidden;
  text-overflow: ellipsis;
}
.md-mod-roulette-copy {
  border: 0;
  cursor: pointer;
  border-radius: 999px;
  padding: 4px 9px;
  font: 800 .65rem/1 inherit;
  color: #0f172a;
  background: #fff;
  white-space: nowrap;
}
.md-mod-roulette-copy.is-copied {
  background: #bbf7d0;
  color: #14532d;
}
.md-mod-roulette-won-timer {
  opacity: .9;
  font-size: .62rem;
  line-height: 1.2;
}
.md-mod-roulette-won-timer b {
  font-variant-numeric: tabular-nums;
}
.md-mod-roulette-miss {
  background: linear-gradient(145deg, #475569, #334155);
  color: #fff;
  border-radius: 12px;
  padding: 7px 9px;
  box-shadow:
    0 0 0 3px rgba(148, 163, 184, .3),
    0 10px 24px rgba(15, 23, 42, .32);
  font: 600 .7rem/1.25 inherit;
  text-align: center;
}
.md-mod-roulette-miss-face {
  font-size: 1.15rem;
  line-height: 1;
  margin-bottom: 2px;
}
.md-mod-roulette-miss strong {
  display: block;
  font-size: .78rem;
  font-weight: 800;
  margin-bottom: 6px;
  line-height: 1.2;
}
.md-mod-roulette-retry {
  border: 0;
  cursor: pointer;
  border-radius: 999px;
  padding: 5px 10px;
  font: 800 .65rem/1 inherit;
  color: #0f172a;
  background: #fff;
}
.md-mod-roulette-overlay {
  position: fixed;
  inset: 0;
  z-index: 100000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background:
    radial-gradient(ellipse at 50% 20%, color-mix(in srgb, var(--md-mod-accent) 35%, transparent), transparent 55%),
    rgba(2, 6, 23, .88);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  overflow: auto;
}
.md-mod-roulette-stage {
  width: min(520px, 100%);
  text-align: center;
  color: #fff;
  font-family: var(--md-mod-font);
  position: relative;
  padding: 12px 8px 24px;
}
.md-mod-roulette-stage h2 {
  margin: 0 0 6px;
  font-size: clamp(1.6rem, 5vw, 2.4rem);
  font-weight: 900;
  letter-spacing: -.02em;
  text-shadow: 0 4px 24px rgba(0,0,0,.45);
}
.md-mod-roulette-stage .md-mod-roulette-sub {
  margin: 0 0 18px;
  opacity: .85;
  font-size: .95rem;
}
.md-mod-roulette-x {
  position: absolute;
  top: 0;
  right: 0;
  border: 0;
  background: rgba(255,255,255,.12);
  color: #fff;
  width: 40px;
  height: 40px;
  border-radius: 999px;
  font-size: 22px;
  cursor: pointer;
  line-height: 1;
}
.md-mod-roulette-pointer {
  width: 0;
  height: 0;
  margin: 0 auto 6px;
  border-left: 14px solid transparent;
  border-right: 14px solid transparent;
  border-top: 28px solid #fff;
  filter: drop-shadow(0 4px 8px rgba(0,0,0,.4));
  position: relative;
  z-index: 2;
}
.md-mod-roulette-wheel-wrap {
  width: min(340px, 78vw);
  aspect-ratio: 1;
  margin: 0 auto 18px;
  border-radius: 50%;
  padding: 10px;
  background: linear-gradient(145deg, #fff, #cbd5e1);
  box-shadow:
    0 0 0 6px color-mix(in srgb, var(--md-mod-accent) 70%, #fff),
    0 0 60px color-mix(in srgb, var(--md-mod-primary) 55%, transparent),
    0 25px 50px rgba(0,0,0,.45);
  position: relative;
}
.md-mod-roulette-wheel {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  position: relative;
  overflow: hidden;
  transition: transform 4.8s cubic-bezier(0.12, 0.75, 0.08, 1);
  will-change: transform;
}
.md-mod-roulette-wheel.is-spinning {
  filter: saturate(1.2) brightness(1.05);
}
.md-mod-roulette-seg-label {
  position: absolute;
  width: max-content;
  max-width: 36%;
  margin: 0;
  padding: 0 2px;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  box-sizing: border-box;
  transform-origin: center center;
  z-index: 1;
}
.md-mod-roulette-seg-label span {
  display: block;
  width: 100%;
  max-width: 100%;
  text-align: center;
  font-size: clamp(.55rem, calc(2.6vw - .08rem * var(--md-roulette-n, 6)), .82rem);
  font-weight: 800;
  line-height: 1.12;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0,0,0,.55);
  overflow: hidden;
  word-break: break-word;
  hyphens: auto;
}
.md-mod-roulette-hub {
  position: absolute;
  inset: 42%;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 30%, #fff, #e2e8f0 55%, #94a3b8);
  box-shadow: inset 0 2px 6px rgba(255,255,255,.8), 0 4px 14px rgba(0,0,0,.35);
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
}
.md-mod-roulette-spin-btn {
  font-size: 1.05rem !important;
  padding: 16px 36px !important;
  border-radius: 999px !important;
  background: linear-gradient(135deg, var(--md-mod-accent), var(--md-mod-primary)) !important;
  box-shadow: 0 10px 30px color-mix(in srgb, var(--md-mod-accent) 50%, transparent);
  text-transform: uppercase;
  letter-spacing: .06em;
}
.md-mod-roulette-spin-btn:disabled {
  opacity: .65;
  cursor: wait;
}
.md-mod-roulette-result {
  margin-top: 16px;
  padding: 16px;
  border-radius: 18px;
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.25);
  animation: md-roulette-pop .45s ease;
}
.md-mod-roulette-result strong {
  display: block;
  font-size: 1.35rem;
  margin-bottom: 4px;
}
@keyframes md-roulette-pop {
  from { transform: scale(.85); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.md-mod-roulette-confetti {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
}
.md-mod-roulette-confetti i {
  position: absolute;
  top: -12px;
  width: 8px;
  height: 12px;
  border-radius: 2px;
  animation: md-roulette-fall 2.2s linear forwards;
}
@keyframes md-roulette-fall {
  to { transform: translateY(110vh) rotate(720deg); opacity: 0; }
}
.md-mod-cross {
  background: color-mix(in srgb, var(--md-mod-bg) 92%, var(--md-mod-primary) 8%);
  border-bottom: 1px solid var(--md-mod-line);
  padding: 14px;
  color: var(--md-mod-text);
  font-family: var(--md-mod-font);
}
.md-mod-cross-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
  font-size: .85rem;
}
.md-mod-cross-head .muted { color: var(--md-mod-muted); font-size: .75rem; }
.md-mod-cross-list { display: flex; gap: 10px; overflow: auto; padding-bottom: 4px; }
.md-mod-cross-card {
  min-width: 140px;
  max-width: 160px;
  flex: 0 0 auto;
  background: var(--md-mod-bg);
  border: 1px solid var(--md-mod-line);
  border-radius: var(--md-mod-radius);
  padding: 8px;
  color: var(--md-mod-text);
}
.md-mod-cross-card .name {
  font-weight: 600;
  font-size: .75rem;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.md-mod-cross-card .price {
  color: var(--md-mod-primary);
  font-weight: 700;
  font-size: .8rem;
}
.md-mod-cross-card .md-btn,
.md-mod-cross-card button {
  margin-top: 8px;
  width: 100%;
  border: 0;
  border-radius: 8px;
  padding: 6px;
  background: var(--md-mod-button);
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  font-size: .75rem;
}

/* Cross-sell mÃ¡gico compacto encima de Contact + Order summary */
.md-mod-cross-checkout {
  grid-column: 1 / -1;
  background: linear-gradient(120deg,
    color-mix(in srgb, var(--md-checkout-accent, var(--md-mod-primary)) 14%, var(--md-checkout-bg, var(--md-mod-bg))),
    var(--md-checkout-bg, var(--md-mod-bg)) 55%);
  border: 1px solid color-mix(in srgb, var(--md-checkout-primary, var(--md-mod-primary)) 35%, transparent);
  border-radius: 14px;
  padding: 10px 12px;
  color: var(--md-checkout-text, var(--md-mod-text));
  font-family: var(--md-mod-font);
  margin: 0;
}
.md-checkout-layout > .md-mod-cross-checkout { grid-column: 1 / -1; }
.md-mod-cross-checkout.is-expired {
  opacity: .72;
  filter: grayscale(.15);
}
.md-mod-cross-checkout__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.md-mod-cross-checkout__intro {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px 10px;
  min-width: 0;
  flex: 1;
}
.md-mod-cross-checkout__badge {
  display: inline-flex;
  align-items: center;
  font-size: .65rem;
  font-weight: 800;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #fff;
  background: var(--md-checkout-primary, var(--md-mod-primary));
  border-radius: 999px;
  padding: 3px 8px;
  line-height: 1.2;
}
.md-mod-cross-checkout__title {
  margin: 0;
  font-size: .92rem;
  font-weight: 800;
  line-height: 1.2;
}
.md-mod-cross-checkout__hint {
  font-size: .72rem;
  font-weight: 600;
  color: var(--md-checkout-accent, #b45309);
  opacity: .95;
}
.md-mod-cross-checkout__timer {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
  background: color-mix(in srgb, var(--md-checkout-text, #0f172a) 8%, transparent);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: .7rem;
  font-weight: 700;
}
.md-mod-cross-checkout__timer-label { opacity: .7; font-weight: 600; }
.md-mod-cross-checkout__timer b {
  font-variant-numeric: tabular-nums;
  font-size: .85rem;
  color: var(--md-checkout-primary, var(--md-mod-primary));
}
.md-mod-cross-checkout__timer.is-urgent b,
.md-mod-cross-checkout__timer.is-expired b { color: #b91c1c; }
.md-mod-cross-checkout__list {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 2px;
  scrollbar-width: thin;
}
.md-mod-cross-checkout__card {
  display: grid;
  grid-template-columns: 44px minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  min-width: min(280px, 86vw);
  max-width: 340px;
  flex: 0 0 auto;
  padding: 6px 8px;
  border-radius: 12px;
  background: var(--md-checkout-bg, var(--md-mod-bg));
  border: 1px solid color-mix(in srgb, var(--md-checkout-text, #0f172a) 10%, transparent);
}
.md-mod-cross-checkout__img {
  width: 44px;
  height: 44px;
  object-fit: cover;
  border-radius: 8px;
  background: color-mix(in srgb, var(--md-checkout-text, #0f172a) 8%, transparent);
}
.md-mod-cross-checkout__img--ph { display: block; }
.md-mod-cross-checkout__meta { min-width: 0; }
.md-mod-cross-checkout__name {
  font-size: .72rem;
  font-weight: 700;
  line-height: 1.25;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.md-mod-cross-checkout__prices {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: baseline;
}
.md-mod-cross-checkout__was {
  font-size: .65rem;
  text-decoration: line-through;
  opacity: .5;
}
.md-mod-cross-checkout__now {
  font-size: .82rem;
  font-weight: 800;
  color: var(--md-checkout-primary, var(--md-mod-primary));
}
.md-mod-cross-checkout__save {
  font-size: .62rem;
  font-weight: 800;
  color: #fff;
  background: var(--md-checkout-accent, #b45309);
  border-radius: 999px;
  padding: 1px 6px;
}
.md-mod-cross-checkout__cta {
  border: 0;
  border-radius: 9px;
  padding: 8px 10px;
  background: var(--md-checkout-button, var(--md-mod-button));
  color: #fff;
  font-weight: 800;
  font-size: .68rem;
  cursor: pointer;
  white-space: nowrap;
  line-height: 1.1;
}
.md-mod-cross-checkout__cta:disabled { opacity: .55; cursor: not-allowed; }
.md-mod-cross-checkout__empty {
  margin: 0;
  font-size: .75rem;
  opacity: .75;
}
.md-checkout-summary__row--magic {
  color: var(--md-checkout-accent, #b45309);
  font-weight: 700;
}
.md-checkout-summary__line {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 12px 0;
  border-bottom: 1px solid var(--md-mod-line, #e2e8f0);
}
.md-checkout-summary__line img,
.md-checkout-summary__line-ph {
  width: 44px;
  height: 44px;
  object-fit: cover;
  flex-shrink: 0;
  border-radius: 6px;
  background: color-mix(in srgb, var(--md-mod-line, #e2e8f0) 50%, transparent);
}
.md-checkout-summary__line-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.md-checkout-summary__line-name { font-size: 13px; font-weight: 600; }
.md-checkout-summary__line-qty { font-size: 12px; opacity: .7; }
.md-checkout-summary__line > .md-price { white-space: nowrap; font-weight: 700; }

.md-checkout-summary__coupon--inline {
  margin: 6px 0 4px;
  padding: 0;
  border: 0;
  background: transparent;
}
.md-checkout-summary__coupon--inline .md-checkout-summary__coupon-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
}
.md-checkout-summary__coupon--inline input[name="code"] {
  flex: 1 1 auto;
  min-width: 0;
  border: 1px solid var(--md-mod-line, #e2e8f0);
  background: var(--md-checkout-bg, #fff);
  color: var(--md-checkout-text, var(--md-mod-text));
  padding: 6px 8px;
  font: inherit;
  font-size: 12px;
  border-radius: 6px;
  height: 32px;
  box-sizing: border-box;
}
.md-checkout-summary__coupon--inline [data-md-coupon-apply] {
  flex: 0 0 auto;
  background: var(--md-checkout-button, var(--md-mod-button));
  color: #fff;
  border: 0;
  padding: 0 10px;
  height: 32px;
  font-weight: 700;
  font-size: 11px;
  cursor: pointer;
  border-radius: 6px;
  white-space: nowrap;
}
.md-checkout-summary__coupon--inline .md-checkout-summary__coupon-applied {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin: 0;
  font-size: 11px;
  white-space: nowrap;
  color: var(--md-checkout-primary, var(--md-mod-primary));
}
.md-checkout-summary__coupon--inline .md-checkout-summary__coupon-applied button {
  border: 0;
  background: transparent;
  color: var(--md-mod-muted, #64748b);
  cursor: pointer;
  font-size: 14px;
  line-height: 1;
  padding: 0 2px;
}
.md-checkout-summary__coupon-msg:empty { display: none; }

.md-field--country { position: relative; }
.md-country-picker { position: relative; }
.md-country-picker__trigger {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--md-mod-line, #e2e8f0);
  background: var(--md-checkout-bg, #fff);
  color: var(--md-checkout-text, var(--md-mod-text));
  padding: 10px 12px;
  border-radius: 8px;
  font: inherit;
  text-align: left;
  cursor: pointer;
}
.md-country-picker__flag {
  width: 20px;
  height: 15px;
  object-fit: cover;
  border-radius: 2px;
  flex: 0 0 auto;
}
.md-country-picker__flag.md-hide,
.md-country-picker__flag[hidden] {
  display: none !important;
}
.md-country-picker__trigger [data-md-country-label] { flex: 1; font-size: 14px; }
.md-country-picker__chev { opacity: .55; font-size: 12px; }
.md-country-picker__panel {
  position: absolute;
  z-index: 40;
  left: 0; right: 0;
  top: calc(100% + 4px);
  background: var(--md-checkout-bg, #fff);
  border: 1px solid var(--md-mod-line, #e2e8f0);
  border-radius: 10px;
  box-shadow: 0 12px 28px rgba(15, 23, 42, .16);
  overflow: hidden;
}
.md-country-picker__search {
  width: 100%;
  box-sizing: border-box;
  border: 0;
  border-bottom: 1px solid var(--md-mod-line, #e2e8f0);
  padding: 10px 12px;
  font: inherit;
  font-size: 13px;
  background: transparent;
  color: inherit;
}
.md-country-picker__list {
  list-style: none;
  margin: 0;
  padding: 4px 0;
  max-height: 220px;
  overflow: auto;
}
.md-country-picker__option {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  border: 0;
  background: transparent;
  color: inherit;
  padding: 8px 12px;
  font: inherit;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
}
.md-country-picker__option:hover,
.md-country-picker__option.is-active {
  background: color-mix(in srgb, var(--md-checkout-primary, var(--md-mod-primary)) 12%, transparent);
}
.md-country-picker__option img {
  width: 20px;
  height: 15px;
  object-fit: cover;
  border-radius: 2px;
}
.md-country-picker__option-code {
  margin-left: auto;
  font-size: 11px;
  opacity: .45;
}
.md-country-picker__empty { padding: 12px; font-size: 12px; opacity: .7; }
.md-country-picker__msg { margin: 6px 0 0; font-size: 12px; color: var(--md-checkout-primary, var(--md-mod-primary)); }
.md-country-picker__msg:empty { display: none; }
.md-country-picker__msg.is-error { color: #b91c1c; }

.md-mod-upsell {
  position: fixed;
  right: 16px;
  bottom: 88px;
  z-index: 99990;
  max-width: 300px;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0;
  background: var(--md-panel, var(--md-mod-bg));
  color: var(--md-mod-text);
  border: 1px solid var(--md-line, var(--md-mod-line));
  border-radius: 16px;
  box-shadow: 0 16px 40px color-mix(in srgb, var(--md-mod-text) 18%, transparent);
  padding: 14px;
  font-family: var(--md-mod-font);
  font-size: .85rem;
}
.md-mod-upsell .md-mod-upsell-head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  align-items: start;
  font-weight: 700;
  margin-bottom: 4px;
}
.md-mod-upsell .md-mod-upsell-body {
  width: 100%;
  box-sizing: border-box;
}
.md-mod-upsell p { margin: 8px 0; color: var(--md-mod-muted); }
.md-mod-upsell-product {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 8px 0 12px;
  padding: 8px;
  border-radius: 12px;
  background: color-mix(in srgb, var(--md-mod-primary) 8%, transparent);
  border: 1px solid var(--md-mod-line);
}
.md-mod-upsell-product > div {
  min-width: 0;
  flex: 1;
}
.md-mod-upsell-product img {
  width: 48px;
  height: 48px;
  object-fit: cover;
  border-radius: 10px;
  background: #e2e8f0;
  flex: 0 0 auto;
}
.md-mod-upsell-name {
  font-weight: 700;
  font-size: .82rem;
  line-height: 1.25;
  color: var(--md-mod-text);
}
.md-mod-upsell-prices {
  display: flex;
  gap: 8px;
  align-items: baseline;
  margin-top: 2px;
  font-size: .78rem;
}
.md-mod-upsell-prices s { color: var(--md-mod-muted); }
.md-mod-upsell-prices strong { color: var(--md-mod-primary); }
.md-mod-upsell-msg {
  margin: 8px 0 0;
  font-size: .75rem;
  color: var(--md-mod-muted);
}
.md-mod-upsell .md-btn,
.md-mod-upsell button[type="button"]:not(.md-mod-close) {
  width: 100%;
  background: var(--md-mod-button);
  color: #fff;
  border: 0;
  border-radius: 10px;
  padding: 10px;
  font-weight: 700;
  cursor: pointer;
  font: inherit;
}
.md-mod-close {
  border: 0;
  background: transparent;
  cursor: pointer;
  font-size: 18px;
  line-height: 1;
  color: var(--md-mod-muted);
  padding: 0;
}

/* Prueba social */
.md-mod-social {
  position: fixed;
  z-index: 99988;
  max-width: min(340px, calc(100vw - 24px));
  background: var(--md-mod-bg);
  border: 1px solid var(--md-mod-line);
  border-radius: var(--md-mod-radius);
  box-shadow: 0 12px 32px color-mix(in srgb, var(--md-mod-text) 16%, transparent);
  padding: 10px 12px;
  font-family: var(--md-mod-font);
  font-size: .75rem;
  line-height: 1.4;
  color: var(--md-mod-text);
  display: flex;
  gap: 10px;
  align-items: center;
  opacity: 0;
  transform: translateY(12px);
  pointer-events: none;
  transition: opacity .35s ease, transform .35s ease;
}
.md-mod-social.md-sp-visible {
  opacity: 1;
  transform: translateY(0);
  pointer-events: auto;
}
.md-mod-social.md-sp-left { left: 12px; bottom: 12px; right: auto; }
.md-mod-social.md-sp-right { right: 12px; bottom: 12px; left: auto; }
.md-mod-social .md-sp-thumb {
  position: relative;
  flex: 0 0 auto;
  width: 48px;
  height: 48px;
  border-radius: 10px;
  overflow: hidden;
  background: color-mix(in srgb, var(--md-mod-text) 8%, transparent);
  text-decoration: none;
  display: block;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--md-mod-text) 10%, transparent);
}
.md-mod-social .md-sp-thumb:hover {
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--md-mod-primary) 55%, transparent);
}
.md-mod-social .md-sp-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.md-mod-social .md-sp-thumb.is-empty .md-sp-img { display: none; }
.md-mod-social .md-sp-dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--md-mod-primary);
  flex-shrink: 0;
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--md-mod-primary) 22%, transparent);
}
.md-mod-social .md-sp-dot--fallback {
  display: none;
  position: absolute;
  inset: 0;
  margin: auto;
  width: 10px;
  height: 10px;
}
.md-mod-social .md-sp-thumb.is-empty .md-sp-dot--fallback { display: block; }
.md-mod-social .md-sp-body { flex: 1; min-width: 0; }
.md-mod-social .md-sp-place {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.md-mod-social .md-sp-flag {
  width: 18px;
  height: 13px;
  object-fit: cover;
  border-radius: 2px;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--md-mod-text) 12%, transparent);
  flex-shrink: 0;
  vertical-align: middle;
}
.md-mod-social .md-sp-muted { color: var(--md-mod-muted); font-size: .7rem; }
.md-mod-social .md-sp-product {
  font-weight: 600;
  color: var(--md-mod-primary);
  text-decoration: none;
}
.md-mod-social .md-sp-product:hover { text-decoration: underline; }

/* Newsletter â€” solo checkbox checkout (popup/FAB ocultos) */
.md-mod-newsletter-fab,
.md-mod-newsletter-overlay,
.md-mod-newsletter-card {
  display: none !important;
}
.md-mod-newsletter-checkout {
  display: flex !important;
  flex-direction: row;
  gap: 10px;
  align-items: flex-start;
  margin: 12px 0 0;
  padding: 12px 14px;
  border-radius: var(--md-mod-radius);
  border: 1px dashed color-mix(in srgb, var(--md-checkout-primary, var(--md-mod-primary)) 45%, var(--md-mod-line));
  background: color-mix(in srgb, var(--md-checkout-accent, var(--md-mod-accent)) 10%, var(--md-checkout-bg, var(--md-mod-bg)));
  font-family: var(--md-mod-font);
  font-size: .88rem;
  line-height: 1.4;
  color: var(--md-checkout-text, var(--md-mod-text));
  grid-column: 1 / -1;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  cursor: pointer;
}
.md-mod-newsletter-checkout span { flex: 1; min-width: 0; }
.md-mod-newsletter-checkout input,
.md-mod-newsletter-checkout input[type="checkbox"] {
  appearance: auto;
  width: 18px !important;
  height: 18px !important;
  min-width: 18px !important;
  max-width: 18px !important;
  margin: 3px 0 0 !important;
  padding: 0 !important;
  flex-shrink: 0;
  accent-color: var(--md-checkout-primary, var(--md-mod-primary));
}
.md-mod-newsletter-checkout strong {
  color: var(--md-checkout-primary, var(--md-mod-primary));
}

/* Cookies UE — banner + preferencias */
.md-mod-cookies {
  font-family: var(--md-mod-font);
  color: var(--md-mod-text);
  z-index: 99995;
}
.md-mod-cookies__bar {
  position: fixed;
  left: 16px;
  right: 16px;
  bottom: 16px;
  z-index: 99995;
  display: flex;
  flex-wrap: wrap;
  gap: 14px 18px;
  align-items: center;
  justify-content: space-between;
  max-width: 1100px;
  margin: 0 auto;
  padding: 16px 18px;
  background: var(--md-mod-bg);
  color: var(--md-mod-text);
  border: 1px solid var(--md-mod-line);
  border-radius: var(--md-mod-radius);
  box-shadow: 0 16px 40px color-mix(in srgb, var(--md-mod-text) 18%, transparent);
}
.md-mod-cookies__copy {
  flex: 1 1 280px;
  min-width: 0;
}
.md-mod-cookies__copy strong {
  display: block;
  font-size: .95rem;
  margin-bottom: 4px;
}
.md-mod-cookies__copy p {
  margin: 0;
  font-size: .82rem;
  line-height: 1.45;
  color: var(--md-mod-muted);
}
.md-mod-cookies__policy {
  display: inline-block;
  margin-top: 6px;
  font-size: .78rem;
  font-weight: 700;
  color: var(--md-mod-primary);
  text-decoration: underline;
}
.md-mod-cookies__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  justify-content: flex-end;
}
.md-mod-cookies .md-btn {
  background: var(--md-mod-button);
  color: #fff;
  border: 0;
  border-radius: 10px;
  padding: 10px 14px;
  font-weight: 700;
  cursor: pointer;
  font: inherit;
  font-size: .82rem;
}
.md-mod-cookies .md-btn--ghost {
  background: transparent;
  color: var(--md-mod-text);
  border: 1px solid var(--md-mod-line);
}
.md-mod-cookies__overlay {
  position: fixed;
  inset: 0;
  z-index: 99996;
  background: color-mix(in srgb, var(--md-mod-text) 45%, transparent);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: 16px;
}
.md-mod-cookies__card {
  position: relative;
  width: min(460px, 100%);
  background: var(--md-mod-bg);
  color: var(--md-mod-text);
  border: 1px solid var(--md-mod-line);
  border-radius: 16px;
  box-shadow: 0 20px 48px color-mix(in srgb, var(--md-mod-text) 22%, transparent);
  padding: 20px 18px 16px;
  font-size: .85rem;
}
.md-mod-cookies__card h2 {
  margin: 0 28px 8px 0;
  font-size: 1.05rem;
}
.md-mod-cookies__hint {
  margin: 0 0 14px;
  color: var(--md-mod-muted);
  font-size: .8rem;
  line-height: 1.4;
}
.md-mod-cookies__cat {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin: 0 0 10px;
  padding: 10px 12px;
  border-radius: 12px;
  border: 1px solid var(--md-mod-line);
  background: color-mix(in srgb, var(--md-mod-primary) 6%, transparent);
  cursor: pointer;
}
.md-mod-cookies__cat[hidden] { display: none !important; }
.md-mod-cookies__cat input {
  margin-top: 3px;
  accent-color: var(--md-mod-primary);
}
.md-mod-cookies__cat input:disabled { cursor: not-allowed; }
.md-mod-cookies__cat strong { display: block; }
.md-mod-cookies__cat em {
  display: block;
  font-style: normal;
  font-size: .75rem;
  color: var(--md-mod-muted);
  margin-top: 2px;
}
.md-mod-cookies__card .md-btn { width: 100%; margin-top: 6px; }
.md-mod-cookies__card .md-mod-close {
  position: absolute;
  top: 10px;
  right: 12px;
}
CSS;
    }

    /**
     * CSS efectivo de mÃ³dulos: custom de la plantilla o starter.
     *
     * @param  array<string, mixed>  $design
     */
    public function resolveModulesCss(array $design): string
    {
        $custom = trim((string) ($design['modules_css'] ?? ''));

        return $custom !== '' ? $custom : $this->starterModulesCss();
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $page
     */
    public function composeStorefrontCss(array $design, array $page = []): string
    {
        $parts = [
            $this->platformModuleCss(),
            $this->pdpRuntimeCss(),
            trim($this->sanitizeThemeCss($this->resolveModulesCss($design))),
            trim($this->sanitizeThemeCss((string) ($design['global_css'] ?? ''))),
            trim((string) ($page['css'] ?? '')),
            $this->platformMobileCss(),
            $this->resolveThemeMobileCss($design),
            $this->platformOverlayGuardCss(),
        ];

        return trim(implode("\n\n", array_filter($parts, fn ($p) => $p !== '')));
    }

    /**
     * Quita reglas de tema que rompen overlays inyectados por la plataforma.
     */
    public function sanitizeThemeCss(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        $patterns = [
            '/\.md-mod-upsell\s*,\s*\.md-mod-cross\s*\{[^}]*\}/i',
            '/\.md-mod-upsell__img[^,{]*,\s*\.md-mod-cross__img\s*\{[^}]*\}/i',
            '/\.md-mod-upsell__title[^,{]*,\s*\.md-mod-cross__title\s*\{[^}]*\}/i',
        ];

        foreach ($patterns as $pattern) {
            $css = preg_replace($pattern, '', $css) ?? $css;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", $css) ?? $css);
    }

    /**
     * Capa final: protege layout de overlays aunque el tema pise selectores .md-mod-*.
     */
    public function platformOverlayGuardCss(): string
    {
        return <<<'CSS'
[data-md-module="upsell"].md-mod-upsell,
#md-upsell-demo.md-mod-upsell {
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  gap: 0 !important;
}
.md-mod-upsell .md-mod-upsell-head,
.md-mod-upsell .md-mod-upsell-body,
.md-mod-upsell .md-btn,
.md-mod-upsell-msg {
  width: 100%;
  box-sizing: border-box;
}
.md-mod-upsell .md-mod-upsell-product {
  display: flex !important;
  flex-direction: row !important;
  align-items: center !important;
}
CSS;
    }

    /**
     * Capa móvil de la plantilla (después de platform mobile).
     * Axiom se detecta por su CSS de escritorio para que todas las copias lo reciban.
     *
     * @param  array<string, mixed>  $design
     */
    public function resolveThemeMobileCss(array $design): string
    {
        $path = $this->detectThemeMobileCssPath($design);
        if ($path && is_file($path)) {
            return trim((string) file_get_contents($path));
        }

        return trim((string) ($design['mobile_css'] ?? ''));
    }

    /**
     * Clase en <body> para que el CSS de plataforma no pinte Axiom encima de EP.
     *
     * @param  array<string, mixed>  $design
     */
    public function themeBodyClass(array $design): string
    {
        if ($this->isAxiomTheme($design)) {
            return 'md-theme-axiom';
        }
        if ($this->isEmergencyPowerTheme($design)) {
            return 'md-theme-ep';
        }
        if ($this->isNocturnoTheme($design)) {
            return 'md-theme-nocturno';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function isAxiomTheme(array $design): bool
    {
        $global = (string) ($design['global_css'] ?? '');

        return str_contains($global, 'Plantilla "Axiom"')
            || str_contains($global, 'Plantilla Axiom')
            || str_contains($global, "Plantilla 'Axiom'");
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function isEmergencyPowerTheme(array $design): bool
    {
        $global = (string) ($design['global_css'] ?? '');
        $hint = strtolower($global.' '.(string) ($design['name'] ?? '').' '.(string) ($design['slug'] ?? ''));

        return str_contains($global, 'Emergency Power — theme.css')
            || str_contains($global, 'Emergency Power -- theme.css')
            || str_contains($global, 'equipment placard')
            || str_contains($global, '--md-graphite')
            || str_contains($hint, 'emergency-power');
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function isNocturnoTheme(array $design): bool
    {
        $global = (string) ($design['global_css'] ?? '');
        $hint = strtolower($global.' '.(string) ($design['name'] ?? '').' '.(string) ($design['slug'] ?? ''));

        return str_contains($global, 'TEMA: "APAGÓN"')
            || str_contains($global, 'TEMA: "APAGON"')
            || str_contains($global, 'Ventilador + Lámpara')
            || str_contains($hint, 'tema-nocturno-calor-luz')
            || str_contains($hint, 'nocturno-calor');
    }

    /**
     * @param  array<string, mixed>  $design
     */
    protected function detectThemeMobileCssPath(array $design): ?string
    {
        if ($this->isAxiomTheme($design)) {
            return resource_path('css/storefront/themes/axiom-mobile.css');
        }
        if ($this->isEmergencyPowerTheme($design)) {
            return resource_path('css/storefront/themes/emergency-power-mobile.css');
        }
        if ($this->isNocturnoTheme($design)) {
            return resource_path('css/storefront/themes/nocturno-mobile.css');
        }

        return null;
    }

    /**
     * CSS de layout de mÃ³dulos (header/grid/pdp/cart). La plantilla lo pisa despuÃ©s.
     */
    public function platformModuleCss(): string
    {
        $path = resource_path('css/storefront/modules/platform.css');
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * Capa móvil al final de la cascada para que ninguna plantilla desborde.
     */
    public function platformMobileCss(): string
    {
        $path = resource_path('css/storefront/modules/mobile.css');
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * CSS de runtime PDP (contraste reseÃ±as + carrusel de fotos y carrusel de videos).
     */
    protected function pdpRuntimeCss(): string
    {
        return <<<'CSS'
.md-review, .md-comment {
  background: var(--md-panel, color-mix(in srgb, currentColor 8%, transparent));
  color: inherit;
  border: 1px solid var(--md-line, color-mix(in srgb, currentColor 14%, transparent));
}
.md-review p, .md-comment p,
.md-review strong, .md-comment strong { color: inherit; }
.md-review__meta, .md-comment__meta { color: var(--md-muted, color-mix(in srgb, currentColor 72%, transparent)); }
.md-review__stars { color: var(--md-amber, #f59e0b); }
[data-md-module="urgency"].md-mod-bar,
.md-mod-urgency {
  justify-content: center;
  text-align: center;
}
[data-md-module="urgency"] [data-md-urgency-copy],
.md-mod-urgency [data-md-urgency-copy] {
  display: inline;
  width: auto;
  text-align: center;
}
[data-md-module="urgency"]:not(.md-mod-bar):not(.md-mod-urgency) {
  display: none !important;
}
.md-media-carousel__stage { position: relative; overflow: hidden; aspect-ratio: 1 / 1; min-height: 220px; }
.md-media-carousel__stage > img { width: 100%; height: 100%; object-fit: cover; display: block; }
.md-media-carousel__stage > video[data-md-media-video] { display: none !important; }
.md-media-carousel__nav, .md-video-carousel__nav {
  position: absolute; top: 50%; transform: translateY(-50%); z-index: 2;
  width: 36px; height: 36px; border: 0; border-radius: 50%;
  background: color-mix(in srgb, var(--md-graphite, #14181A) 70%, transparent);
  color: var(--md-paper, #fff); font-size: 22px; line-height: 1; cursor: pointer;
}
.md-media-carousel__prev, .md-video-carousel__prev { left: 10px; }
.md-media-carousel__next, .md-video-carousel__next { right: 10px; }
.md-media-carousel__count, .md-video-carousel__count {
  position: absolute; right: 10px; bottom: 10px; z-index: 2;
  font: 600 11px/1 var(--md-font-mono, ui-monospace, monospace);
  letter-spacing: .06em; color: var(--md-paper, #fff);
  background: color-mix(in srgb, var(--md-graphite, #000) 62%, transparent);
  padding: 4px 8px; border-radius: 999px;
}
.md-media-carousel__thumbs, .md-video-carousel__thumbs { display: flex; flex-wrap: nowrap; gap: 8px; overflow-x: auto; }
.md-media-carousel__play {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,.45); color: #fff; font-size: 12px; pointer-events: none;
}
.md-video-carousel { margin-top: 18px; width: 100%; }
.md-video-carousel[hidden] { display: none !important; }
.md-video-carousel__stage {
  position: relative; overflow: hidden; background: #000; aspect-ratio: 16/9; min-height: 180px;
}
#md-atc-modal .md-atc-modal__product {
  display: grid !important;
  grid-template-columns: 64px minmax(0, 1fr) auto !important;
  align-items: center !important;
}
#md-atc-modal .md-atc-modal__media {
  width: 64px !important; height: 64px !important; overflow: hidden !important; border-radius: 10px; background: #e2e8f0;
}
#md-atc-modal #md-atc-img,
#md-atc-modal .md-atc-modal__media img {
  width: 64px !important; height: 64px !important; max-width: none !important;
  object-fit: cover !important; display: block !important; position: static !important;
  opacity: 1 !important; visibility: visible !important;
}
#md-atc-modal .md-atc-modal__ph[hidden],
#md-atc-modal #md-atc-img[hidden] { display: none !important; }
#md-atc-modal.md-atc-modal .md-atc-modal__card {
  background: #ffffff !important;
  color: #0f172a !important;
}
#md-atc-modal .md-atc-modal__title,
#md-atc-modal .md-atc-modal__name,
#md-atc-modal .md-atc-modal__price { color: #0f172a !important; }
#md-atc-modal .md-atc-modal__sub,
#md-atc-modal .md-atc-modal__meta { color: #64748b !important; }
#md-atc-modal .md-atc-modal__product {
  background: #f1f5f9 !important;
  color: #0f172a !important;
  border-color: #e2e8f0 !important;
}
.md-video-carousel[hidden] { display: none !important; }
.md-video-carousel__head {
  margin: 0 0 8px; font: 700 12px/1.2 var(--md-font-mono, ui-monospace, monospace);
  letter-spacing: .08em; text-transform: uppercase; color: var(--md-amber, #F2A93B);
}
.md-video-carousel__stage {
  position: relative; overflow: hidden; background: #000; aspect-ratio: 16/9; min-height: 180px;
}
.md-video-carousel__stage video { width: 100%; height: 100%; object-fit: contain; display: block; background: #000; }
.md-product__gallery { display: flex; flex-direction: column; min-width: 0; }
.md-product__specs[hidden] { display: none !important; }
CSS;
    }

    /**
     * Reescribe href="pages/{handle}" relativos a URLs absolutas del storefront/sandbox.
     * Los ZIP de plantillas suelen usar rutas relativas que se rompen fuera del preview estÃ¡tico.
     */
    public function rewriteRelativePageHrefs(string $html, string $pagesBaseUrl): string
    {
        $base = rtrim($pagesBaseUrl, '/');
        if ($html === '' || $base === '') {
            return $html;
        }

        $rewritten = preg_replace_callback(
            '/\bhref=(["\'])pages\/([^"\'?]+)/i',
            static function (array $m) use ($base): string {
                return 'href='.$m[1].$base.'/'.ltrim($m[2], '/');
            },
            $html
        );
        $html = is_string($rewritten) ? $rewritten : $html;

        // ZIP estÃ¡ticos: href="nosotros.html" / "faq.html" â†’ /pages/nosotros
        $rewritten = preg_replace_callback(
            '/\bhref=(["\'])(?!https?:|\/\/|\/|#|mailto:|tel:|\{\{)([a-z0-9_-]+)\.html(\?[^"\']*)?\1/i',
            static function (array $m) use ($base): string {
                $handle = strtolower($m[2]);
                if (in_array($handle, ['index', 'product', 'catalog', 'cart', 'checkout'], true)) {
                    return $m[0];
                }

                return 'href='.$m[1].$base.'/'.$handle.($m[3] ?? '').$m[1];
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Extrae el markup usable de un HTML completo (quita doctype/html/head y links a theme.css).
     */
    public function extractBodyHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $inlineStyles = '';
        if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $headMatch)) {
            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $headMatch[1], $styleMatches)) {
                $inlineStyles = trim(implode("\n", $styleMatches[1]));
            }
        }

        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $bodyMatch)) {
            $html = $bodyMatch[1];
        } elseif (preg_match('/<!doctype|<html[\s>]/i', $html)) {
            // Documento parcial: quitar head si quedÃ³
            $html = (string) preg_replace('/<!doctype[^>]*>/i', '', $html);
            $html = (string) preg_replace('/<\/?html[^>]*>/i', '', $html);
            $html = (string) preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
            $html = (string) preg_replace('/<\/?body[^>]*>/i', '', $html);
        }

        // CSS/JS del theme ya viven en global_* / page css|js
        $html = (string) preg_replace('/<link[^>]+rel=["\']stylesheet["\'][^>]*>\s*/i', '', $html);
        $html = (string) preg_replace('/<script[^>]+src=["\'](?!https?:|\/\/)[^"\']+\.js["\'][^>]*>\s*<\/script>\s*/i', '', $html);

        $html = trim($html);
        if ($inlineStyles !== '') {
            $html = "<style>\n{$inlineStyles}\n</style>\n".$html;
        }

        return $html;
    }

    public function extractHeadCss(string $html): string
    {
        $css = '';
        if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $headMatch)) {
            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $headMatch[1], $styleMatches)) {
                $css = trim(implode("\n", $styleMatches[1]));
            }
        }

        return $css;
    }

    /**
     * @return array{class: string, id: string, style: string}
     */
    public function extractBodyAttributes(string $html): array
    {
        $out = ['class' => '', 'id' => '', 'style' => ''];
        if (! preg_match('/<body([^>]*)>/i', $html, $m)) {
            return $out;
        }
        $attrs = $m[1] ?? '';
        if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/', $attrs, $cm)) {
            $out['class'] = trim($cm[1]);
        }
        if (preg_match('/\bid\s*=\s*["\']([^"\']*)["\']/', $attrs, $im)) {
            $out['id'] = trim($im[1]);
        }
        if (preg_match('/\bstyle\s*=\s*["\']([^"\']*)["\']/', $attrs, $sm)) {
            $out['style'] = trim($sm[1]);
        }

        return $out;
    }

    /**
     * Hojas externas del HTML (Google Fonts, CDN). Omite theme.css local: ya va en global_css.
     *
     * @return list<string>
     */
    public function extractStylesheetUrls(string $html): array
    {
        $urls = [];
        if (! preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            return $urls;
        }
        foreach ($tags[0] as $tag) {
            if (! preg_match('/rel\s*=\s*["\'][^"\']*stylesheet[^"\']*["\']/i', $tag)
                && ! preg_match('/fonts\.googleapis\.com|fonts\.gstatic\.com/i', $tag)) {
                continue;
            }
            if (! preg_match('/href\s*=\s*["\']([^"\']+)["\']/', $tag, $href)) {
                continue;
            }
            $url = trim($href[1]);
            if ($url === '' || str_starts_with($url, 'data:')) {
                continue;
            }
            if (! preg_match('#^(?:https?:)?//#i', $url) && ! str_starts_with($url, '/storage')) {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }
            $urls[] = DesignAssetUrl::localize($url);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Reescribe url(...) relativos en CSS hacia assets subidos del design.
     *
     * @param  list<array<string, mixed>>  $assets
     */
    public function rewriteCssAssetUrls(string $css, array $assets): string
    {
        if (trim($css) === '' || $assets === []) {
            return $css;
        }

        $byBase = $this->assetUrlLookup($assets);

        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function ($m) use ($byBase) {
                $raw = trim($m[2]);
                if ($raw === '' || preg_match('{^(?:https?:)?//|^data:|^#}i', $raw)) {
                    return $m[0];
                }
                $resolved = $this->resolveAssetUrl($raw, $byBase);
                if ($resolved === null) {
                    return $m[0];
                }

                return 'url("'.$resolved.'")';
            },
            $css
        );
    }

    /**
     * Reescribe src/href relativos (p. ej. assets/logo.svg) hacia assets subidos.
     *
     * @param  list<array<string, mixed>>  $assets
     */
    public function rewriteHtmlAssetUrls(string $html, array $assets): string
    {
        if (trim($html) === '' || $assets === []) {
            return $html;
        }

        $byBase = $this->assetUrlLookup($assets);

        return (string) preg_replace_callback(
            '/\b(src|href|poster)\s*=\s*(["\'])([^"\']+)\2/i',
            function ($m) use ($byBase) {
                $raw = trim($m[3]);
                if ($raw === '' || preg_match('{^(?:https?:)?//|^data:|^mailto:|^tel:|^#|^\{\{}i', $raw)) {
                    return $m[0];
                }
                // Solo paths de assets locales del theme
                if (! preg_match('{^(?:\./)?(?:assets|images|img|media|static)/}i', $raw)
                    && ! preg_match('{\.(?:svg|png|jpe?g|gif|webp|ico|avif)(?:\?|#|$)}i', $raw)) {
                    return $m[0];
                }
                $resolved = $this->resolveAssetUrl($raw, $byBase);
                if ($resolved === null) {
                    return $m[0];
                }

                return $m[1].'='.$m[2].$resolved.$m[2];
            },
            $html
        );
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, string>
     */
    protected function assetUrlLookup(array $assets): array
    {
        $byBase = [];
        foreach ($assets as $asset) {
            $url = ! empty($asset['path'])
                ? DesignAssetUrl::fromPath((string) $asset['path'])
                : DesignAssetUrl::localize((string) ($asset['url'] ?? ''));
            $name = (string) ($asset['name'] ?? '');
            if ($url === '') {
                continue;
            }
            if ($name !== '') {
                $byBase[strtolower($name)] = $url;
                $byBase[strtolower(basename($name))] = $url;
                $byBase[strtolower('assets/'.basename($name))] = $url;
                $byBase[strtolower('images/'.basename($name))] = $url;
            }
            $path = (string) ($asset['path'] ?? '');
            if ($path !== '') {
                $byBase[strtolower(basename($path))] = $url;
            }
        }

        return $byBase;
    }

    /**
     * @param  array<string, string>  $byBase
     */
    protected function resolveAssetUrl(string $raw, array $byBase): ?string
    {
        $clean = strtok($raw, '?#') ?: $raw;
        $clean = ltrim(str_replace('\\', '/', $clean), './');
        $base = strtolower(basename($clean));
        if (isset($byBase[$base])) {
            return $byBase[$base];
        }
        $asAssets = strtolower('assets/'.$base);
        if (isset($byBase[$asAssets])) {
            return $byBase[$asAssets];
        }
        $full = strtolower($clean);
        if (isset($byBase[$full])) {
            return $byBase[$full];
        }

        return null;
    }

    public function designerPrompt(Store $store, array $design): string
    {
        return $this->buildDesignerPrompt(
            $store->name,
            $store->slug,
            url('/s/'.$store->slug),
            '/admin/store/design/preview?page={id}',
            'Admin → Diseño de la tienda → «Subir ZIP theme»',
            $design
        );
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function designerPromptForLibrary(string $name, string $slug, array $design): string
    {
        return $this->buildDesignerPrompt(
            $name,
            $slug,
            url('/t/'.$slug),
            '/t/'.$slug.' (sandbox de prueba)',
            'Admin → Plantillas → «Subir ZIP» (biblioteca de plataforma)',
            $design
        );
    }

    /**
     * @param  array<string, mixed>  $design
     */
    protected function buildDesignerPrompt(
        string $name,
        string $slug,
        string $base,
        string $previewAdmin,
        string $uploadHint,
        array $design
    ): string {
        /** @var \App\Services\Storefront\Modules\ModuleRegistry $registry */
        $registry = app(\App\Services\Storefront\Modules\ModuleRegistry::class);

        $pages = collect($design['pages'] ?? [])->map(function ($p) use ($registry) {
            $mods = implode(', ', $registry->keysOf(is_array($p['modules'] ?? null) ? $p['modules'] : []));

            return '- '.$p['title'].' | tipo='.$p['type'].' | handle='.$p['handle'].' | status='.($p['status'] ?? 'draft').($mods !== '' ? ' | módulos: '.$mods : '');
        })->implode("\n");
        if (trim($pages) === '') {
            $pages = '(aún no hay páginas — el importador creará landing/catálogo/PDP/carrito/checkout si faltan)';
        }

        $notes = trim((string) ($design['prompt_notes'] ?? ''));
        if ($notes === '') {
            $notes = '(sin notas adicionales del merchant)';
        }

        $defaultLayouts = collect([
            ['index', 'landing'],
            ['catalog', 'catalog'],
            ['product', 'product'],
            ['cart', 'cart'],
            ['checkout', 'checkout'],
        ])->map(function ($row) use ($registry) {
            [$handle, $type] = $row;
            $mods = implode(' → ', $registry->defaultLayout($type));

            return "| `{$handle}` | {$type} | {$mods} |";
        })->implode("\n");

        $moduleKeys = implode('`, `', $registry->keys());

        return <<<TXT
# Brief Multidrop — {$name}

## Tu rol (léelo antes de escribir una línea)

Eres diseñador front-end para **Multidrop**, plataforma de mini-tiendas dropshipping.
Tu entrega es un **ZIP de identidad visual**, NO una tienda HTML completa.

**Multidrop ya renderiza el comercio** con Twig + JSON inyectado:
header, hero estrella, grid, PDP, carrito, checkout, urgencia, upsell, cross-sell, ruleta, prueba social, newsletter y modal ATC.

**Tú SOLO entregas:**
1. `theme.css` — tokens, tipografía, layout global
2. `modules.css` — overrides de módulos `.md-mod-*` (recomendado, casi obligatorio)
3. `assets/` — logo, iconos, fuentes, fondos
4. `layout.json` — opcional, orden de módulos por página
5. `theme.js` — opcional, SOLO menú móvil / decoración (ver reglas)
6. `pages/*.twig` — opcional, FAQ / nosotros / legal (copy estático)

**Contexto de esta tienda**
- Nombre: {$name}
- Slug: `{$slug}`
- URL base: {$base}
- Subir ZIP: {$uploadHint}
- Preview admin: {$previewAdmin}

---

## Regla de oro

> Si escribes HTML de catálogo, PDP, carrito o checkout, **Multidrop lo descarta** y usa sus módulos Twig.
> Tu CSS sí aplica. Por eso el 90 % del trabajo es **CSS sobre selectores existentes**, no HTML nuevo.

---

## Estructura ZIP exacta

```
mi-tema/
├── theme.css              ← OBLIGATORIO
├── modules.css            ← MUY recomendado (overlays + módulos)
├── theme.js               ← opcional (menú móvil solamente)
├── layout.json            ← opcional
├── assets/
│   ├── logo.svg
│   └── ...
└── pages/
    ├── faq.twig           ← handle = faq
    ├── about.twig         ← handle = about
    └── shipping.twig      ← handle = shipping
```

**NO incluyas:** `index.html`, `product.html`, `cart.html`, `checkout.html`, `page.html`, `checkout.js`, `cart.js`.

Puedes incluir CSS por página comercial (`checkout.css`, `cart.css`) — el importador los asocia al handle. **Nunca** JS comercial.

---

## Páginas comerciales — handles fijos

| Handle | Tipo | Módulos por defecto |
|--------|------|---------------------|
{$defaultLayouts}

**Handles reconocidos por el importador:**
- `index` / `home` / `inicio` → landing
- `catalog` / `shop` / `tienda` → catálogo
- `product` / `pdp` → ficha de producto
- `cart` / `carrito` → carrito
- `checkout` / `pago` → checkout
- Cualquier otro → página libre (`pages/nombre.twig`)

**NO crees:** gracias, thank-you, pedido, seguimiento, mi-cuenta — la plataforma los gestiona post-compra.

---

## Catálogo de módulos (claves exactas)

Claves válidas: `{$moduleKeys}`

| Módulo | Qué renderiza la plataforma | Selectores CSS clave |
|--------|------------------------------|----------------------|
| `header` | Nav sticky, logo, links, idioma/moneda, carrito | `.md-nav` `.md-header` `.md-logo` `.md-nav__links` `.md-locale-currency` `[data-md-nav-toggle]` |
| `footer` | Pie con links | `.md-footer` |
| `hero_star` | Hero del producto estrella (imagen, título, precio, CTA) | `.md-hero` `.md-mod-hero` `.md-niche` |
| `product_grid` | Grid de tarjetas de producto | `.md-grid` `.md-card` `.md-card__img` `.md-card__price` |
| `pdp` | Galería, variantes, qty, add-to-cart, descripción | `.md-pdp` `.md-pdp__grid` `.md-qty` `[data-md-add-to-cart]` `.md-variant` |
| `cart` | Líneas del carrito, qty, totales | `.md-cart` `.md-cart-line` `.md-qty` |
| `checkout` | Formulario envío + pago + resumen | `.md-checkout` `.md-checkout-layout` `.md-checkout-box` `.md-checkout-summary` |
| `static` | Cuerpo de página libre (FAQ, legal) | `.md-mod-static` `.md-static-body` |
| `urgency` | Barra/banner de urgencia | `.md-mod-urgency` |
| `upsell` | Modal combo post-ATC | `.md-mod-upsell` |
| `cross_sell` | Oferta mágica en checkout | `.md-mod-cross-checkout` |
| `roulette` | Ruleta de premios | `.md-mod-roulette-*` |
| `social_proof` | Toast "alguien compró…" | `.md-mod-social` |
| `newsletter` | Checkbox en checkout (NO popup flotante) | `.md-newsletter-slot` |
| `atc_modal` | Modal "Agregado al carrito" | `#md-atc-modal` |
| `cookies` | Banner + preferencias UE (Necesarias / Analítica / Marketing) | `.md-mod-cookies` |

**Overlays** (`roulette`, `social_proof`, `upsell`, `atc_modal`, `cookies`): la plataforma los inyecta sola. **Estilízalos en `modules.css`**, no dupliques su HTML. **No uses `display:flex` en `.md-mod-upsell` ni clases BEM inventadas (`md-mod-upsell__img`);** la plataforma usa `.md-mod-upsell-head`, `.md-mod-upsell-body`, `.md-mod-upsell-product`, `.md-mod-upsell-name`, `.md-mod-upsell-prices`.

---

## layout.json — formato exacto

Claves = **handle** de página (no URL, no título).

```json
{
  "index": ["header", "urgency", "hero_star", "product_grid", "footer"],
  "catalog": ["header", "product_grid", "footer"],
  "product": ["header", "urgency", "pdp", "footer"],
  "cart": ["header", "cart", "footer"],
  "checkout": ["header", "checkout", "footer"],
  "faq": ["header", "static", "footer"]
}
```

Cada ítem también puede ser `{"key":"roulette","desktop":true,"mobile":false}` para mostrar el módulo solo en PC o solo en móvil. Un string equivale a ambos.

También acepta `"pages": { ... }` anidado. Si omites una página comercial, se usan los módulos por defecto de la tabla anterior.

---

## CSS — contrato obligatorio

### 1) Variables en `:root` (theme.css)

```css
:root {
  --md-primary: #0f766e;
  --md-ink: #0f172a;
  --md-muted: #64748b;
  --md-line: #e2e8f0;
  --md-bg: #ffffff;
  --md-panel: #f8fafc;
  --md-container: 1180px;
  /* Checkout: texto SIEMPRE legible sobre fondo */
  --md-checkout-primary: var(--md-primary);
  --md-checkout-accent: #f59e0b;
  --md-checkout-button: var(--md-primary);
  --md-checkout-bg: #ffffff;
  --md-checkout-text: #0f172a;
  --md-mod-primary: var(--md-primary);
  --md-mod-accent: var(--md-checkout-accent);
}
```

### 2) División theme.css vs modules.css

- **theme.css**: tipografía, colores globales, `.md-wrap`, header/footer base, botones `.md-btn`
- **modules.css**: overrides de `.md-mod-*`, overlays, checkout, cart, PDP, grids

### 3) Stepper de cantidad `.md-qty` (CRÍTICO — error frecuente)

La plataforma renderiza: `[ − ] [ input ] [ + ]` como píldora unida.
**NO** uses `gap`, `padding` sueltos ni botones flotantes.

```css
.md-qty {
  display: inline-flex;
  align-items: stretch;
  gap: 0;
  padding: 0;
  border: 1px solid var(--md-line);
  border-radius: 10px;
  overflow: hidden;
  background: var(--md-panel);
  color: var(--md-ink);
}
.md-qty button {
  width: 34px;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: inherit;
  cursor: pointer;
}
.md-qty input {
  width: 42px;
  text-align: center;
  border: 0;
  border-left: 1px solid var(--md-line);
  border-right: 1px solid var(--md-line);
  background: var(--md-bg);
  color: var(--md-ink);
}
```

### 4) Checkout centrado

`.md-checkout-layout` usa grid 2 columnas en desktop. No fuerces `width:100vw` ni rompas el centrado con `.md-wrap`.

### 5) Contraste

- Texto claro sobre fondo claro = **prohibido** (especialmente `.md-qty`, `.md-checkout-summary`)
- Usa `--md-checkout-text` para inputs y totales en checkout/carrito

---

## theme.js — permitido vs prohibido

**PERMITIDO:**
```js
// Menú móvil
document.querySelector('[data-md-nav-toggle]')?.addEventListener('click', …);
// Fondos decorativos, parallax, animaciones CSS-trigger
```

**PROHIBIDO (el importador los borra o rompen la tienda):**
- `renderGrids`, `renderSummary`, `bindAddToCart`
- `Multidrop.Cart =`, `MD.Cart =`, `localStorage` de carrito
- Fetch a APIs de pago
- `data-md-bind`, `data-md-products`, grids manuales de productos
- Cualquier JS que reemplace carrito/checkout

---

## Páginas estáticas (Twig)

Archivo `pages/faq.twig` → handle `faq`. **Un archivo = un handle.**

Tokens disponibles: `{{ store.name }}`, `{{ urls.home }}`, `{{ urls.catalog }}`, `{% if %}…{% endif %}`.

**PROHIBIDO:**
- `page.html` genérico con `{{page.title}}` literal (el importador lo ignora)
- Bucles de productos `{% for product in products %}`
- Precios o nombres hardcodeados

**Plantilla mínima correcta:**
```twig
<section class="md-section md-mod-static">
  <div class="md-wrap">
    <h1>Preguntas frecuentes</h1>
    <div class="md-static-body">
      <p>Contenido estático aquí…</p>
    </div>
  </div>
</section>
```

---

## Errores frecuentes (evítalos)

1. **Entregar index.html con grid de productos** → ignorado; pierdes tiempo
2. **`page.html` con `{{page.title}}`** → no crea páginas; usa `faq.twig`, `about.twig`
3. **checkout.js que pinta resumen** → borrado por importador; checkout queda roto
4. **`.md-qty` con gap/padding** → stepper desalineado, texto ilegible
5. **Fondo oscuro en checkout sin `--md-checkout-text` claro** → inputs invisibles
6. **Duplicar modal ATC o ruleta en HTML** → doble modal, conflictos JS
7. **Ocultar `.md-locale-currency`** → rompe selector idioma/moneda
8. **Hardcodear precios/nombres de productos** → no se actualizan con el catálogo
9. **Popup newsletter flotante** → conflicto con checkbox de checkout
10. **Rutas post-compra (gracias, pedido)** → fuera de scope; la plataforma las tiene

---

## Flujo de trabajo recomendado

1. Define paleta + tipografía → escribe `:root` en `theme.css`
2. Estiliza header/footer en `theme.css`
3. Estiliza módulos comerciales en `modules.css` (grid, PDP, cart, checkout)
4. Estiliza overlays en `modules.css` (urgency, roulette, upsell, ATC modal)
5. Opcional: `layout.json` si quieres cambiar orden de módulos
6. Opcional: `pages/*.twig` para FAQ/legal
7. Opcional: `theme.js` solo para menú móvil
7. Empaqueta ZIP y verifica checklist

---

## Mini-tienda = producto estrella

Landing destaca el flagship en hero + urgencia. Catálogo es secundario.
Datos dinámicos (nombre, precio, imagen, variantes) vienen del JSON — **nunca hardcodees**.

---

## Idioma, moneda y traducción

Copy estático (FAQ) en el idioma principal del merchant.
Tras subir el ZIP, el merchant configura `default_locale`, `locales[]`, `default_currency`, `currencies[]` en Admin, o traduce con MIIA.

---

## Páginas actuales de «{$name}»
{$pages}

## Notas del merchant
{$notes}

---

## Checklist final (marca cada ítem antes de entregar)

- [ ] ZIP contiene `theme.css` + `assets/` (+ `modules.css` recomendado)
- [ ] `:root` define `--md-primary`, `--md-ink`, `--md-checkout-*` con contraste legible
- [ ] `modules.css` estiliza: header, grid, PDP, cart, checkout, urgency, overlays
- [ ] `.md-qty` es píldora unida (gap:0, sin botones sueltos)
- [ ] Sin HTML comercial (`index.html`, `product.html`, etc.)
- [ ] Sin `page.html` genérico; estáticas = `pages/{handle}.twig`
- [ ] Sin `checkout.js` / `cart.js` / `renderSummary` / carrito en JS
- [ ] Sin popup newsletter flotante
- [ ] Sin páginas gracias/pedido/cuenta
- [ ] Header conserva `.md-locale-currency` visible
- [ ] Mobile-first; nav con `[data-md-nav-toggle]` estilizado
- [ ] Sin precios/nombres de productos hardcodeados
TXT;
    }
}
