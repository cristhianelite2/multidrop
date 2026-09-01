<?php

namespace App\Services\Storage;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductMediaMirrorService
{
    public function __construct(
        protected R2StorageManager $r2,
    ) {}

    public function mirrorProduct(Product $product): Product
    {
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

        return $product->fresh();
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
            'visibility' => 'private',
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
        if ($url === '' || MediaUrl::isMaskedUrl($url)) {
            return $url !== '' ? $url : null;
        }

        if ($this->isLocalStorageUrl($url)) {
            $localPath = $this->localPathFromUrl($url);
            if ($localPath && is_readable($localPath)) {
                $contents = file_get_contents($localPath);
                if (is_string($contents) && $contents !== '') {
                    return $this->putContents($contents, $store, $product, $folder, basename($localPath), $url);
                }
            }
        }

        $maxBytes = $folder === 'videos' ? 104857600 : 10485760;
        try {
            $response = Http::timeout($folder === 'videos' ? 120 : 45)
                ->withHeaders(['User-Agent' => 'Multidrop/1.0 (+https://shop.ceballosleon.com)'])
                ->get($url);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->body();
            if (! is_string($body) || $body === '' || strlen($body) > $maxBytes) {
                return null;
            }

            $ext = $this->guessExtension($url, (string) $response->header('Content-Type'), $folder);

            return $this->putContents($body, $store, $product, $folder, null, $url, $ext);
        } catch (\Throwable) {
            return null;
        }
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
            return MediaUrl::fromStoragePath($storagePath);
        }

        $this->r2->disk()->put($storagePath, $contents, ['visibility' => 'private']);
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
}
