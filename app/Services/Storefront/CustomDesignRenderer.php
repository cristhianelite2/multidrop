<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\Store;
use App\Services\Buyer\BuyerPortalLocale;
use App\Services\Commerce\CartService;
use App\Services\Security\TurnstileVerifier;
use App\Services\Storefront\Modules\ModuleRegistry;
use App\Services\Storefront\Modules\RenderContext;
use App\Support\VisitDevice;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class CustomDesignRenderer
{
    public function __construct(
        protected DesignThemeService $themes,
        protected CartService $cart,
        protected StorefrontProductMapper $productMapper,
        protected BuyerPortalLocale $locales,
        protected DesignCopyLocalizer $copyLocalizer,
        protected ModuleRegistry $modules,
        protected TurnstileVerifier $turnstile,
    ) {}

    /**
     * @param  array{
     *   page_id?: ?string,
     *   handle?: string,
     *   preview?: bool,
     *   serve_design?: bool,
     *   allow_draft_page?: bool,
     *   product?: ?array
     * }  $options
     */
    public function response(object $store, array $options = []): Response
    {
        if ($store instanceof Store) {
            try {
                $prefs = app(StorefrontVisitorPrefs::class);
                $prefs->capture(request(), $store);
                $prefs->applyOverrides($store);
            } catch (\Throwable) {
                // CLI / preview sin request
            }
        }

        $design = $this->themes->forDisplay($this->themes->normalize($store));

        $preview = ! empty($options['preview']);
        $serveDesign = ! empty($options['serve_design']);
        $allowDraftPage = ! empty($options['allow_draft_page']) || $preview || $serveDesign;

        // enabled = toma el home clásico; /s/{slug} y preview pueden servir el theme aunque esté off
        if (! ($design['enabled'] ?? false) && ! $preview && ! $serveDesign) {
            abort(404, 'Esta tienda aún no tiene diseño HTML activo.');
        }

        // En /s/ (serve_design) aceptar páginas draft; en canal público estricto solo live
        $liveOnly = ! $preview && ! $allowDraftPage;
        $page = null;

        if (! empty($options['page_id'])) {
            $page = $this->themes->findPage($design, (string) $options['page_id']);
        }

        if (! $page && ! empty($options['handle'])) {
            $page = $this->themes->findPageByHandle($design, (string) $options['handle'], $liveOnly)
                ?: ($allowDraftPage
                    ? $this->themes->findPageByHandle($design, (string) $options['handle'], false)
                    : null);
        }

        if (! $page) {
            $page = $this->themes->findPageByType($design, 'landing', $liveOnly)
                ?: $this->themes->findPageByHandle($design, 'index', $liveOnly);
            if (! $page && $allowDraftPage) {
                $page = $this->themes->findPageByType($design, 'landing', false)
                    ?: $this->themes->findPageByHandle($design, 'index', false);
            }
        }

        if (! $page || ! $this->modules->pageIsRenderable($page)) {
            abort(404, 'Página de diseño no encontrada o sin HTML.');
        }

        if ($liveOnly && ($page['status'] ?? '') !== 'live' && ! $allowDraftPage) {
            abort(404, 'Página en borrador.');
        }

        $includeDraftProducts = $preview || $serveDesign;
        $products = $this->productsForStore($store, $design, $includeDraftProducts);
        $products = $this->ensureCatalogFlags($products);
        $product = $options['product'] ?? null;
        if (! $product && ($page['type'] ?? '') === 'product' && $products->isNotEmpty()) {
            $product = $products->first();
        }
        if ($product && $store instanceof Store && ! empty($product['id']) && empty($product['description_html'])) {
            $model = Product::query()->with('variants')->find((int) $product['id']);
            if ($model && (int) $model->store_id === (int) $store->id) {
                $product = $this->productMapper->fromProduct($model, $store, [
                    'full' => true,
                    'url' => $product['url'] ?? null,
                    'is_star' => ! empty($product['is_star']) || ! empty($product['star']),
                    'featured' => ! empty($product['featured']) || ! empty($product['is_featured']),
                ]);
            }
        }
        $payload = $this->payload($store, $products, $design, $page, $product, $preview);
        $visit = VisitDevice::fromRequest(request());
        $payload = $this->modules->applyDeviceFlags($payload, $visit);
        $locale = (string) ($payload['locale'] ?? 'es');
        $usesModules = $this->modules->pageUsesModules($page);
        $payload['engine'] = $usesModules ? 'twig' : 'legacy';

        $bodyAttrs = ['class' => '', 'id' => '', 'style' => ''];
        $extraStylesheets = [];

        if ($usesModules) {
            $staticHtml = '';
            $layout = $this->modules->layoutFor($page, $visit);
            if (in_array('static', $layout, true)) {
                $previewCtx = new RenderContext($payload, $design, $page, '', $visit);
                $staticHtml = $this->modules->renderStaticBody((string) ($page['html'] ?? ''), $previewCtx);
            }
            $ctx = new RenderContext($payload, $design, $page, $staticHtml, $visit);
            $html = $this->modules->assemble($ctx, $layout);
            $html = $this->themes->rewriteHtmlAssetUrls($html, $design['assets'] ?? []);
            $storeSlug = (string) ($store->slug ?? '');
            if ($storeSlug !== '') {
                $html = $this->themes->rewriteRelativePageHrefs($html, url('/s/'.$storeSlug.'/pages'));
            }
            $html = $this->copyLocalizer->localize($html, $locale, $design);
            $html = DesignAssetUrl::localize($html);
        } else {
            $rawHtml = (string) ($page['html'] ?? '');
            $bodyAttrs = $this->themes->extractBodyAttributes($rawHtml);
            $extraStylesheets = $this->themes->extractStylesheetUrls($rawHtml);
            $html = $this->themes->extractBodyHtml($rawHtml);
            if (($page['type'] ?? '') === 'product') {
                $html = $this->themes->stripGarbageDescriptionBinds($html);
                $html = $this->themes->placeVariantsHook($html);
                $html = $this->themes->ensureMediaCarouselInHtml($html);
            }
            $html = $this->themes->rewriteHtmlAssetUrls($html, $design['assets'] ?? []);
            $storeSlug = (string) ($store->slug ?? '');
            if ($storeSlug !== '') {
                $html = $this->themes->rewriteRelativePageHrefs($html, url('/s/'.$storeSlug.'/pages'));
            }
            $html = $this->copyLocalizer->localize($html, $locale, $design);
            $html = DesignAssetUrl::localize($this->replaceTokens($html, $store, $payload));
        }
        $css = DesignAssetUrl::localize($this->themes->composeStorefrontCss($design, $page));
        $css = DesignAssetUrl::localize($this->themes->rewriteCssAssetUrls($css, $design['assets'] ?? []));
        $js = trim((string) ($design['global_js'] ?? '')."\n".(string) ($page['js'] ?? ''));
        $js = $this->themes->neutralizeThemeCheckoutJs($js);
        $js = $this->copyLocalizer->localize($js, $locale, $design);
        $js = DesignAssetUrl::localize($js);
        $checkout = $design['checkout'] ?? [];
        $payloadJson = DesignAssetUrl::localize(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if (is_array($page) && isset($page['title'])) {
            $page['title'] = $this->copyLocalizer->localizeTitle((string) $page['title'], $locale);
        }

        $visitClass = 'md-visit-'.$visit;
        $themeClass = $this->themes->themeBodyClass($design);
        $bodyAttrs['class'] = trim((string) ($bodyAttrs['class'] ?? '').' '.$visitClass.' '.$themeClass);

        $body = view('storefront.custom', [
            'store' => $store,
            'html' => $html,
            'css' => $css,
            'js' => $js,
            'checkout' => $checkout,
            'page' => $page,
            'preview' => $preview,
            'payloadJson' => $payloadJson,
            'bodyClass' => $bodyAttrs['class'] ?? '',
            'bodyId' => $bodyAttrs['id'] ?? '',
            'bodyStyle' => $bodyAttrs['style'] ?? '',
            'extraStylesheets' => $extraStylesheets,
            'platformModules' => true,
            'moduleEngine' => $usesModules,
            'htmlLang' => $payload['locale'] ?? 'en',
            'seo' => $payload['seo'] ?? [],
            'pixels' => $payload['pixels'] ?? [],
            'deferPixels' => (bool) ($payload['modules']['cookies'] ?? false),
            'visit' => $visit,
        ])->render();

        return response($body, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function design(object $store): array
    {
        return $this->themes->normalize($store);
    }

    public function hasActiveDesign(object $store): bool
    {
        $design = $this->design($store);
        if (! ($design['enabled'] ?? false)) {
            return false;
        }
        $landing = $this->themes->findPageByType($design, 'landing', true)
            ?: $this->themes->findPageByHandle($design, 'index', true);

        return $landing && $this->modules->pageIsRenderable($landing);
    }

    /**
     * @param  object|int  $storeOrId
     * @param  array<string, mixed>|null  $design
     */
    public function productsForStore(object|int $storeOrId, ?array $design = null, bool $preview = false): Collection
    {
        $storeId = is_object($storeOrId) ? (int) $storeOrId->id : (int) $storeOrId;
        $slug = is_object($storeOrId) ? (string) $storeOrId->slug : null;
        $useThemePdp = $design && (
            $this->themes->findPageByType($design, 'product', ! $preview)
            || $this->themes->findPageByType($design, 'product', false)
        );

        $query = Product::query()
            ->with('variants')
            ->where('store_id', $storeId);
        if ($preview) {
            $query->whereIn('status', ['live', 'draft']);
        } else {
            $query->where('status', 'live');
        }

        $store = $storeOrId instanceof Store ? $storeOrId : Store::query()->find($storeId);

        return $query
            ->orderByDesc('is_featured')
            ->orderBy('price')
            ->limit(200)
            ->get()
            ->map(function (Product $p) use ($slug, $useThemePdp, $store, $preview) {
                $url = ($slug && ($useThemePdp || $preview))
                    ? route('store.design.page', ['slug' => $slug, 'handle' => $p->slug])
                    : route('store.product', $p->slug);

                return $this->productMapper->fromProduct($p, $store instanceof Store ? $store : null, [
                    'full' => false,
                    'url' => $url,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>|null  $product
     * @return array<string, mixed>
     */
    public function payload(object $store, Collection $products, array $design, array $page, ?array $product, bool $preview = false): array
    {
        $slug = $store->slug;
        $locale = $this->locales->applyForStore($store instanceof Store ? $store : null, $design);
        // Preferencia completa (es_MX) para selects; applyForStore ya fijó app()->setLocale()
        if ($store instanceof Store) {
            $full = trim((string) $store->defaultLocale());
            if ($full !== '') {
                $locale = $full;
            }
        }
        $commerce = $store instanceof Store ? $store->commerceEnabled() : false;
        $cartPayload = ['items' => [], 'coupon' => null, 'count' => 0, 'totals' => ['subtotal' => 0, 'discount' => 0, 'total' => 0]];
        if ($commerce && $store instanceof Store) {
            try {
                $cartPayload = $this->cart->get($store);
            } catch (\Throwable) {
                // preview / CLI without session
            }
        }

        $modules = ['commerce' => $commerce];
        if ($store instanceof Store) {
            foreach ($store->pluginFlags() as $key => $on) {
                $modules[$key] = (bool) $on;
            }
        }
        // Vista previa de Diseño: misma experiencia que el sandbox de la plantilla
        if ($preview) {
            $modules['commerce'] = true;
            foreach (array_keys(config('multidrop.plugins', [])) as $key) {
                $modules[$key] = true;
            }
        }

        $socialCfg = $store instanceof Store
            ? (array) data_get($store->settings, 'social_proof', [])
            : [];

        $currency = $store instanceof Store
            ? $store->currency()
            : strtoupper((string) ($design['default_currency'] ?? $design['currency'] ?? 'USD'));
        $currencies = $store instanceof Store
            ? $store->enabledCurrencies()
            : array_values(array_filter(array_map('strval', $design['currencies'] ?? [])));
        if ($currencies === [] && $currency !== '') {
            $currencies = [$currency];
        }
        $localesList = $store instanceof Store
            ? $store->enabledLocales()
            : array_values(array_filter(array_map('strval', $design['locales'] ?? [])));
        if ($localesList === [] && $locale !== '') {
            $localesList = [$locale];
        }

        return [
            'store' => [
                'id' => (int) $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'type' => $store->store_type ?? null,
            ],
            'commerce' => $preview ? true : $commerce,
            'modules' => $modules,
            'plugin_devices' => $store instanceof Store ? $store->pluginDevices() : [],
            'locale' => $locale,
            'locales' => $localesList,
            'currency' => $currency,
            'currencies' => $currencies,
            'i18n' => trans('storefront'),
            'preview' => $preview,
            'social_proof' => [
                'interval_seconds' => max(4, (int) ($socialCfg['interval_seconds'] ?? 9)),
                'display_seconds' => max(3, (int) ($socialCfg['display_seconds'] ?? 5)),
                'position' => in_array(($socialCfg['position'] ?? 'bottom-left'), ['bottom-left', 'bottom-right'], true)
                    ? ($socialCfg['position'] ?? 'bottom-left')
                    : 'bottom-left',
            ],
            'newsletter' => $store instanceof Store
                ? app(\App\Services\Commerce\NewsletterService::class)->forStore($store)
                : app(\App\Services\Commerce\NewsletterService::class)->forSandbox(),
            'cookies' => $store instanceof Store
                ? app(\App\Services\Storefront\CookieConsentService::class)->forStore($store)
                : app(\App\Services\Storefront\CookieConsentService::class)->forSandbox(),
            'roulette' => $store instanceof Store
                ? app(\App\Services\Commerce\RouletteWheelService::class)->forStore($store)
                : app(\App\Services\Commerce\RouletteWheelService::class)->forSandbox(),
            'cross_sell' => (function () use ($store, $cartPayload, $modules) {
                if (! ($modules['cross_sell'] ?? false)) {
                    return ['offer' => null, 'products' => []];
                }
                $svc = app(\App\Services\Commerce\CrossSellOfferService::class);
                $offer = $store instanceof Store ? $svc->forStore($store) : $svc->forSandbox();
                if (! ($offer['enabled'] ?? true)) {
                    return ['offer' => $offer, 'products' => []];
                }
                $products = $store instanceof Store
                    ? $svc->suggestedProducts($store, $cartPayload['items'] ?? [], $offer)
                    : [];

                return ['offer' => $offer, 'products' => $products];
            })(),
            'upsell' => (function () use ($store, $cartPayload, $modules) {
                if (! ($modules['upsell'] ?? false) || ! ($store instanceof Store)) {
                    return ['enabled' => false, 'offer_product' => null];
                }

                return app(\App\Services\Commerce\UpsellOfferService::class)->forStore(
                    $store,
                    $cartPayload['items'] ?? []
                );
            })(),
            'payments_enabled' => $store instanceof Store ? $store->paymentsEnabled() : false,
            'products' => $products->values()->all(),
            'product' => $product,
            'star_product' => (function () use ($store, $products, $product) {
                if ($store instanceof Store) {
                    $star = $store->starProduct();
                    if ($star) {
                        $mapped = $products->firstWhere('id', (int) $star->id);
                        if (is_array($mapped)) {
                            return $mapped;
                        }
                    }
                }
                $fromList = $products->first(fn ($p) => ! empty($p['is_star']) || ! empty($p['star']));
                if (is_array($fromList)) {
                    return $fromList;
                }
                if (is_array($product) && ! empty($product['id'])) {
                    return $product;
                }

                return $products->first();
            })(),
            'cart' => $cartPayload,
            'page' => [
                'id' => $page['id'] ?? null,
                'type' => $page['type'] ?? null,
                'handle' => $page['handle'] ?? null,
                'title' => $page['title'] ?? null,
            ],
            'checkout' => [
                'primary' => data_get($design, 'checkout.primary', '#0f766e'),
                'accent' => data_get($design, 'checkout.accent', '#f59e0b'),
                'button' => data_get($design, 'checkout.button', '#0f766e'),
                'bg' => data_get($design, 'checkout.bg', '#ffffff'),
                'text' => data_get($design, 'checkout.text', '#0f172a'),
            ],
            'turnstile' => [
                'enabled' => $this->turnstile->enabled(),
                'site_key' => $this->turnstile->siteKey(),
                'local_bypass' => $this->turnstile->isLocalBypassActive(),
            ],
            'urls' => [
                'home' => route('store.design.show', $slug),
                'catalog' => route('store.design.page', ['slug' => $slug, 'handle' => 'catalog']),
                'cart' => route('store.design.page', ['slug' => $slug, 'handle' => 'cart']),
                'checkout' => route('store.design.page', ['slug' => $slug, 'handle' => 'checkout']),
                'products_json' => route('store.design.products', $slug),
                'coupon' => route('store.coupon'),
                'cart_json' => route('store.cart.show', $slug),
                'cart_add' => route('store.cart.add', $slug),
                'cart_items' => url('/s/'.$slug.'/cart/items'),
                'cart_coupon' => route('store.cart.coupon', $slug),
                'cart_coupon_clear' => route('store.cart.coupon.clear', $slug),
                'cart_shipping' => route('store.cart.shipping', $slug),
                'cart_cross_sell' => route('store.cart.cross-sell', $slug),
                'cart_upsell' => route('store.cart.upsell', $slug),
                'checkout_place' => route('store.checkout.place', $slug),
                'newsletter_subscribe' => route('store.newsletter.subscribe', $slug),
                'track' => route('store.order.track', $slug),
                'cj_video' => route('store.media.cj-video'),
            ],
            'shipping_countries' => app(\App\Services\Commerce\ShippingQuoteService::class)->countries(
                $store instanceof Store ? $store : null
            ),
            'geo' => app(VisitorCountryResolver::class)->forPayload(
                $store instanceof Store ? $store : null
            ),
            'catalog_per_page' => $store instanceof Store ? $store->catalogPerPage() : 12,
            'urgency' => $this->urgencyPayload($store instanceof Store ? $store : null),
            'seo' => $this->seoPayload($store instanceof Store ? $store : null, $page, $product),
            'pixels' => $this->pixelsPayload(),
            'assets' => collect($design['assets'] ?? [])->map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? null,
                'url' => $a['url'] ?? null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function replaceTokens(string $html, object $store, array $payload): string
    {
        $urls = $payload['urls'] ?? [];
        $star = is_array($payload['star_product'] ?? null) ? $payload['star_product'] : [];
        $map = [
            '{{store.name}}' => e($store->name),
            '{{store.slug}}' => e($store->slug),
            '{{store.id}}' => (string) $store->id,
            '{{products.count}}' => (string) count($payload['products'] ?? []),
            '{{star.name}}' => e((string) ($star['name'] ?? $star['title'] ?? '')),
            '{{star.price_formatted}}' => e((string) ($star['price_formatted'] ?? '')),
            '{{star.url}}' => e((string) ($star['url'] ?? '#')),
            '{{star.image}}' => e((string) ($star['image'] ?? '')),
            '{{urls.home}}' => e((string) ($urls['home'] ?? '#')),
            '{{urls.catalog}}' => e((string) ($urls['catalog'] ?? '#')),
            '{{urls.cart}}' => e((string) ($urls['cart'] ?? '#')),
            '{{urls.checkout}}' => e((string) ($urls['checkout'] ?? '#')),
            '{{urls.products_json}}' => e((string) ($urls['products_json'] ?? '#')),
        ];

        return strtr($html, $map);
    }

    /**
     * El theme filtra [data-md-featured] / estrella. Si la mini no marcó ninguno,
     * el grid queda vacío aunque haya catálogo.
     *
     * @param  Collection<int, array<string, mixed>>  $products
     * @return Collection<int, array<string, mixed>>
     */
    protected function ensureCatalogFlags(Collection $products): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $featuredCount = $products->filter(
            fn ($p) => ! empty($p['featured']) || ! empty($p['is_featured'])
        )->count();
        $anyStar = $products->contains(fn ($p) => ! empty($p['is_star']) || ! empty($p['star']));
        $fillFeatured = $featuredCount < 4;

        return $products->values()->map(function ($p, int $i) use ($fillFeatured, $anyStar) {
            if (! is_array($p)) {
                return $p;
            }
            if ($fillFeatured && $i < 8) {
                $p['featured'] = true;
                $p['is_featured'] = true;
            }
            if (! $anyStar && $i === 0) {
                $p['is_star'] = true;
                $p['star'] = true;
                $p['featured'] = true;
                $p['is_featured'] = true;
            }

            return $p;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function urgencyPayload(?Store $store): array
    {
        if (! $store) {
            return ['bar_text' => null, 'show_stock' => true, 'ends_at' => null, 'stock' => 7];
        }
        $settings = is_array($store->settings) ? $store->settings : [];
        $offer = \App\Models\Offer::query()
            ->where('store_id', $store->id)
            ->where('type', 'flash')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->first();

        $bar = trim((string) ($settings['urgency_bar_text'] ?? data_get($offer?->rules, 'bar_text', '') ?: ''));

        return [
            'bar_text' => $bar !== '' ? $bar : null,
            'show_stock' => (bool) ($settings['urgency_show_stock'] ?? data_get($offer?->rules, 'show_stock', true)),
            'ends_at' => $offer && $offer->ends_at ? (string) $offer->ends_at : null,
            'stock' => (int) ($offer->stock_threshold ?? 7),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>|null  $product
     * @return array{title: string, description: ?string, og_image: ?string, canonical: ?string}
     */
    protected function seoPayload(?Store $store, array $page, ?array $product): array
    {
        $storeSeo = $store ? $store->seoMeta() : ['title' => null, 'description' => null, 'og_image' => null];
        $pageTitle = trim((string) ($page['title'] ?? ''));
        $productName = is_array($product) ? trim((string) ($product['name'] ?? $product['title'] ?? '')) : '';
        $title = $productName !== ''
            ? $productName
            : ($pageTitle !== '' ? $pageTitle : (string) ($storeSeo['title'] ?? ($store->name ?? 'Tienda')));
        if ($store && $productName === '' && $pageTitle !== '' && ! str_contains($title, $store->name)) {
            $title .= ' — '.$store->name;
        }
        $desc = is_array($product)
            ? trim(strip_tags((string) ($product['summary'] ?? $product['description'] ?? '')))
            : (string) ($storeSeo['description'] ?? '');
        $image = is_array($product)
            ? (string) ($product['image'] ?? '')
            : (string) ($storeSeo['og_image'] ?? '');
        $canonical = null;
        if ($store) {
            $handle = (string) ($page['handle'] ?? 'index');
            $canonical = $handle === 'index' || $handle === ''
                ? route('store.design.show', $store->slug)
                : route('store.design.page', ['slug' => $store->slug, 'handle' => $handle]);
            if (is_array($product) && ! empty($product['slug'])) {
                $canonical = route('store.design.page', ['slug' => $store->slug, 'handle' => $product['slug']]);
            }
        }

        return [
            'title' => $title,
            'description' => $desc !== '' ? mb_substr($desc, 0, 180) : ($storeSeo['description'] ?? null),
            'og_image' => $image !== '' ? $image : ($storeSeo['og_image'] ?? null),
            'canonical' => $canonical,
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
     * @return array{engine: string, layout: list<string>, modules: list<array<string, mixed>>}
     */
    public function inspect(Store $store, string $handle = 'index'): array
    {
        $design = $this->themes->forDisplay($this->themes->normalize($store));
        $page = $this->themes->findPageByHandle($design, $handle, false)
            ?: $this->themes->findPageByType($design, $handle, false)
            ?: $this->themes->findPageByType($design, 'landing', false);
        if (! $page) {
            return ['engine' => 'none', 'layout' => [], 'modules' => []];
        }
        $products = $this->ensureCatalogFlags($this->productsForStore($store, $design, true));
        $product = ($page['type'] ?? '') === 'product' ? $products->first() : null;
        $payload = $this->payload($store, $products, $design, $page, is_array($product) ? $product : null, true);
        $visit = VisitDevice::fromRequest(request());
        $payload = $this->modules->applyDeviceFlags($payload, $visit);
        $uses = $this->modules->pageUsesModules($page);
        $payload['engine'] = $uses ? 'twig' : 'legacy';
        $staticHtml = '';
        $layout = $this->modules->layoutFor($page, $visit);
        $ctx = new RenderContext($payload, $design, $page, $staticHtml, $visit);
        if (in_array('static', $layout, true)) {
            $staticHtml = $this->modules->renderStaticBody((string) ($page['html'] ?? ''), $ctx);
            $ctx = new RenderContext($payload, $design, $page, $staticHtml, $visit);
        }

        return [
            'engine' => $payload['engine'],
            'handle' => (string) ($page['handle'] ?? ''),
            'type' => (string) ($page['type'] ?? ''),
            'visit' => $visit,
            'layout' => $layout,
            'modules' => $this->modules->inspect($ctx, $layout),
        ];
    }
}
