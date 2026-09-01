<?php

namespace App\Http\Controllers\Admin;

use App\Domain\AI\AiTaskRouter;
use App\Domain\Discovery\ProductDiscoveryService;
use App\Domain\Scoring\CjPricingEstimator;
use App\Domain\Scoring\ProductScoreService;
use App\Domain\Suppliers\AliExpress\AliExpressProductFetcher;
use App\Domain\Suppliers\AliExpress\AliExpressProductSyncService;
use App\Domain\Suppliers\Cj\CjChatGptMcpSearchService;
use App\Domain\Suppliers\Cj\CjConnector;
use App\Domain\Suppliers\Cj\CjProductMatcher;
use App\Domain\Suppliers\Cj\CjProductSyncService;
use App\Domain\Suppliers\Contracts\SupplierInterface;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LabController extends Controller
{
    public function discoveryForm()
    {
        return view('admin.discovery', [
            'markets' => $this->discoveryMarkets(),
            'regionLabels' => $this->marketRegionLabels(),
        ]);
    }

    public function discoveryRun(Request $request, ProductDiscoveryService $discovery)
    {
        $data = $request->validate([
            'problem' => 'required|string|max:2000',
            'market' => 'required|string|max:8',
        ]);

        $result = $discovery->proposeImportList($data['problem'], $data['market']);

        return view('admin.discovery', [
            'markets' => $this->discoveryMarkets(),
            'regionLabels' => $this->marketRegionLabels(),
            'result' => $result,
            'input' => $data,
        ]);
    }

    protected function discoveryMarkets()
    {
        return DB::table('markets')
            ->where('is_active', true)
            ->orderByRaw("FIELD(region, 'north_america', 'oceania', 'europe', 'other')")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    protected function marketRegionLabels(): array
    {
        return [
            'north_america' => 'América del Norte',
            'oceania' => 'Oceanía',
            'europe' => 'Europa',
            'other' => 'Otros',
        ];
    }

    public function cjSearch(
        Request $request,
        SupplierInterface $supplier,
        StoreContext $storeContext,
        CjChatGptMcpSearchService $mcpSearch,
        CjPricingEstimator $pricing
    ) {
        $store = $storeContext->current();
        $market = $store?->market;
        $countryCode = strtoupper((string) ($market?->code ?? 'US'));
        if ($countryCode === 'UK') {
            $countryCode = 'GB';
        }

        $mode = $request->input('mode', $request->filled('prompt') ? 'prompt' : ($request->filled('q') ? 'keyword' : 'prompt'));
        $prompt = trim((string) $request->input('prompt', ''));
        $q = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(50, max(10, (int) $request->input('per_page', 20)));

        $defaultCurrency = strtoupper((string) ($market?->currency ?: 'MXN'));
        $rates = $pricing->rates();
        if (! isset($rates[$defaultCurrency])) {
            $defaultCurrency = 'USD';
        }

        $viewData = [
            'mode' => $mode,
            'prompt' => $prompt,
            'q' => $q,
            'page' => $page,
            'perPage' => $perPage,
            'store' => $store,
            'market' => $market,
            'countryCode' => $countryCode,
            'products' => collect(),
            'total' => 0,
            'result' => null,
            'error' => null,
            'answer' => null,
            'keyword' => null,
            'via' => null,
            'provider' => null,
            'tool_trace' => [],
            'keywords' => [],
            'has_openai' => (bool) config('ai.providers.miia.api_key'),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'has_cj_token' => (bool) (config('cj.access_token') || config('cj.api_key')),
            'fxRates' => $rates,
            'displayCurrency' => $defaultCurrency,
            'currencies' => array_keys($rates),
            'catalogPids' => $this->catalogCjPids($store),
            'importUrl' => route('admin.lab.cj.import'),
            'improvePromptUrl' => route('admin.lab.cj.improve-prompt'),
            'crawlUrl' => route('admin.lab.cj.crawl'),
            'huntUrl' => route('admin.lab.cj.hunt'),
            'huntHtmlUrl' => route('admin.lab.cj.hunt-html'),
            'pluginDownloadUrl' => route('admin.lab.cj.extension'),
            'pluginToken' => $this->ensurePluginToken(),
            'pluginOrigin' => rtrim(request()->getSchemeAndHttpHost(), '/'),
            'importAliExpressUrl' => route('admin.lab.cj.import-aliexpress'),
            'videosUrl' => url('/admin/lab/cj/videos'),
            'imagesUrl' => url('/admin/lab/cj/images'),
            'videoProxyUrl' => route('admin.lab.cj.video-proxy'),
        ];

        if ($mode === 'prompt') {
            if ($prompt === '') {
                return view('admin.cj-search', $viewData);
            }

            $request->validate([
                'prompt' => 'required|string|max:2000',
                'per_page' => 'nullable|integer|min:10|max:50',
            ]);

            if (! config('cj.access_token') && config('cj.api_key') && $supplier instanceof CjConnector) {
                $supplier->authorizeWithApiKey(config('cj.api_key'));
            }

            $out = $mcpSearch->searchByPrompt($prompt, $countryCode, $perPage);

            $viewData['products'] = $this->withPricing($out['products'] ?? collect(), $pricing);
            $viewData['total'] = (int) ($out['total'] ?? 0);
            $viewData['answer'] = $out['answer'] ?? null;
            $viewData['keyword'] = $out['keyword'] ?? null;
            $viewData['keywords'] = $out['keywords'] ?? [];
            $viewData['via'] = $out['via'] ?? null;
            $viewData['provider'] = $out['provider'] ?? null;
            $viewData['tool_trace'] = $out['tool_trace'] ?? [];
            $viewData['q'] = $out['keyword'] ?? $q;
            $viewData['error'] = ($out['success'] ?? false) ? null : ($out['error'] ?? 'Error MCP/ChatGPT');
            $viewData['result'] = $out;

            return view('admin.cj-search', $viewData);
        }

        if ($q === '') {
            $viewData['mode'] = 'prompt';

            return view('admin.cj-search', $viewData);
        }

        $request->validate([
            'q' => 'required|string|max:200',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:50',
        ]);

        $result = $supplier->searchProducts([
            'keyword' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'country_code' => $countryCode,
        ]);

        $viewData['result'] = $result;
        $viewData['via'] = 'rest';

        if (! ($result['success'] ?? false)) {
            $viewData['error'] = $result['error'] ?? 'Error al consultar CJ.';

            return view('admin.cj-search', $viewData);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $list = is_array($data['list'] ?? null) ? $data['list'] : [];
        $products = collect($list)->map(fn ($row) => CjConnector::normalizeListItem(is_array($row) ? $row : []));

        $viewData['products'] = $this->withPricing($products, $pricing);
        $viewData['total'] = (int) ($data['total'] ?? $products->count());
        $viewData['page'] = (int) ($data['pageNum'] ?? $page);
        $viewData['perPage'] = (int) ($data['pageSize'] ?? $perPage);

        return view('admin.cj-search', $viewData);
    }

    protected function withPricing(Collection $products, CjPricingEstimator $pricing): Collection
    {
        return $products->map(function ($row) use ($pricing) {
            $item = is_array($row) ? $row : (array) $row;
            $item['pricing'] = $pricing->estimate($item);

            return $item;
        })->values();
    }

    /**
     * @return list<string>
     */
    protected function catalogCjPids($store): array
    {
        if (! $store) {
            return [];
        }

        return Product::query()
            ->where('store_id', $store->id)
            ->whereNotNull('verified_data')
            ->get(['verified_data'])
            ->map(fn (Product $p) => (string) data_get($p->verified_data, 'cj_pid', ''))
            ->filter(fn ($pid) => $pid !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function importCjProduct(Request $request, StoreContext $storeContext, CjProductSyncService $sync)
    {
        $store = $storeContext->current();
        if (! $store) {
            return response()->json([
                'success' => false,
                'error' => 'Selecciona una tienda activa en el switcher.',
            ], 422);
        }

        $data = $request->validate([
            'pid' => ['required', 'string', 'max:64'],
            'sku' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:1000'],
            'price_usd' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable'],
            'category' => ['nullable', 'string', 'max:190'],
            'cj_url' => ['nullable', 'string', 'max:1000'],
            'has_video' => ['nullable', 'boolean'],
            'sell_usd' => ['nullable', 'numeric', 'min:0'],
            'ship_usd' => ['nullable', 'numeric', 'min:0'],
            'cost_usd' => ['nullable', 'numeric', 'min:0'],
            'exclude_vids' => ['nullable', 'array'],
            'exclude_vids.*' => ['nullable', 'string', 'max:80'],
        ]);

        $data['weight'] = CjConnector::normalizeWeight($data['weight'] ?? null);
        $data['title'] = mb_substr(trim($data['title']), 0, 190);
        if (! empty($data['image']) && mb_strlen($data['image']) > 500) {
            $data['image'] = mb_substr($data['image'], 0, 500);
        }

        $out = $sync->syncToStore($store, $data['pid'], [
            'sku' => $data['sku'] ?? null,
            'title' => $data['title'],
            'image' => $data['image'] ?? null,
            'weight' => $data['weight'] ?? null,
            'cost_usd' => $data['cost_usd'] ?? $data['price_usd'] ?? null,
            'sell_usd' => $data['sell_usd'] ?? null,
            'ship_usd' => $data['ship_usd'] ?? null,
            'exclude_vids' => $data['exclude_vids'] ?? [],
        ]);

        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'No se pudo sincronizar desde CJ',
            ], 422);
        }

        /** @var Product $product */
        $product = $out['product'];
        $created = (bool) ($out['created'] ?? false);
        $variantCount = $product->variants()->count();

        return response()->json([
            'success' => true,
            'already' => ! $created,
            'product_id' => $product->id,
            'edit_url' => route('admin.store.products.edit', $product),
            'variants' => $variantCount,
            'message' => $created
                ? 'Agregado al catálogo de «'.$store->name.'» con '.$variantCount.' variante(s) (borrador).'
                : 'Producto CJ actualizado en «'.$store->name.'» ('.$variantCount.' variante(s)).',
        ]);
    }

    public function huntFromUrl(
        Request $request,
        StoreContext $storeContext,
        AliExpressProductFetcher $fetcher,
        CjProductMatcher $matcher
    ) {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
        ]);
        @set_time_limit(180);

        $input = trim($data['url']);
        if (! AliExpressProductFetcher::looksLikeAliExpress($input)) {
            return response()->json([
                'success' => false,
                'error' => 'Esa entrada no parece AliExpress. Usa una URL de item o el crawler de CJ.',
            ], 422);
        }

        $fetched = $fetcher->fetch($input);
        if (! ($fetched['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $fetched['error'] ?? 'No se pudo extraer AliExpress',
            ], 422);
        }

        return $this->huntAliExpressPayload($fetched['product'], $storeContext, $matcher);
    }

    public function huntFromHtml(
        Request $request,
        StoreContext $storeContext,
        AliExpressProductFetcher $fetcher,
        CjProductMatcher $matcher
    ) {
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:2000'],
            'html' => ['nullable', 'string', 'max:2500000'],
            'snapshot' => ['nullable', 'array'],
        ]);
        @set_time_limit(120);

        $html = (string) ($data['html'] ?? '');
        $snapshot = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
        $url = trim((string) ($data['url'] ?? ''));

        $trimmed = ltrim($html);
        if ($trimmed !== '' && str_starts_with($trimmed, '{')) {
            $decoded = json_decode($html, true);
            if (is_array($decoded) && (isset($decoded['html']) || isset($decoded['snapshot']))) {
                $html = (string) ($decoded['html'] ?? '');
                if (isset($decoded['snapshot']) && is_array($decoded['snapshot'])) {
                    $snapshot = $decoded['snapshot'];
                }
                if ($url === '' && ! empty($decoded['url'])) {
                    $url = (string) $decoded['url'];
                }
            }
        }

        $fetched = $fetcher->parseFromCapture($html, $url, $snapshot);
        if (! ($fetched['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $fetched['error'] ?? 'No se pudo parsear el HTML',
            ], 422);
        }

        return $this->huntAliExpressPayload($fetched['product'], $storeContext, $matcher);
    }

    public function pluginBootstrap(Request $request)
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->pluginCorsHeaders());
        }

        if (! $this->pluginTokenValid($request)) {
            return response()->json(['success' => false, 'error' => 'Token del plugin inválido. Cópialo de Product Hunter.'], 401)
                ->withHeaders($this->pluginCorsHeaders());
        }

        $stores = \App\Models\Store::query()
            ->with('market:id,code,name')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'market_id', 'status'])
            ->map(fn (\App\Models\Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'status' => $s->status,
                'market' => $s->market?->code,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'stores' => $stores,
        ])->withHeaders($this->pluginCorsHeaders());
    }

    public function pluginCapture(
        Request $request,
        AliExpressProductFetcher $fetcher,
        AliExpressProductSyncService $sync,
        CjProductMatcher $matcher
    ) {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->pluginCorsHeaders());
        }

        if (! $this->pluginTokenValid($request)) {
            return response()->json(['success' => false, 'error' => 'Token del plugin inválido. Cópialo de Product Hunter.'], 401)
                ->withHeaders($this->pluginCorsHeaders());
        }

        $html = (string) $request->input('html', '');
        $url = (string) $request->input('url', '');
        $snapshot = $request->input('snapshot');
        if (! is_array($snapshot)) {
            $snapshot = [];
        }
        if (strlen($html) > 2500000) {
            $html = substr($html, 0, 2500000);
        }

        $fetched = $fetcher->parseFromCapture($html, $url, $snapshot);
        if (! ($fetched['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $fetched['error'] ?? 'No se pudo parsear',
            ], 422)->withHeaders($this->pluginCorsHeaders());
        }

        $ae = $fetched['product'];
        $storeId = (int) $request->input('store_id', 0);
        $store = $storeId > 0
            ? \App\Models\Store::query()->find($storeId)
            : \App\Models\Store::query()->orderBy('id')->first();

        if (! $store) {
            return response()->json([
                'success' => false,
                'error' => 'No hay tienda disponible. Crea una en Multidrop o elige tienda en el popup.',
            ], 422)->withHeaders($this->pluginCorsHeaders());
        }

        $out = $sync->syncToStore($store, $ae);
        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'No se pudo guardar el borrador',
            ], 422)->withHeaders($this->pluginCorsHeaders());
        }

        /** @var Product $product */
        $product = $out['product'];
        $created = (bool) ($out['created'] ?? false);

        // Cache opcional por si el panel quiere abrir el hunter después
        $id = (string) Str::uuid();
        $matches = [];
        $matchError = null;
        try {
            $country = strtoupper((string) ($store->market?->code ?? 'MX'));
            if ($country === 'UK') {
                $country = 'GB';
            }
            $matches = $matcher->match($ae, $country);
        } catch (\Throwable $e) {
            $matchError = $e->getMessage();
        }
        \Illuminate\Support\Facades\Cache::put('ae_plugin_capture_'.$id, [
            'success' => true,
            'source' => 'aliexpress',
            'aliexpress' => $ae,
            'matches' => $matches,
            'match_error' => $matchError,
            'product_id' => $product->id,
            'store_id' => $store->id,
        ], now()->addMinutes(20));

        $msg = $created
            ? 'Producto enviado a borrador en «'.$store->name.'».'
            : 'Borrador actualizado en «'.$store->name.'».';

        $mirrorReport = app(\App\Services\Storage\ProductMediaMirrorService::class)->lastMirrorReport();
        $msg .= $this->mediaMirrorMessageSuffix($mirrorReport);

        return response()->json([
            'success' => true,
            'drafted' => true,
            'created' => $created,
            'capture_id' => $id,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'store_name' => $store->name,
            'edit_url' => route('admin.store.products.edit', $product),
            'title' => $ae['title'] ?? $product->name,
            'message' => $msg,
            'media_mirror' => $mirrorReport,
        ])->withHeaders($this->pluginCorsHeaders());
    }

    public function captureResult(string $id)
    {
        $payload = \Illuminate\Support\Facades\Cache::get('ae_plugin_capture_'.$id);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'error' => 'Captura expirada. Vuelve a enviar desde el plugin.'], 404);
        }

        return response()->json($payload);
    }

    public function regeneratePluginToken()
    {
        $token = Str::lower(Str::random(40));
        \App\Models\PlatformSetting::put('aliexpress.plugin_token', $token, 'aliexpress', true);

        return back()->with('success', 'Token del plugin regenerado. Actualízalo en la extensión.');
    }

    public function downloadChromeExtension(Request $request)
    {
        $dir = resource_path('extensions/aliexpress-hunter');
        if (! is_dir($dir)) {
            abort(404, 'Extensión no encontrada');
        }

        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        $token = $this->ensurePluginToken();
        $tmp = storage_path('app/aliexpress-hunter.zip');
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        if (! class_exists(\ZipArchive::class)) {
            abort(500, 'ZIP no disponible en PHP (extensión zip)');
        }
        $zip = new \ZipArchive;
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'No pude crear el ZIP');
        }

        $configJs = 'window.MULTIDROP_DEFAULTS = '.json_encode([
            'origin' => $origin,
            'capture_path' => '/admin/lab/cj/plugin-capture',
            'bootstrap_path' => '/admin/lab/cj/plugin-bootstrap',
            'hunter_path' => '/admin/lab/cj',
        ], JSON_UNESCAPED_SLASHES).";\n";
        $zip->addFromString('config.js', $configJs);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true) ?: [];
        $hosts = [
            'https://*.aliexpress.com/*',
            'https://*.aliexpress.us/*',
            'https://*.aliexpress.ru/*',
            $origin.'/*',
        ];
        $manifest['host_permissions'] = array_values(array_unique($hosts));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach (['background.js', 'content.js', 'content.css', 'popup.html', 'popup.js', 'README.txt'] as $file) {
            $path = $dir.'/'.$file;
            if (is_file($path)) {
                $zip->addFile($path, $file);
            }
        }

        foreach ([16, 48, 128] as $size) {
            $zip->addFromString('icons/icon'.$size.'.png', $this->pluginIconPng($size));
        }

        $zip->close();

        return response()->download($tmp, 'multidrop-aliexpress-hunter.zip')->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, mixed>  $ae
     */
    protected function huntAliExpressPayload(array $ae, StoreContext $storeContext, CjProductMatcher $matcher)
    {
        $store = $storeContext->current();
        $country = strtoupper((string) ($store?->market?->code ?? 'MX'));
        if ($country === 'UK') {
            $country = 'GB';
        }

        $matches = [];
        $matchError = null;
        try {
            $matches = $matcher->match($ae, $country);
        } catch (\Throwable $e) {
            $matchError = $e->getMessage();
        }

        $catalogPids = $this->catalogCjPids($store);
        $inAeCatalog = false;
        if ($store && ! empty($ae['product_id'])) {
            $inAeCatalog = Product::query()
                ->where('store_id', $store->id)
                ->where('verified_data->aliexpress_product_id', $ae['product_id'])
                ->exists();
        }
        $ae['in_catalog'] = $inAeCatalog;

        foreach ($matches as &$m) {
            $m['in_catalog'] = in_array((string) ($m['pid'] ?? ''), $catalogPids, true);
        }
        unset($m);

        return response()->json([
            'success' => true,
            'source' => 'aliexpress',
            'aliexpress' => $ae,
            'matches' => $matches,
            'match_error' => $matchError,
            'store_id' => $store?->id,
            'has_store' => (bool) $store,
        ]);
    }

    /**
     * @param  array{mirrored?: int, skipped?: int, failed?: int, r2?: bool}  $report
     */
    protected function mediaMirrorMessageSuffix(array $report): string
    {
        if (! ($report['r2'] ?? false)) {
            return '';
        }

        $mirrored = (int) ($report['mirrored'] ?? 0);
        $failed = (int) ($report['failed'] ?? 0);

        if ($mirrored > 0) {
            return ' · '.$mirrored.' archivo(s) copiados a R2';
        }

        if ($failed > 0) {
            return ' · no se pudieron copiar '.$failed.' URL(s) a R2';
        }

        return ' · media ya estaba en R2';
    }

    /**
     * @return array<string, string>
     */
    protected function pluginCorsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Multidrop-Token, Accept',
            'Access-Control-Max-Age' => '86400',
        ];
    }

    protected function pluginTokenValid(Request $request): bool
    {
        $expected = (string) \App\Models\PlatformSetting::getValue('aliexpress.plugin_token', '');
        $token = (string) $request->input('token', $request->header('X-Multidrop-Token', ''));

        return $expected !== '' && hash_equals($expected, $token);
    }

    protected function ensurePluginToken(): string
    {
        $token = (string) \App\Models\PlatformSetting::getValue('aliexpress.plugin_token', '');
        if ($token === '') {
            $token = Str::lower(Str::random(40));
            \App\Models\PlatformSetting::put('aliexpress.plugin_token', $token, 'aliexpress', true);
        }

        return $token;
    }

    protected function pluginIconPng(int $size): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') ?: '';
        }
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        $teal = imagecolorallocate($im, 15, 118, 110);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledellipse($im, (int) ($size / 2), (int) ($size / 2), $size - 2, $size - 2, $teal);
        imagestring($im, 5, (int) ($size / 2 - 4), (int) ($size / 2 - 8), 'M', $white);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return $png;
    }

    public function importAliExpressProduct(
        Request $request,
        StoreContext $storeContext,
        AliExpressProductFetcher $fetcher,
        AliExpressProductSyncService $sync
    ) {
        $store = $storeContext->current();
        if (! $store) {
            return response()->json([
                'success' => false,
                'error' => 'Selecciona una tienda activa en el switcher.',
            ], 422);
        }

        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:2000'],
            'product_id' => ['nullable', 'string', 'max:32'],
            'title' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:1000'],
            'product' => ['nullable', 'array'],
        ]);

        $detail = is_array($data['product'] ?? null) ? $data['product'] : null;
        if (! is_array($detail) || empty($detail['product_id'])) {
            $ref = (string) ($data['url'] ?: $data['product_id'] ?: '');
            if ($ref === '') {
                return response()->json(['success' => false, 'error' => 'Falta URL o product_id de AliExpress'], 422);
            }
            $fetched = $fetcher->fetch($ref);
            if (! ($fetched['success'] ?? false)) {
                return response()->json(['success' => false, 'error' => $fetched['error'] ?? 'No se pudo extraer AliExpress'], 422);
            }
            $detail = $fetched['product'];
        }

        if (! empty($data['title'])) {
            $detail['title'] = $data['title'];
        }
        if (! empty($data['image'])) {
            $detail['image'] = $data['image'];
        }

        $out = $sync->syncToStore($store, $detail);
        if (! ($out['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $out['error'] ?? 'No se pudo importar AliExpress'], 422);
        }

        /** @var Product $product */
        $product = $out['product'];
        $created = (bool) ($out['created'] ?? false);
        $variantCount = $product->variants()->count();

        return response()->json([
            'success' => true,
            'already' => ! $created,
            'product_id' => $product->id,
            'edit_url' => route('admin.store.products.edit', $product),
            'variants' => $variantCount,
            'source' => 'aliexpress',
            'message' => $created
                ? 'AliExpress agregado al catálogo de «'.$store->name.'» como borrador (cumplimiento manual).'
                : 'Producto AliExpress actualizado en «'.$store->name.'».',
        ]);
    }

    protected function uniqueProductSlug(int $storeId, string $slug): string
    {
        $base = Str::slug($slug) ?: 'producto';
        $candidate = $base;
        $i = 2;
        while (
            Product::query()
                ->where('store_id', $storeId)
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    public function improvePrompt(Request $request, StoreContext $storeContext, AiTaskRouter $ai)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
        ]);

        if (! $ai->hasMiia()) {
            return response()->json([
                'success' => false,
                'error' => 'Configura la API Key de MIIA (ia.ceballosleon.com) en General.',
            ], 422);
        }

        $store = $storeContext->current();
        $market = $store?->market;
        $country = strtoupper((string) ($market?->code ?? 'MX'));
        if ($country === 'UK') {
            $country = 'GB';
        }
        $currency = strtoupper((string) ($market?->currency ?? 'MXN'));
        $storeName = $store?->name ?? 'mini-tienda';

        $system = <<<TXT
Eres un experto en dropshipping y sourcing CJ Dropshipping.
Tu tarea: mejorar el brief del usuario para buscar productos en CJ vía ChatGPT+MCP.

Contexto de la tienda activa:
- Nombre: {$storeName}
- País / mercado: {$country}
- Moneda: {$currency}

Reglas de salida:
1) Devuelve SOLO el prompt mejorado, listo para pegar (sin comillas, sin markdown, sin preámbulo).
2) Mantén el idioma del usuario (normalmente español).
3) Hazlo más claro, accionable y orientado a keywords CJ en inglés.
4) Incluye: categorías/ángulos, restricciones de precio/peso/envío, qué evitar, cantidad deseada de ideas, y criterios de margen/demo-video si aplica.
5) No inventes marcas ni PIDs. No busques productos tú: solo mejora el brief.
6) Máximo ~1200 caracteres si el original era corto; si el original es largo, estructúralo sin perder ideas.
TXT;

        $result = $ai->chat('lab_prompt', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => trim($data['prompt'])],
        ]);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'MIIA no pudo mejorar el prompt',
                'provider' => $result['provider'] ?? 'miia',
            ], 422);
        }

        $improved = trim((string) ($result['content'] ?? ''));
        $improved = preg_replace('/^```[a-zA-Z]*\s*/', '', $improved) ?? $improved;
        $improved = preg_replace('/\s*```$/', '', $improved) ?? $improved;
        $improved = trim($improved);

        if ($improved === '') {
            return response()->json([
                'success' => false,
                'error' => 'MIIA devolvió una respuesta vacía.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'prompt' => $improved,
            'provider' => 'miia',
        ]);
    }

    public function crawlProduct(Request $request, StoreContext $storeContext, CjConnector $connector, CjPricingEstimator $pricing)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2000'],
        ]);

        @set_time_limit(120);

        if (! config('cj.access_token') && config('cj.api_key')) {
            $connector->authorizeWithApiKey(config('cj.api_key'));
        }

        $store = $storeContext->current();
        $country = strtoupper((string) ($store?->market?->code ?? 'MX'));
        if ($country === 'UK') {
            $country = 'GB';
        }

        $out = $connector->crawlProductFromInput($data['url'], $country);
        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'No se pudo obtener el producto',
                'ref' => $out['ref'] ?? null,
            ], 422);
        }

        $product = $out['product'];
        $product['pricing'] = $pricing->estimate([
            'price' => $product['price'] ?? null,
            'weight' => $product['weight'] ?? $product['packed_weight'] ?? null,
            'free_shipping' => (bool) ($product['free_shipping'] ?? false),
        ]);

        $inCatalog = false;
        if ($store && ! empty($product['pid'])) {
            $inCatalog = Product::query()
                ->where('store_id', $store->id)
                ->where('verified_data->cj_pid', $product['pid'])
                ->exists();
        }
        $product['in_catalog'] = $inCatalog;

        return response()->json([
            'success' => true,
            'product' => $product,
            'ref' => $out['ref'] ?? null,
            'store_id' => $store?->id,
            'has_store' => (bool) $store,
        ]);
    }

    public function productVideos(string $pid, CjConnector $connector)
    {
        $pid = trim($pid);
        if ($pid === '') {
            return response()->json(['success' => false, 'error' => 'PID requerido'], 422);
        }

        $out = $connector->queryVideosByProductId($pid);
        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'Sin videos',
            ], 422);
        }

        $videos = collect($out['videos'] ?? [])->map(function (array $v) {
            $proxy = route('admin.lab.cj.video-proxy', ['u' => $v['url']]);

            return [
                'id' => $v['id'],
                'name' => $v['name'],
                'duration' => $v['duration'],
                'cover' => $v['cover'],
                'play_url' => $proxy,
                'source_url' => $v['url'],
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'pid' => $pid,
            'videos' => $videos,
        ]);
    }

    public function productImages(string $pid, CjConnector $connector)
    {
        $pid = trim($pid);
        if ($pid === '') {
            return response()->json(['success' => false, 'error' => 'PID requerido'], 422);
        }

        $out = $connector->queryProductImages($pid);
        if (! ($out['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $out['error'] ?? 'Sin imágenes',
            ], 422);
        }

        $images = array_values($out['images'] ?? []);

        return response()->json([
            'success' => true,
            'pid' => $pid,
            'count' => count($images),
            'images' => $images,
        ]);
    }

    public function videoProxy(Request $request, \App\Domain\Suppliers\Cj\CjVideoProxy $proxy)
    {
        return $proxy->stream(trim((string) $request->query('u', '')));
    }

    public function scoreDemo(Request $request, ProductScoreService $scorer)
    {
        $sell = (float) $request->input('sell', 599);
        $cost = (float) $request->input('cost', 180);
        $ship = (float) $request->input('ship', 90);

        $margin = $scorer->marginScore($sell, $cost, $ship);

        $score = $scorer->score([
            'demand_growth' => (float) $request->input('demand_growth', 70),
            'trend_velocity' => (float) $request->input('trend_velocity', 65),
            'social_proof' => (float) $request->input('social_proof', 55),
            'competition_inverse' => (float) $request->input('competition_inverse', 60),
            'margin' => $margin,
            'shipping_feasibility' => (float) $request->input('shipping_feasibility', 50),
            'stock_local' => (float) $request->input('stock_local', 40),
            'demo_video_fit' => (float) $request->input('demo_video_fit', 80),
            'problem_fit' => (float) $request->input('problem_fit', 85),
            'seasonality_fit' => (float) $request->input('seasonality_fit', 70),
            'return_risk_inverse' => (float) $request->input('return_risk_inverse', 70),
            'regulatory_risk_inverse' => (float) $request->input('regulatory_risk_inverse', 80),
        ]);

        return view('admin.score', [
            'score' => $score,
            'margin' => $margin,
            'inputs' => compact('sell', 'cost', 'ship'),
        ]);
    }
}
