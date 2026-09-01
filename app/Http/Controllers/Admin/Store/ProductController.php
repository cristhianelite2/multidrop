<?php

namespace App\Http\Controllers\Admin\Store;

use App\Domain\AI\ProductNameCompressionService;
use App\Domain\AI\ProductPriceSuggestionService;
use App\Domain\AI\ProductTranslationService;
use App\Domain\Suppliers\Cj\CjProductSyncService;
use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Domain\Scoring\CjPricingEstimator;
use App\Services\Admin\StoreContext;
use App\Services\Currency\CurrencyService;
use App\Services\Storage\ProductMediaMirrorService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        $filters = [
            'q' => trim((string) $request->input('q', '')),
            'status' => (string) $request->input('status', ''),
            'source' => (string) $request->input('source', ''),
            'flag' => (string) $request->input('flag', ''),
            'sort' => (string) $request->input('sort', 'newest'),
            'per_page' => (int) $request->input('per_page', 20),
        ];

        if (! in_array($filters['status'], ['', 'draft', 'live', 'paused', 'archived'], true)) {
            $filters['status'] = '';
        }
        if (! in_array($filters['source'], ['', 'cj', 'aliexpress', 'manual'], true)) {
            $filters['source'] = '';
        }
        if (! in_array($filters['flag'], ['', 'featured', 'star', 'has_variants', 'no_variants', 'no_image'], true)) {
            $filters['flag'] = '';
        }
        if (! in_array($filters['sort'], ['newest', 'oldest', 'name_asc', 'name_desc', 'price_asc', 'price_desc', 'stock_desc'], true)) {
            $filters['sort'] = 'newest';
        }
        if (! in_array($filters['per_page'], [20, 50, 100], true)) {
            $filters['per_page'] = 20;
        }

        $query = Product::query()
            ->where('store_id', $store->id)
            ->withCount('variants');

        if ($filters['q'] !== '') {
            $q = $filters['q'];
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

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['source'] === 'cj') {
            $query->where('verified_data->source', 'cj')
                ->whereNotNull('verified_data->cj_pid')
                ->where('verified_data->cj_pid', '!=', '');
        } elseif ($filters['source'] === 'aliexpress') {
            $query->whereIn('verified_data->source', ['aliexpress', 'aliexpress_es'])
                ->whereNotNull('verified_data->aliexpress_product_id')
                ->where('verified_data->aliexpress_product_id', '!=', '');
        } elseif ($filters['source'] === 'manual') {
            // Ni CJ completo ni AliExpress completo
            $query->where(function ($w) {
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
            });
        }

        if ($filters['flag'] === 'featured') {
            $query->where('is_featured', true);
        } elseif ($filters['flag'] === 'star') {
            $starId = $store->starProductId();
            if ($starId) {
                $query->where('id', $starId);
            } else {
                $query->whereRaw('0 = 1');
            }
        } elseif ($filters['flag'] === 'has_variants') {
            $query->has('variants');
        } elseif ($filters['flag'] === 'no_variants') {
            $query->doesntHave('variants');
        } elseif ($filters['flag'] === 'no_image') {
            $query->where(function ($w) {
                $w->whereNull('image_url')->orWhere('image_url', '');
            });
        }

        match ($filters['sort']) {
            'oldest' => $query->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderByDesc('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'stock_desc' => $query->orderByDesc('stock')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $products = $query->paginate($filters['per_page'])->withQueryString();

        $currency = app(CurrencyService::class);
        $activeFilters = collect($filters)
            ->except(['sort', 'per_page'])
            ->filter(fn ($v) => $v !== '' && $v !== null)
            ->count();

        return view('admin.store.products.index', [
            'store' => $store,
            'products' => $products,
            'filters' => $filters,
            'active_filters' => $activeFilters,
            'locales' => $this->availableLocales($store),
            'has_miia' => (bool) config('ai.providers.miia.api_key')
                || (bool) \App\Models\PlatformSetting::getValue('ai.miia.api_key'),
            'currencies' => $currency->catalog(),
            'locale_currency_map' => collect($this->availableLocales($store))
                ->mapWithKeys(fn ($l) => [$l['locale'] => $currency->currencyForLocale($l['locale'])])
                ->all(),
        ]);
    }

    public function create(StoreContext $storeContext, CurrencyService $currency)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.products.form', [
            'store' => $store,
            'product' => new Product([
                'store_id' => $store->id,
                'currency' => $store->market?->currency ?? $currency->base(),
                'status' => 'draft',
                'is_featured' => false,
            ]),
            'locales' => $this->availableLocales($store),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'currencies' => $currency->catalog(),
            'fx' => $currency->jsPayload(),
            'locale_currency_map' => collect($this->availableLocales($store))
                ->mapWithKeys(fn ($l) => [$l['locale'] => $currency->currencyForLocale($l['locale'])])
                ->all(),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store);
        unset($data['default_locale']);
        $data['store_id'] = $store->id;
        $data['slug'] = $this->uniqueSlug($store->id, $data['slug'] ?? Str::slug($data['name']));
        $data['is_featured'] = $request->boolean('is_featured');
        $data['creative_data'] = $this->mergeCreativeFromRequest($request, new Product);

        $product = Product::create($data);

        if ($request->boolean('is_star')) {
            $store->setStarProductId((int) $product->id);
        }

        return redirect()->route('admin.store.products.index')->with('success', 'Producto creado.');
    }

    public function edit(Product $product, StoreContext $storeContext, CjProductSyncService $sync, CurrencyService $currency)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);
        $product->load('variants');

        // Completar videos/imágenes faltantes desde API CJ al abrir la ficha
        if ($product->isFromCj()) {
            $product = $sync->ensureMedia($product);
            $product->load('variants');
        }

        $videos = [];
        foreach (data_get($product->verified_data, 'videos', []) as $v) {
            if (! is_array($v) || empty($v['url'])) {
                continue;
            }
            $url = (string) $v['url'];
            $videos[] = array_merge($v, [
                'play_url' => $product->isFromCj()
                    ? route('admin.lab.cj.video-proxy', ['u' => $url])
                    : $url,
            ]);
        }

        return view('admin.store.products.form', [
            'store' => $store,
            'product' => $product,
            'locales' => $this->availableLocales($store),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'cj_videos' => $videos,
            'video_proxy_url' => route('admin.lab.cj.video-proxy'),
            'currencies' => $currency->catalog(),
            'fx' => $currency->jsPayload(),
            'locale_currency_map' => collect($this->availableLocales($store))
                ->mapWithKeys(fn ($l) => [$l['locale'] => $currency->currencyForLocale($l['locale'])])
                ->all(),
        ]);
    }

    public function update(Request $request, Product $product, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $data = $this->validated($request, $store, $product);
        unset($data['default_locale']);
        $data['slug'] = $this->uniqueSlug($store->id, $data['slug'] ?? Str::slug($data['name']), $product->id);
        $data['is_featured'] = $request->boolean('is_featured');
        $data['creative_data'] = $this->mergeCreativeFromRequest($request, $product);
        $data['verified_data'] = $this->mergeVerifiedFromRequest($request, $product);
        if ($request->has('verified_videos_present')) {
            $creative = is_array($data['creative_data']) ? $data['creative_data'] : [];
            $creative['has_video'] = ! empty($data['verified_data']['videos'] ?? []);
            $data['creative_data'] = $creative;
        }
        $product->update($data);

        app(ProductMediaMirrorService::class)->mirrorProduct($product->fresh());

        if ($request->boolean('is_star')) {
            $store->setStarProductId((int) $product->id);
        } elseif ((int) $store->starProductId() === (int) $product->id && ! $request->boolean('is_star')) {
            // Solo quitar si desmarcaron explícitamente este estrella
            $store->setStarProductId(null);
        }

        return redirect()->route('admin.store.products.edit', $product)->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);
        $product->delete();

        return redirect()->route('admin.store.products.index')->with('success', 'Producto eliminado.');
    }

    public function destroyVariant(Product $product, ProductVariant $variant, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);
        abort_unless((int) $variant->product_id === (int) $product->id, 404);

        $vid = (string) data_get($variant->options, 'vid', '');
        $variant->delete();

        if ($vid !== '') {
            $creative = is_array($product->creative_data) ? $product->creative_data : [];
            $excluded = array_values(array_unique(array_filter(array_map(
                'strval',
                $creative['excluded_variant_vids'] ?? []
            ))));
            if (! in_array($vid, $excluded, true)) {
                $excluded[] = $vid;
            }
            $creative['excluded_variant_vids'] = $excluded;
            $product->creative_data = $creative;
            $product->save();
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        if (isset($verified['variants']) && is_array($verified['variants'])) {
            $verified['variants'] = array_values(array_filter(
                $verified['variants'],
                fn ($v) => ! is_array($v) || (string) ($v['vid'] ?? '') !== $vid
            ));
            $product->verified_data = $verified;
            $product->save();
        }

        return back()->with('success', 'Variante eliminada. No se volverá a importar en la próxima sync CJ.');
    }

    public function bulkDestroyVariants(Product $product, StoreContext $storeContext, \Illuminate\Http\Request $request)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'Ninguna variante seleccionada.');
        }

        $variants = $product->variants()->whereIn('id', $ids)->get();
        $creative = is_array($product->creative_data) ? $product->creative_data : [];
        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $excluded = array_values(array_unique(array_filter(array_map(
            'strval',
            $creative['excluded_variant_vids'] ?? []
        ))));

        foreach ($variants as $variant) {
            $vid = (string) data_get($variant->options, 'vid', '');
            $variant->delete();
            if ($vid !== '' && ! in_array($vid, $excluded, true)) {
                $excluded[] = $vid;
            }
        }

        $creative['excluded_variant_vids'] = $excluded;
        $product->creative_data = $creative;

        if (isset($verified['variants']) && is_array($verified['variants'])) {
            $verified['variants'] = array_values(array_filter(
                $verified['variants'],
                fn ($v) => ! is_array($v) || ! in_array((string) ($v['vid'] ?? ''), $excluded, true)
            ));
            $product->verified_data = $verified;
        }

        $product->save();

        return back()->with('success', count($variants) . ' variante(s) eliminada(s).');
    }

    public function recalculatePrices(StoreContext $storeContext, CjPricingEstimator $estimator)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $products = Product::query()->where('store_id', $store->id)->get();
        $ok = 0;
        foreach ($products as $product) {
            $verified = is_array($product->verified_data) ? $product->verified_data : [];
            $cost = (float) (data_get($verified, 'pricing.cost_usd') ?? data_get($verified, 'cost_usd') ?? 0);
            if ($cost <= 0) {
                continue;
            }
            $est = $estimator->estimate([
                'price' => $cost,
                'weight' => data_get($verified, 'weight_g') ?? data_get($verified, 'packed_weight_g'),
                'free_shipping' => false,
            ]);
            $verified['pricing'] = array_merge(is_array($verified['pricing'] ?? null) ? $verified['pricing'] : [], $est);
            $verified['sell_usd'] = $est['sell_usd'];
            $verified['cost_usd'] = $est['cost_usd'];
            $verified['ship_usd'] = $est['ship_usd'];
            $product->verified_data = $verified;
            $product->save();
            $ok++;
        }

        return back()->with('success', 'Precios sugeridos recalculados (sin envío) en '.$ok.' producto(s). El precio de venta no se cambió; usa «Usar sugerido» o Sugerir IA si quieres aplicarlo.');
    }

    /**
     * Acciones masivas: eliminar, sync CJ, traducir, cambiar estado.
     */
    public function bulk(
        Request $request,
        StoreContext $storeContext,
        CjProductSyncService $sync,
        ProductTranslationService $translator,
        CurrencyService $currency
    ) {
        $store = $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['delete', 'sync_cj', 'translate', 'status'])],
            'locale' => ['nullable', 'string', 'max:12'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys($currency->rates()))],
            'status' => ['nullable', Rule::in(['draft', 'live', 'paused', 'archived'])],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->get();

        if ($products->isEmpty()) {
            return back()->with('error', 'No se encontraron productos seleccionados.');
        }

        $ok = 0;
        $fail = 0;
        $skipped = 0;
        $messages = [];

        switch ($data['action']) {
            case 'delete':
                foreach ($products as $product) {
                    $product->delete();
                    $ok++;
                }
                $messages[] = "Eliminados: {$ok}";
                break;

            case 'status':
                $status = $data['status'] ?? null;
                if (! $status) {
                    return back()->with('error', 'Elige un estado para aplicar.');
                }
                foreach ($products as $product) {
                    $product->update(['status' => $status]);
                    $ok++;
                }
                $messages[] = "Estado «{$status}» aplicado a {$ok} producto(s)";
                break;

            case 'sync_cj':
                foreach ($products as $product) {
                    if (! $product->isFromCj() || ! $product->cjPid()) {
                        $skipped++;

                        continue;
                    }
                    $out = $sync->syncToStore($store, $product->cjPid(), [
                        'title' => $product->name,
                        'sku' => $product->sku,
                        'image' => $product->image_url,
                    ]);
                    if ($out['success'] ?? false) {
                        $ok++;
                    } else {
                        $fail++;
                        $messages[] = '#'.$product->id.': '.($out['error'] ?? 'error sync');
                    }
                }
                $messages[] = "Sync CJ OK: {$ok}".($fail ? " · fallos: {$fail}" : '').($skipped ? " · omitidos (no CJ): {$skipped}" : '');
                break;

            case 'translate':
                $locale = trim((string) ($data['locale'] ?? ''));
                if ($locale === '') {
                    return back()->with('error', 'Elige un idioma para traducir.');
                }
                if (! (config('ai.providers.miia.api_key') || \App\Models\PlatformSetting::getValue('ai.miia.api_key'))) {
                    return back()->with('error', 'Configura la API Key de MIIA en General para traducir.');
                }
                $targetCurrency = strtoupper(trim((string) ($data['currency'] ?? '')));
                if ($targetCurrency === '') {
                    $targetCurrency = (string) ($currency->currencyForLocale($locale) ?? '');
                }
                $converted = 0;
                foreach ($products as $product) {
                    $out = $translator->translate($product, $locale, null);
                    if ($out['success'] ?? false) {
                        $ok++;
                        if ($targetCurrency !== '') {
                            $fxQuote = $currency->convertProductPrices($product, $targetCurrency);
                            $product->lockCurrencyPrice(
                                $targetCurrency,
                                (float) $fxQuote['price'],
                                $fxQuote['compare_at_price'] !== null ? (float) $fxQuote['compare_at_price'] : null
                            );
                            $product->save();
                            $converted++;
                        }
                    } else {
                        $fail++;
                        $messages[] = '#'.$product->id.': '.($out['error'] ?? 'error traducción');
                    }
                }
                $messages[] = "Traducciones {$locale} OK: {$ok}".($fail ? " · fallos: {$fail}" : '');
                if ($targetCurrency !== '') {
                    $messages[] = $converted > 0
                        ? "Precios convertidos a {$targetCurrency}: {$converted}"
                        : "Moneda {$targetCurrency} (sin cambios o ya estaba)";
                }
                break;
        }

        $flash = implode(' · ', array_filter($messages));
        if ($fail > 0) {
            return back()->with('error', $flash ?: 'Algunas acciones fallaron.');
        }

        return back()->with('success', $flash ?: 'Acción completada.');
    }

    public function syncCj(Product $product, StoreContext $storeContext, CjProductSyncService $sync)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $pid = $product->cjPid();
        if (! $pid) {
            return response()->json([
                'success' => false,
                'error' => 'Este producto no tiene cj_pid. No se puede sincronizar desde CJ.',
            ], 422);
        }

        $out = $sync->syncToStore($store, $pid, [
            'title' => $product->name,
            'sku' => $product->sku,
            'image' => $product->image_url,
        ]);

        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'Error al sincronizar CJ',
            ], 422);
        }

        $fresh = $out['product']->load('variants');

        return response()->json([
            'success' => true,
            'message' => 'Sincronizado desde CJ: '.$fresh->variants->count().' variante(s), '
                .count(data_get($fresh->verified_data, 'images', [])).' imagen(es).',
            'redirect' => route('admin.store.products.edit', $fresh),
            'variants' => $fresh->variants->count(),
        ]);
    }

    public function translate(
        Request $request,
        Product $product,
        StoreContext $storeContext,
        ProductTranslationService $translator,
        CurrencyService $currency
    ) {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'locale' => ['required', 'string', 'max:12'],
            'source_locale' => ['nullable', 'string', 'max:12'],
            'apply_to_main' => ['nullable', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(array_keys($currency->rates()))],
            'convert_currency' => ['nullable', 'boolean'],
        ]);

        $out = $translator->translate($product, $data['locale'], $data['source_locale'] ?? null);
        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'No se pudo traducir',
                'raw_preview' => $out['raw_preview'] ?? null,
            ], 422);
        }

        if ($request->boolean('apply_to_main')) {
            $t = $out['translation'];
            $product->name = $t['name'] ?: $product->name;
            $product->description = $t['description'] ?: $product->description;
            if (($t['badge'] ?? '') !== '') {
                $product->badge = $t['badge'];
            }
            $creative = is_array($product->creative_data) ? $product->creative_data : [];
            $creative['default_locale'] = $data['locale'];
            $product->creative_data = $creative;
            $product->save();
        }

        $fxOut = null;
        $targetCurrency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($targetCurrency === '' && $request->boolean('convert_currency', true)) {
            $targetCurrency = (string) ($currency->currencyForLocale($data['locale']) ?? '');
        }
        if ($targetCurrency !== '' && $request->boolean('convert_currency', true)) {
            $fxOut = $currency->convertProductPrices($product, $targetCurrency);
            $product->lockCurrencyPrice(
                $targetCurrency,
                (float) $fxOut['price'],
                $fxOut['compare_at_price'] !== null ? (float) $fxOut['compare_at_price'] : null
            );
            $product->save();
            $fxOut['source'] = 'manual';
        }

        $message = 'Traducción '.$data['locale'].' lista (MIIA).';
        if ($fxOut && ($fxOut['converted'] ?? false)) {
            $message .= ' Precio: '.$fxOut['from'].' → '.$fxOut['currency'].' ('.$fxOut['price'].').';
        }

        return response()->json([
            'success' => true,
            'locale' => $data['locale'],
            'translation' => $out['translation'],
            'pricing' => $fxOut,
            'message' => $message,
        ]);
    }

    public function compressName(
        Request $request,
        StoreContext $storeContext,
        ProductNameCompressionService $compressor
    ) {
        $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:500'],
        ]);

        $out = $compressor->compress($data['name']);
        if (! ($out['success'] ?? false)) {
            return response()->json($out, 422);
        }

        return response()->json([
            'success' => true,
            'name' => $out['name'],
            'message' => 'Nombre acortado con MIIA.',
        ]);
    }

    public function uploadImage(Request $request, Product $product, StoreContext $storeContext, ProductMediaMirrorService $mirror)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $stored = $mirror->storeUploadedFile($store, $product, $data['file'], 'images');

        return response()->json([
            'success' => true,
            'url' => $stored['url'],
        ]);
    }

    public function uploadVideo(Request $request, Product $product, StoreContext $storeContext, ProductMediaMirrorService $mirror)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimetypes:video/mp4,video/webm,video/quicktime,video/x-m4v'],
        ]);

        $file = $data['file'];
        $stored = $mirror->storeUploadedFile($store, $product, $file, 'videos');
        $label = trim(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return response()->json([
            'success' => true,
            'url' => $stored['url'],
            'name' => $label !== '' ? $label : 'Video',
        ]);
    }

    public function suggestPrices(
        Request $request,
        StoreContext $storeContext,
        ProductPriceSuggestionService $suggester
    ) {
        $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_currency' => ['nullable', 'string', 'size:3'],
            'cost_usd' => ['nullable', 'numeric', 'min:0'],
            'ship_usd' => ['nullable', 'numeric', 'min:0'],
            'fees_pct' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'target_margin' => ['nullable', 'numeric', 'min:0', 'max:0.95'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'base_currency' => ['nullable', 'string', 'size:3'],
            'compare_at' => ['nullable', 'numeric', 'min:0'],
            'currencies' => ['required', 'array', 'min:1', 'max:30'],
            'currencies.*.code' => ['required', 'string', 'size:3'],
            'currencies.*.rounding' => ['nullable', 'string', 'max:32'],
        ]);

        $out = $suggester->suggest($data);
        if (! ($out['success'] ?? false)) {
            return response()->json($out, 422);
        }

        return response()->json($out);
    }

    protected function validated(Request $request, $store, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'sku' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys(app(CurrencyService::class)->rates()))],
            'status' => ['required', Rule::in(['draft', 'live', 'paused', 'archived'])],
            'badge' => ['nullable', 'string', 'max:80'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'default_locale' => ['nullable', 'string', 'max:12'],
        ]);

        $currency = app(CurrencyService::class);
        $data['price'] = $currency->roundAmount((float) $data['price'], $data['currency']);
        if (array_key_exists('compare_at_price', $data) && $data['compare_at_price'] !== null && $data['compare_at_price'] !== '') {
            $data['compare_at_price'] = $currency->roundAmount((float) $data['compare_at_price'], $data['currency']);
        }
        if (array_key_exists('purchase_price', $data) && $data['purchase_price'] !== null && $data['purchase_price'] !== '') {
            $data['purchase_price'] = $currency->roundAmount((float) $data['purchase_price'], $data['currency']);
        } else {
            $data['purchase_price'] = null;
        }

        if (array_key_exists('description', $data)) {
            $data['description'] = app(\App\Services\Storefront\ProductDescriptionHtml::class)
                ->normalizeSpaces((string) ($data['description'] ?? ''));
            if ($data['description'] === '') {
                $data['description'] = null;
            }
        }

        return $data;
    }

    protected function mergeCreativeFromRequest(Request $request, Product $product): array
    {
        $creative = is_array($product->creative_data) ? $product->creative_data : [];
        $translations = is_array($creative['translations'] ?? null) ? $creative['translations'] : [];

        $incoming = $request->input('translations', []);
        if (is_array($incoming)) {
            foreach ($incoming as $locale => $row) {
                if (! is_string($locale) || ! is_array($row)) {
                    continue;
                }
                $locale = trim($locale);
                if ($locale === '') {
                    continue;
                }
                $prev = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
                $desc = trim((string) ($row['description'] ?? ($prev['description'] ?? '')));
                $translations[$locale] = array_merge($prev, [
                    'name' => mb_substr(trim((string) ($row['name'] ?? ($prev['name'] ?? ''))), 0, 190),
                    'description' => app(\App\Services\Storefront\ProductDescriptionHtml::class)->prose($desc) ?: $desc,
                    'badge' => mb_substr(trim((string) ($row['badge'] ?? ($prev['badge'] ?? ''))), 0, 80),
                ]);
            }
        }

        $creative['translations'] = $translations;
        $creative['default_locale'] = (string) ($request->input('default_locale')
            ?: ($creative['default_locale'] ?? $product->store?->defaultLocale() ?? 'es_MX'));
        if (! array_key_exists('has_video', $creative)) {
            $creative['has_video'] = ! empty(data_get($product->verified_data, 'videos'));
        }

        $fx = app(CurrencyService::class);
        $baseCurrency = strtoupper((string) $request->input('currency', $product->currency ?: $fx->base()));
        $incomingPrices = $request->input('prices', []);
        $prices = [];
        if (is_array($incomingPrices)) {
            foreach ($incomingPrices as $code => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = strtoupper(trim((string) $code));
                if (! preg_match('/^[A-Z]{3}$/', $code) || $code === $baseCurrency) {
                    continue;
                }
                if (! isset($fx->rates()[$code])) {
                    continue;
                }
                $locked = filter_var($row['locked'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || $request->boolean('prices.'.$code.'.locked');
                $price = (float) ($row['price'] ?? 0);
                $mode = (string) ($row['rounding'] ?? '');
                if (! isset(CurrencyService::ROUNDING_MODES[$mode])) {
                    $mode = $fx->roundingFor($code);
                }
                $compare = $row['compare_at_price'] ?? null;
                if ($locked && $price > 0) {
                    $prices[$code] = [
                        'price' => $fx->applyRounding($price, $mode),
                        'compare_at_price' => ($compare !== null && $compare !== '' && (float) $compare > 0)
                            ? $fx->applyRounding((float) $compare, $mode)
                            : null,
                        'locked' => true,
                        'rounding' => $mode,
                    ];
                    continue;
                }
                $prices[$code] = [
                    'locked' => false,
                    'rounding' => $mode,
                ];
            }
        }
        $creative['prices'] = $prices;

        return $creative;
    }

    protected function mergeVerifiedFromRequest(Request $request, Product $product): array
    {
        $verified = is_array($product->verified_data) ? $product->verified_data : [];

        if ($request->has('verified_rating_avg')) {
            $avg = $request->input('verified_rating_avg');
            $verified['rating_avg'] = ($avg !== null && $avg !== '') ? round((float) $avg, 2) : null;
            $verified['rating'] = $verified['rating_avg'];
        }

        if ($request->has('verified_review_count')) {
            $count = $request->input('verified_review_count');
            $verified['review_count'] = ($count !== null && $count !== '') ? max(0, (int) $count) : null;
        }

        if ($request->has('verified_reviews_present')) {
            $reviewsIn = $request->input('verified_reviews', []);
            $reviews = [];
            if (is_array($reviewsIn)) {
                foreach ($reviewsIn as $row) {
                    if (! is_array($row) || filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }
                    $author = trim((string) ($row['author'] ?? ''));
                    $comment = trim((string) ($row['comment'] ?? ''));
                    $score = (int) ($row['score'] ?? 0);
                    if ($author === '' && $comment === '' && ($score < 1 || $score > 5)) {
                        continue;
                    }
                    $images = [];
                    $imgsRaw = trim((string) ($row['images'] ?? ''));
                    if ($imgsRaw !== '') {
                        foreach (preg_split('/[\n,]+/', $imgsRaw) ?: [] as $url) {
                            $url = trim($url);
                            if ($url !== '') {
                                $images[] = $url;
                            }
                        }
                    }
                    $country = strtoupper(trim((string) ($row['country'] ?? '')));
                    if ($country === 'UK') {
                        $country = 'GB';
                    }
                    $reviews[] = array_filter([
                        'author' => $author !== '' ? mb_substr($author, 0, 80) : 'Comprador',
                        'score' => ($score >= 1 && $score <= 5) ? $score : null,
                        'comment' => $comment !== '' ? mb_substr($comment, 0, 4000) : null,
                        'country' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
                        'avatar' => trim((string) ($row['avatar'] ?? '')) ?: null,
                        'date' => trim((string) ($row['date'] ?? '')) ?: null,
                        'sku_info' => trim((string) ($row['sku_info'] ?? '')) ?: null,
                        'images' => $images,
                    ], fn ($v) => $v !== null && $v !== []);
                }
            }
            $verified['reviews'] = $reviews;
            $verified['comments'] = array_values(array_filter(
                $reviews,
                fn ($r) => trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images'])
            ));
            $verified['comment_count'] = count($verified['comments']);
            if (! $request->filled('verified_review_count')) {
                $verified['review_count'] = count($reviews);
            }
        }

        if ($request->has('verified_details_present')) {
            $detailsIn = $request->input('verified_details', []);
            $details = [];
            if (is_array($detailsIn)) {
                foreach ($detailsIn as $row) {
                    if (! is_array($row) || filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }
                    $name = trim((string) ($row['name'] ?? ''));
                    $value = trim((string) ($row['value'] ?? ''));
                    if ($name === '' || $value === '') {
                        continue;
                    }
                    $details[] = [
                        'name' => mb_substr($name, 0, 120),
                        'value' => mb_substr($value, 0, 500),
                    ];
                }
            }
            $verified['details'] = $details;
        }

        if ($request->has('verified_images_present')) {
            $imagesIn = $request->input('verified_images', []);
            $images = [];
            if (is_array($imagesIn)) {
                foreach ($imagesIn as $url) {
                    $url = trim((string) $url);
                    if ($url !== '') {
                        $images[] = mb_substr($url, 0, 500);
                    }
                }
            }
            $verified['images'] = array_values(array_unique($images));
        }

        if ($request->has('verified_videos_present')) {
            $videosIn = $request->input('verified_videos', []);
            $videos = [];
            if (is_array($videosIn)) {
                foreach ($videosIn as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $url = trim((string) ($row['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $name = trim((string) ($row['name'] ?? ''));
                    $cover = trim((string) ($row['cover'] ?? ''));
                    $videos[] = array_filter([
                        'url' => mb_substr($url, 0, 500),
                        'name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                        'cover' => $cover !== '' ? mb_substr($cover, 0, 500) : null,
                    ], fn ($v) => $v !== null);
                }
            }
            $verified['videos'] = $videos;
        }

        return $verified;
    }

    /**
     * @return list<array{locale: string, label: string, name: string, iso: string}>
     */
    protected function availableLocales($store): array
    {
        $preferred = [
            'es_MX' => 'Español (México)',
            'es_ES' => 'Español (España)',
            'en_US' => 'English (US)',
            'en_GB' => 'English (UK)',
            'en_CA' => 'English (Canada)',
            'en_AU' => 'English (Australia)',
            'pt_PT' => 'Português (PT)',
            'pt_BR' => 'Português (BR)',
            'fr_FR' => 'Français',
            'de_DE' => 'Deutsch',
            'it_IT' => 'Italiano',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'sv_SE' => 'Svenska',
            'da_DK' => 'Dansk',
            'nb_NO' => 'Norsk',
            'fi_FI' => 'Suomi',
            'hu_HU' => 'Magyar',
            'cs_CZ' => 'Čeština',
            'ro_RO' => 'Română',
            'el_GR' => 'Ελληνικά',
        ];

        $storeLocale = method_exists($store, 'defaultLocale')
            ? (string) $store->defaultLocale()
            : (string) ($store->market?->locale ?? '');

        if ($storeLocale !== '' && ! isset($preferred[$storeLocale])) {
            $preferred = [$storeLocale => $storeLocale] + $preferred;
        }

        $out = [];
        foreach ($preferred as $locale => $name) {
            $iso = strtolower((string) substr($locale, -2));
            if ($iso === 'uk') {
                $iso = 'gb';
            }
            $label = $name.($locale === $storeLocale ? ' · tienda' : '');
            $out[] = [
                'locale' => $locale,
                'name' => $name,
                'label' => $label,
                'iso' => $iso,
            ];
        }

        return $out;
    }

    protected function uniqueSlug(int $storeId, string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug) ?: 'producto';
        $candidate = $base;
        $i = 2;
        while (
            Product::query()
                ->where('store_id', $storeId)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
