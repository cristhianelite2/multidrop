<?php

namespace App\Domain\Payments\Providers;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoProvider implements PaymentProviderInterface
{
    public function code(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(array $orderPayload): array
    {
        $token = config('payments.mercadopago.access_token');
        if (empty($token)) {
            return ['success' => false, 'error' => 'MERCADOPAGO_ACCESS_TOKEN no configurado'];
        }

        /** @var Order|null $order */
        $order = $orderPayload['order'] ?? null;
        /** @var Store|null $store */
        $store = $orderPayload['store'] ?? null;
        if (! $order instanceof Order) {
            return ['success' => false, 'error' => 'Pedido inválido para Mercado Pago.'];
        }

        $currency     = strtoupper((string) $order->currency);
        $orderTotal   = round((float) ($order->total ?? 0), 2);
        $shipping     = round((float) ($order->shipping ?? 0), 2);
        $couponDisc   = round((float) ($order->discount ?? 0), 2);
        $meta         = is_array($order->meta) ? $order->meta : [];
        $magicDisc    = round((float) ($meta['magic_discount'] ?? 0), 2);
        $comboDisc    = round((float) ($meta['combo_discount'] ?? 0), 2);
        $totalDiscount = round($couponDisc + $magicDisc + $comboDisc, 2);

        // Construir ítems con precios originales
        $rawItems = [];
        $itemsTotal = 0;
        foreach ($order->items as $line) {
            $unitPrice   = round((float) $line->unit_price, 2);
            $qty         = max(1, (int) $line->qty);
            $itemsTotal += $unitPrice * $qty;
            $rawItems[] = [
                'title'       => mb_substr((string) $line->name, 0, 120),
                'quantity'    => $qty,
                'unit_price'  => $unitPrice,
                'currency_id' => $currency,
            ];
        }

        // Reconciliación: calcular descuento efectivo real comparando
        // suma bruta vs total real de la orden
        $computedBruto = round($itemsTotal + $shipping, 2);
        if ($totalDiscount <= 0 && $orderTotal < $computedBruto) {
            $totalDiscount = round($computedBruto - $orderTotal, 2);
        }

        // MercadoPago NO aplica coupon_amount como descuento general — ignora valores
        // arbitrarios. La única forma correcta es reducir unit_price directamente.
        // Distribuimos el descuento proporcionalmente entre los ítems de producto.
        $items = [];
        if ($totalDiscount > 0 && $itemsTotal > 0) {
            $ratio       = 1 - ($totalDiscount / $computedBruto);
            $distributed = 0;
            $lastIdx     = count($rawItems) - 1;
            foreach ($rawItems as $i => $item) {
                if ($i === $lastIdx) {
                    // El último ítem absorbe el resto para evitar decimales acumulados
                    $lineOriginal = round($item['unit_price'] * $item['quantity'], 2);
                    $newLine      = round($orderTotal - $shipping - $distributed, 2);
                    $newUnit      = $item['quantity'] > 1
                        ? round($newLine / $item['quantity'], 2)
                        : $newLine;
                } else {
                    $newUnit      = round($item['unit_price'] * $ratio, 2);
                    $newLine      = round($newUnit * $item['quantity'], 2);
                    $distributed += $newLine;
                }
                $item['unit_price'] = max(0.01, $newUnit);
                $items[] = $item;
            }
        } else {
            $items = $rawItems;
        }

        if ($shipping > 0) {
            $items[] = [
                'title'       => 'Envío',
                'quantity'    => 1,
                'unit_price'  => $shipping,
                'currency_id' => $currency,
            ];
        }

        $returnUrls = $orderPayload['return_urls'] ?? [];
        $successUrl = (string) ($returnUrls['success'] ?? '');
        $failureUrl = (string) ($returnUrls['failure'] ?? $successUrl);
        $pendingUrl = (string) ($returnUrls['pending'] ?? $successUrl);

        // back_urls es requerido con success definido para usar auto_return
        $backUrls = array_filter([
            'success' => $successUrl ?: null,
            'failure' => $failureUrl ?: null,
            'pending' => $pendingUrl ?: null,
        ]);

        $body = [
            'items' => $items,
            'payer' => array_filter([
                'name'  => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone ? ['number' => $order->customer_phone] : null,
            ]),
            'external_reference'   => $order->number,
            'metadata'             => [
                'order_id'     => $order->id,
                'store_id'     => $order->store_id,
                'access_token' => $order->access_token,
            ],
            'notification_url'     => $orderPayload['notification_url'] ?? url('/webhooks/mercadopago'),
            'statement_descriptor' => mb_substr((string) ($store?->name ?: 'Multidrop'), 0, 22),
        ];

        // coupon_code solo se envía si hay cupón real del cliente
        // (coupon_amount arbitrario es ignorado por MercadoPago)
        if ($order->coupon_code) {
            $body['coupon_code'] = (string) $order->coupon_code;
        }

        // solo agrega back_urls y auto_return si success está definido
        if ($successUrl !== '') {
            $body['back_urls'] = $backUrls;
            $body['auto_return'] = 'approved';
        }


        try {
            Log::debug('MercadoPago preference request', ['body' => $body]);

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.mercadopago.com/checkout/preferences', $body);

            $json = $response->json() ?? [];
            if (! $response->successful() || empty($json['id'] ?? null)) {
                Log::warning('MercadoPago preference failed', ['status' => $response->status(), 'body' => $json]);

                return [
                    'success' => false,
                    'error' => $json['message'] ?? ('Mercado Pago no creó la preferencia (HTTP '.$response->status().').'),
                    'raw' => $json,
                ];
            }

            $checkoutUrl = $json['init_point'] ?? $json['sandbox_init_point'] ?? null;

            return [
                'success' => true,
                'provider_ref' => (string) $json['id'],
                'checkout_url' => $checkoutUrl,
                'raw' => $json,
                'provider' => $this->code(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al conectar con Mercado Pago: '.$e->getMessage()];
        }
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        $secret = (string) config('payments.mercadopago.webhook_secret');
        if ($secret === '') {
            return true;
        }

        $sig = $headers['x-signature'] ?? $headers['X-Signature'] ?? null;
        if (is_array($sig)) {
            $sig = $sig[0] ?? '';
        }

        return is_string($sig) && $sig !== '';
    }

    public function parseWebhook(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];
        $type = (string) ($data['type'] ?? $data['action'] ?? '');
        $paymentId = (string) ($data['data']['id'] ?? $data['id'] ?? '');

        return [
            'status' => $type,
            'provider_ref' => $paymentId,
            'raw' => $data,
        ];
    }

    /**
     * @return array{success: bool, status?: string, external_reference?: string, amount?: float, raw?: mixed, error?: string}
     */
    public function fetchPayment(string $paymentId): array
    {
        $token = config('payments.mercadopago.access_token');
        if (empty($token) || $paymentId === '') {
            return ['success' => false, 'error' => 'Sin token o payment id'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get('https://api.mercadopago.com/v1/payments/'.$paymentId);

            $json = $response->json() ?? [];
            if (! $response->successful()) {
                return ['success' => false, 'error' => 'No se pudo leer el pago', 'raw' => $json];
            }

            return [
                'success' => true,
                'status' => (string) ($json['status'] ?? ''),
                'external_reference' => (string) ($json['external_reference'] ?? ''),
                'amount' => (float) ($json['transaction_amount'] ?? 0),
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
