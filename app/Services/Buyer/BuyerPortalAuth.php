<?php

namespace App\Services\Buyer;

use App\Models\BuyerAccount;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class BuyerPortalAuth
{
    /**
     * @return array{ok: bool, buyer?: BuyerAccount, error?: string}
     */
    public function loginWithOrder(string $email, string $orderNumber): array
    {
        $email = strtolower(trim($email));
        $orderNumber = strtoupper(trim($orderNumber));
        if ($email === '' || $orderNumber === '') {
            return ['ok' => false, 'error' => 'Email y número de pedido son obligatorios.'];
        }

        $order = Order::query()
            ->with('store')
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->where('number', $orderNumber)
            ->first();

        if (! $order || ! $order->verifyPortalPass($orderNumber)) {
            return ['ok' => false, 'error' => 'No encontramos un pedido con ese email y número.'];
        }

        $buyer = BuyerAccount::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
            ]
        );

        if (! $buyer->name && $order->customer_name) {
            $buyer->name = $order->customer_name;
            $buyer->save();
        }

        Auth::guard('buyer')->login($buyer, true);

        if ($order->store?->slug) {
            session(['buyer_portal_store_slug' => $order->store->slug]);
        }

        return ['ok' => true, 'buyer' => $buyer];
    }

    /**
     * @return array{ok: bool, buyer?: BuyerAccount, error?: string}
     */
    public function loginWithPassword(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $buyer = BuyerAccount::query()->where('email', $email)->first();
        if (! $buyer || ! $buyer->hasPassword() || ! Hash::check($password, $buyer->password)) {
            return ['ok' => false, 'error' => 'Email o contraseña incorrectos.'];
        }

        Auth::guard('buyer')->login($buyer, true);

        return ['ok' => true, 'buyer' => $buyer];
    }

    public function loginFromOrder(Order $order): BuyerAccount
    {
        $order->loadMissing('store');
        $email = strtolower((string) $order->customer_email);
        $buyer = BuyerAccount::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
            ]
        );
        Auth::guard('buyer')->login($buyer, true);

        if ($order->store?->slug) {
            session(['buyer_portal_store_slug' => $order->store->slug]);
        }

        return $buyer;
    }

    /**
     * Login desde pedido sandbox de plantilla (email + TPL-XXXX).
     *
     * @return array{ok: bool, buyer?: BuyerAccount, order?: \App\Models\ThemeSandboxOrder, error?: string}
     */
    public function loginWithSandboxOrder(\App\Models\Theme $theme, string $email, string $orderNumber): array
    {
        $email = strtolower(trim($email));
        $orderNumber = strtoupper(trim($orderNumber));
        if ($email === '' || $orderNumber === '') {
            return ['ok' => false, 'error' => 'Email y número de pedido son obligatorios.'];
        }

        $order = \App\Models\ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('number', $orderNumber)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $order) {
            return ['ok' => false, 'error' => 'No encontramos un pedido sandbox con ese email y número.'];
        }

        $buyer = BuyerAccount::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $order->name,
                'phone' => $order->phone,
            ]
        );

        if (! $buyer->name && $order->name) {
            $buyer->name = $order->name;
            $buyer->phone = $order->phone ?: $buyer->phone;
            $buyer->save();
        }

        Auth::guard('buyer')->login($buyer, true);

        session([
            'theme_sandbox_buyer.'.$theme->id => [
                'email' => $email,
                'number' => $order->number,
                'order_id' => $order->id,
                'name' => $order->name,
                'phone' => $order->phone,
                'at' => now()->timestamp,
            ],
        ]);

        return ['ok' => true, 'buyer' => $buyer, 'order' => $order];
    }

    public function ownsOrder(BuyerAccount $buyer, Order $order): bool
    {
        return strtolower((string) $order->customer_email) === strtolower($buyer->email);
    }
}
