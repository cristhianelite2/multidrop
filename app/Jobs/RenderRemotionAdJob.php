<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Store;
use App\Services\Marketing\RemotionAdsRenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RenderRemotionAdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(
        public int $storeId,
        public int $campaignId,
        public int $promptId,
        public string $jobId,
        public string $preset = 'product_presenter',
    ) {}

    public function handle(RemotionAdsRenderService $remotion): void
    {
        $store = Store::query()->find($this->storeId);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $this->storeId)
            ->where('id', $this->campaignId)
            ->first();
        $prompt = MarketingPrompt::query()
            ->where('store_id', $this->storeId)
            ->where('id', $this->promptId)
            ->first();

        if (! $store || ! $campaign || ! $prompt) {
            $this->failCache('Recursos no encontrados');

            return;
        }

        try {
            $video = $remotion->runAndIngest($store, $campaign, $prompt, $this->jobId, $this->preset);
            $key = $remotion->cacheKey($this->jobId);
            $cached = Cache::get($key);
            if (! is_array($cached)) {
                $cached = [];
            }
            $cached['state'] = 'done';
            $cached['message'] = 'Video listo';
            $cached['step'] = 6;
            $cached['steps'] = 6;
            $cached['video_id'] = $video->id;
            $cached['error'] = null;
            Cache::put($key, $cached, now()->addHours(6));

            $jobDir = (string) ($cached['job_dir'] ?? '');
            if ($jobDir !== '') {
                @file_put_contents(
                    $jobDir.DIRECTORY_SEPARATOR.'status.json',
                    json_encode([
                        'state' => 'done',
                        'message' => 'Video listo',
                        'step' => 6,
                        'steps' => 6,
                        'video_id' => $video->id,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
                );
            }
        } catch (\Throwable $e) {
            Log::error('RenderRemotionAdJob failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            $this->failCache($e->getMessage());
            throw $e;
        }
    }

    protected function failCache(string $message): void
    {
        $key = 'remotion_ads:'.$this->jobId;
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = [];
        }
        $cached['state'] = 'failed';
        $cached['message'] = $message;
        $cached['error'] = $message;
        Cache::put($key, $cached, now()->addHours(6));
    }
}
