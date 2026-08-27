<?php

namespace App\Domain\Suppliers\Cj;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Proxy de videos CJ (hotlink bloqueado en el navegador).
 */
class CjVideoProxy
{
    public const HOSTS = [
        'download-only-api.cjdropshipping.com',
        'cf.cjdropshipping.com',
        'oss-cf.cjdropshipping.com',
    ];

    public function needsProxy(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::HOSTS, true);
    }

    public function playableUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || ! $this->needsProxy($url)) {
            return $url;
        }

        try {
            $base = route('store.media.cj-video', absolute: false);

            return $base.(str_contains($base, '?') ? '&' : '?').'u='.rawurlencode($url);
        } catch (\Throwable) {
            return '/media/cj-video?u='.rawurlencode($url);
        }
    }

    public function stream(string $url): StreamedResponse
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new HttpException(422, 'URL de video inválida');
        }
        if (! $this->needsProxy($url)) {
            throw new HttpException(403, 'Host de video no permitido');
        }

        try {
            $upstream = Http::timeout(90)
                ->withHeaders([
                    'Referer' => 'https://developers.cjdropshipping.com/',
                    'User-Agent' => 'MultidropStore/1.0',
                ])
                ->withOptions(['stream' => true, 'http_errors' => false])
                ->get($url);

            if (! $upstream->successful()) {
                throw new HttpException(502, 'CJ no entregó el video (HTTP '.$upstream->status().')');
            }

            $contentType = $upstream->header('Content-Type') ?: 'video/mp4';
            $body = $upstream->toPsrResponse()->getBody();

            return response()->stream(function () use ($body) {
                while (! $body->eof()) {
                    echo $body->read(1024 * 64);
                    if (function_exists('flush')) {
                        flush();
                    }
                }
            }, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new HttpException(502, 'Error al proxy del video: '.$e->getMessage());
        }
    }
}
