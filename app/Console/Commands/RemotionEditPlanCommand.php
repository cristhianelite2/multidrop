<?php

namespace App\Console\Commands;

use App\Services\Marketing\RemotionAdsRenderService;
use Illuminate\Console\Command;

class RemotionEditPlanCommand extends Command
{
    protected $signature = 'marketing:remotion-edit-plan
        {jobDir : Ruta absoluta al job en tools/remotion-ads/jobs}
        {--preset=product_presenter : product_presenter|quick_transition}';

    protected $description = 'Genera trabajo/edit_plan.json con MIIA a partir de Whisper + medios';

    public function handle(RemotionAdsRenderService $remotion): int
    {
        $jobDir = (string) $this->argument('jobDir');
        $preset = (string) $this->option('preset');
        if (! in_array($preset, ['product_presenter', 'quick_transition'], true)) {
            $preset = 'product_presenter';
        }

        try {
            $plan = $remotion->writeEditPlanFromMiia($jobDir, $preset);
            $this->info('OK clips='.count($plan['clips'] ?? []).' source='.($plan['source'] ?? 'miia'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
