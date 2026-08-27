<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Domain\Payments\Providers\PayPalProvider;
use App\Models\Store;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use App\Services\Security\CheckoutFraudGuard;
use App\Services\Security\TurnstileVerifier;
use App\Services\Storefront\StorefrontVisitorPrefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function place(
        Request $request,
        string $slug,
        CheckoutService $checkout,
        TurnstileVerifier $turnstile,
        CheckoutFraudGuard $fraud
    ): JsonResponse {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless($store->commerceEnabled(), 404);

        try {
            $prefs = app(StorefrontVisitorPrefs::class);
            $prefs->capture($request, $store);
            $prefs->applyOverrides($store);
        } catch (\Throwable) {
        }

        $turnstileToken = (string) $request->input('cf-turnstile-response', '');
        if (! $turnstile->verify($turnstileToken, $request->ip())) {
            $message = 'No se pudo validar el captcha de seguridad. Intenta de nuevo.';
            if (! $turnstile->enabled()) {
                $message = 'Verificación de seguridad no disponible temporalmente. Intenta de nuevo.';
            } elseif ($turnstileToken === '') {
                $message = 'Falta completar el captcha de seguridad antes de pagar.';
            }
            if (app()->environment('local')) {
                $message .= ' En local: usa localhost/127.0.0.1 y activa bypass local o configura claves de prueba de Turnstile.';
            }
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:250'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:500'],
            'newsletter_opt_in' => ['nullable', 'boolean'],
        ]);

        $cart = app(CartService::class)->get($store);
        $fraudCheck = $fraud->check($store, $request, $data['email'], (float) ($cart['totals']['total'] ?? 0));
        if (! ($fraudCheck['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $fraudCheck['error'] ?? 'Pedido bloqueado por seguridad.',
            ], 429);
        }

        $result = $checkout->place($store, $data);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $result['error'] ?? 'No se pudo crear el pedido.',
                'order_number' => $result['order']->number ?? null,
            ], 422);
        }

        /** @var Order $order */
        $order = $result['order'];
        $order->ensurePortalPassHash();

        $newsletterCoupon = null;
        if ($request->boolean('newsletter_opt_in') && $store->pluginEnabled('newsletter')) {
            $nl = app(\App\Services\Commerce\NewsletterService::class)->subscribeFromCheckout($store, $data['email']);
            if ($nl['ok'] ?? false) {
                $newsletterCoupon = $nl['coupon_code'] ?? null;
            }
        }

        return response()->json([
            'ok' => true,
            'order_number' => $order->number,
            'access_token' => $order->access_token,
            'checkout_url' => $result['checkout_url'] ?? null,
            'newsletter_coupon' => $newsletterCoupon,
            'track_url' => route('store.order.track', ['slug' => $store->slug]).'?number='.$order->number.'&email='.urlencode($order->customer_email),
            'message' => $result['checkout_url'] ?? null
                ? 'Redirigiendo a pago…'
                : ($result['error'] ?? 'Pedido creado.'),
        ]);
    }

    public function returned(Request $request, string $slug, string $status, CartService $cart, CheckoutService $checkout, PayPalProvider $paypal)
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        $number = (string) $request->query('order', $request->query('external_reference', ''));
        $order = $number !== ''
            ? Order::query()->where('store_id', $store->id)->where('number', $number)->first()
            : null;

        if ($status === 'success' && $order) {
            $shouldClearCart = true;

            // PayPal: capturar pago
            if ($order->payment_provider === 'paypal' && ! $order->isPaid()) {
                $paypalOrderId = (string) $request->query('token', $order->payment_ref ?? '');
                $captured = $paypal->captureOrder($paypalOrderId);
                if ($captured['success'] ?? false) {
                    $checkout->markPaid($order, (string) ($captured['provider_ref'] ?? $paypalOrderId), $captured['raw'] ?? []);
                    $order->refresh();
                } else {
                    $order->meta = array_merge($order->meta ?? [], [
                        'paypal_capture_error' => $captured['error'] ?? 'No se pudo capturar el pago en PayPal.',
                    ]);
                    $order->save();
                    $status = 'pending';
                    $shouldClearCart = false;
                }
            }

            // Stripe: marcar pagado cuando llega checkout_session.completed
            if ($order->payment_provider === 'stripe' && ! $order->isPaid()) {
                $sessionId = (string) $request->query('stripe_session_id', '');
                if ($sessionId !== '') {
                    $checkout->markPaid($order, $sessionId, [
                        'stripe_session_id' => $sessionId,
                    ]);
                    $order->refresh();
                }
            }

            // MercadoPago: marcar pagado si MP confirma approved
            if ($order->payment_provider === 'mercadopago' && ! $order->isPaid()) {
                $collectionStatus = (string) $request->query('collection_status', '');
                $paymentId        = (string) $request->query('payment_id', $request->query('collection_id', ''));
                if ($collectionStatus === 'approved' && $paymentId !== '') {
                    $checkout->markPaid($order, $paymentId, [
                        'collection_status' => $collectionStatus,
                        'payment_type'      => $request->query('payment_type'),
                        'preference_id'     => $request->query('preference_id'),
                        'merchant_order_id' => $request->query('merchant_order_id'),
                    ]);
                    $order->refresh();
                }
            }

            if ($shouldClearCart) {
                $cart->clear($store);
            }

            // Redirigir a la página de "Gracias"
            return redirect()->route('store.order.track', [
                'slug'  => $store->slug,
                'token' => $order->access_token,
            ]);
        }

        return view('storefront.checkout-return', [
            'store'  => $store,
            'order'  => $order,
            'status' => $status,
        ]);
    }
}
