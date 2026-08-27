<?php

namespace App\Domain\AI;

use App\Services\Currency\CurrencyService;

/**
 * MIIA elige precio de vitrina por mercado (atractivo local), no un FX redondeado.
 */
class ProductPriceSuggestionService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected CurrencyService $fx
    ) {}

    /**
     * @param  array{
     *   name?: string,
     *   cost_usd?: float,
     *   ship_usd?: float,
     *   fees_pct?: float,
     *   target_margin?: float,
     *   base_price?: float,
     *   base_currency?: string,
     *   compare_at?: float|null,
     *   currencies: list<array{code: string, rounding?: string}>
     * }  $input
     * @return array{success: bool, prices?: array<string, array{price: float, compare_at_price: float|null, rounding: string}>, error?: string, provider?: string, source?: string}
     */
    public function suggest(array $input): array
    {
        $currencies = $this->normalizeCurrencies($input['currencies'] ?? []);
        if ($currencies === []) {
            return ['success' => false, 'error' => 'No hay monedas en la tabla para sugerir.'];
        }

        $costMap = $this->costReference($input, $currencies);
        $fallback = $this->fallbackPrices($input, $currencies, $costMap);
        $ai = $this->fromAi($input, $currencies, $costMap);

        if (! ($ai['success'] ?? false)) {
            if ($fallback === []) {
                return [
                    'success' => false,
                    'error' => $ai['error'] ?? 'No se pudieron calcular precios.',
                    'provider' => $ai['provider'] ?? null,
                ];
            }

            return [
                'success' => true,
                'prices' => $fallback,
                'source' => 'charm',
                'provider' => $ai['provider'] ?? null,
                'message' => 'MIIA no respondió; se eligió precio de vitrina (no FX). Guarda el producto.',
            ];
        }

        $merged = $fallback;
        foreach ($ai['prices'] as $code => $row) {
            $merged[$code] = $row;
        }

        return [
            'success' => true,
            'prices' => $merged,
            'source' => 'ai',
            'provider' => $ai['provider'] ?? 'miia',
            'message' => 'Precios de vitrina sugeridos para '.count($merged).' moneda(s). Guarda el producto.',
        ];
    }

    /**
     * @param  list<array{code: string, rounding: string}>  $currencies
     * @param  array<string, float>  $costMap
     * @return array{success: bool, prices?: array<string, array{price: float, compare_at_price: float|null, rounding: string}>, error?: string, provider?: string}
     */
    protected function fromAi(array $input, array $currencies, array $costMap = []): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en General.',
            ];
        }

        $name = trim((string) ($input['name'] ?? 'Producto'));
        $cost = (float) ($input['cost_usd'] ?? 0);
        $ship = 0.0;
        $landed = $cost;
        $fees = (float) ($input['fees_pct'] ?? 0.045);
        $margin = (float) ($input['target_margin'] ?? 0.42);
        $basePrice = (float) ($input['base_price'] ?? 0);
        $baseCur = strtoupper((string) ($input['base_currency'] ?? 'USD'));
        $compare = $input['compare_at'] ?? null;

        $system = <<<'TXT'
Eres pricing lead de e-commerce / dropshipping. Tu trabajo es elegir el PRECIO DE VITRINA que un comprador local sentiría natural en esa tienda.

Prioridad absoluta: atractivo comercial del número. El margen es secundario.
Puedes ganar o perder un poco respecto al costo. Está bien.

El envío se cobra aparte en el checkout: NO lo incluyas en el precio de vitrina.

PROHIBIDO (parece conversión de tipo de cambio, no precio de tienda):
512.99, 487.50, 318.12, 1,247.00, 27.43, 89.17, 1,013.99

OBLIGATORIO: termina en un número de vitrina de ese mercado.
- MXN: 199, 249, 299, 349, 399, 449, 499, 549, 599, 699, 799, 899, 999, 1199, 1499 (enteros; 499 se siente "menos de 500", 512.99 no).
- USD / CAD / AUD / NZD / SGD / GBP: 9.99, 12.99, 14.99, 19.99, 24.99, 29.99, 34.99, 39.99, 49.99
- EUR / CHF: 9.90, 12.90, 19.90, 24.90, 29.90, 39.90, 49.90
- BRL: 49.90, 79.90, 99.90, 129.90, 149.90
- JPY: 980, 1280, 1980, 2980, 3980
- KRW: 9900, 12900, 19900, 29900
- AED / SEK: mismos criterios locales de "precio bonito", nunca el FX crudo.

El costo/break-even es SOLO contexto. Si en México el costo es ~512, elige 499 o 549, nunca 512.99.
compare_at_price es OBLIGATORIO: un escalón de vitrina arriba (sentido de oferta / "antes vs ahora"). Nunca igual o menor que price.
No copies el redondeo de la fila (.99 sobre el costo). Tú decides el número.

Responde SOLO JSON válido, sin markdown:
{"prices":[{"currency":"MXN","price":499,"compare_at_price":699}]}
TXT;

        $user = "Producto: {$name}\n"
            ."Moneda base del form: {$baseCur} · precio actual: {$basePrice}"
            .($compare ? " · compare: {$compare}" : '')
            ."\nCosto CJ USD: {$cost} (el envío NO entra al precio de vitrina)\n"
            .'Fees: '.round($fees * 100, 1).'% · margen objetivo (orientativo, no obligatorio): '.round($margin * 100, 0)."%\n"
            ."Tasas (1 {$baseCur} = X):\n".$this->rateHint($baseCur, $currencies)
            ."Monedas a cotizar: ".implode(', ', array_column($currencies, 'code'))."\n"
            .($costMap !== [] ? "Costo/break-even aproximado (NO lo uses como precio):\n".$this->costHint($costMap) : '');

        $result = $this->ai->chat('product_price', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'La IA no pudo sugerir precios.',
                'provider' => $result['provider'] ?? null,
            ];
        }

        $parsed = $this->parsePrices((string) ($result['content'] ?? ''), $currencies);
        if ($parsed === []) {
            return [
                'success' => false,
                'error' => 'La IA no devolvió precios utilizables.',
                'provider' => $result['provider'] ?? null,
            ];
        }

        return [
            'success' => true,
            'prices' => $parsed,
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    /**
     * @param  list<array{code: string, rounding: string}>  $currencies
     * @return array<string, float>
     */
    protected function costReference(array $input, array $currencies): array
    {
        $cost = (float) ($input['cost_usd'] ?? 0);
        $fees = (float) ($input['fees_pct'] ?? 0.045);
        $margin = (float) ($input['target_margin'] ?? 0.42);
        $basePrice = (float) ($input['base_price'] ?? 0);
        $baseCur = strtoupper((string) ($input['base_currency'] ?? 'USD'));

        $sellUsd = 0.0;
        if ($cost > 0) {
            $denom = max(0.15, 1 - $margin - $fees);
            $sellUsd = $cost / $denom;
        } elseif ($basePrice > 0) {
            $sellUsd = $this->fx->convert($basePrice, $baseCur, 'USD', false);
        }
        if ($sellUsd <= 0) {
            return [];
        }

        $out = [];
        foreach ($currencies as $row) {
            $out[$row['code']] = $this->fx->convert($sellUsd, 'USD', $row['code'], false);
        }

        return $out;
    }

    /**
     * @param  list<array{code: string, rounding: string}>  $currencies
     * @param  array<string, float>  $costMap
     * @return array<string, array{price: float, compare_at_price: float|null, rounding: string}>
     */
    protected function fallbackPrices(array $input, array $currencies, array $costMap): array
    {
        if ($costMap === []) {
            return [];
        }

        $out = [];
        foreach ($currencies as $row) {
            $code = $row['code'];
            $raw = (float) ($costMap[$code] ?? 0);
            if ($raw <= 0) {
                continue;
            }
            $price = $this->attractivePrice($raw, $code);
            $compare = $this->attractiveCompare($price, $code);
            $out[$code] = [
                'price' => $price,
                'compare_at_price' => $compare,
                'rounding' => 'none',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{code: string, rounding: string}>  $currencies
     * @return array<string, array{price: float, compare_at_price: float|null, rounding: string}>
     */
    protected function parsePrices(string $content, array $currencies): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $fence)) {
            $content = trim($fence[1]);
        }
        $json = json_decode($content, true);
        if (! is_array($json) && preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/', $content, $m)) {
            $json = json_decode($m[1], true);
        }
        if (! is_array($json)) {
            return [];
        }
        $rows = $json['prices'] ?? $json;
        if (! is_array($rows)) {
            return [];
        }

        $allowed = [];
        foreach ($currencies as $row) {
            $allowed[$row['code']] = true;
        }

        $out = [];
        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                if (is_numeric($row) && is_string($key)) {
                    $row = ['currency' => $key, 'price' => $row];
                } else {
                    continue;
                }
            }
            $code = strtoupper(trim((string) ($row['currency'] ?? $row['code'] ?? (is_string($key) ? $key : ''))));
            if (! isset($allowed[$code])) {
                continue;
            }
            $price = (float) ($row['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }
            $price = $this->quantize($price, $code);
            $compare = $row['compare_at_price'] ?? $row['compare'] ?? null;
            $compareVal = ($compare !== null && $compare !== '' && (float) $compare > 0)
                ? $this->quantize((float) $compare, $code)
                : $this->attractiveCompare($price, $code);
            if ($compareVal === null || $compareVal <= $price) {
                $compareVal = $this->attractiveCompare($price, $code);
            }
            $out[$code] = [
                'price' => $price,
                'compare_at_price' => $compareVal,
                'rounding' => 'none',
            ];
        }

        return $out;
    }

    /**
     * Precio de vitrina cercano al costo, no "costo + .99".
     */
    public function attractivePrice(float $raw, string $code): float
    {
        $code = strtoupper($code);
        $raw = max(0.01, $raw);
        [$endings, $mod] = $this->charmScheme($code, $raw);
        $bucket = floor($raw / $mod) * $mod;
        $candidates = [];
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $k) {
            foreach ($endings as $end) {
                $v = ($bucket + ($k * $mod)) + $end;
                if ($v > 0) {
                    $candidates[] = $this->quantize($v, $code);
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return $this->quantize($raw, $code);
        }

        $best = $candidates[0];
        $bestScore = INF;
        $closest = $candidates[0];
        $closestDist = INF;
        foreach ($candidates as $cand) {
            $dist = abs($cand - $raw) / $raw;
            if ($dist < $closestDist) {
                $closestDist = $dist;
                $closest = $cand;
            }
            if ($dist > 0.22) {
                continue;
            }
            $score = $dist;
            if ($this->isCharm($cand, $code)) {
                $score *= 0.82;
            }
            // Preferir "justo debajo" (499 vs 512) si la pérdida es chica
            if ($cand < $raw && ($raw - $cand) / $raw <= 0.12) {
                $score *= 0.88;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }

        return $bestScore === INF ? $closest : $best;
    }

    protected function attractiveCompare(float $price, string $code): ?float
    {
        $up = $this->attractivePrice($price * 1.28, $code);
        if ($up <= $price) {
            $up = $this->attractivePrice($price * 1.45, $code);
        }

        return $up > $price ? $up : null;
    }

    /**
     * @return array{0: list<float>, 1: float}  [endings, modulo]
     */
    protected function charmScheme(string $code, float $raw): array
    {
        return match ($code) {
            'JPY' => $raw < 500 ? [[80.0], 100.0] : [[80.0, 980.0], 1000.0],
            'KRW' => $raw < 5000 ? [[900.0], 1000.0] : [[900.0, 9900.0], 10000.0],
            'EUR', 'CHF' => $raw < 15 ? [[0.90], 1.0] : [[0.90, 4.90, 9.90], 10.0],
            'BRL' => $raw < 30 ? [[0.90], 1.0] : [[0.90, 9.90], 10.0],
            'MXN' => match (true) {
                $raw < 100 => [[9.0, 19.0, 29.0, 39.0, 49.0, 59.0, 69.0, 79.0, 89.0, 99.0], 100.0],
                $raw < 1000 => [[49.0, 99.0], 100.0],
                default => [[99.0], 100.0],
            },
            default => $raw < 15 ? [[0.99], 1.0] : [[0.99, 4.99, 9.99], 10.0],
        };
    }

    protected function isCharm(float $amount, string $code): bool
    {
        $q = $this->quantize($amount, $code);
        $cents = (int) round(fmod($q, 1) * 100);
        $int = (int) round($q);

        return match ($code) {
            'MXN' => in_array($int % 100, [49, 99, 9, 19, 29, 39, 59, 69, 79, 89], true),
            'JPY' => in_array($int % 1000, [80, 980], true) || $int % 100 === 80,
            'KRW' => in_array($int % 10000, [900, 9900], true) || $int % 1000 === 900,
            'EUR', 'CHF', 'BRL' => in_array($cents, [90], true) || in_array($int % 10, [9], true),
            default => in_array($cents, [99], true),
        };
    }

    protected function quantize(float $amount, string $code): float
    {
        if (in_array($code, ['JPY', 'KRW', 'CLP', 'VND'], true)) {
            return (float) round($amount, 0);
        }

        return round($amount, 2);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{code: string, rounding: string}>
     */
    protected function normalizeCurrencies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $allowed = array_keys(CurrencyService::ROUNDING_MODES);
        $out = [];
        foreach ($raw as $row) {
            $code = is_array($row)
                ? strtoupper(trim((string) ($row['code'] ?? $row['currency'] ?? '')))
                : strtoupper(trim((string) $row));
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                continue;
            }
            $mode = is_array($row) ? (string) ($row['rounding'] ?? '') : '';
            if (! in_array($mode, $allowed, true)) {
                $mode = $this->fx->roundingFor($code);
            }
            $out[$code] = ['code' => $code, 'rounding' => $mode];
        }

        return array_values($out);
    }

    /**
     * @param  list<array{code: string, rounding: string}>  $currencies
     */
    protected function rateHint(string $base, array $currencies): string
    {
        $lines = [];
        foreach ($currencies as $row) {
            $rate = $this->fx->convert(1, $base, $row['code'], false);
            $lines[] = '  1 '.$base.' = '.round($rate, 4).' '.$row['code'];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, float>  $costMap
     */
    protected function costHint(array $costMap): string
    {
        $lines = [];
        foreach ($costMap as $code => $raw) {
            $near = [];
            foreach ([-1, 0, 1] as $k) {
                $sample = $this->attractivePrice(max(0.01, $raw * (1 + ($k * 0.08))), $code);
                $near[] = $sample;
            }
            $near = array_values(array_unique($near));
            $lines[] = '  '.$code.' costo≈'.round($raw, 2).' → vitrina típica: '.implode(', ', $near).' (nunca '.round($raw, 2).')';
        }

        return implode("\n", $lines)."\n";
    }
}
