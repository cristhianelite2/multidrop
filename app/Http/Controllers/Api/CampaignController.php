<?php

namespace App\Http\Controllers\Api;

use App\Models\CampaignProductMedia;
use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Services\Currency\CurrencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = MarketingCampaign::query()
            ->withCount(['products', 'videos'])
            ->orderByDesc('id');

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if (in_array($request->input('status'), ['draft', 'ready', 'paused'], true)) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (MarketingCampaign $campaign) => $this->export($campaign));

        return $this->respond($this->paginate($paginator));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $campaign = MarketingCampaign::create($data);

        return $this->respond($this->export($campaign->loadCount(['products', 'videos'])), 201, 'Campaña creada.');
    }

    public function show(MarketingCampaign $campaign): JsonResponse
    {
        $campaign->loadCount(['products', 'videos', 'media']);
        $campaign->load('products:id,store_id,sku,name,slug,image_url,price,currency,status,badge,stock');

        return $this->respond($this->export($campaign, true));
    }

    public function update(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $campaign->update($data);

        return $this->respond($this->export($campaign->fresh()->loadCount(['products', 'videos'])), 200, 'Campaña actualizada.');
    }

    public function destroy(MarketingCampaign $campaign): JsonResponse
    {
        $campaign->delete();

        return $this->respond(null, 200, 'Campaña eliminada.');
    }

    public function products(MarketingCampaign $campaign): JsonResponse
    {
        $products = $campaign->products()
            ->with('variants:id,product_id,sku,name,options,price')
            ->orderBy('products.id')
            ->get();

        $mediaCounts = CampaignProductMedia::query()
            ->where('marketing_campaign_id', $campaign->id)
            ->selectRaw('product_id, COUNT(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $out = $products->map(function (Product $product) use ($campaign, $mediaCounts) {
            $row = $this->productExport($product);
            $row['campaign_media_count'] = (int) ($mediaCounts[(int) $product->id] ?? 0);

            return $row;
        })->values()->all();

        return $this->respond([
            'campaign' => $this->export($campaign->loadCount(['products', 'videos', 'media'])),
            'products' => $out,
        ]);
    }

    public function attachProduct(MarketingCampaign $campaign, Product $product): JsonResponse
    {
        abort_unless((int) $campaign->store_id === (int) $product->store_id, 404);

        $campaign->products()->syncWithoutDetaching([$product->id]);

        return $this->respond([
            'product' => $this->productExport($product),
            'products_count' => $campaign->products()->count(),
        ], 200, 'Producto agregado a la campaña.');
    }

    public function detachProduct(MarketingCampaign $campaign, Product $product): JsonResponse
    {
        abort_unless((int) $campaign->store_id === (int) $product->store_id, 404);

        $campaign->products()->detach($product->id);

        return $this->respond([
            'product_id' => $product->id,
            'products_count' => $campaign->products()->count(),
        ], 200, 'Producto quitado de la campaña.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(bool $creating): array
    {
        return [
            'store_id' => $creating
                ? ['required', 'integer', 'exists:stores,id']
                : ['nullable', 'integer', 'exists:stores,id'],
            'name' => [$creating ? 'required' : 'nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'ready', 'paused'])],
            'platforms' => ['nullable', 'array', 'min:1'],
            'platforms.*' => [Rule::in(['meta', 'tiktok'])],
            'daily_budget' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys(app(CurrencyService::class)->rates()))],
            'landing_handle' => ['nullable', 'string', 'max:80'],
            'landing_url' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function export(MarketingCampaign $campaign, bool $withProducts = false): array
    {
        $out = [
            'id' => $campaign->id,
            'store_id' => $campaign->store_id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'platforms' => $campaign->platformList(),
            'daily_budget' => $campaign->daily_budget !== null ? (float) $campaign->daily_budget : null,
            'currency' => $campaign->currency,
            'landing_handle' => $campaign->landing_handle,
            'landing_url' => $campaign->landing_url,
            'notes' => $campaign->notes,
            'insights' => $campaign->insights,
            'targets' => $campaign->targets,
            'advice' => $campaign->advice,
            'advice_at' => optional($campaign->advice_at)->toISOString(),
            'products_count' => $campaign->relationLoaded('products_count') ? (int) $campaign->products_count : null,
            'videos_count' => $campaign->relationLoaded('videos_count') ? (int) $campaign->videos_count : null,
            'media_count' => $campaign->relationLoaded('media_count') ? (int) $campaign->media_count : null,
            'created_at' => optional($campaign->created_at)->toISOString(),
            'updated_at' => optional($campaign->updated_at)->toISOString(),
        ];

        if ($withProducts) {
            $out['products'] = $campaign->products->map(fn (Product $p) => $this->productExport($p))->all();
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function productExport(Product $product): array
    {
        $product->loadMissing('variants');

        return [
            'id' => $product->id,
            'store_id' => $product->store_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'slug' => $product->slug,
            'image_url' => $product->image_url,
            'price' => (float) $product->price,
            'currency' => $product->currency,
            'status' => $product->status,
            'badge' => $product->badge,
            'stock' => $product->stock,
            'gallery' => $product->galleryImages(),
            'variants_count' => $product->variants->count(),
        ];
    }
}