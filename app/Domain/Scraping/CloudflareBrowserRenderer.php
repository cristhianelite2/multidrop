<?php

namespace App\Domain\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Browser Run / Rendering (Chromium remoto).
 *
 * El REST usa valores Puppeteer en gotoOptions.waitUntil:
 * load | domcontentloaded | networkidle0 | networkidle2
 * (networkidle de Playwright provoca HTTP 400 Invalid input).
 *
 * @see https://developers.cloudflare.com/browser-run/quick-actions/content-endpoint/
 */
class CloudflareBrowserRenderer
{
    public const WAIT_UNTIL = ['load', 'domcontentloaded', 'networkidle0', 'networkidle2'];

    public function enabled(): bool
    {
        return (bool) config('cloudflare.enabled')
            && trim((string) config('cloudflare.account_id')) !== ''
            && trim((string) config('cloudflare.api_token')) !== '';
    }

    /**
     * HTML renderizado. Null si está apagado o falla.
     */
    public function fetchHtml(string $url, array $options = []): ?string
    {
        $out = $this->render($url, $options);

        return ($out['success'] ?? false) ? ($out['html'] ?? null) : null;
    }

    /**
     * @param  array{waitUntil?: string, timeout_ms?: int, userAgent?: string, headers?: array<string, string>, rejectResourceTypes?: list<string>, http_timeout?: int}  $options
     * @return array{success: bool, html?: string, error?: string, bytes?: int, status?: int}
     */
    public function render(string $url, array $options = []): array
    {
        if (! $this->enabled()) {
            return ['success' => false, 'error' => 'Browser Rendering no está activado o faltan Account ID / API Token.'];
        }

        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            return ['success' => false, 'error' => 'URL inválida'];
        }

        $payload = $this->buildPayload($url, $options);
        $out = $this->post($url, $payload, (int) ($options['http_timeout'] ?? config('cloudflare.timeout', 90)));

        if (! ($out['success'] ?? false) && (int) ($out['status'] ?? 0) === 400) {
            Log::info('Cloudflare browser rendering retry with minimal payload', ['url' => $url, 'error' => $out['error'] ?? '']);
            $out = $this->post($url, [
                'url' => $url,
                'gotoOptions' => [
                    'waitUntil' => 'networkidle2',
                    'timeout' => 30000,
                ],
                'waitForSelector' => [
                    'selector' => 'h1, meta[property="og:title"]',
                    'timeout' => 20000,
                ],
            ], (int) ($options['http_timeout'] ?? config('cloudflare.timeout', 90)));
        }

        return $out;
    }

    /**
     * Prueba corta (example.com o la URL que pases).
     *
     * @return array{success: bool, message: string}
     */
    public function test(?string $url = null): array
    {
        $target = trim((string) ($url ?: 'https://example.com/'));
        if (! preg_match('#^https?://#i', $target)) {
            $target = 'https://example.com/';
        }

        $out = $this->render($target, [
            'waitUntil' => 'domcontentloaded',
            'timeout_ms' => 20000,
        ]);

        if (! ($out['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $out['error'] ?? 'Browser Rendering falló',
            ];
        }

        $kb = round((($out['bytes'] ?? 0) / 1024), 1);

        return [
            'success' => true,
            'message' => 'Browser Rendering OK · '.$kb.' KB desde '.$target,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildPayload(string $url, array $options): array
    {
        $waitUntil = $this->normalizeWaitUntil(
            (string) ($options['waitUntil'] ?? config('cloudflare.wait_until', 'networkidle2'))
        );
        $gotoTimeout = min(60000, max(5000, (int) ($options['timeout_ms'] ?? config('cloudflare.goto_timeout_ms', 30000))));
        $userAgent = (string) ($options['userAgent'] ?? config('cloudflare.user_agent', ''));

        $payload = [
            'url' => $url,
            'bestAttempt' => true,
            'gotoOptions' => [
                'waitUntil' => $waitUntil,
                'timeout' => $gotoTimeout,
            ],
        ];
        if ($userAgent !== '') {
            $payload['userAgent'] = $userAgent;
        }
        if (! empty($options['headers']) && is_array($options['headers'])) {
            $payload['setExtraHTTPHeaders'] = $options['headers'];
        }
        if (! empty($options['rejectResourceTypes']) && is_array($options['rejectResourceTypes'])) {
            $payload['rejectResourceTypes'] = array_values($options['rejectResourceTypes']);
        }
        if (array_key_exists('bestAttempt', $options)) {
            $payload['bestAttempt'] = (bool) $options['bestAttempt'];
        }
        if (! empty($options['waitForSelector'])) {
            $sel = $options['waitForSelector'];
            $payload['waitForSelector'] = is_array($sel)
                ? $sel
                : ['selector' => (string) $sel, 'timeout' => 25000];
        }
        if (isset($options['waitForTimeout'])) {
            $payload['waitForTimeout'] = (int) $options['waitForTimeout'];
        }
        if (! empty($options['viewport']) && is_array($options['viewport'])) {
            $payload['viewport'] = $options['viewport'];
        }

        return $payload;
    }

    protected function normalizeWaitUntil(string $value): string
    {
        $value = strtolower(trim($value));
        $map = [
            'networkidle' => 'networkidle2',
            'networkidle0' => 'networkidle0',
            'networkidle2' => 'networkidle2',
            'domcontentloaded' => 'domcontentloaded',
            'load' => 'load',
            'commit' => 'domcontentloaded',
        ];

        return $map[$value] ?? 'networkidle2';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, html?: string, error?: string, bytes?: int, status?: int}
     */
    protected function post(string $url, array $payload, int $httpTimeout): array
    {
        $accountId = trim((string) config('cloudflare.account_id'));
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/browser-rendering/content";

        try {
            $response = Http::timeout(max(15, $httpTimeout))
                ->withToken((string) config('cloudflare.api_token'))
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::error('Cloudflare browser rendering exception', ['error' => $e->getMessage(), 'url' => $url]);

            return ['success' => false, 'error' => 'No se pudo contactar Cloudflare: '.$e->getMessage()];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [
                'success' => false,
                'error' => 'Respuesta no JSON (HTTP '.$response->status().')',
                'status' => $response->status(),
            ];
        }

        if (! $response->successful() || ($json['success'] ?? true) === false) {
            $err = $this->firstError($json) ?: ('HTTP '.$response->status());
            Log::warning('Cloudflare browser rendering failed', [
                'status' => $response->status(),
                'error' => $err,
                'url' => $url,
                'errors' => $json['errors'] ?? null,
            ]);

            return ['success' => false, 'error' => $err, 'status' => $response->status()];
        }

        $html = $this->extractHtml($json);
        if ($html === null || strlen($html) < 80) {
            return ['success' => false, 'error' => 'Cloudflare devolvió HTML vacío o un challenge.'];
        }

        return [
            'success' => true,
            'html' => $html,
            'bytes' => strlen($html),
            'status' => $response->status(),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function extractHtml(array $json): ?string
    {
        $result = $json['result'] ?? null;
        if (is_string($result) && $result !== '') {
            return $result;
        }
        if (is_array($result)) {
            foreach (['html', 'content', 'value', 'body'] as $key) {
                if (isset($result[$key]) && is_string($result[$key]) && $result[$key] !== '') {
                    return $result[$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function firstError(array $json): string
    {
        $errors = $json['errors'] ?? null;
        if (is_array($errors) && isset($errors[0])) {
            $row = $errors[0];
            if (is_array($row)) {
                $msg = (string) ($row['message'] ?? '');
                $code = $row['code'] ?? null;
                if ($msg !== '' && $code) {
                    return $msg.' ('.$code.')';
                }

                return $msg !== '' ? $msg : (string) json_encode($row);
            }

            return (string) $row;
        }

        if (isset($json['messages'][0]['message'])) {
            return (string) $json['messages'][0]['message'];
        }

        return (string) ($json['error'] ?? '');
    }
}
