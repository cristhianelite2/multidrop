<?php

namespace App\Services\Marketing;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemotionStudioClient
{
    private ?string $apiKey = null;

    public function __construct()
    {
    }

    public function withStore(Store $store): self
    {
        $clone = clone $this;
        $storeSettings = is_array($store->settings) ? $store->settings : [];
        $storeKey = (string) data_get($storeSettings, 'marketing.remotion_studio_api_key', '');
        $platformKey = (string) \App\Models\PlatformSetting::getValue('marketing.remotion_studio_api_key', '');
        $clone->apiKey = $storeKey !== '' ? $storeKey : $platformKey;
        return $clone;
    }

    public function configured(): bool
    {
        return !empty($this->apiKey);
    }

    public function baseUrl(): string
    {
        $db = \App\Models\PlatformSetting::getValue('multidrop.marketing.remotion_studio.base_url');

        return rtrim((string) ($db ?: config('multidrop.marketing.remotion_studio.base_url', env('REMOTION_STUDIO_URL', 'https://remotion.ceballosleon.com'))), '/');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->accept('application/json')
            ->withHeader('Authorization', 'Bearer ' . ($this->apiKey ?? ''));
    }

    public function musicCatalog(): array
    {
        $url = $this->baseUrl() . '/api/v1/music';
        try {
            $res = $this->http()->get($url);
            if (!$res->successful()) {
                return ['ok' => false, 'message' => 'HTTP ' . $res->status(), 'tracks' => []];
            }
            $data = $res->json();
            $tracks = is_array($data['body']['data'] ?? null) ? $data['body']['data'] : [];
            return ['ok' => true, 'tracks' => $tracks];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'tracks' => []];
        }
    }

    public function stylesCatalog(): array
    {
        $url = $this->baseUrl() . '/api/v1/videos/styles';
        try {
            $res = $this->http()->get($url);
            if (!$res->successful()) {
                return ['ok' => false, 'message' => 'HTTP ' . $res->status(), 'styles' => []];
            }
            $data = $res->json();
            $styles = is_array($data['body'] ?? null) ? $data['body'] : [];
            return ['ok' => true, 'styles' => $styles];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'styles' => []];
        }
    }

    public function generateFromUrl(
        string $productUrl,
        ?string $styleId,
        ?string $musicId,
        ?float $musicVolume,
        ?int $videoLength,
        ?string $productName = null,
        ?string $productImage = null,
        ?float $productPrice = null,
        ?string $productDescription = null
    ): array {
        $url = $this->baseUrl() . '/api/v1/videos/from-url';
        $payload = [
            'productUrl' => $productUrl,
            'styleId' => $styleId ?: 'random',
            'musicId' => $musicId ?: 'random',
            'musicVolume' => (float) ($musicVolume ?? 0.3),
            'videoLength' => (int) ($videoLength ?? 21),
        ];
        if ($productName !== null) {
            $payload['productName'] = $productName;
        }
        if ($productImage !== null) {
            $payload['productImage'] = $productImage;
        }
        if ($productPrice !== null) {
            $payload['productPrice'] = (float) $productPrice;
        }
        if ($productDescription !== null) {
            $payload['productDescription'] = $productDescription;
        }
        try {
            $res = $this->http()->post($url, $payload);
            if (!$res->successful()) {
                $body = $res->json();
                return [
                    'ok' => false,
                    'message' => ($body['error']['message'] ?? 'HTTP ' . $res->status()),
                    'http_status' => $res->status(),
                ];
            }
            $data = $res->json();
            $body = $data['body'] ?? $data;
            return [
                'ok' => true,
                'jobId' => $body['jobId'] ?? null,
                'outputUrl' => $body['outputUrl'] ?? null,
                'statusUrl' => $body['statusUrl'] ?? null,
                'downloadUrl' => $body['downloadUrl'] ?? null,
                'styleId' => $body['styleId'] ?? null,
                'fileName' => $body['fileName'] ?? null,
                'contentType' => $body['contentType'] ?? 'video/mp4',
                'musicVolume' => $body['musicVolume'] ?? $musicVolume ?? 0.3,
                'videoLength' => $body['videoLength'] ?? $videoLength ?? 21,
                'music' => $body['music'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function status(string $jobId): array
    {
        $url = $this->baseUrl() . '/api/v1/videos/' . rawurlencode($jobId) . '/status';
        try {
            $res = $this->http()->get($url);
            if (!$res->successful()) {
                return ['state' => 'failed', 'message' => 'HTTP ' . $res->status()];
            }
            $data = $res->json();
            $body = $data['body'] ?? $data;
            return [
                'ok' => (bool) ($body['ok'] ?? false),
                'state' => (string) ($body['state'] ?? 'unknown'),
                'jobId' => (string) ($body['jobId'] ?? $jobId),
                'message' => (string) ($body['message'] ?? ''),
                'step' => $body['step'] ?? null,
                'steps' => $body['steps'] ?? null,
                'outputUrl' => $body['outputUrl'] ?? null,
                'downloadUrl' => $body['downloadUrl'] ?? null,
                'styleId' => $body['styleId'] ?? null,
                'fileName' => $body['fileName'] ?? null,
                'contentType' => $body['contentType'] ?? 'video/mp4',
                'fileSizeBytes' => $body['fileSizeBytes'] ?? null,
                'durationSeconds' => $body['durationSeconds'] ?? null,
                'music' => $body['music'] ?? null,
                'createdAt' => $body['createdAt'] ?? null,
                'completedAt' => $body['completedAt'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
