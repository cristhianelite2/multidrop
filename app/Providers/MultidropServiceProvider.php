<?php

namespace App\Providers;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\Providers\MercadoPagoProvider;
use App\Domain\Payments\Providers\PayPalProvider;
use App\Domain\Payments\Providers\StripeProvider;
use App\Domain\Suppliers\Cj\CjConnector;
use App\Domain\Suppliers\Contracts\SupplierInterface;
use App\Services\Admin\StoreContext;
use Illuminate\Support\ServiceProvider;

class MultidropServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupplierInterface::class, CjConnector::class);

        $this->app->bind(PaymentProviderInterface::class, function ($app) {
            $gateway = config('payments.default', 'mercadopago');

            try {
                $store = $app->make(StoreContext::class)->current();
                if ($store && $store->paymentsEnabled() && $store->paymentGateway()) {
                    $gateway = $store->paymentGateway();
                }
            } catch (\Throwable) {
                // storefront / CLI without admin session
            }

            return match ($gateway) {
                'stripe' => $app->make(StripeProvider::class),
                'paypal' => $app->make(PayPalProvider::class),
                default => $app->make(MercadoPagoProvider::class),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
