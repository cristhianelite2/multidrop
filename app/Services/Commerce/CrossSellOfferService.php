<?php

namespace App\Services\Commerce;

use App\Models\CrossSellRule;
use App\Models\Product;
use App\Models\Store;

class CrossSellOfferService
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public function normalize(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $type = (string) ($raw['extra_discount_type'] ?? 'percent');
        if (! in_array($type, ['percent', 'fixed'], true)) {
            $type = 'percent';
        }
        $value = max(1, min(10000, (float) ($raw['extra_discount_value'] ?? 15)));
        if ($type === 'percent') {
            $value = max(1, min(80, $value));
        }
        $hintValue = $type === 'percent'
            ? rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%'
            : '$'.rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        $hint = trim((string) ($raw['hint'] ?? ''));
        if ($hint === '') {
            $hint = __('storefront.cross.hint');
        }

        $headline = trim((string) ($raw['headline'] ?? ''));
        if ($headline === '') {
            $headline = __('storefront.cross.headline');
        }
        $subtitle = trim((string) ($raw['subtitle'] ?? ''));
        if ($subtitle === '') {
            $subtitle = __('storefront.cross.subtitle');
        }
        $badge = trim((string) ($raw['badge'] ?? ''));
        if ($badge === '') {
            $badge = __('storefront.cross.badge');
        }
        $cta = trim((string) ($raw['cta'] ?? ''));
        if ($cta === '') {
            $cta = __('storefront.cross.cta');
        }

        return [
            'headline' => mb_substr($headline, 0, 100) ?: __('storefront.cross.headline'),
            'subtitle' => mb_substr($subtitle, 0, 200),
            'badge' => mb_substr($badge, 0, 40) ?: __('storefront.cross.badge'),
            'cta' => mb_substr($cta, 0, 50) ?: __('storefront.cross.cta'),
            'hint' => mb_substr($hint, 0, 220),
            'hint_display' => mb_substr(str_replace('{value}', $hintValue, $hint), 0, 220),
            'extra_discount_type' => $type,
            'extra_discount_value' => $value,
            'extra_discount_label' => $hintValue,
            'max_products' => max(1, min(8, (int) ($raw['max_products'] ?? 3))),
            'expires_minutes' => max(3, min(120, (int) ($raw['expires_minutes'] ?? 15))),
            'enabled' => filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function forStore(Store $store): array
    {
        $cfg = $this->normalize(data_get($store->settings, 'cross_sell_offer'));
        $cfg['_fx_from'] = $store->configuredCurrency();
        $cfg['_fx_to'] = $store->currency();
        if (($cfg['extra_discount_type'] ?? '') === 'fixed') {
            $converted = $this->fixedDiscountInTarget($cfg);
            $hintValue = '$'.rtrim(rtrim(number_format($converted, 2, '.', ''), '0'), '.');
            $cfg['extra_discount_label'] = $hintValue;
            $hint = (string) ($cfg['hint'] ?? '');
            if ($hint !== '') {
                $cfg['hint_display'] = mb_substr(str_replace('{value}', $hintValue, $hint), 0, 220);
            }
        }

        return $cfg;
    }

    public function forSandbox(): array
    {
        return $this->normalize([
            'extra_discount_type' => 'percent',
            'extra_discount_value' => 15,
            'max_products' => 3,
            'expires_minutes' => 15,
        ]);
    }

    /**
     * Productos sugeridos según reglas / carrito.
     *
     * @param  list<array<string, mixed>>  $cartItems
     * @return list<array<string, mixed>>
     */
    public function suggestedProducts(Store $store, array $cartItems, ?array $cfg = null): array
    {
        $cfg = $cfg ?? $this->forStore($store);
        $max = (int) ($cfg['max_products'] ?? 3);
        [$inCartIds, $inCartSlugs] = $this->cartIdentityMaps($cartItems);

        $offerIds = [];
        $triggerIds = array_keys($inCartIds);
        if ($triggerIds !== []) {
            $rules = CrossSellRule::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->whereIn('trigger_product_id', $triggerIds)
                ->orderBy('priority')
                ->orderByDesc('id')
                ->get();
            foreach ($rules as $rule) {
                $oid = (int) $rule->offer_product_id;
                if ($oid > 0 && empty($inCartIds[$oid])) {
                    $offerIds[] = $oid;
                }
            }
        }

        $offerIds = array_values(array_unique($offerIds));
        // Nunca sugerir algo que ya está en el carrito
        $offerIds = array_values(array_filter($offerIds, fn (int $id) => $id > 0 && empty($inCartIds[$id])));

        if ($offerIds === []) {
            $starId = (int) ($store->starProductId() ?? 0);
            if ($starId > 0 && empty($inCartIds[$starId])) {
                $offerIds[] = $starId;
            }
            $more = Product::query()
                ->where('store_id', $store->id)
                ->whereIn('status', ['live', 'draft'])
                ->when($inCartIds !== [], fn ($q) => $q->whereNotIn('id', array_keys($inCartIds)))
                ->when($starId > 0, fn ($q) => $q->where('id', '!=', $starId))
                ->orderByDesc('is_featured')
                ->orderByDesc('id')
                ->limit(max(1, $max - count($offerIds)))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $offerIds = array_values(array_unique(array_merge($offerIds, $more)));
        }

        $offerIds = array_slice($offerIds, 0, $max);
        if ($offerIds === []) {
            return [];
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $offerIds)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($offerIds as $id) {
            $p = $products->get($id);
            if (! $p) {
                continue;
            }
            $quote = $p->quoteIn($store->currency());
            $price = (float) $quote['price'];
            $magic = $this->magicPrice($price, $cfg);
            $img = $p->image_url;
            if ($img && str_starts_with((string) $img, '/media/')) {
                $img = asset(ltrim($img, '/'));
            }
            $out[] = [
                'id' => $p->id,
                'product_id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'price' => $price,
                'price_formatted' => '$'.number_format($price, 2),
                'currency' => $quote['currency'],
                'magic_price' => $magic['price'],
                'magic_price_formatted' => '$'.number_format($magic['price'], 2),
                'magic_save' => $magic['save'],
                'magic_save_formatted' => '$'.number_format($magic['save'], 2),
                'image' => $img,
                'is_star' => $store->isStarProduct($p),
            ];
        }

        // Defensa extra: por si un offer_id coincidía con el carrito
        return array_values(array_filter(
            $out,
            function (array $row) use ($inCartIds, $inCartSlugs) {
                $id = (int) ($row['product_id'] ?? $row['id'] ?? 0);
                $slug = strtolower(trim((string) ($row['slug'] ?? '')));
                if ($id > 0 && ! empty($inCartIds[$id])) {
                    return false;
                }
                if ($slug !== '' && ! empty($inCartSlugs[$slug])) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * Sugerencias desde un catálogo en memoria (sandbox / demos).
     *
     * @param  list<array<string, mixed>>  $catalog
     * @param  list<array<string, mixed>>  $cartItems
     * @param  array<string, mixed>|null  $cfg
     * @return list<array<string, mixed>>
     */
    public function suggestedFromCatalog(array $catalog, array $cartItems, ?array $cfg = null): array
    {
        $cfg = $cfg ?? $this->forSandbox();
        $max = (int) ($cfg['max_products'] ?? 3);
        [$inCartIds, $inCartSlugs] = $this->cartIdentityMaps($cartItems);

        $out = [];
        // Priorizar producto estrella (si no está en el carrito) para el cross-sell mágico
        $sorted = $catalog;
        usort($sorted, static function ($a, $b) {
            $as = ! empty($a['is_star']) || ! empty($a['star']) ? 1 : 0;
            $bs = ! empty($b['is_star']) || ! empty($b['star']) ? 1 : 0;
            if ($as === $bs) {
                $af = ! empty($a['featured']) || ! empty($a['is_featured']) ? 1 : 0;
                $bf = ! empty($b['featured']) || ! empty($b['is_featured']) ? 1 : 0;

                return $bf <=> $af;
            }

            return $bs <=> $as;
        });

        foreach ($sorted as $p) {
            $id = (int) ($p['id'] ?? $p['product_id'] ?? 0);
            $slug = strtolower(trim((string) ($p['slug'] ?? $p['handle'] ?? '')));
            if ($id <= 0) {
                continue;
            }
            if (! empty($inCartIds[$id]) || ($slug !== '' && ! empty($inCartSlugs[$slug]))) {
                continue;
            }
            $price = (float) ($p['price'] ?? 0);
            $magic = $this->magicPrice($price, $cfg);
            $out[] = [
                'id' => $id,
                'product_id' => $id,
                'name' => $p['name'] ?? $p['title'] ?? 'Producto',
                'slug' => $slug !== '' ? $slug : ($p['slug'] ?? null),
                'price' => $price,
                'price_formatted' => '$'.number_format($price, 2),
                'magic_price' => $magic['price'],
                'magic_price_formatted' => '$'.number_format($magic['price'], 2),
                'magic_save' => $magic['save'],
                'magic_save_formatted' => '$'.number_format($magic['save'], 2),
                'image' => $p['image'] ?? null,
                'is_star' => ! empty($p['is_star']) || ! empty($p['star']),
            ];
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $cartItems
     * @return array{0: array<int, true>, 1: array<string, true>}
     */
    protected function cartIdentityMaps(array $cartItems): array
    {
        $ids = [];
        $slugs = [];
        foreach ($cartItems as $it) {
            if (! is_array($it)) {
                continue;
            }
            $id = (int) ($it['product_id'] ?? $it['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
            $slug = strtolower(trim((string) ($it['slug'] ?? $it['handle'] ?? '')));
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
            // product_id a veces viene como handle/slug string
            $rawPid = $it['product_id'] ?? null;
            if (is_string($rawPid) && $rawPid !== '' && ! ctype_digit($rawPid)) {
                $slugs[strtolower(trim($rawPid))] = true;
            }
        }

        return [$ids, $slugs];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{price: float, save: float}
     */
    public function magicPrice(float $price, array $cfg): array
    {
        $type = $cfg['extra_discount_type'] ?? 'percent';
        $value = (float) ($cfg['extra_discount_value'] ?? 15);
        if ($type === 'fixed') {
            $value = $this->fixedDiscountInTarget($cfg);
            $save = min($price, $value);
        } else {
            $save = round($price * ($value / 100), 2);
        }
        $save = max(0, round($save, 2));

        return [
            'price' => max(0, round($price - $save, 2)),
            'save' => $save,
        ];
    }

    /**
     * Descuento magic fixed: valor en moneda configurada de la tienda → moneda visitante.
     *
     * @param  array<string, mixed>  $cfg
     */
    protected function fixedDiscountInTarget(array $cfg): float
    {
        $value = (float) ($cfg['extra_discount_value'] ?? 0);
        $from = strtoupper(trim((string) ($cfg['_fx_from'] ?? '')));
        $to = strtoupper(trim((string) ($cfg['_fx_to'] ?? '')));
        if ($from === '' || $to === '' || $from === $to || $value == 0.0) {
            return round($value, 2);
        }

        return app(\App\Services\Currency\CurrencyService::class)->convert($value, $from, $to);
    }

    /**
     * Añade campos de precio mágico a una línea del carrito (para Order summary).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    public function enrichCartItem(array $item, array $cfg): array
    {
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $unit = (float) ($item['price'] ?? 0);
        $line = round($unit * $qty, 2);
        $item['qty'] = $qty;
        $item['line_total'] = $line;
        $item['price_formatted'] = $item['price_formatted'] ?? ('$'.number_format($unit, 2));
        $item['line_total_formatted'] = '$'.number_format($line, 2);

        if (empty($item['cross_sell_magic']) || ! ($cfg['enabled'] ?? true)) {
            $item['magic_unit_price'] = null;
            $item['magic_save'] = null;
            $item['magic_line_total'] = null;
            $item['magic_line_save'] = null;

            return $item;
        }

        $magic = $this->magicPrice($unit, $cfg);
        $lineSave = round($magic['save'] * $qty, 2);
        $lineMagic = round($magic['price'] * $qty, 2);
        $item['magic_unit_price'] = $magic['price'];
        $item['magic_unit_price_formatted'] = '$'.number_format($magic['price'], 2);
        $item['magic_save'] = $magic['save'];
        $item['magic_save_formatted'] = '$'.number_format($magic['save'], 2);
        $item['magic_line_total'] = $lineMagic;
        $item['magic_line_total_formatted'] = '$'.number_format($lineMagic, 2);
        $item['magic_line_save'] = $lineSave;
        $item['magic_line_save_formatted'] = '$'.number_format($lineSave, 2);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<array<string, mixed>>  $items Hydrated cart lines
     */
    public function computeMagicDiscount(array $items, array $cfg): float
    {
        if (! ($cfg['enabled'] ?? true)) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($items as $item) {
            if (empty($item['cross_sell_magic'])) {
                continue;
            }
            if (isset($item['magic_line_save'])) {
                $total += (float) $item['magic_line_save'];

                continue;
            }
            $unit = (float) ($item['price'] ?? 0);
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $magic = $this->magicPrice($unit, $cfg);
            $total += $magic['save'] * $qty;
        }

        return round($total, 2);
    }
}
