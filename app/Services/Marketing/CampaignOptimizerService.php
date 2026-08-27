<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\PlatformSetting;
use App\Models\Store;
use Illuminate\Support\Facades\Http;

class CampaignOptimizerService
{
    /**
     * @return array<string, float|int>
     */
    public function normalizeInsights(array $raw): array
    {
        $spend = max(0, (float) ($raw['spend'] ?? 0));
        $impressions = max(0, (int) ($raw['impressions'] ?? 0));
        $clicks = max(0, (int) ($raw['clicks'] ?? 0));
        $conversions = max(0, (int) ($raw['conversions'] ?? 0));
        $revenue = max(0, (float) ($raw['revenue'] ?? 0));

        return [
            'spend' => round($spend, 2),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'conversions' => $conversions,
            'revenue' => round($revenue, 2),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeTargets(array $raw): array
    {
        $countries = [];
        foreach ((array) ($raw['countries'] ?? []) as $c) {
            $c = strtoupper(trim((string) $c));
            if (preg_match('/^[A-Z]{2}$/', $c)) {
                $countries[] = $c;
            }
        }

        return [
            'objective' => in_array(($raw['objective'] ?? ''), ['sales', 'traffic', 'leads'], true)
                ? $raw['objective']
                : 'sales',
            'audience' => mb_substr(trim((string) ($raw['audience'] ?? '')), 0, 400),
            'countries' => array_values(array_unique($countries)),
            'age_min' => max(13, min(65, (int) ($raw['age_min'] ?? 18))),
            'age_max' => max(18, min(65, (int) ($raw['age_max'] ?? 45))),
            'interests' => mb_substr(trim((string) ($raw['interests'] ?? '')), 0, 400),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $insights
     * @return array{spend: float, impressions: int, clicks: int, conversions: int, revenue: float, ctr: float, cpa: float, roas: float}
     */
    public function kpis(?array $insights): array
    {
        $i = $this->normalizeInsights(is_array($insights) ? $insights : []);
        $ctr = $i['impressions'] > 0 ? round(100 * $i['clicks'] / $i['impressions'], 2) : 0.0;
        $cpa = $i['conversions'] > 0 ? round($i['spend'] / $i['conversions'], 2) : 0.0;
        $roas = $i['spend'] > 0 ? round($i['revenue'] / $i['spend'], 2) : 0.0;

        return $i + ['ctr' => $ctr, 'cpa' => $cpa, 'roas' => $roas];
    }

    public function webhookUrl(): string
    {
        return trim((string) config('multidrop.marketing.optimizer.webhook', ''));
    }

    public function webhookConfigured(): bool
    {
        return $this->webhookUrl() !== '';
    }

    /**
     * Brief que se manda a Madgicx / n8n / tu propio cerebro de media buying.
     *
     * @return array<string, mixed>
     */
    public function brief(Store $store, MarketingCampaign $campaign): array
    {
        $campaign->loadMissing(['videos', 'prompts']);
        $kpis = $this->kpis(is_array($campaign->insights) ? $campaign->insights : []);
        $targets = $this->normalizeTargets(is_array($campaign->targets) ? $campaign->targets : []);
        $star = $store->starProduct();
        $ads = [];
        foreach ($campaign->videos as $video) {
            $ads[] = [
                'id' => $video->id,
                'file' => $video->original_name,
                'url' => $video->publicUrl(),
                'headline' => $video->ad_headline,
                'primary_text' => $video->ad_primary_text,
                'cta' => $video->ad_cta ?: 'SHOP_NOW',
                'source' => $video->source,
            ];
        }
        $prompts = [];
        foreach ($campaign->prompts as $p) {
            $prompts[] = [
                'id' => $p->id,
                'name' => $p->name,
                'hook' => $p->hook,
                'audience' => $p->audience,
                'platform' => $p->target_platform,
            ];
        }

        return [
            'goal' => 'maximize_sales',
            'ask' => 'Define y mejora targets, objetivo y presupuesto diario para vender más. Devuelve JSON advice.',
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'url' => $store->publicUrl(),
                'currency' => $store->currency(),
                'locales' => $store->enabledLocales(),
            ],
            'product' => $star ? [
                'id' => $star->id,
                'name' => $star->name,
                'slug' => $star->slug,
            ] : null,
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'platforms' => $campaign->platformList(),
                'daily_budget' => (float) $campaign->daily_budget,
                'landing_url' => $campaign->landing_url,
                'landing_handle' => $campaign->landing_handle,
            ],
            'budget_cap' => (float) config('multidrop.human_in_the_loop.max_daily_ad_spend', 50),
            'pixel' => PlatformSetting::getValue('marketing.meta_pixel_id') ?: null,
            'targets' => $targets,
            'results' => $kpis,
            'ads' => $ads,
            'prompts' => $prompts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function advise(Store $store, MarketingCampaign $campaign, bool $apply = false): array
    {
        $brief = $this->brief($store, $campaign);
        $advice = $this->fromWebhook($brief) ?? $this->localAdvice($brief);
        $advice['at'] = now()->toIso8601String();
        $advice['source'] = $advice['source'] ?? 'local';

        $campaign->advice = $advice;
        $campaign->advice_at = now();

        if ($apply) {
            $cap = (float) $brief['budget_cap'];
            if (isset($advice['budget_daily'])) {
                $campaign->daily_budget = max(0, min($cap, (float) $advice['budget_daily']));
            }
            if (is_array($advice['targets'] ?? null)) {
                $campaign->targets = $this->normalizeTargets($advice['targets'] + (array) $campaign->targets);
            }
        }
        $campaign->save();

        return $advice;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>|null
     */
    protected function fromWebhook(array $brief): ?array
    {
        if (! $this->webhookConfigured()) {
            return null;
        }
        try {
            $res = Http::timeout(25)->acceptJson()->post($this->webhookUrl(), $brief);
            if (! $res->successful()) {
                return null;
            }
            $json = $res->json();
            $row = is_array($json['advice'] ?? null) ? $json['advice'] : (is_array($json) ? $json : null);
            if (! is_array($row) || empty($row['summary'])) {
                return null;
            }
            $row['source'] = 'webhook';

            return $row;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    protected function localAdvice(array $brief): array
    {
        $k = $brief['results'];
        $budget = (float) $brief['campaign']['daily_budget'];
        $cap = (float) $brief['budget_cap'];
        $ads = count($brief['ads']);
        $suggested = $budget;
        $moves = [];

        if ($ads < 2) {
            $moves[] = 'Sube al menos 2 anuncios (hooks distintos). Un solo creativo no deja aprender a Advantage+/Smart+.';
        }
        if ($k['spend'] > 0 && $k['conversions'] === 0) {
            $suggested = round(max(5, $budget * 0.7), 2);
            $moves[] = 'Hay gasto y 0 ventas: no subas presupuesto. Cambia hook/CTA y acota audiencia o prueba el producto estrella.';
        } elseif ($k['roas'] >= 2) {
            $suggested = round(min($cap, $budget * 1.25), 2);
            $moves[] = 'ROAS ≥ 2: escala un 25% el diario (tope HITL '.$cap.'). Mantén los anuncios ganadores.';
        } elseif ($k['roas'] > 0 && $k['roas'] < 1) {
            $suggested = round(max(5, $budget * 0.8), 2);
            $moves[] = 'ROAS < 1: recorta 20% y manda más tráfico al landing actual. Revisa precio/oferta.';
        } else {
            $moves[] = 'Aún no hay resultados: arranca Advantage+ Sales / Smart+ con presupuesto estable 3–5 días antes de tocar target.';
        }
        if ($k['impressions'] > 1000 && $k['ctr'] < 0.8) {
            $moves[] = 'CTR bajo: el video no para el scroll. Genera otra variante de Creatify con un hook más agresivo.';
        }

        $targets = $brief['targets'];
        if (($targets['audience'] ?? '') === '') {
            $targets['audience'] = 'Compradores 25-45 con intención de compra en el nicho de la tienda';
        }
        if (($targets['objective'] ?? '') === '') {
            $targets['objective'] = 'sales';
        }

        return [
            'source' => 'local',
            'summary' => $moves[0] ?? 'Mantén el plan actual y carga resultados reales.',
            'moves' => $moves,
            'budget_daily' => min($cap, max(1, $suggested)),
            'targets' => $targets,
        ];
    }
}
