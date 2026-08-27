<?php

namespace App\Services\Commerce;

use App\Models\Combo;
use App\Models\Product;
use App\Models\Store;
use App\Services\Storefront\StorefrontProductMapper;

class ComboService
{
    /**
     * @return list<Combo>
     */
    public function activeForStore(Store $store): array
    {
        if (! $store->pluginEnabled('combos')) {
            return [];
        }

        return Combo::query()
            ->with(['items.product', 'product'])
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function comboByStorefrontProduct(Store $store, int $productId): ?Combo
    {
        if ($productId < 1 || ! $store->pluginEnabled('combos')) {
            return null;
        }

        return Combo::query()
            ->with(['items.product'])
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * Líneas a insertar al agregar el producto-vitrina del combo.
     *
     * @return list<array{product_id: int, variant_id: null, qty: int, combo_id: int}>
     */
    public function expansionLines(Combo $combo): array
    {
        $lines = [];
        foreach ($combo->items as $item) {
            $pid = (int) $item->product_id;
            if ($pid < 1) {
                continue;
            }
            $qty = max(1, (int) $item->qty);
            if ($combo->strategy === 'qty' || $combo->strategy === 'both') {
                $qty = max($qty, max(1, (int) $combo->qty_min));
            }
            $lines[] = [
                'product_id' => $pid,
                'variant_id' => null,
                'qty' => $qty,
                'combo_id' => (int) $combo->id,
            ];
        }

        return $lines;
    }

    /**
     * Recalcula precios de combo. Si falta un producto del pack, se disuelve.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, bundle_discount: float, bundle_label: ?string}
     */
    public function applyToHydratedItems(Store $store, array $items): array
    {
        $bundleDiscount = 0.0;
        $bundleLabel = null;
        if (! $store->pluginEnabled('combos') || $items === []) {
            return ['items' => $items, 'bundle_discount' => 0.0, 'bundle_label' => null];
        }

        $combos = $this->activeForStore($store);
        if ($combos === []) {
            return ['items' => $items, 'bundle_discount' => 0.0, 'bundle_label' => null];
        }

        $qtyByProduct = [];
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? $it['id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + max(1, (int) ($it['qty'] ?? 1));
        }

        $appliedComboIds = [];
        foreach ($items as $it) {
            $cid = (int) ($it['combo_id'] ?? 0);
            if ($cid > 0) {
                $appliedComboIds[$cid] = true;
            }
        }

        foreach ($combos as $combo) {
            $complete = $this->comboIsComplete($combo, $qtyByProduct, $appliedComboIds);
            if (! $complete) {
                foreach ($items as &$it) {
                    if ((int) ($it['combo_id'] ?? 0) === (int) $combo->id) {
                        unset($it['combo_id'], $it['combo_badge']);
                    }
                }
                unset($it);

                continue;
            }

            $priced = $this->priceCombo($combo, $items);
            $items = $priced['items'];
            $bundleDiscount += $priced['discount'];
            if ($priced['discount'] > 0) {
                $bundleLabel = $combo->name;
            }
        }

        return [
            'items' => $items,
            'bundle_discount' => round($bundleDiscount, 2),
            'bundle_label' => $bundleLabel,
        ];
    }

    /**
     * @param  array<int, int>  $qtyByProduct
     * @param  array<int, true>  $taggedComboIds
     */
    protected function comboIsComplete(Combo $combo, array $qtyByProduct, array $taggedComboIds): bool
    {
        $itemIds = $combo->items->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($itemIds === []) {
            return false;
        }

        $strategy = (string) $combo->strategy;
        $qtyMin = max(1, (int) $combo->qty_min);
        $tagged = isset($taggedComboIds[(int) $combo->id]);

        if ($strategy === 'qty') {
            $pid = $itemIds[0];
            $have = (int) ($qtyByProduct[$pid] ?? 0);

            return $have >= $qtyMin || ($tagged && $have >= 1);
        }

        if ($strategy === 'pair') {
            foreach ($itemIds as $pid) {
                if ((int) ($qtyByProduct[$pid] ?? 0) < 1) {
                    return false;
                }
            }

            return count($itemIds) >= 2 || $tagged;
        }

        // both
        $first = $itemIds[0];
        if ((int) ($qtyByProduct[$first] ?? 0) < $qtyMin && ! $tagged) {
            return false;
        }
        foreach ($itemIds as $pid) {
            if ((int) ($qtyByProduct[$pid] ?? 0) < 1) {
                return false;
            }
        }

        return count($itemIds) >= 1;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, discount: float}
     */
    protected function priceCombo(Combo $combo, array $items): array
    {
        $memberIds = $combo->items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $indexes = [];
        $listSum = 0.0;
        foreach ($items as $i => $it) {
            $pid = (int) ($it['product_id'] ?? $it['id'] ?? 0);
            if (! in_array($pid, $memberIds, true)) {
                continue;
            }
            $indexes[] = $i;
            $qty = max(1, (int) ($it['qty'] ?? 1));
            $list = (float) ($it['list_unit'] ?? $it['price'] ?? 0);
            $listSum += $list * $qty;
        }

        if ($indexes === [] || $listSum <= 0) {
            return ['items' => $items, 'discount' => 0.0];
        }

        $type = (string) $combo->discount_type;
        $value = max(0, (float) $combo->discount_value);
        if ($type === 'fixed') {
            $special = min($listSum, $value);
            $discount = round(max(0, $listSum - $special), 2);
        } else {
            $pct = min(90, $value);
            $discount = round($listSum * ($pct / 100), 2);
        }

        if ($discount <= 0) {
            return ['items' => $items, 'discount' => 0.0];
        }

        $remaining = $discount;
        $last = count($indexes) - 1;
        foreach ($indexes as $k => $i) {
            $qty = max(1, (int) ($items[$i]['qty'] ?? 1));
            $list = (float) ($items[$i]['list_unit'] ?? $items[$i]['price'] ?? 0);
            $lineList = $list * $qty;
            $share = $k === $last ? $remaining : round($discount * ($lineList / $listSum), 2);
            $remaining = round($remaining - $share, 2);
            $newLine = max(0, round($lineList - $share, 2));
            $unit = $qty > 0 ? round($newLine / $qty, 2) : 0.0;
            $items[$i]['price'] = $unit;
            $items[$i]['price_formatted'] = '$'.number_format($unit, 2);
            $items[$i]['line_total'] = $newLine;
            $items[$i]['compare_at'] = $list > $unit ? $list : ($items[$i]['compare_at'] ?? null);
            $items[$i]['discount_save'] = round(max(0, $list - $unit) * $qty, 2);
            $items[$i]['line_save'] = round((float) ($items[$i]['price_save'] ?? 0) + (float) $items[$i]['discount_save'], 2);
            $items[$i]['combo_id'] = (int) $combo->id;
            $items[$i]['combo_badge'] = $combo->name;
            if (! str_contains((string) $items[$i]['name'], 'Combo')) {
                $items[$i]['name'] = 'Combo · '.$items[$i]['name'];
            }
        }

        return ['items' => $items, 'discount' => $discount];
    }

    /**
     * @return array{compare: float, normal: float, discounted: float, currency: string}|null
     */
    public function priceSummary(Combo $combo, Store $store): ?array
    {
        $currency = $store->currency();
        $normal = 0.0;
        $compare = 0.0;
        foreach ($combo->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $qty = max(1, (int) $item->qty);
            if ($combo->strategy === 'qty' || $combo->strategy === 'both') {
                $qty = max($qty, max(1, (int) $combo->qty_min));
            }
            $quote = $product->quoteIn($currency);
            $unit = (float) $quote['price'];
            $msrp = isset($quote['compare_at_price']) ? (float) $quote['compare_at_price'] : $unit;
            $normal += $unit * $qty;
            $compare += max($msrp, $unit) * $qty;
        }
        if ($normal <= 0) {
            return null;
        }
        $type = (string) $combo->discount_type;
        $value = max(0, (float) $combo->discount_value);
        $discounted = $type === 'fixed'
            ? min($normal, $value)
            : round($normal * (1 - (min(90, $value) / 100)), 2);

        return [
            'compare' => round(max($compare, $normal), 2),
            'normal' => round($normal, 2),
            'discounted' => round($discounted, 2),
            'currency' => $currency,
        ];
    }

    public function syncStorefrontProduct(Combo $combo): ?Product
    {
        $combo->loadMissing(['items.product', 'store']);
        $store = $combo->store;
        if (! $store || ! $combo->publish_as_product) {
            if ($combo->product_id) {
                $linked = Product::query()->find($combo->product_id);
                if ($linked && (int) $linked->store_id === (int) $combo->store_id) {
                    $linked->update(['status' => 'draft']);
                }
            }

            return $combo->product;
        }

        $summary = $this->priceSummary($combo, $store);
        $image = $combo->coverImage();
        $prev = [];
        if ($combo->product_id) {
            $existing = Product::query()->find($combo->product_id);
            $prev = is_array($existing?->creative_data) ? $existing->creative_data : [];
        }
        unset($prev['prices']);
        $creative = array_merge($prev, [
            'is_combo' => true,
            'combo_id' => (int) $combo->id,
            'combo_prices' => $summary,
            'images' => is_array($combo->images) ? $combo->images : [],
        ]);

        $payload = [
            'store_id' => $store->id,
            'name' => $combo->name,
            'slug' => $combo->slug,
            'description' => $this->productDescription($combo, $summary),
            'image_url' => $image,
            'price' => $summary['discounted'] ?? 0,
            'compare_at_price' => $summary['compare'] ?? null,
            'currency' => $store->configuredCurrency(),
            'status' => $combo->is_active ? 'live' : 'draft',
            'badge' => 'Combo',
            'stock' => 99,
            'creative_data' => $creative,
        ];

        if ($combo->product_id) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $combo->product_id)
                ->first();
            if ($product) {
                $product->update($payload);

                return $product->fresh();
            }
        }

        $product = Product::create($payload);
        $combo->product_id = $product->id;
        $combo->save();

        return $product;
    }

    /**
     * @param  array{compare: float, normal: float, discounted: float, currency: string}|null  $summary
     */
    public function productDescription(Combo $combo, ?array $summary): string
    {
        return trim((string) $combo->description);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogProducts(Store $store, StorefrontProductMapper $mapper): array
    {
        $out = [];
        foreach ($this->activeForStore($store) as $combo) {
            if (! $combo->publish_as_product || ! $combo->product) {
                continue;
            }
            $mapped = $mapper->fromProduct($combo->product, $store, [
                'full' => false,
                'url' => route('store.design.page', ['slug' => $store->slug, 'handle' => $combo->product->slug]),
            ]);
            $mapped['is_combo'] = true;
            $mapped['combo_id'] = (int) $combo->id;
            $out[] = $mapped;
        }

        return $out;
    }
}
