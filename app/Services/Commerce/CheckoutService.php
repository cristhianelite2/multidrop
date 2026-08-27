<?php

namespace App\Services\Commerce;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\Providers\MercadoPagoProvider;
use App\Domain\Payments\Providers\PayPalProvider;
use App\Domain\Payments\Providers\StripeProvider;
use App\Jobs\FulfillOrderWithCjJob;
use App\Mail\OrderReceivedMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckoutService
{
    public function __construct(
        protected CartService $cart,
        protected CouponService $coupons
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, order?: Order, checkout_url?: string, error?: string}
     */
    public function place(Store $store, array $input): array
    {
        if (! $store->commerceEnabled()) {
            return ['ok' => false, 'error' => 'El comercio no está habilitado en esta tienda.'];
        }

        $cart = $this->cart->get($store);
        if (($cart['items'] ?? []) === []) {
            return ['ok' => false, 'error' => 'Tu carrito está vacío.'];
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($email === '' || $name === '') {
            return ['ok' => false, 'error' => 'Nombre y email son obligatorios.'];
        }

        $address = [
            'name' => $name,
            'phone' => trim((string) ($input['phone'] ?? '')),
            'address' => trim((string) ($input['address'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'state' => trim((string) ($input['state'] ?? '')),
            'zip' => trim((string) ($input['zip'] ?? '')),
            'country' => strtoupper(trim((string) ($input['country'] ?? $store->market?->code ?? 'MX'))),
        ];

        if ($address['address'] === '' || $address['city'] === '') {
            return ['ok' => false, 'error' => 'Dirección y ciudad son obligatorias.'];
        }

        $order = DB::transaction(function () use ($store, $cart, $email, $name, $address, $input) {
            $customer = Customer::query()->firstOrCreate(
                ['store_id' => $store->id, 'email' => $email],
                [
                    'name' => $name,
                    'phone' => $address['phone'] ?: null,
                    'shipping_address' => $address,
                ]
            );
            $customer->fill([
                'name' => $name,
                'phone' => $address['phone'] ?: $customer->phone,
                'shipping_address' => $address,
            ])->save();

            $order = Order::create([
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'customer_email' => $email,
                'customer_name' => $name,
                'customer_phone' => $address['phone'] ?: null,
                'market_id' => $store->market_id,
                'status' => 'pending',
                'payment_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'payment_provider' => $store->paymentGateway(),
                'currency' => $cart['totals']['currency'] ?? $store->currency(),
                'subtotal' => $cart['totals']['subtotal'],
                'discount' => $cart['totals']['discount'],
                'shipping' => $cart['totals']['shipping'],
                'tax' => $cart['totals']['tax'],
                'total' => $cart['totals']['total'],
                'coupon_code' => $cart['coupon'],
                'shipping_address' => $address,
                'meta' => [
                    'notes' => $input['notes'] ?? null,
                    'list_subtotal' => $cart['totals']['subtotal_list'] ?? null,
                    'combo_discount' => $cart['totals']['combo_discount'] ?? 0,
                    'magic_discount' => $cart['totals']['magic_discount'] ?? 0,
                    'price_save' => round(array_sum(array_map(
                        fn ($it) => (float) ($it['price_save'] ?? 0),
                        $cart['items'] ?? []
                    )), 2),
                    'line_discount_save' => round(array_sum(array_map(
                        fn ($it) => (float) ($it['discount_save'] ?? 0),
                        $cart['items'] ?? []
                    )), 2),
                ],
            ]);

            foreach ($cart['items'] as $line) {
                $product = Product::query()->with('variants')->find($line['product_id']);
                $qty = max(1, (int) ($line['qty'] ?? 1));
                $unit = (float) ($line['price'] ?? 0);
                $listUnit = (float) ($line['list_unit'] ?? $unit);
                $msrp = isset($line['msrp']) ? (float) $line['msrp'] : null;
                $lineTotal = (float) ($line['line_total'] ?? ($unit * $qty));
                $priceSave = (float) ($line['price_save'] ?? 0);
                $discountSave = (float) ($line['discount_save'] ?? 0);
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['variant_id'] ?? null,
                    'line_type' => ! empty($line['upsell_combo']) ? 'upsell' : (! empty($line['cross_sell_magic']) ? 'cross_sell' : 'primary'),
                    'name' => $line['name'],
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'unit_cost' => $product?->variants->first()?->cost,
                    'total' => $lineTotal,
                    'meta' => [
                        'slug' => $line['slug'] ?? null,
                        'image' => $line['image'] ?? null,
                        'msrp' => $msrp,
                        'list_unit' => $listUnit,
                        'compare_at' => $line['compare_at'] ?? $msrp ?? ($listUnit > $unit ? $listUnit : null),
                        'compare_line_total' => $line['compare_line_total'] ?? null,
                        'price_save' => $priceSave,
                        'discount_save' => $discountSave,
                        'line_save' => round($priceSave + $discountSave, 2),
                        'upsell_combo' => ! empty($line['upsell_combo']),
                        'upsell_percent' => $line['upsell_percent'] ?? null,
                        'cross_sell_magic' => ! empty($line['cross_sell_magic']),
                    ],
                ]);
            }

            return $order->load('items');
        });

        $this->sendOrderReceivedMail($store, $order);

        if (! $store->paymentsEnabled() || ! $store->paymentGateway()) {
            return [
                'ok' => true,
                'order' => $order,
                'error' => 'Pedido creado, pero los pagos no están habilitados. Contacta a la tienda.',
            ];
        }

        $provider = $this->providerFor($store);
        $returnSuccess = $this->publicPaymentUrl(
            route('store.checkout.return', ['slug' => $store->slug, 'status' => 'success', 'order' => $order->number])
        );
        $returnFailure = $this->publicPaymentUrl(
            route('store.checkout.return', ['slug' => $store->slug, 'status' => 'failure', 'order' => $order->number])
        );
        $returnPending = $this->publicPaymentUrl(
            route('store.checkout.return', ['slug' => $store->slug, 'status' => 'pending', 'order' => $order->number])
        );
        $notificationUrl = $this->publicPaymentUrl(url('/webhooks/'.$provider->code()));
        $result = $provider->createCheckout([
            'order' => $order,
            'store' => $store,
            'return_urls' => [
                'success' => $returnSuccess,
                'failure' => $returnFailure,
                'pending' => $returnPending,
            ],
            'notification_url' => $notificationUrl,
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'ok' => false,
                'order' => $order,
                'error' => $result['error'] ?? 'No se pudo iniciar el pago.',
            ];
        }

        if (! empty($result['provider_ref'])) {
            $order->payment_ref = $result['provider_ref'];
            $order->save();
            Payment::create([
                'order_id' => $order->id,
                'provider' => $provider->code(),
                'provider_ref' => $result['provider_ref'],
                'status' => 'pending',
                'amount' => $order->total,
                'currency' => $order->currency,
                'raw' => $result,
            ]);
        }

        $this->cart->clear($store);

        return [
            'ok' => true,
            'order' => $order,
            'checkout_url' => $result['checkout_url'] ?? null,
        ];
    }

    public function markPaid(Order $order, string $providerRef, array $raw = []): void
    {
        if ($order->isPaid()) {
            return;
        }

        DB::transaction(function () use ($order, $providerRef, $raw) {
            $order->payment_status = 'paid';
            $order->status = 'paid';
            $order->payment_ref = $providerRef ?: $order->payment_ref;
            $order->save();

            Payment::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'provider' => $order->payment_provider ?: 'mercadopago',
                    'provider_ref' => $providerRef ?: ($order->payment_ref ?: 'paid'),
                ],
                [
                    'status' => 'paid',
                    'amount' => $order->total,
                    'currency' => $order->currency,
                    'raw' => $raw ?: null,
                ]
            );

            if ($order->coupon_code) {
                $this->coupons->redeem($order->store, $order->coupon_code);
            }
        });

        FulfillOrderWithCjJob::dispatch($order->id);
    }

    protected function sendOrderReceivedMail(Store $store, Order $order): void
    {
        try {
            $order->ensurePortalPassHash();
            $trackUrl = route('store.order.track', [
                'slug' => $store->slug,
                'number' => $order->number,
                'email' => $order->customer_email,
            ]);
            $portalUrl = $order->access_token
                ? route('buyer.track.enter', ['slug' => $store->slug, 'token' => $order->access_token])
                : '';
            Mail::to($order->customer_email)->send(new OrderReceivedMail($store, $order, $trackUrl, $portalUrl));
        } catch (\Throwable $e) {
            Log::warning('order.received_mail_failed', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function providerFor(Store $store): PaymentProviderInterface
    {
        return match ($store->paymentGateway()) {
            'stripe' => app(StripeProvider::class),
            'paypal' => app(PayPalProvider::class),
            default => app(MercadoPagoProvider::class),
        };
    }

    protected function publicPaymentUrl(string $generatedUrl): string
    {
        $publicBase = rtrim((string) config('payments.public_base_url', ''), '/');
        if ($publicBase === '') {
            return $generatedUrl;
        }

        $parts = parse_url($generatedUrl);
        if ($parts === false) {
            return $generatedUrl;
        }

        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? ('?'.$parts['query']) : '';
        $fragment = isset($parts['fragment']) ? ('#'.$parts['fragment']) : '';

        return $publicBase.$path.$query.$fragment;
    }
}
