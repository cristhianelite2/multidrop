<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Domain\AI\AiTaskRouter;
use App\Domain\AI\OpenAiComboImageService;
use App\Models\Combo;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Services\Admin\StoreContext;
use App\Services\Combo\ComboLandingAiService;
use App\Services\Combo\ComboPromoStyleLibrary;
use App\Services\Commerce\ComboService;
use App\Services\Storefront\DesignAssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComboController extends Controller
{
    use ResolvesCurrentStore;

    public function index(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $combos = Combo::query()
            ->with(['items.product', 'product'])
            ->where('store_id', $store->id)
            ->orderByDesc('id')
            ->get();

        return view('admin.store.combos.index', compact('store', 'combos'));
    }

    public function create(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        return view('admin.store.combos.form', [
            'store' => $store,
            'combo' => new Combo([
                'strategy' => 'qty',
                'qty_min' => 2,
                'discount_type' => 'percent',
                'discount_value' => 10,
                'is_active' => true,
                'publish_as_product' => true,
            ]),
            'products' => $this->eligibleProducts($store),
            'selected_ids' => [],
            'has_miia' => $this->hasMiia(),
        ]);
    }

    public function store(Request $request, StoreContext $storeContext, ComboService $combos)
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $this->validated($request, $store);
        [$strategy, $ids] = $this->strategyAndIds($request, $store);

        $combo = Combo::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'slug' => Combo::uniqueSlug($store->id, $data['slug'] ?: $data['name']),
            'description' => $data['description'] ?? null,
            'images' => $this->parseImages($data['images'] ?? ''),
            'strategy' => $strategy,
            'qty_min' => (int) $data['qty_min'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'is_active' => $request->boolean('is_active'),
            'publish_as_product' => $request->boolean('publish_as_product'),
        ]);
        $this->syncItems($combo, $ids);
        $product = $combos->syncStorefrontProduct($combo->fresh(['items.product', 'store']));
        if ($request->boolean('modify_landing') && $product) {
            $store->setStarProductId((int) $product->id);
        }

        $note = $strategy !== (string) $data['strategy']
            ? ' Se usó «Por combinación» porque el combo tiene varios productos.'
            : '';

        return redirect()->route('admin.store.combos.edit', $combo)->with('success', 'Combo creado.'.$note);
    }

    public function edit(Combo $combo, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $combo->store_id === (int) $store->id, 404);
        $combo->load('items');

        return view('admin.store.combos.form', [
            'store' => $store,
            'combo' => $combo,
            'products' => $this->eligibleProducts($store, (int) $combo->product_id),
            'selected_ids' => $combo->items->pluck('product_id')->map(fn ($id) => (int) $id)->all(),
            'has_miia' => $this->hasMiia(),
        ]);
    }

    public function update(Request $request, Combo $combo, StoreContext $storeContext, ComboService $combos)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $combo->store_id === (int) $store->id, 404);
        $data = $this->validated($request, $store, $combo);
        [$strategy, $ids] = $this->strategyAndIds($request, $store);

        $combo->update([
            'name' => $data['name'],
            'slug' => Combo::uniqueSlug($store->id, $data['slug'] ?: $data['name'], (int) $combo->id),
            'description' => $data['description'] ?? null,
            'images' => $this->parseImages($data['images'] ?? ''),
            'strategy' => $strategy,
            'qty_min' => (int) $data['qty_min'],
            'discount_type' => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'is_active' => $request->boolean('is_active'),
            'publish_as_product' => $request->boolean('publish_as_product'),
        ]);
        $this->syncItems($combo, $ids);
        $product = $combos->syncStorefrontProduct($combo->fresh(['items.product', 'store']));
        if ($request->boolean('modify_landing') && $product) {
            $store->setStarProductId((int) $product->id);
        }

        $note = $strategy !== (string) $data['strategy']
            ? ' Se usó «Por combinación» porque el combo tiene varios productos.'
            : '';

        return redirect()->route('admin.store.combos.edit', $combo)->with('success', 'Combo actualizado.'.$note);
    }

    public function destroy(Combo $combo, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);
        abort_unless((int) $combo->store_id === (int) $store->id, 404);
        if ($combo->product_id) {
            Product::query()
                ->where('store_id', $store->id)
                ->where('id', $combo->product_id)
                ->delete();
        }
        $combo->delete();

        return redirect()->route('admin.store.combos.index')->with('success', 'Combo eliminado.');
    }

    public function aiCopy(Request $request, StoreContext $storeContext, AiTaskRouter $ai)
    {
        $store = $this->currentStoreOrFail($storeContext);

        if (! $this->hasMiia()) {
            return response()->json([
                'success' => false,
                'error' => 'Configura la API Key de MIIA en General de plataforma.',
            ], 422);
        }

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'strategy' => ['required', Rule::in(['qty', 'pair', 'both'])],
            'qty_min' => ['required', 'integer', 'min:1', 'max:99'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
        ]);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $data['product_ids'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price', 'currency', 'description', 'image_url', 'verified_data']);

        if ($products->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Selecciona al menos un producto en el combo.'], 422);
        }

        $market = $store->market;
        $locale = strtolower((string) ($market?->locale ?? 'es'));
        $currency = strtoupper((string) ($market?->currency ?? 'MXN'));
        $strategyLabels = [
            'qty' => 'por cantidad mínima del mismo producto',
            'pair' => 'por llevar todos los productos juntos',
            'both' => 'por cantidad o por combinación completa',
        ];
        $strategy = $data['strategy'];
        $discLabel = $data['discount_type'] === 'percent'
            ? ((float) $data['discount_value']).'% de descuento'
            : 'precio fijo de '.number_format((float) $data['discount_value'], 2).' '.$currency;

        $lines = [];
        foreach ($products as $p) {
            $thumb = $p->image_url ?? data_get($p->verified_data, 'images.0', '');
            $lines[] = '- '.$p->name.' (SKU: '.($p->sku ?: '—').', '.number_format((float) $p->price, 2).' '.$currency.')'
                .($thumb ? ' · imagen: '.$thumb : '');
        }

        $system = <<<TXT
Eres redactor de catálogo para e-commerce. Escribe como una persona real: sobrio, claro y creíble.
Genera textos para un combo en la tienda "{$store->name}".

Idioma de name/description: {$locale}
Moneda: {$currency}
Regla del combo: {$strategyLabels[$strategy]}
Cantidad mínima (si aplica): {$data['qty_min']}
Beneficio: {$discLabel}

Productos incluidos (usa ESTOS, no inventes otros):
{$this->bulletLines($lines)}

Estilo obligatorio:
- name: nombre corto de catálogo, sin adjetivos exagerados ni mayúsculas innecesarias.
- description: 1 oración, máximo 2 si hace falta (140-220 caracteres). Indica qué incluye y el ahorro sin hype.
- image_prompt: IDENTITY BRIEF en inglés, 2-4 oraciones, máximo 400 caracteres. Sirve para un anuncio vertical 9:16, NO para una foto de catálogo.
  Debe decir: (1) qué es cada SKU en concreto (forma, color, cómo se usa: en el cuello, en la mano, en el piso…), (2) UNA sola escena realista donde conviven todos, (3) headline corta y CTA sugeridos en español.
  Las fotos reales se mandan aparte: el prompt NO redibuja ni “mejora” el producto. Prohibido inventar un gadget genérico.

PROHIBIDO en image_prompt: mesa de madera, fondo blanco de estudio, top-down, flat lay, "no text", "studio lighting", "sleek modern gadget", "premium aesthetic", "cinematic product shot", productos que no están en la lista, nombres en inglés inventados.

Evita por completo: emojis, signos de exclamación, "descubre", "no te pierdas", "ideal para", "revolucionario", "perfecto", listas con viñetas, preguntas retóricas y tono de anuncio de IA.

Responde ÚNICAMENTE con un objeto JSON válido UTF-8. Sin markdown, sin ```, sin texto antes ni después.
Escapa comillas y saltos de línea dentro de strings. Usa exactamente estas claves en inglés:
name, slug, description, image_prompt

Ejemplo de forma (valores ilustrativos):
{"name":"Combo aire fresco","slug":"combo-aire-fresco","description":"Aire portátil, ventilador de mano y mini ventilador de cuello con 10% de descuento al llevarlos juntos.","image_prompt":"Hot home office in Mexico. Floor portable evaporative air cooler, USB handheld turbine fan in hand, mute neck fan around the neck — each unit identical to its product photo. Spanish headline about beating the heat and CTA Compra ahora."}
TXT;

        $result = $ai->chat('combo_copy', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => 'Genera nombre, slug, descripción y prompt de imagen para este combo.'],
        ]);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'MIIA no pudo generar el copy del combo.',
            ], 422);
        }

        $raw = (string) ($result['content'] ?? '');
        $decoded = $this->parseComboAiPayload($raw);
        $partial = ! empty($decoded['_partial_parse']);

        $name = $this->clipText((string) ($decoded['name'] ?? ''), 190);
        $slug = Str::slug((string) ($decoded['slug'] ?? $name));
        $description = $this->humanizeComboDescription($this->clipText((string) ($decoded['description'] ?? ''), 2000));
        $imagePrompt = $this->clipText((string) ($decoded['image_prompt'] ?? ''), 500);

        if ($name === '' && $description === '' && $imagePrompt === '') {
            $decoded = array_merge(
                $this->fallbackComboCopy($products, $discLabel, (int) $data['qty_min']),
                $decoded
            );
            $partial = true;
            $name = $this->clipText((string) ($decoded['name'] ?? ''), 190);
            $slug = Str::slug((string) ($decoded['slug'] ?? $name));
            $description = $this->humanizeComboDescription($this->clipText((string) ($decoded['description'] ?? ''), 2000));
            $imagePrompt = $this->clipText((string) ($decoded['image_prompt'] ?? ''), 500);
        }

        if ($name === '' && $description === '' && $imagePrompt === '') {
            return response()->json([
                'success' => false,
                'error' => 'MIIA respondió, pero no se pudieron extraer campos útiles. Inténtalo de nuevo.',
            ], 422);
        }

        if ($slug === '') {
            $slug = Str::slug($name);
        }

        $suggestedImages = $products->map(function (Product $p) {
            return $p->image_url ?? data_get($p->verified_data, 'images.0', '');
        })->filter()->unique()->values()->all();

        return response()->json([
            'success' => true,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'image_prompt' => $imagePrompt,
            'suggested_images' => $suggestedImages,
            'partial_parse' => $partial,
        ]);
    }

    public function promoStyles(StoreContext $storeContext, ComboPromoStyleLibrary $library)
    {
        $this->currentStoreOrFail($storeContext);

        return response()->json([
            'success' => true,
            'styles' => $library->listStyles(),
        ]);
    }

    public function promoStyleTemplates(string $style, StoreContext $storeContext, ComboPromoStyleLibrary $library)
    {
        $this->currentStoreOrFail($storeContext);

        try {
            return response()->json([
                'success' => true,
                'style' => $style,
                'templates' => $library->listTemplates($style),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function promoStyleThumb(string $style, string $file, StoreContext $storeContext, ComboPromoStyleLibrary $library)
    {
        $this->currentStoreOrFail($storeContext);

        try {
            $filename = $library->decodeThumbFilename($file);
            $path = $library->resolveTemplatePath($style, $filename);
            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            };

            return response()->file($path, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=3600',
            ]);
        } catch (\Throwable $e) {
            abort(404);
        }
    }

    public function aiGenerateImage(Request $request, StoreContext $storeContext, OpenAiComboImageService $images, ComboPromoStyleLibrary $library)
    {
        @set_time_limit(600);

        $store = $this->currentStoreOrFail($storeContext);

        if (! $this->hasMiia()) {
            return response()->json([
                'success' => false,
                'error' => 'Configura la API Key de MIIA en General para generar la imagen.',
            ], 422);
        }

        $data = $request->validate([
            'image_prompt' => ['required', 'string', 'max:1200'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'style' => ['nullable', 'string', 'max:80'],
            'template_files' => ['nullable', 'array'],
            'template_files.*' => ['string', 'max:255'],
            'template_selections' => ['nullable', 'array'],
            'template_selections.*.style' => ['required', 'string', 'max:80'],
            'template_selections.*.file' => ['required', 'string', 'max:255'],
            'strategy' => ['nullable', 'in:qty,pair,both'],
            'qty_min' => ['nullable', 'integer', 'min:1', 'max:99'],
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $data['product_ids'])
            ->get(['id', 'name', 'price', 'image_url', 'verified_data']);

        $productImages = [];
        $productNames = [];
        $seenUrls = [];
        foreach ($products as $p) {
            $url = trim((string) ($p->image_url ?? data_get($p->verified_data, 'images.0', '')));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $productImages[] = $url;
            $name = trim((string) $p->name);
            $productNames[] = $name !== '' ? $name : ('Producto '.(count($productNames) + 1));
        }

        $pricing = $this->comboPriceContext($store, $products, $data);

        $style = trim((string) ($data['style'] ?? ''));
        $templateFiles = array_values(array_filter(array_map('trim', $data['template_files'] ?? [])));
        $templateSelections = collect($data['template_selections'] ?? [])
            ->map(function ($item) {
                if (! is_array($item)) {
                    return null;
                }

                $itemStyle = trim((string) ($item['style'] ?? ''));
                $itemFile = trim((string) ($item['file'] ?? ''));
                if ($itemStyle === '' || $itemFile === '') {
                    return null;
                }

                return [
                    'style' => $itemStyle,
                    'file' => $itemFile,
                ];
            })
            ->filter()
            ->unique(fn (array $item) => $item['style'].'|'.$item['file'])
            ->values()
            ->all();

        if ($templateSelections === [] && $style !== '' && $templateFiles !== []) {
            foreach ($templateFiles as $templateFile) {
                $templateSelections[] = [
                    'style' => $style,
                    'file' => $templateFile,
                ];
            }
        }

        if ($templateSelections !== []) {
            $results = [];
            $generatedUrls = [];

            $selectionsByStyle = collect($templateSelections)->groupBy('style');

            foreach ($selectionsByStyle as $selectionStyle => $items) {
                $selectionStyle = (string) $selectionStyle;
                $templateFiles = collect($items)
                    ->pluck('file')
                    ->map(fn ($file) => trim((string) $file))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                try {
                    $templatePaths = [];
                    foreach ($templateFiles as $templateFile) {
                        $templatePaths[] = $library->resolveTemplatePath($selectionStyle, $templateFile);
                    }

                    $imageResult = $images->generateFromStyleTemplates(
                        (string) $data['image_prompt'],
                        $templatePaths,
                        $productImages,
                        (int) $store->id,
                        $selectionStyle,
                        '1024x1536',
                        $productNames,
                        $pricing
                    );

                    if ($imageResult['success'] ?? false) {
                        $url = (string) ($imageResult['url'] ?? '');
                        $generatedUrls[] = $url;
                        $results[] = [
                            'style' => $selectionStyle,
                            'templates' => $templateFiles,
                            'template_count' => count($templateFiles),
                            'success' => true,
                            'url' => $url,
                        ];
                    } else {
                        $results[] = [
                            'style' => $selectionStyle,
                            'templates' => $templateFiles,
                            'template_count' => count($templateFiles),
                            'success' => false,
                            'error' => (string) ($imageResult['error'] ?? 'Error al generar.'),
                        ];
                    }
                } catch (\Throwable $e) {
                    $results[] = [
                        'style' => $selectionStyle,
                        'templates' => $templateFiles,
                        'template_count' => count($templateFiles),
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $okCount = count($generatedUrls);

            return response()->json([
                'success' => $okCount > 0,
                'results' => $results,
                'generated_image_urls' => $generatedUrls,
                'generated_image_url' => $generatedUrls[0] ?? null,
                'styles_requested' => $selectionsByStyle->count(),
                'error' => $okCount > 0 ? null : 'No se pudo generar ninguna imagen promocional.',
            ], $okCount > 0 ? 200 : 422);
        }

        $imageResult = $images->generatePromoImage(
            (string) $data['image_prompt'],
            $productImages,
            (int) $store->id
        );

        if (! ($imageResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => (string) ($imageResult['error'] ?? 'ChatGPT no pudo generar la imagen.'),
            ], 422);
        }

        $url = (string) ($imageResult['url'] ?? '');

        return response()->json([
            'success' => true,
            'generated_image_url' => $url,
            'generated_image_urls' => [$url],
            'results' => [[
                'template' => null,
                'success' => true,
                'url' => $url,
            ]],
        ]);
    }

    public function aiLanding(Request $request, StoreContext $storeContext, ComboLandingAiService $landing)
    {
        @set_time_limit(180);
        $store = $this->currentStoreOrFail($storeContext);

        if (! $this->hasMiia()) {
            return response()->json([
                'success' => false,
                'error' => 'Configura la API Key de MIIA en General para modificar la landing.',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
            'images' => ['required', 'array', 'min:1'],
            'images.*.style' => ['nullable', 'string', 'max:80'],
            'images.*.url' => ['required', 'string', 'max:2000'],
            'combo_id' => ['nullable', 'integer'],
        ]);

        $comboProductId = null;
        $comboId = (int) ($data['combo_id'] ?? 0);
        if ($comboId > 0) {
            $combo = Combo::query()
                ->where('store_id', $store->id)
                ->where('id', $comboId)
                ->first();
            $comboProductId = $combo?->product_id ? (int) $combo->product_id : null;
        }

        $result = $landing->apply($store, [
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? '',
            'description' => $data['description'] ?? '',
            'product_ids' => $data['product_ids'],
            'images' => $data['images'],
            'combo_product_id' => $comboProductId,
        ]);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function uploadImage(Request $request, StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $file = $data['file'];
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'combo';
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = $base.'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = $file->storeAs('combos/'.$store->id, $filename, 'public');
        $url = DesignAssetUrl::fromPath($path);

        return response()->json([
            'success' => true,
            'url' => $url,
        ]);
    }

    protected function hasMiia(): bool
    {
        return (bool) config('ai.providers.miia.api_key')
            || (bool) PlatformSetting::getValue('ai.miia.api_key');
    }

    protected function humanizeComboDescription(string $text): string
    {
        $v = trim($text);
        if ($v === '') {
            return '';
        }

        $v = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $v) ?? $v;
        $v = preg_replace('/!{2,}/u', '.', $v) ?? $v;
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;

        $banned = [
            'descubre', 'no te pierdas', 'revolucionario', 'increíble', 'increible',
            'perfecto para', 'ideal para', 'la mejor opción', 'la mejor opcion',
            'transforma tu', 'eleva tu', 'experiencia única', 'experiencia unica',
        ];
        foreach ($banned as $phrase) {
            $v = preg_replace('/'.preg_quote($phrase, '/').'/iu', '', $v) ?? $v;
        }

        $v = trim($v, " \t\n\r\0\x0B.,;:-");
        if ($v !== '' && ! preg_match('/[.!?…]$/u', $v)) {
            $v .= '.';
        }

        return $this->clipText($v, 220);
    }

    /**
     * @param  list<string>  $lines
     */
    protected function bulletLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    protected function parseComboAiPayload(string $raw): array
    {
        $clean = $this->sanitizeAiRaw($raw);
        if ($clean === '') {
            return [];
        }

        $decoded = $this->tryDecodeJsonObject($clean);
        if ($decoded !== null) {
            return $this->normalizeComboAiKeys($decoded);
        }

        $out = $this->extractComboFieldsByRegex($clean);
        if ($out !== []) {
            $out['_partial_parse'] = '1';

            return $out;
        }

        return $this->extractComboFieldsFromPlainText($clean);
    }

    protected function sanitizeAiRaw(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/^[\s\S]*?```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```[\s\S]*$/u', '', $clean) ?? $clean;
        $clean = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99"], ['"', '"', "'", "'"], $clean);
        $clean = preg_replace('/^\s*json\s*/i', '', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function tryDecodeJsonObject(string $text): ?array
    {
        $candidates = array_values(array_unique(array_filter([
            $text,
            $this->extractJsonObjectSubstring($text),
        ])));

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $fixed = preg_replace('/,\s*([}\]])/u', '$1', $candidate) ?? $candidate;
            $fixed = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/u', '$1"$2":', $fixed) ?? $fixed;
            $decoded = json_decode($fixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function extractJsonObjectSubstring(string $text): ?string
    {
        if (preg_match('/\{[\s\S]*\}/u', $text, $m) !== 1) {
            return null;
        }

        return trim($m[0]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, string>
     */
    protected function normalizeComboAiKeys(array $decoded): array
    {
        $map = [
            'name' => ['name', 'nombre', 'title', 'titulo', 'combo_name'],
            'slug' => ['slug', 'url_slug', 'handle'],
            'description' => ['description', 'descripcion', 'desc', 'detalle'],
            'image_prompt' => ['image_prompt', 'imagePrompt', 'prompt_imagen', 'prompt', 'imagen_prompt'],
        ];

        $out = [];
        foreach ($map as $canonical => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $decoded) && $decoded[$key] !== null && $decoded[$key] !== '') {
                    $out[$canonical] = is_scalar($decoded[$key])
                        ? (string) $decoded[$key]
                        : json_encode($decoded[$key], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function extractComboFieldsByRegex(string $text): array
    {
        $out = [];
        $keys = [
            'name' => ['name', 'nombre', 'title', 'titulo'],
            'slug' => ['slug'],
            'description' => ['description', 'descripcion'],
            'image_prompt' => ['image_prompt', 'imagePrompt', 'prompt_imagen', 'prompt'],
        ];

        foreach ($keys as $canonical => $aliases) {
            foreach ($aliases as $key) {
                $val = $this->extractJsonStringField($text, $key);
                if ($val !== null && trim($val) !== '') {
                    $out[$canonical] = trim($val);
                    break;
                }
            }
        }

        return $out;
    }

    protected function extractJsonStringField(string $text, string $key): ?string
    {
        $quoted = preg_quote($key, '/');
        $patterns = [
            '/["\']?'.$quoted.'["\']?\s*:\s*"((?:\\\\.|[^"\\\\])*)"/su',
            '/["\']?'.$quoted.'["\']?\s*:\s*\'((?:\\\\.|[^\'])*)\'/su',
            '/["\']?'.$quoted.'["\']?\s*:\s*`((?:\\\\.|[^`])*)`/su',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                return stripcslashes($m[1]);
            }
        }

        if (preg_match('/["\']?'.$quoted.'["\']?\s*:\s*([^\n\r",\}]+)/u', $text, $m) === 1) {
            return trim($m[1], " \t\"'");
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function extractComboFieldsFromPlainText(string $text): array
    {
        $out = [];
        $labels = [
            'name' => ['nombre', 'name', 'título', 'titulo'],
            'slug' => ['slug'],
            'description' => ['descripción', 'descripcion', 'description'],
            'image_prompt' => ['prompt de imagen', 'image prompt', 'image_prompt', 'prompt'],
        ];

        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            foreach ($labels as $canonical => $labelsList) {
                if (isset($out[$canonical])) {
                    continue;
                }
                foreach ($labelsList as $label) {
                    if (! preg_match('/^[-*•\s]*'.preg_quote($label, '/').'\s*[:：-]\s*(.+)$/iu', $trimmed, $m)) {
                        continue;
                    }
                    $val = trim($m[1]);
                    if ($val !== '') {
                        $out[$canonical] = $val;
                        break 2;
                    }
                }
            }
        }

        if ($out !== []) {
            $out['_partial_parse'] = '1';
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<string, string>
     */
    protected function fallbackComboCopy($products, string $discLabel, int $qtyMin): array
    {
        $names = $products->pluck('name')->filter()->values();
        $short = $names->take(2)->implode(' + ');
        if ($names->count() > 2) {
            $short .= ' y más';
        }

        $name = $this->clipText('Combo '.$short, 190);
        $description = $this->humanizeComboDescription(
            $names->take(3)->implode(', ').'. '.$discLabel.'.'
        );

        $items = $names->take(4)->implode(', ');
        $imagePrompt = 'People actually using these exact products together: '.$items.'. One realistic indoor scene. Keep each unit identical to its product photo. Spanish headline and Compra ahora CTA.';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'image_prompt' => $imagePrompt,
            '_partial_parse' => '1',
        ];
    }

    protected function clipText(string $text, int $max): string
    {
        $v = trim($text);
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        $v = trim($v, "\"'` \t\n\r\0\x0B");

        if (mb_strlen($v) > $max) {
            $v = rtrim(mb_substr($v, 0, $max - 1)).'…';
        }

        return $v;
    }

    protected function validated(Request $request, $store, ?Combo $combo = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string'],
            'images' => ['nullable', 'string'],
            'strategy' => ['required', Rule::in(['qty', 'pair', 'both'])],
            'qty_min' => ['required', 'integer', 'min:1', 'max:99'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('store_id', $store->id)),
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    protected function strategyAndIds(Request $request, $store): array
    {
        $ids = array_values(array_unique(array_map('intval', $request->input('product_ids', []))));
        $strategy = (string) $request->input('strategy');
        if (count($ids) >= 2 && $strategy === 'qty') {
            $strategy = 'pair';
        }
        if ($strategy === 'qty') {
            $ids = array_slice($ids, 0, 1);
        }
        if ($strategy === 'pair' && count($ids) < 2) {
            throw ValidationException::withMessages([
                'product_ids' => 'La estrategia «Por combinación» necesita al menos 2 productos.',
            ]);
        }

        return [$strategy, $ids];
    }

    /**
     * @return list<int>
     */
    protected function itemIds(Request $request, $store): array
    {
        [, $ids] = $this->strategyAndIds($request, $store);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    protected function syncItems(Combo $combo, array $ids): void
    {
        $combo->items()->delete();
        foreach ($ids as $i => $pid) {
            $combo->items()->create([
                'product_id' => $pid,
                'qty' => 1,
                'sort_order' => $i,
            ]);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function eligibleProducts($store, ?int $keepProductId = null)
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'sku', 'status', 'price', 'currency', 'image_url', 'creative_data', 'verified_data'])
            ->filter(function (Product $p) use ($keepProductId) {
                if ($keepProductId && (int) $p->id === (int) $keepProductId) {
                    return true;
                }

                return empty(data_get($p->creative_data, 'is_combo'));
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    protected function parseImages(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $url = trim($line);
            if ($url !== '' && strlen($url) <= 500) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @param  array<string, mixed>  $data
     * @return array{currency: string, regular: float, final: float, regular_label: string, final_label: string}
     */
    protected function comboPriceContext($store, $products, array $data): array
    {
        $currency = strtoupper((string) ($store->currency() ?? 'MXN'));
        $strategy = (string) ($data['strategy'] ?? 'qty');
        $qtyMin = max(1, (int) ($data['qty_min'] ?? 1));
        $qty = in_array($strategy, ['qty', 'both'], true) ? $qtyMin : 1;
        $normal = 0.0;
        foreach ($products as $p) {
            $quote = $p->quoteIn($currency);
            $normal += (float) $quote['price'] * $qty;
        }
        $normal = round($normal, 2);
        $type = (string) ($data['discount_type'] ?? 'percent');
        $value = max(0, (float) ($data['discount_value'] ?? 0));
        if ($type === 'fixed') {
            $final = $normal > 0 ? min($normal, $value) : 0.0;
        } else {
            $final = round($normal * (1 - (min(90, $value) / 100)), 2);
        }
        $final = round($final, 2);

        return [
            'currency' => $currency,
            'regular' => $normal,
            'final' => $final,
            'regular_label' => '$'.number_format($normal, 2).' '.$currency,
            'final_label' => '$'.number_format($final, 2).' '.$currency,
        ];
    }
}
