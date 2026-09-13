<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\MarketingVideo;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use App\Services\Marketing\PublicationJsonService;
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
            'product_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:'.$maxKb],
            'files' => ['nullable', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:'.$maxKb],
            'from' => ['nullable', 'in:campaign'],
        ]);

        $uploads = [];
        if ($request->hasFile('files')) {
            foreach ((array) $request->file('files') as $f) {
                if ($f) {
                    $uploads[] = $f;
                }
            }
        } elseif ($request->hasFile('file')) {
            $uploads[] = $request->file('file');
        }

        if ($uploads === []) {
            return back()->withErrors(['files' => 'Selecciona al menos un video.'])->withInput();
        }

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
        $productId = null;
        if (! empty($data['product_id'])) {
            $exists = Product::query()
                ->where('store_id', $store->id)
                ->where('id', $data['product_id'])
                ->exists();
            if ($exists) {
                $productId = (int) $data['product_id'];
                $campaign->products()->syncWithoutDetaching([$productId]);
            }
        }

        $saved = 0;
        $stripped = 0;
        foreach ($uploads as $file) {
            $video = $ingest->ingestUpload($store, $campaign, $file, $prompt, $productId);
            $saved++;
            if ($video->stripped_at) {
                $stripped++;
            }
        }

        if ($saved === 1) {
            $msg = $stripped === 1
                ? 'Video guardado. Se quitó la metadata (encoder, software, comentarios).'
                : 'Video guardado. ffmpeg no está disponible: no se pudo limpiar la metadata.';
        } else {
            $msg = $stripped === $saved
                ? $saved.' videos guardados. Se limpió la metadata de todos.'
                : ($stripped > 0
                    ? $saved.' videos guardados. Metadata limpia en '.$stripped.'; el resto sin limpiar (ffmpeg).'
                    : $saved.' videos guardados. ffmpeg no está disponible: no se pudo limpiar la metadata.');
        }

        return $this->afterVideo($data['from'] ?? 'campaign', (int) $campaign->id, $msg, 'productos', $productId);
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

        $productId = (int) $request->input('redirect_product', $video->product_id ?: 0);

        return $this->afterVideo('campaign', (int) $video->campaign_id, 'Copy del anuncio guardado.', 'productos', $productId ?: null);
    }

    public function destroy(Request $request, StoreContext $storeContext, VideoIngestService $ingest, MarketingVideo $video): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);
        $campaignId = (int) $video->campaign_id;
        $from = $request->input('from', 'campaign');
        $tab = $request->input('redirect_tab', 'productos');
        if (! in_array($tab, ['productos', 'publicaciones', 'campana', 'resumen', 'ads', 'prompts'], true)) {
            $tab = 'productos';
        }
        $productId = (int) $request->input('redirect_product', $video->product_id ?: 0);
        $ingest->delete($video);

        return $this->afterVideo((string) $from, $campaignId, 'Video eliminado.', $tab, $productId ?: null);
    }

    public function download(StoreContext $storeContext, VideoIngestService $ingest, MarketingVideo $video): StreamedResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);
        abort_unless(Storage::disk('public')->exists($video->path), 404);
        $name = $ingest->downloadName($video);

        return Storage::disk('public')->download($video->path, $name);
    }

    public function publicationJson(
        StoreContext $storeContext,
        PublicationJsonService $publications,
        MarketingVideo $video
    ) {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $video->store_id === (int) $store->id, 404);

        $json = $publications->forVideo($store, $video);

        return response()->json(
            $json,
            200,
            ['Content-Disposition' => 'attachment; filename="publication-'.$video->id.'.json"'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    protected function afterVideo(string $from, int $campaignId, string $msg, string $tab = 'productos', ?int $productId = null): RedirectResponse
    {
        if ($campaignId < 1) {
            return redirect()->route('admin.store.marketing.campaigns.index')->with('success', $msg);
        }
        $params = ['campaign' => $campaignId, 'tab' => $tab];
        if ($productId && $productId > 0) {
            $params['product'] = $productId;
        }

        return redirect()
            ->route('admin.store.marketing.campaigns.edit', $params)
            ->with('success', $msg);
    }
}
