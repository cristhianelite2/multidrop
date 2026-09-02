<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Domain\Ads\Creatify\CreatifyClient;
use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\ProductMarketingMediaService;
use App\Services\Marketing\VideoIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatifyController extends Controller
{
    use ResolvesCurrentStore;

    public function generate(
        Request $request,
        StoreContext $storeContext,
        CreatifyClient $client,
        CampaignService $campaigns,
        ProductMarketingMediaService $productMedia
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        if (! $client->configured()) {
            return response()->json(['ok' => false, 'message' => 'Creatify no está configurado en .env'], 422);
        }
        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'prompt_id' => ['required', 'integer'],
        ]);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();
        $prompt = MarketingPrompt::query()
            ->where('store_id', $store->id)
            ->where('id', $data['prompt_id'])
            ->with('product')
            ->firstOrFail();

        $product = $prompt->product_id
            ? Product::query()->where('store_id', $store->id)->where('id', $prompt->product_id)->first()
            : null;

        $url = $campaign->landing_url
            ?: ($campaign->landing_handle ? $campaigns->urlForHandle($store, (string) $campaign->landing_handle) : $store->publicUrl());
        if ($product) {
            $url = $productMedia->productPageUrl($store, $product);
        }

        try {
            if ($product) {
                $linkPayload = $productMedia->creatifyLinkPayload($store, $product);
                $linkPayload['url'] = $url;
                $link = $client->createLinkWithParams($linkPayload);
            } else {
                $link = $client->createLink($url, $store->name.' · '.$campaign->name);
            }
            $linkId = (string) ($link['id'] ?? '');
            if ($linkId === '') {
                return response()->json(['ok' => false, 'message' => 'Creatify no devolvió id de enlace.'], 502);
            }
            $campaign->creatify_link_id = $linkId;
            $campaign->save();

            $script = trim((string) $prompt->hook);
            if ($script !== '' && trim((string) $prompt->script) !== '') {
                $script .= "\n\n".$prompt->script;
            } else {
                $script = (string) $prompt->script;
            }

            $job = $client->createLinkToVideo($linkId, [
                'target_platform' => $prompt->target_platform ?: 'Tiktok',
                'language' => $prompt->language ?: 'es',
                'target_audience' => $prompt->audience,
                'override_script' => $script,
                'visual_style' => $prompt->style ?: null,
                'aspect_ratio' => '9x16',
                'video_length' => $prompt->videoLengthSeconds(),
            ]);
            $jobId = (string) ($job['id'] ?? '');
            if ($jobId === '') {
                return response()->json(['ok' => false, 'message' => 'Creatify no devolvió id de job.'], 502);
            }

            return response()->json([
                'ok' => true,
                'job_id' => $jobId,
                'status' => $job['status'] ?? 'pending',
                'link_id' => $linkId,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function poll(
        Request $request,
        StoreContext $storeContext,
        CreatifyClient $client,
        CampaignService $campaigns,
        VideoIngestService $ingest
    ): JsonResponse {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'job_id' => ['required', 'string', 'max:80'],
            'campaign_id' => ['required', 'integer'],
            'prompt_id' => ['required', 'integer'],
        ]);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();
        $prompt = MarketingPrompt::query()
            ->where('store_id', $store->id)
            ->where('id', $data['prompt_id'])
            ->firstOrFail();

        try {
            $job = $client->getLinkToVideo($data['job_id']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }

        $status = strtolower((string) ($job['status'] ?? 'pending'));
        if (in_array($status, ['failed', 'error'], true)) {
            return response()->json([
                'ok' => false,
                'status' => $status,
                'message' => (string) ($job['failed_reason'] ?? 'Creatify falló'),
            ]);
        }
        if ($status !== 'done') {
            return response()->json([
                'ok' => true,
                'status' => $status,
                'progress' => (int) ($job['progress'] ?? 0),
            ]);
        }
        $output = (string) ($job['video_output'] ?? '');
        if ($output === '') {
            return response()->json(['ok' => false, 'message' => 'Job listo pero sin video_output.'], 502);
        }

        $video = $ingest->ingestFromUrl(
            $store,
            $campaign,
            $output,
            $prompt,
            $data['job_id']
        );

        return response()->json([
            'ok' => true,
            'status' => 'done',
            'video_id' => $video->id,
            'url' => $video->publicUrl(),
            'stripped' => $video->stripped_at !== null,
        ]);
    }
}
