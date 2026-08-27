<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Storefront\CustomDesignRenderer;
use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\StorefrontProductMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CustomDesignController extends Controller
{
    public function show(string $slug, CustomDesignRenderer $renderer): Response
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();

        // /s/{slug} es el canal de diseño custom: servir aunque el toggle "enabled"
        // aún no esté marcado, si hay HTML de landing.
        return $renderer->response($store, [
            'handle' => 'index',
            'serve_design' => true,
        ]);
    }

    public function page(string $slug, string $handle, CustomDesignRenderer $renderer, DesignThemeService $themes): Response
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        $design = $themes->normalize($store);
        $reserved = ['catalog', 'cart', 'checkout', 'index', 'product', 'about', 'faq', 'page'];

        // Plantilla PDP (live preferida; si no, draft con HTML)
        $productPage = $themes->findPageByType($design, 'product', true)
            ?: $themes->findPageByType($design, 'product', false);

        if ($handle === 'product') {
            $sample = $renderer->productsForStore($store, $design, true)->first();

            return $renderer->response($store, [
                'page_id' => $productPage['id'] ?? null,
                'handle' => 'product',
                'product' => $sample,
                'serve_design' => true,
                'allow_draft_page' => true,
            ]);
        }

        // /s/{store}/pages/{product-slug} → PDP custom (live o draft: el catálogo /s/ también lista drafts)
        if ($productPage && ! in_array($handle, $reserved, true)) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('slug', $handle)
                ->whereIn('status', ['live', 'draft'])
                ->first();

            if ($product) {
                return $renderer->response($store, [
                    'page_id' => $productPage['id'] ?? null,
                    'handle' => 'product',
                    'product' => $this->productPayload($product, $store),
                    'serve_design' => true,
                    'allow_draft_page' => true,
                ]);
            }

            $cmsPage = $themes->findPageByHandle($design, $handle, false);
            if (! $cmsPage) {
                abort(404, 'Producto no encontrado.');
            }
        }

        return $renderer->response($store, [
            'handle' => $handle,
            'serve_design' => true,
            'allow_draft_page' => true,
        ]);
    }

    public function products(string $slug, CustomDesignRenderer $renderer): JsonResponse
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        try {
            $prefs = app(\App\Services\Storefront\StorefrontVisitorPrefs::class);
            $prefs->capture(request(), $store);
            $prefs->applyOverrides($store);
        } catch (\Throwable) {
        }
        // JSON público: solo productos live
        $products = $renderer->productsForStore($store, null, false);

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ],
            'products' => $products->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function productPayload(Product $p, Store $store): array
    {
        $p->loadMissing('variants');

        return app(StorefrontProductMapper::class)->fromProduct($p, $store, [
            'full' => true,
            'url' => route('store.design.page', ['slug' => $store->slug, 'handle' => $p->slug]),
        ]);
    }
}
