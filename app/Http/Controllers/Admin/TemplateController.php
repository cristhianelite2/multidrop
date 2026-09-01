<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Theme;
use App\Services\Storefront\DesignAssetUrl;
use App\Services\Storefront\DesignThemeService;
use App\Services\Storefront\DesignZipExporter;
use App\Services\Storefront\DesignZipImporter;
use App\Services\Storefront\ThemeLibraryService;
use App\Services\Storefront\ThemeSandboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function __construct(
        protected DesignThemeService $themes,
        protected ThemeLibraryService $library,
        protected ThemeSandboxService $sandbox
    ) {}

    public function index()
    {
        $themes = Theme::query()->orderByDesc('id')->get();

        return view('admin.templates.index', [
            'themes' => $themes,
            'stores' => $this->applyStores(),
            'designerPrompt' => $this->themes->designerPromptForLibrary('Plantilla Multidrop', 'plantilla', $this->themes->defaults()),
            'sandboxModuleOptions' => $this->sandboxModuleOptions(),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'translate_locales' => app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales(),
        ]);
    }

    public function store(Request $request, DesignZipImporter $importer)
    {
        $request->validate([
            'zip' => ['required', 'file', 'max:20480'],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('zip');
        if (! $file || ! $file->isValid()) {
            return back()->with('error', 'Archivo ZIP inválido o incompleto.');
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('error', 'El archivo debe tener extensión .zip');
        }

        $result = $importer->importToLibrary($file, [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);
        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'No se pudo importar el ZIP.');
        }

        return redirect()
            ->route('admin.templates.edit', $result['theme_id'])
            ->with('success', $result['message'] ?? 'Plantilla importada.');
    }

    public function downloadZip(Theme $theme, DesignZipExporter $exporter)
    {
        $path = $exporter->exportTheme($theme);
        if (! $path || ! is_file($path)) {
            return back()->with('error', 'No se pudo generar el ZIP de la plantilla.');
        }

        $filename = Str::slug($theme->slug ?: $theme->name).'-template.zip';

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    public function edit(Theme $theme)
    {
        $design = $this->displayDesign($theme);

        return view('admin.templates.edit', [
            'theme' => $theme,
            'design' => $design,
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'designerPrompt' => $this->themes->designerPromptForLibrary($theme->name, $theme->slug, $design),
            'stores' => $this->applyStores(),
            'starterGlobalCss' => $this->themes->starterGlobalCss(),
            'sandboxModuleOptions' => $this->sandboxModuleOptions(),
            'starterModulesCss' => $this->themes->starterModulesCss(),
            'has_miia' => (bool) config('ai.providers.miia.api_key'),
            'translate_locales' => app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales(),
            'design_locale' => (string) ($design['default_locale'] ?? $design['locale'] ?? ''),
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
        ]);
    }

    public function translate(Request $request, Theme $theme, \App\Services\Storefront\DesignTranslationService $translator)
    {
        $localeCodes = array_column($translator->availableLocales(), 'locale');
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:12', Rule::in($localeCodes)],
        ]);

        $result = $translator->translateTheme($theme, (string) $data['locale']);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        }

        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'No se pudo traducir la plantilla.');
        }

        return back()->with('success', trim(($result['message'] ?? 'Plantilla traducida.').' '.($result['summary'] ?? '')));
    }

    public function update(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'prompt_notes' => ['nullable', 'string', 'max:20000'],
            'default_locale' => ['nullable', 'string', 'max:12'],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', 'max:12'],
            'default_currency' => ['nullable', 'string', 'size:3'],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string', 'size:3'],
            'global_css' => ['nullable', 'string', 'max:200000'],
            'modules_css' => ['nullable', 'string', 'max:200000'],
            'global_js' => ['nullable', 'string', 'max:200000'],
            'checkout_primary' => ['nullable', 'string', 'max:20'],
            'checkout_accent' => ['nullable', 'string', 'max:20'],
            'checkout_button' => ['nullable', 'string', 'max:20'],
            'checkout_bg' => ['nullable', 'string', 'max:20'],
            'checkout_text' => ['nullable', 'string', 'max:20'],
            'section' => ['nullable', 'string', Rule::in(['meta', 'notes', 'theme', 'i18n'])],
        ]);

        $design = $this->rawDesign($theme);
        $section = $data['section'] ?? 'theme';

        if ($section === 'meta') {
            if (! empty($data['name'])) {
                $theme->name = $data['name'];
            }
            $theme->description = isset($data['description']) ? (trim((string) $data['description']) ?: null) : $theme->description;
            $theme->save();

            return back()->with('success', 'Datos de la plantilla guardados.');
        }

        if ($section === 'i18n') {
            $localeCodes = array_column(app(\App\Services\Storefront\DesignTranslationService::class)->availableLocales(), 'locale');
            $currencyCodes = array_column(app(\App\Services\Currency\CurrencyService::class)->catalog(), 'code');

            $defaultLocale = trim((string) ($data['default_locale'] ?? ''));
            if ($defaultLocale !== '' && ! in_array($defaultLocale, $localeCodes, true)) {
                return back()->withInput()->with('error', 'Idioma por defecto no válido.');
            }
            $locales = array_values(array_unique(array_filter(array_map('strval', $data['locales'] ?? []))));
            $locales = array_values(array_filter($locales, fn ($l) => in_array($l, $localeCodes, true)));
            if ($defaultLocale !== '') {
                if ($locales === []) {
                    $locales = [$defaultLocale];
                } elseif (! in_array($defaultLocale, $locales, true)) {
                    $locales[] = $defaultLocale;
                }
            }

            $defaultCurrency = strtoupper(trim((string) ($data['default_currency'] ?? '')));
            if ($defaultCurrency !== '' && ! in_array($defaultCurrency, $currencyCodes, true)) {
                return back()->withInput()->with('error', 'Moneda por defecto no válida.');
            }
            $currencies = array_values(array_unique(array_filter(array_map(
                fn ($c) => strtoupper((string) $c),
                $data['currencies'] ?? []
            ))));
            $currencies = array_values(array_filter($currencies, fn ($c) => in_array($c, $currencyCodes, true)));
            if ($defaultCurrency !== '') {
                if ($currencies === []) {
                    $currencies = [$defaultCurrency];
                } elseif (! in_array($defaultCurrency, $currencies, true)) {
                    $currencies[] = $defaultCurrency;
                }
            }

            $design['default_locale'] = $defaultLocale;
            $design['locale'] = $defaultLocale;
            $design['locales'] = $locales;
            $design['default_currency'] = $defaultCurrency;
            $design['currency'] = $defaultCurrency;
            $design['currencies'] = $currencies;
            $this->library->saveTheme($theme, $design);

            return back()->with('success', 'Idioma y moneda de la plantilla guardados.');
        }

        if ($section === 'notes') {
            $design['prompt_notes'] = (string) ($data['prompt_notes'] ?? '');
            $this->library->saveTheme($theme, $design);

            return back()->with('success', 'Notas del brief guardadas.');
        }

        if (array_key_exists('prompt_notes', $data)) {
            $design['prompt_notes'] = (string) ($data['prompt_notes'] ?? '');
        }
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
        $this->library->saveTheme($theme, $design);

        return back()->with('success', 'Plantilla guardada.');
    }

    public function destroy(Theme $theme)
    {
        $this->library->deleteTheme($theme);

        return redirect()->route('admin.templates.index')->with('success', 'Plantilla eliminada.');
    }

    public function apply(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'activate' => ['nullable', 'boolean'],
        ]);
        $store = Store::query()->findOrFail((int) $data['store_id']);
        $this->library->applyThemeToStore(
            $theme,
            $store,
            $data['name'] ?? null,
            true
        );

        return back()->with('success', 'Se asignó una copia de «'.$theme->name.'» a «'.$store->name.'». La plantilla global no se modificó. Edítala en Diseño de esa tienda.');
    }

    public function launchSandbox(Request $request, Theme $theme)
    {
        $allowed = array_merge(['commerce'], array_keys(config('multidrop.plugins', [])));
        $raw = $request->input('modules', []);
        if (! is_array($raw)) {
            $raw = [];
        }
        $modules = [];
        foreach ($allowed as $key) {
            $modules[$key] = $request->boolean('modules.'.$key) || filter_var($raw[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        // Si el form no envió nada (edge), dejar todos activos
        if (! $request->has('modules')) {
            $modules = $this->sandbox->defaultModules();
        }

        // Flujo limpio: carrito, cupones y datos de sesión del sandbox
        $this->sandbox->resetFlow($theme);
        $this->sandbox->rememberModules($theme, $modules);

        $active = collect($modules)->filter()->keys()->implode(',');

        return redirect()->route('theme.sandbox.show', [
            'theme' => $theme->slug,
            'md_modules' => $active,
            'md_reset' => 1,
        ]);
    }

    public function seed(Theme $theme)
    {
        $design = $this->rawDesign($theme);
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
                'status' => 'draft',
            ]);
            $created++;
        }
        if (trim((string) $design['global_css']) === '') {
            $design['global_css'] = $this->themes->starterGlobalCss();
        }
        $this->library->saveTheme($theme, $design);

        return back()->with('success', $created
            ? "Páginas base creadas: {$created}."
            : 'Ya tienes las páginas base.');
    }

    public function storePage(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DesignThemeService::PAGE_TYPES))],
            'title' => ['required', 'string', 'max:120'],
            'handle' => ['nullable', 'string', 'max:80'],
            'with_starter' => ['nullable', 'boolean'],
        ]);

        $design = $this->rawDesign($theme);
        $type = $data['type'];
        $handle = $data['handle'] ?? ($type === 'landing' ? 'index' : $type);

        if (in_array($type, ['landing', 'product', 'catalog', 'cart', 'checkout'], true)) {
            $handle = $type === 'landing' ? 'index' : $type;
            foreach ($design['pages'] as $existing) {
                if (($existing['type'] ?? '') === $type || ($existing['handle'] ?? '') === $handle) {
                    return back()->with('error', 'Ya existe una página «'.(DesignThemeService::PAGE_TYPES[$type] ?? $type).'».');
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
            'html' => $request->boolean('with_starter')
                ? $this->themes->starterHtml($type, $theme->name)
                : '',
            'status' => 'draft',
        ]);
        $design['pages'][] = $page;
        if (trim((string) $design['global_css']) === '' && $request->boolean('with_starter')) {
            $design['global_css'] = $this->themes->starterGlobalCss();
        }
        $this->library->saveTheme($theme, $design);

        return redirect()
            ->route('admin.templates.pages.edit', [$theme, $page['id']])
            ->with('success', 'Página creada.');
    }

    public function editPage(Theme $theme, string $page)
    {
        $design = $this->displayDesign($theme);
        $pageData = $this->themes->findPage($design, $page);
        abort_unless($pageData, 404);

        return view('admin.store.design.page', [
            'store' => $theme,
            'design' => $design,
            'page' => $pageData,
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'starterHtml' => $this->themes->starterHtml($pageData['type'], $theme->name),
            'has_miia' => false,
            'hideAiFix' => true,
            'moduleCatalog' => array_keys(\App\Services\Storefront\Modules\ModuleRegistry::CATALOG),
            'designBackUrl' => route('admin.templates.edit', $theme),
            'designEditorUrl' => route('admin.templates.editor', [$theme, $pageData['id']]),
            'designPreviewUrl' => $this->sandboxUrl($theme, $pageData),
            'designPublicUrl' => $this->sandboxUrl($theme, $pageData),
            'pageUpdateUrl' => route('admin.templates.pages.update', [$theme, $pageData['id']]),
            'pageDestroyUrl' => route('admin.templates.pages.destroy', [$theme, $pageData['id']]),
        ]);
    }

    public function updatePage(Request $request, Theme $theme, string $page)
    {
        $design = $this->rawDesign($theme);
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
        $this->library->saveTheme($theme, $design);

        return back()->with('success', 'Página guardada.');
    }

    public function destroyPage(Theme $theme, string $page)
    {
        $design = $this->rawDesign($theme);
        $existing = $this->themes->findPage($design, $page);
        abort_unless($existing, 404);
        if (($existing['type'] ?? '') === 'landing') {
            return back()->with('error', 'No puedes eliminar la landing.');
        }
        $design['pages'] = array_values(array_filter(
            $design['pages'],
            fn ($p) => ($p['id'] ?? '') !== $page
        ));
        $this->library->saveTheme($theme, $design);

        return redirect()->route('admin.templates.edit', $theme)->with('success', 'Página eliminada.');
    }

    public function editor(Theme $theme, string $page)
    {
        $design = $this->displayDesign($theme);
        $pageData = $this->themes->findPage($design, $page);
        abort_unless($pageData, 404);

        $editorProducts = $this->sandbox->demoProducts($theme)->map(static function ($p) {
            return [
                'id' => $p['id'],
                'name' => $p['name'],
                'slug' => $p['slug'],
                'price' => $p['price'],
                'price_formatted' => $p['price_formatted'],
                'image' => $p['image'],
                'featured' => $p['featured'],
                'badge' => $p['badge'],
            ];
        })->values()->all();

        $pageId = (string) ($pageData['id'] ?? $page);
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

        return view('admin.store.design.editor', [
            'store' => $theme,
            'design' => $design,
            'page' => $pageData,
            'pageId' => $pageId,
            'pageTitle' => (string) ($pageData['title'] ?? 'Página'),
            'pageTypeLabel' => DesignThemeService::PAGE_TYPES[$pageData['type'] ?? ''] ?? ($pageData['type'] ?? ''),
            'pageTypes' => DesignThemeService::PAGE_TYPES,
            'products' => collect($editorProducts),
            'productsJsonUrl' => route('admin.templates.products.json', $theme),
            'editorSaveUrl' => route('admin.templates.editor.save', [$theme, $pageId]),
            'editorHtml' => $editorHtml,
            'editorCss' => $pageCss,
            'editorCanvasStyles' => array_values(array_unique(array_filter($canvasStyles))),
            'editorBodyAttrs' => $this->themes->extractBodyAttributes($rawHtml),
            'editorProducts' => $editorProducts,
            'editorBackUrl' => route('admin.templates.edit', $theme),
            'editorCodeUrl' => route('admin.templates.pages.edit', [$theme, $pageId]),
            'editorPreviewUrl' => $this->sandboxUrl($theme, $pageData),
        ]);
    }

    public function saveEditor(Request $request, Theme $theme, string $page)
    {
        $design = $this->rawDesign($theme);
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
                'css' => (string) ($row['css'] ?? ''),
                'updated_at' => now()->toIso8601String(),
            ]);
        }
        $this->library->saveTheme($theme, $design);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => 'Página guardada.']);
        }

        return back()->with('success', 'Página guardada.');
    }

    public function productsJson(Theme $theme)
    {
        return response()->json([
            'products' => $this->sandbox->demoProducts($theme)->values()->all(),
        ]);
    }

    public function uploadAsset(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,svg,css,js,woff,woff2'],
        ]);
        $file = $data['file'];
        $dir = 'themes/'.$theme->id;
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $filename = ($name ?: 'asset').'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = $file->storeAs($dir, $filename, 'public');

        $design = $this->rawDesign($theme);
        $design['assets'][] = [
            'id' => (string) Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'url' => DesignAssetUrl::fromPath($path),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $this->library->saveTheme($theme, $design);

        return back()->with('success', 'Asset subido.');
    }

    public function destroyAsset(Theme $theme, string $asset)
    {
        $design = $this->rawDesign($theme);
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
        $this->library->saveTheme($theme, $design);

        return back()->with('success', 'Asset eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawDesign(Theme $theme): array
    {
        return $this->themes->normalizeDesign(
            is_array($theme->design) ? $theme->design : [],
            $theme->name
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function displayDesign(Theme $theme): array
    {
        return $this->themes->forDisplay($this->rawDesign($theme));
    }

    /**
     * @param  array<string, mixed>  $page
     */
    protected function sandboxUrl(Theme $theme, array $page): string
    {
        $handle = (string) ($page['handle'] ?? 'index');
        if (in_array($handle, ['index', ''], true) || ($page['type'] ?? '') === 'landing') {
            return route('theme.sandbox.show', $theme->slug);
        }

        return route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => $handle]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Store>
     */
    protected function applyStores()
    {
        return Store::query()
            ->where('status', '!=', 'archived')
            ->orderBy('store_type')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'store_type']);
    }

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    protected function sandboxModuleOptions(): array
    {
        $opts = [];
        foreach (config('multidrop.services', []) as $key => $svc) {
            $opts[] = [
                'key' => $key,
                'label' => $svc['label'] ?? $key,
                'group' => 'Servicio',
            ];
        }
        foreach (config('multidrop.plugins', []) as $key => $plugin) {
            $opts[] = [
                'key' => $key,
                'label' => $plugin['label'] ?? $key,
                'group' => 'Plugin',
            ];
        }

        return $opts;
    }
}
