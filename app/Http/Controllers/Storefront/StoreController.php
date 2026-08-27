<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\CouponService;
use App\Services\Storefront\CustomDesignRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function home(CustomDesignRenderer $renderer)
    {
        $storeRow = $this->defaultStore();
        $store = Store::query()->find($storeRow->id);

        if ($store && $renderer->hasActiveDesign($store)) {
            return $renderer->response($store);
        }

        $products = DB::table('products')
            ->where('store_id', $storeRow->id)
            ->where('status', 'live')
            ->orderByDesc('is_featured')
            ->orderBy('price')
            ->get();

        $offer = DB::table('offers')
            ->where('store_id', $storeRow->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $coupon = DB::table('coupons')
            ->where('store_id', $storeRow->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $rouletteSlides = DB::table('roulette_slides')
            ->where('store_id', $storeRow->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $store = $storeRow;
        $storeSettings = json_decode($store->settings ?? '{}', true) ?: [];

        return view('storefront.home', compact('store', 'products', 'offer', 'coupon', 'rouletteSlides', 'storeSettings'));
    }

    public function show(string $slug)
    {
        $store = $this->defaultStore();
        $product = DB::table('products')
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->where('status', 'live')
            ->first();

        abort_unless($product, 404);

        $verified = json_decode($product->verified_data ?? '{}', true) ?: [];
        $creative = json_decode($product->creative_data ?? '{}', true) ?: [];

        $upsell = DB::table('upsell_rules as u')
            ->join('products as p', 'p.id', '=', 'u.offer_product_id')
            ->where('u.trigger_product_id', $product->id)
            ->where('u.is_active', true)
            ->select('p.*', 'u.discount_percent', 'u.position')
            ->first();

        $crossSells = DB::table('cross_sell_rules as c')
            ->join('products as p', 'p.id', '=', 'c.offer_product_id')
            ->where('c.trigger_product_id', $product->id)
            ->where('c.is_active', true)
            ->orderBy('c.priority')
            ->select('p.*')
            ->get();

        $related = DB::table('products')
            ->where('store_id', $store->id)
            ->where('status', 'live')
            ->where('id', '!=', $product->id)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) ($store->starProductId() ?? 0)])
            ->orderByDesc('is_featured')
            ->limit(4)
            ->get();

        $offer = DB::table('offers')
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return view('storefront.product', compact(
            'store', 'product', 'verified', 'creative', 'upsell', 'crossSells', 'related', 'offer'
        ));
    }

    public function validateCoupon(Request $request, CouponService $coupons)
    {
        $data = $request->validate([
            'code' => 'required|string|max:40',
            'subtotal' => 'nullable|numeric|min:0',
            'store_id' => ['nullable', 'integer'],
            'store_slug' => ['nullable', 'string', 'max:80'],
        ]);

        $store = null;
        if (! empty($data['store_slug'])) {
            $store = Store::query()->where('slug', $data['store_slug'])->first();
        } elseif (! empty($data['store_id'])) {
            $store = Store::query()->find((int) $data['store_id']);
        } elseif ($request->route('slug')) {
            $store = Store::query()->where('slug', $request->route('slug'))->first();
        }
        if (! $store) {
            $row = $this->defaultStore();
            $store = Store::query()->find($row->id);
        }
        abort_unless($store, 404);

        $result = $coupons->preview($store, $data['code'], (float) ($data['subtotal'] ?? 0));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    protected function defaultStore()
    {
        $store = DB::table('stores')
            ->where('slug', 'baza')
            ->where('store_type', 'mega')
            ->first();

        if (! $store) {
            $store = DB::table('stores')
                ->where('store_type', 'mega')
                ->where('status', 'live')
                ->orderBy('id')
                ->first();
        }

        abort_unless($store, 404, 'Mega-tienda BAZA no configurada');

        return $store;
    }
}
