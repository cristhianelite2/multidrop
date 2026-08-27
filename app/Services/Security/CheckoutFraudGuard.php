<?php

namespace App\Services\Security;

use App\Models\FraudEvent;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

class CheckoutFraudGuard
{
    public function maxOrdersPerHour(): int
    {
        $fromDb = \App\Models\PlatformSetting::getValue('fraud.max_orders_per_hour');
        if ($fromDb !== null && $fromDb !== '') {
            return max(1, (int) $fromDb);
        }

        return max(1, (int) config('multidrop.fraud.max_orders_per_hour', 8));
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function check(Store $store, Request $request, string $email, float $cartTotal): array
    {
        $email = strtolower(trim($email));
        $ip = $request->ip();

        if ($cartTotal <= 0) {
            $this->log('zero_total', $email, $ip, $store->id, ['total' => $cartTotal]);

            return ['ok' => false, 'error' => 'No se puede completar un pedido con total 0.'];
        }

        $limit = $this->maxOrdersPerHour();
        $byEmail = Order::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($byEmail >= $limit) {
            $this->log('velocity_email', $email, $ip, $store->id, ['count' => $byEmail]);

            return ['ok' => false, 'error' => 'Demasiados pedidos recientes con este email. Intenta más tarde.'];
        }

        if ($ip) {
            $byIp = FraudEvent::query()
                ->where('type', 'checkout_attempt')
                ->where('ip', $ip)
                ->where('created_at', '>=', now()->subHour())
                ->count();
            if ($byIp >= ($limit * 2)) {
                $this->log('velocity_ip', $email, $ip, $store->id, ['count' => $byIp]);

                return ['ok' => false, 'error' => 'Demasiados intentos desde esta red. Intenta más tarde.'];
            }
        }

        $this->log('checkout_attempt', $email, $ip, $store->id, ['total' => $cartTotal]);

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function log(string $type, ?string $email, ?string $ip, ?int $storeId, array $meta = []): void
    {
        FraudEvent::create([
            'type' => $type,
            'email' => $email,
            'ip' => $ip,
            'store_id' => $storeId,
            'meta' => $meta,
        ]);
    }
}
