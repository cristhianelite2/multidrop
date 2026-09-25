<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\HyperFramesAdsRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HyperFramesController extends Controller
{
    use ResolvesCurrentStore;

    public function generate(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        if (! $hyperframes->configured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Configura HYPERFRAMES_ADS_URL (https://hyperframes.ceballosleon.com) y HYPERFRAMES_ADS_TOKEN. Arranca el bridge local.',
            ], 422);
        }

        $styleKeys = array_keys((array) config('multidrop.marketing.hyperframes.visual_styles', []));
        $defaultStyle = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'prompt_id' => ['nullable', 'integer'],
            'use_miia' => ['nullable', 'boolean'],
            'visual_style' => ['nullable', 'string', 'max:40', Rule::in($styleKeys !== [] ? $styleKeys : [$defaultStyle])],
            'sync' => ['nullable', 'boolean'],
        ]);

        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('id', $data['product_id'])
            ->firstOrFail();

        $useMiia = array_key_exists('use_miia', $data)
            ? (bool) $data['use_miia']
            : empty($data['prompt_id']);

        $prompt = null;
        if (! $useMiia) {
            if (empty($data['prompt_id'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Marca “Usar MIIA para guiones” o elige al menos un prompt guardado.',
                ], 422);
            }
            $prompt = MarketingPrompt::query()
                ->where('store_id', $store->id)
                ->where('id', $data['prompt_id'])
                ->firstOrFail();
        }

        $visualStyle = trim((string) ($data['visual_style'] ?? $defaultStyle)) ?: $defaultStyle;
        if ($styleKeys !== [] && ! in_array($visualStyle, $styleKeys, true)) {
            $visualStyle = $defaultStyle;
        }

        $sync = array_key_exists('sync', $data)
            ? (bool) $data['sync']
            : (bool) config('multidrop.marketing.hyperframes.sync', true);

        $result = $hyperframes->enqueue($store, $campaign, $product, $prompt, $sync, [
            'visual_style' => $visualStyle,
            'use_miia' => $prompt === null,
            'preview' => true,
        ]);

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        $jobId = (string) $result['job_id'];

        return response()->json([
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'preparing',
            'preview' => true,
            'message' => 'Preparando guion y medios… Te mostraremos una revisión antes de renderizar.',
        ]);
    }

    public function poll(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'string', 'max:80'],
        ]);

        $status = $hyperframes->status($data['job_id']);
        if ((int) ($status['store_id'] ?? 0) !== (int) $store->id && ($status['state'] ?? '') !== 'unknown') {
            return response()->json(['ok' => false, 'message' => 'Job de otra tienda'], 403);
        }

        $state = (string) ($status['state'] ?? 'unknown');
        $done = $state === 'done';
        $failed = $state === 'failed';
        $cancelled = $state === 'cancelled';
        $review = $state === 'awaiting_review';

        $response = [
            'ok' => ! $failed && ! $cancelled,
            'status' => $state,
            'message' => $status['message'] ?? '',
            'step' => $status['step'] ?? null,
            'steps' => $status['steps'] ?? null,
            'video_id' => $status['video_id'] ?? null,
            'scrape' => $status['scrape'] ?? null,
            'creative' => $status['creative'] ?? null,
            'done' => $done,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'review' => $review,
        ];

        if ($review) {
            $response['review_payload'] = $hyperframes->reviewPayload($data['job_id']);
        }

        return response()->json($response);
    }

    public function confirm(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'string', 'max:80'],
            'texts' => ['nullable', 'array'],
            'texts.*' => ['nullable', 'string'],
            'visual_style' => ['nullable', 'string', 'max:40'],
            'exclude_images' => ['nullable', 'array'],
            'exclude_images.*' => ['nullable', 'string', 'max:120'],
            'exclude_videos' => ['nullable', 'array'],
            'exclude_videos.*' => ['nullable', 'string', 'max:120'],
        ]);

        $cached = $hyperframes->statusMeta($data['job_id']);
        if (! is_array($cached) || (int) ($cached['store_id'] ?? 0) !== (int) $store->id) {
            return response()->json(['ok' => false, 'message' => 'Job no encontrado o de otra tienda'], 422);
        }

        $applied = $hyperframes->applyReviewEdits($data['job_id'], array_filter([
            'texts' => $data['texts'] ?? null,
            'visual_style' => $data['visual_style'] ?? null,
            'exclude_images' => $data['exclude_images'] ?? null,
            'exclude_videos' => $data['exclude_videos'] ?? null,
        ], fn ($v) => $v !== null), (int) $store->id);

        if (! ($applied['ok'] ?? false)) {
            return response()->json($applied, 422);
        }

        $sync = (bool) config('multidrop.marketing.hyperframes.sync', true);
        $resumed = $hyperframes->resume(
            $data['job_id'],
            (int) $store->id,
            (int) ($cached['campaign_id'] ?? 0),
            (int) ($cached['product_id'] ?? 0),
            $sync
        );

        if (! ($resumed['ok'] ?? false)) {
            return response()->json($resumed, 422);
        }

        return response()->json([
            'ok' => true,
            'job_id' => $resumed['job_id'],
            'status' => 'running',
            'message' => $resumed['message'] ?? 'Render en curso…',
        ]);
    }

    public function regeneratePayload(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'video_id' => ['required', 'integer'],
        ]);

        $payload = $hyperframes->reviewPayloadFromVideo((int) $data['video_id'], (int) $store->id);
        if ($payload === null) {
            return response()->json(['ok' => false, 'message' => 'Video no encontrado o sin guion.'], 422);
        }

        return response()->json(['ok' => true, 'payload' => $payload]);
    }

    public function regenerate(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);

        $styleKeys = array_keys((array) config('multidrop.marketing.hyperframes.visual_styles', []));
        $defaultStyle = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'video_id' => ['required', 'integer'],
            'texts' => ['nullable', 'array'],
            'texts.*' => ['nullable', 'string'],
            'visual_style' => ['nullable', 'string', 'max:40', Rule::in($styleKeys !== [] ? $styleKeys : [$defaultStyle])],
        ]);

        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('id', $data['product_id'])
            ->firstOrFail();

        $visualStyle = trim((string) ($data['visual_style'] ?? $defaultStyle)) ?: $defaultStyle;

        $result = $hyperframes->regenerate($store, $campaign, $product, (int) $data['video_id'], [
            'texts' => $data['texts'] ?? [],
        ], $visualStyle);

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        return response()->json([
            'ok' => true,
            'job_id' => (string) $result['job_id'],
            'status' => 'preparing',
            'preview' => true,
            'message' => 'Regenerando video a partir del guion actualizado…',
        ]);
    }

    public function cancel(
        Request $request,
        StoreContext $storeContext,
        HyperFramesAdsRenderService $hyperframes
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'string', 'max:80'],
        ]);

        $result = $hyperframes->cancel($data['job_id'], (int) $store->id);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
