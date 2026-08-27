<?php

namespace App\Services\Currency;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/**
 * Tasas FX globales (1 unidad de moneda base = rate unidades de cada moneda),
 * redondeo por moneda y conversión entre pares.
 */
class CurrencyService
{
    public const ROUNDING_MODES = [
        'none' => '2 decimales (sin redondeo especial)',
        'cent_00' => 'Entero (.00)',
        'cent_99' => 'Terminación .99',
        'cent_95' => 'Terminación .95',
        'cent_49' => 'Terminación .49',
        'nearest_5' => 'Múltiplo de 5',
        'nearest_10' => 'Múltiplo de 10',
        'psych' => 'Psicológico (x.99 / x4.99 / x9.99)',
    ];

    /**
     * Monedas de vitrina / dropshipping: MX, UK, Euro, AU, CA
     * y potencias con e-commerce real (USD, JP, KR, BR, CH, NZ, SG, AE, SE).
     *
     * @var list<string>
     */
    public const STOREFRONT_CURRENCIES = [
        'USD', 'MXN', 'EUR', 'GBP', 'CAD', 'AUD',
        'JPY', 'KRW', 'BRL', 'CHF', 'NZD', 'SGD', 'AED', 'SEK',
    ];

    /** @var array<string, string> */
    public const CURRENCY_LABELS = [
        'USD' => 'Dólar estadounidense',
        'MXN' => 'Peso mexicano',
        'EUR' => 'Euro',
        'GBP' => 'Libra esterlina',
        'CAD' => 'Dólar canadiense',
        'AUD' => 'Dólar australiano',
        'NZD' => 'Dólar neozelandés',
        'CHF' => 'Franco suizo',
        'JPY' => 'Yen japonés',
        'KRW' => 'Won surcoreano',
        'SGD' => 'Dólar de Singapur',
        'AED' => 'Dírham de EAU',
        'CNY' => 'Yuan chino',
        'BRL' => 'Real brasileño',
        'COP' => 'Peso colombiano',
        'ARS' => 'Peso argentino',
        'CLP' => 'Peso chileno',
        'PEN' => 'Sol peruano',
        'PLN' => 'Złoty polaco',
        'SEK' => 'Corona sueca',
        'NOK' => 'Corona noruega',
        'DKK' => 'Corona danesa',
        'CZK' => 'Corona checa',
        'HUF' => 'Florín húngaro',
        'RON' => 'Leu rumano',
        'BGN' => 'Lev búlgaro',
        'ISK' => 'Corona islandesa',
    ];

    /** @return array<string, float> 1 BASE → currency */
    public function defaultRates(): array
    {
        return [
            'USD' => 1.0,
            'MXN' => 17.2,
            'EUR' => 0.92,
            'GBP' => 0.79,
            'CAD' => 1.37,
            'AUD' => 1.53,
            'NZD' => 1.68,
            'CHF' => 0.89,
            'JPY' => 151.0,
            'KRW' => 1350.0,
            'SGD' => 1.34,
            'AED' => 3.67,
            'CNY' => 7.25,
            'BRL' => 5.05,
            'COP' => 4100.0,
            'ARS' => 980.0,
            'CLP' => 950.0,
            'PEN' => 3.75,
            'PLN' => 3.95,
            'SEK' => 10.5,
            'NOK' => 10.7,
            'DKK' => 6.85,
            'CZK' => 23.0,
            'HUF' => 360.0,
            'RON' => 4.55,
            'BGN' => 1.80,
            'ISK' => 138.0,
        ];
    }

    public function base(): string
    {
        $base = strtoupper((string) PlatformSetting::getValue('currency.base', 'USD'));

        return preg_match('/^[A-Z]{3}$/', $base) ? $base : 'USD';
    }

    /**
     * Moneda sugerida para un locale (es_MX → MXN, en_US → USD…).
     */
    public function currencyForLocale(string $locale): ?string
    {
        $locale = str_replace('-', '_', trim($locale));
        $map = [
            'MX' => 'MXN', 'US' => 'USD', 'GB' => 'GBP', 'UK' => 'GBP',
            'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'CH' => 'CHF',
            'JP' => 'JPY', 'KR' => 'KRW', 'SG' => 'SGD', 'AE' => 'AED',
            'CN' => 'CNY', 'BR' => 'BRL', 'CO' => 'COP',
            'AR' => 'ARS', 'CL' => 'CLP', 'PE' => 'PEN', 'PL' => 'PLN',
            'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK', 'CZ' => 'CZK',
            'HU' => 'HUF', 'RO' => 'RON', 'BG' => 'BGN', 'IS' => 'ISK',
            'ES' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR',
            'NL' => 'EUR', 'PT' => 'EUR', 'IE' => 'EUR', 'AT' => 'EUR',
            'BE' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR',
        ];

        if (preg_match('/_([A-Za-z]{2})$/', $locale, $m)) {
            $region = strtoupper($m[1]);
            $code = $map[$region] ?? null;
            if ($code && isset($this->rates()[$code])) {
                return $code;
            }
        }

        // Solo idioma
        $lang = strtolower(explode('_', $locale)[0] ?? '');
        $langMap = [
            'es' => 'MXN', 'en' => 'USD', 'pt' => 'BRL', 'fr' => 'EUR',
            'de' => 'EUR', 'it' => 'EUR', 'nl' => 'EUR', 'pl' => 'PLN',
            'sv' => 'SEK', 'da' => 'DKK', 'nb' => 'NOK', 'fi' => 'EUR',
            'hu' => 'HUF', 'cs' => 'CZK', 'ro' => 'RON', 'el' => 'EUR',
        ];
        $code = $langMap[$lang] ?? null;

        return ($code && isset($this->rates()[$code])) ? $code : null;
    }

    /**
     * Convierte precio (y compare) de un producto a otra moneda.
     *
     * @return array{currency: string, price: float, compare_at_price: float|null, from: string, converted: bool}
     */
    public function convertProductPrices(object $product, string $toCurrency, ?string $roundingMode = null): array
    {
        $to = strtoupper($toCurrency);
        $from = strtoupper((string) ($product->currency ?? $this->base()));
        if (! isset($this->rates()[$to])) {
            $to = $this->base();
        }
        if ($from === $to) {
            return [
                'currency' => $from,
                'price' => round((float) $product->price, 2),
                'compare_at_price' => $product->compare_at_price !== null ? round((float) $product->compare_at_price, 2) : null,
                'from' => $from,
                'converted' => false,
            ];
        }

        $price = $this->convert((float) $product->price, $from, $to, true, $roundingMode);
        $compare = null;
        if ($product->compare_at_price !== null && (float) $product->compare_at_price > 0) {
            $compare = $this->convert((float) $product->compare_at_price, $from, $to, true, $roundingMode);
        }

        return [
            'currency' => $to,
            'price' => $price,
            'compare_at_price' => $compare,
            'from' => $from,
            'converted' => true,
        ];
    }

    /**
     * Aplica conversión y guarda en el modelo Product.
     *
     * @return array{currency: string, price: float, compare_at_price: float|null, from: string, converted: bool}
     */
    public function applyToProduct(object $product, string $toCurrency, bool $persist = true): array
    {
        $out = $this->convertProductPrices($product, $toCurrency);
        if (! $out['converted']) {
            return $out;
        }

        $product->currency = $out['currency'];
        $product->price = $out['price'];
        $product->compare_at_price = $out['compare_at_price'];
        if ($persist && method_exists($product, 'save')) {
            $product->save();
        }

        return $out;
    }

    /**
     * @return array<string, float>
     */
    public function rates(): array
    {
        $defaults = $this->defaultRates();
        $base = $this->base();
        $stored = PlatformSetting::getValue('currency.rates');
        $parsed = [];
        if (is_string($stored) && $stored !== '') {
            $json = json_decode($stored, true);
            if (is_array($json)) {
                foreach ($json as $code => $rate) {
                    $code = strtoupper((string) $code);
                    if (! preg_match('/^[A-Z]{3}$/', $code)) {
                        continue;
                    }
                    $parsed[$code] = (float) $rate;
                }
            }
        }

        $config = config('cj.fx_rates_usd', []);
        if (is_array($config) && $base === 'USD') {
            foreach ($config as $code => $rate) {
                $code = strtoupper((string) $code);
                if (preg_match('/^[A-Z]{3}$/', $code)) {
                    $defaults[$code] = (float) $rate;
                }
            }
        }

        $rates = array_merge($defaults, $parsed);
        $rates[$base] = 1.0;
        ksort($rates);

        return $rates;
    }

    /**
     * @return array<string, string>
     */
    public function roundingMap(): array
    {
        $defaults = [];
        foreach (array_keys($this->defaultRates()) as $code) {
            $defaults[$code] = 'none';
        }
        $defaults['MXN'] = 'cent_99';
        $defaults['USD'] = 'psych';
        $defaults['EUR'] = 'cent_99';
        $defaults['GBP'] = 'psych';
        $defaults['CAD'] = 'psych';
        $defaults['AUD'] = 'psych';
        $defaults['JPY'] = 'cent_00';
        $defaults['KRW'] = 'nearest_10';
        $defaults['SGD'] = 'psych';
        $defaults['AED'] = 'cent_99';
        $defaults['BRL'] = 'cent_99';
        $defaults['CHF'] = 'psych';
        $defaults['NZD'] = 'psych';
        $defaults['SEK'] = 'cent_00';

        $stored = PlatformSetting::getValue('currency.rounding');
        if (is_string($stored) && $stored !== '') {
            $json = json_decode($stored, true);
            if (is_array($json)) {
                foreach ($json as $code => $mode) {
                    $code = strtoupper((string) $code);
                    $mode = (string) $mode;
                    if (isset(self::ROUNDING_MODES[$mode])) {
                        $defaults[$code] = $mode;
                    }
                }
            }
        }

        return $defaults;
    }

    public function roundingFor(string $currency): string
    {
        $map = $this->roundingMap();
        $code = strtoupper($currency);

        return $map[$code] ?? 'none';
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_keys($this->rates());
    }

    /**
     * @return list<array{code: string, label: string, rate: float, rounding: string}>
     */
    public function catalog(): array
    {
        return $this->storefrontCatalog();
    }

    /**
     * ISO de bandera (flag-icons) para una moneda. EUR → eu.
     */
    public static function isoForCurrency(string $code): string
    {
        $map = [
            'USD' => 'us', 'MXN' => 'mx', 'EUR' => 'eu', 'GBP' => 'gb',
            'CAD' => 'ca', 'AUD' => 'au', 'NZD' => 'nz', 'CHF' => 'ch',
            'JPY' => 'jp', 'KRW' => 'kr', 'SGD' => 'sg', 'AED' => 'ae',
            'CNY' => 'cn', 'BRL' => 'br', 'COP' => 'co', 'ARS' => 'ar',
            'CLP' => 'cl', 'PEN' => 'pe', 'PLN' => 'pl', 'SEK' => 'se',
            'NOK' => 'no', 'DKK' => 'dk', 'CZK' => 'cz', 'HUF' => 'hu',
            'RON' => 'ro', 'BGN' => 'bg', 'ISK' => 'is',
        ];

        return $map[strtoupper(trim($code))] ?? '';
    }

    /**
     * Catálogo corto para selectores (productos, General, vitrina).
     *
     * @return list<array{code: string, label: string, rate: float, rounding: string}>
     */
    public function storefrontCatalog(): array
    {
        $rounding = $this->roundingMap();
        $out = [];
        foreach (self::STOREFRONT_CURRENCIES as $code) {
            $out[] = [
                'code' => $code,
                'label' => self::CURRENCY_LABELS[$code] ?? $code,
                'rate' => $this->rate($code),
                'rounding' => $rounding[$code] ?? 'none',
            ];
        }

        return $out;
    }

    public function isStorefrontCurrency(string $code): bool
    {
        return in_array(strtoupper($code), self::STOREFRONT_CURRENCIES, true);
    }

    public function updatedAt(): ?string
    {
        return PlatformSetting::getValue('currency.updated_at');
    }

    public function rate(string $currency): float
    {
        $rates = $this->rates();
        $code = strtoupper($currency);

        return (float) ($rates[$code] ?? 1.0);
    }

    /**
     * Convierte amount desde $from hacia $to usando tasas vs moneda base.
     */
    public function convert(float $amount, string $from, string $to, bool $applyRounding = true, ?string $roundingMode = null): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return $applyRounding ? $this->roundAmount($amount, $to, $roundingMode) : round($amount, 2);
        }

        $fromRate = $this->rate($from);
        $toRate = $this->rate($to);
        if ($fromRate <= 0) {
            $fromRate = 1.0;
        }
        $inBase = $amount / $fromRate;
        $converted = $inBase * $toRate;

        return $applyRounding ? $this->roundAmount($converted, $to, $roundingMode) : round($converted, 2);
    }

    /** Atajo: amount en moneda base → currency */
    public function fromBase(float $amountInBase, string $currency, bool $applyRounding = true): float
    {
        return $this->convert($amountInBase, $this->base(), $currency, $applyRounding);
    }

    public function roundAmount(float $amount, string $currency, ?string $mode = null): float
    {
        $allowed = array_keys(self::ROUNDING_MODES);
        if (! is_string($mode) || ! in_array($mode, $allowed, true)) {
            $mode = $this->roundingFor($currency);
        }

        return $this->applyRounding($amount, $mode);
    }

    public function applyRounding(float $amount, string $mode): float
    {
        if ($amount < 0) {
            return -1 * $this->applyRounding(abs($amount), $mode);
        }

        $out = match ($mode) {
            'cent_00' => (float) round($amount, 0),
            'cent_99' => $amount < 1 ? round($amount, 2) : floor($amount) + 0.99,
            'cent_95' => $amount < 1 ? round($amount, 2) : floor($amount) + 0.95,
            'cent_49' => $amount < 1 ? round($amount, 2) : floor($amount) + 0.49,
            'nearest_5' => (float) (round($amount / 5) * 5),
            'nearest_10' => (float) (round($amount / 10) * 10),
            'psych' => $this->psychRound($amount),
            default => round($amount, 2),
        };

        // Evitar basura float (29.990000000000002)
        return round((float) $out, 2);
    }

    protected function psychRound(float $amount): float
    {
        if ($amount < 8) {
            return round($amount, 2);
        }
        if ($amount < 25) {
            return round(floor($amount) + 0.99, 2);
        }
        if ($amount < 80) {
            return round((floor($amount / 5) * 5) + 4.99, 2);
        }

        return round((floor($amount / 10) * 10) + 9.99, 2);
    }

    /**
     * Guarda base, tasas y redondeos.
     *
     * @param  array<string, float|int|string>  $rates
     * @param  array<string, string>  $rounding
     */
    public function save(string $base, array $rates, array $rounding = []): void
    {
        $base = strtoupper($base);
        if (! preg_match('/^[A-Z]{3}$/', $base)) {
            $base = 'USD';
        }

        $cleanRates = [];
        foreach ($rates as $code => $rate) {
            $code = strtoupper((string) $code);
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                continue;
            }
            $cleanRates[$code] = round((float) $rate, 6);
        }
        $cleanRates[$base] = 1.0;

        $cleanRounding = [];
        foreach ($rounding as $code => $mode) {
            $code = strtoupper((string) $code);
            $mode = (string) $mode;
            if (preg_match('/^[A-Z]{3}$/', $code) && isset(self::ROUNDING_MODES[$mode])) {
                $cleanRounding[$code] = $mode;
            }
        }

        PlatformSetting::put('currency.base', $base, 'currency');
        PlatformSetting::put('currency.rates', json_encode($cleanRates, JSON_UNESCAPED_UNICODE), 'currency');
        PlatformSetting::put('currency.rounding', json_encode($cleanRounding, JSON_UNESCAPED_UNICODE), 'currency');
        PlatformSetting::put('currency.updated_at', now()->toIso8601String(), 'currency');
    }

    /**
     * Consulta API pública y devuelve tasas respecto a $base.
     *
     * @return array{success: bool, base?: string, rates?: array<string, float>, source?: string, date?: string|null, error?: string}
     */
    public function fetchFromPublicApi(?string $base = null): array
    {
        $base = strtoupper($base ?: $this->base());
        if (! preg_match('/^[A-Z]{3}$/', $base)) {
            $base = 'USD';
        }

        $wanted = array_keys($this->defaultRates());

        // 1) Frankfurter (BCE) — sin API key
        $frank = $this->fetchFrankfurter($base, $wanted);
        if ($frank['success']) {
            return $frank;
        }

        // 2) open.er-api.com — más monedas, sin key
        $open = $this->fetchOpenErApi($base, $wanted);
        if ($open['success']) {
            return $open;
        }

        return [
            'success' => false,
            'error' => ($frank['error'] ?? 'Frankfurter falló').' · '.($open['error'] ?? 'open.er-api falló'),
        ];
    }

    /**
     * @param  list<string>  $wanted
     * @return array{success: bool, base?: string, rates?: array<string, float>, source?: string, date?: string|null, error?: string}
     */
    protected function fetchFrankfurter(string $base, array $wanted): array
    {
        try {
            $to = array_values(array_filter($wanted, fn ($c) => $c !== $base));
            $response = Http::timeout(12)
                ->acceptJson()
                ->get('https://api.frankfurter.app/latest', [
                    'from' => $base,
                    'to' => implode(',', $to),
                ]);

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'Frankfurter HTTP '.$response->status()];
            }

            $json = $response->json();
            $rates = is_array($json['rates'] ?? null) ? $json['rates'] : [];
            $clean = [$base => 1.0];
            foreach ($rates as $code => $rate) {
                $code = strtoupper((string) $code);
                $clean[$code] = (float) $rate;
            }

            // Completar faltantes con tasas previas convertidas vía USD si hace falta
            $clean = $this->fillMissingRates($clean, $base, $wanted);

            return [
                'success' => true,
                'base' => $base,
                'rates' => $clean,
                'source' => 'frankfurter.app',
                'date' => $json['date'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Frankfurter: '.$e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $wanted
     * @return array{success: bool, base?: string, rates?: array<string, float>, source?: string, date?: string|null, error?: string}
     */
    protected function fetchOpenErApi(string $base, array $wanted): array
    {
        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get('https://open.er-api.com/v6/latest/'.$base);

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'open.er-api HTTP '.$response->status()];
            }

            $json = $response->json();
            if (($json['result'] ?? '') !== 'success') {
                return ['success' => false, 'error' => 'open.er-api: '.($json['error-type'] ?? 'sin éxito')];
            }

            $rates = is_array($json['rates'] ?? null) ? $json['rates'] : [];
            $clean = [$base => 1.0];
            foreach ($wanted as $code) {
                if (isset($rates[$code])) {
                    $clean[$code] = (float) $rates[$code];
                }
            }
            $clean = $this->fillMissingRates($clean, $base, $wanted);

            return [
                'success' => true,
                'base' => $base,
                'rates' => $clean,
                'source' => 'open.er-api.com',
                'date' => isset($json['time_last_update_utc']) ? (string) $json['time_last_update_utc'] : null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'open.er-api: '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string, float>  $rates
     * @param  list<string>  $wanted
     * @return array<string, float>
     */
    protected function fillMissingRates(array $rates, string $base, array $wanted): array
    {
        $current = $this->rates();
        // Si la base actual no es la pedida, convertir defaults vía ratio
        $oldBase = $this->base();
        $oldRates = $current;

        foreach ($wanted as $code) {
            if (isset($rates[$code])) {
                continue;
            }
            if ($code === $base) {
                $rates[$code] = 1.0;

                continue;
            }
            // Intentar: amount_in_old_base vía oldRates, luego a new base
            if (isset($oldRates[$code], $oldRates[$base]) && (float) $oldRates[$base] > 0 && $oldBase === $base) {
                $rates[$code] = (float) $oldRates[$code];
            } elseif (isset($oldRates[$code], $oldRates[$oldBase]) && (float) $oldRates[$oldBase] > 0) {
                // rate_code / rate_base_in_old = units of code per 1 oldBase... 
                // We need: 1 newBase = ? code
                // If oldRates are vs oldBase: 1 oldBase = oldRates[X] of X
                // 1 newBase = (1/oldRates[newBase])*oldBase if newBase was in oldRates as currency...
                // Simpler: keep previous relative value using USD pivot if possible
                $defaults = $this->defaultRates();
                if (isset($defaults[$code], $defaults[$base]) && (float) $defaults[$base] > 0) {
                    // defaults are vs USD-ish; use ratio
                    $rates[$code] = round(((float) $defaults[$code]) / ((float) $defaults[$base]), 6);
                } else {
                    $rates[$code] = (float) ($oldRates[$code] ?? 1);
                }
            } else {
                $defaults = $this->defaultRates();
                if (isset($defaults[$code], $defaults[$base]) && (float) $defaults[$base] > 0) {
                    $rates[$code] = round(((float) $defaults[$code]) / ((float) $defaults[$base]), 6);
                } else {
                    $rates[$code] = 1.0;
                }
            }
        }

        $rates[$base] = 1.0;

        return $rates;
    }

    /**
     * Payload listo para JS en formularios.
     *
     * @return array{base: string, rates: array<string, float>, rounding: array<string, string>, modes: array<string, string>}
     */
    public function jsPayload(): array
    {
        return [
            'base' => $this->base(),
            'rates' => $this->rates(),
            'rounding' => $this->roundingMap(),
            'modes' => self::ROUNDING_MODES,
            'updated_at' => $this->updatedAt(),
        ];
    }
}
