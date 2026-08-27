<?php

namespace App\Services\Commerce;

use App\Models\Coupon;
use App\Models\Store;
use App\Services\Currency\CurrencyService;

class CouponService
{
    /**
     * @return array{ok: bool, code?: string, type?: string, value?: float, discount?: float, message: string}
     */
    public function preview(Store $store, string $code, float $subtotal): array
    {
        $coupon = $this->findUsable($store, $code);
        if (! $coupon) {
            return ['ok' => false, 'message' => 'Cupón no válido'];
        }

        $minSubtotal = $this->amountInVisitorCurrency($store, (float) ($coupon->min_subtotal ?? 0));
        if ($coupon->min_subtotal !== null && $subtotal < $minSubtotal) {
            return [
                'ok' => false,
                'message' => 'Mínimo $'.number_format($minSubtotal, 0).' '.$store->currency(),
            ];
        }

        $discount = $this->computeDiscount($coupon, $subtotal, $store);
        $displayValue = $coupon->type === 'percent'
            ? (float) $coupon->value
            : $this->amountInVisitorCurrency($store, (float) $coupon->value);

        return [
            'ok' => true,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => $displayValue,
            'discount' => $discount,
            'message' => $coupon->type === 'percent'
                ? '-'.(float) $coupon->value.'% aplicado'
                : '-$'.number_format($displayValue, 0).' '.$store->currency(),
        ];
    }

    public function findUsable(Store $store, string $code): ?Coupon
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->where('store_id', $store->id)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $coupon) {
            return null;
        }
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return null;
        }
        if ($coupon->ends_at && now()->gt($coupon->ends_at)) {
            return null;
        }
        if ($coupon->max_redemptions !== null && (int) $coupon->redemptions_count >= (int) $coupon->max_redemptions) {
            return null;
        }

        return $coupon;
    }

    public function computeDiscount(Coupon $coupon, float $subtotal, ?Store $store = null): float
    {
        if ($coupon->type === 'percent') {
            return round($subtotal * ((float) $coupon->value / 100), 2);
        }

        $value = (float) $coupon->value;
        if ($store instanceof Store) {
            $value = $this->amountInVisitorCurrency($store, $value);
        }

        return min($subtotal, round($value, 2));
    }

    /**
     * Cupones fixed / min_subtotal se configuran en la moneda default de la tienda
     * y se convierten a la moneda del visitante con CurrencyService.
     */
    public function amountInVisitorCurrency(Store $store, float $amount): float
    {
        $from = $store->configuredCurrency();
        $to = $store->currency();
        if ($from === $to || $amount == 0.0) {
            return round($amount, 2);
        }

        return app(CurrencyService::class)->convert($amount, $from, $to);
    }

    public function redeem(Store $store, ?string $code): void
    {
        if (! $code) {
            return;
        }
        $coupon = $this->findUsable($store, $code);
        if (! $coupon) {
            return;
        }
        $coupon->increment('redemptions_count');
    }
}
