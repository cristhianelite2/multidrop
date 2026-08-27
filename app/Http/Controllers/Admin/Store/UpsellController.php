<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\UpsellRule;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpsellController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $rules = UpsellRule::query()
            ->with(['triggerProduct', 'offerProduct'])
            ->where('store_id', $store->id)
            ->orderByDesc('id')
            ->get();

        return view('admin.store.upsells.index', compact('store', 'rules'));
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.upsells.form', [
            'store' => $store,
            'rule' => new UpsellRule(['position' => 'pre_pay', 'discount_percent' => 10, 'is_active' => true]),
            'products' => $this->storeProductOptions($store),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store);
        $data['store_id'] = $store->id;
        $data['is_active'] = $request->boolean('is_active');
        UpsellRule::create($data);

        return redirect()->route('admin.store.upsells.index')->with('success', 'Upsell creado.');
    }

    public function edit(UpsellRule $upsell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $upsell->store_id === (int) $store->id, 404);

        return view('admin.store.upsells.form', [
            'store' => $store,
            'rule' => $upsell,
            'products' => $this->storeProductOptions($store),
        ]);
    }

    public function update(Request $request, UpsellRule $upsell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $upsell->store_id === (int) $store->id, 404);
        $data = $this->validated($request, $store);
        $data['is_active'] = $request->boolean('is_active');
        $upsell->update($data);

        return redirect()->route('admin.store.upsells.index')->with('success', 'Upsell actualizado.');
    }

    public function destroy(UpsellRule $upsell, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $upsell->store_id === (int) $store->id, 404);
        $upsell->delete();

        return redirect()->route('admin.store.upsells.index')->with('success', 'Upsell eliminado.');
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
            'position' => ['required', Rule::in(['pre_pay', 'post_pay'])],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
