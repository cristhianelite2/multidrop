<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Commerce\NewsletterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function subscribe(Request $request, string $slug, NewsletterService $newsletter): JsonResponse
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless($store->pluginEnabled('newsletter'), 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        $result = $newsletter->subscribe($store, $data['email'], 'popup');

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? '',
            'already' => (bool) ($result['already'] ?? false),
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    public function confirm(string $slug, string $token, NewsletterService $newsletter)
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();
        abort_unless($store->pluginEnabled('newsletter'), 404);

        $result = $newsletter->confirmByToken($store, $token);

        return view('storefront.newsletter-confirm', [
            'store' => $store,
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? '',
            'couponCode' => $result['coupon_code'] ?? null,
            'couponHint' => $result['view']['coupon_hint'] ?? null,
            'days' => $result['view']['days'] ?? null,
            'expires' => $result['view']['expires'] ?? null,
            'shopUrl' => route('store.design.show', $store->slug),
        ]);
    }
}
