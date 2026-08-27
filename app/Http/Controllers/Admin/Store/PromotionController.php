<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $coupons = Coupon::query()
            ->where('store_id', $store->id)
            ->orderByDesc('id')
            ->get();

        return view('admin.store.promotions.index', compact('store', 'coupons'));
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.promotions.form', [
            'store' => $store,
            'coupon' => new Coupon([
                'type' => 'percent',
                'is_active' => true,
                'value' => 10,
            ]),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store);
        $data['store_id'] = $store->id;
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');

        Coupon::create($data);

        return redirect()->route('admin.store.promotions.index')->with('success', 'Cupón creado.');
    }

    public function edit(Coupon $coupon, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $coupon->store_id === (int) $store->id, 404);

        return view('admin.store.promotions.form', compact('store', 'coupon'));
    }

    public function update(Request $request, Coupon $coupon, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $coupon->store_id === (int) $store->id, 404);

        $data = $this->validated($request, $store, $coupon);
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');
        $coupon->update($data);

        return redirect()->route('admin.store.promotions.index')->with('success', 'Cupón actualizado.');
    }

    public function destroy(Coupon $coupon, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $coupon->store_id === (int) $store->id, 404);
        $coupon->delete();

        return redirect()->route('admin.store.promotions.index')->with('success', 'Cupón eliminado.');
    }

    protected function validated(Request $request, $store, ?Coupon $coupon = null): array
    {
        return $request->validate([
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('coupons', 'code')
                    ->where(fn ($q) => $q->where('store_id', $store->id))
                    ->ignore($coupon?->id),
            ],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }
}
