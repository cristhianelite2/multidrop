<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;

class OrderTrackController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless($store->commerceEnabled(), 404);

        $order = null;
        $error = null;
        $number = trim((string) $request->query('number', $request->input('number', '')));
        $email = strtolower(trim((string) $request->query('email', $request->input('email', ''))));
        $token = trim((string) $request->query('token', ''));

        if ($token !== '') {
            $order = Order::query()
                ->where('store_id', $store->id)
                ->where('access_token', $token)
                ->with(['items.product', 'fulfillments'])
                ->first();
        } elseif ($number !== '' && $email !== '') {
            $order = Order::query()
                ->where('store_id', $store->id)
                ->where('number', strtoupper($number))
                ->where('customer_email', $email)
                ->with(['items.product', 'fulfillments'])
                ->first();
            if (! $order) {
                $error = 'No encontramos un pedido con esos datos.';
            }
        }

        $orderSavings = null;
        if ($order) {
            $priceSave = (float) data_get($order->meta, 'price_save', 0);
            $lineDiscountSave = (float) data_get($order->meta, 'line_discount_save', 0);
            if ($priceSave <= 0 && $lineDiscountSave <= 0) {
                $priceSave = round($order->items->sum(fn ($it) => $it->priceSave()), 2);
                $lineDiscountSave = round($order->items->sum(fn ($it) => $it->discountSave()), 2);
            }
            $couponSave = (float) ($order->discount ?? 0);
            $magicSave = (float) data_get($order->meta, 'magic_discount', 0);
            $discountSave = round($lineDiscountSave + $couponSave + $magicSave, 2);
            $totalSave = round($priceSave + $discountSave, 2);
            $listSubtotal = (float) data_get($order->meta, 'list_subtotal', 0);
            if ($listSubtotal <= 0) {
                $listSubtotal = round($order->items->sum(function ($it) {
                    $b = $it->pricingBreakdown();

                    return ($b['msrp'] ?? $b['list_unit']) * $b['qty'];
                }), 2);
            }
            $orderSavings = [
                'price_save' => $priceSave,
                'discount_save' => $discountSave,
                'line_discount_save' => $lineDiscountSave,
                'coupon_save' => $couponSave,
                'magic_save' => $magicSave,
                'total_save' => $totalSave,
                'list_subtotal' => $listSubtotal,
                'paid_subtotal' => (float) $order->subtotal,
            ];
        }

        return view('storefront.track', compact('store', 'order', 'error', 'number', 'email', 'orderSavings'));
    }

    public function lookup(Request $request, string $slug)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        return redirect()->route('store.order.track', [
            'slug' => $slug,
            'number' => strtoupper($data['number']),
            'email' => $data['email'],
        ]);
    }
}
