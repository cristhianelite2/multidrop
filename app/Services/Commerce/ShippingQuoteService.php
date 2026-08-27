<?php

namespace App\Services\Commerce;

use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\Product;
use App\Models\Store;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShippingQuoteService
{
    /**
     * @return list<array{code: string, name: string, rate: float}>
     */
    public function countries(?Store $store = null): array
    {
        $catalog = config('shipping.countries', []);
        $allowed = $store instanceof Store ? $store->displayCountries() : [];
        $to = $store instanceof Store ? $store->currency() : $this->sourceCurrency();
        $out = [];
        foreach ($catalog as $code => $meta) {
            $code = strtoupper((string) $code);
            if ($allowed !== [] && ! in_array($code, $allowed, true)) {
                continue;
            }
            $rateUsd = round((float) ($meta['rate'] ?? 0), 2);
            $out[] = [
                'code' => $code,
                'name' => (string) ($meta['name'] ?? $code),
                'rate' => $this->toDisplayCurrency($this->applyMarkup($rateUsd, $store), $to),
                'currency' => $to,
                'eta' => $this->etaFor($code),
                'eta_label' => $this->etaLabel($this->etaFor($code)),
                'flag' => 'https://flagcdn.com/w40/'.strtolower($code === 'UK' ? 'gb' : $code).'.png',
            ];
        }
        if ($out === []) {
            foreach ($catalog as $code => $meta) {
                $code = strtoupper((string) $code);
                $rateUsd = round((float) ($meta['rate'] ?? 0), 2);
                $out[] = [
                    'code' => $code,
                    'name' => (string) ($meta['name'] ?? $code),
                    'rate' => $this->toDisplayCurrency($this->applyMarkup($rateUsd, $store), $to),
                    'currency' => $to,
                    'eta' => $this->etaFor($code),
                    'eta_label' => $this->etaLabel($this->etaFor($code)),
                    'flag' => 'https://flagcdn.com/w40/'.strtolower($code === 'UK' ? 'gb' : $code).'.png',
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $cartItems
     * @return array{
     *   ok: bool,
     *   country: string,
     *   amount: float,
     *   currency: string,
     *   label: string,
     *   source: string,
     *   options?: list<array{name: string, amount: float}>
     * }
     */
    public function quote(?Store $store, string $country, array $cartItems = [], ?string $displayCurrency = null): array
    {
        $country = strtoupper(trim($country));
        if ($country === 'UK') {
            $country = 'GB';
        }
        if ($country === '') {
            $country = strtoupper((string) config('shipping.default_country', 'MX'));
        }

        $currency = strtoupper(trim((string) ($displayCurrency
            ?? ($store instanceof Store ? $store->currency() : $this->sourceCurrency()))));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = $this->sourceCurrency();
        }

        $fallback = $this->tableRate($country, $cartItems);
        $eta = $this->etaFor($country);

        if (config('shipping.try_cj', true) && $cartItems !== []) {
            $cj = $this->quoteFromCj($country, $cartItems);
            if ($cj !== null) {
                $options = [];
                foreach ($cj['options'] ?? [] as $opt) {
                    $raw = $this->applyMarkup((float) ($opt['amount'] ?? 0), $store);
                    $options[] = [
                        'name' => (string) ($opt['name'] ?? 'Shipping'),
                        'amount' => $this->toDisplayCurrency($raw, $currency),
                    ];
                }
                $amount = $this->applyMarkup((float) $cj['amount'], $store);

                return [
                    'ok' => true,
                    'country' => $country,
                    'amount' => $this->toDisplayCurrency($amount, $currency),
                    'currency' => $currency,
                    'label' => $cj['label'],
                    'source' => 'cj',
                    'options' => $options,
                    'eta' => $eta,
                    'eta_label' => $this->etaLabel($eta),
                ];
            }
        }

        $amount = $this->applyMarkup((float) $fallback['amount'], $store);

        return [
            'ok' => true,
            'country' => $country,
            'amount' => $this->toDisplayCurrency($amount, $currency),
            'currency' => $currency,
            'label' => $fallback['label'],
            'source' => 'table',
            'eta' => $eta,
            'eta_label' => $this->etaLabel($eta),
        ];
    }

    /**
     * Tarifas de config/shipping.php y cotizaciones CJ se asumen en esta moneda.
     */
    public function sourceCurrency(): string
    {
        $code = strtoupper(trim((string) config('shipping.currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
    }

    /**
     * Convierte un monto de envío (tabla/CJ) a la moneda de vitrina.
     */
    public function toDisplayCurrency(float $amountInSource, string $toCurrency): float
    {
        $from = $this->sourceCurrency();
        $to = strtoupper(trim($toCurrency));
        if ($to === '' || ! preg_match('/^[A-Z]{3}$/', $to)) {
            $to = $from;
        }
        if ($from === $to) {
            return round(max(0, $amountInSource), 2);
        }

        return app(CurrencyService::class)->convert($amountInSource, $from, $to);
    }

    /**
     * @param  list<array<string, mixed>>  $cartItems
     * @return array{amount: float, label: string}
     */
    protected function tableRate(string $country, array $cartItems): array
    {
        $catalog = config('shipping.countries', []);
        $meta = $catalog[$country] ?? null;
        $base = is_array($meta) ? (float) ($meta['rate'] ?? 7.99) : 9.99;
        $units = max(1, (int) array_sum(array_map(fn ($it) => max(1, (int) ($it['qty'] ?? 1)), $cartItems)));
        // +15% por unidad extra después de la primera
        $amount = round($base + max(0, $units - 1) * $base * 0.15, 2);
        $name = is_array($meta) ? (string) ($meta['name'] ?? $country) : $country;

        return [
            'amount' => $amount,
            'label' => 'Envío estándar a '.$name,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cartItems
     * @return array{amount: float, label: string, options: list<array{name: string, amount: float}>}|null
     */
    protected function quoteFromCj(string $country, array $cartItems): ?array
    {
        $products = $this->cjProductsPayload($cartItems);
        if ($products === []) {
            return null;
        }

        $cacheKey = 'ship.cj.'.md5($country.'|'.json_encode($products));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['amount'])) {
            return $cached;
        }

        try {
            /** @var CjConnector $cj */
            $cj = app(CjConnector::class);
            $from = strtoupper((string) config('shipping.from_country', config('cj.from_country_code', 'CN')));
            $result = $cj->calculateFreight([
                'startCountryCode' => $from !== '' ? $from : 'CN',
                'endCountryCode' => $country,
                'products' => $products,
            ]);
            if (! ($result['success'] ?? false)) {
                return null;
            }
            $rows = $this->flattenFreightRows($result);
            $options = [];
            foreach ($rows as $row) {
                $amount = $this->extractFreightAmount($row);
                if ($amount === null || $amount <= 0) {
                    continue;
                }
                $name = (string) ($row['logisticName'] ?? $row['enName'] ?? $row['logistic'] ?? 'CJ Shipping');
                $options[] = ['name' => $name, 'amount' => $amount];
            }
            if ($options === []) {
                return null;
            }
            usort($options, fn ($a, $b) => $a['amount'] <=> $b['amount']);
            $best = $options[0];
            $payload = [
                'amount' => $best['amount'],
                'label' => $best['name'],
                'options' => array_slice($options, 0, 5),
            ];
            Cache::put($cacheKey, $payload, now()->addMinutes(30));

            return $payload;
        } catch (\Throwable $e) {
            Log::debug('shipping.cj_quote_failed', ['error' => $e->getMessage(), 'country' => $country]);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $cartItems
     * @return list<array{vid: string, quantity: int}>
     */
    protected function cjProductsPayload(array $cartItems): array
    {
        $out = [];
        foreach ($cartItems as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $vid = (string) ($item['vid'] ?? '');
            if ($vid === '' && ! empty($item['product_id'])) {
                $product = Product::query()->with('variants')->find((int) $item['product_id']);
                if ($product) {
                    $vid = $this->vidFromProduct($product);
                }
            }
            if ($vid === '') {
                continue;
            }
            $out[] = ['vid' => $vid, 'quantity' => $qty];
        }

        return $out;
    }

    protected function vidFromProduct(Product $product): string
    {
        foreach ($product->variants as $variant) {
            $vid = (string) data_get($variant->options, 'vid', '');
            if ($vid !== '') {
                return $vid;
            }
        }

        return (string) data_get($product->verified_data, 'vid', '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function flattenFreightRows(array $result): array
    {
        $data = $result['data'] ?? $result;
        if (! is_array($data)) {
            return [];
        }
        foreach (['freight', 'freightList', 'freightTrialList', 'logisticList', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }
        if ($data !== [] && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return is_array($data) ? [$data] : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function extractFreightAmount(array $row): ?float
    {
        foreach (['logisticPrice', 'totalPostage', 'postage', 'freightFee', 'price', 'amount', 'cost'] as $key) {
            if (! isset($row[$key])) {
                continue;
            }
            $raw = $row[$key];
            if (is_array($raw)) {
                $raw = $raw['amount'] ?? $raw['value'] ?? null;
            }
            if ($raw === null || $raw === '') {
                continue;
            }
            $n = (float) preg_replace('/[^0-9.]/', '', (string) $raw);
            if ($n > 0) {
                return round($n, 2);
            }
        }

        return null;
    }

    protected function applyMarkup(float $amount, ?Store $store = null): float
    {
        $fixed = (float) config('shipping.markup', 0);
        $pct = $store instanceof Store
            ? $store->shippingMarkupPercent()
            : (float) (config('shipping.markup_percent', 10) ?: 10);
        $amount = $amount + $fixed + ($amount * max(0, $pct) / 100);

        return round(max(0, $amount), 2);
    }

    /**
     * @return array{min: int, max: int}
     */
    public function etaFor(string $country): array
    {
        $meta = config('shipping.countries.'.strtoupper($country), []);
        $min = (int) ($meta['eta_min'] ?? 8);
        $max = (int) ($meta['eta_max'] ?? 18);
        if ($min < 1) {
            $min = 8;
        }
        if ($max < $min) {
            $max = $min + 6;
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param  array{min: int, max: int}|null  $eta
     */
    public function etaLabel(?array $eta): string
    {
        $eta = $eta ?: ['min' => 8, 'max' => 18];

        return $eta['min'].'–'.$eta['max'].' días hábiles aprox.';
    }
}
