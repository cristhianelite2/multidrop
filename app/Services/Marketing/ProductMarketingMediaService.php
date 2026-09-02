<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\ProductMediaDownloadService;
use App\Services\Storage\MediaUrl;
use App\Services\Storefront\DesignAssetUrl;

class ProductMarketingMediaService
{
    public function __construct(
        protected ProductMediaDownloadService $media
    ) {}

    public function productPageUrl(Store $store, Product $product): string
    {
        $base = rtrim($store->publicUrl(), '/');
        $slug = trim((string) $product->slug);
        if ($slug === '') {
            return $base;
        }

        return $base.'/pages/'.rawurlencode($slug);
    }

    /**
     * @return list<string>
     */
    public function exportImageUrls(Product $product): array
    {
        $urls = $product->galleryImages();
        $verified = is_array($product->verified_data) ? $product->verified_data : [];

        foreach (is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [] as $review) {
            if (! is_array($review)) {
                continue;
            }
            foreach (is_array($review['images'] ?? null) ? $review['images'] : [] as $img) {
                if (is_string($img) && trim($img) !== '') {
                    $urls[] = trim($img);
                }
            }
        }

        $unique = [];
        $seen = [];
        foreach ($urls as $url) {
            $key = strtolower(trim($url));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = trim($url);
        }

        return $unique;
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    public function exportVideoEntries(Product $product): array
    {
        $rows = [];
        $seen = [];
        $verified = is_array($product->verified_data) ? $product->verified_data : [];

        foreach (is_array($verified['videos'] ?? null) ? $verified['videos'] : [] as $i => $video) {
            if (! is_array($video)) {
                continue;
            }
            $url = trim((string) ($video['url'] ?? $video['play_url'] ?? $video['source_url'] ?? $video['videoUrl'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (str_contains(strtolower($url), '.m3u8')) {
                continue;
            }
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'url' => $url,
                'name' => trim((string) ($video['name'] ?? $video['videoName'] ?? '')) ?: ('video-'.($i + 1)),
            ];
        }

        return $rows;
    }

    /**
     * @return array{body: string, mime: string, filename: string}|null
     */
    public function fetchMediaBytes(Store $store, Product $product, string $rawUrl, string $prefix, int $index): ?array
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return null;
        }

        $candidates = [$rawUrl];
        $absolute = $this->absoluteUrl($rawUrl, $store);
        if ($absolute !== '' && ! in_array($absolute, $candidates, true)) {
            $candidates[] = $absolute;
        }

        foreach ($candidates as $candidate) {
            $file = $this->media->fetchBytes($candidate, $prefix.'-'.$product->id, $index);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function publicImageUrls(Product $product, int $limit = 8, ?Store $store = null): array
    {
        $urls = [];
        foreach ($product->galleryImages() as $url) {
            $abs = $this->absoluteUrl((string) $url, $store);
            if ($abs !== '') {
                $urls[] = $abs;
            }
            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Partes multimodal para MIIA: base64 desde R2/disco (fiable) con fallback a URL pública.
     *
     * @return list<array{type: string, image_url: array{url: string}}>
     */
    public function visionImageParts(Store $store, Product $product, int $limit = 4): array
    {
        $parts = [];
        $i = 0;
        foreach (array_slice($product->galleryImages(), 0, $limit) as $rawUrl) {
            $rawUrl = trim((string) $rawUrl);
            if ($rawUrl === '') {
                continue;
            }
            $i++;
            $fetched = $this->media->fetchBytes($rawUrl, 'product-'.$product->id, $i);
            if (! is_array($fetched) || ($fetched['body'] ?? '') === '') {
                $abs = $this->absoluteUrl($rawUrl, $store);
                if ($abs !== '' && $abs !== $rawUrl) {
                    $fetched = $this->media->fetchBytes($abs, 'product-'.$product->id, $i);
                }
            }
            if (is_array($fetched) && ($fetched['body'] ?? '') !== '') {
                $mime = trim((string) ($fetched['mime'] ?? 'image/jpeg'));
                $mime = preg_replace('/;.*/', '', $mime) ?: 'image/jpeg';
                if (! str_starts_with($mime, 'image/')) {
                    $mime = 'image/jpeg';
                }
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:'.$mime.';base64,'.base64_encode((string) $fetched['body']),
                    ],
                ];

                continue;
            }

            $abs = $this->absoluteUrl($rawUrl, $store);
            if ($abs !== '') {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $abs],
                ];
            }
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    public function publicVideoUrls(Product $product, int $limit = 4, ?Store $store = null): array
    {
        $urls = [];
        foreach ($this->media->videos($product) as $row) {
            $abs = $this->absoluteUrl((string) ($row['url'] ?? ''), $store);
            if ($abs === '') {
                continue;
            }
            if (str_contains(strtolower($abs), '.m3u8')) {
                continue;
            }
            $urls[] = $abs;
            if (count($urls) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{author?: string, text: string, rating?: int}>
     */
    public function reviewSnippets(Product $product, int $limit = 5): array
    {
        $out = [];
        foreach ($product->reviews() as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['comment'] ?? $row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = array_filter([
                'author' => trim((string) ($row['author'] ?? $row['name'] ?? '')),
                'text' => mb_substr($text, 0, 280),
                'rating' => isset($row['score']) ? (int) $row['score'] : null,
            ]);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   title: string,
     *   description: string,
     *   url: string,
     *   image_urls: list<string>,
     *   video_urls: list<string>,
     *   reviews: list<array{author?: string, text: string, rating?: int}>
     * }
     */
    public function creatifyLinkPayload(Store $store, Product $product): array
    {
        $name = trim($product->localizedName());
        $desc = trim(strip_tags((string) ($product->localizedDescription() ?: '')));
        if ($desc === '') {
            $desc = $name;
        }

        return [
            'title' => $name,
            'description' => mb_substr($desc, 0, 1800),
            'url' => $this->productPageUrl($store, $product),
            'image_urls' => $this->publicImageUrls($product, 8, $store),
            'video_urls' => $this->publicVideoUrls($product, 4, $store),
            'reviews' => $this->reviewSnippets($product, 5),
        ];
    }

    protected function absoluteUrl(string $url, ?Store $store = null): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $publicBase = $store ? rtrim($store->publicUrl(), '/') : null;

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            if ($publicBase !== null && MediaUrl::isMaskedUrl($url)) {
                $storage = MediaUrl::storagePathFromUrl($url);
                if ($storage) {
                    return $publicBase.'/'.MediaUrl::prefix().'/'.ltrim($storage, '/');
                }
            }

            return $url;
        }
        if (MediaUrl::isMaskedUrl($url) || str_starts_with(ltrim($url, '/'), MediaUrl::prefix().'/')) {
            $storage = MediaUrl::storagePathFromUrl($url);
            if ($storage) {
                $base = $publicBase ?? rtrim((string) config('app.url'), '/');

                return $base.'/'.MediaUrl::prefix().'/'.ltrim($storage, '/');
            }
        }
        if (str_starts_with($url, '/media/')) {
            $base = $publicBase ?? rtrim((string) config('app.url'), '/');

            return $base.$url;
        }
        if (str_starts_with($url, 'storage/')) {
            return DesignAssetUrl::fromPath($url);
        }
        if (str_starts_with($url, '/')) {
            $base = $publicBase;
            if ($base === null && app()->bound('request') && request()->getHost() !== '') {
                $base = rtrim(request()->getSchemeAndHttpHost().request()->getBaseUrl(), '/');
            }
            if ($base === null) {
                $base = rtrim((string) config('app.url'), '/');
            }

            return $base.$url;
        }

        return $url;
    }
}
