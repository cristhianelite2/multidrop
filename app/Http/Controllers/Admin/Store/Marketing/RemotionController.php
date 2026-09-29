<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\RemotionAdsRenderService;
use App\Services\Marketing\RemotionMusicCatalog;
use App\Services\Marketing\RemotionStyleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RemotionController extends Controller
{
    use ResolvesCurrentStore;

    public function generate(
        Request $request,
        StoreContext $storeContext,
        RemotionAdsRenderService $remotion,
        RemotionMusicCatalog $musicCatalog,
        RemotionStyleCatalog $styleCatalog
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
            'product_id' => ['required', 'integer'],
            'preset' => ['nullable', 'string', Rule::in($styleCatalog->options())],
            'voice' => ['nullable', 'file', 'mimes:mp3,wav,m4a,mpeg', 'max:20480'],
            'sync' => ['nullable', 'boolean'],
            'music_id' => ['nullable', 'string', 'in:random,none,'.implode(',', array_keys($musicCatalog->all()))],
            'music_volume' => ['nullable', 'numeric', 'min:0', 'max:0.6'],
        ]);
        if (($data['music_id'] ?? 'random') === 'random') {
            $musicIds = array_keys($musicCatalog->all());
            $data['music_id'] = $musicIds ? $musicIds[array_rand($musicIds)] : 'none';
        }

        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();
        $prompt = MarketingPrompt::query()
            ->where('store_id', $store->id)
            ->where('campaign_id', $campaign->id)
            ->where('id', $data['prompt_id'])
            ->firstOrFail();
        $product = Product::query()->where('store_id', $store->id)->whereKey($data['product_id'])->firstOrFail();
        abort_unless((int) $prompt->product_id === (int) $product->id, 422, 'El prompt no corresponde al producto seleccionado.');
        abort_unless($campaign->products()->whereKey($product->id)->exists(), 422, 'El producto no pertenece a esta campaña.');

        $sync = array_key_exists('sync', $data)
            ? (bool) $data['sync']
            : (bool) config('multidrop.marketing.remotion.sync', false);

        $result = $remotion->enqueue(
            $store,
            $campaign,
            $prompt,
            $request->file('voice'),
            (string) ($data['preset'] ?? $styleCatalog->defaultId()),
            $sync,
            (int) $product->id,
            (string) ($data['music_id'] ?? 'random'),
            (float) ($data['music_volume'] ?? 0.3)
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        $status = ($result['existing'] ?? false) || $sync ? 'running' : 'queued';

        return response()->json([
            'ok' => true,
            'job_id' => $result['job_id'],
            'status' => $status,
            'sync' => $sync,
            'style_id' => $result['style_id'] ?? null,
            'style_label' => $result['style_label'] ?? null,
            'message' => ($result['existing'] ?? false)
                ? 'Ya existe una generación activa para este producto.'
                : ($sync
                ? '0/6 Descargando medios en segundo plano…'
                : 'En cola — ejecuta php artisan queue:work'),
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
            'style_id' => $status['style_id'] ?? null,
            'style_label' => $status['style_label'] ?? null,
            'done' => $done,
            'failed' => $failed,
        ]);
    }

    public function active(
        Request $request,
        StoreContext $storeContext,
        RemotionAdsRenderService $remotion
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->whereKey($data['campaign_id'])
            ->firstOrFail();
        abort_unless($campaign->products()->whereKey($data['product_id'])->exists(), 404);

        return response()->json($remotion->activeJob(
            (int) $store->id,
            (int) $campaign->id,
            (int) $data['product_id']
        ));
    }
}
