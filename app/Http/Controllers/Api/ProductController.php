<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Store;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->searchQuery(null, $request);
        $paginator = $query->with('variants:id,product_id,sku,name,options,price')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Product $product) => $this->export($product));

        return $this->respond($this->paginate($paginator));
    }

    public function byStore(Store $store, Request $request): JsonResponse
    {
        $query = $this->searchQuery($store->id, $request);
        $paginator = $query->with('variants:id,product_id,sku,name,options,price')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Product $product) => $this->export($product));

        return $this->respond($this->paginate($paginator));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $storeId = (int) $data['store_id'];
        $data['slug'] = $this->uniqueSlug($storeId, (string) ($data['slug'] ?? $data['name']));
        $data['is_featured'] = filter_var($data['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $product = Product::create($data);

        return $this->respond($this->export($product->load('variants')), 201, 'Producto creado.');
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('variants');

        return $this->respond($this->export($product));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->rules(false));

        if (isset($data['name']) || isset($data['slug']) || $request->filled('slug')) {
            $name = (string) ($data['name'] ?? $product->name);
            $data['slug'] = $this->uniqueSlug(
                (int) $product->store_id,
                (string) ($data['slug'] ?? $name),
                (int) $product->id
            );
        }

        $data['is_featured'] = filter_var(
            $data['is_featured'] ?? (bool) $product->is_featured,
            FILTER_VALIDATE_BOOLEAN
        );

        $product->update($data);

        return $this->respond($this->export($product->fresh()->load('variants')), 200, 'Producto actualizado.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return $this->respond(null, 200, 'Producto eliminado.');
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
            'name' => [$creating ? 'required' : 'nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'sku' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'price' => [$creating ? 'required' : 'nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => [$creating ? 'required' : 'nullable', 'string', 'size:3', Rule::in(array_keys(app(CurrencyService::class)->rates()))],
            'status' => [$creating ? 'required' : 'nullable', Rule::in(['draft', 'live', 'paused', 'archived'])],
            'badge' => ['nullable', 'string', 'max:80'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'verified_data' => ['nullable', 'array'],
            'creative_data' => ['nullable', 'array'],
        ];
    }

    /**
     * Query de productos con filtros: q, status, source, sort, is_featured, store_id.
     */
    protected function searchQuery(?int $storeId, Request $request)
    {
        $query = Product::query();

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        } elseif ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('badge', 'like', $like)
                    ->orWhere('verified_data->cj_pid', 'like', $like)
                    ->orWhere('verified_data->aliexpress_product_id', 'like', $like)
                    ->orWhere('verified_data->product_sku', 'like', $like);
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q);
                }
            });
        }

        if (in_array($request->input('status'), ['draft', 'live', 'paused', 'archived'], true)) {
            $query->where('status', $request->input('status'));
        }

        match ((string) $request->input('source', '')) {
            'cj' => $query->where('verified_data->source', 'cj')
                ->whereNotNull('verified_data->cj_pid')
                ->where('verified_data->cj_pid', '!=', ''),
            'aliexpress' => $query->whereIn('verified_data->source', ['aliexpress', 'aliexpress_es'])
                ->whereNotNull('verified_data->aliexpress_product_id')
                ->where('verified_data->aliexpress_product_id', '!=', ''),
            'manual' => $query->where(function ($w) {
                $w->where(function ($notCj) {
                    $notCj->whereNull('verified_data->source')
                        ->orWhere('verified_data->source', '!=', 'cj')
                        ->orWhereNull('verified_data->cj_pid')
                        ->orWhere('verified_data->cj_pid', '');
                })->where(function ($notAe) {
                    $notAe->whereNull('verified_data->source')
                        ->orWhereNotIn('verified_data->source', ['aliexpress', 'aliexpress_es'])
                        ->orWhereNull('verified_data->aliexpress_product_id')
                        ->orWhere('verified_data->aliexpress_product_id', '');
                });
            }),
            default => null,
        };

        if ($request->filled('is_featured')) {
            $query->where('is_featured', filter_var($request->input('is_featured'), FILTER_VALIDATE_BOOLEAN));
        }

        match ((string) $request->input('sort', 'newest')) {
            'oldest' => $query->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderByDesc('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'stock_desc' => $query->orderByDesc('stock')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        return $query;
    }

    protected function uniqueSlug(int $storeId, string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug) ?: 'producto';
        $candidate = $base;
        $n = 2;
        while (
            Product::query()
                ->where('store_id', $storeId)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    protected function export(Product $product): array
    {
        $product->loadMissing('variants');

        return [
            'id' => $product->id,
            'store_id' => $product->store_id,
            'sku' => $product->sku,
            'name' => $product->name,
            'slug' => $product->slug,
            'image_url' => $product->image_url,
            'description' => $product->description,
            'price' => (float) $product->price,
            'compare_at_price' => $product->compare_at_price !== null ? (float) $product->compare_at_price : null,
            'purchase_price' => $product->purchase_price !== null ? (float) $product->purchase_price : null,
            'currency' => $product->currency,
            'status' => $product->status,
            'badge' => $product->badge,
            'stock' => $product->stock,
            'is_featured' => (bool) $product->is_featured,
            'score' => $product->score,
            'score_band' => $product->score_band,
            'gallery' => $product->galleryImages(),
            'variants_count' => $product->variants->count(),
            'variants' => $product->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'options' => $variant->options,
                'price' => $variant->price !== null ? (float) $variant->price : null,
            ])->values()->all(),
            'created_at' => optional($product->created_at)->toISOString(),
            'updated_at' => optional($product->updated_at)->toISOString(),
        ];
    }
}