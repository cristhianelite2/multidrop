<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Domain\Ads\Creatify\CreatifyClient;
use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\Product;
use App\Models\Store;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\CampaignOptimizerService;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\VideoIngestService;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext, CampaignService $campaigns, CampaignOptimizerService $optimizer)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $rows = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->with(['videos' => fn ($q) => $q->orderByDesc('id')])
            ->withCount(['videos', 'prompts'])
            ->orderByDesc('id')
            ->get()
            ->map(function (MarketingCampaign $c) use ($optimizer) {
                $c->kpis = $optimizer->kpis(is_array($c->insights) ? $c->insights : []);

                return $c;
            });

        return view('admin.store.marketing.campaigns.index', [
            'store' => $store,
            'campaigns' => $rows,
            'budgetCap' => $campaigns->maxDailySpend(),
        ]);
    }

    public function create(StoreContext $storeContext, CampaignService $campaigns)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.marketing.campaigns.form', [
            'store' => $store,
            'campaign' => new MarketingCampaign([
                'status' => 'draft',
                'platforms' => ['meta', 'tiktok'],
                'daily_budget' => min(10, $campaigns->maxDailySpend()),
                'currency' => $store->currency(),
            ]),
            'pages' => $campaigns->pageOptions($store),
            'budgetCap' => $campaigns->maxDailySpend(),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext, CampaignService $campaigns)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request);
        $norm = $campaigns->normalize($store, $data);
        $clamped = $norm['budget_clamped'];
        unset($norm['budget_clamped'], $norm['budget_cap']);
        $norm['store_id'] = $store->id;
        $campaign = MarketingCampaign::create($norm);

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', $campaign)
            ->with('success', $clamped
                ? 'Campaña creada. El presupuesto se recortó al tope diario ('.$campaigns->maxDailySpend().' '.$store->currency().').'
                : 'Campaña creada.');
    }

    public function edit(
        StoreContext $storeContext,
        CampaignService $campaigns,
        CampaignOptimizerService $optimizer,
        VideoIngestService $ingest,
        CreatifyClient $creatify,
        MarketingCampaign $campaign
    ) {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $campaign->load(['videos.prompt', 'prompts.product']);

        return view('admin.store.marketing.campaigns.show', [
            'store' => $store,
            'campaign' => $campaign,
            'pages' => $campaigns->pageOptions($store),
            'budgetCap' => $campaigns->maxDailySpend(),
            'kpis' => $optimizer->kpis(is_array($campaign->insights) ? $campaign->insights : []),
            'targets' => $optimizer->normalizeTargets(is_array($campaign->targets) ? $campaign->targets : []),
            'brief' => $optimizer->brief($store, $campaign),
            'webhook' => $optimizer->webhookConfigured(),
            'creatify' => $creatify->connectionStatus(),
            'ffmpeg' => $ingest->ffmpegAvailable(),
            'maxMb' => (int) config('multidrop.marketing.max_video_mb', 80),
            'libraryPrompts' => MarketingPrompt::query()->where('store_id', $store->id)->orderBy('name')->get(['id', 'name', 'campaign_id']),
            'catalogProducts' => Product::query()
                ->where('store_id', $store->id)
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'name', 'slug', 'image_url', 'status']),
            'sellercentralEmbedUrl' => $this->sellercentralEmbedUrl($store),
            'tab' => (string) request('tab', 'resumen'),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext, CampaignService $campaigns, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $data = $this->validated($request);
        $norm = $campaigns->normalize($store, $data);
        $clamped = $norm['budget_clamped'];
        unset($norm['budget_clamped'], $norm['budget_cap']);
        if ($campaign->status === 'ready' && ($norm['status'] ?? '') === 'draft') {
            $norm['draft_payload'] = null;
            $norm['meta_draft_id'] = null;
            $norm['tiktok_draft_id'] = null;
        }
        $campaign->fill($norm)->save();

        if ($request->exists('sellercentral_embed_url')) {
            $this->saveSellercentralEmbedUrl($store, (string) $request->input('sellercentral_embed_url', ''));
        }

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'resumen'])
            ->with('success', $clamped
                ? 'Guardado. Presupuesto recortado al tope diario ('.$campaigns->maxDailySpend().').'
                : 'Campaña guardada.');
    }

    public function insights(Request $request, StoreContext $storeContext, CampaignOptimizerService $optimizer, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $data = $request->validate([
            'spend' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'impressions' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
            'revenue' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $campaign->insights = $optimizer->normalizeInsights($data);
        $campaign->save();

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'resultados'])
            ->with('success', 'Resultados actualizados.');
    }

    public function targets(Request $request, StoreContext $storeContext, CampaignOptimizerService $optimizer, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $data = $request->validate([
            'objective' => ['nullable', 'in:sales,traffic,leads'],
            'audience' => ['nullable', 'string', 'max:400'],
            'countries' => ['nullable', 'string', 'max:80'],
            'age_min' => ['nullable', 'integer', 'min:13', 'max:65'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:65'],
            'interests' => ['nullable', 'string', 'max:400'],
        ]);
        $countries = preg_split('/[,\s]+/', (string) ($data['countries'] ?? '')) ?: [];
        $campaign->targets = $optimizer->normalizeTargets([
            ...$data,
            'countries' => $countries,
        ]);
        $campaign->save();

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'optimizar'])
            ->with('success', 'Target guardado.');
    }

    public function optimize(Request $request, StoreContext $storeContext, CampaignOptimizerService $optimizer, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $optimizer->advise($store, $campaign, $request->boolean('apply'));

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'optimizar'])
            ->with('success', $request->boolean('apply')
                ? 'Consejo aplicado (presupuesto/target, con tope HITL).'
                : 'Consejo generado. Revisa y aplica si te convence.');
    }

    public function brief(StoreContext $storeContext, CampaignOptimizerService $optimizer, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);

        return response()->json(
            $optimizer->brief($store, $campaign),
            200,
            ['Content-Disposition' => 'attachment; filename="campaign-'.$campaign->id.'-brief.json"'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    public function destroy(StoreContext $storeContext, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $campaign->delete();

        return redirect()->route('admin.store.marketing.campaigns.index')->with('success', 'Campaña eliminada.');
    }

    public function duplicate(StoreContext $storeContext, CampaignService $campaigns, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $copy = $campaigns->duplicate($store, $campaign);

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', $copy)
            ->with('success', 'Campaña duplicada: '.$copy->name.'. Los resultados de gasto no se copiaron.');
    }

    public function draft(StoreContext $storeContext, CampaignService $campaigns, MarketingCampaign $campaign)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $campaign->store_id);
        $campaigns->prepareDraft($store, $campaign);

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'resumen'])
            ->with('success', 'Borrador Advantage+/Smart+ listo (PAUSED, sin gastar).');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,ready,paused'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['in:meta,tiktok'],
            'daily_budget' => ['required', 'numeric', 'min:0', 'max:10000'],
            'landing_handle' => ['nullable', 'string', 'max:80'],
            'landing_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sellercentral_embed_url' => ['nullable', 'string', 'max:500'],
        ]);
    }

    protected function assertStore(int $current, int $owner): void
    {
        abort_unless($current === $owner, 404);
    }

    protected function sellercentralEmbedUrl(Store $store): string
    {
        $fromStore = trim((string) data_get($store->settings, 'marketing.sellercentral_embed_url', ''));
        if ($fromStore !== '') {
            return $fromStore;
        }

        return trim((string) config('multidrop.marketing.sellercentral.embed_url', ''));
    }

    protected function saveSellercentralEmbedUrl(Store $store, string $url): void
    {
        $url = trim($url);
        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $settings = is_array($store->settings) ? $store->settings : [];
        $marketing = is_array($settings['marketing'] ?? null) ? $settings['marketing'] : [];
        $default = trim((string) config('multidrop.marketing.sellercentral.embed_url', ''));
        if ($url === '' || $url === $default) {
            unset($marketing['sellercentral_embed_url']);
        } else {
            $marketing['sellercentral_embed_url'] = $url;
        }
        $settings['marketing'] = $marketing;
        $store->settings = $settings;
        $store->save();
    }
}
