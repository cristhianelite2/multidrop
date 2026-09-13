<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Store;
use App\Services\Marketing\RemotionAdsRenderService;
use Illuminate\Console\Command;

class RemotionRenderPromptCommand extends Command
{
    protected $signature = 'marketing:remotion-render
        {prompt : ID del marketing_prompt}
        {--preset=product_presenter}
        {--sync : Ejecutar en este proceso (sin cola)}';

    protected $description = 'Prepara job Remotion para un prompt y encola (o --sync) el render';

    public function handle(RemotionAdsRenderService $remotion): int
    {
        $prompt = MarketingPrompt::query()->find((int) $this->argument('prompt'));
        if (! $prompt) {
            $this->error('Prompt no encontrado');

            return self::FAILURE;
        }
        $store = Store::query()->find($prompt->store_id);
        $campaign = $prompt->campaign_id
            ? MarketingCampaign::query()->find($prompt->campaign_id)
            : null;
        if (! $store || ! $campaign) {
            $this->error('El prompt necesita store y campaign');

            return self::FAILURE;
        }

        $result = $remotion->enqueue(
            $store,
            $campaign,
            $prompt,
            null,
            (string) $this->option('preset'),
            (bool) $this->option('sync')
        );

        if (! ($result['ok'] ?? false)) {
            $this->error($result['message'] ?? 'Error');

            return self::FAILURE;
        }

        $this->info('job_id='.($result['job_id'] ?? ''));

        return self::SUCCESS;
    }
}
