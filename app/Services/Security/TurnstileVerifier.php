<?php

namespace App\Services\Security;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier
{
    public function isLocalBypassActive(?string $host = null): bool
    {
        if (! app()->environment('local')) {
            return false;
        }
        if (! (bool) config('cloudflare.turnstile.allow_local_bypass', true)) {
            return false;
        }
        $host = strtolower(trim((string) ($host ?? '')));
        if ($host === '') {
            try {
                $host = strtolower((string) request()->getHost());
            } catch (\Throwable) {
                $host = '';
            }
        }

        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    public function siteKey(): ?string
    {
        $key = PlatformSetting::getValue('cloudflare.turnstile.site_key')
            ?: config('cloudflare.turnstile.site_key');

        return filled($key) ? (string) $key : null;
    }

    public function secretKey(): ?string
    {
        $key = PlatformSetting::getValue('cloudflare.turnstile.secret_key')
            ?: config('cloudflare.turnstile.secret_key');

        return filled($key) ? (string) $key : null;
    }

    public function enabled(): bool
    {
        return filled($this->siteKey()) && filled($this->secretKey());
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if ($this->isLocalBypassActive()) {
            return true;
        }
        if (! $this->enabled()) {
            return true;
        }
        if (! filled($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                array_filter([
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => $ip,
                ])
            );
            $json = $response->json() ?? [];

            return (bool) ($json['success'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('Turnstile verify failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
