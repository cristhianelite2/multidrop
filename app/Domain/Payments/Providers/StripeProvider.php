<?php

namespace App\Domain\Payments\Providers;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StripeProvider implements PaymentProviderInterface
{
    public function code(): string
    {
        return 'stripe';
    }

    public function createCheckout(array $orderPayload): array
    {
        $secret = (string) \App\Models\PlatformSetting::getValue('payments.stripe.secret')
            ?: (string) config('payments.stripe.secret', '');

        if ($secret === '') {
            return ['success' => false, 'error' => 'STRIPE_SECRET no configurado.'];
        }

        /** @var Order|null $order */
        $order = $orderPayload['order'] ?? null;
        /** @var Store|null $store */
        $store = $orderPayload['store'] ?? null;

        if (! $order instanceof Order) {
            return ['success' => false, 'error' => 'Pedido inválido para Stripe.'];
        }

        $currency    = strtolower((string) $order->currency ?: 'usd');
        $returnUrls  = $orderPayload['return_urls'] ?? [];
        $successUrl  = (string) ($returnUrls['success'] ?? '');
        $cancelUrl   = (string) ($returnUrls['failure'] ?? $returnUrls['pending'] ?? $successUrl);

        if ($successUrl === '') {
            return ['success' => false, 'error' => 'No se pudo generar la URL de retorno para Stripe.'];
        }

        // Stripe Checkout necesita la URL de éxito con {CHECKOUT_SESSION_ID} para referenciar la sesión
        $successUrlWithSession = $successUrl . (str_contains($successUrl, '?') ? '&' : '?')
            . 'stripe_session_id={CHECKOUT_SESSION_ID}';

        // Construir line_items
        $lineItems   = [];
        $orderTotal  = round((float) ($order->total ?? 0), 2);
        $shipping    = round((float) ($order->shipping ?? 0), 2);
        $meta        = is_array($order->meta) ? $order->meta : [];
        $magicDisc   = round((float) ($meta['magic_discount'] ?? 0), 2);
        $comboDisc   = round((float) ($meta['combo_discount'] ?? 0), 2);
        $couponDisc  = round((float) ($order->discount ?? 0), 2);
        $totalDisc   = round($magicDisc + $comboDisc + $couponDisc, 2);

        $itemsTotal  = 0;
        foreach ($order->items as $line) {
            $unitPrice  = round((float) $line->unit_price, 2);
            $qty        = max(1, (int) $line->qty);
            $itemsTotal += $unitPrice * $qty;
        }

        // Distribuir descuento proporcionalmente entre ítems si aplica
        $bruto = round($itemsTotal + $shipping, 2);
        if ($totalDisc <= 0 && $orderTotal < $bruto) {
            $totalDisc = round($bruto - $orderTotal, 2);
        }

        $ratio = ($totalDisc > 0 && $itemsTotal > 0)
            ? (1 - ($totalDisc / $bruto))
            : 1.0;

        foreach ($order->items as $line) {
            $unitPrice = round((float) $line->unit_price * $ratio, 2);
            $unitPrice = max(0.01, $unitPrice);
            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round($unitPrice * 100), // Stripe usa centavos
                    'product_data' => [
                        'name' => mb_substr((string) $line->name, 0, 255),
                    ],
                ],
                'quantity' => max(1, (int) $line->qty),
            ];
        }

        if ($shipping > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round($shipping * 100),
                    'product_data' => ['name' => 'Envío'],
                ],
                'quantity' => 1,
            ];
        }

        $params = [
            'mode'                  => 'payment',
            'success_url'           => $successUrlWithSession,
            'cancel_url'            => $cancelUrl,
            'client_reference_id'   => $order->number,
            'customer_email'        => $order->customer_email,
            'metadata[order_id]'    => $order->id,
            'metadata[store_id]'    => $order->store_id,
            'metadata[access_token]' => $order->access_token,
            'payment_intent_data[metadata][order_number]' => $order->number,
        ];

        foreach ($lineItems as $i => $item) {
            $params["line_items[{$i}][price_data][currency]"]            = $item['price_data']['currency'];
            $params["line_items[{$i}][price_data][unit_amount]"]         = $item['price_data']['unit_amount'];
            $params["line_items[{$i}][price_data][product_data][name]"]  = $item['price_data']['product_data']['name'];
            $params["line_items[{$i}][quantity]"]                        = $item['quantity'];
        }

        try {
            Log::debug('Stripe Checkout Session request', ['params' => $params]);

            $response = Http::withBasicAuth($secret, '')
                ->asForm()
                ->timeout(30)
                ->post('https://api.stripe.com/v1/checkout/sessions', $params);

            $json = $response->json() ?? [];

            if (! $response->successful() || empty($json['id'] ?? null)) {
                Log::warning('Stripe session failed', ['status' => $response->status(), 'body' => $json]);

                return [
                    'success' => false,
                    'error'   => $json['error']['message'] ?? ('Stripe no creó la sesión (HTTP ' . $response->status() . ').'),
                    'raw'     => $json,
                ];
            }

            return [
                'success'      => true,
                'provider_ref' => (string) $json['id'],
                'checkout_url' => $json['url'],
                'raw'          => $json,
                'provider'     => $this->code(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al conectar con Stripe: ' . $e->getMessage()];
        }
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        $secret = (string) config('payments.stripe.webhook_secret', '');
        if ($secret === '') {
            return true;
        }

        $sig = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? null;
        if (is_array($sig)) {
            $sig = $sig[0] ?? '';
        }

        if (! is_string($sig) || $sig === '') {
            return false;
        }

        // Verificación HMAC estándar de Stripe
        preg_match('/t=(\d+)/', $sig, $tMatch);
        preg_match('/v1=([a-f0-9]+)/', $sig, $vMatch);

        if (empty($tMatch[1]) || empty($vMatch[1])) {
            return false;
        }

        $signed  = $tMatch[1] . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $vMatch[1]);
    }

    public function parseWebhook(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];
        $type = (string) ($data['type'] ?? 'unknown');

        $providerRef = (string) data_get($data, 'data.object.id', '');
        $orderNumber = (string) data_get($data, 'data.object.client_reference_id', '')
            ?: (string) data_get($data, 'data.object.metadata.order_number', '');

        $status = match ($type) {
            'checkout.session.completed'          => 'paid',
            'payment_intent.succeeded'            => 'paid',
            'payment_intent.payment_failed'       => 'failed',
            'charge.refunded'                     => 'refunded',
            default                               => $type,
        };

        return [
            'status'           => $status,
            'provider_ref'     => $providerRef,
            'order_number'     => $orderNumber,
            'raw'              => $data,
        ];
    }
}
