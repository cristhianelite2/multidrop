<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Models\SellerCentralVideoJob;
use App\Services\Admin\StoreContext;
use App\Services\SellerCentral\SellerCentralException;
use App\Services\SellerCentral\SellerCentralVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotebookLmController extends Controller
{
    use ResolvesCurrentStore;

    public function generate(
        Request $request,
        StoreContext $storeContext,
        SellerCentralVideoService $videos
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        if (! $videos->hasConnection($store)) {
            return response()->json([
                'ok' => false,
                'message' => 'Configura Base URL + API key en Marketing → Publicaciones (Seller Central).',
            ], 422);
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'mode' => ['required', Rule::in(['assets', 'url_only'])],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'video_format' => ['nullable', Rule::in(['short', 'explainer', 'cinematic'])],
        ]);

        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('id', $data['product_id'])
            ->firstOrFail();

        if (! $campaign->products()->where('products.id', $product->id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'El producto no está en esta campaña.'], 422);
        }

        try {
            $job = $videos->start(
                $store,
                $campaign,
                $product,
                (string) $data['mode'],
                $data['instructions'] ?? null,
                (string) ($data['video_format'] ?? config('multidrop.marketing.sellercentral.video_format', 'short'))
            );
        } catch (SellerCentralException $e) {
            $failed = SellerCentralVideoJob::query()
                ->where('store_id', $store->id)
                ->where('campaign_id', $campaign->id)
                ->where('product_id', $product->id)
                ->where('status', 'error')
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'job' => $failed?->toPollPayload(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Tarea enviada a Seller Central / NotebookLM.',
            'job' => $job->toPollPayload(),
            'poll_seconds' => (int) config('multidrop.marketing.sellercentral.video_poll_seconds', 8),
        ]);
    }

    public function poll(
        Request $request,
        StoreContext $storeContext,
        SellerCentralVideoService $videos
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'integer'],
        ]);

        $job = SellerCentralVideoJob::query()
            ->where('store_id', $store->id)
            ->where('id', $data['job_id'])
            ->firstOrFail();

        if (! $job->isTerminal()) {
            $job = $videos->syncFromRemote($job);
        }

        return response()->json([
            'ok' => true,
            'job' => $job->toPollPayload(),
            'poll_seconds' => (int) config('multidrop.marketing.sellercentral.video_poll_seconds', 8),
        ]);
    }

    public function cancel(
        Request $request,
        StoreContext $storeContext,
        SellerCentralVideoService $videos
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'integer'],
        ]);

        $job = SellerCentralVideoJob::query()
            ->where('store_id', $store->id)
            ->where('id', $data['job_id'])
            ->firstOrFail();

        if ($job->isTerminal()) {
            return response()->json([
                'ok' => true,
                'job' => $job->toPollPayload(),
                'message' => 'La tarea ya estaba finalizada.',
            ]);
        }

        $job = $videos->stop($job);

        return response()->json([
            'ok' => true,
            'message' => 'Generación detenida.',
            'job' => $job->toPollPayload(),
        ]);
    }

    public function retry(
        Request $request,
        StoreContext $storeContext,
        SellerCentralVideoService $videos
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        if (! $videos->hasConnection($store)) {
            return response()->json([
                'ok' => false,
                'message' => 'Configura Base URL + API key en Marketing → Publicaciones (Seller Central).',
            ], 422);
        }

        $data = $request->validate([
            'job_id' => ['required', 'integer'],
        ]);

        $failed = SellerCentralVideoJob::query()
            ->where('store_id', $store->id)
            ->where('id', $data['job_id'])
            ->firstOrFail();

        try {
            $job = $videos->retry($failed);
        } catch (SellerCentralException $e) {
            $latest = SellerCentralVideoJob::query()
                ->where('store_id', $store->id)
                ->where('id', '>=', $failed->id)
                ->where('status', 'error')
                ->orderByDesc('id')
                ->first() ?: $failed;

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'job' => $latest->toPollPayload(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Reintento enviado a Seller Central / NotebookLM.',
            'job' => $job->toPollPayload(),
            'poll_seconds' => (int) config('multidrop.marketing.sellercentral.video_poll_seconds', 8),
        ]);
    }
}
