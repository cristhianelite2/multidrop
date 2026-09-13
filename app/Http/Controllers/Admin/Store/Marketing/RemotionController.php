<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\RemotionAdsRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemotionController extends Controller
{
    use ResolvesCurrentStore;

    public function generate(
        Request $request,
        StoreContext $storeContext,
        RemotionAdsRenderService $remotion
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        if (! $remotion->configured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Instala tools/remotion-ads (npm install + pip).',
            ], 422);
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'prompt_id' => ['required', 'integer'],
            'preset' => ['nullable', 'string', 'in:product_presenter,quick_transition'],
            'voice' => ['nullable', 'file', 'mimes:mp3,wav,m4a,mpeg', 'max:20480'],
            'sync' => ['nullable', 'boolean'],
        ]);

        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();
        $prompt = MarketingPrompt::query()
            ->where('store_id', $store->id)
            ->where('id', $data['prompt_id'])
            ->firstOrFail();

        $sync = array_key_exists('sync', $data)
            ? (bool) $data['sync']
            : (bool) config('multidrop.marketing.remotion.sync', false);

        $result = $remotion->enqueue(
            $store,
            $campaign,
            $prompt,
            $request->file('voice'),
            (string) ($data['preset'] ?? config('multidrop.marketing.remotion.default_preset', 'product_presenter')),
            $sync
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        $status = $sync
            ? 'running'
            : 'queued';

        return response()->json([
            'ok' => true,
            'job_id' => $result['job_id'],
            'status' => $status,
            'sync' => $sync,
            'message' => $sync
                ? '0/6 Descargando medios en segundo plano…'
                : 'En cola — ejecuta php artisan queue:work',
        ]);
    }

    public function poll(
        Request $request,
        StoreContext $storeContext,
        RemotionAdsRenderService $remotion
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'string', 'max:80'],
        ]);

        $status = $remotion->status($data['job_id']);
        if ((int) ($status['store_id'] ?? 0) !== (int) $store->id && ($status['state'] ?? '') !== 'unknown') {
            return response()->json(['ok' => false, 'message' => 'Job de otra tienda'], 403);
        }

        $state = (string) ($status['state'] ?? 'unknown');
        $done = $state === 'done';
        $failed = $state === 'failed';

        return response()->json([
            'ok' => ! $failed,
            'status' => $state,
            'message' => $status['message'] ?? $status['pipeline_message'] ?? '',
            'step' => $status['step'] ?? null,
            'steps' => $status['steps'] ?? null,
            'video_id' => $status['video_id'] ?? null,
            'done' => $done,
            'failed' => $failed,
        ]);
    }
}
