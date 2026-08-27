<?php

namespace App\Http\Controllers\Admin\Store;

use App\Domain\AI\AiTaskRouter;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Market;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\Admin\StoreContext;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreGeneralController extends Controller
{
    public function edit(StoreContext $storeContext, CurrencyService $currency)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $settings = $store->settings ?? [];
        $payments = $settings['payments'] ?? [];
        $site = is_array($settings['site'] ?? null) ? $settings['site'] : [];

        $available = $this->platformConfiguredGateways();
        $markets = Market::query()
            ->where('is_active', true)
            ->orderBy('region')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $primaryDomain = $store->domains()->where('is_primary', true)->first()
            ?: $store->domains()->orderByDesc('is_active')->first();

        $defaultLocale = (string) ($site['default_locale'] ?? $store->market?->locale ?? 'es_MX');
        $defaultCurrency = strtoupper((string) ($site['currency'] ?? $site['default_currency'] ?? $store->market?->currency ?? 'MXN'));
        $enabledLocales = array_values(array_filter(array_map('strval', $site['locales'] ?? [])));
        if ($enabledLocales === []) {
            $enabledLocales = [$defaultLocale];
        }
        if (! in_array($defaultLocale, $enabledLocales, true)) {
            $enabledLocales[] = $defaultLocale;
        }
        $enabledCurrencies = array_values(array_filter(array_map(
            fn ($c) => strtoupper((string) $c),
            $site['currencies'] ?? []
        )));
        if ($enabledCurrencies === []) {
            $enabledCurrencies = [$defaultCurrency];
        }
        if ($defaultCurrency !== '' && ! in_array($defaultCurrency, $enabledCurrencies, true)) {
            $enabledCurrencies[] = $defaultCurrency;
        }

        $localeCurrencyMap = [];
        foreach ($this->availableLocales() as $loc) {
            $localeCurrencyMap[$loc['locale']] = $currency->currencyForLocale($loc['locale']) ?: $defaultCurrency;
        }

        $allStores = Store::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'store_type', 'parent_id']);
        $blockedParentIds = array_merge([(int) $store->id], $store->descendantIds($allStores));
        $parentOptions = $allStores
            ->filter(fn (Store $s) => ! in_array((int) $s->id, $blockedParentIds, true))
            ->values();

        $identity = is_array(data_get($settings, 'identity')) ? $settings['identity'] : [];

        return view('admin.store.general.edit', [
            'store' => $store,
            'payments_enabled' => (bool) ($payments['enabled'] ?? false),
            'payment_gateway' => $payments['gateway'] ?? null,
            'available' => $available,
            'labels' => $this->gatewayLabels(),
            'markets' => $markets,
            'locales' => $this->availableLocales(),
            'currencies' => $currency->catalog(),
            'locale_currency_map' => $localeCurrencyMap,
            'default_locale' => $defaultLocale,
            'default_currency' => $defaultCurrency,
            'enabled_locales' => $enabledLocales,
            'enabled_currencies' => $enabledCurrencies,
            'public_url' => (string) ($site['public_url'] ?? ''),
            'path_prefix' => (string) ($site['path_prefix'] ?? ($primaryDomain?->path_prefix ?? '')),
            'countries' => array_values(array_filter(array_map('strval', $site['countries'] ?? []))),
            'fallback_public_url' => url('/s/'.$store->slug),
            'primary_domain' => $primaryDomain,
            'platform_services' => config('multidrop.services', []),
            'platform_plugins' => config('multidrop.plugins', []),
            'service_flags' => $store->serviceFlags(),
            'plugin_flags' => $store->pluginFlags(),
            'plugin_devices' => $store->pluginDevices(),
            'shipping_markup_percent' => (float) data_get($settings, 'shipping.markup_percent', 10),
            'catalog_per_page' => (int) data_get($site, 'catalog_per_page', 12),
            'seo_title' => (string) data_get($settings, 'seo.title', ''),
            'seo_description' => (string) data_get($settings, 'seo.description', ''),
            'seo_og_image' => (string) data_get($settings, 'seo.og_image', ''),
            'parent_options' => $parentOptions,
            'identity_color' => (string) ($identity['color'] ?? $store->identityColor()),
            'identity_icon' => (string) ($identity['icon'] ?? ''),
        ]);
    }

    public function update(Request $request, StoreContext $storeContext, CurrencyService $currency)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        $available = array_keys($this->platformConfiguredGateways());
        $marketCodes = Market::query()->where('is_active', true)->pluck('code')->all();
        $localeCodes = array_column($this->availableLocales(), 'locale');
        $currencyCodes = array_column($currency->catalog(), 'code');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'store_type' => ['required', 'string', Rule::in(['mega', 'mini'])],
            'parent_id' => ['nullable', 'integer', 'exists:stores,id'],
            'identity_color' => ['nullable', 'string', 'max:7'],
            'identity_icon' => ['nullable', 'string', 'max:16'],
            'default_locale' => ['required', 'string', 'max:12', Rule::in($localeCodes)],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', Rule::in($localeCodes)],
            'default_currency' => ['required', 'string', 'size:3', Rule::in($currencyCodes)],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string', 'size:3', Rule::in($currencyCodes)],
            'public_url' => ['nullable', 'string', 'max:500'],
            'path_prefix' => ['nullable', 'string', 'max:80'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', Rule::in($marketCodes)],
            'payments_enabled' => ['nullable', 'boolean'],
            'shipping_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'catalog_per_page' => ['nullable', 'integer', Rule::in([8, 12, 16, 24, 36, 48])],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
            'seo_og_image' => ['nullable', 'string', 'max:500'],
            'payment_gateway' => [
                'nullable',
                'string',
                Rule::in(['mercadopago', 'stripe', 'paypal']),
                Rule::requiredIf(fn () => $request->boolean('payments_enabled')),
            ],
            'services' => ['nullable', 'array'],
            'plugins' => ['nullable', 'array'],
            'plugin_devices' => ['nullable', 'array'],
        ]);

        $storeType = (string) $data['store_type'];
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : 0;
        if ($storeType === 'mega') {
            $parentId = 0;
        }
        if ($storeType === 'mini') {
            if ($parentId < 1) {
                return back()->withInput()->with('error', 'Una mini-tienda necesita una tienda padre (mega u otra mini).');
            }
            if ($parentId === (int) $store->id) {
                return back()->withInput()->with('error', 'La tienda no puede ser padre de sí misma.');
            }
            $descendants = $store->descendantIds();
            if (in_array($parentId, $descendants, true)) {
                return back()->withInput()->with('error', 'No puedes mover esta tienda debajo de una de sus hijas (ciclo).');
            }
            $parent = Store::query()->active()->find($parentId);
            if (! $parent) {
                return back()->withInput()->with('error', 'La tienda padre no existe o está archivada.');
            }
        }

        $enabled = $request->boolean('payments_enabled');
        $gateway = $data['payment_gateway'] ?? null;

        if ($enabled && $gateway && ! in_array($gateway, $available, true)) {
            return back()
                ->withInput()
                ->with('error', 'Esa pasarela no tiene API en General de plataforma. Configúrala ahí primero.');
        }

        $publicUrl = trim((string) ($data['public_url'] ?? ''));
        if ($publicUrl !== '' && ! preg_match('#^https?://#i', $publicUrl)) {
            $publicUrl = 'https://'.$publicUrl;
        }
        if ($publicUrl !== '' && ! filter_var($publicUrl, FILTER_VALIDATE_URL)) {
            return back()->withInput()->with('error', 'La URL de la tienda no es válida.');
        }

        $pathPrefix = trim((string) ($data['path_prefix'] ?? ''));
        if ($pathPrefix !== '') {
            $pathPrefix = '/'.trim($pathPrefix, '/');
        } else {
            $pathPrefix = null;
        }

        $countries = array_values(array_unique(array_map(
            fn ($c) => strtoupper((string) $c),
            $data['countries'] ?? []
        )));

        $defaultLocale = (string) $data['default_locale'];
        $locales = array_values(array_unique(array_filter(array_map('strval', $data['locales'] ?? []))));
        if ($locales === []) {
            $locales = [$defaultLocale];
        }
        if (! in_array($defaultLocale, $locales, true)) {
            $locales[] = $defaultLocale;
        }

        $defaultCurrency = strtoupper((string) $data['default_currency']);
        $currencies = array_values(array_unique(array_filter(array_map(
            fn ($c) => strtoupper((string) $c),
            $data['currencies'] ?? []
        ))));
        if ($currencies === []) {
            $currencies = [$defaultCurrency];
        }
        if (! in_array($defaultCurrency, $currencies, true)) {
            $currencies[] = $defaultCurrency;
        }

        DB::transaction(function () use ($store, $data, $enabled, $gateway, $publicUrl, $pathPrefix, $countries, $request, $defaultLocale, $locales, $defaultCurrency, $currencies, $storeType, $parentId) {
            $settings = $store->settings ?? [];
            $settings['site'] = [
                'default_locale' => $defaultLocale,
                'locales' => $locales,
                'currency' => $defaultCurrency,
                'default_currency' => $defaultCurrency,
                'currencies' => $currencies,
                'public_url' => $publicUrl !== '' ? $publicUrl : null,
                'path_prefix' => $pathPrefix,
                'countries' => $countries,
            ];
            $settings['payments'] = [
                'enabled' => $enabled,
                'gateway' => $enabled ? $gateway : null,
            ];

            $serviceFlags = [];
            foreach (array_keys(config('multidrop.services', [])) as $key) {
                $serviceFlags[$key] = $request->boolean('services.'.$key);
            }
            $pluginFlags = [];
            $pluginDevices = [];
            foreach (array_keys(config('multidrop.plugins', [])) as $key) {
                $desktop = $request->boolean('plugin_devices.'.$key.'.desktop');
                $mobile = $request->boolean('plugin_devices.'.$key.'.mobile');
                $pluginDevices[$key] = ['desktop' => $desktop, 'mobile' => $mobile];
                $pluginFlags[$key] = $desktop || $mobile;
            }
            $settings['services'] = $serviceFlags;
            $settings['plugins'] = $pluginFlags;
            $settings['plugin_devices'] = $pluginDevices;
            $settings['shipping'] = [
                'markup_percent' => (float) ($data['shipping_markup_percent'] ?? 10),
            ];
            $settings['site']['catalog_per_page'] = (int) ($data['catalog_per_page'] ?? 12);
            $settings['seo'] = [
                'title' => trim((string) ($data['seo_title'] ?? '')) ?: null,
                'description' => trim((string) ($data['seo_description'] ?? '')) ?: null,
                'og_image' => trim((string) ($data['seo_og_image'] ?? '')) ?: null,
            ];
            $color = strtoupper(trim((string) ($data['identity_color'] ?? '')));
            if ($color !== '' && ! str_starts_with($color, '#')) {
                $color = '#'.$color;
            }
            if (! preg_match('/^#[0-9A-F]{6}$/', $color)) {
                $color = '';
            }
            $icon = trim((string) ($data['identity_icon'] ?? ''));
            $settings['identity'] = [
                'color' => preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : null,
                'icon' => $icon !== '' ? mb_substr($icon, 0, 4) : null,
            ];

            $store->name = trim((string) $data['name']);
            $store->store_type = $storeType;
            $store->parent_id = $storeType === 'mini' ? $parentId : null;
            $store->settings = $settings;
            $store->save();

            $this->syncPrimaryDomain($store, $publicUrl, $pathPrefix);
        });

        return back()->with('success', 'General de la tienda actualizado.');
    }

    protected function syncPrimaryDomain($store, ?string $publicUrl, ?string $pathPrefix): void
    {
        if (! $publicUrl) {
            return;
        }

        $parts = parse_url($publicUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return;
        }

        $urlPath = trim((string) ($parts['path'] ?? ''), '/');
        $prefix = $pathPrefix;
        if ($prefix === null && $urlPath !== '') {
            $prefix = '/'.$urlPath;
        }

        $type = 'apex';
        if (substr_count($host, '.') >= 2 && ! str_starts_with($host, 'www.')) {
            $type = 'subdomain';
        }
        if ($prefix) {
            $type = 'path';
        }

        $domain = $store->domains()->where('is_primary', true)->first();
        if (! $domain) {
            $domain = new Domain(['store_id' => $store->id, 'is_primary' => true]);
        }

        // Evitar choque unique(host, path_prefix) con otra tienda
        $clash = Domain::query()
            ->where('host', $host)
            ->where('path_prefix', $prefix)
            ->when($domain->exists, fn ($q) => $q->where('id', '!=', $domain->id))
            ->exists();

        if ($clash) {
            return;
        }

        $domain->fill([
            'host' => $host,
            'path_prefix' => $prefix,
            'type' => $type,
            'is_primary' => true,
            'is_active' => true,
        ]);
        $domain->store_id = $store->id;
        $domain->save();

        $store->domains()
            ->where('id', '!=', $domain->id)
            ->update(['is_primary' => false]);
    }

    /**
     * @return list<array{locale: string, label: string, name: string, iso: string}>
     */
    protected function availableLocales(): array
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

        $fromMarkets = Market::query()
            ->where('is_active', true)
            ->whereNotNull('locale')
            ->orderBy('name')
            ->get(['locale', 'name', 'code']);

        foreach ($fromMarkets as $m) {
            $loc = (string) $m->locale;
            if ($loc !== '' && ! isset($preferred[$loc])) {
                $preferred[$loc] = $m->name.' ('.$m->code.')';
            }
        }

        $out = [];
        foreach ($preferred as $locale => $label) {
            $iso = strtolower((string) substr($locale, -2));
            if ($iso === 'uk') {
                $iso = 'gb';
            }
            $out[] = [
                'locale' => $locale,
                'name' => $label,
                'label' => $label,
                'iso' => strlen($iso) === 2 ? $iso : '',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, bool>
     */
    protected function platformConfiguredGateways(): array
    {
        return [
            'mercadopago' => (bool) (
                PlatformSetting::getValue('payments.mercadopago.access_token')
                ?: config('payments.mercadopago.access_token')
            ),
            'stripe' => (bool) (
                PlatformSetting::getValue('payments.stripe.secret')
                ?: config('payments.stripe.secret')
            ),
            'paypal' => (bool) (
                PlatformSetting::getValue('payments.paypal.client_id')
                ?: config('payments.paypal.client_id')
            ),
        ];
    }

    protected function gatewayLabels(): array
    {
        return [
            'mercadopago' => 'Mercado Pago',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
        ];
    }

    public function aiSeo(Request $request, StoreContext $storeContext, AiTaskRouter $ai)
    {
        $store = $storeContext->current();
        abort_unless($store, 404);

        if (! $ai->hasMiia()) {
            return response()->json([
                'success' => false,
                'error' => 'Configura la API Key de MIIA en General de plataforma.',
            ], 422);
        }

        $market = $store->market;
        $locale = strtolower((string) ($market?->locale ?? 'es'));
        $country = strtoupper((string) ($market?->code ?? 'MX'));
        $currency = strtoupper((string) ($market?->currency ?? 'MXN'));
        $storeName = $store->name;
        $sector = $store->sector ?? 'general';

        $system = <<<TXT
Eres un experto en SEO, branding y e-commerce para tiendas de dropshipping.
Dado el nombre y contexto de la tienda, genera información de marketing lista para usar.

Tienda: {$storeName}
Sector: {$sector}
País/Mercado: {$country} · {$currency}
Idioma de la respuesta: {$locale}

Responde ÚNICAMENTE con un objeto JSON válido con esta estructura exacta (sin markdown, sin texto adicional):
{
  "seo_title": "Título SEO (máximo 60 caracteres, incluye nombre tienda y beneficio clave)",
  "seo_description": "Descripción meta (máximo 155 caracteres, beneficio + llamado a acción)",
  "tagline": "Slogan corto de la tienda (máximo 70 caracteres)",
  "about": "Descripción corta de la tienda para el footer o página About (2-3 oraciones, máximo 200 caracteres)"
}
TXT;

        $result = $ai->chat('store_seo', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Genera la información SEO y marketing para la tienda \"{$storeName}\"."],
        ]);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Error al generar con IA.',
            ], 422);
        }

        $raw = trim((string) ($result['content'] ?? ''));
        $decoded = $this->parseAiSeoPayload($raw);

        if (! is_array($decoded)) {
            return response()->json([
                'success' => false,
                'error' => 'La IA devolvió un formato inesperado. Inténtalo de nuevo.',
                'raw' => $raw,
            ], 422);
        }

        $seoTitle = $this->normalizeSeoText((string) ($decoded['seo_title'] ?? ''), 70);
        $seoDescription = $this->normalizeSeoText((string) ($decoded['seo_description'] ?? ''), 180);
        $tagline = $this->normalizeSeoText((string) ($decoded['tagline'] ?? ''), 120);
        $about = $this->normalizeSeoText((string) ($decoded['about'] ?? ''), 300);

        if ($seoTitle === '' && $seoDescription === '' && $tagline === '' && $about === '') {
            return response()->json([
                'success' => false,
                'error' => 'La IA respondió, pero no se pudieron extraer campos útiles.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'tagline' => $tagline,
            'about' => $about,
        ]);
    }

    /**
     * Parsea respuestas IA tolerando markdown, texto adicional y JSON parcial.
     *
     * @return array<string, string>|null
     */
    protected function parseAiSeoPayload(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```[a-z]*\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/i', '', $clean) ?? $clean;
        $clean = trim($clean);

        // 1) Intento directo (JSON puro)
        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 2) Extraer el primer bloque {...} (si vino texto antes/después)
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonChunk = substr($clean, $start, $end - $start + 1);
            $decodedChunk = json_decode($jsonChunk, true);
            if (is_array($decodedChunk)) {
                return $decodedChunk;
            }
        }

        // 3) Fallback por regex de claves esperadas (aunque no sea JSON válido)
        $fields = [];
        foreach (['seo_title', 'seo_description', 'tagline', 'about'] as $key) {
            $pattern = '/"'.preg_quote($key, '/').'"\s*:\s*"((?:\\\\.|[^"])*)"/u';
            if (preg_match($pattern, $clean, $m) === 1) {
                $fields[$key] = stripcslashes($m[1]);
            }
        }

        return $fields !== [] ? $fields : null;
    }

    protected function normalizeSeoText(string $text, int $max): string
    {
        $v = trim($text);
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        $v = trim($v, "\"'` \t\n\r\0\x0B");

        if (mb_strlen($v) > $max) {
            $v = rtrim(mb_substr($v, 0, $max - 1)).'…';
        }

        return $v;
    }
}
