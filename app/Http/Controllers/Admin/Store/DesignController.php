<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StoreDesign;
use App\Models\Theme;
use App\Services\Admin\StoreContext;
use App\Services\Storefront\CustomDesignRenderer;
use App\Services\Storefront\DesignAiFixService;
use App\Services\Storefront\DesignAssetUrl;
use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\DesignZipImporter;
use App\Services\Storefront\ThemeLibraryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DesignController extends Controller
{
    public function __construct(
        protected DesignThemeService $themes,
        protected ThemeLibraryService $library
    ) {}

    public function edit(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $this->library->ensureActiveDesign($store);
        $design = $this->themes->forDisplay($this->themes->normalize($store));

        return view('admin.store.design.edit', [
            'store' => $store,
            'design' => $design,
            'storeDesigns' => StoreDesign::query()
                ->with('theme:id,name,slug')
                ->where('store_id', $store->id)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get(),
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'starterGlobalCss' => $this->themes->starterGlobalCss(),
            'starterModulesCss' => $this->themes->starterModulesCss(),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'libraryThemes' => Theme::query()->orderByDesc('id')->limit(40)->get(),
            'translate_locales' => app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales(),
            'design_locale' => (string) ($design['default_locale'] ?? $design['locale'] ?? $design['lang'] ?? ''),
            'design_currency' => (string) ($design['default_currency'] ?? $design['currency'] ?? ''),
            'design_locales' => array_values(array_filter(array_map('strval', $design['locales'] ?? []))),
            'design_currencies' => array_values(array_filter(array_map('strval', $design['currencies'] ?? []))),
            'currencies' => app(\App\Services\Currency\CurrencyService::class)->catalog(),
            'locale_currency_map' => collect(app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales())
                ->mapWithKeys(function ($l) {
                    $code = app(\App\Services\Currency\CurrencyService::class)->currencyForLocale($l['locale']);

                    return [$l['locale'] => $code ?: 'USD'];
                })
                ->all(),
            'store_default_locale' => $store->defaultLocale(),
            'designerPrompt' => $this->themes->designerPrompt($store, $design),
            'moduleCatalog' => \App\Services\Storefront\Modules\ModuleRegistry::CATALOG,
        ]);
    }

    public function translate(Request $request, StoreContext $storeContext, \App\Services\Storefront\DesignTranslationService $translator)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $localeCodes = array_column($translator->availableLocales(), 'locale');
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:12', Rule::in($localeCodes)],
        ]);

        $result = $translator->translateStore($store, (string) $data['locale']);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        }

        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'No se pudo traducir la plantilla.');
        }

        return back()->with('success', trim(($result['message'] ?? 'Plantilla traducida.').' '.($result['summary'] ?? '')));
    }

    public function update(Request $request, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'global_css' => ['nullable', 'string', 'max:200000'],
            'modules_css' => ['nullable', 'string', 'max:200000'],
            'global_js' => ['nullable', 'string', 'max:200000'],
            'checkout_primary' => ['nullable', 'string', 'max:20'],
            'checkout_accent' => ['nullable', 'string', 'max:20'],
            'checkout_button' => ['nullable', 'string', 'max:20'],
            'checkout_bg' => ['nullable', 'string', 'max:20'],
            'checkout_text' => ['nullable', 'string', 'max:20'],
            'default_locale' => ['nullable', 'string', 'max:12'],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', 'max:12'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string', 'size:3'],
            'section' => ['nullable', 'string', Rule::in(['theme', 'i18n'])],
        ]);

        $design = $this->themes->normalize($store);
        $section = (string) ($data['section'] ?? 'theme');

        if ($section === 'i18n') {
            $localeCodes = array_column(app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales(), 'locale');
            $currencyCodes = array_column(app(\App\Services\Currency\CurrencyService::class)->catalog(), 'code');

            $defaultLocale = trim((string) ($data['default_locale'] ?? ''));
            if ($defaultLocale === '' || ! in_array($defaultLocale, $localeCodes, true)) {
                return back()->withInput()->with('error', 'Idioma por defecto no válido.');
            }
            $locales = array_values(array_unique(array_filter(array_map('strval', $data['locales'] ?? []))));
            $locales = array_values(array_filter($locales, fn ($l) => in_array($l, $localeCodes, true)));
            if ($locales === []) {
                $locales = [$defaultLocale];
            } elseif (! in_array($defaultLocale, $locales, true)) {
                $locales[] = $defaultLocale;
            }

            $defaultCurrency = strtoupper(trim((string) ($data['default_currency'] ?? '')));
            if ($defaultCurrency === '' || ! in_array($defaultCurrency, $currencyCodes, true)) {
                return back()->withInput()->with('error', 'Moneda por defecto no válida.');
            }
            $currencies = array_values(array_unique(array_filter(array_map(
                fn ($c) => strtoupper((string) $c),
                $data['currencies'] ?? []
            ))));
            $currencies = array_values(array_filter($currencies, fn ($c) => in_array($c, $currencyCodes, true)));
            if ($currencies === []) {
                $currencies = [$defaultCurrency];
            } elseif (! in_array($defaultCurrency, $currencies, true)) {
                $currencies[] = $defaultCurrency;
            }

            $design['default_locale'] = $defaultLocale;
            $design['locale'] = $defaultLocale;
            $design['locales'] = $locales;
            $design['default_currency'] = $defaultCurrency;
            $design['currency'] = $defaultCurrency;
            $design['currencies'] = $currencies;
            $this->themes->save($store, $design);

            return back()->with('success', 'Idioma y moneda de la plantilla guardados.');
        }

        $design['enabled'] = $request->boolean('enabled');
        $design['global_css'] = (string) ($data['global_css'] ?? '');
        $design['modules_css'] = (string) ($data['modules_css'] ?? '');
        $design['global_js'] = (string) ($data['global_js'] ?? '');
        $design['checkout'] = [
            'primary' => $data['checkout_primary'] ?: '#0f766e',
            'accent' => $data['checkout_accent'] ?: '#f59e0b',
            'button' => $data['checkout_button'] ?: '#0f766e',
            'bg' => $data['checkout_bg'] ?: '#ffffff',
            'text' => $data['checkout_text'] ?: '#0f172a',
        ];

        $this->themes->save($store, $design);

        return back()->with('success', 'Diseño de tienda guardado.');
    }

    public function storePage(Request $request, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DesignThemeService::PAGE_TYPES))],
            'title' => ['required', 'string', 'max:120'],
            'handle' => ['nullable', 'string', 'max:80'],
            'with_starter' => ['nullable', 'boolean'],
        ]);

        $design = $this->themes->normalize($store);
        $type = $data['type'];
        $handle = $data['handle'] ?? ($type === 'landing' ? 'index' : $type);

        // Una sola landing / product template por tipo (reemplaza handle si duplicado de sistema)
        if (in_array($type, ['landing', 'product', 'catalog', 'cart', 'checkout'], true)) {
            $handle = $type === 'landing' ? 'index' : $type;
            foreach ($design['pages'] as $existing) {
                if (($existing['type'] ?? '') === $type || ($existing['handle'] ?? '') === $handle) {
                    return back()->with('error', 'Ya existe una página «'.(DesignThemeService::PAGE_TYPES[$type] ?? $type).'». Edítala o cámbiale el handle.');
                }
            }
        } else {
            $handle = Str::slug($handle ?: $data['title']) ?: 'page';
            foreach ($design['pages'] as $existing) {
                if (($existing['handle'] ?? '') === $handle) {
                    return back()->with('error', 'El handle «'.$handle.'» ya está en uso.');
                }
            }
        }

        $page = $this->themes->makePage([
            'type' => $type,
            'handle' => $handle,
            'title' => $data['title'],
            'html' => $type === 'page' && $request->boolean('with_starter')
                ? $this->themes->starterHtml($type, $store->name)
                : '',
            'status' => 'draft',
        ]);

        $design['pages'][] = $page;
        if (trim((string) $design['global_css']) === '' && $request->boolean('with_starter')) {
            $design['global_css'] = $this->themes->starterGlobalCss();
        }
        $this->themes->save($store, $design);

        return redirect()
            ->route('admin.store.design.pages.edit', $page['id'])
            ->with('success', 'Página creada.');
    }

    public function editPage(string $page, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->forDisplay($this->themes->normalize($store));
        $pageData = $this->themes->findPage($design, $page);
        abort_unless($pageData, 404);

        return view('admin.store.design.page', [
            'store' => $store,
            'design' => $design,
            'page' => $pageData,
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'starterHtml' => $this->themes->starterHtml($pageData['type'], $store->name),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'moduleCatalog' => array_keys(\App\Services\Storefront\Modules\ModuleRegistry::CATALOG),
        ]);
    }

    public function aiFix(Request $request, StoreContext $storeContext, DesignAiFixService $fixer)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $data = $request->validate([
            'problem' => ['required', 'string', 'max:8000'],
            'page_id' => ['nullable', 'string', 'max:80'],
            'scope' => ['nullable', 'string', Rule::in(['page', 'global', 'both'])],
        ]);

        $result = $fixer->resolve(
            $store,
            (string) $data['problem'],
            $data['page_id'] ?? null,
            (string) ($data['scope'] ?? 'page')
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        }

        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'No se pudo resolver con MIIA.');
        }

        $msg = ($result['message'] ?? 'Corregido.').' '.($result['summary'] ?? '');
        if (! empty($result['page_id'])) {
            return redirect()
                ->route('admin.store.design.pages.edit', $result['page_id'])
                ->with('success', trim($msg));
        }

        return back()->with('success', trim($msg));
    }

    public function updatePage(Request $request, string $page, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->normalize($store);
        $existing = $this->themes->findPage($design, $page);
        abort_unless($existing, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'handle' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['draft', 'live'])],
            'html' => ['nullable', 'string', 'max:500000'],
            'css' => ['nullable', 'string', 'max:200000'],
            'js' => ['nullable', 'string', 'max:200000'],
            'modules' => ['nullable', 'array'],
        ]);

        $type = $existing['type'];
        $handle = $type === 'landing'
            ? 'index'
            : (Str::slug((string) ($data['handle'] ?? $existing['handle'])) ?: $existing['handle']);

        foreach ($design['pages'] as $other) {
            if (($other['id'] ?? '') === $page) {
                continue;
            }
            if (($other['handle'] ?? '') === $handle) {
                return back()->with('error', 'El handle «'.$handle.'» ya está en uso.');
            }
        }

        foreach ($design['pages'] as $i => $row) {
            if (($row['id'] ?? '') !== $page) {
                continue;
            }
            $design['pages'][$i] = $this->themes->sanitizePage([
                ...$row,
                'title' => $data['title'],
                'handle' => $handle,
                'status' => $data['status'],
                'html' => (string) ($data['html'] ?? $row['html'] ?? ''),
                'css' => (string) ($data['css'] ?? ''),
                'js' => (string) ($data['js'] ?? ''),
                'modules' => array_key_exists('modules', $data) ? ($data['modules'] ?? []) : ($row['modules'] ?? null),
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $this->themes->save($store, $design);

        return back()->with('success', 'Página guardada.');
    }

    public function destroyPage(string $page, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->normalize($store);
        $existing = $this->themes->findPage($design, $page);
        abort_unless($existing, 404);

        if (($existing['type'] ?? '') === 'landing') {
            return back()->with('error', 'No puedes eliminar la landing. Edítala o crea otra página.');
        }

        $design['pages'] = array_values(array_filter(
            $design['pages'],
            fn ($p) => ($p['id'] ?? '') !== $page
        ));
        $this->themes->save($store, $design);

        return redirect()->route('admin.store.design.edit')->with('success', 'Página eliminada.');
    }

    public function uploadZip(Request $request, StoreContext $storeContext, DesignZipImporter $importer)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $request->validate([
            // No usar mimes:zip: en Windows suele llegar como application/x-zip-compressed
            'zip' => ['required', 'file', 'max:20480'],
            'name' => ['nullable', 'string', 'max:120'],
            'save_to_library' => ['nullable', 'boolean'],
            'library_name' => ['nullable', 'string', 'max:120'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('zip');
        if (! $file || ! $file->isValid()) {
            return back()->with('error', 'Archivo ZIP inválido o incompleto.');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('error', 'El archivo debe tener extensión .zip');
        }

        $result = $importer->import($store, $file, [
            'name' => $request->input('name'),
            // La tienda no publica ni toca la biblioteca global.
            'save_to_library' => false,
            'activate' => $request->boolean('activate', true),
        ]);
        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'No se pudo importar el ZIP.');
        }

        return back()->with('success', $result['message'] ?? 'ZIP importado.');
    }

    public function activateDesign(StoreDesign $storeDesign, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $storeDesign->store_id === $store->id, 404);
        $this->library->activate($storeDesign);

        return back()->with('success', 'Ahora está asignada «'.$storeDesign->name.'». La editas abajo.');
    }

    public function duplicateDesign(StoreDesign $storeDesign, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $storeDesign->store_id === $store->id, 404);
        $copy = $this->library->duplicate($storeDesign);

        return back()->with('success', 'Copia de esta tienda guardada: '.$copy->name.'. La plantilla global no se modificó.');
    }

    public function resetDesignFromLibrary(StoreDesign $storeDesign, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $storeDesign->store_id === $store->id, 404);
        try {
            $this->library->resetStoreDesignFromTheme($storeDesign);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Se restableció la copia desde la biblioteca. La plantilla global no se modificó.');
    }

    public function destroyDesign(StoreDesign $storeDesign, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store && $storeDesign->store_id === $store->id, 404);
        try {
            $this->library->deleteStoreDesign($storeDesign);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Copia eliminada de esta tienda. La plantilla global no se tocó.');
    }

    public function saveDesignToLibrary(Request $request, StoreDesign $storeDesign, StoreContext $storeContext)
    {
        abort(403, 'Esta tienda no puede crear ni modificar plantillas globales. Usa Plataforma → Plantillas.');
    }

    public function applyTheme(Request $request, Theme $theme, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $existed = StoreDesign::query()
            ->where('store_id', $store->id)
            ->where('theme_id', $theme->id)
            ->exists();

        $this->library->applyThemeToStore(
            $theme,
            $store,
            $data['name'] ?? null,
            true
        );

        $msg = $existed
            ? ('Se activó la copia de «'.$theme->name.'» que ya tenía esta tienda. La global no se tocó.')
            : ('Se asignó una copia editable de «'.$theme->name.'». La plantilla global no se modifica.');

        return back()->with('success', $msg);
    }

    public function editor(string $page, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->forDisplay($this->themes->normalize($store));
        $pageData = $this->themes->findPage($design, $page);
        abort_unless($pageData, 404);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['live', 'draft'])
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit(48)
            ->get(['id', 'name', 'slug', 'price', 'compare_at_price', 'currency', 'image_url', 'is_featured', 'badge', 'status', 'creative_data']);

        $editorProducts = $products->map(function ($p) use ($store) {
            $quote = $p->quoteIn($store->currency());

            return [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'price' => (float) $quote['price'],
                'price_formatted' => '$'.number_format((float) $quote['price'], 2),
                'currency' => $quote['currency'],
                'image' => $p->image_url,
                'featured' => (bool) $p->is_featured,
                'badge' => $p->badge,
            ];
        })->values()->all();

        $pageId = (string) ($pageData['id'] ?? $page);
        $pageTitle = (string) ($pageData['title'] ?? 'Página');
        $pageType = (string) ($pageData['type'] ?? '');
        $pageTypeLabel = DesignThemeService::PAGE_TYPES[$pageType] ?? $pageType;
        $rawHtml = (string) ($pageData['html'] ?? '');
        $checkout = is_array($design['checkout'] ?? null) ? $design['checkout'] : [];
        $checkoutCss = sprintf(
            ':root{--md-checkout-primary:%s;--md-checkout-accent:%s;--md-checkout-button:%s;--md-checkout-bg:%s;--md-checkout-text:%s;}',
            $checkout['primary'] ?? '#0f766e',
            $checkout['accent'] ?? '#f59e0b',
            $checkout['button'] ?? '#0f766e',
            $checkout['bg'] ?? '#ffffff',
            $checkout['text'] ?? '#0f172a'
        );
        $pageCss = trim(implode("\n", array_filter([
            $checkoutCss,
            $this->themes->extractHeadCss($rawHtml),
            (string) ($design['global_css'] ?? ''),
            (string) ($pageData['css'] ?? ''),
        ])));
        $pageCss = DesignAssetUrl::localize($this->themes->rewriteCssAssetUrls($pageCss, $design['assets'] ?? []));

        $editorHtml = $this->themes->extractBodyHtml($rawHtml);
        $editorHtml = (string) preg_replace('/^(?:\s*<style\b[^>]*>.*?<\/style>\s*)+/is', '', $editorHtml);

        $canvasStyles = $this->themes->extractStylesheetUrls($rawHtml);
        foreach ($design['assets'] ?? [] as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $hint = strtolower((string) (($asset['name'] ?? '').' '.($asset['path'] ?? '').' '.($asset['url'] ?? '')));
            if (! preg_match('/\.css(\?|$)/i', $hint)) {
                continue;
            }
            if (preg_match('/(?:^|[\/\\\\])(theme|global|styles)\.css/i', $hint)) {
                continue;
            }
            $url = (string) ($asset['url'] ?? '');
            if ($url !== '') {
                $canvasStyles[] = $url;
            }
        }
        $canvasStyles = array_values(array_unique(array_filter($canvasStyles)));

        return view('admin.store.design.editor', [
            'store' => $store,
            'design' => $design,
            'page' => $pageData,
            'pageId' => $pageId,
            'pageTitle' => $pageTitle,
            'pageTypeLabel' => $pageTypeLabel,
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'products' => $products,
            'productsJsonUrl' => route('admin.store.products.json'),
            'editorSaveUrl' => route('admin.store.design.editor.save', $pageId),
            'editorHtml' => $editorHtml,
            'editorCss' => $pageCss,
            'editorCanvasStyles' => $canvasStyles,
            'editorBodyAttrs' => $this->themes->extractBodyAttributes($rawHtml),
            'editorProducts' => $editorProducts,
        ]);
    }

    public function saveEditor(Request $request, string $page, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->normalize($store);
        $existing = $this->themes->findPage($design, $page);
        abort_unless($existing, 404);

        $data = $request->validate([
            'html' => ['nullable', 'string', 'max:500000'],
            'css' => ['nullable', 'string', 'max:200000'],
        ]);

        foreach ($design['pages'] as $i => $row) {
            if (($row['id'] ?? '') !== $page) {
                continue;
            }
            $design['pages'][$i] = $this->themes->sanitizePage([
                ...$row,
                'html' => (string) ($data['html'] ?? ''),
                // El editor visual no reescribe theme.css: conservar CSS de página.
                'css' => (string) ($row['css'] ?? ''),
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $this->themes->save($store, $design);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => 'Página guardada.']);
        }

        return back()->with('success', 'Página guardada.');
    }

    public function productsJson(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['live', 'draft'])
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit(80)
            ->get()
            ->map(function (Product $p) use ($store) {
                $img = $p->image_url;
                if ($img && str_starts_with((string) $img, '/media/')) {
                    $img = asset(ltrim($img, '/'));
                }

                $quote = $p->quoteIn($store->currency());

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'price' => (float) $quote['price'],
                    'price_formatted' => '$'.number_format((float) $quote['price'], 2),
                    'currency' => $quote['currency'],
                    'image' => $img,
                    'featured' => (bool) $p->is_featured,
                    'badge' => $p->badge,
                    'url' => route('store.design.page', ['slug' => $store->slug, 'handle' => $p->slug]),
                ];
            });

        return response()->json(['products' => $products]);
    }

    public function uploadAsset(Request $request, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,svg,css,js,woff,woff2'],
        ]);

        $file = $data['file'];
        $active = $this->library->ensureActiveDesign($store);
        $dir = 'store-designs/'.$active->id;
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = $name.'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = $file->storeAs($dir, $filename, 'public');
        $url = Storage::disk('public')->url($path);

        $design = $this->themes->normalize($store);
        $asset = [
            'id' => (string) Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'url' => $url,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $design['assets'][] = $asset;
        $this->themes->save($store, $design);

        return back()->with('success', 'Asset subido: '.$url);
    }

    public function destroyAsset(string $asset, StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->normalize($store);
        $kept = [];
        foreach ($design['assets'] as $row) {
            if (($row['id'] ?? '') === $asset) {
                if (! empty($row['path'])) {
                    Storage::disk('public')->delete($row['path']);
                }

                continue;
            }
            $kept[] = $row;
        }
        $design['assets'] = $kept;
        $this->themes->save($store, $design);

        return back()->with('success', 'Asset eliminado.');
    }

    public function inspect(Request $request, StoreContext $storeContext, CustomDesignRenderer $renderer)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $handle = (string) $request->query('handle', 'index');

        return response()->json($renderer->inspect($store, $handle));
    }

    public function preview(Request $request, StoreContext $storeContext, CustomDesignRenderer $renderer)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $pageId = (string) $request->query('page', '');
        $handle = (string) $request->query('handle', 'index');

        return $renderer->response($store, [
            'page_id' => $pageId !== '' ? $pageId : null,
            'handle' => $handle,
            'preview' => true,
        ]);
    }

    public function seedDefaults(StoreContext $storeContext)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);
        $design = $this->themes->normalize($store);

        $needed = [
            ['type' => 'landing', 'handle' => 'index', 'title' => 'Inicio'],
            ['type' => 'catalog', 'handle' => 'catalog', 'title' => 'Catálogo'],
            ['type' => 'product', 'handle' => 'product', 'title' => 'Producto'],
            ['type' => 'cart', 'handle' => 'cart', 'title' => 'Carrito'],
            ['type' => 'checkout', 'handle' => 'checkout', 'title' => 'Checkout'],
        ];

        $handles = collect($design['pages'])->pluck('handle')->all();
        $types = collect($design['pages'])->pluck('type')->all();
        $created = 0;

        foreach ($needed as $spec) {
            if (in_array($spec['handle'], $handles, true) || in_array($spec['type'], $types, true)) {
                continue;
            }
            $design['pages'][] = $this->themes->makePage([
                ...$spec,
                'html' => '',
                'status' => 'live',
            ]);
            $created++;
        }

        if (trim((string) $design['global_css']) === '') {
            $design['global_css'] = $this->themes->starterGlobalCss();
        }

        $this->themes->save($store, $design);

        return back()->with('success', $created
            ? "Plantilla base creada: {$created} página(s)."
            : 'Ya tienes las páginas base. No se creó nada nuevo.');
    }
}
