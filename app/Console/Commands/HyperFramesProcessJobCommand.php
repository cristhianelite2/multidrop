<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\HyperFramesAdsRenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HyperFramesProcessJobCommand extends Command
{
    protected $signature = 'marketing:hyperframes-process
        {jobId : UUID del job HyperFrames}
        {--store= : ID tienda}
        {--campaign= : ID campaña}
        {--product= : ID producto}
        {--prompt= : ID prompt opcional}
        {--resume : Reanudar un job pausado en revisión}';

    protected $description = 'Prepara medios + ejecuta pipeline HyperFrames para un job_id';

    public function handle(HyperFramesAdsRenderService $hyperframes): int
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobId = trim((string) $this->argument('jobId'));
        $cached = Cache::get($hyperframes->cacheKey($jobId));
        if (! is_array($cached)) {
            $cached = [];
        }

        $storeId = (int) ($this->option('store') ?: ($cached['store_id'] ?? 0));
        $campaignId = (int) ($this->option('campaign') ?: ($cached['campaign_id'] ?? 0));
        $productId = (int) ($this->option('product') ?: ($cached['product_id'] ?? 0));
        $promptId = (int) ($this->option('prompt') ?: ($cached['prompt_id'] ?? 0));

        $store = Store::query()->find($storeId);
        $campaign = MarketingCampaign::query()->where('store_id', $storeId)->where('id', $campaignId)->first();
        $product = Product::query()->where('store_id', $storeId)->where('id', $productId)->first();
        $prompt = $promptId
            ? MarketingPrompt::query()->where('store_id', $storeId)->where('id', $promptId)->first()
            : null;

        if (! $store || ! $campaign || ! $product) {
            $hyperframes->failJob($jobId, 'Recursos no encontrados');
            $this->error('Recursos no encontrados');

            return self::FAILURE;
        }

        try {
            $video = $this->option('resume')
                ? $hyperframes->resumeAndIngest($store, $campaign, $product, $jobId)
                : $hyperframes->runAndIngest($store, $campaign, $product, $jobId, $prompt);
            if (! $video) {
                $this->info('Pausado para revisión (guion y medios listos).');

                return self::SUCCESS;
            }
            $key = $hyperframes->cacheKey($jobId);
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
            if ($jobDir !== '' && is_dir($jobDir)) {
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
            $this->info('OK video #'.$video->id);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('marketing:hyperframes-process failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $hyperframes->failJob($jobId, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
