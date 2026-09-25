<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\HyperFramesAdsRenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RenderHyperFramesAdJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3000;

    public function __construct(
        public int $storeId,
        public int $campaignId,
        public int $productId,
        public string $jobId,
        public ?int $promptId = null,
        public bool $resume = false,
    ) {}

    public function handle(HyperFramesAdsRenderService $hyperframes): void
    {
        $store = Store::query()->find($this->storeId);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $this->storeId)
            ->where('id', $this->campaignId)
            ->first();
        $product = Product::query()
            ->where('store_id', $this->storeId)
            ->where('id', $this->productId)
            ->first();
        $prompt = $this->promptId
            ? MarketingPrompt::query()->where('store_id', $this->storeId)->where('id', $this->promptId)->first()
            : null;

        if (! $store || ! $campaign || ! $product) {
            $this->failCache($hyperframes, 'Recursos no encontrados');

            return;
        }

        try {
            $video = $this->resume
                ? $hyperframes->resumeAndIngest($store, $campaign, $product, $this->jobId)
                : $hyperframes->runAndIngest($store, $campaign, $product, $this->jobId, $prompt);
            if (! $video) {
                return;
            }
            $key = $hyperframes->cacheKey($this->jobId);
            $cached = Cache::get($key);
            if (! is_array($cached)) {
                $cached = [];
            }
            $cached['state'] = 'done';
            $cached['message'] = 'Video HyperFrames listo';
            $cached['step'] = 8;
            $cached['steps'] = 8;
            $cached['video_id'] = $video->id;
            $cached['error'] = null;
            Cache::put($key, $cached, now()->addHours(8));

            $jobDir = (string) ($cached['job_dir'] ?? '');
            if ($jobDir !== '') {
                @file_put_contents(
                    $jobDir.DIRECTORY_SEPARATOR.'status.json',
                    json_encode([
                        'state' => 'done',
                        'message' => 'Video HyperFrames listo',
                        'step' => 8,
                        'steps' => 8,
                        'video_id' => $video->id,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
                );
            }
        } catch (\Throwable $e) {
            Log::error('RenderHyperFramesAdJob failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            $this->failCache($hyperframes, $e->getMessage());
        }
    }

    protected function failCache(HyperFramesAdsRenderService $hyperframes, string $message): void
    {
        $hyperframes->failJob($this->jobId, $message);
    }
}
