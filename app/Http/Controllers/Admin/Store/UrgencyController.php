<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;

class UrgencyController extends Controller
{
    use ResolvesCurrentStore;

    public function edit(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        $offer = Offer::query()
            ->where('store_id', $store->id)
            ->where('type', 'flash')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->first() ?? new Offer([
                'type' => 'flash',
                'name' => 'Oferta relámpago',
                'is_active' => true,
                'stock_threshold' => 12,
            ]);

        $settings = $store->settings ?? [];

        return view('admin.store.urgency.edit', [
            'store' => $store,
            'offer' => $offer,
            'barText' => $settings['urgency_bar_text'] ?? 'Oferta por tiempo limitado',
            'showStock' => (bool) ($settings['urgency_show_stock'] ?? true),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'stock_threshold' => ['nullable', 'integer', 'min:0'],
            'bar_text' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable'],
            'show_stock' => ['nullable'],
        ]);

        $payload = [
            'store_id' => $store->id,
            'type' => 'flash',
            'name' => $data['name'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'stock_threshold' => $data['stock_threshold'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'rules' => [
                'bar_text' => $data['bar_text'] ?? null,
                'show_stock' => $request->boolean('show_stock'),
            ],
        ];

        $offer = Offer::query()
            ->where('store_id', $store->id)
            ->where('type', 'flash')
            ->orderByDesc('id')
            ->first();

        if ($offer) {
            $offer->update($payload);
        } else {
            Offer::create($payload);
        }

        $settings = $store->settings ?? [];
        $settings['urgency_bar_text'] = $data['bar_text'] ?? null;
        $settings['urgency_show_stock'] = $request->boolean('show_stock');
        $store->settings = $settings;
        $store->save();

        return back()->with('success', 'Urgencia actualizada.');
    }
}
