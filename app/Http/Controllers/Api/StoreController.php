<?php

namespace App\Http\Controllers\Api;

use App\Models\Market;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Store::query()
            ->with('market:id,code,name,locale,currency')
            ->withCount('products')
            ->orderByDesc('id');

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%')
                    ->orWhere('sector', 'like', '%'.$q.'%');
            });
        }

        if ($request->filled('store_type')) {
            $query->where('store_type', $request->input('store_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (Store $store) => $this->export($store));

        return $this->respond($this->paginate($paginator));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $marketId = (int) ($data['market_id'] ?? $this->defaultMarketId());
        $data['slug'] = $this->uniqueSlug((string) ($data['slug'] ?? $data['name']), $marketId);
        $data['market_id'] = $marketId;
        $data['settings'] = is_array($data['settings'] ?? null) ? $data['settings'] : null;

        $store = Store::create($data);

        return $this->respond($this->export($store->load('market')), 201, 'Tienda creada.');
    }

    public function show(Store $store): JsonResponse
    {
        $store->load([
            'market:id,code,name,locale,currency',
            'parent:id,parent_id,name,slug',
            'children:id,parent_id,name,slug,status,store_type',
            'brand:id,name,slug',
        ]);

        return $this->respond($this->export($store));
    }

    public function update(Request $request, Store $store): JsonResponse
    {
        $data = $this->validated($request, $store);
        $data['market_id'] = (int) ($data['market_id'] ?? $store->market_id);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $data['market_id'], (int) $store->id);
        } else {
            unset($data['slug']);
        }

        if (array_key_exists('settings', $data) && ! is_array($data['settings'])) {
            unset($data['settings']);
        }

        $store->update($data);

        return $this->respond($this->export($store->fresh()->load('market')), 200, 'Tienda actualizada.');
    }

    public function destroy(Store $store): JsonResponse
    {
        if ($store->orders()->exists()) {
            return $this->respond(null, 409, 'No se puede eliminar: la tienda tiene pedidos asociados. Archívala (status=archived) en su lugar.');
        }

        $store->delete();

        return $this->respond(null, 200, 'Tienda eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Store $store = null): array
    {
        $marketRule = $store
            ? ['nullable', 'integer', 'exists:markets,id']
            : ['required', 'integer', 'exists:markets,id'];

        return $request->validate([
            'market_id' => $marketRule,
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160'],
            'sector' => ['nullable', 'string', 'max:80'],
            'store_type' => ['nullable', Rule::in(['mega', 'mini'])],
            'status' => ['nullable', Rule::in(['draft', 'live', 'paused', 'archived'])],
            'theme' => ['nullable', 'string', 'max:80'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'parent_id' => ['nullable', 'integer', 'exists:stores,id', Rule::notIn(array_filter([$store?->id]))],
            'settings' => ['nullable', 'array'],
        ]) + [
            'store_type' => 'mini',
            'status' => 'draft',
            'theme' => 'default',
        ];
    }

    protected function uniqueSlug(string $slug, int $marketId, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug) ?: 'tienda';
        $candidate = $base;
        $n = 2;
        while (
            Store::query()
                ->where('market_id', $marketId)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    protected function defaultMarketId(): int
    {
        $code = strtoupper((string) config('multidrop.default_market_code', 'MX'));
        $market = Market::query()->where('code', $code)->first();
        if ($market) {
            return (int) $market->id;
        }

        return (int) (Market::query()->orderBy('id')->value('id') ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function export(Store $store): array
    {
        return [
            'id' => $store->id,
            'brand_id' => $store->brand_id,
            'parent_id' => $store->parent_id,
            'market_id' => $store->market_id,
            'market' => $store->relationLoaded('market') && $store->market
                ? [
                    'id' => $store->market->id,
                    'code' => $store->market->code,
                    'name' => $store->market->name,
                    'locale' => $store->market->locale,
                    'currency' => $store->market->currency,
                ]
                : null,
            'name' => $store->name,
            'slug' => $store->slug,
            'sector' => $store->sector,
            'store_type' => $store->store_type,
            'status' => $store->status,
            'theme' => $store->theme,
            'settings' => $store->settings,
            'products_count' => $store->relationLoaded('products_count') ? (int) $store->products_count : null,
            'created_at' => optional($store->created_at)->toISOString(),
            'updated_at' => optional($store->updated_at)->toISOString(),
        ];
    }
}