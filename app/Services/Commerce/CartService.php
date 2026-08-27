<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Session;

class CartService
{
    public function __construct(
        protected CouponService $coupons,
        protected ShippingQuoteService $shipping,
        protected CrossSellOfferService $crossSell,
        protected UpsellOfferService $upsell,
        protected ComboService $combos
    ) {}

    public function sessionKey(Store $store): string
    {
        return 'cart.'.$store->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Store $store): array
    {
        $raw = Session::get($this->sessionKey($store), ['items' => [], 'coupon' => null, 'shipping_country' => null]);
        if (! is_array($raw)) {
            $raw = ['items' => [], 'coupon' => null, 'shipping_country' => null];
        }
        $items = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];
        $coupon = isset($raw['coupon']) && is_string($raw['coupon']) ? strtoupper($raw['coupon']) : null;
        $country = isset($raw['shipping_country']) && is_string($raw['shipping_country'])
            ? strtoupper($raw['shipping_country'])
            : null;

        return $this->hydrate($store, $items, $coupon, $country);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function hydrate(Store $store, array $items, ?string $couponCode = null, ?string $shippingCountry = null): array
    {
        $clean = [];
        foreach ($items as $row) {
            $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($productId < 1) {
                continue;
            }
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $productId)
                ->whereIn('status', ['live', 'draft'])
                ->with('variants')
                ->first();
            if (! $product) {
                continue;
            }
            $img = $product->image_url;
            if ($img && str_starts_with((string) $img, '/media/')) {
                $img = asset(ltrim($img, '/'));
            }
            $quote = $product->quoteIn($store->currency());
            $unit = (float) $quote['price'];
            $msrp = isset($quote['compare_at_price']) ? (float) $quote['compare_at_price'] : null;
            if ($msrp !== null && $msrp <= $unit) {
                $msrp = null;
            }
            $listUnit = $unit;
            $isCombo = ! empty($row['upsell_combo']);
            $comboPct = $isCombo ? max(1, min(80, (float) ($row['upsell_percent'] ?? 20))) : 0.0;
            $displayName = $product->localizedName();
            if ($isCombo && $comboPct > 0) {
                $unit = round($listUnit * (1 - ($comboPct / 100)), 2);
                if (! str_starts_with($displayName, 'Combo · ')) {
                    $displayName = 'Combo · '.$displayName;
                }
            }
            $vid = '';
            foreach ($product->variants as $variant) {
                $cand = (string) data_get($variant->options, 'vid', '');
                if ($cand !== '') {
                    $vid = $cand;
                    break;
                }
            }
            if ($vid === '') {
                $vid = (string) data_get($product->verified_data, 'vid', '');
            }
            $lineTotal = round($unit * $qty, 2);
            $priceSave = ($msrp !== null && $msrp > $listUnit)
                ? round(($msrp - $listUnit) * $qty, 2)
                : 0.0;
            $discountSave = ($listUnit > $unit)
                ? round(($listUnit - $unit) * $qty, 2)
                : 0.0;
            $compareAt = $msrp ?? ($listUnit > $unit ? $listUnit : null);
            $compareLine = $compareAt !== null ? round($compareAt * $qty, 2) : null;
            $clean[] = [
                'product_id' => $product->id,
                'id' => $product->id,
                'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
                'vid' => $vid !== '' ? $vid : null,
                'name' => $displayName,
                'slug' => $product->slug,
                'price' => $unit,
                'price_formatted' => '$'.number_format($unit, 2),
                'msrp' => $msrp,
                'list_unit' => $listUnit,
                'compare_at' => $compareAt,
                'compare_line_total' => $compareLine,
                'price_save' => $priceSave,
                'discount_save' => $discountSave,
                'line_save' => round($priceSave + $discountSave, 2),
                'currency' => $quote['currency'],
                'qty' => $qty,
                'line_total' => $lineTotal,
                'image' => $img,
                'url' => route('store.design.page', ['slug' => $store->slug, 'handle' => $product->slug]),
                'cross_sell_magic' => ! empty($row['cross_sell_magic']),
                'upsell_combo' => $isCombo,
                'upsell_percent' => $isCombo ? $comboPct : null,
                'combo_id' => isset($row['combo_id']) ? (int) $row['combo_id'] : null,
            ];
        }

        $crossCfg = $this->crossSell->forStore($store);
        $clean = array_map(
            fn (array $item) => $this->crossSell->enrichCartItem($item, $crossCfg),
            $clean
        );

        $bundle = $this->combos->applyToHydratedItems($store, $clean);
        $clean = $bundle['items'];
        $bundleDiscount = (float) ($bundle['bundle_discount'] ?? 0);
        $bundleLabel = $bundle['bundle_label'] ?? null;

        $subtotal = round(array_sum(array_column($clean, 'line_total')), 2);
        $comboDiscount = 0.0;
        $listSubtotal = 0.0;
        $comboPct = null;
        foreach ($clean as $it) {
            $qty = max(1, (int) ($it['qty'] ?? 1));
            $unit = (float) ($it['price'] ?? 0);
            $compare = isset($it['compare_at']) ? (float) $it['compare_at'] : 0.0;
            $listUnit = ($compare > $unit) ? $compare : $unit;
            $listSubtotal += round($listUnit * $qty, 2);
            if (! empty($it['upsell_combo']) && $listUnit > $unit) {
                $comboDiscount += round(($listUnit - $unit) * $qty, 2);
                $comboPct = (float) ($it['upsell_percent'] ?? 20);
            }
        }
        $listSubtotal = round($listSubtotal, 2);
        $comboDiscount = round($comboDiscount, 2);
        $discount = 0.0;
        $couponPayload = null;
        if ($couponCode) {
            $applied = $this->coupons->preview($store, $couponCode, $subtotal);
            if ($applied['ok'] ?? false) {
                $discount = (float) $applied['discount'];
                $couponPayload = [
                    'code' => $applied['code'],
                    'type' => $applied['type'],
                    'value' => $applied['value'],
                    'message' => $applied['message'],
                ];
            } else {
                $couponCode = null;
            }
        }

        $magicDiscount = $this->crossSell->computeMagicDiscount($clean, $crossCfg);

        $shippingAmount = 0.0;
        $shippingInfo = null;
        if ($shippingCountry && $clean !== []) {
            $quote = $this->shipping->quote($store, $shippingCountry, $clean);
            $shippingAmount = (float) ($quote['amount'] ?? 0);
            $shippingInfo = [
                'country' => $quote['country'] ?? $shippingCountry,
                'amount' => $shippingAmount,
                'label' => $quote['label'] ?? __('storefront.checkout.shipping'),
                'source' => $quote['source'] ?? 'table',
                'eta' => $quote['eta'] ?? null,
                'eta_label' => $quote['eta_label'] ?? null,
            ];
            $shippingCountry = $quote['country'] ?? $shippingCountry;
        }

        $tax = 0.0;
        $total = max(0, round($subtotal - $discount - $magicDiscount + $shippingAmount + $tax, 2));

        return [
            'items' => $clean,
            'coupon' => $couponCode,
            'coupon_info' => $couponPayload,
            'shipping_country' => $shippingCountry,
            'shipping_info' => $shippingInfo,
            'cross_sell' => [
                'magic_discount' => $magicDiscount,
                'label' => $crossCfg['badge'] ?? __('storefront.checkout.magic'),
                'hint' => $crossCfg['hint_display'] ?? null,
                'offer' => $crossCfg,
                'products' => ($store->pluginEnabled('cross_sell') && ($crossCfg['enabled'] ?? true))
                    ? $this->crossSell->suggestedProducts($store, $clean, $crossCfg)
                    : [],
            ],
            'count' => array_sum(array_column($clean, 'qty')),
            'totals' => [
                'subtotal' => $subtotal,
                'subtotal_list' => $listSubtotal > 0 ? $listSubtotal : $subtotal,
                'combo_discount' => $comboDiscount,
                'combo_percent' => $comboPct,
                'bundle_discount' => $bundleDiscount,
                'bundle_label' => $bundleLabel,
                'discount' => $discount,
                'magic_discount' => $magicDiscount,
                'shipping' => $shippingAmount,
                'tax' => $tax,
                'total' => $total,
                'currency' => $store->currency(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(Store $store, int $productId, int $qty = 1, ?int $variantId = null): array
    {
        $pack = $this->combos->comboByStorefrontProduct($store, $productId);
        if ($pack) {
            return $this->addComboPack($store, $pack);
        }

        $cart = $this->get($store);
        $qty = max(1, $qty);
        $found = false;
        foreach ($cart['items'] as &$item) {
            if ((int) $item['product_id'] === $productId && (int) ($item['variant_id'] ?? 0) === (int) ($variantId ?? 0)) {
                $item['qty'] = (int) $item['qty'] + $qty;
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            $cart['items'][] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty' => $qty,
            ];
        }

        return $this->put($store, $cart['items'], $cart['coupon'], $cart['shipping_country'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function addComboPack(Store $store, \App\Models\Combo $combo): array
    {
        $cart = $this->get($store);
        $items = $cart['items'];
        foreach ($this->combos->expansionLines($combo) as $line) {
            $found = false;
            foreach ($items as &$item) {
                if ((int) $item['product_id'] === (int) $line['product_id'] && (int) ($item['variant_id'] ?? 0) === 0) {
                    $item['qty'] = (int) ($item['qty'] ?? 1) + (int) $line['qty'];
                    $item['combo_id'] = (int) $combo->id;
                    $found = true;
                    break;
                }
            }
            unset($item);
            if (! $found) {
                $items[] = $line;
            }
        }

        return $this->put($store, $items, $cart['coupon'], $cart['shipping_country'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQtyByIndex(Store $store, int $index, int $qty): array
    {
        $key = $this->sessionKey($store);
        $raw = Session::get($key, ['items' => [], 'coupon' => null, 'shipping_country' => null]);
        if (! is_array($raw)) {
            $raw = ['items' => [], 'coupon' => null, 'shipping_country' => null];
        }
        $items = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];
        if (! isset($items[$index]) || ! is_array($items[$index])) {
            return $this->get($store);
        }
        if ($qty <= 0) {
            return $this->removeByIndex($store, $index);
        }
        $items[$index]['qty'] = $qty;

        return $this->put($store, $items, $raw['coupon'] ?? null, $raw['shipping_country'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeByIndex(Store $store, int $index): array
    {
        $key = $this->sessionKey($store);
        $raw = Session::get($key, ['items' => [], 'coupon' => null, 'shipping_country' => null]);
        if (! is_array($raw)) {
            $raw = ['items' => [], 'coupon' => null, 'shipping_country' => null];
        }
        $items = is_array($raw['items'] ?? null) ? array_values($raw['items']) : [];
        if (! isset($items[$index]) || ! is_array($items[$index])) {
            return $this->get($store);
        }
        $comboId = (int) ($items[$index]['combo_id'] ?? 0) ?: null;
        array_splice($items, $index, 1);
        if ($comboId) {
            foreach ($items as &$item) {
                if ((int) ($item['combo_id'] ?? 0) === $comboId) {
                    unset($item['combo_id'], $item['combo_badge']);
                }
            }
            unset($item);
        }

        return $this->put($store, $items, $raw['coupon'] ?? null, $raw['shipping_country'] ?? null);
    }

    /**
     * @return array{ok: bool, cart: array<string, mixed>, message?: string}
     */
    public function addMagicCrossSell(Store $store, int $productId, int $qty = 1): array
    {
        $cfg = $this->crossSell->forStore($store);
        if (! ($cfg['enabled'] ?? true)) {
            return ['ok' => false, 'message' => 'Cross-sell no disponible.', 'cart' => $this->get($store)];
        }
        $cart = $this->get($store);
        $qty = max(1, $qty);
        $found = false;
        foreach ($cart['items'] as &$item) {
            if ((int) $item['product_id'] === $productId) {
                $item['qty'] = (int) $item['qty'] + $qty;
                $item['cross_sell_magic'] = true;
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            $cart['items'][] = [
                'product_id' => $productId,
                'variant_id' => null,
                'qty' => $qty,
                'cross_sell_magic' => true,
            ];
        }
        $cart = $this->put($store, $cart['items'], $cart['coupon'], $cart['shipping_country'] ?? null);

        return [
            'ok' => true,
            'message' => 'Agregado con '.$cfg['extra_discount_label'].' mágico extra',
            'cart' => $cart,
        ];
    }

    /**
     * Agrega el producto del combo upsell con descuento.
     *
     * @return array{ok: bool, cart: array<string, mixed>, message?: string}
     */
    public function addUpsellCombo(Store $store, ?int $productId = null): array
    {
        if (! $store->pluginEnabled('upsell')) {
            return ['ok' => false, 'message' => 'Upsell no activo.', 'cart' => $this->get($store)];
        }
        $cart = $this->get($store);
        $offer = $this->upsell->forStore($store, $cart['items'] ?? []);
        $productId = $productId ?: (int) ($offer['offer_product_id'] ?? 0);
        if ($productId < 1 || ! $this->upsell->product($store, $productId)) {
            return ['ok' => false, 'message' => 'No hay producto para el combo.', 'cart' => $cart];
        }
        $pct = max(1, min(80, (float) ($offer['discount_percent'] ?? 20)));
        $found = false;
        foreach ($cart['items'] as &$item) {
            if ((int) $item['product_id'] === $productId) {
                $item['qty'] = (int) ($item['qty'] ?? 1) + 1;
                $item['upsell_combo'] = true;
                $item['upsell_percent'] = $pct;
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            $cart['items'][] = [
                'product_id' => $productId,
                'variant_id' => null,
                'qty' => 1,
                'upsell_combo' => true,
                'upsell_percent' => $pct,
            ];
        }
        $cart = $this->put($store, $cart['items'], $cart['coupon'], $cart['shipping_country'] ?? null);

        return [
            'ok' => true,
            'message' => 'Combo agregado al carrito (−'.$this->upsell->pctLabel($pct).').',
            'cart' => $cart,
            'redirect' => route('store.design.page', ['slug' => $store->slug, 'handle' => 'cart']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateQty(Store $store, int $productId, int $qty, ?int $variantId = null): array
    {
        $cart = $this->get($store);
        $items = [];
        foreach ($cart['items'] as $item) {
            if ((int) $item['product_id'] === $productId && (int) ($item['variant_id'] ?? 0) === (int) ($variantId ?? 0)) {
                if ($qty > 0) {
                    $item['qty'] = $qty;
                    $items[] = $item;
                }
            } else {
                $items[] = $item;
            }
        }

        return $this->put($store, $items, $cart['coupon'], $cart['shipping_country'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(Store $store, int $productId, ?int $variantId = null): array
    {
        $cart = $this->get($store);
        $comboId = null;
        $items = [];
        $removed = false;
        foreach ($cart['items'] as $item) {
            $pidMatch = (int) $item['product_id'] === $productId;
            $itemVid = (int) ($item['variant_id'] ?? 0);
            $wantVid = (int) ($variantId ?? 0);
            $vidMatch = $wantVid < 1 ? true : $itemVid === $wantVid;
            if ($pidMatch && $vidMatch && ! $removed) {
                $comboId = (int) ($item['combo_id'] ?? 0) ?: null;
                $removed = true;
                continue;
            }
            $items[] = $item;
        }
        if ($comboId) {
            foreach ($items as &$item) {
                if ((int) ($item['combo_id'] ?? 0) === $comboId) {
                    unset($item['combo_id'], $item['combo_badge']);
                }
            }
            unset($item);
        }

        return $this->put($store, $items, $cart['coupon'], $cart['shipping_country'] ?? null);
    }

    /**
     * @return array{ok: bool, cart: array<string, mixed>, message?: string}
     */
    public function applyCoupon(Store $store, string $code): array
    {
        $cart = $this->get($store);
        $preview = $this->coupons->preview($store, $code, (float) $cart['totals']['subtotal']);
        if (! ($preview['ok'] ?? false)) {
            return ['ok' => false, 'message' => $preview['message'] ?? 'Cupón no válido', 'cart' => $cart];
        }
        $cart = $this->put($store, $cart['items'], $preview['code'], $cart['shipping_country'] ?? null);

        return ['ok' => true, 'message' => $preview['message'], 'cart' => $cart];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearCoupon(Store $store): array
    {
        $cart = $this->get($store);

        return $this->put($store, $cart['items'], null, $cart['shipping_country'] ?? null);
    }

    /**
     * @return array{ok: bool, cart: array<string, mixed>, message?: string, quote?: array<string, mixed>}
     */
    public function setShippingCountry(Store $store, string $country): array
    {
        $country = strtoupper(trim($country));
        if ($country === 'UK') {
            $country = 'GB';
        }
        if ($country === '') {
            return ['ok' => false, 'message' => 'Selecciona un país.', 'cart' => $this->get($store)];
        }
        if (! $store->servesCountry($country)) {
            return ['ok' => false, 'message' => 'Este país no está disponible para envío.', 'cart' => $this->get($store)];
        }
        $cart = $this->get($store);
        $cart = $this->put($store, $cart['items'], $cart['coupon'], $country);

        return [
            'ok' => true,
            'message' => $cart['shipping_info']['label'] ?? 'Envío actualizado',
            'cart' => $cart,
            'quote' => $cart['shipping_info'],
        ];
    }

    public function clear(Store $store): void
    {
        Session::forget($this->sessionKey($store));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function put(Store $store, array $items, ?string $coupon, ?string $shippingCountry = null): array
    {
        $hydrated = $this->hydrate($store, $items, $coupon, $shippingCountry);
        Session::put($this->sessionKey($store), [
            'items' => array_map(fn ($it) => [
                'product_id' => $it['product_id'],
                'variant_id' => $it['variant_id'] ?? null,
                'qty' => $it['qty'],
                'cross_sell_magic' => ! empty($it['cross_sell_magic']),
                'upsell_combo' => ! empty($it['upsell_combo']),
                'upsell_percent' => ! empty($it['upsell_combo']) ? (float) ($it['upsell_percent'] ?? 20) : null,
                'combo_id' => ! empty($it['combo_id']) ? (int) $it['combo_id'] : null,
            ], $hydrated['items']),
            'coupon' => $hydrated['coupon'],
            'shipping_country' => $hydrated['shipping_country'],
        ]);

        return $hydrated;
    }
}
