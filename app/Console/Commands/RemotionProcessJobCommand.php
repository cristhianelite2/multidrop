<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Store;
use App\Services\Marketing\RemotionAdsRenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Procesa un job Remotion en un proceso PHP aparte (para no bloquear artisan serve).
 */
class RemotionProcessJobCommand extends Command
{
    protected $signature = 'marketing:remotion-process
        {jobId : UUID del job Remotion}
        {--store= : ID tienda (opcional si está en cache)}
        {--campaign= : ID campaña}
        {--prompt= : ID prompt}
        {--preset=product_presenter}';

    protected $description = 'Prepara medios + ejecuta pipeline Remotion para un job_id';

    public function handle(RemotionAdsRenderService $remotion): int
    {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobId = trim((string) $this->argument('jobId'));
        $cached = Cache::get($remotion->cacheKey($jobId));
        if (! is_array($cached)) {
            $cached = [];
        }

        $storeId = (int) ($this->option('store') ?: ($cached['store_id'] ?? 0));
        $campaignId = (int) ($this->option('campaign') ?: ($cached['campaign_id'] ?? 0));
        $promptId = (int) ($this->option('prompt') ?: ($cached['prompt_id'] ?? 0));
        $preset = (string) ($this->option('preset') ?: ($cached['preset'] ?? 'product_presenter'));

        $store = Store::query()->find($storeId);
        $campaign = MarketingCampaign::query()->where('store_id', $storeId)->where('id', $campaignId)->first();
        $prompt = MarketingPrompt::query()->where('store_id', $storeId)->where('id', $promptId)->first();

        if (! $store || ! $campaign || ! $prompt) {
            $remotion->failJob($jobId, 'Recursos no encontrados para el job Remotion.');
            $this->error('Recursos no encontrados');

            return self::FAILURE;
        }

        try {
            $video = $remotion->runAndIngest($store, $campaign, $prompt, $jobId, $preset);
            $key = $remotion->cacheKey($jobId);
            $row = Cache::get($key);
            if (! is_array($row)) {
                $row = $cached;
            }
            $row['state'] = 'done';
            $row['message'] = 'Video listo';
            $row['step'] = 6;
            $row['steps'] = 6;
            $row['video_id'] = $video->id;
            $row['error'] = null;
            Cache::put($key, $row, now()->addHours(6));

            $jobDir = (string) ($row['job_dir'] ?? '');
            if ($jobDir !== '') {
                $remotion->writePublicJobStatus($jobDir, 'done', 'Video listo', 6, 6);
            }

            $this->info('OK video_id='.$video->id);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('marketing:remotion-process failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $remotion->failJob($jobId, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
