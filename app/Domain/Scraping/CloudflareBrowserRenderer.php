<?php

namespace App\Domain\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente opcional de Cloudflare Browser Rendering.
 * @see https://developers.cloudflare.com/browser-rendering/rest-api/content-endpoint/
 */
class CloudflareBrowserRenderer
{
    public function enabled(): bool
    {
        return config('cloudflare.enabled')
            && config('cloudflare.account_id')
            && config('cloudflare.api_token');
    }

    /**
     * Obtiene HTML renderizado de una URL vía Cloudflare.
     */
    public function fetchHtml(string $url): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $accountId = config('cloudflare.account_id');
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/browser-rendering/content";

        try {
            $response = Http::timeout(90)
                ->withToken(config('cloudflare.api_token'))
                ->acceptJson()
                ->post($endpoint, [
                    'url' => $url,
                    'gotoOptions' => [
                        'waitUntil' => 'networkidle2',
                        'timeout' => 45000,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Cloudflare browser rendering failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $json = $response->json();

            return $json['result'] ?? (is_string($json) ? $json : null);
        } catch (\Throwable $e) {
            Log::error('Cloudflare browser rendering exception', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
