<?php

namespace App\Services\Storage;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductMediaMirrorService
{
    /** @var array{mirrored: int, skipped: int, failed: int, r2: bool} */
    protected array $lastMirrorReport = [
        'mirrored' => 0,
        'skipped' => 0,
        'failed' => 0,
        'r2' => false,
    ];

    public function __construct(
        protected R2StorageManager $r2,
    ) {}

    /**
     * @return array{mirrored: int, skipped: int, failed: int, r2: bool}
     */
    public function lastMirrorReport(): array
    {
        return $this->lastMirrorReport;
    }

    public function mirrorProduct(Product $product, bool $mirrorVideos = true): Product
    {
        $this->lastMirrorReport = [
            'mirrored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'r2' => $this->r2->enabled(),
        ];

        if (! $this->r2->enabled()) {
            return $product;
        }

        $store = $product->store ?: Store::query()->find($product->store_id);
        if (! $store) {
            return $product;
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $changed = false;

        $images = is_array($verified['images'] ?? null) ? $verified['images'] : [];
        $newImages = [];
        foreach ($images as $img) {
            $url = is_string($img) ? trim($img) : '';
            if ($url === '') {
                continue;
            }
            $mirrored = $this->mirrorRemoteUrl($url, $store, $product, 'images');
            $newImages[] = $mirrored ?: $url;
            if ($mirrored && $mirrored !== $url) {
                $changed = true;
            }
        }
        if ($newImages !== []) {
            $verified['images'] = array_values(array_unique($newImages));
        }

        $videos = is_array($verified['videos'] ?? null) ? $verified['videos'] : [];
        $newVideos = [];
        if ($mirrorVideos) {
            foreach ($videos as $video) {
                if (! is_array($video)) {
                    continue;
                }
                $row = $video;
                $url = trim((string) ($video['url'] ?? ''));
                if ($url !== '') {
                    $mirrored = $this->mirrorRemoteUrl($url, $store, $product, 'videos');
                    if ($mirrored && $mirrored !== $url) {
                        $row['url'] = $mirrored;
                        $changed = true;
                    }
                }
                $cover = trim((string) ($video['cover'] ?? ''));
                if ($cover !== '') {
                    $mirroredCover = $this->mirrorRemoteUrl($cover, $store, $product, 'images');
                    if ($mirroredCover && $mirroredCover !== $cover) {
                        $row['cover'] = $mirroredCover;
                        $changed = true;
                    }
                }
                $newVideos[] = $row;
            }
            if ($newVideos !== []) {
                $verified['videos'] = $newVideos;
            }
        }

        $variants = is_array($verified['variants'] ?? null) ? $verified['variants'] : [];
        $newVariants = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $row = $variant;
            $img = trim((string) ($variant['image'] ?? ''));
            if ($img !== '') {
                $mirrored = $this->mirrorRemoteUrl($img, $store, $product, 'images');
                if ($mirrored && $mirrored !== $img) {
                    $row['image'] = $mirrored;
                    $changed = true;
                }
            }
            $newVariants[] = $row;
        }
        if ($newVariants !== []) {
            $verified['variants'] = $newVariants;
        }

        $reviews = is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [];
        $newReviews = [];
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $row = $review;
            $reviewImages = is_array($review['images'] ?? null) ? $review['images'] : [];
            $newReviewImages = [];
            foreach ($reviewImages as $img) {
                $imgUrl = is_string($img) ? trim($img) : '';
                if ($imgUrl === '') {
                    continue;
                }
                $mirrored = $this->mirrorRemoteUrl($imgUrl, $store, $product, 'images');
                $newReviewImages[] = $mirrored ?: $imgUrl;
                if ($mirrored && $mirrored !== $imgUrl) {
                    $changed = true;
                }
            }
            if ($newReviewImages !== []) {
                $row['images'] = array_values(array_unique($newReviewImages));
            }
            $newReviews[] = $row;
        }
        if ($newReviews !== []) {
            $verified['reviews'] = $newReviews;
            $verified['comments'] = $newReviews;
        }

        $descriptionHtml = trim((string) ($verified['description_html'] ?? ''));
        if ($descriptionHtml !== '') {
            $mirroredHtml = $this->mirrorDescriptionHtml($descriptionHtml, $store, $product);
            if ($mirroredHtml !== $descriptionHtml) {
                $verified['description_html'] = $mirroredHtml;
                $changed = true;
            }
        }

        $imageUrl = trim((string) ($product->image_url ?? ''));
        if ($imageUrl !== '') {
            $mirroredMain = $this->mirrorRemoteUrl($imageUrl, $store, $product, 'images');
            if ($mirroredMain && $mirroredMain !== $imageUrl) {
                $product->image_url = mb_substr($mirroredMain, 0, 500);
                $changed = true;
            }
        } elseif (! empty($verified['images'][0])) {
            $product->image_url = mb_substr((string) $verified['images'][0], 0, 500);
            $changed = true;
        }

        if ($changed) {
            $product->verified_data = $verified;
            $product->save();
            $this->r2->refreshStoreStats($store);
        }

        return $product->fresh() ?? $product;
    }

    public function storeUploadedFile(Store $store, Product $product, UploadedFile $file, string $folder): array
    {
        if (! $this->r2->enabled()) {
            $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
            $ext = strtolower($file->getClientOriginalExtension() ?: ($folder === 'videos' ? 'mp4' : 'jpg'));
            $filename = $base.'-'.Str::lower(Str::random(6)).'.'.$ext;
            $path = $file->storeAs('products/'.$store->id.'/'.$product->id.'/'.$folder, $filename, 'public');

            return [
                'path' => $path,
                'url' => \App\Services\Storefront\DesignAssetUrl::fromPath($path),
                'bytes' => (int) $file->getSize(),
            ];
        }

        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $ext = strtolower($file->getClientOriginalExtension() ?: ($folder === 'videos' ? 'mp4' : 'jpg'));
        $filename = $base.'-'.Str::lower(Str::random(6)).'.'.$ext;
        $storagePath = $this->r2->productPrefix((int) $store->id, (int) $product->id).'/'.$folder.'/'.$filename;
        $contents = file_get_contents($file->getRealPath());
        $bytes = is_string($contents) ? strlen($contents) : 0;
        $this->r2->disk()->put($storagePath, $contents, [
            'ContentType' => $file->getMimeType() ?: null,
        ]);
        $this->r2->incrementStoreStats($store, $bytes, $folder === 'videos' ? 'video' : 'image');

        return [
            'path' => $storagePath,
            'url' => MediaUrl::fromStoragePath($storagePath),
            'bytes' => $bytes,
        ];
    }

    protected function mirrorRemoteUrl(string $url, Store $store, Product $product, string $folder): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (MediaUrl::isMaskedUrl($url)) {
            $this->lastMirrorReport['skipped']++;

            return $url;
        }

        if ($folder === 'videos' && str_contains(strtolower($url), '.m3u8')) {
            $this->lastMirrorReport['skipped']++;

            return $url;
        }

        if ($this->isLocalStorageUrl($url)) {
            $localPath = $this->localPathFromUrl($url);
            if ($localPath && is_readable($localPath)) {
                $contents = file_get_contents($localPath);
                if (is_string($contents) && $contents !== '') {
                    $mirrored = $this->putContents($contents, $store, $product, $folder, basename($localPath), $url);
                    if ($mirrored) {
                        $this->lastMirrorReport['mirrored']++;
                    } else {
                        $this->lastMirrorReport['failed']++;
                    }

                    return $mirrored;
                }
            }
        }

        $maxBytes = $folder === 'videos' ? 104857600 : 10485760;
        try {
            $response = Http::timeout($folder === 'videos' ? 120 : 45)
                ->withHeaders($this->downloadHeaders($url))
                ->get($url);
            if (! $response->successful()) {
                $this->lastMirrorReport['failed']++;

                return null;
            }
            $body = $response->body();
            if (! is_string($body) || $body === '' || strlen($body) > $maxBytes) {
                $this->lastMirrorReport['failed']++;

                return null;
            }

            $ext = $this->guessExtension($url, (string) $response->header('Content-Type'), $folder);
            $mirrored = $this->putContents($body, $store, $product, $folder, null, $url, $ext);
            if ($mirrored) {
                $this->lastMirrorReport['mirrored']++;
            } else {
                $this->lastMirrorReport['failed']++;
            }

            return $mirrored;
        } catch (\Throwable) {
            $this->lastMirrorReport['failed']++;

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function downloadHeaders(string $url): array
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (compatible; Multidrop/1.0; +https://shop.ceballosleon.com)',
            'Accept' => '*/*',
        ];

        if (preg_match('#(alicdn\.com|aliexpress\.com|aliexpress\.us)#i', $url)) {
            $headers['Referer'] = 'https://www.aliexpress.com/';
        } elseif (preg_match('#(cjdropshipping\.com|cj\.com)#i', $url)) {
            $headers['Referer'] = 'https://www.cjdropshipping.com/';
        }

        return $headers;
    }

    protected function mirrorDescriptionHtml(string $html, Store $store, Product $product): string
    {
        return (string) preg_replace_callback(
            '/\bsrc=(["\'])([^"\']+)\1/i',
            function (array $matches) use ($store, $product) {
                $quote = $matches[1];
                $src = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5));
                if ($src === '' || str_starts_with(strtolower($src), 'data:')) {
                    return $matches[0];
                }
                $mirrored = $this->mirrorRemoteUrl($src, $store, $product, 'images');

                return $mirrored ? 'src='.$quote.$mirrored.$quote : $matches[0];
            },
            $html
        );
    }

  /**
   * @return non-empty-string|null
   */
    protected function putContents(
        string $contents,
        Store $store,
        Product $product,
        string $folder,
        ?string $filename = null,
        ?string $sourceUrl = null,
        ?string $ext = null
    ): ?string {
        $ext = $ext ?: ($folder === 'videos' ? 'mp4' : 'jpg');
        $hash = substr(md5(($sourceUrl ?: $filename ?: $contents).$folder), 0, 12);
        $filename = $filename ? basename($filename) : ($hash.'.'.$ext);
        if (! str_contains($filename, '.')) {
            $filename .= '.'.$ext;
        }
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?: ($hash.'.'.$ext);

        $storagePath = $this->r2->productPrefix((int) $store->id, (int) $product->id).'/'.$folder.'/'.$filename;
        if ($this->r2->disk()->exists($storagePath)) {
            $this->lastMirrorReport['skipped']++;

            return MediaUrl::fromStoragePath($storagePath);
        }

        $this->r2->disk()->put($storagePath, $contents);
        $this->r2->incrementStoreStats($store, strlen($contents), $folder === 'videos' ? 'video' : 'image');

        return MediaUrl::fromStoragePath($storagePath);
    }

    protected function isLocalStorageUrl(string $url): bool
    {
        return str_contains($url, '/storage/');
    }

    protected function localPathFromUrl(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $pos = strpos($path, '/storage/');
        if ($pos === false) {
            return null;
        }
        $relative = ltrim(substr($path, $pos + strlen('/storage/')), '/');

        return $relative !== '' ? storage_path('app/public/'.$relative) : null;
    }

    protected function guessExtension(string $url, string $contentType, string $folder): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
        ];
        $ct = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return $map[$ct] ?? ($folder === 'videos' ? 'mp4' : 'jpg');
    }

    /**
     * @return list<string>
     */
    public function collectProductMediaUrls(Product $product): array
    {
        $urls = [];
        $push = function (?string $url) use (&$urls): void {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        };

        $push($product->image_url);
        $verified = is_array($product->verified_data) ? $product->verified_data : [];

        foreach (is_array($verified['images'] ?? null) ? $verified['images'] : [] as $img) {
            $push(is_string($img) ? $img : null);
        }

        foreach (is_array($verified['videos'] ?? null) ? $verified['videos'] : [] as $video) {
            if (! is_array($video)) {
                continue;
            }
            $push($video['url'] ?? null);
            $push($video['cover'] ?? null);
        }

        foreach (is_array($verified['variants'] ?? null) ? $verified['variants'] : [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $push($variant['image'] ?? null);
        }

        foreach (is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [] as $review) {
            if (! is_array($review)) {
                continue;
            }
            foreach (is_array($review['images'] ?? null) ? $review['images'] : [] as $img) {
                $push(is_string($img) ? $img : null);
            }
        }

        $html = trim((string) ($verified['description_html'] ?? ''));
        if ($html !== '' && preg_match_all('/\bsrc=(["\'])([^"\']+)\1/i', $html, $matches)) {
            foreach ($matches[2] as $src) {
                $push(html_entity_decode((string) $src, ENT_QUOTES | ENT_HTML5));
            }
        }

        return array_values(array_unique($urls));
    }

    public function normalizeMediaUrl(string $url): string
    {
        return strtolower(trim($url));
    }

    public function isUrlReferencedByProduct(Product $product, string $url): bool
    {
        $needle = $this->normalizeMediaUrl($url);
        if ($needle === '') {
            return false;
        }

        foreach ($this->collectProductMediaUrls($product) as $candidate) {
            if ($this->normalizeMediaUrl($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{success: bool, r2_deleted?: bool, storage_deleted?: bool, error?: string}
     */
    public function detachMediaUrl(Product $product, string $url, string $kind = 'image'): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['success' => false, 'error' => 'URL vacía'];
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $changed = false;

        if ($kind === 'video') {
            $videos = [];
            foreach (is_array($verified['videos'] ?? null) ? $verified['videos'] : [] as $video) {
                if (! is_array($video)) {
                    continue;
                }
                if ($this->normalizeMediaUrl((string) ($video['url'] ?? '')) === $this->normalizeMediaUrl($url)) {
                    $changed = true;
                    continue;
                }
                if ($this->normalizeMediaUrl((string) ($video['cover'] ?? '')) === $this->normalizeMediaUrl($url)) {
                    $video['cover'] = null;
                    $changed = true;
                }
                $videos[] = $video;
            }
            $verified['videos'] = $videos;
        } else {
            $images = [];
            foreach (is_array($verified['images'] ?? null) ? $verified['images'] : [] as $img) {
                if ($this->normalizeMediaUrl((string) $img) === $this->normalizeMediaUrl($url)) {
                    $changed = true;
                    continue;
                }
                $images[] = $img;
            }
            $verified['images'] = array_values($images);
        }

        foreach (['reviews', 'comments'] as $reviewKey) {
            $rows = is_array($verified[$reviewKey] ?? null) ? $verified[$reviewKey] : [];
            $newRows = [];
            foreach ($rows as $review) {
                if (! is_array($review)) {
                    continue;
                }
                $row = $review;
                $imgs = [];
                foreach (is_array($review['images'] ?? null) ? $review['images'] : [] as $img) {
                    if ($this->normalizeMediaUrl((string) $img) === $this->normalizeMediaUrl($url)) {
                        $changed = true;
                        continue;
                    }
                    $imgs[] = $img;
                }
                if ($imgs !== ($review['images'] ?? [])) {
                    $row['images'] = $imgs;
                }
                $newRows[] = $row;
            }
            if ($newRows !== $rows) {
                $verified[$reviewKey] = $newRows;
            }
        }

        $variants = is_array($verified['variants'] ?? null) ? $verified['variants'] : [];
        $newVariants = [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $row = $variant;
            if ($this->normalizeMediaUrl((string) ($variant['image'] ?? '')) === $this->normalizeMediaUrl($url)) {
                $row['image'] = null;
                $changed = true;
            }
            $newVariants[] = $row;
        }
        if ($newVariants !== $variants) {
            $verified['variants'] = $newVariants;
        }

        if ($this->normalizeMediaUrl((string) ($product->image_url ?? '')) === $this->normalizeMediaUrl($url)) {
            $product->image_url = $verified['images'][0] ?? null;
            $changed = true;
        }

        if ($changed) {
            $product->verified_data = $verified;
            $product->save();
        }

        $product = $product->fresh() ?? $product;
        $storageDeleted = false;
        $r2Deleted = false;
        if (! $this->isUrlReferencedByProduct($product, $url)) {
            $storageDeleted = $this->deleteStoredMediaFile($product, $url);
            $r2Deleted = $storageDeleted && MediaUrl::isMaskedUrl($url);
        }

        return [
            'success' => true,
            'r2_deleted' => $r2Deleted,
            'storage_deleted' => $storageDeleted,
            'image_url' => $product->image_url,
            'images' => array_values(is_array($product->verified_data['images'] ?? null) ? $product->verified_data['images'] : []),
        ];
    }

  /**
   * @return list<string>
   */
    public function purgeDetachedMedia(Product $product, array $beforeUrls, array $afterUrls): array
    {
        $before = [];
        foreach ($beforeUrls as $url) {
            $before[$this->normalizeMediaUrl((string) $url)] = (string) $url;
        }
        $after = [];
        foreach ($afterUrls as $url) {
            $after[$this->normalizeMediaUrl((string) $url)] = true;
        }

        $deleted = [];
        foreach ($before as $key => $url) {
            if (isset($after[$key])) {
                continue;
            }
            if ($this->deleteStoredMediaFile($product, $url)) {
                $deleted[] = $url;
            }
        }

        return $deleted;
    }

    public function deleteStoredMediaFile(Product $product, string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (MediaUrl::isMaskedUrl($url)) {
            if (! $this->r2->enabled()) {
                return false;
            }

            $storagePath = MediaUrl::storagePathFromUrl($url);
            if ($storagePath === null) {
                return false;
            }

            $expectedPrefix = $this->r2->productPrefix((int) $product->store_id, (int) $product->id).'/';
            if (! str_starts_with($storagePath, $expectedPrefix)) {
                return false;
            }

            if (! $this->r2->disk()->exists($storagePath)) {
                return false;
            }

            $deleted = $this->r2->disk()->delete($storagePath);
            if ($deleted) {
                $store = $product->store ?: Store::query()->find($product->store_id);
                if ($store) {
                    $this->r2->refreshStoreStats($store);
                }
            }

            return (bool) $deleted;
        }

        if ($this->isLocalStorageUrl($url)) {
            $localPath = $this->localPathFromUrl($url);
            if ($localPath && is_file($localPath)) {
                return @unlink($localPath);
            }
        }

        return false;
    }
}
