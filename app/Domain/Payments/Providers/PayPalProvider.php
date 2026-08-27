<?php

namespace App\Domain\Payments\Providers;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalProvider implements PaymentProviderInterface
{
    public function code(): string
    {
        return 'paypal';
    }

    public function createCheckout(array $orderPayload): array
    {
        $clientId = (string) config('payments.paypal.client_id');
        $secret = (string) config('payments.paypal.client_secret');
        if ($clientId === '' || $secret === '') {
            return ['success' => false, 'error' => 'Credenciales PayPal incompletas'];
        }

        /** @var Order|null $order */
        $order = $orderPayload['order'] ?? null;
        if (! $order instanceof Order) {
            return ['success' => false, 'error' => 'Pedido inválido para PayPal.'];
        }

        $access = $this->accessToken();
        if (! ($access['success'] ?? false)) {
            return ['success' => false, 'error' => $access['error'] ?? 'No se pudo autenticar con PayPal.'];
        }

        $currency = strtoupper((string) $order->currency);
        $items = [];
        $itemTotal = 0.0;
        foreach ($order->items as $line) {
            $qty = max(1, (int) $line->qty);
            $unit = round((float) $line->unit_price, 2);
            $lineTotal = round((float) ($line->total ?? ($unit * $qty)), 2);
            $itemTotal += $lineTotal;
            $items[] = [
                'name' => mb_substr((string) $line->name, 0, 127),
                'quantity' => (string) $qty,
                'unit_amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($unit, 2, '.', ''),
                ],
            ];
        }
        $itemTotal = round($itemTotal, 2);
        $shipping = round((float) $order->shipping, 2);
        $tax = round((float) $order->tax, 2);
        $discount = round(max(0.0, (float) $order->discount), 2);
        $computedTotal = round($itemTotal + $shipping + $tax - $discount, 2);

        $breakdown = [
            'item_total' => [
                'currency_code' => $currency,
                'value' => number_format($itemTotal, 2, '.', ''),
            ],
        ];
        if ($shipping > 0) {
            $breakdown['shipping'] = [
                'currency_code' => $currency,
                'value' => number_format($shipping, 2, '.', ''),
            ];
        }
        if ($tax > 0) {
            $breakdown['tax_total'] = [
                'currency_code' => $currency,
                'value' => number_format($tax, 2, '.', ''),
            ];
        }
        if ($discount > 0) {
            $breakdown['discount'] = [
                'currency_code' => $currency,
                'value' => number_format($discount, 2, '.', ''),
            ];
        }

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $order->number,
                'invoice_id' => (string) $order->number,
                'custom_id' => (string) $order->number,
                'description' => 'Pedido '.$order->number,
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($computedTotal, 2, '.', ''),
                    'breakdown' => $breakdown,
                ],
                'items' => $items,
            ]],
            'application_context' => [
                'return_url' => (string) ($orderPayload['return_urls']['success'] ?? ''),
                'cancel_url' => (string) ($orderPayload['return_urls']['failure'] ?? ''),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ],
        ];

        try {
            $response = Http::withToken((string) ($access['token'] ?? ''))
                ->acceptJson()
                ->timeout(30)
                ->post($this->apiBase().'/v2/checkout/orders', $body);
            $json = $response->json() ?? [];

            if (! $response->successful() || empty($json['id'])) {
                Log::warning('PayPal create order failed', ['status' => $response->status(), 'body' => $json]);

                return [
                    'success' => false,
                    'error' => (string) (
                        data_get($json, 'details.0.description')
                        ?: data_get($json, 'message')
                        ?: ('PayPal no creó la orden (HTTP '.$response->status().').')
                    ),
                    'raw' => $json,
                ];
            }

            $approveLink = collect($json['links'] ?? [])->firstWhere('rel', 'approve');
            $checkoutUrl = is_array($approveLink) ? ($approveLink['href'] ?? null) : null;
            if (! is_string($checkoutUrl) || $checkoutUrl === '') {
                return ['success' => false, 'error' => 'PayPal no devolvió URL de aprobación.', 'raw' => $json];
            }

            return [
                'success' => true,
                'provider_ref' => (string) $json['id'],
                'checkout_url' => $checkoutUrl,
                'raw' => $json,
                'provider' => $this->code(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al conectar con PayPal: '.$e->getMessage()];
        }
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        return true;
    }

    public function parseWebhook(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];

        return [
            'status' => $data['event_type'] ?? 'unknown',
            'provider_ref' => (string) data_get($data, 'resource.id', ''),
            'raw' => $data,
        ];
    }

    /**
     * @return array{success: bool, token?: string, error?: string}
     */
    protected function accessToken(): array
    {
        $clientId = (string) config('payments.paypal.client_id');
        $secret = (string) config('payments.paypal.client_secret');
        if ($clientId === '' || $secret === '') {
            return ['success' => false, 'error' => 'Credenciales PayPal incompletas'];
        }
        try {
            $res = Http::asForm()
                ->withBasicAuth($clientId, $secret)
                ->acceptJson()
                ->timeout(20)
                ->post($this->apiBase().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            $json = $res->json() ?? [];
            $token = (string) ($json['access_token'] ?? '');
            if (! $res->successful() || $token === '') {
                return ['success' => false, 'error' => 'No se pudo obtener token PayPal (HTTP '.$res->status().').'];
            }

            return ['success' => true, 'token' => $token];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Token PayPal falló: '.$e->getMessage()];
        }
    }

    public function captureOrder(string $paypalOrderId): array
    {
        if ($paypalOrderId === '') {
            return ['success' => false, 'error' => 'Order ID PayPal vacío'];
        }
        $access = $this->accessToken();
        if (! ($access['success'] ?? false)) {
            return ['success' => false, 'error' => $access['error'] ?? 'No se pudo autenticar con PayPal.'];
        }
        try {
            $res = Http::withToken((string) ($access['token'] ?? ''))
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Prefer' => 'return=representation',
                ])
                ->timeout(30)
                ->post($this->apiBase().'/v2/checkout/orders/'.$paypalOrderId.'/capture', (object) []);
            $json = $res->json() ?? [];
            if (! $res->successful()) {
                return [
                    'success' => false,
                    'error' => (string) (
                        data_get($json, 'details.0.description')
                        ?: data_get($json, 'message')
                        ?: ('PayPal capture falló (HTTP '.$res->status().').')
                    ),
                    'raw' => $json,
                ];
            }
            $status = strtoupper((string) ($json['status'] ?? ''));
            $captureId = (string) data_get($json, 'purchase_units.0.payments.captures.0.id', '');

            return [
                'success' => $status === 'COMPLETED' || $status === 'APPROVED',
                'status' => $status,
                'provider_ref' => $captureId !== '' ? $captureId : $paypalOrderId,
                'raw' => $json,
                'error' => $status === 'COMPLETED' || $status === 'APPROVED' ? null : ('Estado PayPal: '.$status),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al capturar en PayPal: '.$e->getMessage()];
        }
    }

    protected function apiBase(): string
    {
        $mode = strtolower((string) config('payments.paypal.mode', 'sandbox'));

        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
