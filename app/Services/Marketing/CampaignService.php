<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\Store;
use App\Services\Storefront\DesignThemeService;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function __construct(
        protected DesignThemeService $themes,
        protected VideoIngestService $videos
    ) {}

    public function maxDailySpend(): float
    {
        return max(1, (float) config('multidrop.human_in_the_loop.max_daily_ad_spend', 50));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(Store $store, array $raw): array
    {
        $cap = $this->maxDailySpend();
        $budget = max(0, (float) ($raw['daily_budget'] ?? 0));
        $clamped = min($budget, $cap);
        $platforms = [];
        foreach ((array) ($raw['platforms'] ?? []) as $p) {
            $p = strtolower(trim((string) $p));
            if (in_array($p, ['meta', 'tiktok'], true)) {
                $platforms[] = $p;
            }
        }
        if ($platforms === []) {
            $platforms = ['meta', 'tiktok'];
        }
        $status = (string) ($raw['status'] ?? 'draft');
        if (! in_array($status, ['draft', 'ready', 'paused'], true)) {
            $status = 'draft';
        }
        $handle = trim((string) ($raw['landing_handle'] ?? ''));
        $url = trim((string) ($raw['landing_url'] ?? ''));
        if ($url === '' && $handle !== '') {
            $url = $this->urlForHandle($store, $handle);
        }

        return [
            'name' => mb_substr(trim((string) ($raw['name'] ?? '')) ?: 'Campaña', 0, 120),
            'status' => $status,
            'platforms' => array_values(array_unique($platforms)),
            'daily_budget' => $clamped,
            'currency' => $store->currency(),
            'landing_handle' => $handle !== '' ? mb_substr($handle, 0, 80) : null,
            'landing_url' => $url !== '' ? mb_substr($url, 0, 500) : null,
            'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 2000) ?: null,
            'budget_clamped' => $budget > $cap,
            'budget_cap' => $cap,
        ];
    }

    /**
     * @return list<array{handle: string, title: string, type: string}>
     */
    public function pageOptions(Store $store): array
    {
        $design = $this->themes->normalize($store);
        $out = [];
        foreach ($design['pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $handle = trim((string) ($page['handle'] ?? ''));
            if ($handle === '') {
                continue;
            }
            $out[] = [
                'handle' => $handle,
                'title' => (string) ($page['title'] ?? $handle),
                'type' => (string) ($page['type'] ?? ''),
            ];
        }

        return $out;
    }

    public function urlForHandle(Store $store, string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '' || $handle === 'index') {
            return route('store.design.show', $store->slug);
        }

        return route('store.design.page', ['slug' => $store->slug, 'handle' => $handle]);
    }

    /**
     * Payload Advantage+ / Smart+ (borrador local, sin gastar).
     *
     * @return array<string, mixed>
     */
    public function prepareDraft(Store $store, MarketingCampaign $campaign): array
    {
        $campaign->loadMissing('videos');
        $pixel = trim((string) (\App\Models\PlatformSetting::getValue('marketing.meta_pixel_id') ?: ''));
        $landing = $campaign->landing_url
            ?: ($campaign->landing_handle ? $this->urlForHandle($store, (string) $campaign->landing_handle) : $store->publicUrl());
        $creatives = [];
        foreach ($campaign->videos as $video) {
            $creatives[] = [
                'id' => $video->id,
                'url' => $video->publicUrl(),
                'name' => $video->original_name,
                'stripped' => $video->stripped_at !== null,
            ];
        }

        $platforms = $campaign->platformList();
        $payload = [
            'objective' => [
                'meta' => 'OUTCOME_SALES',
                'meta_mode' => 'advantage_plus_shopping',
                'tiktok' => 'SMART_PLUS',
            ],
            'status' => 'PAUSED',
            'human_approval_required' => (bool) config('multidrop.human_in_the_loop.require_approval_for_ads', true),
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
            ],
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'platforms' => $platforms,
                'daily_budget' => (float) $campaign->daily_budget,
                'currency' => $campaign->currency,
                'landing_url' => $landing,
                'landing_handle' => $campaign->landing_handle,
            ],
            'pixel' => [
                'meta' => $pixel !== '' ? $pixel : null,
            ],
            'creatives' => $creatives,
            'prepared_at' => now()->toIso8601String(),
            'note' => 'Borrador local. v1 no publica ni gasta en Ads Manager.',
        ];

        $metaId = in_array('meta', $platforms, true) ? 'draft_local_'.$campaign->id.'_meta' : null;
        $ttId = in_array('tiktok', $platforms, true) ? 'draft_local_'.$campaign->id.'_tiktok' : null;

        $campaign->fill([
            'status' => 'ready',
            'landing_url' => $landing,
            'draft_payload' => $payload,
            'meta_draft_id' => $metaId,
            'tiktok_draft_id' => $ttId,
        ]);
        $campaign->save();

        return $payload;
    }

    public function duplicate(Store $store, MarketingCampaign $campaign): MarketingCampaign
    {
        $campaign->load(['prompts', 'videos']);

        return DB::transaction(function () use ($store, $campaign) {
            $copy = $campaign->replicate([
                'creatify_link_id',
                'meta_draft_id',
                'tiktok_draft_id',
                'draft_payload',
                'insights',
                'advice',
                'advice_at',
            ]);
            $copy->name = $this->copyName($store, (string) $campaign->name);
            $copy->status = 'draft';
            $copy->creatify_link_id = null;
            $copy->meta_draft_id = null;
            $copy->tiktok_draft_id = null;
            $copy->draft_payload = null;
            $copy->insights = null;
            $copy->advice = null;
            $copy->advice_at = null;
            $copy->save();

            $promptMap = [];
            foreach ($campaign->prompts as $prompt) {
                $newPrompt = $prompt->replicate();
                $newPrompt->store_id = $store->id;
                $newPrompt->campaign_id = $copy->id;
                $newPrompt->save();
                $promptMap[(int) $prompt->id] = $newPrompt;
            }

            foreach ($campaign->videos as $video) {
                $linked = null;
                if ($video->prompt_id && isset($promptMap[(int) $video->prompt_id])) {
                    $linked = $promptMap[(int) $video->prompt_id];
                }
                $this->videos->copyToCampaign($store, $copy, $video, $linked);
            }

            return $copy->refresh();
        });
    }

    protected function copyName(Store $store, string $name): string
    {
        $base = trim($name);
        if ($base === '') {
            $base = 'Campaña';
        }
        $base = mb_substr($base, 0, 100);
        $candidate = mb_substr($base.' (copia)', 0, 120);
        $n = 2;
        while (MarketingCampaign::query()->where('store_id', $store->id)->where('name', $candidate)->exists()) {
            $candidate = mb_substr($base.' (copia '.$n.')', 0, 120);
            $n++;
            if ($n > 80) {
                return mb_substr($base.' (copia '.now()->format('His').')', 0, 120);
            }
        }

        return $candidate;
    }
}
