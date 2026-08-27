<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;

class PromptController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->seedDefaults($store->id);
        $prompts = MarketingPrompt::query()
            ->where('store_id', $store->id)
            ->with('campaign')
            ->orderByDesc('id')
            ->get();

        return view('admin.store.marketing.prompts.index', [
            'store' => $store,
            'prompts' => $prompts,
        ]);
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.marketing.prompts.form', [
            'store' => $store,
            'prompt' => new MarketingPrompt([
                'language' => 'es',
                'target_platform' => 'Tiktok',
                'style' => 'DynamicProductTemplate',
                'campaign_id' => request()->integer('campaign_id') ?: null,
            ]),
            'campaigns' => $this->campaignOptions($store->id),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store->id);
        $data['store_id'] = $store->id;
        MarketingPrompt::create($data);

        if (! empty($data['campaign_id'])) {
            return redirect()
                ->route('admin.store.marketing.campaigns.edit', ['campaign' => $data['campaign_id'], 'tab' => 'prompts'])
                ->with('success', 'Prompt añadido a la campaña.');
        }

        return redirect()->route('admin.store.marketing.prompts.index')->with('success', 'Prompt guardado.');
    }

    public function edit(StoreContext $storeContext, MarketingPrompt $prompt)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $prompt->store_id === (int) $store->id, 404);

        return view('admin.store.marketing.prompts.form', [
            'store' => $store,
            'prompt' => $prompt,
            'campaigns' => $this->campaignOptions($store->id),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext, MarketingPrompt $prompt)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $prompt->store_id === (int) $store->id, 404);
        $prompt->fill($this->validated($request, $store->id))->save();

        if ($prompt->campaign_id) {
            return redirect()
                ->route('admin.store.marketing.campaigns.edit', ['campaign' => $prompt->campaign_id, 'tab' => 'prompts'])
                ->with('success', 'Prompt actualizado.');
        }

        return back()->with('success', 'Prompt actualizado.');
    }

    public function destroy(StoreContext $storeContext, MarketingPrompt $prompt)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $prompt->store_id === (int) $store->id, 404);
        $campaignId = $prompt->campaign_id;
        $prompt->delete();

        if ($campaignId) {
            return redirect()
                ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaignId, 'tab' => 'prompts'])
                ->with('success', 'Prompt eliminado.');
        }

        return redirect()->route('admin.store.marketing.prompts.index')->with('success', 'Prompt eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, int $storeId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'hook' => ['nullable', 'string', 'max:240'],
            'script' => ['required', 'string', 'max:4000'],
            'audience' => ['nullable', 'string', 'max:240'],
            'language' => ['required', 'string', 'max:16'],
            'style' => ['nullable', 'string', 'max:80'],
            'target_platform' => ['required', 'in:Tiktok,Meta'],
            'campaign_id' => ['nullable', 'integer'],
        ]);
        $cid = $data['campaign_id'] ?? null;
        if ($cid) {
            $ok = MarketingCampaign::query()->where('store_id', $storeId)->where('id', $cid)->exists();
            if (! $ok) {
                $data['campaign_id'] = null;
            }
        }

        return $data;
    }

    protected function campaignOptions(int $storeId)
    {
        return MarketingCampaign::query()
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function seedDefaults(int $storeId): void
    {
        if (MarketingPrompt::query()->where('store_id', $storeId)->exists()) {
            return;
        }
        $seeds = [
            [
                'name' => 'Hook problema + CTA',
                'hook' => '¿Sigues perdiendo tiempo con esto?',
                'script' => "Si te pasa esto todos los días, no estás solo.\nEste pack lo resuelve en minutos.\nToca ahora y llévalo con envío a tu puerta.",
                'audience' => 'Compradores 25-45 que buscan una solución rápida',
                'style' => 'DynamicProductTemplate',
                'target_platform' => 'Tiktok',
                'language' => 'es',
            ],
            [
                'name' => 'Prueba social + oferta',
                'hook' => 'Por eso se está agotando',
                'script' => "Miles ya lo probaron y lo vuelven a pedir.\nHoy el combo tiene precio de lanzamiento.\nEntra antes de que se acabe el stock.",
                'audience' => 'Compradores impulsivos en redes',
                'style' => 'DynamicProductTemplate',
                'target_platform' => 'Meta',
                'language' => 'es',
            ],
        ];
        foreach ($seeds as $row) {
            MarketingPrompt::create(['store_id' => $storeId] + $row);
        }
    }
}
