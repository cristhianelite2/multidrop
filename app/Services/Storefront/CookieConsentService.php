<?php

namespace App\Services\Storefront;

use App\Models\Store;

class CookieConsentService
{
    public const VERSION = 1;

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *   version: int,
     *   title: string,
     *   body: string,
     *   policy_url: string,
     *   accept_label: string,
     *   reject_label: string,
     *   configure_label: string,
     *   save_label: string,
     *   necessary_label: string,
     *   analytics_label: string,
     *   marketing_label: string,
     *   analytics_enabled: bool,
     *   marketing_enabled: bool
     * }
     */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $policy = trim((string) ($raw['policy_url'] ?? ''));
        if ($policy !== '' && ! preg_match('#^https?://#i', $policy) && ! str_starts_with($policy, '/')) {
            $policy = '';
        }

        return [
            'version' => self::VERSION,
            'title' => $this->clip((string) ($raw['title'] ?? 'Usamos cookies'), 80, 'Usamos cookies'),
            'body' => $this->clip(
                (string) ($raw['body'] ?? 'Usamos cookies necesarias para que la tienda funcione. La analítica y el marketing solo se activan si las aceptas.'),
                400,
                'Usamos cookies necesarias para que la tienda funcione. La analítica y el marketing solo se activan si las aceptas.'
            ),
            'policy_url' => mb_substr($policy, 0, 300),
            'accept_label' => $this->clip((string) ($raw['accept_label'] ?? 'Aceptar todo'), 40, 'Aceptar todo'),
            'reject_label' => $this->clip((string) ($raw['reject_label'] ?? 'Rechazar opcionales'), 40, 'Rechazar opcionales'),
            'configure_label' => $this->clip((string) ($raw['configure_label'] ?? 'Configurar'), 40, 'Configurar'),
            'save_label' => $this->clip((string) ($raw['save_label'] ?? 'Guardar preferencias'), 40, 'Guardar preferencias'),
            'necessary_label' => $this->clip((string) ($raw['necessary_label'] ?? 'Necesarias'), 40, 'Necesarias'),
            'analytics_label' => $this->clip((string) ($raw['analytics_label'] ?? 'Analítica'), 40, 'Analítica'),
            'marketing_label' => $this->clip((string) ($raw['marketing_label'] ?? 'Marketing'), 40, 'Marketing'),
            'analytics_enabled' => filter_var($raw['analytics_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'marketing_enabled' => filter_var($raw['marketing_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forStore(Store $store): array
    {
        return $this->normalize(data_get($store->settings, 'cookies'));
    }

    /**
     * @return array<string, mixed>
     */
    public function forSandbox(): array
    {
        return $this->normalize([
            'title' => 'Usamos cookies',
            'body' => 'En este sandbox puedes probar el banner UE. La analítica y el marketing no se cargan hasta que aceptes.',
            'analytics_enabled' => true,
            'marketing_enabled' => true,
        ]);
    }

    protected function clip(string $value, int $max, string $fallback): string
    {
        $value = mb_substr(trim($value), 0, $max);

        return $value !== '' ? $value : $fallback;
    }
}
