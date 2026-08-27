<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\Theme;
use App\Models\ThemeSandboxOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catálogo, carrito y pedidos ficticios para probar una plantilla de plataforma.
 */
class ThemeSandboxService
{
    public function __construct(
        protected SandboxCjFulfillmentService $cjFulfillment,
        protected StorefrontProductMapper $productMapper,
    ) {}

    public function demoProducts(Theme $theme): Collection
    {
        $real = Product::query()
            ->with(['variants', 'store'])
            ->whereIn('status', ['live', 'draft'])
            ->orderByDesc('is_featured')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->filter(fn (Product $p) => $p->isFromCj() && $this->vidFor($p) !== '')
            ->take(8)
            ->values();

        if ($real->isNotEmpty()) {
            return $real->map(function (Product $p, int $i) use ($theme) {
                $mapped = $this->productMapper->fromProduct($p, $p->store, [
                    'full' => true,
                    'url' => route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => $p->slug]),
                    'featured' => $i < 4 || (bool) $p->is_featured,
                    'is_star' => $i === 0,
                ]);
                $mapped['from_cj'] = true;
                $mapped['cj_pid'] = $p->cjPid();
                $mapped['vid'] = $this->vidFor($p);
                $mapped['badge'] = $mapped['badge'] ?: 'CJ';
                if (empty($mapped['image'])) {
                    $mapped['image'] = 'https://picsum.photos/seed/md-cj-'.$p->id.'/800/800';
                    $mapped['images'] = array_values(array_filter(array_merge([$mapped['image']], $mapped['images'] ?? [])));
                }

                return $mapped;
            });
        }

        $items = [
            ['slug' => 'linterna-solar', 'name' => 'Linterna solar táctica', 'price' => 489, 'badge' => 'Nuevo', 'featured' => true],
            ['slug' => 'power-bank-20k', 'name' => 'Power bank 20 000 mAh', 'price' => 799, 'badge' => 'Top', 'featured' => true],
            ['slug' => 'panel-60w', 'name' => 'Panel solar plegable 60W', 'price' => 1899, 'badge' => null, 'featured' => true],
            ['slug' => 'kit-emergencia', 'name' => 'Kit de emergencia familiar', 'price' => 1299, 'badge' => 'Pack', 'featured' => true],
            ['slug' => 'radio-crank', 'name' => 'Radio manivela + USB', 'price' => 359, 'badge' => null, 'featured' => false],
            ['slug' => 'lampara-camping', 'name' => 'Lámpara de camping LED', 'price' => 249, 'badge' => null, 'featured' => false],
            ['slug' => 'cables-carga', 'name' => 'Set cables carga rápida', 'price' => 189, 'badge' => null, 'featured' => false],
            ['slug' => 'mochila-dry', 'name' => 'Mochila dry 30L', 'price' => 649, 'badge' => null, 'featured' => false],
        ];

        return collect($items)->values()->map(function (array $row, int $i) use ($theme) {
            $img = 'https://picsum.photos/seed/md-tpl-'.$row['slug'].'/800/800';
            $img2 = 'https://picsum.photos/seed/md-tpl-'.$row['slug'].'-b/800/800';
            $img3 = 'https://picsum.photos/seed/md-tpl-'.$row['slug'].'-c/800/800';
            $short = 'Descripción corta de demostración para '.$row['name'].'.';
            $long = '<p>Descripción larga de demostración. Incluye beneficios, materiales y uso de <strong>'.$row['name'].'</strong>.</p><ul><li>Listo para enviar</li><li>Fotos de compradores en reseñas</li></ul>';
            $reviews = [
                ['author' => 'Ana M.', 'score' => 5, 'comment' => 'Excelente calidad, llegó rápido.', 'date' => '2026-07-02', 'country' => 'MX', 'images' => [$img2]],
                ['author' => 'Luis R.', 'score' => 4, 'comment' => 'Cumple lo que promete.', 'date' => '2026-06-18', 'country' => 'CO', 'images' => []],
            ];
            $comments = [
                ['author' => 'Carla', 'score' => 5, 'comment' => 'Foto real del producto.', 'date' => '2026-07-10', 'country' => 'MX', 'images' => [$img3]],
            ];
            $variants = [
                ['id' => ($i + 1) * 10 + 1, 'sku' => $row['slug'].'-a', 'name' => 'Negro', 'vid' => 'demo-'.$row['slug'].'-a', 'image' => $img, 'stock' => 12, 'price' => (float) $row['price']],
                ['id' => ($i + 1) * 10 + 2, 'sku' => $row['slug'].'-b', 'name' => 'Verde', 'vid' => 'demo-'.$row['slug'].'-b', 'image' => $img2, 'stock' => 8, 'price' => (float) $row['price']],
            ];

            return [
                'id' => $i + 1,
                'name' => $row['name'],
                'title' => $row['name'],
                'slug' => $row['slug'],
                'handle' => $row['slug'],
                'price' => (float) $row['price'],
                'price_formatted' => '$'.number_format((float) $row['price'], 2),
                'compare_at_price' => null,
                'currency' => 'MXN',
                'badge' => $row['badge'],
                'stock' => 25,
                'image' => $img,
                'images' => [$img, $img2, $img3],
                'url' => route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => $row['slug']]),
                'is_featured' => (bool) $row['featured'],
                'featured' => (bool) $row['featured'],
                'is_star' => $i === 0,
                'star' => $i === 0,
                'description' => $long,
                'description_short' => $short,
                'description_long' => $long,
                'description_html' => $long,
                'summary' => $short,
                'status' => 'live',
                'rating_avg' => 4.6,
                'review_count' => 12,
                'comment_count' => count($comments),
                'reviews' => $reviews,
                'comments' => $comments,
                'variants' => $variants,
                'variant_count' => count($variants),
                'from_cj' => false,
                'cj_pid' => null,
                'vid' => null,
                'has_video' => $i === 0,
                'video_ids' => $i === 0 ? ['demo-video-1'] : [],
                'videos' => $i === 0 ? [[
                    'id' => 'demo-video-1',
                    'name' => 'Demo product video',
                    'url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
                    'poster' => $img,
                ]] : [],
                'video_url' => $i === 0
                    ? 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4'
                    : null,
                'video_poster' => $i === 0 ? $img : null,
            ];
        });
    }

    /**
     * @return array{has_video: bool, video_ids: list<string>, videos: list<array<string, mixed>>, video_url: ?string, video_poster: ?string}
     */
    protected function productVideosPayload(Product $p): array
    {
        $raw = data_get($p->verified_data, 'videos', []);
        if (! is_array($raw)) {
            $raw = [];
        }
        $ids = data_get($p->creative_data, 'video_ids', data_get($p->verified_data, 'video_ids', []));
        if (! is_array($ids)) {
            $ids = [];
        }
        $videos = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? $row['play_url'] ?? $row['source_url'] ?? $row['videoUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            $videos[] = [
                'id' => (string) ($row['id'] ?? $row['videoId'] ?? uniqid('v')),
                'name' => (string) ($row['name'] ?? $row['videoName'] ?? 'Video'),
                'url' => app(\App\Domain\Suppliers\Cj\CjVideoProxy::class)->playableUrl($url),
                'poster' => (string) ($row['poster'] ?? $row['cover'] ?? $p->image_url ?? ''),
            ];
        }
        $has = (bool) data_get($p->creative_data, 'has_video', false) || $videos !== [] || $ids !== [];

        return [
            'has_video' => $has,
            'video_ids' => array_values(array_filter(array_map('strval', $ids))),
            'videos' => $videos,
            'video_url' => $videos[0]['url'] ?? null,
            'video_poster' => $videos[0]['poster'] ?? ($p->image_url ?: null),
        ];
    }

    public function findProduct(Theme $theme, string $handle): ?array
    {
        return $this->demoProducts($theme)->first(
            fn ($p) => ($p['slug'] ?? '') === $handle || ($p['handle'] ?? '') === $handle
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cart(Theme $theme): array
    {
        $raw = session($this->cartKey($theme), null);
        if (! is_array($raw) || ! isset($raw['items']) || ! is_array($raw['items'])) {
            $raw = ['items' => [], 'coupon' => null];
        }

        return $this->withTotals($theme, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function addToCart(Theme $theme, int $productId, int $qty = 1): array
    {
        $product = $this->demoProducts($theme)->firstWhere('id', $productId);
        if (! $product) {
            return $this->cart($theme);
        }
        $cart = $this->cart($theme);
        $found = false;
        foreach ($cart['items'] as &$item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === $productId) {
                $item['qty'] = max(1, (int) ($item['qty'] ?? 1) + max(1, $qty));
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            $cart['items'][] = [
                'product_id' => $productId,
                'id' => $productId,
                'name' => $product['name'],
                'slug' => $product['slug'] ?? $product['handle'] ?? null,
                'handle' => $product['handle'] ?? $product['slug'] ?? null,
                'price' => $product['price'],
                'image' => $product['image'],
                'url' => $product['url'],
                'qty' => max(1, $qty),
                'vid' => $product['vid'] ?? null,
                'cj_pid' => $product['cj_pid'] ?? null,
                'from_cj' => (bool) ($product['from_cj'] ?? false),
            ];
        }
        session([$this->cartKey($theme) => $cart]);

        return $this->cart($theme);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCartItem(Theme $theme, int $productId, int $qty): array
    {
        $cart = $this->cart($theme);
        if ($qty < 1) {
            return $this->removeCartItem($theme, $productId);
        }
        foreach ($cart['items'] as &$item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === $productId) {
                $item['qty'] = $qty;
            }
        }
        unset($item);
        session([$this->cartKey($theme) => $cart]);

        return $this->cart($theme);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeCartItem(Theme $theme, int $productId): array
    {
        $cart = $this->cart($theme);
        $cart['items'] = array_values(array_filter(
            $cart['items'],
            fn ($it) => (int) ($it['product_id'] ?? $it['id'] ?? 0) !== $productId
        ));
        session([$this->cartKey($theme) => $cart]);

        return $this->cart($theme);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyCoupon(Theme $theme, string $code): array
    {
        $cart = $this->cart($theme);
        $code = strtoupper(trim($code));
        $allowed = ['DEMO10', 'SAVE5', 'SAVE10', 'SAVE15', 'FREESHIP'];
        $ok = in_array($code, $allowed, true) || str_starts_with($code, 'NL-') || str_starts_with($code, 'NL');
        $cart['coupon'] = $ok ? $code : null;
        session([$this->cartKey($theme) => $cart]);

        return $this->cart($theme);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearCoupon(Theme $theme): array
    {
        $cart = $this->cart($theme);
        $cart['coupon'] = null;
        session([$this->cartKey($theme) => $cart]);

        return $this->cart($theme);
    }

    /**
     * @return array{ok: bool, cart: array<string, mixed>, message?: string, quote?: array<string, mixed>}
     */
    public function setShippingCountry(Theme $theme, string $country): array
    {
        $country = strtoupper(trim($country));
        if ($country === 'UK') {
            $country = 'GB';
        }
        if ($country === '') {
            return ['ok' => false, 'message' => 'Selecciona un país.', 'cart' => $this->cart($theme)];
        }
        $cart = $this->cart($theme);
        $cart['shipping_country'] = $country;
        session([$this->cartKey($theme) => $cart]);
        $cart = $this->cart($theme);

        return [
            'ok' => true,
            'message' => $cart['shipping_info']['label'] ?? 'Envío actualizado',
            'cart' => $cart,
            'quote' => $cart['shipping_info'] ?? null,
        ];
    }

    /**
     * Oferta demo de upsell (combo con descuento).
     *
     * @param  array<string, mixed>|null  $cart
     * @return array<string, mixed>
     */
    public function demoUpsellOffer(Theme $theme, ?array $cart = null): array
    {
        $products = $this->demoProducts($theme)->values();
        $cart = is_array($cart) ? $cart : ['items' => []];
        $inCart = collect($cart['items'] ?? [])->map(
            fn ($it) => (int) ($it['product_id'] ?? $it['id'] ?? 0)
        )->filter()->all();

        // Producto estrella: eje del combo/upsell. Si ya está en carrito, ofrecer complemento.
        $star = $products->first(fn ($p) => ! empty($p['is_star']) || ! empty($p['star']))
            ?? $products->first();
        $starId = (int) ($star['id'] ?? 0);
        $starInCart = $starId > 0 && in_array($starId, $inCart, true);

        if ($star && ! $starInCart) {
            $offer = $star;
        } else {
            $offer = $products->first(fn ($p) => ! in_array((int) ($p['id'] ?? 0), $inCart, true) && (int) ($p['id'] ?? 0) !== $starId)
                ?? $products->first(fn ($p) => ! in_array((int) ($p['id'] ?? 0), $inCart, true))
                ?? $products->skip(1)->first()
                ?? $products->first();
        }

        $listPrice = (float) ($offer['price'] ?? 0);
        $discountPercent = 20.0;
        $salePrice = round($listPrice * (1 - ($discountPercent / 100)), 2);
        $isStarOffer = $offer && ((int) ($offer['id'] ?? 0) === $starId);
        $pctLabel = rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.').'%';

        return [
            'enabled' => true,
            'discount_percent' => $discountPercent,
            'headline' => $isStarOffer ? __('storefront.upsell.headline_star') : __('storefront.upsell.headline'),
            'copy' => $offer
                ? ($isStarOffer
                    ? __('storefront.upsell.copy_star', ['pct' => $pctLabel])
                    : __('storefront.upsell.copy_add_on', ['pct' => $pctLabel]))
                : __('storefront.upsell.copy_generic', ['pct' => $pctLabel]),
            'cta' => __('storefront.upsell.cta', ['pct' => $pctLabel]),
            'star_product_id' => $starId ?: null,
            'offer_product_id' => $offer ? (int) $offer['id'] : null,
            'offer_product' => $offer ? [
                'id' => (int) $offer['id'],
                'name' => $offer['name'] ?? 'Producto',
                'image' => $offer['image'] ?? null,
                'url' => $offer['url'] ?? null,
                'price' => $listPrice,
                'price_formatted' => '$'.number_format($listPrice, 2),
                'sale_price' => $salePrice,
                'sale_price_formatted' => '$'.number_format($salePrice, 2),
                'is_star' => $isStarOffer,
            ] : null,
        ];
    }

    /**
     * Acepta el upsell demo: agrega el producto oferta como combo con descuento.
     *
     * @return array{ok: bool, cart: array<string, mixed>, message?: string}
     */
    public function acceptUpsell(Theme $theme, ?int $productId = null): array
    {
        if (! ($this->modulesFor($theme)['upsell'] ?? false)) {
            return ['ok' => false, 'message' => 'Upsell no activo en este sandbox.', 'cart' => $this->cart($theme)];
        }

        $offer = $this->demoUpsellOffer($theme, $this->cart($theme));
        $productId = $productId ?: (int) ($offer['offer_product_id'] ?? 0);
        if ($productId < 1) {
            return ['ok' => false, 'message' => 'No hay producto para el combo.', 'cart' => $this->cart($theme)];
        }

        $product = $this->demoProducts($theme)->firstWhere('id', $productId);
        if (! $product) {
            return ['ok' => false, 'message' => 'Producto no encontrado.', 'cart' => $this->cart($theme)];
        }

        $pct = (float) ($offer['discount_percent'] ?? 20);
        $listPrice = (float) ($product['price'] ?? 0);
        $salePrice = round($listPrice * (1 - ($pct / 100)), 2);
        $cart = $this->cart($theme);
        $found = false;

        foreach ($cart['items'] as &$item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === $productId) {
                $item['qty'] = max(1, (int) ($item['qty'] ?? 1) + 1);
                $item['upsell_combo'] = true;
                $item['upsell_percent'] = $pct;
                $item['compare_at'] = $listPrice;
                $item['price'] = $salePrice;
                $item['name'] = str_starts_with((string) ($item['name'] ?? ''), 'Combo · ')
                    ? $item['name']
                    : ('Combo · '.($product['name'] ?? $item['name'] ?? 'Producto'));
                $found = true;
                break;
            }
        }
        unset($item);

        if (! $found) {
            $cart['items'][] = [
                'product_id' => $productId,
                'id' => $productId,
                'name' => 'Combo · '.($product['name'] ?? 'Producto'),
                'slug' => $product['slug'] ?? $product['handle'] ?? null,
                'handle' => $product['handle'] ?? $product['slug'] ?? null,
                'price' => $salePrice,
                'compare_at' => $listPrice,
                'image' => $product['image'] ?? null,
                'url' => $product['url'] ?? null,
                'qty' => 1,
                'vid' => $product['vid'] ?? null,
                'cj_pid' => $product['cj_pid'] ?? null,
                'from_cj' => (bool) ($product['from_cj'] ?? false),
                'upsell_combo' => true,
                'upsell_percent' => $pct,
            ];
        }

        $cart['upsell_accepted'] = true;
        session([$this->cartKey($theme) => [
            'items' => $cart['items'],
            'coupon' => $cart['coupon'] ?? null,
            'shipping_country' => $cart['shipping_country'] ?? null,
            'upsell_accepted' => true,
        ]]);

        return [
            'ok' => true,
            'message' => 'Combo agregado al carrito (−'.rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').'%).',
            'cart' => $this->cart($theme),
            'redirect' => route('theme.sandbox.page', ['theme' => $theme, 'handle' => 'cart']),
        ];
    }

    /**
     * @return array{ok: bool, cart: array<string, mixed>, message?: string}
     */
    public function addMagicCrossSell(Theme $theme, int $productId, int $qty = 1): array
    {
        $cfg = app(\App\Services\Commerce\CrossSellOfferService::class)->forSandbox();
        $cart = $this->cart($theme);
        $qty = max(1, $qty);
        $found = false;
        foreach ($cart['items'] as &$item) {
            if ((int) ($item['product_id'] ?? $item['id'] ?? 0) === $productId) {
                $item['qty'] = (int) ($item['qty'] ?? 1) + $qty;
                $item['cross_sell_magic'] = true;
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            $product = collect($this->demoProducts($theme))->first(
                fn ($p) => (int) ($p['id'] ?? 0) === $productId
            );
            if (! $product) {
                return ['ok' => false, 'message' => 'Producto no encontrado.', 'cart' => $cart];
            }
            $cart['items'][] = [
                'product_id' => $productId,
                'id' => $productId,
                'name' => $product['name'] ?? 'Producto',
                'slug' => $product['slug'] ?? $product['handle'] ?? null,
                'handle' => $product['handle'] ?? $product['slug'] ?? null,
                'price' => (float) ($product['price'] ?? 0),
                'image' => $product['image'] ?? null,
                'url' => $product['url'] ?? null,
                'qty' => $qty,
                'vid' => $product['vid'] ?? null,
                'cross_sell_magic' => true,
            ];
        }
        session([$this->cartKey($theme) => $cart]);
        $cart = $this->cart($theme);

        return [
            'ok' => true,
            'message' => 'Agregado con '.$cfg['extra_discount_label'].' mágico extra',
            'cart' => $cart,
        ];
    }

    public function clearCart(Theme $theme): void
    {
        session()->forget($this->cartKey($theme));
    }

    /**
     * Reinicia el flujo sandbox: carrito, cupones, newsletter pendiente y datos de sesión del theme.
     * No toca los módulos elegidos (se reasignan al abrir desde admin).
     */
    public function resetFlow(Theme $theme): void
    {
        $this->clearCart($theme);
        session()->forget([
            $this->ordersKey($theme),
            'sandbox_nl_checkout.'.$theme->id,
        ]);

        $prefix = 'sandbox_nl.'.$theme->id.'.';
        foreach (array_keys(session()->all()) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                session()->forget($key);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function placeOrder(Theme $theme, array $customer): array
    {
        $cart = $this->cart($theme);
        if (($cart['items'] ?? []) === []) {
            return ['ok' => false, 'error' => 'El carrito está vacío.'];
        }

        $number = 'TPL-'.strtoupper(Str::random(6));
        $email = strtolower((string) ($customer['email'] ?? ''));
        $name = (string) ($customer['name'] ?? '');
        $phone = (string) ($customer['phone'] ?? '');
        $row = ThemeSandboxOrder::create([
            'theme_id' => $theme->id,
            'number' => $number,
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
            'address' => $customer,
            'items' => $cart['items'],
            'subtotal' => (float) ($cart['totals']['subtotal'] ?? 0),
            'discount' => (float) ($cart['totals']['discount'] ?? 0),
            'total' => (float) ($cart['totals']['total'] ?? 0),
            'currency' => 'MXN',
            'coupon' => $cart['coupon'] ?? null,
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
        ]);

        $row = $this->cjFulfillment->submit($row);
        $this->clearCart($theme);

        $order = $row->toClientArray();

        return [
            'ok' => true,
            'order' => $order,
            'confirm_url' => route('theme.sandbox.confirm', $theme->slug).'?number='.$number.'&email='.urlencode($email),
            'track_url' => route('theme.sandbox.track', $theme->slug).'?number='.$number.'&email='.urlencode($email),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOrder(Theme $theme, string $number, string $email): ?array
    {
        $number = strtoupper(trim($number));
        $email = strtolower(trim($email));
        $row = ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('number', $number)
            ->where('email', $email)
            ->first();

        return $row?->toClientArray();
    }

    public function findOrderRow(Theme $theme, string $number, string $email): ?ThemeSandboxOrder
    {
        return ThemeSandboxOrder::query()
            ->where('theme_id', $theme->id)
            ->where('number', strtoupper(trim($number)))
            ->where('email', strtolower(trim($email)))
            ->first();
    }

    /**
     * @return array{commerce: bool, upsell: bool, cross_sell: bool, urgency: bool, roulette: bool}
     */
    public function defaultModules(): array
    {
        $modules = ['commerce' => true];
        foreach (array_keys(config('multidrop.plugins', [])) as $key) {
            $modules[$key] = true;
        }

        return $modules;
    }

    /**
     * @return array<string, array{desktop: bool, mobile: bool}>
     */
    public function defaultPluginDevices(): array
    {
        $out = [];
        foreach (array_keys(config('multidrop.plugins', [])) as $key) {
            $out[$key] = ['desktop' => true, 'mobile' => true];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $selected
     * @return array{commerce: bool, upsell: bool, cross_sell: bool, urgency: bool, roulette: bool}
     */
    public function normalizeModules(array $selected = []): array
    {
        $defaults = $this->defaultModules();
        if ($selected === []) {
            return $defaults;
        }
        $out = [];
        foreach ($defaults as $key => $_) {
            $out[$key] = filter_var($selected[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /**
     * @return array{commerce: bool, upsell: bool, cross_sell: bool, urgency: bool, roulette: bool}
     */
    public function modulesFor(Theme $theme): array
    {
        $stored = session('theme_sandbox_modules.'.$theme->id);
        if (! is_array($stored)) {
            return $this->defaultModules();
        }

        return $this->normalizeModules($stored);
    }

    /**
     * @param  array<string, mixed>  $modules
     */
    public function rememberModules(Theme $theme, array $modules): void
    {
        session(['theme_sandbox_modules.'.$theme->id => $this->normalizeModules($modules)]);
    }

    /**
     * Acepta ?md_modules=commerce,upsell,cross_sell (útil con target=_blank / sin sesión previa).
     * Con ?md_reset=1 limpia carrito/sesión del sandbox para reiniciar el flujo.
     */
    public function absorbModulesFromRequest(Theme $theme, Request $request): void
    {
        if ($request->boolean('md_reset')) {
            $this->resetFlow($theme);
        }

        $raw = $request->query('md_modules');
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }
        $keys = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $modules = $this->defaultModules();
        foreach ($modules as $key => $_) {
            $modules[$key] = in_array($key, $keys, true);
        }
        $this->rememberModules($theme, $modules);
    }

    /**
     * Etiquetas legibles para chips del banner.
     *
     * @return array<string, string>
     */
    public function moduleLabels(): array
    {
        $labels = [];
        foreach (config('multidrop.services', []) as $key => $svc) {
            $labels[$key] = (string) ($svc['label'] ?? $key);
        }
        foreach (config('multidrop.plugins', []) as $key => $plugin) {
            $labels[$key] = (string) ($plugin['label'] ?? $key);
        }

        return $labels;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>|null  $product
     * @return array<string, mixed>
     */
    public function payload(Theme $theme, array $page, ?array $product = null): array
    {
        $products = $this->demoProducts($theme);
        $cart = $this->cart($theme);
        $slug = $theme->slug;
        $modules = $this->modulesFor($theme);

        $localeSvc = app(\App\Services\Buyer\BuyerPortalLocale::class);
        $locale = $localeSvc->applyForTheme($theme);
        $design = is_array($theme->design) ? $theme->design : [];
        $currency = strtoupper((string) ($design['default_currency'] ?? $design['currency'] ?? 'USD'));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }
        $currencies = array_values(array_filter(array_map(
            fn ($c) => strtoupper((string) $c),
            is_array($design['currencies'] ?? null) ? $design['currencies'] : []
        )));
        if ($currencies === []) {
            $currencies = [$currency];
        }
        $localesList = array_values(array_filter(array_map(
            'strval',
            is_array($design['locales'] ?? null) ? $design['locales'] : []
        )));
        if ($localesList === []) {
            $localesList = [$locale];
        }

        return [
            'store' => [
                'id' => 0,
                'name' => $theme->name,
                'slug' => $slug,
                'type' => 'template',
            ],
            'commerce' => (bool) ($modules['commerce'] ?? true),
            'modules' => $modules,
            'plugin_devices' => $this->defaultPluginDevices(),
            'locale' => $locale,
            'locales' => $localesList,
            'currency' => $currency,
            'currencies' => $currencies,
            'i18n' => trans('storefront'),
            'social_proof' => [
                'interval_seconds' => 8,
                'display_seconds' => 5,
                'position' => 'bottom-left',
            ],
            'newsletter' => app(\App\Services\Commerce\NewsletterService::class)->forSandbox(),
            'cookies' => app(\App\Services\Storefront\CookieConsentService::class)->forSandbox(),
            'roulette' => app(\App\Services\Commerce\RouletteWheelService::class)->forSandbox(),
            'cross_sell' => (function () use ($theme, $cart, $modules) {
                if (! ($modules['cross_sell'] ?? false)) {
                    return ['offer' => null, 'products' => []];
                }
                $svc = app(\App\Services\Commerce\CrossSellOfferService::class);
                $offer = $svc->forSandbox();
                $products = ($offer['enabled'] ?? true)
                    ? $svc->suggestedFromCatalog(
                        $this->demoProducts($theme)->values()->all(),
                        $cart['items'] ?? [],
                        $offer
                    )
                    : [];

                return ['offer' => $offer, 'products' => $products];
            })(),
            'upsell' => ($modules['upsell'] ?? false)
                ? $this->demoUpsellOffer($theme, $cart)
                : ['enabled' => false, 'offer_product' => null],
            'payments_enabled' => true,
            'sandbox' => true,
            'products' => $products->values()->all(),
            'product' => $product,
            'star_product' => $products->first(fn ($p) => ! empty($p['is_star']) || ! empty($p['star']))
                ?? $product
                ?? $products->first(),
            'cart' => $cart,
            'page' => [
                'id' => $page['id'] ?? null,
                'type' => $page['type'] ?? null,
                'handle' => $page['handle'] ?? null,
                'title' => $page['title'] ?? null,
            ],
            'checkout' => [
                'primary' => data_get($theme->design, 'checkout.primary', '#0f766e'),
                'accent' => data_get($theme->design, 'checkout.accent', '#f59e0b'),
                'button' => data_get($theme->design, 'checkout.button', '#0f766e'),
                'bg' => data_get($theme->design, 'checkout.bg', '#ffffff'),
                'text' => data_get($theme->design, 'checkout.text', '#0f172a'),
            ],
            'urls' => [
                'home' => route('theme.sandbox.show', $slug),
                'catalog' => route('theme.sandbox.page', ['theme' => $slug, 'handle' => 'catalog']),
                'cart' => route('theme.sandbox.page', ['theme' => $slug, 'handle' => 'cart']),
                'checkout' => route('theme.sandbox.page', ['theme' => $slug, 'handle' => 'checkout']),
                'products_json' => route('theme.sandbox.products', $slug),
                'coupon' => route('theme.sandbox.cart.coupon', $slug),
                'cart_json' => route('theme.sandbox.cart.show', $slug),
                'cart_add' => route('theme.sandbox.cart.add', $slug),
                'cart_items' => url('/t/'.$slug.'/cart/items'),
                'cart_coupon' => route('theme.sandbox.cart.coupon', $slug),
                'cart_coupon_clear' => route('theme.sandbox.cart.coupon.clear', $slug),
                'cart_shipping' => route('theme.sandbox.cart.shipping', $slug),
                'cart_cross_sell' => route('theme.sandbox.cart.cross-sell', $slug),
                'cart_upsell' => route('theme.sandbox.cart.upsell', $slug),
                'checkout_place' => route('theme.sandbox.checkout', $slug),
                'newsletter_subscribe' => route('theme.sandbox.newsletter.subscribe', $slug),
                'track' => route('theme.sandbox.track', $slug),
                'confirm' => route('theme.sandbox.confirm', $slug),
                'cj_video' => route('store.media.cj-video'),
            ],
            'pixels' => $this->pixelsPayload(),
            'shipping_countries' => app(\App\Services\Commerce\ShippingQuoteService::class)->countries(null),
            'geo' => app(VisitorCountryResolver::class)->forPayload(null),
            'assets' => collect(data_get($theme->design, 'assets', []))->map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? null,
                'url' => $a['url'] ?? null,
            ])->values()->all(),
            'csrf' => csrf_token(),
        ];
    }

    /**
     * @return array{ga: ?string, meta: ?string}
     */
    protected function pixelsPayload(): array
    {
        $ga = trim((string) (\App\Models\PlatformSetting::getValue('marketing.ga_measurement_id') ?: ''));
        $meta = trim((string) (\App\Models\PlatformSetting::getValue('marketing.meta_pixel_id') ?: ''));

        return [
            'ga' => $ga !== '' ? $ga : null,
            'meta' => $meta !== '' ? $meta : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    protected function withTotals(Theme $theme, array $cart): array
    {
        $offerSvc = app(\App\Services\Commerce\CrossSellOfferService::class);
        $crossCfg = $offerSvc->forSandbox();
        $items = [];
        foreach ($cart['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = $offerSvc->enrichCartItem($item, $crossCfg);
        }
        $cart['items'] = $items;

        $subtotal = 0.0;
        $listSubtotal = 0.0;
        $comboDiscount = 0.0;
        $comboPct = null;
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $unit = (float) ($item['price'] ?? 0);
            $line = (float) ($item['line_total'] ?? ($unit * $qty));
            $subtotal += $line;
            $compare = (float) ($item['compare_at'] ?? 0);
            $listUnit = $compare > $unit ? $compare : $unit;
            $listSubtotal += $listUnit * $qty;
            if (! empty($item['upsell_combo']) && $listUnit > $unit) {
                $comboDiscount += ($listUnit - $unit) * $qty;
                $comboPct = (float) ($item['upsell_percent'] ?? 20);
            }
        }
        $subtotal = round($subtotal, 2);
        $listSubtotal = round($listSubtotal, 2);
        $comboDiscount = round($comboDiscount, 2);
        $code = strtoupper((string) ($cart['coupon'] ?? ''));
        $discount = 0.0;
        if ($code === 'SAVE15') {
            $discount = round($subtotal * 0.15, 2);
        } elseif ($code === 'SAVE5') {
            $discount = round($subtotal * 0.05, 2);
        } elseif ($code !== '' && (
            $code === 'DEMO10' || $code === 'SAVE10' || str_starts_with($code, 'NL')
        )) {
            $discount = round($subtotal * 0.10, 2);
        }

        $country = strtoupper((string) ($cart['shipping_country'] ?? ''));
        $shippingAmount = 0.0;
        $shippingInfo = null;
        if ($country !== '' && $items !== []) {
            $design = is_array($theme->design) ? $theme->design : [];
            $displayCurrency = strtoupper((string) ($design['default_currency'] ?? $design['currency'] ?? 'USD'));
            if (! preg_match('/^[A-Z]{3}$/', $displayCurrency)) {
                $displayCurrency = 'USD';
            }
            $quote = app(\App\Services\Commerce\ShippingQuoteService::class)
                ->quote(null, $country, $items, $displayCurrency);
            $shippingAmount = (float) ($quote['amount'] ?? 0);
            $shippingInfo = [
                'country' => $quote['country'] ?? $country,
                'amount' => $shippingAmount,
                'label' => $quote['label'] ?? 'Envío',
                'source' => $quote['source'] ?? 'table',
            ];
            $cart['shipping_country'] = $quote['country'] ?? $country;
        }

        $magicDiscount = $offerSvc->computeMagicDiscount($items, $crossCfg);
        $crossOn = (bool) ($this->modulesFor($theme)['cross_sell'] ?? false);

        $total = max(0, round($subtotal - $discount - $magicDiscount + $shippingAmount, 2));
        $count = collect($items)->sum(fn ($it) => (int) ($it['qty'] ?? 1));

        $cart['count'] = $count;
        $cart['shipping_info'] = $shippingInfo;
        $cart['cross_sell'] = [
            'magic_discount' => $magicDiscount,
            'label' => $crossCfg['badge'] ?? 'Descuento mágico',
            'hint' => $crossCfg['hint_display'] ?? null,
            'offer' => $crossCfg,
            'products' => ($crossOn && ($crossCfg['enabled'] ?? true))
                ? $offerSvc->suggestedFromCatalog(
                    $this->demoProducts($theme)->values()->all(),
                    $items,
                    $crossCfg
                )
                : [],
        ];
        $cart['totals'] = [
            'subtotal' => round($subtotal, 2),
            'subtotal_list' => $listSubtotal > 0 ? $listSubtotal : round($subtotal, 2),
            'combo_discount' => $comboDiscount,
            'combo_percent' => $comboPct,
            'discount' => $discount,
            'magic_discount' => $magicDiscount,
            'shipping' => $shippingAmount,
            'tax' => 0,
            'total' => $total,
            'currency' => 'USD',
        ];
        $cart['coupon_info'] = ($code !== '' && $discount > 0)
            ? ['code' => $code, 'discount' => $discount]
            : null;

        return $cart;
    }

    protected function vidFor(Product $product): string
    {
        foreach ($product->variants as $variant) {
            $vid = (string) data_get($variant->options, 'vid', '');
            if ($vid !== '') {
                return $vid;
            }
        }
        $list = data_get($product->verified_data, 'variants', []);
        if (is_array($list)) {
            foreach ($list as $row) {
                $vid = (string) ($row['vid'] ?? '');
                if ($vid !== '') {
                    return $vid;
                }
            }
        }

        return '';
    }

    protected function cartKey(Theme $theme): string
    {
        return 'theme_sandbox_cart.'.$theme->id;
    }

    protected function ordersKey(Theme $theme): string
    {
        return 'theme_sandbox_orders.'.$theme->id;
    }
}
