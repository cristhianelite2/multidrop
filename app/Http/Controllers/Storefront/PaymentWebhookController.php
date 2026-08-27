<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Payments\Providers\MercadoPagoProvider;
use App\Domain\Payments\Providers\PayPalProvider;
use App\Domain\Payments\Providers\StripeProvider;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function paypal(Request $request, PayPalProvider $paypal, CheckoutService $checkout): Response
    {
        $rawPayload = $request->getContent() ?: json_encode($request->all());
        $parsed = $paypal->parseWebhook($rawPayload ?: '{}');
        $event = strtoupper((string) ($parsed['status'] ?? ''));
        $resource = data_get($parsed, 'raw.resource', []);

        if (! in_array($event, ['PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED'], true)) {
            return response('ignored', 200);
        }

        $orderNumber = (string) (
            data_get($resource, 'custom_id')
            ?: data_get($resource, 'invoice_id')
            ?: data_get($resource, 'purchase_units.0.custom_id')
            ?: data_get($resource, 'purchase_units.0.invoice_id')
            ?: ''
        );
        if ($orderNumber === '') {
            return response('ok', 200);
        }

        $order = Order::query()->where('number', $orderNumber)->first();
        if (! $order) {
            return response('ok', 200);
        }

        $providerRef = (string) (
            data_get($resource, 'id')
            ?: data_get($resource, 'supplementary_data.related_ids.order_id')
            ?: $order->payment_ref
            ?: ''
        );
        $checkout->markPaid($order, $providerRef, is_array($parsed['raw'] ?? null) ? $parsed['raw'] : []);

        return response('ok', 200);
    }

    public function mercadopago(Request $request, MercadoPagoProvider $mp, CheckoutService $checkout): Response
    {
        $parsed = $mp->parseWebhook($request->getContent() ?: json_encode($request->all()));
        $paymentId = (string) ($parsed['provider_ref'] ?? $request->query('data.id', $request->query('id', '')));
        $topic = (string) ($request->query('topic', $request->query('type', $parsed['status'] ?? '')));

        if ($paymentId === '' || ! in_array($topic, ['payment', 'payments', ''], true) && ! str_contains($topic, 'payment')) {
            if ($paymentId === '') {
                return response('ignored', 200);
            }
        }

        $fetched = $mp->fetchPayment($paymentId);
        if (! ($fetched['success'] ?? false)) {
            Log::info('MP webhook payment fetch failed', $fetched);

            return response('ok', 200);
        }

        $status = strtolower((string) ($fetched['status'] ?? ''));
        $number = (string) ($fetched['external_reference'] ?? '');
        if ($number === '') {
            return response('ok', 200);
        }

        $order = Order::query()->where('number', $number)->first();
        if (! $order) {
            return response('ok', 200);
        }

        if (in_array($status, ['approved', 'paid'], true)) {
            $checkout->markPaid($order, $paymentId, $fetched['raw'] ?? []);
        } elseif (in_array($status, ['rejected', 'cancelled', 'canceled'], true)) {
            $order->payment_status = 'failed';
            $order->status = 'cancelled';
            $order->save();
        } elseif ($status === 'pending' || $status === 'in_process') {
            $order->payment_status = 'pending';
            $order->save();
        }

        return response('ok', 200);
    }

    public function stripe(Request $request, StripeProvider $stripe, CheckoutService $checkout): Response
    {
        $payload   = $request->getContent() ?: '{}';
        $headers   = array_change_key_case($request->headers->all(), CASE_LOWER);

        if (! $stripe->verifyWebhook($headers, $payload)) {
            Log::warning('Stripe webhook signature inválida');
            return response('unauthorized', 401);
        }

        $parsed      = $stripe->parseWebhook($payload);
        $status      = (string) ($parsed['status'] ?? '');
        $providerRef = (string) ($parsed['provider_ref'] ?? '');
        $orderNumber = (string) ($parsed['order_number'] ?? '');

        if ($orderNumber === '' || $providerRef === '') {
            return response('ignored', 200);
        }

        $order = Order::query()->where('number', $orderNumber)->first();
        if (! $order) {
            return response('ok', 200);
        }

        if ($status === 'paid') {
            $checkout->markPaid($order, $providerRef, $parsed['raw'] ?? []);
        } elseif ($status === 'failed') {
            $order->payment_status = 'failed';
            $order->save();
        } elseif ($status === 'refunded') {
            $order->payment_status = 'refunded';
            $order->save();
        }

        return response('ok', 200);
    }
}
