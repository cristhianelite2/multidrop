<?php

namespace App\Services\Catalog;

use App\Domain\Suppliers\AliExpress\AliExpressProductFetcher;
use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\Product;
use App\Models\Store;
use App\Services\Storage\ProductMediaMirrorService;

class ProductSimilarImportService
{
    public function __construct(
        protected AliExpressProductFetcher $aeFetcher,
        protected CjConnector $cjConnector,
        protected ProductMediaMirrorService $mirror,
    ) {}

    public function detectSource(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (AliExpressProductFetcher::looksLikeAliExpress($url)) {
            return 'aliexpress';
        }
        if (preg_match('#cjdropshipping\.com#i', $url) || CjConnector::parseProductRef($url)) {
            return 'cj';
        }

        return null;
    }

    /**
     * @return array{success: bool, source?: string, title?: string, counts?: array<string, int>, error?: string}
     */
    public function preview(string $url, Store $store): array
    {
        $fetched = $this->fetchRemote($url, $store);
        if (! ($fetched['success'] ?? false)) {
            return $fetched;
        }

        $product = $fetched['product'];

        return [
            'success' => true,
            'source' => $fetched['source'],
            'title' => (string) ($product['title'] ?? ''),
            'counts' => $this->countSections($product),
        ];
    }

    /**
     * @param  list<string>  $sections
     * @return array{success: bool, message?: string, source?: string, title?: string, imported?: array<string, int>, payload?: array<string, mixed>, error?: string}
     */
    public function import(Product $product, string $url, Store $store, array $sections, bool $replace = false): array
    {
        $sections = array_values(array_unique(array_filter($sections, fn ($s) => is_string($s) && $s !== '')));
        if ($sections === []) {
            return ['success' => false, 'error' => 'Marca al menos una sección para importar.'];
        }

        $fetched = $this->fetchRemote($url, $store);
        if (! ($fetched['success'] ?? false)) {
            return $fetched;
        }

        return $this->importFromParsed(
            $product,
            $fetched['product'],
            $sections,
            $replace,
            (string) ($fetched['source'] ?? 'marketplace')
        );
    }

    /**
     * @param  list<string>  $sections
     * @return array{success: bool, message?: string, source?: string, title?: string, imported?: array<string, int>, payload?: array<string, mixed>, error?: string}
     */
    public function importFromParsed(Product $product, array $remote, array $sections, bool $replace = false, string $source = 'marketplace', bool $mirrorVideos = true): array
    {
        $sections = array_values(array_unique(array_filter($sections, fn ($s) => is_string($s) && $s !== '')));
        if ($sections === []) {
            return ['success' => false, 'error' => 'Marca al menos una sección para importar.'];
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $imported = [];

        if (in_array('images', $sections, true)) {
            $before = count(is_array($verified['images'] ?? null) ? $verified['images'] : []);
            $verified['images'] = $this->mergeUrlList(
                is_array($verified['images'] ?? null) ? $verified['images'] : [],
                $remote['images'] ?? [],
                $replace
            );
            $imported['images'] = max(0, count($verified['images']) - ($replace ? 0 : $before));
            if ($replace) {
                $imported['images'] = count($verified['images']);
            }
        }

        if (in_array('videos', $sections, true)) {
            $before = count(is_array($verified['videos'] ?? null) ? $verified['videos'] : []);
            $verified['videos'] = $this->mergeVideos(
                is_array($verified['videos'] ?? null) ? $verified['videos'] : [],
                $remote['videos'] ?? [],
                $replace
            );
            $imported['videos'] = max(0, count($verified['videos']) - ($replace ? 0 : $before));
            if ($replace) {
                $imported['videos'] = count($verified['videos']);
            }
        }

        if (in_array('reviews', $sections, true)) {
            $before = count(is_array($verified['reviews'] ?? null) ? $verified['reviews'] : []);
            $verified['reviews'] = $this->mergeReviews(
                is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [],
                $remote['reviews'] ?? [],
                $replace
            );
            $verified['comments'] = array_values(array_filter(
                $verified['reviews'],
                fn ($r) => is_array($r) && (trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images']))
            ));
            $verified['comment_count'] = count($verified['comments']);
            $verified['review_count'] = count($verified['reviews']);
            $imported['reviews'] = max(0, count($verified['reviews']) - ($replace ? 0 : $before));
            if ($replace) {
                $imported['reviews'] = count($verified['reviews']);
            }
            if (! empty($verified['reviews'])) {
                $avg = $this->averageReviewScore($verified['reviews']);
                if ($avg !== null) {
                    $verified['rating_avg'] = $avg;
                    $verified['rating'] = $avg;
                }
            }
        }

        if (in_array('details', $sections, true)) {
            $before = count(is_array($verified['details'] ?? null) ? $verified['details'] : []);
            $verified['details'] = $this->mergeDetails(
                is_array($verified['details'] ?? null) ? $verified['details'] : [],
                $remote['details'] ?? [],
                $replace
            );
            $imported['details'] = max(0, count($verified['details']) - ($replace ? 0 : $before));
            if ($replace) {
                $imported['details'] = count($verified['details']);
            }
        }

        if (in_array('description', $sections, true)) {
            $plain = trim((string) ($remote['description_plain'] ?? ''));
            $html = trim((string) ($remote['description_html'] ?? ''));
            $short = trim((string) ($remote['description_short'] ?? ''));
            $descUpdated = false;
            if ($replace || trim((string) ($product->description ?? '')) === '') {
                if ($plain !== '') {
                    $product->description = mb_substr($plain, 0, 20000);
                    $descUpdated = true;
                }
            }
            if ($html !== '' && ($replace || trim((string) ($verified['description_html'] ?? '')) === '')) {
                $verified['description_html'] = $html;
                $descUpdated = true;
            }
            if ($short !== '' && ($replace || trim((string) ($verified['description_short'] ?? '')) === '')) {
                $verified['description_short'] = $short;
                $descUpdated = true;
            } elseif ($plain !== '' && ($replace || trim((string) ($verified['description_short'] ?? '')) === '')) {
                $verified['description_short'] = mb_substr($plain, 0, 500);
                $descUpdated = true;
            }
            if ($replace && ($plain !== '' || $html !== '')) {
                if ($plain !== '') {
                    $product->description = mb_substr($plain, 0, 20000);
                }
                if ($html !== '') {
                    $verified['description_html'] = $html;
                }
                if ($short !== '') {
                    $verified['description_short'] = $short;
                }
                $descUpdated = true;
            }
            if ($descUpdated) {
                $imported['description'] = 1;
            }
        }

        $product->verified_data = $verified;
        $product->save();

        $fresh = $product->fresh() ?? $product;
        try {
            $product = $this->mirror->mirrorProduct($fresh, $mirrorVideos);
        } catch (\Throwable $e) {
            report($e);
            $product = $fresh;
        }
        $product = $product->fresh() ?? $product;

        return [
            'success' => true,
            'source' => $source,
            'title' => (string) ($remote['title'] ?? ''),
            'imported' => $imported,
            'message' => $this->buildMessage($imported, $source === 'cj' ? 'cj' : ($source === 'aliexpress' ? 'aliexpress' : $source)),
            'payload' => $this->exportForFrontend($product),
        ];
    }

    /**
     * @return array{success: bool, source?: string, product?: array<string, mixed>, error?: string}
     */
    public function fetchFromPage(string $url, ?string $html, ?array $snapshot, Store $store): array
    {
        $url = trim($url);
        if (preg_match('#cjdropshipping\.com#i', $url) || CjConnector::parseProductRef($url)) {
            return $this->fetchRemote($url, $store);
        }

        if ($html !== null && $html !== '') {
            $fetched = $this->aeFetcher->parseFromCapture((string) $html, $url, is_array($snapshot) ? $snapshot : []);
            if (! ($fetched['success'] ?? false)) {
                return ['success' => false, 'error' => (string) ($fetched['error'] ?? 'No se pudo parsear AliExpress.')];
            }

            return [
                'success' => true,
                'source' => 'aliexpress',
                'product' => $this->normalizeAe(is_array($fetched['product'] ?? null) ? $fetched['product'] : []),
            ];
        }

        if ($url !== '' && AliExpressProductFetcher::looksLikeAliExpress($url)) {
            return $this->fetchRemote($url, $store);
        }

        return ['success' => false, 'error' => 'No hay URL ni HTML para extraer.'];
    }

    /**
     * @return array{success: bool, source?: string, title?: string, counts?: array<string, int>, error?: string}
     */
    public function previewFromPage(string $url, ?string $html, ?array $snapshot, Store $store): array
    {
        $fetched = $this->fetchFromPage($url, $html, $snapshot, $store);
        if (! ($fetched['success'] ?? false)) {
            return $fetched;
        }

        $product = $fetched['product'];

        return [
            'success' => true,
            'source' => $fetched['source'],
            'title' => (string) ($product['title'] ?? ''),
            'counts' => $this->countSections($product),
        ];
    }

    /**
     * @return array{success: bool, source?: string, product?: array<string, mixed>, error?: string}
     */
    protected function fetchRemote(string $url, Store $store): array
    {
        $source = $this->detectSource($url);
        if ($source === null) {
            return [
                'success' => false,
                'error' => 'URL no reconocida. Pega una ficha de AliExpress o CJ Dropshipping (URL, PID o SKU).',
            ];
        }

        if ($source === 'aliexpress') {
            $out = $this->aeFetcher->fetch($url);
            if (! ($out['success'] ?? false)) {
                return ['success' => false, 'error' => (string) ($out['error'] ?? 'No se pudo obtener el producto de AliExpress.')];
            }

            return [
                'success' => true,
                'source' => 'aliexpress',
                'product' => $this->normalizeAe(is_array($out['product'] ?? null) ? $out['product'] : []),
            ];
        }

        if (! config('cj.access_token') && config('cj.api_key')) {
            $this->cjConnector->authorizeWithApiKey((string) config('cj.api_key'));
        }

        $country = strtoupper((string) ($store->market?->code ?? 'MX'));
        if ($country === 'UK') {
            $country = 'GB';
        }

        $out = $this->cjConnector->crawlProductFromInput($url, $country);
        if (! ($out['success'] ?? false)) {
            return ['success' => false, 'error' => (string) ($out['error'] ?? 'No se pudo obtener el producto de CJ.')];
        }

        return [
            'success' => true,
            'source' => 'cj',
            'product' => $this->normalizeCj(is_array($out['product'] ?? null) ? $out['product'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    protected function normalizeAe(array $product): array
    {
        $videos = [];
        foreach (array_values($product['videos'] ?? []) as $video) {
            if (! is_array($video)) {
                continue;
            }
            $url = trim((string) ($video['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $videos[] = [
                'url' => $url,
                'name' => trim((string) ($video['name'] ?? '')) ?: 'Video',
                'cover' => trim((string) ($video['cover'] ?? '')) ?: null,
            ];
        }

        $videos = $this->normalizeVideoRows($videos);

        $plain = trim(strip_tags((string) ($product['description_html'] ?? $product['description'] ?? '')));

        return [
            'title' => (string) ($product['title'] ?? ''),
            'images' => array_values(array_filter(array_map('strval', $product['images'] ?? []))),
            'videos' => $videos,
            'reviews' => array_values(is_array($product['reviews'] ?? null) ? $product['reviews'] : []),
            'details' => array_values(is_array($product['details'] ?? null) ? $product['details'] : []),
            'description_plain' => $plain,
            'description_html' => (string) ($product['description_html'] ?? ''),
            'description_short' => (string) ($product['description_short'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    protected function normalizeCj(array $product): array
    {
        $videos = [];
        foreach (array_values($product['videos'] ?? []) as $video) {
            if (is_string($video) && trim($video) !== '') {
                $videos[] = ['url' => trim($video), 'name' => 'Video', 'cover' => null];

                continue;
            }
            if (! is_array($video)) {
                continue;
            }
            $url = trim((string) ($video['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $videos[] = [
                'url' => $url,
                'name' => trim((string) ($video['name'] ?? '')) ?: 'Video',
                'cover' => trim((string) ($video['cover'] ?? '')) ?: null,
            ];
        }

        $videos = $this->normalizeVideoRows($videos);

        $plain = trim((string) ($product['description'] ?? ''));
        if ($plain === '') {
            $plain = trim(strip_tags((string) ($product['description_html'] ?? '')));
        }

        return [
            'title' => (string) ($product['title'] ?? $product['name'] ?? $product['productName'] ?? ''),
            'images' => array_values(array_filter(array_map('strval', $product['images'] ?? []))),
            'videos' => $videos,
            'reviews' => array_values(is_array($product['reviews'] ?? null) ? $product['reviews'] : []),
            'details' => array_values(is_array($product['details'] ?? null) ? $product['details'] : []),
            'description_plain' => $plain,
            'description_html' => (string) ($product['description_html'] ?? $product['description_long'] ?? ''),
            'description_short' => (string) ($product['description_short'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $videos
     * @return list<array{url: string, name: string, cover: ?string}>
     */
    protected function normalizeVideoRows(array $videos): array
    {
        $rows = [];
        $mp4Bases = [];
        foreach ($videos as $video) {
            if (is_string($video) && trim($video) !== '') {
                $video = ['url' => trim($video), 'name' => 'Video', 'cover' => null];
            }
            if (! is_array($video)) {
                continue;
            }
            $url = trim((string) ($video['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = [
                'url' => $url,
                'name' => trim((string) ($video['name'] ?? '')) ?: 'Video',
                'cover' => trim((string) ($video['cover'] ?? '')) ?: null,
            ];
            if (str_contains(strtolower($url), '.mp4')) {
                $mp4Bases[$this->videoBaseKey($url)] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $url = $row['url'];
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            if (str_contains(strtolower($url), '.m3u8') && isset($mp4Bases[$this->videoBaseKey($url)])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return array_slice($out, 0, 8);
    }

    protected function videoBaseKey(string $url): string
    {
        $base = preg_replace('#\.(m3u8|mp4)(\?.*)?$#i', '', strtolower($url));

        return is_string($base) && $base !== '' ? $base : strtolower($url);
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, int>
     */
    protected function countSections(array $product): array
    {
        return [
            'images' => count($product['images'] ?? []),
            'videos' => count($product['videos'] ?? []),
            'reviews' => count($product['reviews'] ?? []),
            'details' => count($product['details'] ?? []),
            'description' => (trim((string) ($product['description_plain'] ?? '')) !== '' || trim((string) ($product['description_html'] ?? '')) !== '') ? 1 : 0,
        ];
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     */
    protected function countNewUrls(array $existing, array $incoming, bool $replace): int
    {
        if ($replace) {
            return count(array_values(array_filter(array_map(fn ($u) => trim((string) $u), $incoming))));
        }
        $existing = array_map(fn ($u) => trim((string) $u), $existing);
        $added = 0;
        foreach ($incoming as $url) {
            $url = trim((string) $url);
            if ($url !== '' && ! in_array($url, $existing, true)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     */
    protected function countNewVideos(array $existing, array $incoming, bool $replace): int
    {
        if ($replace) {
            return count($incoming);
        }
        $urls = [];
        foreach ($existing as $row) {
            if (is_array($row) && trim((string) ($row['url'] ?? '')) !== '') {
                $urls[] = trim((string) $row['url']);
            }
        }
        $added = 0;
        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '' && ! in_array($url, $urls, true)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    protected function mergeUrlList(array $existing, array $incoming, bool $replace): array
    {
        $base = $replace ? [] : array_values(array_filter(array_map(fn ($u) => trim((string) $u), $existing)));
        foreach ($incoming as $url) {
            $url = trim((string) $url);
            if ($url === '' || in_array($url, $base, true)) {
                continue;
            }
            $base[] = mb_substr($url, 0, 500);
        }

        return array_values($base);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    protected function mergeVideos(array $existing, array $incoming, bool $replace): array
    {
        $map = [];
        if (! $replace) {
            foreach ($existing as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $url = trim((string) ($row['url'] ?? ''));
                if ($url !== '') {
                    $map[$url] = $row;
                }
            }
        }
        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = [
                'url' => $url,
                'name' => trim((string) ($row['name'] ?? '')) ?: 'Video',
                'cover' => trim((string) ($row['cover'] ?? '')) ?: null,
            ];
        }

        return array_values($map);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    protected function mergeReviews(array $existing, array $incoming, bool $replace): array
    {
        $map = [];
        if (! $replace) {
            foreach ($existing as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $map[$this->reviewFingerprint($row)] = $row;
            }
        }
        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeReviewRow($row);
            if ($normalized === null) {
                continue;
            }
            $map[$this->reviewFingerprint($normalized)] = $normalized;
        }

        return array_values($map);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array{name: string, value: string}>
     */
    protected function mergeDetails(array $existing, array $incoming, bool $replace): array
    {
        $map = [];
        if (! $replace) {
            foreach ($existing as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }
                $map[mb_strtolower($name)] = [
                    'name' => mb_substr($name, 0, 120),
                    'value' => mb_substr($value, 0, 500),
                ];
            }
        }
        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $map[mb_strtolower($name)] = [
                'name' => mb_substr($name, 0, 120),
                'value' => mb_substr($value, 0, 500),
            ];
        }

        return array_values($map);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function reviewFingerprint(array $row): string
    {
        return md5(mb_strtolower(trim((string) ($row['author'] ?? ''))).'|'.trim((string) ($row['comment'] ?? '')).'|'.trim((string) ($row['date'] ?? '')));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function normalizeReviewRow(array $row): ?array
    {
        $author = trim((string) ($row['author'] ?? ''));
        $comment = trim((string) ($row['comment'] ?? ''));
        $score = (int) ($row['score'] ?? $row['rating'] ?? 0);
        $images = [];
        foreach ((array) ($row['images'] ?? []) as $img) {
            $img = trim((string) $img);
            if ($img !== '') {
                $images[] = $img;
            }
        }
        if ($author === '' && $comment === '' && ($score < 1 || $score > 5) && $images === []) {
            return null;
        }

        $country = strtoupper(trim((string) ($row['country'] ?? '')));
        if ($country === 'UK') {
            $country = 'GB';
        }

        return array_filter([
            'author' => $author !== '' ? mb_substr($author, 0, 80) : 'Comprador',
            'score' => ($score >= 1 && $score <= 5) ? $score : null,
            'comment' => $comment !== '' ? mb_substr($comment, 0, 4000) : null,
            'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
            'avatar' => trim((string) ($row['avatar'] ?? '')) ?: null,
            'date' => trim((string) ($row['date'] ?? '')) ?: null,
            'sku_info' => trim((string) ($row['sku_info'] ?? '')) ?: null,
            'images' => $images,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $reviews
     */
    protected function averageReviewScore(array $reviews): ?float
    {
        $scores = [];
        foreach ($reviews as $row) {
            if (! is_array($row)) {
                continue;
            }
            $score = (int) ($row['score'] ?? 0);
            if ($score >= 1 && $score <= 5) {
                $scores[] = $score;
            }
        }
        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * @param  array<string, int>  $imported
     */
    protected function buildMessage(array $imported, string $source): string
    {
        $labels = [
            'images' => 'imágenes',
            'videos' => 'videos',
            'reviews' => 'reseñas',
            'details' => 'detalles',
            'description' => 'descripción',
        ];
        $parts = [];
        foreach ($labels as $key => $label) {
            $n = (int) ($imported[$key] ?? 0);
            if ($n > 0) {
                $parts[] = $n.' '.$label;
            }
        }
        $from = $source === 'cj' ? 'CJ' : 'AliExpress';
        if ($parts === []) {
            return 'Importación desde '.$from.' completada (sin cambios nuevos).';
        }

        return 'Importado desde '.$from.': '.implode(', ', $parts).'.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function exportForFrontend(Product $product): array
    {
        $verified = is_array($product->verified_data) ? $product->verified_data : [];

        return [
            'description' => (string) ($product->description ?? ''),
            'image_url' => (string) ($product->image_url ?? ''),
            'images' => array_values($verified['images'] ?? []),
            'videos' => array_values($verified['videos'] ?? []),
            'reviews' => array_values($verified['reviews'] ?? []),
            'details' => array_values($verified['details'] ?? []),
            'rating_avg' => $verified['rating_avg'] ?? $verified['rating'] ?? null,
            'review_count' => $verified['review_count'] ?? count($verified['reviews'] ?? []),
        ];
    }
}
