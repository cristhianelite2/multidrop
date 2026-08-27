<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Services\Commerce\ShippingQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * País ISO-2 del visitante (headers CDN / lookup ip-api) filtrado por destinos de envío.
 */
class VisitorCountryResolver
{
    private const HEADER_KEYS = [
        'CF-IPCountry',
        'CloudFront-Viewer-Country',
        'X-AppEngine-Country',
        'X-Country-Code',
    ];

    private const IGNORE_CODES = ['XX', 'T1', 'ZZ', 'A1', 'A2'];

    /**
     * @return array{country: ?string, source: ?string}
     */
    public function resolve(?Store $store = null, ?Request $request = null): array
    {
        try {
            $request = $request ?? request();
        } catch (\Throwable) {
            return ['country' => null, 'source' => null];
        }

        if (! $request instanceof Request) {
            return ['country' => null, 'source' => null];
        }

        $allowed = $this->allowedCodes($store);

        $fromHeader = $this->fromHeaders($request);
        if ($fromHeader !== null) {
            $code = $this->normalizeAndFilter($fromHeader, $allowed);
            if ($code !== null) {
                return ['country' => $code, 'source' => 'header'];
            }
        }

        $ip = $this->clientIp($request);
        if ($ip === null || $this->isLocalIp($ip)) {
            return ['country' => null, 'source' => null];
        }

        $fromApi = $this->fromIpApi($ip);
        $code = $this->normalizeAndFilter($fromApi, $allowed);
        if ($code !== null) {
            return ['country' => $code, 'source' => 'ip-api'];
        }

        return ['country' => null, 'source' => null];
    }

    /**
     * @return array{country: ?string, source: ?string}
     */
    public function forPayload(?Store $store = null): array
    {
        try {
            return $this->resolve($store);
        } catch (\Throwable) {
            return ['country' => null, 'source' => null];
        }
    }

    /**
     * @return array<string, true>
     */
    protected function allowedCodes(?Store $store): array
    {
        $out = [];
        foreach (app(ShippingQuoteService::class)->countries($store) as $row) {
            $code = $this->normalizeCode((string) ($row['code'] ?? ''));
            if ($code !== '') {
                $out[$code] = true;
            }
        }

        return $out;
    }

    protected function fromHeaders(Request $request): ?string
    {
        foreach (self::HEADER_KEYS as $name) {
            $raw = $this->normalizeCode((string) $request->header($name, ''));
            if ($raw === '' || in_array($raw, self::IGNORE_CODES, true)) {
                continue;
            }
            if (preg_match('/^[A-Z]{2}$/', $raw)) {
                return $raw;
            }
        }

        return null;
    }

    protected function fromIpApi(string $ip): ?string
    {
        $cached = Cache::remember('md_geo_cc:'.$ip, now()->addDay(), function () use ($ip) {
            try {
                $response = Http::timeout(1.5)
                    ->connectTimeout(1)
                    ->acceptJson()
                    ->get('http://ip-api.com/json/'.rawurlencode($ip), [
                        'fields' => 'status,countryCode',
                    ]);
                if (! $response->ok()) {
                    return '';
                }
                $json = $response->json();
                if (! is_array($json) || ($json['status'] ?? '') !== 'success') {
                    return '';
                }

                return $this->normalizeCode((string) ($json['countryCode'] ?? ''));
            } catch (\Throwable $e) {
                Log::debug('VisitorCountryResolver ip-api failed', ['message' => $e->getMessage()]);

                return '';
            }
        });

        $code = $this->normalizeCode((string) $cached);

        return $code !== '' ? $code : null;
    }

    /**
     * @param  array<string, true>  $allowed
     */
    protected function normalizeAndFilter(?string $code, array $allowed): ?string
    {
        $code = $this->normalizeCode((string) $code);
        if ($code === '' || in_array($code, self::IGNORE_CODES, true)) {
            return null;
        }
        if ($allowed !== [] && ! isset($allowed[$code])) {
            return null;
        }

        return $code;
    }

    protected function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === 'UK') {
            return 'GB';
        }

        return $code;
    }

    protected function clientIp(Request $request): ?string
    {
        $ip = trim((string) $request->ip());
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        return $ip !== '' ? $ip : null;
    }

    protected function isLocalIp(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if ($ip === '' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
