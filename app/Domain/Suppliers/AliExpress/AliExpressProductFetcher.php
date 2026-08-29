<?php

namespace App\Domain\Suppliers\AliExpress;

use App\Domain\Scraping\CloudflareBrowserRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AliExpressProductFetcher
{
    public function __construct(
        protected AliExpressAffiliateClient $affiliate,
        protected CloudflareBrowserRenderer $browser,
    ) {}

    public static function looksLikeAliExpress(string $input): bool
    {
        $t = trim($input);
        if ($t === '') {
            return false;
        }
        if (preg_match('/aliexpress\./i', $t)) {
            return true;
        }

        return (bool) preg_match('/^(100\d{10,16}|\d{13,16})$/', $t);
    }

    public static function parseProductId(string $input): ?string
    {
        $t = trim($input);
        if ($t === '') {
            return null;
        }
        if (preg_match('/(?:item|i)\/(\d{10,20})/i', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/[?&](?:productId|product_id|item_id)=(\d{10,20})/i', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{10,20})$/', $t, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function canonicalProductUrl(string $productId, string $preferredHost = 'www.aliexpress.com'): string
    {
        $id = preg_replace('/\D+/', '', $productId) ?? '';
        $host = preg_replace('#^https?://#i', '', trim($preferredHost)) ?: 'www.aliexpress.com';
        $host = explode('/', $host)[0] ?: 'www.aliexpress.com';
        if (! preg_match('/aliexpress\./i', $host)) {
            $host = 'www.aliexpress.com';
        }

        return 'https://'.$host.'/item/'.$id.'.html';
    }

    /**
     * URL de ficha real de AliExpress (no trackers tipo Criteo con ?an=www.aliexpress.com).
     */
    public static function isProductPageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || ! preg_match('/(^|\.)aliexpress\./i', $host)) {
            return false;
        }
        if (preg_match('#/(?:item|i)/\d{10,20}#i', $url)) {
            return true;
        }

        return self::parseProductId($url) !== null;
    }

    /**
     * @return array{success: bool, product?: array<string, mixed>, error?: string}
     */
    public function fetch(string $input): array
    {
        $resolved = $this->resolveInput($input);
        $id = self::parseProductId($resolved);
        if (! $id) {
            return ['success' => false, 'error' => 'No pude extraer el ID de AliExpress de esa URL.'];
        }

        $url = 'https://es.aliexpress.com/item/'.$id.'.html';
        $apiProduct = null;
        $apiError = null;

        if ($this->affiliate->isConfigured()) {
            $api = $this->affiliate->productDetail($id);
            if ($api['success'] ?? false) {
                $row = $api['products'][0] ?? null;
                if (is_array($row)) {
                    $apiProduct = $this->normalizeAffiliate($row, $id, $url);
                }
            } else {
                $apiError = $api['error'] ?? 'Affiliate API falló';
            }
        }

        $scraped = $this->scrape($id, $url);
        $scrapeProduct = ($scraped['success'] ?? false) ? ($scraped['product'] ?? null) : null;
        $scrapeError = $scraped['error'] ?? null;

        if (is_array($apiProduct) && is_array($scrapeProduct)) {
            $merged = $this->merge($apiProduct, $scrapeProduct);
            $via = (string) ($scrapeProduct['source_mode'] ?? 'scrape');
            $merged['source_mode'] = $via === 'cloudflare' ? 'api+cloudflare' : 'api';
            $merged['source_note'] = $via === 'cloudflare'
                ? 'API Affiliate + Cloudflare Browser Rendering'
                : 'API Affiliate + ficha HTML';

            return ['success' => true, 'product' => $merged];
        }

        if (is_array($apiProduct)) {
            $apiProduct['source_mode'] = 'api';
            $apiProduct['source_note'] = 'API Affiliate';

            return ['success' => true, 'product' => $apiProduct];
        }

        if (is_array($scrapeProduct)) {
            $via = (string) ($scrapeProduct['source_mode'] ?? 'scrape');
            $label = $via === 'cloudflare' ? 'Cloudflare Browser Rendering' : 'Scrape HTML';
            $scrapeProduct['source_note'] = $apiError
                ? ($label.' (API: '.$apiError.')')
                : ($label.' (sin API Affiliate)');

            return ['success' => true, 'product' => $scrapeProduct];
        }

        return [
            'success' => false,
            'error' => $apiError ?: $scrapeError ?: 'No se pudo obtener la ficha de AliExpress.',
        ];
    }

    /**
     * Parsea HTML (y opcionalmente un snapshot JS) capturado en el navegador.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{success: bool, product?: array<string, mixed>, error?: string}
     */
    public function parseFromCapture(string $html, string $url = '', array $snapshot = []): array
    {
        $html = trim($html);
        $url = trim($url);
        if ($html === '' && $snapshot === []) {
            return ['success' => false, 'error' => 'No recibí HTML ni snapshot.'];
        }

        $html = $this->injectSnapshot($html !== '' ? $html : '<html></html>', $snapshot);

        $id = self::parseProductId($url)
            ?: self::parseProductId($html)
            ?: (string) ($snapshot['productId'] ?? $snapshot['product_id'] ?? '');
        if ($id === '' || ! preg_match('/^\d{10,20}$/', $id)) {
            return ['success' => false, 'error' => 'No pude extraer el ID de AliExpress del HTML. Abre una ficha /item/…'];
        }

        $url = $this->resolveCapturedProductUrl($url, $html, $snapshot, $id);

        $product = $this->parseHtml($html, $id, $url);
        if ($product === null) {
            return ['success' => false, 'error' => 'No pude parsear título/imágenes. Espera a que la ficha AE termine de cargar y vuelve a capturar.'];
        }

        $h1 = trim((string) ($snapshot['h1'] ?? $snapshot['ogTitle'] ?? ''));
        if ($h1 !== '' && (str_starts_with((string) $product['title'], 'Producto AliExpress') || ($product['title'] ?? '') === '')) {
            $product['title'] = mb_substr($h1, 0, 255);
        }
        $ogImage = $this->absUrl((string) ($snapshot['ogImage'] ?? ''));
        if ($ogImage !== '' && ($product['image'] ?? '') === '') {
            $product['image'] = $ogImage;
            array_unshift($product['images'], $ogImage);
            $product['images'] = array_values(array_unique(array_filter($product['images'])));
        }
        $priceHint = trim((string) ($snapshot['priceText'] ?? $snapshot['price'] ?? ''));
        if ($priceHint !== '') {
            $hintPrice = $this->toFloat($priceHint);
            $hintCur = $this->detectCurrencyFromText($priceHint);
            if ($hintPrice) {
                $product['price'] = $hintPrice;
            }
            if ($hintCur) {
                $product['currency'] = $hintCur;
            }
        }
        $shipHint = trim((string) ($snapshot['shippingText'] ?? $snapshot['deliveryText'] ?? ''));
        if ($shipHint !== '') {
            if (($product['shipping_note'] ?? null) === null || ($product['shipping_note'] ?? '') === '') {
                $product['shipping_note'] = mb_substr($shipHint, 0, 180);
            }
            if (empty($product['shipping_time'])) {
                $product['shipping_time'] = $this->shippingTimeFromText($shipHint);
            }
            if ($product['shipping_price'] === null && preg_match('/gratis|free\s+shipping|envio\s+0/i', $shipHint)) {
                $product['shipping_price'] = 0.0;
            }
        }

        if (($product['description_html'] ?? '') === '' || strlen(strip_tags((string) $product['description_html'])) < 20) {
            $snapDesc = trim((string) ($snapshot['descriptionHtml'] ?? $snapshot['description_html'] ?? ''));
            if ($snapDesc !== '') {
                $product['description_html'] = mb_substr($this->sanitizeHtml($snapDesc), 0, 20000);
            }
        }
        if (($product['description_html'] ?? '') === '' || strlen(strip_tags((string) $product['description_html'])) < 20) {
            $descUrl = trim((string) ($snapshot['descriptionUrl'] ?? $snapshot['description_url'] ?? ''));
            if ($descUrl === '') {
                $descUrl = (string) ($this->extractDescriptionUrl($html) ?? '');
            }
            $remote = $this->fetchDescriptionHtml($descUrl !== '' ? $descUrl : null);
            if ($remote !== '') {
                $product['description_html'] = $remote;
                $product['description'] = mb_substr(strip_tags($remote), 0, 4000);
            }
        }

        $product['source_mode'] = ! empty($snapshot) ? 'plugin' : 'html';
        $product['source_note'] = ! empty($snapshot)
            ? 'Plugin / snapshot del navegador'
            : 'HTML pegado en Product Hunter';

        $product = $this->enrichFromRemote($product, $id, $url, $html);

        return ['success' => true, 'product' => $product];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function resolveCapturedProductUrl(string $url, string $html, array $snapshot, string $id): string
    {
        $candidates = [];
        foreach ([
            $url,
            (string) ($snapshot['url'] ?? $snapshot['pageUrl'] ?? $snapshot['canonicalUrl'] ?? ''),
            (string) ($this->matchOne($html, '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i') ?: ''),
            (string) ($this->matchOne($html, '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i') ?: ''),
        ] as $candidate) {
            $candidate = trim(html_entity_decode((string) $candidate, ENT_QUOTES, 'UTF-8'));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        if (preg_match_all('#https?://(?:[a-z0-9-]+\.)*aliexpress\.[^\s"\'<>]+#i', $html, $matches)) {
            foreach ($matches[0] as $candidate) {
                $candidates[] = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
            }
        }

        foreach ($candidates as $candidate) {
            if (! self::isProductPageUrl($candidate)) {
                continue;
            }
            $candidateId = self::parseProductId($candidate);
            if ($candidateId !== null && $candidateId !== $id) {
                continue;
            }
            $host = (string) (parse_url($candidate, PHP_URL_HOST) ?: 'www.aliexpress.com');

            return self::canonicalProductUrl($id, $host);
        }

        return self::canonicalProductUrl($id);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function injectSnapshot(string $html, array $snapshot): string
    {
        $chunks = '';
        $run = $snapshot['runParams'] ?? $snapshot['run_params'] ?? null;
        if (is_array($run) && $run !== []) {
            $chunks .= '<script>window.runParams = '.json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).';</script>';
        }
        $dc = $snapshot['dcData'] ?? $snapshot['DCData'] ?? null;
        if (is_array($dc) && $dc !== []) {
            $chunks .= '<script>window._d_c_=window._d_c_||{};window._d_c_.DCData = '.json_encode($dc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).';</script>';
        }
        $init = $snapshot['initData'] ?? $snapshot['__INIT_DATA__'] ?? null;
        if (is_array($init) && $init !== []) {
            $chunks .= '<script>window.__INIT_DATA__ = '.json_encode($init, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).';</script>';
        }

        return $chunks.$html;
    }

    protected function resolveInput(string $input): string
    {
        $t = trim($input);
        if (! preg_match('#^https?://#i', $t)) {
            return $t;
        }
        if (! preg_match('/s\.click\.aliexpress\.|a\.aliexpress\./i', $t)) {
            return $t;
        }

        try {
            $res = Http::withHeaders($this->browserHeaders())
                ->timeout(18)
                ->withOptions(['allow_redirects' => ['max' => 8, 'track_redirects' => true]])
                ->get($t);
            $final = (string) ($res->effectiveUri() ?? '');
            if ($final !== '' && self::parseProductId($final)) {
                return $final;
            }
            $headerUrl = $res->header('X-Guzzle-Redirect-History');
            if (is_string($headerUrl) && self::parseProductId($headerUrl)) {
                return $headerUrl;
            }
        } catch (\Throwable) {
            // seguir con la URL original
        }

        return $t;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeAffiliate(array $row, string $id, string $url): array
    {
        $images = [];
        $main = $this->absUrl((string) ($row['product_main_image_url'] ?? $row['productMainImageUrl'] ?? ''));
        if ($main !== '') {
            $images[] = $main;
        }
        $small = $row['product_small_image_urls'] ?? $row['productSmallImageUrls'] ?? null;
        if (is_array($small)) {
            $list = $small['string'] ?? $small;
            if (is_array($list)) {
                foreach ($list as $img) {
                    $u = $this->absUrl(is_string($img) ? $img : '');
                    if ($u !== '') {
                        $images[] = $u;
                    }
                }
            }
        }

        $images = array_values(array_unique($images));
        $price = $this->toFloat($row['target_sale_price'] ?? $row['sale_price'] ?? $row['targetSalePrice'] ?? null);
        $compare = $this->toFloat($row['target_original_price'] ?? $row['original_price'] ?? null);
        $currency = strtoupper((string) ($row['target_sale_price_currency'] ?? $row['target_original_price_currency'] ?? config('aliexpress.target_currency', 'USD')));

        $title = (string) ($row['product_title'] ?? $row['productTitle'] ?? 'Producto AliExpress');

        return [
            'product_id' => (string) ($row['product_id'] ?? $id),
            'url' => (string) ($row['product_detail_url'] ?? $url),
            'title' => mb_substr($title, 0, 255),
            'image' => $images[0] ?? '',
            'images' => $images,
            'videos' => [],
            'variants' => [],
            'description' => (string) ($row['product_title'] ?? ''),
            'description_html' => '',
            'description_short' => mb_substr($title, 0, 280),
            'price' => $price,
            'compare_at_price' => $compare,
            'currency' => $currency ?: 'USD',
            'sku' => (string) ($row['product_id'] ?? $id),
            'skus' => [],
            'category' => (string) ($row['first_level_category_name'] ?? $row['second_level_category_name'] ?? ''),
            'shop_name' => (string) ($row['shop_id'] ?? ''),
            'rating' => $this->toFloat($row['evaluate_rate'] ?? null),
            'orders' => isset($row['lastest_volume']) ? (int) $row['lastest_volume'] : null,
            'has_video' => false,
        ];
    }

    /**
     * @return array{success: bool, product?: array<string, mixed>, error?: string}
     */
    protected function scrape(string $id, string $url): array
    {
        $via = 'http';
        $html = null;
        $product = null;
        $candidates = array_values(array_unique([
            'https://www.aliexpress.com/item/'.$id.'.html',
            $url,
            'https://www.aliexpress.us/item/'.$id.'.html',
            'https://m.aliexpress.com/item/'.$id.'.html',
        ]));

        $lastCfError = null;
        if ($this->browser->enabled()) {
            $cfOpts = [
                'waitUntil' => 'networkidle0',
                'timeout_ms' => 30000,
                'bestAttempt' => false,
                'waitForSelector' => [
                    'selector' => 'h1, meta[property="og:title"]',
                    'timeout' => 25000,
                ],
                'waitForTimeout' => 3500,
                'viewport' => ['width' => 1366, 'height' => 900],
                'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8',
                ],
                'http_timeout' => 80,
            ];
            foreach ($candidates as $candidate) {
                $rendered = $this->browser->render($candidate, $cfOpts);
                if (! ($rendered['success'] ?? false)) {
                    $lastCfError = (string) ($rendered['error'] ?? 'HTTP '.($rendered['status'] ?? '?'));
                    continue;
                }
                $try = (string) ($rendered['html'] ?? '');
                if (strlen($try) < 400) {
                    continue;
                }
                $parsed = $this->parseHtml($try, $id, $url);
                if ($parsed !== null) {
                    $html = $try;
                    $product = $parsed;
                    $via = 'cloudflare';
                    break;
                }
                $this->logUnparseable($candidate, $try);
            }
        }

        if ($product === null) {
            foreach ($candidates as $candidate) {
                $try = $this->fetchHtml($candidate);
                if (! is_string($try) || strlen($try) < 400) {
                    continue;
                }
                $parsed = $this->parseHtml($try, $id, $url);
                if ($parsed !== null) {
                    $html = $try;
                    $product = $parsed;
                    $via = 'http';
                    break;
                }
            }
        }

        if ($product === null || $html === null) {
            $hint = $this->browser->enabled()
                ? ('Cloudflare renderizó pero no pude extraer título/imágenes de AliExpress'
                    .($lastCfError ? ' (CF: '.$lastCfError.')' : '')
                    .'. Prueba otra URL de item o la Affiliate API.')
                : 'AliExpress bloqueó o no devolvió la ficha HTML. Activa Cloudflare Browser Rendering en General.';

            return ['success' => false, 'error' => $hint];
        }

        if (($product['description_html'] ?? '') === '' || strlen(strip_tags((string) $product['description_html'])) < 20) {
            $descUrl = $this->extractDescriptionUrl($html);
            $remote = $this->fetchDescriptionHtml($descUrl);
            if ($remote !== '') {
                $product['description_html'] = $remote;
                $product['description'] = mb_substr(strip_tags($remote), 0, 4000);
            }
        }

        $product['source_mode'] = $via === 'cloudflare' ? 'cloudflare' : 'scrape';
        $product['source_note'] = $via === 'cloudflare'
            ? 'Cloudflare Browser Rendering'
            : 'Scrape HTML directo';

        $product = $this->enrichFromRemote($product, $id, $url, $html);

        return ['success' => true, 'product' => $product];
    }

    protected function fetchHtml(string $url): ?string
    {
        try {
            $res = Http::withHeaders($this->browserHeaders())
                ->timeout(22)
                ->withOptions(['allow_redirects' => true])
                ->get($url);
            if (! $res->successful()) {
                return null;
            }
            $body = $res->body();

            return is_string($body) && $body !== '' ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseHtml(string $html, string $id, string $url): ?array
    {
        $html = $this->unwrapDeclarativeShadowDom($html);
        $run = $this->extractRunParams($html);

        $title = (string) ($run['title'] ?? '');
        if ($title === '') {
            $title = $this->matchOne($html, '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i')
                ?: $this->matchOne($html, '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i')
                ?: $this->matchOne($html, '/<h1[^>]*>([^<]{8,220})<\/h1>/i')
                ?: $this->matchOne($html, '/<title>([^<]{8,220})<\/title>/i')
                ?: $this->matchOne($html, '/"subject"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/i')
                ?: $this->matchOne($html, '/"title"\s*:\s*"((?:\\\\.|[^"\\\\]){8,180})"/i');
            $title = $title ? html_entity_decode(stripslashes($title), ENT_QUOTES, 'UTF-8') : '';
        }
        if (preg_match('/^aliexpress(\s*[-|:].*)?$/i', trim($title))) {
            $title = '';
        }

        $jsonLd = $this->extractJsonLd($html);
        $images = $this->resolveImages($html, $run, is_array($jsonLd) ? $jsonLd : null);

        $visible = $this->extractVisiblePrice($html);
        $price = $visible['price']
            ?? $run['price']
            ?? $this->toFloat(
                $this->matchOne($html, '/"salePriceValue"\s*:\s*"?([0-9.]+)"?/i')
                ?: $this->matchOne($html, '/"minPrice"\s*:\s*"?([0-9.]+)"?/i')
                ?: $this->matchOne($html, '/"actSkuCalPrice"\s*:\s*"?([0-9.]+)"?/i')
            );
        $compareRaw = (string) ($this->matchOne($html, '/"formatedPrice"\s*:\s*"([^"]+)"/i') ?: '');
        $compare = $this->toFloat(
            $compareRaw !== '' ? $compareRaw : $this->matchOne($html, '/"skuValPrice"\s*:\s*"?([0-9.]+)"?/i')
        );

        $jsonLd = $this->extractJsonLd($html);
        $currency = $this->resolveCurrency($html, $run, $visible, is_array($jsonLd) ? $jsonLd : null);

        $variantData = $this->resolveVariants($html, $run);
        $variants = $variantData['variants'];
        $attributes = $variantData['attributes'];
        if (($price === null || $price <= 0) && isset($run['price'])) {
            $price = $run['price'];
        }
        if (($compare === null || $compare <= 0) && isset($run['compare_at_price'])) {
            $compare = $run['compare_at_price'];
        }
        $videos = $this->extractVideos($html);
        if ($videos === [] && ! empty($run['videos']) && is_array($run['videos'])) {
            $videos = $run['videos'];
        }
        $reviews = $this->extractReviews($html, $run);
        $shipping = $this->extractShipping($html, $run);
        $skus = [];
        foreach ($variants as $v) {
            $sku = trim((string) ($v['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $descHtml = (string) ($run['description_html'] ?? '');
        if (is_array($jsonLd)) {
            if ($title === '' && ! empty($jsonLd['name'])) {
                $title = (string) $jsonLd['name'];
            }
            if ($price === null && isset($jsonLd['offers']['price'])) {
                $price = $this->toFloat($jsonLd['offers']['price']);
            }
            if ($descHtml === '' && ! empty($jsonLd['description'])) {
                $descHtml = '<p>'.e((string) $jsonLd['description']).'</p>';
            }
        }
        $descHtml = $this->resolveDescriptionHtml($html, array_merge($run, [
            'description_html' => $descHtml,
        ]));
        $details = $this->extractDetails($html, $run);

        if ($title === '' && $images === []) {
            return null;
        }
        if ($this->looksLikeBlockPage($title, $html)) {
            return null;
        }

        $title = $title !== '' ? mb_substr($title, 0, 255) : 'Producto AliExpress '.$id;

        return [
            'product_id' => $id,
            'url' => $url,
            'title' => $title,
            'image' => $images[0] ?? '',
            'images' => array_values(array_filter($images)),
            'videos' => $videos,
            'variants' => $variants,
            'attributes' => $attributes,
            'details' => $details,
            'description' => mb_substr(strip_tags($descHtml ?: $title), 0, 4000),
            'description_html' => $descHtml,
            'description_short' => mb_substr($title, 0, 280),
            'price' => $price,
            'compare_at_price' => $compare,
            'currency' => $currency,
            'sku' => $skus[0] ?? $id,
            'skus' => array_values(array_unique($skus)),
            'category' => (string) ($this->matchOne($html, '/"categoryName"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/') ?: ''),
            'shop_name' => '',
            'rating' => $reviews['rating'] ?? $this->toFloat($this->matchOne($html, '/"averageStar"\s*:\s*"?([0-9.]+)"?/')),
            'orders' => $reviews['orders'] ?? null,
            'review_count' => $reviews['count'] ?? null,
            'reviews' => $reviews['list'] ?? [],
            'shipping_price' => $shipping['price'] ?? null,
            'shipping_currency' => $shipping['currency'] ?? $currency,
            'shipping_note' => $shipping['note'] ?? null,
            'shipping_time' => $shipping['time'] ?? ($run['shipping_time'] ?? null),
            'has_video' => $videos !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>|null  $jsonLd
     * @return list<string>
     */
    protected function resolveImages(string $html, array $run, ?array $jsonLd): array
    {
        $candidates = [];

        if (! empty($run['images']) && is_array($run['images'])) {
            foreach ($run['images'] as $img) {
                $u = $this->absUrl(is_string($img) ? $img : '');
                if ($u !== '') {
                    $candidates[] = $u;
                }
            }
        }

        if ($candidates === []) {
            $candidates = array_merge($candidates, $this->extractImagesFromJson($html));
        }

        if ($candidates === []) {
            $candidates = array_merge($candidates, $this->extractGalleryImagesFromDom($html));
        }

        $ogImage = $this->absUrl((string) $this->matchOne($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i'));
        if ($ogImage !== '') {
            array_unshift($candidates, $ogImage);
        }

        if ($candidates === [] && is_array($jsonLd) && ! empty($jsonLd['image'])) {
            $img = $jsonLd['image'];
            if (is_string($img)) {
                $candidates[] = $this->absUrl($img);
            } elseif (is_array($img)) {
                foreach ($img as $i) {
                    if (is_string($i)) {
                        $candidates[] = $this->absUrl($i);
                    }
                }
            }
        }

        return $this->dedupeGalleryImages($candidates);
    }

    /**
     * @return list<string>
     */
    protected function extractImagesFromJson(string $html): array
    {
        $out = [];
        foreach ([
            '/"imagePathList"\s*:\s*\[(.*?)\]/s',
            '/"summImagePathList"\s*:\s*\[(.*?)\]/s',
            '/"imageList"\s*:\s*\[(.*?)\]/s',
        ] as $re) {
            if (! preg_match($re, $html, $m)) {
                continue;
            }
            if (preg_match_all('#https?:\\\\?/\\\\?/[^"\\\\]+#', $m[1], $urls)) {
                foreach ($urls[0] as $u) {
                    $abs = $this->normalizeGalleryImageUrl($this->absUrl(stripslashes($u)));
                    if ($abs !== '' && $this->isGalleryImageUrl($abs)) {
                        $out[] = $abs;
                    }
                }
            }
            if ($out !== []) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function extractGalleryImagesFromDom(string $html): array
    {
        $out = [];
        foreach ([
            '/<img[^>]+class="[^"]*(?:slider--img|magnifier--image|image-view-v2--img|images-view-item)[^"]*"[^>]+src="([^"]+)"/i',
            '/<img[^>]+src="([^"]+)"[^>]+class="[^"]*(?:slider--img|magnifier--image|image-view-v2--img)[^"]*"/i',
            '/<img[^>]+data-src="([^"]+)"[^>]+class="[^"]*(?:slider--img|magnifier--image)[^"]*"/i',
        ] as $re) {
            if (! preg_match_all($re, $html, $m)) {
                continue;
            }
            foreach ($m[1] as $u) {
                $abs = $this->normalizeGalleryImageUrl($this->absUrl($u));
                if ($abs !== '' && $this->isGalleryImageUrl($abs)) {
                    $out[] = $abs;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function dedupeGalleryImages(array $urls): array
    {
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            $abs = $this->normalizeGalleryImageUrl($this->absUrl($url));
            if ($abs === '' || ! $this->isGalleryImageUrl($abs)) {
                continue;
            }
            $key = $this->galleryImageKey($abs);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $abs;
        }

        return array_slice($out, 0, 24);
    }

    protected function normalizeGalleryImageUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^(https?://[^/]+/kf/S[a-zA-Z0-9]+)#i', $url, $m)) {
            return $m[1].'.jpg';
        }
        $url = preg_replace('/_\.(avif|webp)$/i', '.$1', $url) ?? $url;
        $url = preg_replace('/\.(jpg|jpeg|png|webp|avif)_\.(avif|webp)$/i', '.$1', $url) ?? $url;
        $url = preg_replace('/_\d+x\d+q?\d*\.(jpg|jpeg|png|webp|avif)(?:_\.(avif|webp))?$/i', '.$1', $url) ?? $url;

        return $url;
    }

    protected function galleryImageKey(string $url): string
    {
        if (preg_match('/\/(kf\/[A-Za-z0-9._-]+)/i', $url, $m)) {
            return preg_replace('/_\d+x\d+.*$/', '', $m[1]) ?? $m[1];
        }

        return md5($url);
    }

    protected function isGalleryImageUrl(string $url): bool
    {
        if ($url === '' || str_contains($url, 'data:')) {
            return false;
        }
        if (! preg_match('/\.(jpe?g|webp|avif)(?:\?|$)/i', $url)) {
            return false;
        }
        if (preg_match('/shipping--|sku-item|review--|avatar|favicon|logo|icon/i', $url)) {
            return false;
        }
        if (! str_contains($url, '/kf/') && ! str_contains($url, 'aliexpress-media')) {
            return false;
        }
        if (preg_match('/_\d+x\d+/i', $url) && ! preg_match('/_(?:640|800|960|1200)x(?:640|800|960|1200)/i', $url)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{variants: list<array<string, mixed>>, attributes: list<array{name: string, value: string, image?: string}>}
     */
    protected function resolveVariants(string $html, array $run): array
    {
        $attributes = $this->extractAttributesFromDom($html);
        $variants = [];

        if (! empty($run['variants']) && is_array($run['variants'])) {
            $variants = $this->filterRealVariants($run['variants']);
        }

        if ($variants === []) {
            $skuModule = $this->extractJsonObjectAfterKey($html, 'skuModule');
            if (is_array($skuModule) && $skuModule !== []) {
                $variants = $this->filterRealVariants($this->variantsFromSkuModule($skuModule));
            }
        }

        $domVariants = $this->extractVariantsFromDom($html);
        if ($domVariants !== []) {
            if ($variants === [] || (count($variants) === 1 && ! empty($variants[0]['is_product_option']))) {
                $variants = $this->filterRealVariants($domVariants);
            } elseif (count($domVariants) > count($variants)) {
                // El JSON vino incompleto (p. ej. HTML pegado sin skuPriceList completo)
                $variants = $this->mergeVariantLists($variants, $domVariants);
            }
        }

        if ($variants === [] && $attributes !== []) {
            $image = '';
            if (preg_match('/sku-item--(?:selected|image)[^>]*>[\s\S]{0,400}?<img[^>]+(?:src|data-src)="([^"]+)"/i', $html, $m)) {
                $image = $this->normalizeGalleryImageUrl($this->absUrl($m[1]));
            }
            $label = implode(' / ', array_map(
                fn (array $a) => $a['name'].': '.$a['value'],
                $attributes
            ));
            $variants[] = [
                'vid' => 'default',
                'sku' => 'default',
                'name' => mb_substr($label, 0, 190),
                'key' => $label,
                'price' => null,
                'image' => $image,
                'stock' => null,
                'weight' => null,
                'is_product_option' => true,
            ];
        } elseif (count($variants) === 1 && $attributes !== [] && count($domVariants) <= 1) {
            $variants[0]['is_product_option'] = true;
            if (($variants[0]['name'] ?? '') === '' || str_starts_with((string) ($variants[0]['name'] ?? ''), 'SKU ')) {
                $variants[0]['name'] = mb_substr(implode(' / ', array_map(
                    fn (array $a) => $a['name'].': '.$a['value'],
                    $attributes
                )), 0, 190);
            }
        }

        return [
            'variants' => array_slice($variants, 0, 120),
            'attributes' => $attributes,
        ];
    }

    /**
     * Todas las opciones visibles en los bloques sku-item (imágenes + texto).
     *
     * @return list<array<string, mixed>>
     */
    protected function extractVariantsFromDom(string $html): array
    {
        $variants = [];
        $seen = [];

        if (preg_match_all(
            '/<(?:div|section)[^>]+class="[^"]*sku-item--property[^"]*"[^>]*>([\s\S]*?)(?=<(?:div|section)[^>]+class="[^"]*sku-item--property[^"]*"|$)/i',
            $html,
            $blocks
        )) {
            foreach ($blocks[1] as $block) {
                $propName = trim(html_entity_decode((string) (
                    $this->matchOne($block, '/sku-item--title[^>]*>[\s\S]*?<span[^>]*>\s*([^:<>]+)\s*:/iu')
                    ?: $this->matchOne($block, '/sku-item--title[^>]*>[\s\S]*?([^:<>]{2,40})\s*:/iu')
                    ?: 'Opción'
                ), ENT_QUOTES, 'UTF-8'));

                // Opciones con imagen (Color, etc.)
                if (preg_match_all(
                    '/<(?:div|li|button|a)[^>]*(?:data-sku-col=["\']([^"\']+)["\'][^>]*class="[^"]*sku-item--(?:image|selected|showHot)[^"]*"|class="[^"]*sku-item--(?:image|selected|showHot)[^"]*"[^>]*data-sku-col=["\']([^"\']+)["\'])[^>]*>[\s\S]{0,800}?<img[^>]+(?:src|data-src)=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?/i',
                    $block,
                    $imgs,
                    PREG_SET_ORDER
                )) {
                    foreach ($imgs as $img) {
                        $col = trim((string) ($img[1] !== '' ? $img[1] : $img[2]));
                        $src = $this->normalizeGalleryImageUrl($this->absUrl($img[3]));
                        $alt = trim(html_entity_decode((string) ($img[4] ?? ''), ENT_QUOTES, 'UTF-8'));
                        if ($alt === '') {
                            $alt = trim(html_entity_decode((string) (
                                $this->matchOne($img[0], '/\balt=["\']([^"\']+)["\']/i') ?: ''
                            ), ENT_QUOTES, 'UTF-8'));
                        }
                        if ($alt === '' && $col === '') {
                            continue;
                        }
                        $name = $propName !== '' && $alt !== '' ? ($propName.': '.$alt) : ($alt !== '' ? $alt : ($propName.': '.$col));
                        $key = $col !== '' ? $col : $name;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $variants[] = [
                            'vid' => $col !== '' ? $col : ('dom-'.md5($name)),
                            'sku' => $col !== '' ? $col : ('dom-'.md5($name)),
                            'name' => mb_substr($name, 0, 190),
                            'key' => $key,
                            'price' => null,
                            'image' => $src,
                            'stock' => null,
                            'weight' => null,
                        ];
                    }
                }

                // Fallback imágenes sku-item sin data-sku-col en el mismo orden del regex
                if (! preg_match('/data-sku-col=/i', $block) && preg_match_all(
                    '/class="[^"]*sku-item--(?:image|selected|showHot)[^"]*"[^>]*>[\s\S]{0,500}?<img[^>]+(?:src|data-src)=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\']/i',
                    $block,
                    $plainImgs,
                    PREG_SET_ORDER
                )) {
                    foreach ($plainImgs as $img) {
                        $alt = trim(html_entity_decode($img[2], ENT_QUOTES, 'UTF-8'));
                        $src = $this->normalizeGalleryImageUrl($this->absUrl($img[1]));
                        if ($alt === '') {
                            continue;
                        }
                        $name = $propName !== '' ? ($propName.': '.$alt) : $alt;
                        if (isset($seen[$name])) {
                            continue;
                        }
                        $seen[$name] = true;
                        $variants[] = [
                            'vid' => 'dom-'.md5($name),
                            'sku' => 'dom-'.md5($name),
                            'name' => mb_substr($name, 0, 190),
                            'key' => $name,
                            'price' => null,
                            'image' => $src,
                            'stock' => null,
                            'weight' => null,
                        ];
                    }
                }

                // Opciones de texto (talla, etc.)
                if (preg_match_all(
                    '/<(?:div|li|button|span)[^>]+class="[^"]*sku-item--(?:text|skuText|selectSku)[^"]*"[^>]*>([\s\S]*?)<\/(?:div|li|button|span)>/i',
                    $block,
                    $texts
                )) {
                    foreach ($texts[1] as $raw) {
                        $val = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8'));
                        $val = trim(preg_replace('/\s+/', ' ', $val) ?? $val);
                        if ($val === '' || mb_strlen($val) > 80) {
                            continue;
                        }
                        $name = $propName !== '' ? ($propName.': '.$val) : $val;
                        if (isset($seen[$name])) {
                            continue;
                        }
                        $seen[$name] = true;
                        $variants[] = [
                            'vid' => 'dom-'.md5($name),
                            'sku' => 'dom-'.md5($name),
                            'name' => mb_substr($name, 0, 190),
                            'key' => $name,
                            'price' => null,
                            'image' => '',
                            'stock' => null,
                            'weight' => null,
                        ];
                    }
                }
            }
        }

        // Si no hubo bloques property, barrido global de data-sku-col
        if ($variants === [] && preg_match_all(
            '/data-sku-col=["\']([^"\']+)["\'][^>]*>[\s\S]{0,500}?<img[^>]+(?:src|data-src)=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?/i',
            $html,
            $global,
            PREG_SET_ORDER
        )) {
            foreach ($global as $img) {
                $col = trim($img[1]);
                $alt = trim(html_entity_decode((string) ($img[3] ?? ''), ENT_QUOTES, 'UTF-8'));
                if ($alt === '') {
                    $alt = $col;
                }
                if ($alt === '' || isset($seen[$col])) {
                    continue;
                }
                $seen[$col] = true;
                $variants[] = [
                    'vid' => $col,
                    'sku' => $col,
                    'name' => mb_substr('Color: '.$alt, 0, 190),
                    'key' => $col,
                    'price' => null,
                    'image' => $this->normalizeGalleryImageUrl($this->absUrl($img[2])),
                    'stock' => null,
                    'weight' => null,
                ];
            }
        }

        return $variants;
    }

    /**
     * @param  list<array<string, mixed>>  $primary
     * @param  list<array<string, mixed>>  $extra
     * @return list<array<string, mixed>>
     */
    protected function mergeVariantLists(array $primary, array $extra): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($primary, $extra) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $key = trim((string) ($v['sku'] ?? $v['vid'] ?? $v['key'] ?? $v['name'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $v;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    protected function filterRealVariants(array $variants): array
    {
        $seen = [];
        $out = [];
        foreach ($variants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $sku = trim((string) ($v['sku'] ?? $v['vid'] ?? ''));
            $name = trim((string) ($v['name'] ?? ''));
            if ($sku === '' && $name === '') {
                continue;
            }
            if ($name !== '' && preg_match('/^(sku|prop|module)\b/i', $name)) {
                continue;
            }
            $key = $sku !== '' ? $sku : $name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $v;
        }

        return $out;
    }

    /**
     * @return list<array{name: string, value: string, image?: string}>
     */
    protected function extractAttributesFromDom(string $html): array
    {
        $attrs = [];
        if (preg_match_all(
            '/sku-item--title[^>]*>[\s\S]*?<span[^>]*>([^:]+):(?:\s|&nbsp;)+<span[^>]*>([^<]+)<\/span>/iu',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $name = trim(html_entity_decode($row[1], ENT_QUOTES, 'UTF-8'));
                $value = trim(html_entity_decode($row[2], ENT_QUOTES, 'UTF-8'));
                if ($name !== '' && $value !== '') {
                    $attrs[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        return $attrs;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractJsonObjectAfterKey(string $html, string $key): ?array
    {
        $marker = '"'.$key.'":';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return null;
        }
        $brace = strpos($html, '{', $pos);
        if ($brace === false || $brace - $pos > 40) {
            return null;
        }

        $decoded = $this->decodeBalancedJson($html, $brace, '{', '}');

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<mixed>|null
     */
    protected function extractJsonArrayAfterKey(string $html, string $key): ?array
    {
        $marker = '"'.$key.'":';
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return null;
        }
        $start = strpos($html, '[', $pos);
        if ($start === false || $start - $pos > 40) {
            return null;
        }

        $decoded = $this->decodeBalancedJson($html, $start, '[', ']');

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<mixed>|null
     */
    protected function decodeBalancedJson(string $html, int $start, string $open, string $close): ?array
    {
        $len = strlen($html);
        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($i = $start; $i < min($len, $start + 500000); $i++) {
            $ch = $html[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inStr = true;

                continue;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode(substr($html, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @deprecated Solo para compatibilidad; usar resolveImages().
     * @return list<string>
     */
    protected function extractImages(string $html): array
    {
        return $this->extractImagesFromJson($html);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractVariants(string $html): array
    {
        $variants = [];
        if (preg_match_all('/"skuId"\s*:\s*"?(?P<id>\d+)"?[^\{]{0,800}?"skuAttr"\s*:\s*"(?P<attr>[^"]+)"/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $name = $this->prettySkuName((string) $row['attr']);
                $variants[] = [
                    'vid' => (string) $row['id'],
                    'sku' => (string) $row['id'],
                    'name' => $name !== '' ? $name : ('SKU '.$row['id']),
                    'key' => $name,
                    'price' => null,
                    'image' => '',
                    'stock' => null,
                    'weight' => null,
                ];
            }
        }
        if ($variants === [] && preg_match_all('/"skuPropIds"\s*:\s*"(?P<ids>[^"]+)"[^\{]{0,400}?"skuVal"\s*:\s*\{(?P<body>.*?)\}\s*,/s', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $price = $this->toFloat($this->matchOne($row['body'], '/"actSkuCalPrice"\s*:\s*"?([0-9.]+)"?/'));
                $variants[] = [
                    'vid' => (string) $row['ids'],
                    'sku' => (string) $row['ids'],
                    'name' => mb_substr((string) $row['ids'], 0, 190),
                    'key' => (string) $row['ids'],
                    'price' => $price,
                    'image' => '',
                    'stock' => null,
                    'weight' => null,
                ];
            }
        }

        $seen = [];
        $uniq = [];
        foreach ($variants as $v) {
            $k = (string) ($v['sku'] ?: $v['vid']);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $v;
        }

        return array_slice($uniq, 0, 120);
    }

    /**
     * @return list<array{url: string, cover?: string}>
     */
    protected function extractVideos(string $html): array
    {
        $videos = [];
        foreach ([
            '/"(?:videoUrl|aliVideoUrl|playUrl)"\s*:\s*"(https?:[^"]+\.(?:mp4|m3u8)[^"]*)"/i',
            '/https?:\\\\?\/\\\\?\/[^"\']+\.mp4/i',
        ] as $re) {
            if (preg_match_all($re, $html, $m)) {
                foreach ($m[1] ?? $m[0] as $u) {
                    $abs = $this->absUrl(stripslashes((string) $u));
                    if ($abs !== '' && (str_contains($abs, '.mp4') || str_contains($abs, '.m3u8'))) {
                        $videos[] = ['url' => $abs];
                    }
                }
            }
        }

        $seen = [];
        $out = [];
        foreach ($videos as $v) {
            if (isset($seen[$v['url']])) {
                continue;
            }
            $seen[$v['url']] = true;
            $out[] = $v;
        }

        return array_slice($out, 0, 8);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractJsonLd(string $html): ?array
    {
        if (! preg_match_all('/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $raw) {
            $json = json_decode(html_entity_decode(trim($raw)), true);
            if (! is_array($json)) {
                continue;
            }
            $type = (string) ($json['@type'] ?? '');
            if (stripos($type, 'Product') !== false) {
                return $json;
            }
            if (isset($json[0]) && is_array($json[0]) && stripos((string) ($json[0]['@type'] ?? ''), 'Product') !== false) {
                return $json[0];
            }
        }

        return null;
    }

    protected function extractDescriptionUrl(string $html): ?string
    {
        $candidates = [];
        foreach ([
            '/"(?:descriptionUrl|descUrl|productDescUrl|descriptionPCUrl|pcDescUrl)"\s*:\s*"(https?:[^"]+)"/i',
            '/"(?:descriptionUrl|descUrl|productDescUrl)"\s*:\s*"(https?:\\\\\/\\\\\/[^"]+)"/i',
            '/https?:\\\\?\/\\\\?\/aeproductsourcesite\.alicdn\.com[^"\'\s<>]+/i',
            '/https?:\/\/aeproductsourcesite\.alicdn\.com[^"\'\s<>]+/i',
            '/https?:\\\\?\/\\\\?\/[a-z0-9.-]*alicdn\.com[^"\']*(?:desc|description)[^"\']*/i',
        ] as $re) {
            if (preg_match_all($re, $html, $m)) {
                foreach ($m[1] ?? $m[0] as $raw) {
                    $abs = $this->absUrl(stripslashes((string) $raw));
                    if ($abs !== '' && ! in_array($abs, $candidates, true)) {
                        $candidates[] = $abs;
                    }
                }
            }
        }

        foreach ($candidates as $u) {
            if (str_contains($u, 'aeproductsourcesite') || str_contains($u, 'desc')) {
                return $u;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Descarga el HTML de descripción remota (descriptionUrl de AE).
     */
    protected function fetchDescriptionHtml(?string $descUrl): string
    {
        if ($descUrl === null || $descUrl === '') {
            return '';
        }
        $body = $this->fetchHtml($descUrl);
        if ((! is_string($body) || strlen($body) < 40) && $this->browser->enabled()) {
            $body = $this->browser->fetchHtml($descUrl);
        }
        if (! is_string($body) || strlen($body) < 20) {
            return '';
        }

        $trim = trim($body);
        if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
            $json = json_decode($trim, true);
            if (is_array($json)) {
                foreach (['content', 'html', 'description', 'productDescription', 'moduleDesc'] as $key) {
                    $chunk = $json[$key] ?? null;
                    if (is_string($chunk) && strlen($chunk) > 20) {
                        return mb_substr($this->sanitizeHtml($chunk), 0, 20000);
                    }
                }
            }
        }

        return mb_substr($this->sanitizeHtml($body), 0, 20000);
    }

    /**
     * Descripción local (DOM) o remota (descriptionUrl).
     */
    protected function resolveDescriptionHtml(string $html, array $run = []): string
    {
        $desc = (string) ($run['description_html'] ?? '');
        if ($desc === '' || strlen(strip_tags($desc)) < 20) {
            $desc = $this->extractDescriptionHtml($html);
        }
        if ($desc === '' || strlen(strip_tags($desc)) < 20) {
            $descUrl = (string) ($run['description_url'] ?? '');
            if ($descUrl === '') {
                $descUrl = (string) ($this->extractDescriptionUrl($html) ?? '');
            }
            $remote = $this->fetchDescriptionHtml($descUrl !== '' ? $descUrl : null);
            if ($remote !== '') {
                $desc = $remote;
            }
        }

        return $this->stripImagesFromHtml($desc);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{rating: ?float, count: ?int, orders: ?int, list: list<array<string, mixed>>}
     */
    protected function extractReviews(string $html, array $run): array
    {
        $rating = isset($run['rating']) ? $this->toFloat($run['rating']) : $this->toFloat(
            $this->matchOne($html, '/"evarageStar"\s*:\s*"?([0-9.]+)"?/')
            ?: $this->matchOne($html, '/"averageStar"\s*:\s*"?([0-9.]+)"?/')
        );
        $count = isset($run['review_count']) ? (int) $run['review_count'] : (int) (
            $this->matchOne($html, '/"totalValidNum"\s*:\s*"?([0-9]+)"?/')
            ?: $this->matchOne($html, '/"reviewCount"\s*:\s*"?([0-9]+)"?/')
            ?: 0
        );
        $orders = isset($run['orders']) ? (int) $run['orders'] : (int) (
            $this->matchOne($html, '/"tradeCount"\s*:\s*"?([0-9]+)"?/')
            ?: $this->matchOne($html, '/"formatTradeCount"\s*:\s*"([0-9,.+]+)"/')
            ?: 0
        );
        $list = [];
        if (! empty($run['reviews']) && is_array($run['reviews'])) {
            foreach ($run['reviews'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $list[] = [
                    'author' => (string) ($row['author'] ?? $row['buyerName'] ?? 'Comprador'),
                    'score' => isset($row['score']) ? (int) $row['score'] : (isset($row['buyerEval']) ? (int) round(((int) $row['buyerEval']) / 20) : null),
                    'comment' => (string) ($row['comment'] ?? $row['buyerFeedback'] ?? $row['buyerTranslationFeedback'] ?? ''),
                    'date' => (string) ($row['date'] ?? $row['evalDate'] ?? ''),
                    'country' => (string) ($row['country'] ?? $row['buyerCountry'] ?? ''),
                    'avatar' => $this->absUrl((string) ($row['avatar'] ?? $row['buyerHeadPortrait'] ?? $row['headPortrait'] ?? '')),
                    'images' => is_array($row['images'] ?? null) ? $row['images'] : [],
                ];
            }
        }
        if ($list === [] && preg_match_all('/"buyerName"\s*:\s*"(?P<name>[^"]+)"[\s\S]{0,1500}?"buyerEval"\s*:\s*(?P<score>\d+)[\s\S]{0,1500}?"buyerFeedback"\s*:\s*"(?P<comment>(?:\\\\.|[^"\\\\])*)"/', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $list[] = [
                    'author' => html_entity_decode(stripslashes($row['name']), ENT_QUOTES, 'UTF-8'),
                    'score' => (int) round(((int) $row['score']) / 20),
                    'comment' => html_entity_decode(stripslashes($row['comment']), ENT_QUOTES, 'UTF-8'),
                    'images' => [],
                ];
            }
        }
        if ($list === []) {
            $list = $this->extractReviewsFromEvaJson($html);
        }
        if ($list === []) {
            $list = $this->extractReviewsFromLooseJson($html);
        }
        if ($list === []) {
            $list = $this->extractReviewsFromDom($html);
        }

        return [
            'rating' => $rating,
            'count' => $count > 0 ? $count : ($list !== [] ? count($list) : null),
            'orders' => $orders > 0 ? $orders : null,
            'list' => array_slice($list, 0, 40),
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array{price: ?float, currency: ?string, note: ?string, time: ?string}
     */
    protected function extractShipping(string $html, array $run): array
    {
        $dom = $this->extractShippingFromDom($html);

        $price = $dom['price'] ?? (isset($run['shipping_price']) ? $this->toFloat($run['shipping_price']) : $this->toFloat(
            $this->matchOne($html, '/"freightAmount"\s*:\s*\{[^}]{0,120}"value"\s*:\s*([0-9.]+)/')
            ?: $this->matchOne($html, '/"shippingFee"\s*:\s*"?([0-9.]+)"?/')
        ));
        $currency = (string) ($run['shipping_currency'] ?? $this->matchOne($html, '/"freightAmount"\s*:\s*\{[^}]{0,160}"currency"\s*:\s*"([A-Z]{3})"/') ?? '');
        $note = trim((string) ($dom['note'] ?? $run['shipping_note'] ?? ''));
        if ($note === '') {
            $note = (string) ($this->matchOne($html, '/"shippingFeeText"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/') ?: '');
            $note = html_entity_decode(stripslashes($note), ENT_QUOTES, 'UTF-8');
        }
        if ($price === null && $note !== '' && preg_match('/gratis|free/i', $note)) {
            $price = 0.0;
        }
        if ($price === null && preg_match('/env[ií]o\s+gratis|free\s+shipping/i', $html)) {
            $price = 0.0;
            $note = $note !== '' ? $note : 'Envío gratis';
        }
        $time = trim((string) ($dom['time'] ?? $run['shipping_time'] ?? ''));
        if ($time === '') {
            $time = (string) ($this->extractShippingTime($html) ?? '');
        }
        $time = $this->normalizeShippingTimeLabel($time);

        return [
            'price' => $price,
            'currency' => $currency !== '' ? $currency : null,
            'note' => $note !== '' ? mb_substr($note, 0, 220) : null,
            'time' => $time !== '' ? mb_substr($time, 0, 160) : null,
        ];
    }

    /**
     * @return array{note: ?string, time: ?string, price: ?float}
     */
    protected function extractShippingFromDom(string $html): array
    {
        $note = '';
        $time = '';
        $price = null;

        if (preg_match('/dynamic-shipping-titleLayout[\s\S]{0,800}?<strong[^>]*>([\s\S]*?)<\/strong>/i', $html, $m)) {
            $note = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))) ?? '');
            if (preg_match('/gratis|free/i', $note)) {
                $price = 0.0;
            }
        }
        if (preg_match('/dynamic-shipping-contentLayout[\s\S]{0,800}?<strong[^>]*>([\s\S]*?)<\/strong>/i', $html, $m)) {
            $time = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'))) ?? '');
        }

        return [
            'note' => $note !== '' ? $note : null,
            'time' => $time !== '' ? $this->normalizeShippingTimeLabel($time) : null,
            'price' => $price,
        ];
    }

    protected function normalizeShippingTimeLabel(string $text): string
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        if (preg_match('/^env[ií]o\s*:\s*(.+)$/iu', $text, $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractReviewsFromEvaJson(string $html): array
    {
        $eva = $this->extractJsonArrayAfterKey($html, 'evaViewList')
            ?? $this->extractJsonArrayAfterKey($html, 'feedbackList');
        if (! is_array($eva) || $eva === []) {
            return [];
        }

        $list = [];
        foreach ($eva as $row) {
            if (! is_array($row)) {
                continue;
            }
            $comment = trim((string) ($row['buyerTranslationFeedback'] ?? $row['buyerFeedback'] ?? ''));
            if ($comment === '') {
                continue;
            }
            $imgs = [];
            foreach ($row['images'] ?? $row['imageList'] ?? [] as $img) {
                $u = $this->absUrl(is_string($img) ? $img : (string) ($img['url'] ?? $img['imgUrl'] ?? ''));
                if ($u !== '') {
                    $imgs[] = $u;
                }
            }
            $list[] = [
                'author' => (string) ($row['buyerName'] ?? $row['anonymous'] ?? 'Comprador'),
                'score' => isset($row['buyerEval']) ? (int) round(((int) $row['buyerEval']) / 20) : (isset($row['star']) ? (int) $row['star'] : null),
                'comment' => html_entity_decode($comment, ENT_QUOTES, 'UTF-8'),
                'date' => (string) ($row['evalDate'] ?? ''),
                'country' => (string) ($row['buyerCountry'] ?? ''),
                'avatar' => $this->absUrl((string) ($row['buyerHeadPortrait'] ?? $row['headPortrait'] ?? '')),
                'images' => $imgs,
            ];
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractReviewsFromLooseJson(string $html): array
    {
        $list = [];
        if (preg_match_all(
            '/"buyerName"\s*:\s*"([^"]+)"[\s\S]{0,4000}?"buyer(?:Translation)?Feedback"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $comment = trim(html_entity_decode(stripslashes($row[2]), ENT_QUOTES, 'UTF-8'));
                if ($comment === '' || strlen($comment) < 4) {
                    continue;
                }
                $list[] = [
                    'author' => html_entity_decode(stripslashes($row[1]), ENT_QUOTES, 'UTF-8'),
                    'score' => null,
                    'comment' => $comment,
                    'date' => '',
                    'images' => [],
                ];
            }
        }
        if ($list === [] && preg_match_all('/"buyer(?:Translation)?Feedback"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $html, $m)) {
            foreach ($m[1] as $comment) {
                $text = trim(html_entity_decode(stripslashes($comment), ENT_QUOTES, 'UTF-8'));
                if ($text === '' || strlen($text) < 4) {
                    continue;
                }
                $list[] = [
                    'author' => 'Comprador',
                    'score' => null,
                    'comment' => $text,
                    'date' => '',
                    'images' => [],
                ];
            }
        }

        $seen = [];
        $uniq = [];
        foreach ($list as $row) {
            $key = md5(($row['author'] ?? '').'|'.($row['comment'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniq[] = $row;
        }

        return array_slice($uniq, 0, 40);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractReviewsFromDom(string $html): array
    {
        $list = [];
        if (preg_match_all(
            '/<(?:div|article|li)[^>]+class="[^"]*(?:review--card|feedback--card|review-item|feedback-item|list--item--review|review--item)[^"]*"[^>]*>([\s\S]{40,6000}?)<\/(?:div|article|li)>/i',
            $html,
            $blocks
        )) {
            foreach ($blocks[1] as $block) {
                $parsed = $this->parseReviewBlock($block);
                if ($parsed !== null) {
                    $list[] = $parsed;
                }
            }
        }
        if ($list === [] && preg_match_all(
            '/class="[^"]*(?:review--content|feedback--content|buyer-feedback|rate-des)[^"]*"[^>]*>([\s\S]{12,2000}?)<\//i',
            $html,
            $comments
        )) {
            foreach ($comments[1] as $comment) {
                $text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($comment, ENT_QUOTES, 'UTF-8'))) ?? '');
                if (strlen($text) < 12) {
                    continue;
                }
                $list[] = [
                    'author' => 'Comprador',
                    'score' => null,
                    'comment' => $text,
                    'date' => '',
                    'images' => [],
                ];
            }
        }

        return array_slice($list, 0, 40);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseReviewBlock(string $block): ?array
    {
        $author = (string) ($this->matchOne($block, '/class="[^"]*(?:review--name|buyer-name|feedback--name)[^"]*"[^>]*>([^<]{2,80})</i') ?: 'Comprador');
        $rawComment = (string) ($this->matchOne($block, '/class="[^"]*(?:review--content|feedback--content|buyer-feedback)[^"]*"[^>]*>([\s\S]{12,2000}?)<\//i') ?: '');
        $comment = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($rawComment, ENT_QUOTES, 'UTF-8'))) ?? '');
        if ($comment === '') {
            return null;
        }
        $score = null;
        if (preg_match('/class="[^"]*comet-rate[^"]*"[^>]*aria-label="([0-9.]+)/i', $block, $m)) {
            $score = (int) round((float) $m[1]);
        }

        return [
            'author' => html_entity_decode($author, ENT_QUOTES, 'UTF-8'),
            'score' => $score,
            'comment' => $comment,
            'date' => (string) ($this->matchOne($block, '/class="[^"]*(?:review--time|feedback--time)[^"]*"[^>]*>([^<]+)</i') ?: ''),
            'images' => [],
        ];
    }

    /**
     * JSON embebido (runParams / INIT_DATA) típico de ficha AliExpress ya renderizada.
     *
     * @return array<string, mixed>
     */
    protected function extractRunParams(string $html): array
    {
        $blob = null;
        foreach ([
            'window._d_c_.DCData',
            'window.runParams',
            'window.__INIT_DATA__',
            'window._dida_config_',
            'window.globalData',
        ] as $marker) {
            $try = $this->extractBalancedJson($html, $marker);
            if (is_array($try) && $try !== []) {
                $blob = $try;
                break;
            }
        }
        if (! is_array($blob) || $blob === []) {
            $blob = $this->extractScriptJsonById($html, '__AER_DATA__')
                ?? $this->extractScriptJsonById($html, '__NEXT_DATA__');
        }
        if (! is_array($blob)) {
            return [];
        }

        $data = is_array($blob['data'] ?? null) ? $blob['data'] : $blob;
        if (isset($data['props']['pageProps']) && is_array($data['props']['pageProps'])) {
            $data = $data['props']['pageProps'];
            if (isset($data['data']) && is_array($data['data'])) {
                $data = $data['data'];
            }
        }
        $titleModule = $this->findAssoc($data, 'titleModule');
        $imageModule = $this->findAssoc($data, 'imageModule');
        $priceModule = $this->findAssoc($data, 'priceModule');
        $skuModule = $this->findAssoc($data, 'skuModule');
        $feedbackModule = $this->findAssoc($data, 'feedbackModule');
        $descModule = $this->findAssoc($data, 'descriptionModule')
            ?: $this->findAssoc($data, 'productDescModule');
        $freightModule = $this->findAssoc($data, 'webGeneralFreightCalculateComponent')
            ?: $this->findAssoc($data, 'shippingModule')
            ?: $this->findAssoc($data, 'freightInfo');

        $title = (string) ($titleModule['subject'] ?? $data['subject'] ?? $data['title'] ?? '');
        if (preg_match('/Resp$|Module$|^ItemDetail/i', $title)) {
            $title = '';
        }
        $images = [];
        foreach (['imagePathList', 'summImagePathList'] as $key) {
            $list = $imageModule[$key] ?? $data[$key] ?? [];
            if (is_array($list)) {
                foreach ($list as $img) {
                    $u = $this->absUrl(is_string($img) ? $img : '');
                    if ($u !== '') {
                        $images[] = $u;
                    }
                }
            }
        }

        $formattedActivity = (string) ($priceModule['formatedActivityPrice'] ?? $priceModule['formattedActivityPrice'] ?? '');
        $price = $this->toFloat(
            $formattedActivity !== '' ? $formattedActivity : (
                $priceModule['minActivityAmount']['value']
                ?? $priceModule['minPrice']
                ?? $data['minPrice']
                ?? null
            )
        );
        $compare = $this->toFloat($priceModule['formatedPrice'] ?? $priceModule['maxPrice'] ?? null);
        $currency = $this->detectCurrencyFromText($formattedActivity)
            ?: $this->detectCurrencyFromText((string) ($priceModule['formatedPrice'] ?? ''))
            ?: (string) ($priceModule['currencyCode'] ?? '');

        $variants = $this->variantsFromSkuModule($skuModule);

        $reviews = [];
        $evaList = $feedbackModule['evaViewList'] ?? $feedbackModule['feedbackList'] ?? [];
        if (is_array($evaList)) {
            foreach ($evaList as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $imgs = [];
                foreach ($row['images'] ?? $row['imageList'] ?? [] as $img) {
                    $u = $this->absUrl(is_string($img) ? $img : (string) ($img['url'] ?? $img['imgUrl'] ?? ''));
                    if ($u !== '') {
                        $imgs[] = $u;
                    }
                }
                $reviews[] = [
                    'author' => (string) ($row['buyerName'] ?? $row['anonymous'] ?? 'Comprador'),
                    'score' => isset($row['buyerEval']) ? (int) round(((int) $row['buyerEval']) / 20) : (isset($row['star']) ? (int) $row['star'] : null),
                    'comment' => (string) ($row['buyerTranslationFeedback'] ?? $row['buyerFeedback'] ?? ''),
                    'date' => (string) ($row['evalDate'] ?? ''),
                    'country' => (string) ($row['buyerCountry'] ?? ''),
                    'avatar' => $this->absUrl((string) ($row['buyerHeadPortrait'] ?? $row['headPortrait'] ?? '')),
                    'images' => $imgs,
                ];
            }
        }

        $videos = [];
        foreach (['videoUrl', 'aliVideoUrl', 'videoPath', 'playUrl'] as $vk) {
            $vu = $this->absUrl((string) ($imageModule[$vk] ?? ''));
            if ($vu !== '' && (str_contains($vu, '.mp4') || str_contains($vu, '.m3u8'))) {
                $videos[] = ['url' => $vu, 'cover' => $this->absUrl((string) ($imageModule['videoCover'] ?? $imageModule['videoPoster'] ?? ''))];
            }
        }

        $descHtml = (string) ($descModule['description'] ?? $descModule['productDesc'] ?? $descModule['detailDesc'] ?? '');
        if ($descHtml !== '' && ! str_contains($descHtml, '<')) {
            $descHtml = '<p>'.e($descHtml).'</p>';
        }
        $descUrl = $this->absUrl((string) (
            $descModule['descriptionUrl']
            ?? $descModule['descUrl']
            ?? $descModule['productDescUrl']
            ?? $descModule['descriptionPCUrl']
            ?? $data['descriptionUrl']
            ?? ''
        ));

        $shipTime = $this->shippingTimeFromModule($freightModule);
        $shipNote = (string) ($freightModule['shippingFeeText'] ?? '');
        if ($shipNote === '' && isset($freightModule['deliveryLayoutInfo']) && is_string($freightModule['deliveryLayoutInfo'])) {
            $shipNote = $freightModule['deliveryLayoutInfo'];
        }

        return array_filter([
            'title' => $title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : null,
            'images' => $images !== [] ? $images : null,
            'price' => $price,
            'compare_at_price' => $compare,
            'currency' => $currency !== '' ? $currency : null,
            'variants' => $variants !== [] ? $variants : null,
            'videos' => $videos !== [] ? $videos : null,
            'description_html' => $descHtml !== '' ? $descHtml : null,
            'description_url' => $descUrl !== '' ? $descUrl : null,
            'rating' => $this->toFloat($feedbackModule['evarageStar'] ?? $feedbackModule['averageStar'] ?? $titleModule['feedbackRating']['averageStar'] ?? null),
            'review_count' => isset($feedbackModule['totalValidNum']) ? (int) $feedbackModule['totalValidNum'] : (isset($titleModule['feedbackRating']['totalValidNum']) ? (int) $titleModule['feedbackRating']['totalValidNum'] : null),
            'orders' => isset($titleModule['tradeCount']) ? (int) preg_replace('/\D+/', '', (string) $titleModule['tradeCount']) : null,
            'reviews' => $reviews !== [] ? $reviews : null,
            'shipping_price' => $this->toFloat(
                $freightModule['freightAmount']['value']
                ?? $freightModule['displayAmount']
                ?? $freightModule['shippingFee']
                ?? null
            ),
            'shipping_currency' => (string) ($freightModule['freightAmount']['currency'] ?? $freightModule['currency'] ?? ''),
            'shipping_note' => $shipNote !== '' ? $shipNote : null,
            'shipping_time' => $shipTime,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractScriptJsonById(string $html, string $id): ?array
    {
        if (! preg_match('/<script[^>]+id=["\']'.preg_quote($id, '/').'["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            return null;
        }
        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function looksLikeBlockPage(string $title, string $html): bool
    {
        $head = strtolower($title.' '.substr($html, 0, 2500));
        foreach (['access denied', 'attention required', 'just a moment', 'cf-challenge', 'cf-browser-verification', 'punish?x5sec'] as $needle) {
            if (str_contains($head, $needle)) {
                return true;
            }
        }

        return str_contains($head, 'captcha') && str_contains($head, 'cloudflare');
    }

    /**
     * @param  array<string, mixed>  $blob
     * @return array<string, mixed>
     */
    protected function findAssoc(array $blob, string $key, int $depth = 0): array
    {
        if (isset($blob[$key]) && is_array($blob[$key])) {
            return $blob[$key];
        }
        if ($depth >= 4) {
            return [];
        }
        foreach ($blob as $value) {
            if (! is_array($value)) {
                continue;
            }
            $found = $this->findAssoc($value, $key, $depth + 1);
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    protected function logUnparseable(string $url, string $html): void
    {
        $plain = preg_replace('/\s+/', ' ', strip_tags(substr($html, 0, 4000))) ?? '';
        Log::warning('AliExpress HTML no parseable', [
            'url' => $url,
            'bytes' => strlen($html),
            'has_runParams' => str_contains($html, 'runParams'),
            'has_og_title' => str_contains($html, 'og:title'),
            'has_alicdn' => str_contains($html, 'alicdn'),
            'snippet' => mb_substr($plain, 0, 280),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractBalancedJson(string $html, string $marker): ?array
    {
        $pos = strpos($html, $marker);
        if ($pos === false) {
            return null;
        }
        $eq = strpos($html, '=', $pos);
        if ($eq === false) {
            return null;
        }
        $brace = strpos($html, '{', $eq);
        if ($brace === false || $brace - $eq > 40) {
            return null;
        }

        $len = strlen($html);
        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($i = $brace; $i < min($len, $brace + 800000); $i++) {
            $ch = $html[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($ch === '\\') {
                    $esc = true;
                    continue;
                }
                if ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($html, $brace, $i - $brace + 1);
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $api
     * @param  array<string, mixed>  $scrape
     * @return array<string, mixed>
     */
    protected function merge(array $api, array $scrape): array
    {
        $images = array_values(array_unique(array_filter(array_merge(
            is_array($api['images'] ?? null) ? $api['images'] : [],
            is_array($scrape['images'] ?? null) ? $scrape['images'] : []
        ))));
        $videos = $scrape['videos'] ?? [];
        if ($videos === [] && ! empty($api['videos'])) {
            $videos = $api['videos'];
        }
        $variants = $scrape['variants'] ?? [];
        if ($variants === [] && ! empty($api['variants'])) {
            $variants = $api['variants'];
        }

        $api['images'] = $images;
        $api['image'] = $images[0] ?? ($api['image'] ?? '');
        $api['videos'] = $videos;
        $api['has_video'] = $videos !== [];
        $api['variants'] = $variants;
        $api['skus'] = array_values(array_unique(array_merge(
            is_array($api['skus'] ?? null) ? $api['skus'] : [],
            is_array($scrape['skus'] ?? null) ? $scrape['skus'] : []
        )));
        if (($api['description_html'] ?? '') === '' && ($scrape['description_html'] ?? '') !== '') {
            $api['description_html'] = $scrape['description_html'];
            $api['description'] = $scrape['description'] ?? $api['description'];
        }
        if (($api['price'] ?? null) === null && isset($scrape['price'])) {
            $api['price'] = $scrape['price'];
            $api['currency'] = $scrape['currency'] ?? $api['currency'];
        }
        $scrapeCur = strtoupper((string) ($scrape['currency'] ?? ''));
        $apiCur = strtoupper((string) ($api['currency'] ?? ''));
        if ($scrapeCur !== '' && ($apiCur === '' || ($apiCur === 'USD' && $scrapeCur !== 'USD'))) {
            $api['currency'] = $scrapeCur;
            if (isset($scrape['price'])) {
                $api['price'] = $scrape['price'];
            }
        }
        foreach (['shipping_price', 'shipping_currency', 'shipping_note', 'shipping_time', 'reviews', 'review_count', 'rating', 'orders', 'details', 'reviews_source'] as $k) {
            if ((empty($api[$k]) && $api[$k] !== 0 && $api[$k] !== 0.0) && array_key_exists($k, $scrape) && $scrape[$k] !== null && $scrape[$k] !== []) {
                $api[$k] = $scrape[$k];
            }
        }
        if (($api['title'] ?? '') === '' && ($scrape['title'] ?? '') !== '') {
            $api['title'] = $scrape['title'];
        }

        return $api;
    }

    /**
     * @return array<string, string>
     */
    protected function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8',
        ];
    }

    protected function matchOne(string $html, string $re): ?string
    {
        if (preg_match($re, $html, $m)) {
            return $m[1] ?? $m[0] ?? null;
        }

        return null;
    }

    protected function absUrl(string $url, bool $stripQuery = true): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        $url = str_replace(['\\/', '\\u002F'], ['/', '/'], $url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }
        if (! $stripQuery) {
            return $url;
        }
        // Mantener query en URLs de descripción remota (key/token)
        if (preg_match('/(?:description|desc\.htm|desc\.json|aeproductsourcesite)/i', $url)) {
            return $url;
        }

        return strtok($url, '?') ?: $url;
    }

    protected function isMediaUrl(string $url): bool
    {
        return str_contains($url, 'alicdn') || str_contains($url, 'aliexpress-media') || preg_match('/\.(jpe?g|png|webp)/i', $url) === 1;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array{price: ?float, currency: ?string, raw: ?string}  $visible
     * @param  array<string, mixed>|null  $jsonLd
     */
    protected function resolveCurrency(string $html, array $run, array $visible, ?array $jsonLd): string
    {
        $fromVisible = $visible['currency'] ?? null;
        if (is_string($fromVisible) && $fromVisible !== '') {
            return strtoupper($fromVisible);
        }
        $fromRun = strtoupper((string) ($run['currency'] ?? ''));
        if ($fromRun !== '' && $fromRun !== 'USD') {
            return $fromRun;
        }
        $ld = (is_array($jsonLd) && is_array($jsonLd['offers'] ?? null))
            ? strtoupper((string) ($jsonLd['offers']['priceCurrency'] ?? ''))
            : '';
        if ($ld !== '') {
            return $ld;
        }
        if (preg_match('/price-default--current[^>]*>\s*([^<]+)/i', $html, $m)) {
            $fromSpan = $this->detectCurrencyFromText(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            if ($fromSpan) {
                return $fromSpan;
            }
        }
        if (preg_match('/MX\s*\$/', $html)) {
            return 'MXN';
        }
        if ($fromRun !== '') {
            return $fromRun;
        }

        return strtoupper((string) (
            config('aliexpress.ship_to') === 'MX'
                ? 'MXN'
                : (config('aliexpress.target_currency') ?: 'MXN')
        ));
    }

    /**
     * @return array{price: ?float, currency: ?string, raw: ?string}
     */
    protected function extractVisiblePrice(string $html): array
    {
        $raw = null;
        foreach ([
            '/<span[^>]*class="[^"]*price-default--current[^"]*"[^>]*>(.*?)<\/span>/is',
            '/<strong[^>]*class="[^"]*price-default--current[^"]*"[^>]*>(.*?)<\/strong>/is',
            '/<span[^>]*class="[^"]*product-price-value[^"]*"[^>]*>(.*?)<\/span>/is',
            '/itemprop="price"[^>]*content="([^"]+)"/i',
            '/>(MX\$\s*[\d.,]+)</u',
            '/>(US\s*\$\s*[\d.,]+)</u',
            '/"formatedActivityPrice"\s*:\s*"([^"]+)"/i',
            '/"formattedActivityPrice"\s*:\s*"([^"]+)"/i',
        ] as $re) {
            if (preg_match($re, $html, $m)) {
                $cand = trim(html_entity_decode(strip_tags((string) ($m[1] ?? '')), ENT_QUOTES, 'UTF-8'));
                if ($cand !== '' && $this->toFloat($cand)) {
                    $raw = $cand;
                    break;
                }
            }
        }

        return [
            'raw' => $raw,
            'price' => $this->toFloat($raw),
            'currency' => $raw ? $this->detectCurrencyFromText($raw) : null,
        ];
    }

    protected function detectCurrencyFromText(string $text): ?string
    {
        $t = html_entity_decode(str_replace("\u{00A0}", ' ', $text), ENT_QUOTES, 'UTF-8');
        if ($t === '') {
            return null;
        }
        if (preg_match('/MXN|MX\s*\$|Mex\s*\$/i', $t)) {
            return 'MXN';
        }
        if (preg_match('/CAD|CA\s*\$/i', $t)) {
            return 'CAD';
        }
        if (preg_match('/AUD|AU\s*\$/i', $t)) {
            return 'AUD';
        }
        if (preg_match('/BRL|R\s*\$/i', $t)) {
            return 'BRL';
        }
        if (preg_match('/USD|US\s*\$/i', $t)) {
            return 'USD';
        }
        if (preg_match('/EUR|€/i', $t)) {
            return 'EUR';
        }
        if (preg_match('/GBP|£/i', $t)) {
            return 'GBP';
        }
        if (preg_match('/CNY|RMB|¥/i', $t)) {
            return 'CNY';
        }
        if (preg_match('/\b([A-Z]{3})\b/', $t, $m) && in_array($m[1], ['MXN', 'USD', 'EUR', 'GBP', 'BRL', 'CAD', 'AUD', 'CNY', 'JPY', 'COP', 'CLP', 'ARS', 'PEN'], true)) {
            return $m[1];
        }

        return null;
    }

    protected function extractDescriptionHtml(string $html): string
    {
        $html = $this->unwrapDeclarativeShadowDom($html);
        $parts = [];

        $rich = $this->extractRichDescriptionBlock($html);
        if ($rich !== '') {
            $parts[] = $rich;
        }

        if ($parts === []) {
            foreach ([
                '/<(?:div|section)[^>]{0,300}id=["\']nav-description["\'][^>]*>/i',
                '/<(?:div|section)[^>]+(?:data-pl=["\']product-description["\']|data-spm=["\']description["\'])[^>]*>/i',
                '/<div[^>]+class="[^"]*(?:description--wrap|detail-desc-decorate|product-description|extend--content|desc-root)[^"]*"[^>]*>/i',
                '/<div[^>]{0,200}id="product-description"[^>]*>/i',
            ] as $re) {
                $chunk = $this->extractElementInnerHtml($html, $re);
                // Ignorar el ancla vacía del TOC ("Descripción" sin contenido real)
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags($chunk)) ?? '');
                if ($chunk !== '' && strlen($plain) > 40 && ! preg_match('/^Descripci[oó]n$/iu', $plain)) {
                    $parts[] = mb_substr($this->sanitizeHtml($chunk), 0, 20000);
                    break;
                }
            }
        }

        if ($parts === []) {
            $og = $this->matchOne($html, '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i')
                ?: $this->matchOne($html, '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{40,})["\']/i');
            if ($og) {
                $parts[] = '<p>'.e(html_entity_decode($og, ENT_QUOTES, 'UTF-8')).'</p>';
            }
        }

        $htmlOut = trim(implode("\n", $parts));
        $htmlOut = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $htmlOut) ?? $htmlOut;
        $htmlOut = preg_replace('/<template\b[^>]*>[\s\S]*?<\/template>/is', '', $htmlOut) ?? $htmlOut;
        // Quitar el TOC/ancla vacío que a veces se captura como "descripción"
        $htmlOut = preg_replace('/<a[^>]+href=["\']#nav-description["\'][^>]*>[\s\S]*?<\/a>/i', '', $htmlOut) ?? $htmlOut;

        return $this->stripImagesFromHtml($htmlOut);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<array{name: string, value: string}>
     */
    protected function extractDetails(string $html, array $run): array
    {
        $blob = $run;
        if ($this->findAssoc($blob, 'specsModule') === [] && $this->findAssoc($blob, 'propsModule') === []) {
            foreach ([
                'window._d_c_.DCData',
                'window.runParams',
                'window.__INIT_DATA__',
                'window._dida_config_',
                'window.globalData',
            ] as $marker) {
                $try = $this->extractBalancedJson($html, $marker);
                if (is_array($try) && $try !== []) {
                    $blob = $try;
                    break;
                }
            }
        }

        $details = $this->extractDetailsFromJson($html, $blob);
        if ($details === []) {
            $details = $this->extractSpecsFromDom($html);
        }
        if ($details === []) {
            $text = strip_tags($this->extractRichDescriptionBlock($html));
            $details = $this->extractDetailsFromDescriptionText($text);
        }

        return $this->dedupeDetails($details);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<array{name: string, value: string}>
     */
    protected function extractDetailsFromJson(string $html, array $run): array
    {
        $details = [];
        $specsModule = $this->findAssoc($run, 'specsModule')
            ?: $this->findAssoc($run, 'productPropModule')
            ?: $this->findAssoc($run, 'propsModule');

        foreach (['props', 'productProps', 'showedProps', 'groupedProps'] as $key) {
            $props = $specsModule[$key] ?? null;
            if (! is_array($props)) {
                continue;
            }
            foreach ($props as $prop) {
                if (! is_array($prop)) {
                    continue;
                }
                $name = trim((string) ($prop['attrName'] ?? $prop['name'] ?? $prop['attrNameDesc'] ?? ''));
                $value = trim((string) ($prop['attrValue'] ?? $prop['value'] ?? $prop['attrValueDesc'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $details[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        if ($details === [] && preg_match_all(
            '/"attrName"\s*:\s*"((?:\\\\.|[^"\\\\])*)"\s*,\s*"attrValue"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/',
            $html,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $name = trim(html_entity_decode(stripslashes($row[1]), ENT_QUOTES, 'UTF-8'));
                $value = trim(html_entity_decode(stripslashes($row[2]), ENT_QUOTES, 'UTF-8'));
                if ($name !== '' && $value !== '') {
                    $details[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        return $this->dedupeDetails($details);
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    protected function extractDetailsFromDescriptionText(string $text): array
    {
        $text = html_entity_decode(preg_replace('/\s+/', ' ', $text) ?? $text, ENT_QUOTES, 'UTF-8');
        $details = [];
        if (preg_match('/Especificaci[oó]n\s*(.+?)(?:Lista de productos:|Nota:|Lista del paquete:|$)/iu', $text, $m)) {
            $block = $m[1];
        } else {
            $block = $text;
        }
        if (preg_match_all('/(?:^|[\.\n\r])\s*([A-Za-zÁÉÍÓÚáéíóúñÑ][^:\n]{2,70}?):\s*([^\.;\n]{1,180})/u', $block, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $name = trim($row[1]);
                $value = trim($row[2]);
                if ($name === '' || $value === '' || preg_match('/^(puntos clave|nota)$/iu', $name)) {
                    continue;
                }
                $details[] = ['name' => $name, 'value' => $value];
            }
        }

        return $this->dedupeDetails($details);
    }

    /**
     * @param  list<array{name: string, value: string}>  $details
     * @return list<array{name: string, value: string}>
     */
    protected function dedupeDetails(array $details): array
    {
        $seen = [];
        $out = [];
        foreach ($details as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['name' => mb_substr($name, 0, 120), 'value' => mb_substr($value, 0, 500)];
        }

        return array_slice($out, 0, 60);
    }

    /**
     * @return array{list: list<array<string, mixed>>, count: ?int, rating: ?float}
     */
    protected function fetchReviewsFromApi(string $productId, int $pageSize = 20, int $maxPages = 3): array
    {
        $list = [];
        $total = 0;
        $rating = null;
        $lang = strtolower((string) config('aliexpress.target_language', 'ES')) === 'es' ? 'es_ES' : 'en_US';

        for ($page = 1; $page <= $maxPages; $page++) {
            $endpoint = 'https://feedback.aliexpress.com/pc/searchEvaluation.do?'.http_build_query([
                'productId' => $productId,
                'page' => $page,
                'pageSize' => $pageSize,
                'filter' => 'all',
                'sort' => 'complex_default',
                'lang' => $lang,
            ]);

            try {
                $res = Http::withHeaders(array_merge($this->browserHeaders(), [
                    'Accept' => 'application/json, text/plain, */*',
                    'Referer' => 'https://www.aliexpress.com/item/'.$productId.'.html',
                ]))->timeout(22)->get($endpoint);

                if (! $res->successful()) {
                    break;
                }

                $json = $res->json();
                if (! is_array($json)) {
                    break;
                }

                $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
                $eva = is_array($data['evaViewList'] ?? null) ? $data['evaViewList'] : [];
                $stat = is_array($data['productEvaluationStatistic'] ?? null) ? $data['productEvaluationStatistic'] : [];

                if ($total === 0) {
                    $total = (int) ($stat['totalNum'] ?? $data['totalNum'] ?? $data['totalValidNum'] ?? 0);
                }
                if ($rating === null && isset($stat['evarageStar'])) {
                    $rating = $this->toFloat($stat['evarageStar']);
                } elseif ($rating === null && isset($stat['averageStar'])) {
                    $rating = $this->toFloat($stat['averageStar']);
                }

                foreach ($eva as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $normalized = $this->normalizeApiReview($row);
                    if ($normalized !== null) {
                        $list[] = $normalized;
                    }
                }

                $totalPages = (int) ($data['totalPage'] ?? 1);
                if ($page >= $totalPages || $eva === []) {
                    break;
                }
            } catch (\Throwable) {
                break;
            }
        }

        return [
            'list' => array_slice($list, 0, 40),
            'count' => $total > 0 ? $total : ($list !== [] ? count($list) : null),
            'rating' => $rating,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function normalizeApiReview(array $row): ?array
    {
        $comment = trim((string) ($row['buyerTranslationFeedback'] ?? $row['buyerFeedback'] ?? ''));
        $extra = trim((string) ($row['buyerAddFbContent'] ?? ''));
        if ($comment === '' && $extra === '') {
            $score = isset($row['buyerEval']) ? (int) round(((int) $row['buyerEval']) / 20) : null;
            if ($score === null || $score < 1) {
                return null;
            }
            $comment = '';
        } elseif ($extra !== '' && $extra !== $comment) {
            $comment = $comment !== '' ? ($comment."\n\n".$extra) : $extra;
        }

        $imgs = [];
        foreach ($row['images'] ?? $row['imageList'] ?? [] as $img) {
            $u = $this->absUrl(is_string($img) ? $img : (string) ($img['url'] ?? $img['imgUrl'] ?? ''));
            if ($u !== '') {
                $imgs[] = $u;
            }
        }

        return [
            'author' => (string) ($row['buyerName'] ?? 'Comprador'),
            'score' => isset($row['buyerEval']) ? (int) round(((int) $row['buyerEval']) / 20) : null,
            'comment' => html_entity_decode($comment, ENT_QUOTES, 'UTF-8'),
            'date' => (string) ($row['evalDate'] ?? $row['buyerAddFbDate'] ?? ''),
            'country' => (string) ($row['buyerCountry'] ?? ''),
            'avatar' => $this->absUrl((string) ($row['buyerHeadPortrait'] ?? $row['headPortrait'] ?? '')),
            'sku_info' => (string) ($row['skuInfo'] ?? ''),
            'images' => $imgs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function enrichFromRemote(array $product, string $id, string $url, string $localHtml = ''): array
    {
        $reviewCount = (int) ($product['review_count'] ?? 0);
        $haveReviews = count($product['reviews'] ?? []);
        if ($haveReviews === 0 || ($reviewCount > 0 && $haveReviews < $reviewCount)) {
            $remote = $this->fetchReviewsFromApi($id);
            if ($remote['list'] !== []) {
                $product['reviews'] = $remote['list'];
                $product['review_count'] = $remote['count'] ?? count($remote['list']);
                if ($remote['rating'] !== null) {
                    $product['rating'] = $remote['rating'];
                }
                $product['reviews_source'] = 'feedback_api';
            }
        }

        if (($product['details'] ?? []) === []) {
            $html = trim($localHtml);
            if ($html !== '') {
                $html = $this->unwrapDeclarativeShadowDom($html);
                $run = $this->extractRunParams($html);
                $product['details'] = $this->extractDetails($html, $run);
            }

            if (($product['details'] ?? []) === [] && ($product['description_html'] ?? '') !== '') {
                $product['details'] = $this->extractDetailsFromDescriptionText(
                    strip_tags((string) $product['description_html'])
                );
            }

            if (($product['details'] ?? []) === []) {
                $descUrl = $this->extractDescriptionUrl($html);
                $remoteHtml = '';
                if ($descUrl === null || ! $this->htmlHasProductPayload($html)) {
                    $remoteHtml = (string) ($this->fetchHtml($url) ?? '');
                    if ($descUrl === null) {
                        $descUrl = $this->extractDescriptionUrl($remoteHtml);
                    }
                    if ($html === '' || ! $this->htmlHasProductPayload($html)) {
                        $html = $remoteHtml !== '' ? $remoteHtml : $html;
                    }
                }

                if ($descUrl) {
                    $descHtml = $this->fetchHtml($descUrl);
                    if ((! is_string($descHtml) || strlen($descHtml) < 40) && $this->browser->enabled()) {
                        $descHtml = $this->browser->fetchHtml($descUrl);
                    }
                    if (is_string($descHtml) && strlen($descHtml) > 40) {
                        $descDetails = array_merge(
                            $this->extractSpecsFromDom($descHtml),
                            $this->extractDetailsFromDescriptionText(strip_tags($descHtml))
                        );
                        $product['details'] = $this->dedupeDetails($descDetails);
                        if (($product['description_html'] ?? '') === '') {
                            $product['description_html'] = mb_substr($this->sanitizeHtml($descHtml), 0, 20000);
                            $product['description'] = mb_substr(strip_tags($descHtml), 0, 4000);
                        }
                    }
                }

                if (($product['details'] ?? []) === [] && $html !== '' && $this->htmlHasProductPayload($html)) {
                    $run = $this->extractRunParams($html);
                    $product['details'] = $this->extractDetails($html, $run);
                }

                if (($product['details'] ?? []) === [] && $this->browser->enabled()) {
                    $rendered = $this->browser->render($url, [
                        'waitUntil' => 'networkidle0',
                        'timeout_ms' => 30000,
                        'waitForSelector' => [
                            'selector' => 'h1, meta[property="og:title"]',
                            'timeout' => 25000,
                        ],
                        'waitForTimeout' => 3500,
                        'viewport' => ['width' => 1366, 'height' => 900],
                        'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'headers' => ['Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8'],
                        'http_timeout' => 80,
                    ]);
                    if ($rendered['success'] ?? false) {
                        $cfHtml = $this->unwrapDeclarativeShadowDom((string) ($rendered['html'] ?? ''));
                        if ($cfHtml !== '') {
                            $run = $this->extractRunParams($cfHtml);
                            $product['details'] = $this->extractDetails($cfHtml, $run);
                            if (($product['description_html'] ?? '') === '' && ! empty($run['description_html'])) {
                                $product['description_html'] = (string) $run['description_html'];
                            }
                        }
                    }
                }
            }
        }

        if (($product['details'] ?? []) === []) {
            $text = strip_tags((string) ($product['description_html'] ?? $product['description'] ?? ''));
            $product['details'] = $this->extractDetailsFromDescriptionText($text);
        }

        $product['description_html'] = $this->stripImagesFromHtml((string) ($product['description_html'] ?? ''));
        $plain = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', ' ', (string) $product['description_html']) ?? (string) $product['description_html'];
        $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $product['description'] = mb_substr(trim($plain), 0, 4000);

        return $product;
    }

    protected function stripImagesFromHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('/<picture\b[^>]*>[\s\S]*?<\/picture>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/<figure\b[^>]*>[\s\S]*?<\/figure>/is', '', $html) ?? $html;

        return trim($html);
    }

    protected function extractRichDescriptionBlock(string $html): string
    {
        if (preg_match_all('/<div[^>]*class="[^"]*detail-desc-decorate-richtext[^"]*"[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $best = '';
            foreach ($m[0] as $hit) {
                $chunk = $this->extractElementInnerHtml($html, null, (int) $hit[1]);
                $inner = $this->sanitizeHtml($chunk);
                if (strlen(strip_tags($inner)) > strlen(strip_tags($best))) {
                    $best = $inner;
                }
            }
            if ($best !== '') {
                return mb_substr($best, 0, 20000);
            }
        }

        return '';
    }

    protected function extractElementInnerHtml(string $html, ?string $openTagRe = null, ?int $offset = null): string
    {
        if ($openTagRe !== null) {
            if (! preg_match($openTagRe, $html, $m, PREG_OFFSET_CAPTURE, $offset ?? 0)) {
                return '';
            }
            $offset = (int) $m[0][1];
        }
        if ($offset === null) {
            return '';
        }
        $slice = substr($html, $offset);
        if (! preg_match('/^<(\w+)/', $slice, $tm)) {
            return '';
        }
        $tag = strtolower($tm[1]);
        $len = strlen($html);
        $depth = 0;
        $start = $offset;
        for ($i = $offset; $i < min($len, $offset + 400000); $i++) {
            if ($html[$i] !== '<') {
                continue;
            }
            if (preg_match('/^<\/'.preg_quote($tag, '/').'\b/i', substr($html, $i))) {
                $depth--;
                if ($depth === 0) {
                    $closeEnd = strpos($html, '>', $i);
                    $outer = substr($html, $start, ($closeEnd !== false ? $closeEnd + 1 : $i) - $start);
                    if (preg_match('/^<'.$tag.'[^>]*>(.*)<\/'.$tag.'>$/is', $outer, $inner)) {
                        return $inner[1];
                    }

                    return $outer;
                }
            } elseif (preg_match('/^<'.$tag.'[\s>\/]/i', substr($html, $i))) {
                $depth++;
            }
        }

        return '';
    }

    protected function unwrapDeclarativeShadowDom(string $html): string
    {
        $prev = '';
        while ($prev !== $html) {
            $prev = $html;
            $html = preg_replace('/<template\b[^>]*shadowrootmode\s*=\s*["\']open["\'][^>]*>([\s\S]*?)<\/template>/i', '$1', $html) ?? $html;
        }

        return $html;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    protected function htmlHasProductPayload(string $html): bool
    {
        if ($html === '') {
            return false;
        }
        foreach (['runParams', '_d_c_.DCData', '__INIT_DATA__', 'attrName', 'specsModule', 'imagePathList', 'specification--prop'] as $needle) {
            if (str_contains($html, $needle)) {
                return true;
            }
        }

        return $this->extractRunParams($html) !== [];
    }

    protected function extractSpecsFromDom(string $html): array
    {
        $specs = [];
        $scope = $html;
        if (preg_match('/<(?:div|section|ul)[^>]+(?:id="nav-specification"|class="[^"]*specification--(?:wrap|list)[^"]*")[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $chunk = $this->extractElementInnerHtml($html, null, (int) $m[0][1]);
            if ($chunk !== '') {
                $scope = $chunk;
            }
        }

        if (preg_match_all('/<div[^>]+class="[^"]*specification--prop[^"]*"[^>]*>/i', $scope, $hits, PREG_OFFSET_CAPTURE)) {
            foreach ($hits[0] as $hit) {
                $block = $this->extractElementInnerHtml($scope, null, (int) $hit[1]);
                if ($block === '') {
                    continue;
                }
                $parsed = $this->parseSpecificationPropBlock($block);
                if ($parsed !== null) {
                    $specs[] = $parsed;
                }
            }
        }

        if ($specs === [] && preg_match_all(
            '/<(?:div|li|tr)[^>]+class="[^"]*(?:specification--prop|product-prop|sku-property-item|property--item|specification-item|specification-prop)[^"]*"[^>]*>/i',
            $scope,
            $legacyHits,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($legacyHits[0] as $hit) {
                $block = $this->extractElementInnerHtml($scope, null, (int) $hit[1]);
                if ($block === '') {
                    continue;
                }
                $parsed = $this->parseSpecificationPropBlock($block);
                if ($parsed !== null) {
                    $specs[] = $parsed;
                }
            }
        }

        if ($specs === [] && preg_match_all('/<tr[^>]*>[\s\S]{0,800}?<\/tr>/i', $scope, $rows)) {
            foreach ($rows[0] as $row) {
                if (! preg_match_all('/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/i', $row, $cells) || count($cells[1]) < 2) {
                    continue;
                }
                $name = trim(strip_tags($cells[1][0]));
                $value = trim(strip_tags($cells[1][1]));
                if ($name !== '' && $value !== '' && mb_strlen($name) < 80 && mb_strlen($value) < 300) {
                    $specs[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        return array_slice($this->dedupeDetails($specs), 0, 40);
    }

    /**
     * @return array{name: string, value: string}|null
     */
    protected function parseSpecificationPropBlock(string $block): ?array
    {
        $name = trim(strip_tags((string) (
            $this->matchOne($block, '/class="[^"]*(?:specification--title|prop--title|property-title|property--title)[^"]*"[^>]*>([\s\S]*?)<\/div>/i')
            ?: $this->matchOne($block, '/<div[^>]*class="[^"]*title[^"]*"[^>]*>([\s\S]*?)<\/div>/i')
            ?: ''
        )));
        $value = trim(html_entity_decode((string) (
            $this->matchOne($block, '/class="[^"]*(?:specification--desc|prop--desc|property-desc|property--desc)[^"]*"[^>]*\btitle="([^"]*)"/i')
            ?: ''
        ), ENT_QUOTES, 'UTF-8'));
        if ($value === '') {
            $value = trim(strip_tags((string) (
                $this->matchOne($block, '/class="[^"]*(?:specification--desc|prop--desc|property-desc|property--desc)[^"]*"[^>]*>([\s\S]*?)<\/div>/i')
                ?: $this->matchOne($block, '/<div[^>]*class="[^"]*desc[^"]*"[^>]*>([\s\S]*?)<\/div>/i')
                ?: ''
            )));
        }
        if ($name === '' || $value === '') {
            return null;
        }

        return ['name' => $name, 'value' => $value];
    }

    protected function extractShippingTime(string $html): ?string
    {
        $dom = $this->extractShippingFromDom($html);
        if (! empty($dom['time'])) {
            return $dom['time'];
        }

        foreach ([
            '/<[^>]*class="[^"]*dynamic-shipping-contentLayout[^"]*"[^>]*>[\s\S]{0,500}?<strong[^>]*>([\s\S]*?)<\/strong>/i',
            '/<[^>]*class="[^"]*dynamic-shipping--delivery[^"]*"[^>]*>([^<]{4,120})/i',
            '/"(?:displayDeliveryDate|deliveryDateDisplay|deliveryDate)"\s*:\s*"([^"]{4,80})"/',
            '/"(?:deliveryTimeDesc|promiseTime|choiceDeliveryTime)"\s*:\s*"([^"]{4,80})"/',
        ] as $re) {
            $v = $this->matchOne($html, $re);
            if ($v) {
                $text = $this->normalizeShippingTimeLabel(html_entity_decode(stripslashes($v), ENT_QUOTES, 'UTF-8'));
                if (trim($text) !== '') {
                    return mb_substr(trim($text), 0, 160);
                }
            }
        }
        $min = $this->matchOne($html, '/"(?:minDeliveryDays|minPromiseDays|deliveryDayMin)"\s*:\s*"?(\d+)/');
        $max = $this->matchOne($html, '/"(?:maxDeliveryDays|maxPromiseDays|deliveryDayMax)"\s*:\s*"?(\d+)/');
        if ($min && $max) {
            return $min.'-'.$max.' días';
        }
        if ($max) {
            return 'hasta '.$max.' días';
        }
        $from = $this->matchOne($html, '/"(?:minDeliveryDate|earliestDeliveryDate)"\s*:\s*"([^"]+)"/');
        $to = $this->matchOne($html, '/"(?:maxDeliveryDate|latestDeliveryDate)"\s*:\s*"([^"]+)"/');
        if ($from && $to) {
            return 'Entrega '.$from.' – '.$to;
        }
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s*(?:d[ií]as|days)/iu', $html, $m)) {
            return $m[1].'-'.$m[2].' días';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $module
     */
    protected function shippingTimeFromModule(array $module): ?string
    {
        foreach (['deliveryDate', 'displayDeliveryDate', 'deliveryDateDisplay', 'deliveryTimeDesc', 'promise', 'choiceDeliveryTime'] as $key) {
            $v = $module[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return mb_substr(trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8')), 0, 160);
            }
        }
        $min = $module['minDeliveryDays'] ?? $module['minPromiseDays'] ?? $module['deliveryDayMin'] ?? null;
        $max = $module['maxDeliveryDays'] ?? $module['maxPromiseDays'] ?? $module['deliveryDayMax'] ?? null;
        if ($min && $max) {
            return $min.'-'.$max.' días';
        }
        $from = $module['minDeliveryDate'] ?? $module['earliestDeliveryDate'] ?? null;
        $to = $module['maxDeliveryDate'] ?? $module['latestDeliveryDate'] ?? null;
        if (is_string($from) && is_string($to) && $from !== '' && $to !== '') {
            return 'Entrega '.$from.' – '.$to;
        }

        return null;
    }

    protected function shippingTimeFromText(string $text): ?string
    {
        $t = html_entity_decode(preg_replace('/\s+/', ' ', $text) ?? $text, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^env[ií]o\s*:\s*(.+)$/iu', $t, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s*(?:d[ií]as|days)/iu', $t, $m)) {
            return $m[1].'-'.$m[2].' días';
        }
        if (preg_match('/(?:entrega(?:\s+el)?|arrives?\s+(?:by)?|delivery)\s*[:\s]+(.{4,80}?)(?:\.|$)/iu', $t, $m)) {
            return mb_substr(trim($m[1]), 0, 160);
        }
        if (preg_match('/(\d+)\s*(?:d[ií]as|days)/iu', $t, $m)) {
            return $m[1].' días';
        }
        if (preg_match('/\d{1,2}\s*[-–]\s*\d{1,2}\s+de\s+[A-ZÁÉÍÓÚa-záéíóú]{3,}/iu', $t, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    protected function prettySkuName(string $skuAttr): string
    {
        $skuAttr = html_entity_decode(stripslashes($skuAttr), ENT_QUOTES, 'UTF-8');
        if (preg_match_all('/#([^;#]+)/', $skuAttr, $m)) {
            $labels = array_values(array_filter(array_map('trim', $m[1])));
            if ($labels !== []) {
                return mb_substr(implode(' / ', $labels), 0, 190);
            }
        }
        $clean = trim((string) (preg_replace('/#.*$/', '', $skuAttr) ?? $skuAttr));

        return mb_substr($clean !== '' ? $clean : $skuAttr, 0, 190);
    }

    /**
     * @param  array<string, mixed>  $skuModule
     * @return list<array<string, mixed>>
     */
    protected function variantsFromSkuModule(array $skuModule): array
    {
        $propMap = [];
        foreach ($skuModule['productSKUPropertyList'] ?? [] as $prop) {
            if (! is_array($prop)) {
                continue;
            }
            $pid = (string) ($prop['skuPropertyId'] ?? '');
            $pname = (string) ($prop['skuPropertyName'] ?? '');
            foreach ($prop['skuPropertyValues'] ?? [] as $val) {
                if (! is_array($val)) {
                    continue;
                }
                $vid = (string) ($val['propertyValueId'] ?? $val['propertyValueIdLong'] ?? '');
                $label = (string) ($val['propertyValueDisplayName'] ?? $val['propertyValueName'] ?? $val['skuPropertyTips'] ?? '');
                $key = ($pid !== '' && $vid !== '') ? $pid.':'.$vid : $vid;
                $propMap[$key] = [
                    'name' => ($pname !== '' && $label !== '') ? ($pname.': '.$label) : $label,
                    'image' => $this->absUrl((string) ($val['skuPropertyImagePath'] ?? '')),
                ];
            }
        }

        $variants = [];
        foreach ($skuModule['skuPriceList'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $skuId = (string) ($row['skuId'] ?? $row['skuIdStr'] ?? '');
            $skuAttr = (string) ($row['skuAttr'] ?? $row['skuPropIds'] ?? '');
            $skuVal = is_array($row['skuVal'] ?? null) ? $row['skuVal'] : [];
            $name = $this->prettySkuName($skuAttr);
            $image = '';
            if ($skuAttr !== '') {
                $parts = [];
                foreach (preg_split('/;/', $skuAttr) ?: [] as $piece) {
                    $piece = trim((string) $piece);
                    if ($piece === '') {
                        continue;
                    }
                    $idPart = preg_replace('/#.*$/', '', $piece) ?? $piece;
                    if (isset($propMap[$idPart]['name']) && $propMap[$idPart]['name'] !== '') {
                        $parts[] = $propMap[$idPart]['name'];
                    }
                    if ($image === '' && ! empty($propMap[$idPart]['image'])) {
                        $image = $propMap[$idPart]['image'];
                    }
                }
                if ($parts !== []) {
                    $name = mb_substr(implode(' / ', $parts), 0, 190);
                }
            }
            $variants[] = [
                'vid' => $skuId,
                'sku' => $skuId !== '' ? $skuId : $skuAttr,
                'name' => $name !== '' ? $name : ('SKU '.$skuId),
                'key' => $skuAttr,
                'price' => $this->toFloat(
                    $skuVal['skuActivityAmount']['value']
                    ?? $skuVal['actSkuCalPrice']
                    ?? $skuVal['skuAmount']['value']
                    ?? null
                ),
                'image' => $image,
                'stock' => isset($skuVal['availQuantity']) ? (int) $skuVal['availQuantity'] : null,
                'weight' => null,
            ];
        }

        // Sin skuPriceList: expandir cada valor de propiedad (útil en HTML pegado incompleto)
        if ($variants === [] && $propMap !== []) {
            foreach ($propMap as $key => $meta) {
                $name = trim((string) ($meta['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $variants[] = [
                    'vid' => (string) $key,
                    'sku' => (string) $key,
                    'name' => mb_substr($name, 0, 190),
                    'key' => (string) $key,
                    'price' => null,
                    'image' => (string) ($meta['image'] ?? ''),
                    'stock' => null,
                    'weight' => null,
                ];
            }
        }

        return $variants;
    }

    protected function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $n = preg_replace('/[^\d.,]/', '', (string) $value);
        if ($n === null || $n === '') {
            return null;
        }
        $lastComma = strrpos($n, ',');
        $lastDot = strrpos($n, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $n = str_replace('.', '', $n);
                $n = str_replace(',', '.', $n);
            } else {
                $n = str_replace(',', '', $n);
            }
        } elseif ($lastComma !== false) {
            $frac = substr($n, $lastComma + 1);
            $n = (strlen($frac) === 3 && $lastComma > 0)
                ? str_replace(',', '', $n)
                : str_replace(',', '.', $n);
        }

        return is_numeric($n) ? (float) $n : null;
    }

    protected function sanitizeHtml(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = preg_replace('/<template\b[^>]*>[\s\S]*?<\/template>/is', '', $html) ?? $html;

        return trim($html);
    }
}
