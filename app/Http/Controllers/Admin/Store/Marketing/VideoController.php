<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\MarketingVideo;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\VideoIngestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $this->currentStoreOrFail($storeContext);

        return redirect()->route('admin.store.marketing.campaigns.index');
    }

    public function store(Request $request, StoreContext $storeContext, VideoIngestService $ingest): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $maxKb = (int) ceil($ingest->maxBytes() / 1024);
        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'prompt_id' => ['nullable', 'integer'],
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:'.$maxKb],
            'from' => ['nullable', 'in:campaign'],
        ]);
        $campaign = MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $data['campaign_id'])
            ->firstOrFail();
        $prompt = null;
        if (! empty($data['prompt_id'])) {
            $prompt = MarketingPrompt::query()
                ->where('store_id', $store->id)
                ->where('id', $data['prompt_id'])
                ->first();
        }
        $video = $ingest->ingestUpload($store, $campaign, $request->file('file'), $prompt);
        $msg = $video->stripped_at
            ? 'Video guardado. Se quitó la metadata (encoder, software, comentarios).'
            : 'Video guardado. ffmpeg no está disponible: no se pudo limpiar la metadata.';

        return $this->afterVideo($data['from'] ?? 'campaign', (int) $campaign->id, $msg);
    }

    public function update(Request $request, StoreContext $storeContext, MarketingVideo $video): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);
        $data = $request->validate([
            'ad_headline' => ['nullable', 'string', 'max:120'],
            'ad_primary_text' => ['nullable', 'string', 'max:500'],
            'ad_cta' => ['nullable', 'in:SHOP_NOW,LEARN_MORE,SIGN_UP,ORDER_NOW,GET_OFFER'],
        ]);
        $video->fill($data)->save();

        return $this->afterVideo('campaign', (int) $video->campaign_id, 'Copy del anuncio guardado.');
    }

    public function destroy(Request $request, StoreContext $storeContext, VideoIngestService $ingest, MarketingVideo $video): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);
        $campaignId = (int) $video->campaign_id;
        $from = $request->input('from', 'campaign');
        $ingest->delete($video);

        return $this->afterVideo((string) $from, $campaignId, 'Video eliminado.');
    }

    public function download(StoreContext $storeContext, VideoIngestService $ingest, MarketingVideo $video): StreamedResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);
        abort_unless(Storage::disk('public')->exists($video->path), 404);
        $name = $ingest->downloadName($video);

        return Storage::disk('public')->download($video->path, $name);
    }

    protected function afterVideo(string $from, int $campaignId, string $msg): RedirectResponse
    {
        if ($campaignId < 1) {
            return redirect()->route('admin.store.marketing.campaigns.index')->with('success', $msg);
        }

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', ['campaign' => $campaignId, 'tab' => 'ads'])
            ->with('success', $msg);
    }
}
