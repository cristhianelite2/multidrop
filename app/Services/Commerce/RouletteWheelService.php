<?php

namespace App\Services\Commerce;

/**
 * Premios de la ruleta (plugin) con pesos / probabilidades.
 */
class RouletteWheelService
{
    /**
     * @return list<array{label: string, color: string, weight: int, code: ?string}>
     */
    public function defaultPrizes(): array
    {
        return [
            ['label' => '5% OFF', 'color' => '#14b8a6', 'weight' => 25, 'code' => 'SAVE5'],
            ['label' => '10% OFF', 'color' => '#f59e0b', 'weight' => 20, 'code' => 'SAVE10'],
            ['label' => __('storefront.roulette.free_ship'), 'color' => '#8b5cf6', 'weight' => 15, 'code' => 'FREESHIP'],
            ['label' => '15% OFF', 'color' => '#ef4444', 'weight' => 10, 'code' => 'SAVE15'],
            ['label' => __('storefront.roulette.try_again'), 'color' => '#64748b', 'weight' => 20, 'code' => null],
            ['label' => 'DEMO10', 'color' => '#0ea5e9', 'weight' => 10, 'code' => 'DEMO10'],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   headline: string,
     *   subtitle: string,
     *   auto_open: bool,
     *   auto_open_delay_ms: int,
     *   spin_ms: int,
     *   prizes: list<array{label: string, color: string, weight: int, code: ?string, chance: float}>
     * }
     */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $prizesIn = is_array($raw['prizes'] ?? null) ? $raw['prizes'] : [];
        $prizes = [];
        foreach ($prizesIn as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $color = trim((string) ($row['color'] ?? '#0f766e'));
            if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = '#0f766e';
            }
            $weight = max(1, min(1000, (int) ($row['weight'] ?? 1)));
            $code = trim((string) ($row['code'] ?? ''));
            $prizes[] = [
                'label' => mb_substr($this->localizePrizeLabel($label), 0, 40),
                'color' => $color,
                'weight' => $weight,
                'code' => $code !== '' ? mb_substr($code, 0, 40) : null,
            ];
        }
        if ($prizes === []) {
            $prizes = $this->defaultPrizes();
        }

        $total = array_sum(array_column($prizes, 'weight')) ?: 1;
        foreach ($prizes as $i => $p) {
            $prizes[$i]['chance'] = round(($p['weight'] / $total) * 100, 1);
        }

        $defaultHeadline = __('storefront.roulette.headline');
        $defaultSubtitle = __('storefront.roulette.subtitle');
        $headline = trim((string) ($raw['headline'] ?? ''));
        $subtitle = trim((string) ($raw['subtitle'] ?? ''));
        if ($headline === '' || $this->isDefaultSpanishCopy($headline)) {
            $headline = $defaultHeadline;
        }
        if ($subtitle === '' || $this->isDefaultSpanishCopy($subtitle)) {
            $subtitle = $defaultSubtitle;
        }

        return [
            'headline' => mb_substr($headline, 0, 80) ?: $defaultHeadline,
            'subtitle' => mb_substr($subtitle, 0, 160),
            'auto_open' => filter_var($raw['auto_open'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'auto_open_delay_ms' => max(500, min(30000, (int) ($raw['auto_open_delay_ms'] ?? 1800))),
            'spin_ms' => max(2500, min(12000, (int) ($raw['spin_ms'] ?? 4800))),
            'prizes' => $prizes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forStore(\App\Models\Store $store): array
    {
        return $this->normalize(data_get($store->settings, 'roulette_wheel'));
    }

    /**
     * @return array<string, mixed>
     */
    public function forSandbox(): array
    {
        return $this->normalize([
            'headline' => __('storefront.roulette.headline_sandbox'),
            'subtitle' => __('storefront.roulette.subtitle_sandbox'),
            'auto_open' => true,
            'auto_open_delay_ms' => 1200,
            'spin_ms' => 4500,
            'prizes' => $this->defaultPrizes(),
        ]);
    }

    protected function localizePrizeLabel(string $label): string
    {
        $key = mb_strtolower(trim($label));
        if (in_array($key, ['envío gratis', 'envio gratis', 'free shipping', 'frete grátis', 'frete gratis'], true)) {
            return __('storefront.roulette.free_ship');
        }
        if (in_array($key, ['intenta otra vez', 'try again', 'tente de novo', 'tente outra vez'], true)) {
            return __('storefront.roulette.try_again');
        }

        return $label;
    }

    protected function isDefaultSpanishCopy(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        return in_array($t, [
            '¡gira y gana!',
            'gira y gana!',
            '¡gira la ruleta!',
            'gira la ruleta!',
            'prueba tu suerte — ofertas reales en segundos',
            'prueba tu suerte - ofertas reales en segundos',
            'sandbox · prueba de conversión llamativa',
            'sandbox · prueba de conversion llamativa',
        ], true);
    }
}
