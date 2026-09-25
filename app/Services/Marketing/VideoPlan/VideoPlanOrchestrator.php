<?php

namespace App\Services\Marketing\VideoPlan;

use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador del plan creativo Miia → video_plan.json.
 *
 * Stub operativo: con video_planner.enabled=false no interviene.
 * Cuando se reactive, aquí irá MiiaVideoPlanner + Sanitizer + Adapter.
 */
class VideoPlanOrchestrator
{
    public function __construct(
        protected VideoPlanCatalog $catalog,
        protected VideoPlanSanitizer $sanitizer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('multidrop.marketing.hyperframes.video_planner.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function process(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        ?MarketingPrompt $prompt,
        string $jobDir,
        string $jobId,
        array $options = []
    ): ?array {
        if (! $this->enabled()) {
            return null;
        }

        Log::info('VideoPlan: enabled pero planificador completo aún no restaurado; se omite', [
            'job_id' => $jobId,
            'product_id' => $product->id,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $planMeta
     */
    public function persistMeta(array $planMeta, string $videoId): void
    {
        if ($videoId === '' || $planMeta === []) {
            return;
        }

        // MarketingVideo aún no tiene columna meta; el plan vive en el job dir.
        Log::info('VideoPlan: meta de plan disponible (sin columna meta en marketing_videos)', [
            'video_id' => $videoId,
            'keys' => array_keys($planMeta),
        ]);
    }
}
