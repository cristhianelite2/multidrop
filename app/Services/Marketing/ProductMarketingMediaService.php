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
    public function publicImageUrls(Product $product, int $limit = 8): array
    {
        $urls = [];
        foreach ($product->galleryImages() as $url) {
            $abs = $this->absoluteUrl((string) $url);
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
     * @return list<string>
     */
    public function publicVideoUrls(Product $product, int $limit = 4): array
    {
        $urls = [];
        foreach ($this->media->videos($product) as $row) {
            $abs = $this->absoluteUrl((string) ($row['url'] ?? ''));
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
            'image_urls' => $this->publicImageUrls($product, 8),
            'video_urls' => $this->publicVideoUrls($product, 4),
            'reviews' => $this->reviewSnippets($product, 5),
        ];
    }

    protected function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (MediaUrl::isMaskedUrl($url)) {
            $storage = MediaUrl::storagePathFromUrl($url);
            if ($storage) {
                return MediaUrl::fromStoragePath($storage);
            }
        }
        if (str_starts_with($url, '/media/')) {
            return rtrim((string) config('app.url'), '/').$url;
        }
        if (str_starts_with($url, 'storage/')) {
            return DesignAssetUrl::fromPath($url);
        }
        if (str_starts_with($url, '/')) {
            if (app()->bound('request') && request()->getHost() !== '') {
                return rtrim(request()->getSchemeAndHttpHost().request()->getBaseUrl(), '/').$url;
            }

            return rtrim((string) config('app.url'), '/').$url;
        }

        return $url;
    }
}
