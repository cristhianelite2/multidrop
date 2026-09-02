<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\Storage\MediaUrl;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ProductMediaDownloadService
{
    /**
     * @return list<string>
     */
    public function imageUrls(Product $product): array
    {
        $urls = [];
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        foreach ($verified['images'] ?? [] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        $main = trim((string) ($product->image_url ?? ''));
        if ($main !== '' && ! in_array($main, $urls, true)) {
            array_unshift($urls, $main);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    public function videos(Product $product): array
    {
        $rows = [];
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        foreach ($verified['videos'] ?? [] as $video) {
            if (! is_array($video)) {
                continue;
            }
            $url = trim((string) ($video['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = [
                'url' => $url,
                'name' => trim((string) ($video['name'] ?? '')) ?: 'video',
            ];
        }

        return $rows;
    }

    public function ownsUrl(Product $product, string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if ($url === trim((string) ($product->image_url ?? ''))) {
            return true;
        }

        return in_array($url, $this->imageUrls($product), true)
            || in_array($url, array_column($this->videos($product), 'url'), true);
    }

    public function downloadFilename(string $url, string $fallbackPrefix, int $index = 1): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $base = basename($path);
        if ($base !== '' && $base !== '/' && str_contains($base, '.')) {
            return preg_replace('/[^a-zA-Z0-9._-]/', '-', $base) ?: ($fallbackPrefix.'-'.$index);
        }

        return $fallbackPrefix.'-'.$index;
    }

    /**
     * @return array{body: string, mime: string, filename: string}|null
     */
    public function fetchBytes(string $url, string $fallbackPrefix, int $index = 1): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        foreach ($this->urlCandidates($url) as $candidate) {
            $file = $this->fetchBytesFromCandidate($candidate, $fallbackPrefix, $index);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function urlCandidates(string $url): array
    {
        $candidates = [];
        $push = function (string $candidate) use (&$candidates): void {
            $candidate = trim($candidate);
            if ($candidate !== '' && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        };

        $push($url);
        $withoutQuery = strtok($url, '?') ?: '';
        $push($withoutQuery);
        $withoutFragment = strtok($withoutQuery, '#') ?: '';
        $push($withoutFragment);

        return $candidates;
    }

    /**
     * @return array{body: string, mime: string, filename: string}|null
     */
    protected function fetchBytesFromCandidate(string $url, string $fallbackPrefix, int $index): ?array
    {
        $storagePath = MediaUrl::storagePathFromUrl($url);
        if ($storagePath && app(\App\Services\Storage\R2StorageManager::class)->enabled()) {
            $disk = app(\App\Services\Storage\R2StorageManager::class)->disk();
            if ($disk->exists($storagePath)) {
                $body = $disk->get($storagePath);

                return is_string($body) && $body !== '' ? [
                    'body' => $body,
                    'mime' => $this->mimeFromPath($storagePath),
                    'filename' => $this->downloadFilename($url, $fallbackPrefix, $index),
                ] : null;
            }
        }

        $local = $this->localPathFromUrl($url);
        if ($local && is_readable($local)) {
            $body = file_get_contents($local);

            return is_string($body) && $body !== '' ? [
                'body' => $body,
                'mime' => mime_content_type($local) ?: $this->mimeFromPath($local),
                'filename' => basename($local),
            ] : null;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'User-Agent' => 'Multidrop/1.0',
                    'Referer' => 'https://www.aliexpress.com/',
                    'Accept' => '*/*',
                ])
                ->get($url);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->body();
            if (! is_string($body) || $body === '') {
                return null;
            }

            return [
                'body' => $body,
                'mime' => (string) ($response->header('Content-Type') ?: $this->mimeFromPath($url)),
                'filename' => $this->downloadFilename($url, $fallbackPrefix, $index),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function streamSingle(string $url, string $fallbackPrefix): StreamedResponse|\Illuminate\Http\Response
    {
        $file = $this->fetchBytes($url, $fallbackPrefix);
        if ($file === null) {
            abort(404, 'No se pudo descargar el archivo.');
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Content-Length' => (string) strlen($file['body']),
        ]);
    }

    /**
     * @param  'images'|'videos'|'all'  $kind
     */
    public function buildZip(Product $product, string $kind = 'all'): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mdzip_');
        if ($tmp === false) {
            return null;
        }
        @unlink($tmp);
        $zipPath = $tmp.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $added = 0;
        if ($kind === 'images' || $kind === 'all') {
            foreach ($this->imageUrls($product) as $i => $url) {
                $file = $this->fetchBytes($url, 'image', $i + 1);
                if ($file) {
                    $zip->addFromString('images/'.$file['filename'], $file['body']);
                    $added++;
                }
            }
        }
        if ($kind === 'videos' || $kind === 'all') {
            foreach ($this->videos($product) as $i => $video) {
                $file = $this->fetchBytes($video['url'], 'video', $i + 1);
                if ($file) {
                    $name = preg_replace('/[^a-zA-Z0-9._-]/', '-', $video['name']) ?: ('video-'.($i + 1));
                    if (! str_contains($file['filename'], '.')) {
                        $name .= '.mp4';
                    } else {
                        $name = $file['filename'];
                    }
                    $zip->addFromString('videos/'.$name, $file['body']);
                    $added++;
                }
            }
        }

        $zip->close();
        if ($added === 0) {
            @unlink($zipPath);

            return null;
        }

        return $zipPath;
    }

    protected function localPathFromUrl(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $pos = strpos($path, '/storage/');
        if ($pos !== false) {
            $relative = ltrim(substr($path, $pos + strlen('/storage/')), '/');

            return $relative !== '' ? storage_path('app/public/'.$relative) : null;
        }

        $storagePath = MediaUrl::storagePathFromUrl($url);
        if ($storagePath) {
            $local = storage_path('app/public/'.$storagePath);
            if (is_readable($local)) {
                return $local;
            }
        }

        return null;
    }

    protected function mimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            default => 'application/octet-stream',
        };
    }
}
