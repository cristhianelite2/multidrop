<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);

        return response()->json(['ok' => true, 'cart' => $cart->get($store)]);
    }

    public function add(Request $request, string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        $payload = $cart->add(
            $store,
            (int) $data['product_id'],
            (int) ($data['qty'] ?? 1),
            isset($data['variant_id']) ? (int) $data['variant_id'] : null
        );

        return response()->json(['ok' => true, 'cart' => $payload, 'message' => 'Agregado al carrito']);
    }

    public function update(Request $request, string $slug, int $product, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:0', 'max:99'],
            'variant_id' => ['nullable', 'integer'],
            'line_index' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($request->filled('line_index')) {
            $payload = $cart->updateQtyByIndex(
                $store,
                (int) $data['line_index'],
                (int) $data['qty']
            );
        } else {
            $payload = $cart->updateQty(
                $store,
                $product,
                (int) $data['qty'],
                isset($data['variant_id']) ? (int) $data['variant_id'] : null
            );
        }

        return response()->json(['ok' => true, 'cart' => $payload]);
    }

    public function remove(Request $request, string $slug, int $product, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        $variantId = $request->integer('variant_id') ?: null;
        $lineIndex = $request->integer('line_index');
        if ($request->filled('line_index')) {
            $payload = $cart->removeByIndex($store, $lineIndex);
        } else {
            $payload = $cart->remove($store, $product, $variantId ?: null);
        }

        return response()->json(['ok' => true, 'cart' => $payload]);
    }

    public function applyCoupon(Request $request, string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);
        $result = $cart->applyCoupon($store, $data['code']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function clearCoupon(string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);

        return response()->json(['ok' => true, 'cart' => $cart->clearCoupon($store), 'message' => 'Cupón quitado.']);
    }

    public function setShipping(Request $request, string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        $data = $request->validate([
            'country' => ['required', 'string', 'max:8'],
        ]);
        $result = $cart->setShippingCountry($store, $data['country']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function addCrossSell(Request $request, string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        abort_unless($store->pluginEnabled('cross_sell'), 404);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        $result = $cart->addMagicCrossSell(
            $store,
            (int) $data['product_id'],
            (int) ($data['qty'] ?? 1)
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function addUpsell(Request $request, string $slug, CartService $cart): JsonResponse
    {
        $store = $this->store($slug);
        abort_unless($store->commerceEnabled(), 404);
        abort_unless($store->pluginEnabled('upsell'), 404);
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
        ]);
        $result = $cart->addUpsellCombo(
            $store,
            isset($data['product_id']) ? (int) $data['product_id'] : null
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    protected function store(string $slug): Store
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        try {
            $prefs = app(\App\Services\Storefront\StorefrontVisitorPrefs::class);
            $prefs->capture(request(), $store);
            $prefs->applyOverrides($store);
        } catch (\Throwable) {
        }

        return $store;
    }
}
