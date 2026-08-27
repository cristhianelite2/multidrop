<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\CrossSellRule;
use App\Services\Admin\StoreContext;
use App\Services\Commerce\CrossSellOfferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrossSellController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext, CrossSellOfferService $offers)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $rules = CrossSellRule::query()
            ->with(['triggerProduct', 'offerProduct'])
            ->where('store_id', $store->id)
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get();

        return view('admin.store.cross-sells.index', [
            'store' => $store,
            'rules' => $rules,
            'offer' => $offers->forStore($store),
        ]);
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.cross-sells.form', [
            'store' => $store,
            'rule' => new CrossSellRule(['priority' => 1, 'is_active' => true]),
            'products' => $this->storeProductOptions($store),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store);
        $data['store_id'] = $store->id;
        $data['is_active'] = $request->boolean('is_active');
        CrossSellRule::create($data);

        return redirect()->route('admin.store.cross-sells.index')->with('success', 'Cross-sell creado.');
    }

    public function edit(CrossSellRule $cross_sell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $cross_sell->store_id === (int) $store->id, 404);

        return view('admin.store.cross-sells.form', [
            'store' => $store,
            'rule' => $cross_sell,
            'products' => $this->storeProductOptions($store),
        ]);
    }

    public function update(Request $request, CrossSellRule $cross_sell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $cross_sell->store_id === (int) $store->id, 404);
        $data = $this->validated($request, $store);
        $data['is_active'] = $request->boolean('is_active');
        $cross_sell->update($data);

        return redirect()->route('admin.store.cross-sells.index')->with('success', 'Cross-sell actualizado.');
    }

    public function destroy(CrossSellRule $cross_sell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $cross_sell->store_id === (int) $store->id, 404);
        $cross_sell->delete();

        return redirect()->route('admin.store.cross-sells.index')->with('success', 'Cross-sell eliminado.');
    }

    public function updateOffer(Request $request, StoreContext $storeContext, CrossSellOfferService $offers)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:100'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'badge' => ['nullable', 'string', 'max:40'],
            'cta' => ['required', 'string', 'max:50'],
            'hint' => ['nullable', 'string', 'max:220'],
            'extra_discount_type' => ['required', 'in:percent,fixed'],
            'extra_discount_value' => ['required', 'numeric', 'min:1', 'max:10000'],
            'max_products' => ['required', 'integer', 'min:1', 'max:8'],
            'expires_minutes' => ['required', 'integer', 'min:3', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $settings = $store->settings ?? [];
        $settings['cross_sell_offer'] = $offers->normalize([
            ...$data,
            'enabled' => $request->boolean('enabled', true),
        ]);
        $store->settings = $settings;
        $store->save();

        return back()->with('success', 'Oferta mágica de Cross Sell guardada.');
    }

    protected function validated(Request $request, $store): array
    {
        return $request->validate([
            'trigger_product_id' => [
                'required',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'offer_product_id' => [
                'required',
                'different:trigger_product_id',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'priority' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
    }
}
