<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;

class SocialProofController extends Controller
{
    use ResolvesCurrentStore;

    public function edit(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $cfg = data_get($store->settings, 'social_proof', []);

        return view('admin.store.social-proof.edit', [
            'store' => $store,
            'intervalSeconds' => (int) ($cfg['interval_seconds'] ?? 9),
            'displaySeconds' => (int) ($cfg['display_seconds'] ?? 5),
            'position' => (string) ($cfg['position'] ?? 'bottom-left'),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'interval_seconds' => ['required', 'integer', 'min:4', 'max:60'],
            'display_seconds' => ['required', 'integer', 'min:3', 'max:20'],
            'position' => ['required', 'in:bottom-left,bottom-right'],
        ]);

        $settings = $store->settings ?? [];
        $settings['social_proof'] = [
            'interval_seconds' => (int) $data['interval_seconds'],
            'display_seconds' => (int) $data['display_seconds'],
            'position' => $data['position'],
        ];
        $store->settings = $settings;
        $store->save();

        return back()->with('success', 'Prueba social guardada.');
    }
}
