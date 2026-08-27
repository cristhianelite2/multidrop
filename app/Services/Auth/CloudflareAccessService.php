<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareAccessService
{
    public function isEnabled(): bool
    {
        return (bool) config('cloudflare.access.enabled');
    }

    public function mode(): string
    {
        return (string) config('cloudflare.access.mode', 'optional');
    }

    public function isRequired(): bool
    {
        return $this->isEnabled() && $this->mode() === 'required';
    }

    /**
     * Resuelve el usuario autenticado vía Cloudflare Access (JWT y/o header de email).
     */
    public function resolveUser(Request $request): ?User
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $email = $this->resolveEmail($request);

        if (! $email) {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->where('is_active', true)
            ->first();
    }

    public function resolveEmail(Request $request): ?string
    {
        $jwtHeader = (string) config('cloudflare.access.header_jwt', 'Cf-Access-Jwt-Assertion');
        $emailHeader = (string) config('cloudflare.access.header_email', 'Cf-Access-Authenticated-User-Email');
        $verifyJwt = (bool) config('cloudflare.access.verify_jwt', true);

        $jwt = $request->header($jwtHeader);

        if ($jwt) {
            try {
                $payload = $this->verifyJwt($jwt);
                $email = $payload['email'] ?? ($payload['common_name'] ?? null);
                if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return mb_strtolower($email);
                }
            } catch (\Throwable $e) {
                Log::warning('Cloudflare Access JWT inválido', [
                    'message' => $e->getMessage(),
                ]);

                if ($verifyJwt) {
                    return null;
                }
            }
        }

        // Header de email inyectado por CF Access (útil en modo optional / verify_jwt=false).
        $email = $request->header($emailHeader);
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (! $verifyJwt || ! $jwt) {
                return mb_strtolower($email);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('JWT malformado.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new \RuntimeException('JWT no decodificable.');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            throw new \RuntimeException('Algoritmo JWT no soportado.');
        }

        $aud = config('cloudflare.access.audience');
        if ($aud) {
            $tokenAud = $payload['aud'] ?? null;
            $ok = is_array($tokenAud) ? in_array($aud, $tokenAud, true) : ($tokenAud === $aud);
            if (! $ok) {
                throw new \RuntimeException('Audience JWT no coincide.');
            }
        }

        $now = time();
        if (isset($payload['exp']) && (int) $payload['exp'] < $now) {
            throw new \RuntimeException('JWT expirado.');
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + 60) {
            throw new \RuntimeException('JWT aún no válido.');
        }

        if (! (bool) config('cloudflare.access.verify_jwt', true)) {
            return $payload;
        }

        $kid = $header['kid'] ?? null;
        if (! $kid) {
            throw new \RuntimeException('JWT sin kid.');
        }

        $publicKey = $this->publicKeyForKid((string) $kid);
        $signed = $headerB64.'.'.$payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);

        $ok = openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \RuntimeException('Firma JWT inválida.');
        }

        return $payload;
    }

    protected function publicKeyForKid(string $kid): \OpenSSLAsymmetricKey
    {
        $certs = $this->fetchCerts();

        foreach ($certs['public_certs'] ?? [] as $item) {
            if (($item['kid'] ?? null) !== $kid) {
                continue;
            }

            $cert = $item['cert'] ?? '';
            $resource = openssl_pkey_get_public($cert);
            if ($resource === false) {
                throw new \RuntimeException('No se pudo cargar el certificado público CF.');
            }

            return $resource;
        }

        if (($certs['public_cert']['kid'] ?? null) === $kid && ! empty($certs['public_cert']['cert'])) {
            $resource = openssl_pkey_get_public($certs['public_cert']['cert']);
            if ($resource !== false) {
                return $resource;
            }
        }

        throw new \RuntimeException('kid JWT no encontrado en certificados de Cloudflare Access.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchCerts(): array
    {
        $team = trim((string) config('cloudflare.access.team_domain', ''), '/');
        if ($team === '') {
            throw new \RuntimeException('CLOUDFLARE_ACCESS_TEAM_DOMAIN no configurado.');
        }

        $url = 'https://'.$team.'/cdn-cgi/access/certs';

        return Cache::remember('cloudflare_access_certs_'.$team, 3600, function () use ($url) {
            $response = Http::timeout(10)->get($url);
            if (! $response->successful()) {
                throw new \RuntimeException('No se pudieron obtener certificados Cloudflare Access.');
            }

            return $response->json() ?? [];
        });
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
