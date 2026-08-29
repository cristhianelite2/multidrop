<?php

namespace App\Domain\Suppliers\AliExpress;

use Illuminate\Support\Facades\Http;

class AliExpressAffiliateClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('aliexpress.app_key')) !== ''
            && trim((string) config('aliexpress.app_secret')) !== '';
    }

    /**
     * @return array{success: bool, products?: list<array<string, mixed>>, error?: string, raw?: mixed}
     */
    public function productDetail(string $productId, array $overrides = []): array
    {
        $productId = preg_replace('/\D+/', '', $productId) ?? '';
        if ($productId === '') {
            return ['success' => false, 'error' => 'ID de producto AliExpress vacío'];
        }
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Falta App Key / App Secret de AliExpress Affiliate'];
        }

        $params = [
            'app_key' => (string) config('aliexpress.app_key'),
            'method' => 'aliexpress.affiliate.productdetail.get',
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'format' => 'json',
            'v' => '2.0',
            'sign_method' => 'md5',
            'product_ids' => $productId,
            'target_currency' => (string) ($overrides['currency'] ?? config('aliexpress.target_currency', 'USD')),
            'target_language' => (string) ($overrides['language'] ?? config('aliexpress.target_language', 'ES')),
            'ship_to_country' => (string) ($overrides['ship_to'] ?? config('aliexpress.ship_to', 'MX')),
        ];
        $tracking = trim((string) ($overrides['tracking_id'] ?? config('aliexpress.tracking_id')));
        if ($tracking !== '') {
            $params['tracking_id'] = $tracking;
        }

        $params['sign'] = $this->sign($params, (string) config('aliexpress.app_secret'));

        try {
            $res = Http::asForm()
                ->timeout((int) config('aliexpress.timeout', 25))
                ->acceptJson()
                ->post((string) config('aliexpress.gateway'), $params);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'No se pudo contactar AliExpress: '.$e->getMessage()];
        }

        $json = $res->json();
        if (! is_array($json)) {
            return [
                'success' => false,
                'error' => 'Respuesta no JSON (HTTP '.$res->status().')',
            ];
        }

        if (isset($json['error_response'])) {
            $err = $json['error_response'];
            $msg = is_array($err)
                ? (string) ($err['sub_msg'] ?? $err['msg'] ?? json_encode($err))
                : (string) $err;

            return ['success' => false, 'error' => $msg !== '' ? $msg : 'error_response', 'raw' => $json];
        }

        $envelope = $json['aliexpress_affiliate_productdetail_get_response']
            ?? $json['response']
            ?? $json;
        $resp = is_array($envelope) ? ($envelope['resp_result'] ?? $envelope) : [];
        if (! is_array($resp)) {
            $resp = [];
        }

        $code = (int) ($resp['resp_code'] ?? $resp['code'] ?? $res->status());
        if ($code !== 200 && $code !== 0 && empty($resp['result'])) {
            $msg = (string) ($resp['resp_msg'] ?? $resp['msg'] ?? $resp['message'] ?? 'Affiliate API error '.$code);

            return ['success' => false, 'error' => $msg, 'raw' => $json];
        }

        $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
        $products = $this->extractProducts($result);
        if ($products === []) {
            return [
                'success' => false,
                'error' => 'La API no devolvió el producto '.$productId.'. Revisa Tracking ID y que el ID exista en Affiliate.',
                'raw' => $json,
            ];
        }

        return ['success' => true, 'products' => $products, 'raw' => $json];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    protected function extractProducts(array $result): array
    {
        $node = $result['products'] ?? $result['product'] ?? null;
        $list = [];
        if (is_array($node)) {
            if (isset($node['product']) && is_array($node['product'])) {
                $list = $this->isList($node['product']) ? $node['product'] : [$node['product']];
            } elseif ($this->isList($node)) {
                $list = $node;
            }
        }

        $out = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $arr
     */
    protected function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * @param  array<string, string>  $params
     */
    protected function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params);
        $buf = $secret;
        foreach ($params as $key => $value) {
            if ($value === '' || is_array($value)) {
                continue;
            }
            $buf .= $key.$value;
        }
        $buf .= $secret;

        return strtoupper(md5($buf));
    }
}
