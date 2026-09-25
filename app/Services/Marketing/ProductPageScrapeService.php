<?php

namespace App\Services\Marketing;

use App\Domain\Scraping\CloudflareBrowserRenderer;
use App\Domain\Suppliers\AliExpress\AliExpressProductFetcher;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * Enriquece el brief creativo scrapeando la ficha del producto con Cloudflare Browser Rendering
 * (sin visión MIIA). Prioriza AliExpress / URL de origen y cae a la página de la tienda.
 */
class ProductPageScrapeService
{
    public function __construct(
        protected CloudflareBrowserRenderer $browser,
        protected ProductMarketingMediaService $media,
    ) {}

    public function cloudflareReady(): bool
    {
        return $this->browser->enabled();
    }

    /**
     * @return list<string>
     */
    public function candidateUrls(Store $store, Product $product): array
    {
        $urls = [];

        if ($product->isFromAliExpress()) {
            $aeId = $product->aliexpressProductId();
            if ($aeId) {
                $urls[] = AliExpressProductFetcher::canonicalProductUrl($aeId);
            }
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        foreach (['source_url', 'product_url', 'url', 'import_url', 'original_url', 'aliexpress_url'] as $key) {
            $raw = trim((string) ($verified[$key] ?? ''));
            if ($raw !== '' && preg_match('#^https?://#i', $raw)) {
                $urls[] = $raw;
            }
        }

        $storefront = $this->publicStorefrontUrl($store, $product);
        if ($storefront !== '') {
            $urls[] = $storefront;
        }

        $out = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '' || isset($out[$url])) {
                continue;
            }
            // Cloudflare no puede abrir hosts locales / privados.
            if ($this->isUnreachablePublicHost($url)) {
                continue;
            }
            $out[$url] = $url;
        }

        return array_values($out);
    }

    /**
     * URL pública de ficha (reemplaza localhost del store por APP_URL).
     */
    protected function publicStorefrontUrl(Store $store, Product $product): string
    {
        $path = '';
        $slug = trim((string) $product->slug);
        if ($slug !== '') {
            $path = '/pages/'.rawurlencode($slug);
        }

        $base = rtrim($store->publicUrl(), '/');
        if ($base !== '' && ! $this->isUnreachablePublicHost($base)) {
            return $base.$path;
        }

        $app = rtrim((string) config('app.url'), '/');
        if ($app !== '' && ! $this->isUnreachablePublicHost($app)) {
            // Storefront público suele ser /s/{slug}/pages/...
            $storeSlug = trim((string) ($store->slug ?: ''));
            if ($storeSlug !== '' && $path !== '') {
                return $app.'/s/'.rawurlencode($storeSlug).$path;
            }

            return $app.$path;
        }

        return '';
    }

    protected function isUnreachablePublicHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return true;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{title?: string, description?: string, plain_text?: string, bullets?: list<string>}  $parsed
     */
    protected function isJunkScrape(array $parsed): bool
    {
        $title = mb_strtolower(trim((string) ($parsed['title'] ?? '')));
        $plain = mb_strtolower((string) ($parsed['plain_text'] ?? ''));
        $desc = trim((string) ($parsed['description'] ?? ''));
        $bullets = is_array($parsed['bullets'] ?? null) ? $parsed['bullets'] : [];

        if (in_array($title, ['localhost', 'blocked', 'access denied', '403 forbidden', 'just a moment...'], true)) {
            return true;
        }
        if (preg_match('/(doesn.?t allow you to view|access denied|cf-error|attention required|captcha|bot detection)/u', $plain)) {
            return true;
        }
        // Chrome de storefront / checkout / home (p.ej. "Inicio — BAZA").
        if ($this->looksLikeStoreChrome($title, $plain, $desc)) {
            return true;
        }
        // Solo título vacío / basura y sin descripción ni bullets útiles.
        if (($title === '' || $title === 'localhost') && $desc === '' && $bullets === []) {
            return true;
        }

        return false;
    }

    /**
     * Título/texto que es UI de tienda, no ficha de producto.
     */
    protected function looksLikeStoreChrome(string $title, string $plain, string $desc = ''): bool
    {
        $hay = mb_strtolower(trim($title.' '.$desc));
        if (preg_match('/^(inicio|home|cat[aá]logo|carrito|checkout|pasarela|tienda)\b/u', $hay)) {
            return true;
        }
        if (preg_match('/\b(inicio|home)\s*[—\-–|]\s*\w+/u', $hay)) {
            return true;
        }
        if (preg_match('/\b(pasarela de pago|te estamos llevando|también te puede gustar|sin resultados)\b/u', $plain)) {
            return true;
        }
        // Mucho chrome de nav y poco producto.
        $chromeHits = 0;
        foreach (['carrito', 'checkout', 'pasarela', 'catálogo', 'catalogo', 'mxn', 'locale', 'md-checkout', ':root'] as $w) {
            if (str_contains($plain, $w)) {
                $chromeHits++;
            }
        }

        return $chromeHits >= 3 && mb_strlen(trim($desc)) < 40;
    }

    /**
     * El scrape no aporta nada útil frente al catálogo (nombre/desc reales).
     *
     * @param  array<string, mixed>  $scrape
     */
    public function isWeakAgainstProduct(Product $product, array $scrape): bool
    {
        if (! ($scrape['success'] ?? false)) {
            return true;
        }
        if ($this->isJunkScrape($scrape)) {
            return true;
        }

        $productName = mb_strtolower(trim((string) $product->localizedName()));
        $scrapeTitle = mb_strtolower(trim((string) ($scrape['title'] ?? '')));
        if ($productName !== '' && $scrapeTitle !== '') {
            // Si el título scrapeado no comparte tokens con el nombre del producto → basura.
            $nameTokens = preg_split('/\s+/u', $productName) ?: [];
            $nameTokens = array_values(array_filter($nameTokens, static fn ($t) => mb_strlen($t) >= 4));
            $hits = 0;
            foreach (array_slice($nameTokens, 0, 6) as $tok) {
                if (str_contains($scrapeTitle, $tok)) {
                    $hits++;
                }
            }
            if ($nameTokens !== [] && $hits === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brief sintético desde el catálogo (sin Cloudflare).
     *
     * @return array{success: bool, url?: string, title: string, description: string, bullets: list<string>, plain_text: string, source_hint: string}
     */
    public function fromCatalog(Product $product): array
    {
        $name = $this->cleanLine((string) $product->localizedName(), 160);
        $desc = $this->cleanLine(strip_tags((string) ($product->localizedDescription() ?: $product->description ?: '')), 500);
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $bullets = [];
        foreach (is_array($verified['details'] ?? null) ? $verified['details'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['name'] ?? $row['label'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $line = $label !== '' && $value !== '' ? $label.': '.$value : ($value ?: $label);
            $line = $this->cleanLine($line, 90);
            if ($line !== '' && mb_strlen($line) >= 8) {
                $bullets[] = $line;
            }
            if (count($bullets) >= 8) {
                break;
            }
        }
        $ai = trim((string) ($verified['ai_summary'] ?? ''));
        if ($ai !== '') {
            foreach (preg_split("/\n+/", $ai) ?: [] as $line) {
                $line = $this->cleanLine(ltrim(trim($line), "-•* "), 90);
                if ($line !== '' && ! in_array($line, $bullets, true)) {
                    $bullets[] = $line;
                }
                if (count($bullets) >= 10) {
                    break;
                }
            }
        }

        $plain = trim(implode("\n", array_filter([$name, $desc, implode("\n", $bullets)])));

        return [
            'success' => true,
            'url' => null,
            'title' => $name,
            'description' => $desc,
            'bullets' => array_values(array_slice($bullets, 0, 10)),
            'plain_text' => mb_substr($plain, 0, 5000),
            'source_hint' => 'catalog',
            'from_catalog' => true,
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   url?: string,
     *   title?: string,
     *   description?: string,
     *   bullets?: list<string>,
     *   plain_text?: string,
     *   error?: string
     * }
     */
    public function scrape(Store $store, Product $product): array
    {
        if (! $this->browser->enabled()) {
            return [
                'success' => false,
                'error' => 'Activa Cloudflare Browser Rendering en Admin → General (Account ID + API Token).',
            ];
        }

        $candidates = $this->candidateUrls($store, $product);
        // Si el catálogo ya tiene ficha rica, no dependemos del storefront (suele ser chrome).
        $catalog = $this->fromCatalog($product);
        $hasRichCatalog = mb_strlen((string) ($catalog['description'] ?? '')) >= 60
            || count($catalog['bullets'] ?? []) >= 3;

        if ($candidates === []) {
            return $hasRichCatalog
                ? $catalog
                : ['success' => false, 'error' => 'No hay URL de producto para scrapear.'];
        }

        $lastError = 'No se pudo scrapear ninguna URL.';
        foreach ($candidates as $url) {
            // Evitar storefront propio si ya hay catálogo útil (Cloudflare trae nav/checkout).
            $isStorefront = (bool) preg_match('#/s/[^/]+/pages/#i', $url)
                || str_contains(strtolower($url), strtolower((string) $store->slug));
            if ($hasRichCatalog && $isStorefront && $product->isFromAliExpress()) {
                continue;
            }

            $render = $this->browser->render($url, [
                'waitUntil' => 'domcontentloaded',
                'timeout_ms' => 35000,
                'http_timeout' => 95,
                'rejectResourceTypes' => ['image', 'media', 'font', 'stylesheet'],
                'waitForSelector' => [
                    'selector' => 'h1, meta[property="og:title"], title',
                    'timeout' => 20000,
                ],
            ]);

            if (! ($render['success'] ?? false)) {
                $lastError = (string) ($render['error'] ?? 'Error Cloudflare');
                Log::info('ProductPageScrapeService: fallo URL', ['url' => $url, 'error' => $lastError]);

                continue;
            }

            $parsed = $this->parseHtml((string) ($render['html'] ?? ''), $url);
            if (($parsed['plain_text'] ?? '') === '' && ($parsed['title'] ?? '') === '') {
                $lastError = 'HTML sin texto útil: '.$url;

                continue;
            }
            if ($this->isJunkScrape($parsed) || $this->isWeakAgainstProduct($product, array_merge(['success' => true], $parsed))) {
                $lastError = 'Página bloqueada o sin contenido de producto: '.$url;
                Log::info('ProductPageScrapeService: scrape basura descartado', [
                    'url' => $url,
                    'title' => $parsed['title'] ?? '',
                ]);

                continue;
            }

            Log::info('ProductPageScrapeService: OK', [
                'url' => $url,
                'bytes' => $render['bytes'] ?? 0,
                'bullets' => count($parsed['bullets'] ?? []),
            ]);

            return array_merge(['success' => true, 'url' => $url], $parsed);
        }

        // Fallback: catálogo local (mejor que chrome de BAZA).
        if ($hasRichCatalog || trim((string) ($catalog['title'] ?? '')) !== '') {
            Log::info('ProductPageScrapeService: usando catálogo local', [
                'product_id' => $product->id,
                'last_error' => $lastError,
            ]);

            return $catalog;
        }

        return ['success' => false, 'error' => $lastError];
    }

    /**
     * @return array{title: string, description: string, bullets: list<string>, plain_text: string}
     */
    public function parseHtml(string $html, string $url = ''): array
    {
        $title = $this->metaContent($html, 'og:title')
            ?: $this->metaName($html, 'twitter:title')
            ?: $this->tagInner($html, 'title')
            ?: $this->tagInner($html, 'h1');

        $description = $this->metaContent($html, 'og:description')
            ?: $this->metaName($html, 'description')
            ?: $this->metaName($html, 'twitter:description');

        $bullets = $this->extractListItems($html);
        $headings = $this->extractHeadings($html);

        $clean = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $clean = preg_replace('#<!--.*?-->#s', ' ', $clean) ?? $clean;
        $plain = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[ \t]+/u', ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{3,}/u", "\n\n", $plain) ?? $plain;
        $plain = trim($plain);
        $plain = mb_substr($plain, 0, 5000);

        $title = $this->cleanLine($title, 160);
        $description = $this->cleanLine($description, 500);

        foreach ($headings as $h) {
            if ($h !== '' && $h !== $title && count($bullets) < 8 && ! in_array($h, $bullets, true)) {
                $bullets[] = $h;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'bullets' => array_values(array_slice($bullets, 0, 10)),
            'plain_text' => $plain,
            'source_hint' => $url,
        ];
    }

    /**
     * Resumen seguro para UI / poll (sin HTML crudo).
     *
     * @param  array<string, mixed>  $scrape
     * @return array{url:?string,title:?string,description:?string,bullets:list<string>,plain_excerpt:?string}
     */
    public function publicSummary(array $scrape): array
    {
        $bullets = [];
        foreach (is_array($scrape['bullets'] ?? null) ? $scrape['bullets'] : [] as $b) {
            $line = $this->cleanLine((string) $b, 90);
            if ($line !== '') {
                $bullets[] = $line;
            }
            if (count($bullets) >= 6) {
                break;
            }
        }

        return [
            'url' => isset($scrape['url']) ? (string) $scrape['url'] : null,
            'title' => $this->cleanLine((string) ($scrape['title'] ?? ''), 120) ?: null,
            'description' => $this->cleanLine((string) ($scrape['description'] ?? ''), 280) ?: null,
            'bullets' => $bullets,
            'plain_excerpt' => $this->cleanLine((string) ($scrape['plain_text'] ?? ''), 420) ?: null,
        ];
    }

    /**
     * Ángulo comercial ligero a partir del scrape + precio.
     *
     * @param  array<string, mixed>  $scrape
     * @return array{type: string, label: string, problem: string, value: string, hook: string, beats: list<string>}
     */
    public function lightCreativeAngle(Product $product, array $scrape): array
    {
        // Corpus prioriza catálogo; el scrape solo si no es chrome.
        $productName = (string) $product->localizedName();
        $productDesc = (string) ($product->localizedDescription() ?: $product->description ?: '');
        $useScrapeText = ! $this->isJunkScrape($scrape) && empty($scrape['from_catalog']);
        $corpus = mb_strtolower(implode(' ', array_filter([
            $productName,
            $productDesc,
            $useScrapeText ? (string) ($scrape['description'] ?? '') : '',
            $useScrapeText ? implode(' ', is_array($scrape['bullets'] ?? null) ? $scrape['bullets'] : []) : '',
            // Nunca meter plain_text de storefront (lleva "tiempo limitado", nav, JS).
        ])));

        $type = 'innovador';
        $label = 'Innovador';
        if ($this->looksEconomical($product, $corpus)) {
            $type = 'economico';
            $label = 'Económico';
        } elseif (preg_match('/\b(bonit|eleganc|diseñ|design|beaut|belleza|estilo|aesthetic|premium|lujo|luxe|minimal|lindo|cute|gato|cat)\b/u', $corpus)) {
            $type = 'bonito';
            $label = 'Bonito';
        } elseif (preg_match('/\b(nuevo|smart|inteligente|innov|tech|gadget|rápid|rapid|eficiente|mejor)\b/u', $corpus)) {
            $type = 'innovador';
            $label = 'Innovador';
        }

        $problem = $this->inferProblem($corpus, $type, $productName);
        $value = $this->inferValueProp($product, $scrape, $type, $label);
        $hook = $this->cleanLine($problem, 52);
        $beats = [
            $hook,
            $this->cleanLine($value, 52),
            $this->cleanLine($this->pickBenefit($scrape, $type, $product), 52),
            $this->cleanLine('Pídelo hoy y nota la diferencia', 48),
        ];

        return [
            'type' => $type,
            'label' => $label,
            'problem' => $problem,
            'value' => $value,
            'hook' => $hook,
            'beats' => $beats,
        ];
    }

    /**
     * Brief mínimo sin MIIA: ligero, problema → solución → ángulo.
     *
     * @param  array<string, mixed>  $scrape
     * @return array{prompt: array<string, mixed>, segments: list<array<string, mixed>>, analysis: array<string, mixed>}
     */
    public function buildFallbackBrief(Product $product, array $scrape, string $language = 'es'): array
    {
        $name = $this->cleanLine((string) $product->localizedName(), 80);
        if ($name === '') {
            $name = $this->cleanLine((string) ($scrape['title'] ?? 'Producto'), 80);
        }
        $angle = $this->lightCreativeAngle($product, $scrape);
        $hook = $angle['hook'];
        $value = $angle['value'];
        $cta = 'Cómpralo ahora en la tienda';
        $beats = $angle['beats'];

        $segments = [];
        $t = 0;
        $voiceovers = [
            ['type' => 'hook', 'line' => $beats[0]],
            ['type' => 'solution', 'line' => $this->cleanLine($name.' lo resuelve', 56)],
            ['type' => 'value', 'line' => $beats[1]],
            ['type' => 'proof', 'line' => $beats[2]],
            ['type' => 'cta', 'line' => $cta],
        ];
        foreach ($voiceovers as $i => $row) {
            $end = $t + ($row['type'] === 'cta' ? 4 : 5);
            $line = $row['line'];
            $segments[] = [
                'index' => $i + 1,
                'start' => $t,
                'end' => $end,
                'duration' => $end - $t,
                'type' => $row['type'],
                'voiceover' => $line,
                'talent' => 'Presenta el producto con energía ligera',
                'camera' => '9:16, corte limpio',
                'visual' => 'Producto enmarcado, no full-bleed',
                'text_on_screen' => mb_substr($line, 0, 42),
                'audio' => 'Música suave',
                'transition' => 'cut',
                'media_hint' => 'product_closeup',
            ];
            $t = $end;
        }

        $source = ! empty($scrape['from_catalog']) ? 'catalog' : 'cloudflare_scrape_fallback';
        $script = "=== BRIEF CATÁLOGO ===\n\n"
            ."Producto: {$name}\n"
            ."Problema: {$angle['problem']}\n"
            ."Ángulo: {$angle['label']} — {$value}\n"
            ."Hook: {$hook}\n"
            .'Beats: '.implode(' · ', $beats)."\n"
            ."CTA: {$cta}\n";

        return [
            'prompt' => [
                'name' => mb_substr('HF · '.$name, 0, 120),
                'hook' => mb_substr($hook, 0, 240),
                'script' => mb_substr($script, 0, 4000),
                'audience' => 'Compradores que buscan una solución simple',
                'language' => $language,
                'style' => 'CatalogPopTemplate',
                'script_style' => 'ProductSpotlight',
                'target_platform' => 'Tiktok',
                'video_length' => min(30, $t),
                'cta' => $cta,
            ],
            'segments' => $segments,
            'analysis' => [
                'summary' => $value,
                'product_angle' => $value,
                'problem' => $angle['problem'],
                'angle_type' => $angle['type'],
                'angle_label' => $angle['label'],
                'value_prop' => $value,
                'beats' => $beats,
                'cta' => $cta,
                'recommended_format' => 'mixed',
                'video_length_seconds' => min(30, $t),
                'source' => $source,
                'scraped_url' => $scrape['url'] ?? null,
                'scrape' => $this->publicSummary($scrape),
                'generated_at' => now()->toIso8601String(),
                'product_id' => $product->id,
            ],
        ];
    }

    protected function looksEconomical(Product $product, string $corpus): bool
    {
        if (preg_match('/\b(barat|económ|econom|oferta|descuento|precio|ahorr|low.?cost|budget|deal)\b/u', $corpus)) {
            return true;
        }
        $price = $product->price !== null ? (float) $product->price : null;
        $compare = $product->compare_at_price !== null ? (float) $product->compare_at_price : null;
        if ($price !== null && $compare !== null && $compare > $price * 1.15) {
            return true;
        }

        return $price !== null && $price > 0 && $price < 25;
    }

    protected function inferProblem(string $corpus, string $type, string $productName = ''): string
    {
        // Hooks ligados al producto (no genéricos de chrome).
        if (preg_match('/\b(gato|cat|kitty|felino)\b/u', $corpus)) {
            return '¿Fan de los gatos y el orden?';
        }
        if (preg_match('/\b(lápiz|lapiz|bolígrafo|boligrafo|pen|pencil|estuche|pencil case|útil(?:es)? escolar)\b/u', $corpus)) {
            return '¿Tu escritorio es un caos de útiles?';
        }
        if (preg_match('/\b(espacio|desorden|organiz)\b/u', $corpus)) {
            return '¿Cansado del desorden diario?';
        }
        // Evitar "tiempo" suelto (dispara con "oferta por tiempo limitado" del chrome).
        if (preg_match('/\b(lento|demora|esperando|pérdida de tiempo|pierdes horas)\b/u', $corpus)) {
            return '¿Pierdes tiempo en lo mismo cada día?';
        }
        if (preg_match('/\b(calor|frío|frio|ruido|dolor|cansanc)\b/u', $corpus)) {
            return '¿Eso te complica el día a día?';
        }
        if (preg_match('/\b(cable|carga|bater|conect)\b/u', $corpus)) {
            return '¿Harto de cables y recargas a medias?';
        }

        $short = $this->cleanLine($productName, 36);
        if ($short !== '' && $type === 'bonito') {
            return '¿Buscas algo cute que también sirva?';
        }

        return match ($type) {
            'economico' => '¿Pagas de más por lo mismo?',
            'bonito' => '¿Tu espacio pide un upgrade visual?',
            default => '¿Buscas una solución más simple?',
        };
    }

    /**
     * @param  array<string, mixed>  $scrape
     */
    protected function inferValueProp(Product $product, array $scrape, string $type, string $label): string
    {
        $name = $this->cleanLine((string) $product->localizedName(), 48);
        if ($name === '') {
            $rawTitle = (string) ($scrape['title'] ?? '');
            if ($rawTitle !== '' && ! $this->looksLikeStoreChrome(mb_strtolower($rawTitle), '', '')) {
                $name = $this->cleanLine($rawTitle, 40);
            }
        }

        $desc = $this->cleanLine(strip_tags((string) ($product->localizedDescription() ?: $product->description ?: '')), 80);
        if ($desc === '' && ! empty($scrape['from_catalog'])) {
            $desc = $this->cleanLine((string) ($scrape['description'] ?? ''), 80);
        }
        if ($desc !== '' && mb_strlen($desc) > 24 && ! $this->looksLikeStoreChrome('', mb_strtolower($desc), $desc)) {
            return $desc;
        }

        return match ($type) {
            'economico' => $name !== '' ? "{$name}: más por menos" : 'Más por menos, sin rodeos',
            'bonito' => $name !== '' ? "{$name}: lindo y práctico" : 'Diseño que se nota de cerca',
            default => $name !== '' ? "{$name} lo hace fácil" : 'Una solución más inteligente',
        };
    }

    /**
     * @param  array<string, mixed>  $scrape
     */
    protected function pickBenefit(array $scrape, string $type, ?Product $product = null): string
    {
        if ($product) {
            $verified = is_array($product->verified_data) ? $product->verified_data : [];
            foreach (is_array($verified['details'] ?? null) ? $verified['details'] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $line = $this->cleanLine(
                    trim((string) ($row['name'] ?? '')).': '.trim((string) ($row['value'] ?? '')),
                    52
                );
                if (mb_strlen($line) >= 12 && ! preg_match('/^(sku|origen|pcb)\b/iu', $line)) {
                    return $line;
                }
            }
        }
        foreach (is_array($scrape['bullets'] ?? null) ? $scrape['bullets'] : [] as $b) {
            $line = $this->cleanLine((string) $b, 52);
            if (mb_strlen($line) >= 12 && ! $this->looksLikeStoreChrome(mb_strtolower($line), mb_strtolower($line))) {
                return $line;
            }
        }

        return match ($type) {
            'economico' => 'Calidad real sin gastar de más',
            'bonito' => 'Diseño que se nota de cerca',
            default => 'Fácil de usar desde el día uno',
        };
    }

    protected function metaContent(string $html, string $property): string
    {
        if (preg_match(
            '/<meta[^>]+property=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']+)["\']/i',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match(
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.preg_quote($property, '/').'["\']/i',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    protected function metaName(string $html, string $name): string
    {
        if (preg_match(
            '/<meta[^>]+name=["\']'.preg_quote($name, '/').'["\'][^>]+content=["\']([^"\']+)["\']/i',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match(
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']'.preg_quote($name, '/').'["\']/i',
            $html,
            $m
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    protected function tagInner(string $html, string $tag): string
    {
        if (preg_match('/<'.$tag.'[^>]*>(.*?)<\/'.$tag.'>/is', $html, $m)) {
            return html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    /**
     * @return list<string>
     */
    protected function extractListItems(string $html): array
    {
        $items = [];
        if (! preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $matches)) {
            return [];
        }
        foreach ($matches[1] as $raw) {
            $line = $this->cleanLine(strip_tags($raw), 90);
            if (mb_strlen($line) < 8 || mb_strlen($line) > 90) {
                continue;
            }
            if (in_array($line, $items, true)) {
                continue;
            }
            $items[] = $line;
            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    protected function extractHeadings(string $html): array
    {
        $out = [];
        if (! preg_match_all('/<h[2-4][^>]*>(.*?)<\/h[2-4]>/is', $html, $matches)) {
            return [];
        }
        foreach ($matches[1] as $raw) {
            $line = $this->cleanLine(strip_tags($raw), 80);
            if (mb_strlen($line) < 6) {
                continue;
            }
            $out[] = $line;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    protected function cleanLine(string $text, int $limit): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit - 1).'…';
        }

        return $text;
    }
}
