<?php

namespace App\Domain\Ads\Creatify;

use Illuminate\Support\Facades\Http;

class CreatifyClient
{
    public function configured(): bool
    {
        return $this->apiId() !== '' && $this->apiKey() !== '';
    }

    public function apiId(): string
    {
        return trim((string) config('multidrop.marketing.creatify.api_id', ''));
    }

    public function apiKey(): string
    {
        return trim((string) config('multidrop.marketing.creatify.api_key', ''));
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('multidrop.marketing.creatify.base_url', 'https://api.creatify.ai'), '/');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function connectionStatus(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Faltan CREATIFY_API_ID / CREATIFY_API_KEY en .env'];
        }

        return ['ok' => true, 'message' => 'Creatify conectado'];
    }

    /**
     * @return array<string, mixed>
     */
    public function createLink(string $url, ?string $name = null): array
    {
        $res = $this->http()->post($this->baseUrl().'/api/links/', [
            'url' => $url,
            'title' => $name ?: 'Multidrop',
        ]);
        $this->throwIfFailed($res, 'No se pudo registrar el enlace en Creatify.');

        return $res->json() ?: [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createLinkWithParams(array $payload): array
    {
        $body = array_filter([
            'url' => $payload['url'] ?? null,
            'title' => $payload['title'] ?? null,
            'description' => $payload['description'] ?? null,
            'image_urls' => $payload['image_urls'] ?? null,
            'video_urls' => $payload['video_urls'] ?? null,
            'reviews' => $payload['reviews'] ?? null,
            'logo_url' => $payload['logo_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        $endpoints = [
            $this->baseUrl().'/api/link_with_params/',
            $this->baseUrl().'/api/links/link_with_params/',
        ];
        $res = null;
        foreach ($endpoints as $endpoint) {
            $res = $this->http()->post($endpoint, $body);
            if ($res->successful()) {
                return $res->json() ?: [];
            }
            if ($res->status() !== 404) {
                break;
            }
        }
        if ($res && $res->status() === 404) {
            $res = $this->http()->post($this->baseUrl().'/api/links/', [
                'url' => (string) ($payload['url'] ?? ''),
                'title' => (string) ($payload['title'] ?? 'Multidrop'),
            ]);
            $json = $res->json() ?: [];
            $linkId = (string) ($json['id'] ?? '');
            if ($res->successful() && $linkId !== '') {
                return $this->updateLink($linkId, $payload);
            }
        }
        $this->throwIfFailed($res, 'No se pudo crear el enlace en Creatify con media del producto.');

        return $res->json() ?: [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateLink(string $linkId, array $payload): array
    {
        $body = array_filter([
            'url' => $payload['url'] ?? null,
            'title' => $payload['title'] ?? null,
            'description' => $payload['description'] ?? null,
            'image_urls' => $payload['image_urls'] ?? null,
            'video_urls' => $payload['video_urls'] ?? null,
            'reviews' => $payload['reviews'] ?? null,
            'logo_url' => $payload['logo_url'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        $res = $this->http()->put($this->baseUrl().'/api/links/'.$linkId.'/', $body);
        $this->throwIfFailed($res, 'No se pudo actualizar el enlace en Creatify.');

        return $res->json() ?: [];
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array<string, mixed>
     */
    public function createLinkToVideo(string $linkId, array $opts): array
    {
        $payload = [
            'link' => $linkId,
            'target_platform' => $opts['target_platform'] ?? 'Tiktok',
            'aspect_ratio' => $opts['aspect_ratio'] ?? '9x16',
            'video_length' => (int) ($opts['video_length'] ?? 15),
            'language' => $opts['language'] ?? 'es',
        ];
        if (! empty($opts['target_audience'])) {
            $payload['target_audience'] = $opts['target_audience'];
        }
        if (! empty($opts['override_script'])) {
            $payload['override_script'] = $opts['override_script'];
        }
        if (! empty($opts['visual_style'])) {
            $payload['visual_style'] = $opts['visual_style'];
        }
        if (! empty($opts['script_style'])) {
            $payload['script_style'] = $opts['script_style'];
        }
        $res = $this->http()->post($this->baseUrl().'/api/link_to_videos/', $payload);
        $this->throwIfFailed($res, 'No se pudo pedir el video a Creatify.');

        return $res->json() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getLinkToVideo(string $jobId): array
    {
        $res = $this->http()->get($this->baseUrl().'/api/link_to_videos/'.$jobId.'/');
        $this->throwIfFailed($res, 'No se pudo consultar el job de Creatify.');

        return $res->json() ?: [];
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(60)
            ->acceptJson()
            ->withHeaders([
                'X-API-ID' => $this->apiId(),
                'X-API-KEY' => $this->apiKey(),
            ]);
    }

    protected function throwIfFailed(\Illuminate\Http\Client\Response $res, string $fallback): void
    {
        if ($res->successful()) {
            return;
        }
        $msg = $res->json('detail') ?? $res->json('message') ?? $res->body();
        $text = is_string($msg) && trim($msg) !== '' ? trim($msg) : $fallback;

        throw new \RuntimeException($text.' (HTTP '.$res->status().')');
    }
}
