<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Services\Admin\StoreContext;
use App\Services\Storefront\CookieConsentService;
use Illuminate\Http\Request;

class CookieConsentController extends Controller
{
    use ResolvesCurrentStore;

    public function edit(StoreContext $storeContext, CookieConsentService $cookies)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.cookies.edit', [
            'store' => $store,
            'cfg' => $cookies->forStore($store),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext, CookieConsentService $cookies)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:400'],
            'policy_url' => ['nullable', 'string', 'max:300'],
            'accept_label' => ['required', 'string', 'max:40'],
            'reject_label' => ['required', 'string', 'max:40'],
            'configure_label' => ['required', 'string', 'max:40'],
            'save_label' => ['required', 'string', 'max:40'],
            'necessary_label' => ['nullable', 'string', 'max:40'],
            'analytics_label' => ['nullable', 'string', 'max:40'],
            'marketing_label' => ['nullable', 'string', 'max:40'],
            'analytics_enabled' => ['nullable', 'boolean'],
            'marketing_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = $store->settings ?? [];
        $settings['cookies'] = $cookies->normalize([
            ...$data,
            'analytics_enabled' => $request->boolean('analytics_enabled'),
            'marketing_enabled' => $request->boolean('marketing_enabled'),
        ]);
        $store->settings = $settings;
        $store->save();

        return back()->with('success', 'Preferencias de cookies guardadas.');
    }
}
