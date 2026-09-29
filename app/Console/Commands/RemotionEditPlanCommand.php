<?php

namespace App\Console\Commands;

use App\Services\Marketing\RemotionAdsRenderService;
use App\Services\Marketing\RemotionStyleCatalog;
use Illuminate\Console\Command;

class RemotionEditPlanCommand extends Command
{
    protected $signature = 'marketing:remotion-edit-plan
        {jobDir : Ruta absoluta al job en tools/remotion-ads/jobs}
        {--preset=random : random o un id de tools/remotion-ads/styles.json}';

    protected $description = 'Genera trabajo/edit_plan.json con MIIA a partir de Whisper + medios';

    public function handle(RemotionAdsRenderService $remotion, RemotionStyleCatalog $styles): int
    {
        $jobDir = (string) $this->argument('jobDir');
        $preset = $styles->resolve((string) $this->option('preset'));

        try {
            $plan = $remotion->writeEditPlanFromMiia($jobDir, $preset['id']);
            $this->info('OK estilo='.$preset['id'].' clips='.count($plan['clips'] ?? []).' source='.($plan['source'] ?? 'miia'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
