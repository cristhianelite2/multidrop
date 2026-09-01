<?php

namespace App\Services\Storage;

use App\Models\PlatformSetting;
use App\Models\Store;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class R2StorageManager
{
    public function enabled(): bool
    {
        return filter_var(config('r2.enabled'), FILTER_VALIDATE_BOOLEAN) && $this->configured();
    }

    public function configured(): bool
    {
        return trim((string) config('r2.bucket')) !== ''
            && trim((string) config('r2.access_key_id')) !== ''
            && trim((string) config('r2.secret_access_key')) !== ''
            && trim((string) config('r2.endpoint')) !== '';
    }

    public function disk(): Filesystem
    {
        $this->syncDiskConfig();

        return Storage::disk('r2');
    }

    public function syncDiskConfig(bool $throw = false): void
    {
        $this->ensureEndpoint();

        config([
            'filesystems.disks.r2' => [
                'driver' => 's3',
                'key' => config('r2.access_key_id'),
                'secret' => config('r2.secret_access_key'),
                'region' => config('r2.region', 'auto'),
                'bucket' => config('r2.bucket'),
                'endpoint' => config('r2.endpoint'),
                'url' => env('R2_URL'),
                'use_path_style_endpoint' => true,
                'throw' => $throw,
            ],
        ]);

        Storage::forgetDisk('r2');
    }

    public function ensureEndpoint(): void
    {
        $endpoint = trim((string) config('r2.endpoint'));
        if ($endpoint !== '') {
            return;
        }
        $accountId = trim((string) config('r2.account_id'));
        if ($accountId === '') {
            return;
        }
        config(['r2.endpoint' => 'https://'.$accountId.'.r2.cloudflarestorage.com']);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['success' => false, 'message' => 'Completa bucket, access key, secret y endpoint (o Account ID para derivarlo).'];
        }

        try {
            $this->syncDiskConfig(true);
            $probe = 'multidrop/_probe/'.Str::lower(Str::random(8)).'.txt';
            $disk = Storage::disk('r2');
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return ['success' => true, 'message' => 'R2 OK · bucket «'.config('r2.bucket').'» accesible.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->formatR2Error($e)];
        } finally {
            $this->syncDiskConfig(false);
        }
    }

    protected function formatR2Error(\Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if (str_contains($msg, 'InvalidAccessKeyId')) {
            return 'Access Key ID inválida.';
        }
        if (str_contains($msg, 'SignatureDoesNotMatch')) {
            return 'Secret Access Key incorrecta.';
        }
        if (str_contains($msg, 'NoSuchBucket')) {
            return 'El bucket no existe o el nombre no coincide.';
        }
        if (str_contains($msg, 'AccessDenied') || str_contains($msg, '403')) {
            return 'Acceso denegado: el token necesita permiso Object Read & Write sobre el bucket.';
        }

        return $msg !== '' ? $msg : 'No se pudo conectar con R2.';
    }

    public function storePrefix(int $storeId): string
    {
        return 'stores/'.$storeId;
    }

    public function productPrefix(int $storeId, int $productId): string
    {
        return $this->storePrefix($storeId).'/products/'.$productId;
    }

    /**
     * @return array{bytes: int, files: int, images: int, videos: int}
     */
    public function measureStoreUsage(int $storeId): array
    {
        $prefix = $this->storePrefix($storeId).'/';
        $bytes = 0;
        $files = 0;
        $images = 0;
        $videos = 0;

        try {
            foreach ($this->disk()->allFiles($prefix) as $path) {
                $size = (int) $this->disk()->size($path);
                $bytes += $size;
                $files++;
                if (str_contains($path, '/images/')) {
                    $images++;
                } elseif (str_contains($path, '/videos/')) {
                    $videos++;
                }
            }
        } catch (\Throwable) {
            //
        }

        return [
            'bytes' => $bytes,
            'files' => $files,
            'images' => $images,
            'videos' => $videos,
        ];
    }

    public function refreshStoreStats(Store $store): void
    {
        if (! $this->enabled()) {
            return;
        }

        $usage = $this->measureStoreUsage((int) $store->id);
        $settings = is_array($store->settings) ? $store->settings : [];
        $settings['storage'] = array_merge(is_array($settings['storage'] ?? null) ? $settings['storage'] : [], [
            'r2_bytes' => $usage['bytes'],
            'r2_files' => $usage['files'],
            'r2_images' => $usage['images'],
            'r2_videos' => $usage['videos'],
            'r2_synced_at' => now()->toIso8601String(),
        ]);
        $store->settings = $settings;
        $store->save();
    }

    public function incrementStoreStats(Store $store, int $bytes, string $type = 'image'): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $storage = is_array($settings['storage'] ?? null) ? $settings['storage'] : [];
        $storage['r2_bytes'] = (int) ($storage['r2_bytes'] ?? 0) + max(0, $bytes);
        $storage['r2_files'] = (int) ($storage['r2_files'] ?? 0) + 1;
        if ($type === 'video') {
            $storage['r2_videos'] = (int) ($storage['r2_videos'] ?? 0) + 1;
        } else {
            $storage['r2_images'] = (int) ($storage['r2_images'] ?? 0) + 1;
        }
        $storage['r2_synced_at'] = now()->toIso8601String();
        $settings['storage'] = $storage;
        $store->settings = $settings;
        $store->save();
    }

    public function applyFromPlatformSettings(): void
    {
        $enabled = filter_var(PlatformSetting::getValue('storage.r2.enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $accountId = trim((string) (PlatformSetting::getValue('storage.r2.account_id') ?: PlatformSetting::getValue('cloudflare.account_id') ?: config('r2.account_id')));
        $accessKey = trim((string) (PlatformSetting::getValue('storage.r2.access_key_id') ?: config('r2.access_key_id')));
        $secret = trim((string) (PlatformSetting::getValue('storage.r2.secret_access_key') ?: config('r2.secret_access_key')));
        $bucket = trim((string) (PlatformSetting::getValue('storage.r2.bucket') ?: config('r2.bucket')));
        $endpoint = trim((string) (PlatformSetting::getValue('storage.r2.endpoint') ?: config('r2.endpoint')));
        if ($endpoint === '' && $accountId !== '') {
            $endpoint = 'https://'.$accountId.'.r2.cloudflarestorage.com';
        }

        config([
            'r2.enabled' => $enabled,
            'r2.account_id' => $accountId,
            'r2.access_key_id' => $accessKey,
            'r2.secret_access_key' => $secret,
            'r2.bucket' => $bucket,
            'r2.endpoint' => $endpoint,
        ]);

        $this->syncDiskConfig();
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 2).' MB';
        }

        return round($bytes / 1073741824, 2).' GB';
    }
}
