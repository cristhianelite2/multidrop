<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\UpsellRule;

class UpsellOfferService
{
    /**
     * Oferta de combo para la mini-tienda (reglas o fallback al estrella).
     *
     * @param  list<array<string, mixed>>  $cartItems
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $cartItems = []): array
    {
        $inCart = [];
        foreach ($cartItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
            if ($id > 0) {
                $inCart[$id] = true;
            }
        }

        $star = $store->starProduct();
        $starId = (int) ($star?->id ?: ($store->starProductId() ?? 0));
        $discount = 20.0;
        $offer = null;
        $fromRule = false;

        $rules = UpsellRule::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();

        $picked = $rules->first(function (UpsellRule $rule) use ($inCart) {
            $trigger = (int) $rule->trigger_product_id;
            $offerId = (int) $rule->offer_product_id;

            return $trigger > 0 && $offerId > 0 && isset($inCart[$trigger]) && empty($inCart[$offerId]);
        });
        if (! $picked) {
            $picked = $rules->first(function (UpsellRule $rule) use ($starId, $inCart) {
                $offerId = (int) $rule->offer_product_id;
                if ($offerId < 1 || isset($inCart[$offerId])) {
                    return false;
                }

                return $starId < 1 || (int) $rule->trigger_product_id === $starId;
            });
        }
        if (! $picked) {
            $picked = $rules->first(function (UpsellRule $rule) use ($inCart) {
                $offerId = (int) $rule->offer_product_id;

                return $offerId > 0 && empty($inCart[$offerId]);
            });
        }

        if ($picked) {
            $offer = $this->product($store, (int) $picked->offer_product_id);
            $discount = max(1, min(80, (float) $picked->discount_percent));
            $fromRule = $offer !== null;
        }

        if ($offer === null) {
            if ($star && empty($inCart[$starId])) {
                $offer = $star;
            } else {
                $offer = Product::query()
                    ->where('store_id', $store->id)
                    ->whereIn('status', ['live', 'draft'])
                    ->when($inCart !== [], fn ($q) => $q->whereNotIn('id', array_keys($inCart)))
                    ->when($starId > 0, fn ($q) => $q->where('id', '!=', $starId))
                    ->orderByDesc('is_featured')
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if ($offer === null) {
            $pctLabel = $this->pctLabel($discount);

            return [
                'enabled' => true,
                'discount_percent' => $discount,
                'headline' => __('storefront.upsell.headline'),
                'copy' => __('storefront.upsell.copy_generic', ['pct' => $pctLabel]),
                'cta' => __('storefront.upsell.cta', ['pct' => $pctLabel]),
                'star_product_id' => $starId ?: null,
                'offer_product_id' => null,
                'offer_product' => null,
            ];
        }

        $quote = $offer->quoteIn($store->currency());
        $listPrice = (float) $quote['price'];
        $compare = isset($quote['compare_at_price']) ? (float) $quote['compare_at_price'] : 0.0;
        $isCombo = (bool) data_get($offer->creative_data, 'is_combo');
        if ($isCombo && $compare > $listPrice) {
            $salePrice = $listPrice;
            $discount = $compare > 0 ? round((1 - ($salePrice / $compare)) * 100) : $discount;
            $listPrice = $compare;
        } else {
            $salePrice = round($listPrice * (1 - ($discount / 100)), 2);
        }
        $isStarOffer = $starId > 0 && (int) $offer->id === $starId;

        $pctLabel = $this->pctLabel($discount);
        $copy = $fromRule
            ? __('storefront.upsell.copy_add_on', ['pct' => $pctLabel])
            : ($isStarOffer
                ? __('storefront.upsell.copy_star', ['pct' => $pctLabel])
                : __('storefront.upsell.copy_add_on', ['pct' => $pctLabel]));

        return [
            'enabled' => true,
            'discount_percent' => $discount,
            'headline' => $isStarOffer ? __('storefront.upsell.headline_star') : __('storefront.upsell.headline'),
            'copy' => $copy,
            'cta' => __('storefront.upsell.cta', ['pct' => $pctLabel]),
            'star_product_id' => $starId ?: null,
            'offer_product_id' => (int) $offer->id,
            'offer_product' => $this->present($offer, $store, $listPrice, $salePrice, $isStarOffer),
        ];
    }

    public function product(Store $store, int $id): ?Product
    {
        if ($id < 1) {
            return null;
        }

        return Product::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->whereIn('status', ['live', 'draft'])
            ->first();
    }

    public function pctLabel(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Product $p, Store $store, float $listPrice, float $salePrice, bool $isStar): array
    {
        $img = (string) ($p->image_url ?: '');
        if ($img !== '' && str_starts_with($img, '/media/')) {
            $img = asset(ltrim($img, '/'));
        }

        return [
            'id' => (int) $p->id,
            'name' => $p->localizedName(),
            'image' => $img !== '' ? $img : null,
            'url' => route('store.design.page', ['slug' => $store->slug, 'handle' => $p->slug]),
            'price' => $listPrice,
            'price_formatted' => '$'.number_format($listPrice, 2),
            'sale_price' => $salePrice,
            'sale_price_formatted' => '$'.number_format($salePrice, 2),
            'is_star' => $isStar,
        ];
    }
}
