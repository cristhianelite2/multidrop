@extends('layouts.admin')

@section('title', 'General — '.$store->name)
@section('heading', 'General')
@section('subheading', 'Configuración solo de «'.$store->name.'». Las API keys de pasarelas y CJ están en General de plataforma.')

@section('content')
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        @canperm('settings.general')
            <a href="{{ route('admin.settings.general') }}" class="admin-btn-secondary">General plataforma ↗</a>
        @endcanperm
    </div>

    @php
        $anyAvailable = collect($available)->contains(true);
        $selectedCountries = collect(old('countries', $countries ?? []))->map(fn ($c) => strtoupper((string) $c))->all();
        $marketsByRegion = ($markets ?? collect())->groupBy(fn ($m) => $m->region ?: 'other');
        $selectedLocale = (string) old('default_locale', $default_locale);
        $selectedLocaleMeta = collect($locales ?? [])->firstWhere('locale', $selectedLocale)
            ?: (collect($locales ?? [])->first() ?? ['locale' => $selectedLocale, 'label' => $selectedLocale, 'name' => $selectedLocale, 'iso' => '']);
        $selectedLocaleIso = (string) ($selectedLocaleMeta['iso'] ?? '');
        $selectedCurrency = strtoupper((string) old('default_currency', $default_currency ?? 'MXN'));
        $selectedCurrencyMeta = collect($currencies ?? [])->firstWhere('code', $selectedCurrency)
            ?: ['code' => $selectedCurrency, 'label' => $selectedCurrency];
        $checkedLocales = collect(old('locales', $enabled_locales ?? [$selectedLocale]))->map(fn ($l) => (string) $l)->all();
        if (! in_array($selectedLocale, $checkedLocales, true)) {
            $checkedLocales[] = $selectedLocale;
        }
        $checkedCurrencies = collect(old('currencies', $enabled_currencies ?? [$selectedCurrency]))
            ->map(fn ($c) => strtoupper((string) $c))->all();
        if (! in_array($selectedCurrency, $checkedCurrencies, true)) {
            $checkedCurrencies[] = $selectedCurrency;
        }
        $localeCurrencyMap = $locale_currency_map ?? [];
    @endphp

    @if(! $anyAvailable)
        <div class="mb-4 max-w-3xl rounded-2xl border border-amber/30 bg-amber/10 px-4 py-3 text-sm text-amber">
            No hay pasarelas con API en General de plataforma.
            @canperm('settings.general')
                <a href="{{ route('admin.settings.general') }}" class="font-semibold underline">Configurar APIs</a>
            @else
                Pide a un admin con permiso de General (plataforma) que cargue Stripe / PayPal / Mercado Pago.
            @endcanperm
        </div>
    @endif

    <form method="post" action="{{ route('admin.store.general.update') }}" class="space-y-5" id="store-general-form">
        @csrf
        @method('PUT')

        <div class="admin-blocks">
        <div class="admin-card p-5 sm:p-6 space-y-5">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Identidad y ubicación</h2>
                <p class="mt-1 text-sm text-ink-soft/65">Nombre, color/icono del árbol de sitios, y si esta tienda es mega o mini (puedes moverla).</p>
            </div>

            @php
                $selectedType = old('store_type', $store->store_type ?: 'mini');
                $selectedParent = (int) old('parent_id', $store->parent_id ?? 0);
                $identityColor = strtoupper((string) old('identity_color', $identity_color ?? $store->identityColor()));
                $identityIcon = (string) old('identity_icon', $identity_icon ?? '');
                $colorPresets = ['#0F766E', '#0284C7', '#7C3AED', '#DC2626', '#EA580C', '#CA8A04', '#16A34A', '#0F172A', '#DB2777', '#57534E'];
                $iconPresets = ['⚡', '🏠', '💡', '🔥', '💧', '🌿', '💎', '🎯', '⭐', '🛠'];
                $parentOptions = $parent_options ?? collect();
            @endphp

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre de la tienda</label>
                    <input type="text" name="name" value="{{ old('name', $store->name) }}" required maxlength="80" class="admin-input" placeholder="Emergency Power">
                    <p class="mt-1 text-xs text-ink-soft/55">El slug público <code>/{{ $store->slug }}</code> no cambia al renombrar.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tipo</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-white px-3 py-2.5 has-[:checked]:border-sky-400 has-[:checked]:bg-sky-50">
                            <input type="radio" name="store_type" value="mega" class="text-sky-600" @checked($selectedType === 'mega')>
                            <span>
                                <span class="block text-sm font-semibold text-ink">Mega</span>
                                <span class="block text-[11px] text-ink-soft/55">Raíz del árbol, sin padre</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-white px-3 py-2.5 has-[:checked]:border-teal/50 has-[:checked]:bg-teal/10">
                            <input type="radio" name="store_type" value="mini" class="text-teal" @checked($selectedType === 'mini')>
                            <span>
                                <span class="block text-sm font-semibold text-ink">Mini</span>
                                <span class="block text-[11px] text-ink-soft/55">Cuelga de otra tienda</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div id="store-parent-wrap" class="{{ $selectedType === 'mini' ? '' : 'hidden' }}">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mover bajo (padre)</label>
                    @if($parentOptions->isEmpty())
                        <p class="rounded-xl border border-amber/30 bg-amber/10 px-3 py-2 text-sm text-amber">No hay otra tienda disponible como padre. Crea o elige una mega primero.</p>
                        <input type="hidden" name="parent_id" value="">
                    @else
                        <select name="parent_id" id="store-parent-id" class="admin-input" {{ $selectedType === 'mini' ? 'required' : '' }}>
                            <option value="">Selecciona tienda padre…</option>
                            @foreach($parentOptions as $opt)
                                <option value="{{ $opt->id }}" @selected($selectedParent === (int) $opt->id)>
                                    {{ $opt->name }} · {{ $opt->store_type }} · /{{ $opt->slug }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-ink-soft/55">Puedes colgar una mini de una mega o de otra mini. Las hijas de esta tienda se mueven con ella.</p>
                    @endif
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Color</label>
                    <div class="flex items-center gap-3">
                        <input type="color" id="identity-color-picker" value="{{ $identityColor }}" class="h-10 w-12 cursor-pointer rounded-lg border border-line bg-white p-0.5">
                        <input type="text" name="identity_color" id="identity-color" value="{{ $identityColor }}" pattern="^#[0-9A-Fa-f]{6}$" maxlength="7" class="admin-input font-mono uppercase" placeholder="#0F766E">
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5" id="identity-color-presets">
                        @foreach($colorPresets as $hex)
                            <button type="button" class="h-7 w-7 rounded-lg border border-black/10 shadow-sm" style="background: {{ $hex }}" data-color="{{ $hex }}" title="{{ $hex }}"></button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Icono</label>
                    <div class="flex items-center gap-3">
                        <span id="identity-preview" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-bold" style="background: {{ $identityColor }}; color: {{ $store->identityInk() }}">{{ $identityIcon !== '' ? $identityIcon : $store->identityIcon() }}</span>
                        <input type="text" name="identity_icon" id="identity-icon" value="{{ $identityIcon }}" maxlength="8" class="admin-input" placeholder="EP o ⚡">
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5" id="identity-icon-presets">
                        @foreach($iconPresets as $ico)
                            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line bg-white text-base hover:border-teal/40" data-icon="{{ $ico }}">{{ $ico }}</button>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-ink-soft/55">Emoji o 1–2 letras. Si lo dejas vacío se usan las iniciales.</p>
                </div>
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-5">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Tienda y alcance</h2>
                <p class="mt-1 text-sm text-ink-soft/65">Idiomas y monedas compatibles, defaults, URL pública y países de esta tienda.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Idioma por defecto</label>
                    <input type="hidden" name="default_locale" id="default-locale-input" value="{{ $selectedLocaleMeta['locale'] }}" required>

                    <div id="locale-picker" class="relative">
                        <button type="button" id="locale-toggle" class="admin-input flex w-full items-center gap-3 text-left">
                            <span id="locale-flag" class="market-flag fi {{ $selectedLocaleIso ? 'fi-'.$selectedLocaleIso : '' }}" @if($selectedLocaleIso) data-iso="{{ $selectedLocaleIso }}" @endif></span>
                            <span class="min-w-0 flex-1">
                                <span id="locale-label" class="block truncate font-semibold text-ink">{{ $selectedLocaleMeta['label'] ?? $selectedLocaleMeta['name'] ?? $selectedLocale }}</span>
                                <span id="locale-meta" class="block truncate text-xs text-ink-soft/60">{{ $selectedLocaleMeta['locale'] ?? $selectedLocale }}</span>
                            </span>
                            <span class="text-ink-soft/50">▾</span>
                        </button>

                        <div id="locale-menu" class="absolute left-0 right-0 z-40 mt-2 hidden overflow-hidden rounded-2xl border border-line bg-white shadow-xl shadow-ink/10">
                            <div class="border-b border-line p-2">
                                <input type="search" id="locale-search" placeholder="Buscar idioma, país o código…" class="admin-input" autocomplete="off">
                            </div>
                            <div id="locale-list" class="max-h-72 overflow-y-auto p-1.5">
                                @foreach($locales as $loc)
                                    @php
                                        $iso = (string) ($loc['iso'] ?? '');
                                        $isSelected = ($selectedLocaleMeta['locale'] ?? '') === ($loc['locale'] ?? '');
                                        $searchBlob = strtolower(trim(($loc['label'] ?? '').' '.($loc['name'] ?? '').' '.($loc['locale'] ?? '').' '.$iso));
                                    @endphp
                                    <button
                                        type="button"
                                        class="locale-option flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-mist {{ $isSelected ? 'bg-teal/10 ring-1 ring-teal/20' : '' }}"
                                        data-locale="{{ $loc['locale'] }}"
                                        data-name="{{ $loc['label'] ?? $loc['name'] }}"
                                        data-iso="{{ $iso }}"
                                        data-currency="{{ $localeCurrencyMap[$loc['locale']] ?? '' }}"
                                        data-search="{{ $searchBlob }}"
                                    >
                                        <span class="market-flag fi {{ $iso ? 'fi-'.$iso : '' }}"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold text-ink">{{ $loc['label'] ?? $loc['name'] }}</span>
                                            <span class="block truncate text-[11px] text-ink-soft/60">{{ $loc['locale'] }}@if(!empty($localeCurrencyMap[$loc['locale']])) · {{ $localeCurrencyMap[$loc['locale']] }}@endif</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            <div id="locale-empty" class="hidden px-3 py-6 text-center text-sm text-ink-soft/60">Sin resultados</div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-ink-soft/55">Base del storefront y de traducciones MIIA.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Moneda por defecto</label>
                    <input type="hidden" name="default_currency" id="default-currency-input" value="{{ $selectedCurrencyMeta['code'] }}" required>

                    <div id="currency-picker" class="relative">
                        <button type="button" id="currency-toggle" class="admin-input flex w-full items-center gap-3 text-left">
                            <span id="currency-code-badge" class="inline-flex h-7 min-w-[2.75rem] items-center justify-center rounded-lg bg-mist px-2 text-xs font-bold text-ink">{{ $selectedCurrencyMeta['code'] }}</span>
                            <span class="min-w-0 flex-1">
                                <span id="currency-label" class="block truncate font-semibold text-ink">{{ $selectedCurrencyMeta['label'] ?? $selectedCurrency }}</span>
                                <span id="currency-meta" class="block truncate text-xs text-ink-soft/60">{{ $selectedCurrencyMeta['code'] }}</span>
                            </span>
                            <span class="text-ink-soft/50">▾</span>
                        </button>

                        <div id="currency-menu" class="absolute left-0 right-0 z-40 mt-2 hidden overflow-hidden rounded-2xl border border-line bg-white shadow-xl shadow-ink/10">
                            <div class="border-b border-line p-2">
                                <input type="search" id="currency-search" placeholder="Buscar moneda o código…" class="admin-input" autocomplete="off">
                            </div>
                            <div id="currency-list" class="max-h-72 overflow-y-auto p-1.5">
                                @foreach($currencies as $cur)
                                    @php
                                        $isCur = ($selectedCurrencyMeta['code'] ?? '') === ($cur['code'] ?? '');
                                        $curSearch = strtolower(trim(($cur['label'] ?? '').' '.($cur['code'] ?? '')));
                                    @endphp
                                    <button
                                        type="button"
                                        class="currency-option flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-mist {{ $isCur ? 'bg-teal/10 ring-1 ring-teal/20' : '' }}"
                                        data-code="{{ $cur['code'] }}"
                                        data-name="{{ $cur['label'] }}"
                                        data-search="{{ $curSearch }}"
                                    >
                                        <span class="inline-flex h-7 min-w-[2.75rem] items-center justify-center rounded-lg bg-mist px-2 text-xs font-bold text-ink">{{ $cur['code'] }}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold text-ink">{{ $cur['label'] }}</span>
                                            <span class="block truncate text-[11px] text-ink-soft/60">{{ $cur['code'] }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            <div id="currency-empty" class="hidden px-3 py-6 text-center text-sm text-ink-soft/60">Sin resultados</div>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-ink-soft/55">Precios de vitrina y carrito usan esta moneda.</p>
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-sm font-medium text-ink-soft">Idiomas compatibles</label>
                        <div class="flex gap-2">
                            <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="locales-select-all">Todos</button>
                            <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="locales-clear">Solo default</button>
                        </div>
                    </div>
                    <div class="max-h-56 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                        @foreach($locales as $loc)
                            @php $iso = (string) ($loc['iso'] ?? ''); @endphp
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-mist/70">
                                <input type="checkbox" name="locales[]" value="{{ $loc['locale'] }}" class="locale-check rounded border-line text-teal"
                                       @checked(in_array($loc['locale'], $checkedLocales, true))
                                       data-locale="{{ $loc['locale'] }}">
                                @if($iso !== '')
                                    <span class="market-flag fi fi-{{ $iso }}"></span>
                                @endif
                                <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ $loc['label'] ?? $loc['name'] }}</span>
                                <span class="text-[10px] text-ink-soft/50">{{ $loc['locale'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-sm font-medium text-ink-soft">Monedas compatibles</label>
                        <div class="flex gap-2">
                            <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="currencies-select-all">Todas</button>
                            <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="currencies-clear">Solo default</button>
                        </div>
                    </div>
                    <div class="max-h-56 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                        @foreach($currencies as $cur)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-mist/70">
                                <input type="checkbox" name="currencies[]" value="{{ $cur['code'] }}" class="currency-check rounded border-line text-teal"
                                       @checked(in_array($cur['code'], $checkedCurrencies, true))
                                       data-code="{{ $cur['code'] }}">
                                <span class="inline-flex min-w-[2.5rem] justify-center rounded bg-mist px-1.5 text-[10px] font-bold text-ink">{{ $cur['code'] }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-ink">{{ $cur['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-ink-soft/55">Las monedas que esta mini-tienda puede mostrar / convertir.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL de la tienda</label>
                    <input type="url" name="public_url" value="{{ old('public_url', $public_url) }}"
                           class="admin-input" placeholder="https://tienda.tudominio.com o https://tudominio.com/ruta">
                    <p class="mt-1 text-xs text-ink-soft/55">
                        Si la dejas vacía se usa la URL interna:
                        <a href="{{ $fallback_public_url }}" target="_blank" rel="noopener" class="text-teal underline">{{ $fallback_public_url }}</a>
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Path prefix (opcional)</label>
                    <input type="text" name="path_prefix" value="{{ old('path_prefix', $path_prefix) }}"
                           class="admin-input" placeholder="/mi-tienda">
                    <p class="mt-1 text-xs text-ink-soft/55">Útil si la tienda vive en una ruta del dominio (se sincroniza al dominio primario).</p>
                </div>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <label class="block text-sm font-medium text-ink-soft">Países donde se muestra</label>
                        <p class="text-xs text-ink-soft/55">Marca los mercados objetivo. Si no eliges ninguno, se asume el mercado de la tienda ({{ $store->market?->code ?? '—' }}).</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="countries-select-all">Todos</button>
                        <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="countries-clear">Ninguno</button>
                        @if($store->market?->code)
                            <button type="button" class="admin-btn-secondary !py-1 !px-2.5 text-xs" id="countries-market-only" data-code="{{ strtoupper($store->market->code) }}">Solo {{ strtoupper($store->market->code) }}</button>
                        @endif
                    </div>
                </div>

                <div class="space-y-4 max-h-[28rem] overflow-y-auto rounded-xl border border-line p-3">
                    @foreach($marketsByRegion as $region => $regionMarkets)
                        <div>
                            <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">
                                {{ $regionMarkets->first()?->regionLabel() ?? $region }}
                            </div>
                            <div class="grid gap-1.5 sm:grid-cols-2">
                                @foreach($regionMarkets as $market)
                                    @php
                                        $code = strtoupper((string) $market->code);
                                        $checked = in_array($code, $selectedCountries, true);
                                        $iso = strtolower($code === 'UK' ? 'gb' : $code);
                                    @endphp
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-line/70 bg-white px-2.5 py-2 hover:border-teal/40">
                                        <input type="checkbox" name="countries[]" value="{{ $code }}" class="country-check rounded border-line text-teal" @checked($checked)>
                                        @if(strlen($iso) === 2)
                                            <span class="market-flag fi fi-{{ $iso }}" title="{{ $code }}"></span>
                                        @else
                                            <span>{{ $market->flagOrFallback() }}</span>
                                        @endif
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-medium text-ink">{{ $market->name }}</span>
                                            <span class="block text-[10px] text-ink-soft/50">{{ $code }} · {{ $market->locale }} · {{ $market->currency }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-5" id="sec-seo-shipping">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Envío, catálogo y SEO</h2>
                    <p class="mt-1 text-sm text-ink-soft/65">Ganancia sobre el flete de CJ, paginación del catálogo y metadatos de esta tienda.</p>
                </div>
                <button type="button" id="js-ai-seo-btn"
                    class="admin-btn-secondary flex items-center gap-1.5 !px-3 !py-2 text-xs"
                    data-url="{{ route('admin.store.general.ai-seo') }}"
                    title="Generar SEO y tagline con MIIA (IA)">
                    <i class="fa-solid fa-wand-magic-sparkles text-teal"></i>
                    Generar con IA
                </button>
            </div>

            <div id="js-ai-seo-status" class="hidden rounded-xl border border-teal/25 bg-teal/5 px-3 py-2 text-xs text-teal"></div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Ganancia de envío (%)</label>
                <input type="number" min="0" max="100" step="0.5" name="shipping_markup_percent"
                       value="{{ old('shipping_markup_percent', $shipping_markup_percent ?? 10) }}" class="admin-input">
                <p class="mt-1 text-xs text-ink-soft/55">Se suma al costo de envío que cotiza CJ (default 10%).</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Productos por página (catálogo)</label>
                <select name="catalog_per_page" class="admin-input">
                    @foreach([8, 12, 16, 24, 36, 48] as $n)
                        <option value="{{ $n }}" @selected((int) old('catalog_per_page', $catalog_per_page ?? 12) === $n)>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">SEO · título</label>
                <input id="seo_title" name="seo_title" value="{{ old('seo_title', $seo_title ?? '') }}" class="admin-input" maxlength="70" placeholder="{{ $store->name }}">
                <p class="mt-1 text-xs text-ink-soft/55"><span id="seo_title_count">{{ mb_strlen(old('seo_title', $seo_title ?? '')) }}</span>/70 caracteres</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">SEO · descripción</label>
                <textarea id="seo_description" name="seo_description" rows="3" class="admin-input" maxlength="180">{{ old('seo_description', $seo_description ?? '') }}</textarea>
                <p class="mt-1 text-xs text-ink-soft/55"><span id="seo_desc_count">{{ mb_strlen(old('seo_description', $seo_description ?? '')) }}</span>/180 caracteres</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">SEO · imagen OG (URL)</label>
                <input name="seo_og_image" value="{{ old('seo_og_image', $seo_og_image ?? '') }}" class="admin-input" placeholder="https://…">
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-5">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Servicios y plugins</h2>
                <p class="mt-1 text-sm text-ink-soft/65">El motor es de plataforma. En plugins elige PC y/o móvil por tipo. Si las dos casillas están apagadas, se oculta del menú y de la vitrina.</p>
            </div>

            <div class="space-y-2">
                <p class="text-sm font-medium text-ink-soft">Servicios</p>
                @foreach($platform_services as $key => $meta)
                    <label class="flex items-start gap-3 rounded-2xl border border-line bg-mist/40 p-4 cursor-pointer">
                        <input type="checkbox" name="services[{{ $key }}]" value="1"
                               @checked(old('services.'.$key, $service_flags[$key] ?? ($meta['default'] ?? true)))
                               class="mt-1 rounded border-line text-teal">
                        <span>
                            <span class="block text-sm font-semibold text-ink">{{ $meta['label'] ?? $key }}</span>
                            <span class="mt-0.5 block text-sm text-ink-soft/65">{{ $meta['desc'] ?? '' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="space-y-2">
                <p class="text-sm font-medium text-ink-soft">Plugins de conversión</p>
                <p class="text-xs text-ink-soft/60">Dos casillas por plugin: se muestra en PC y/o en móvil. Si las dos están apagadas, el plugin queda desactivado (también se oculta del menú).</p>
                @foreach($platform_plugins as $key => $meta)
                    @php
                        $dev = $plugin_devices[$key] ?? null;
                        $fallbackOn = (bool) ($plugin_flags[$key] ?? ($meta['default'] ?? true));
                        $onDesktop = old('plugin_devices.'.$key.'.desktop', is_array($dev) ? ($dev['desktop'] ?? true) : $fallbackOn);
                        $onMobile = old('plugin_devices.'.$key.'.mobile', is_array($dev) ? ($dev['mobile'] ?? true) : $fallbackOn);
                    @endphp
                    <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-line bg-white p-4 hover:border-teal/40">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-ink">{{ $meta['icon'] ?? '' }} {{ $meta['label'] ?? $key }}</span>
                            <span class="mt-0.5 block text-sm text-ink-soft/65">{{ $meta['desc'] ?? '' }}</span>
                        </span>
                        <div class="flex items-center gap-4 shrink-0">
                            <label class="inline-flex items-center gap-1.5 text-sm text-ink cursor-pointer">
                                <input type="hidden" name="plugin_devices[{{ $key }}][desktop]" value="0">
                                <input type="checkbox" name="plugin_devices[{{ $key }}][desktop]" value="1"
                                       @checked($onDesktop)
                                       class="rounded border-line text-teal">
                                PC
                            </label>
                            <label class="inline-flex items-center gap-1.5 text-sm text-ink cursor-pointer">
                                <input type="hidden" name="plugin_devices[{{ $key }}][mobile]" value="0">
                                <input type="checkbox" name="plugin_devices[{{ $key }}][mobile]" value="1"
                                       @checked($onMobile)
                                       class="rounded border-line text-teal">
                                Móvil
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-5">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Pagos de esta tienda</h2>
                <p class="mt-1 text-sm text-ink-soft/65">Habilita cobros y elige la pasarela. No se editan API keys aquí.</p>
            </div>

            <label class="flex items-start gap-3 rounded-2xl border border-line bg-mist/40 p-4 cursor-pointer">
                <input type="checkbox" name="payments_enabled" value="1" id="payments_enabled"
                       @checked(old('payments_enabled', $payments_enabled))
                       class="mt-1 rounded border-line text-teal">
                <span>
                    <span class="block text-sm font-semibold text-ink">Habilitar pagos en esta tienda</span>
                    <span class="mt-0.5 block text-sm text-ink-soft/65">Sin esto, el checkout no ofrecerá cobro online.</span>
                </span>
            </label>

            <div id="gateway-block" class="space-y-3 {{ old('payments_enabled', $payments_enabled) ? '' : 'opacity-50' }}">
                <p class="text-sm font-medium text-ink-soft">Tipo de pasarela</p>
                <div class="space-y-2">
                    @foreach($labels as $code => $label)
                        @php $ok = $available[$code] ?? false; @endphp
                        <label class="flex items-center gap-3 rounded-xl border border-line px-4 py-3 {{ $ok ? 'bg-white cursor-pointer hover:border-teal/40' : 'bg-mist/30 opacity-60 cursor-not-allowed' }}">
                            <input type="radio" name="payment_gateway" value="{{ $code }}"
                                   @checked(old('payment_gateway', $payment_gateway) === $code)
                                   @disabled(! $ok)
                                   class="border-line text-teal">
                            <span class="flex-1">
                                <span class="block text-sm font-semibold text-ink">{{ $label }}</span>
                                <span class="block text-xs text-ink-soft/55">
                                    {{ $ok ? 'API lista en plataforma' : 'Sin API en General de plataforma' }}
                                </span>
                            </span>
                            @if($ok)
                                <span class="admin-badge bg-teal/10 text-teal">OK</span>
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        </div>

        <button class="admin-btn">Guardar</button>
    </form>
@endsection

@push('scripts')
<script>
(function ($) {
  function syncStoreTypeUi() {
    var type = String($('input[name="store_type"]:checked').val() || 'mini');
    var $wrap = $('#store-parent-wrap');
    var $sel = $('#store-parent-id');
    if (type === 'mini') {
      $wrap.removeClass('hidden');
      $sel.prop('required', true);
    } else {
      $wrap.addClass('hidden');
      $sel.prop('required', false);
    }
  }
  $('input[name="store_type"]').on('change', syncStoreTypeUi);
  syncStoreTypeUi();

  function identityInk(hex) {
    var h = String(hex || '').replace('#', '');
    if (h.length !== 6) return '#ffffff';
    var r = parseInt(h.slice(0, 2), 16);
    var g = parseInt(h.slice(2, 4), 16);
    var b = parseInt(h.slice(4, 6), 16);
    var luma = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luma > 0.62 ? '#0f172a' : '#ffffff';
  }
  function syncIdentityPreview() {
    var color = String($('#identity-color').val() || '#0F766E').toUpperCase();
    if (!/^#[0-9A-F]{6}$/.test(color)) color = '#0F766E';
    var icon = String($('#identity-icon').val() || '').trim();
    var fallback = @json($store->identityIcon());
    $('#identity-preview').css({ background: color, color: identityInk(color) }).text(icon || fallback);
    $('#identity-color-picker').val(color);
  }
  $('#identity-color-picker').on('input change', function () {
    var v = String($(this).val() || '').toUpperCase();
    $('#identity-color').val(v);
    syncIdentityPreview();
  });
  $('#identity-color, #identity-icon').on('input', syncIdentityPreview);
  $('#identity-color-presets').on('click', 'button[data-color]', function () {
    var c = String($(this).data('color') || '').toUpperCase();
    $('#identity-color').val(c);
    syncIdentityPreview();
  });
  $('#identity-icon-presets').on('click', 'button[data-icon]', function () {
    $('#identity-icon').val(String($(this).data('icon') || ''));
    syncIdentityPreview();
  });
  syncIdentityPreview();

  function syncPayments() {
    var on = $('#payments_enabled').is(':checked');
    $('#gateway-block').toggleClass('opacity-50', !on);
    $('#gateway-block input[type=radio]').each(function () {
      var unavailable = $(this).closest('label').hasClass('cursor-not-allowed');
      $(this).prop('disabled', !on || unavailable);
    });
  }
  $('#payments_enabled').on('change', syncPayments);
  syncPayments();

  $('#countries-select-all').on('click', function () {
    $('.country-check').prop('checked', true);
  });
  $('#countries-clear').on('click', function () {
    $('.country-check').prop('checked', false);
  });
  $('#countries-market-only').on('click', function () {
    var code = String($(this).data('code') || '').toUpperCase();
    $('.country-check').each(function () {
      $(this).prop('checked', String($(this).val()).toUpperCase() === code);
    });
  });

  var $menu = $('#locale-menu');
  var $toggle = $('#locale-toggle');
  var $search = $('#locale-search');
  var $empty = $('#locale-empty');

  function closeLocaleMenu() {
    $menu.addClass('hidden');
  }

  function openLocaleMenu() {
    $menu.removeClass('hidden');
    $search.val('').trigger('input').focus();
  }

  function setFlagEl($el, iso) {
    $el.removeClass(function (i, cls) {
      return (cls.match(/(^|\s)fi-\S+/g) || []).join(' ');
    });
    if (iso) {
      $el.addClass('fi-' + iso).attr('data-iso', iso);
    } else {
      $el.removeAttr('data-iso');
    }
  }

  $toggle.on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($menu.hasClass('hidden')) openLocaleMenu();
    else closeLocaleMenu();
  });

  $menu.on('click', function (e) { e.stopPropagation(); });
  $(document).on('click', closeLocaleMenu);

  $menu.on('click', '.locale-option', function () {
    var $o = $(this);
    var iso = String($o.data('iso') || '');
    var locale = String($o.data('locale') || '');
    var name = String($o.data('name') || locale);
    var suggestedCurrency = String($o.data('currency') || '');
    $('#default-locale-input').val(locale);
    setFlagEl($('#locale-flag'), iso);
    $('#locale-label').text(name);
    $('#locale-meta').text(locale);
    $('.locale-option').removeClass('bg-teal/10 ring-1 ring-teal/20');
    $o.addClass('bg-teal/10 ring-1 ring-teal/20');
    $('.locale-check[data-locale="' + locale + '"]').prop('checked', true);
    if (suggestedCurrency) {
      var $curOpt = $('.currency-option[data-code="' + suggestedCurrency + '"]');
      if ($curOpt.length) {
        $('#default-currency-input').val(suggestedCurrency);
        $('#currency-code-badge').text(suggestedCurrency);
        $('#currency-label').text($curOpt.data('name'));
        $('#currency-meta').text(suggestedCurrency);
        $('.currency-option').removeClass('bg-teal/10 ring-1 ring-teal/20');
        $curOpt.addClass('bg-teal/10 ring-1 ring-teal/20');
        $('.currency-check[data-code="' + suggestedCurrency + '"]').prop('checked', true);
      }
    }
    closeLocaleMenu();
  });

  $search.on('input', function () {
    var q = $.trim($(this).val()).toLowerCase();
    var visible = 0;
    $('.locale-option').each(function () {
      var $o = $(this);
      var match = !q || String($o.data('search')).indexOf(q) !== -1;
      $o.toggleClass('hidden', !match);
      if (match) visible++;
    });
    $empty.toggleClass('hidden', visible > 0);
  });

  var $cMenu = $('#currency-menu');
  var $cToggle = $('#currency-toggle');
  var $cSearch = $('#currency-search');
  var $cEmpty = $('#currency-empty');

  function closeCurrencyMenu() { $cMenu.addClass('hidden'); }
  function openCurrencyMenu() {
    $cMenu.removeClass('hidden');
    $cSearch.val('').trigger('input').focus();
  }

  $cToggle.on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($cMenu.hasClass('hidden')) openCurrencyMenu();
    else closeCurrencyMenu();
  });
  $cMenu.on('click', function (e) { e.stopPropagation(); });
  $(document).on('click', closeCurrencyMenu);

  $cMenu.on('click', '.currency-option', function () {
    var $o = $(this);
    var code = String($o.data('code') || '');
    var name = String($o.data('name') || code);
    $('#default-currency-input').val(code);
    $('#currency-code-badge').text(code);
    $('#currency-label').text(name);
    $('#currency-meta').text(code);
    $('.currency-option').removeClass('bg-teal/10 ring-1 ring-teal/20');
    $o.addClass('bg-teal/10 ring-1 ring-teal/20');
    $('.currency-check[data-code="' + code + '"]').prop('checked', true);
    closeCurrencyMenu();
  });

  $cSearch.on('input', function () {
    var q = $.trim($(this).val()).toLowerCase();
    var visible = 0;
    $('.currency-option').each(function () {
      var $o = $(this);
      var match = !q || String($o.data('search')).indexOf(q) !== -1;
      $o.toggleClass('hidden', !match);
      if (match) visible++;
    });
    $cEmpty.toggleClass('hidden', visible > 0);
  });

  $('#locales-select-all').on('click', function () {
    $('.locale-check').prop('checked', true);
  });
  $('#locales-clear').on('click', function () {
    var def = String($('#default-locale-input').val() || '');
    $('.locale-check').each(function () {
      $(this).prop('checked', String($(this).data('locale')) === def);
    });
  });
  $('#currencies-select-all').on('click', function () {
    $('.currency-check').prop('checked', true);
  });
  $('#currencies-clear').on('click', function () {
    var def = String($('#default-currency-input').val() || '');
    $('.currency-check').each(function () {
      $(this).prop('checked', String($(this).data('code')) === def);
    });
  });

  $('#store-general-form').on('submit', function () {
    var defLoc = String($('#default-locale-input').val() || '');
    var defCur = String($('#default-currency-input').val() || '');
    if (defLoc) $('.locale-check[data-locale="' + defLoc + '"]').prop('checked', true);
    if (defCur) $('.currency-check[data-code="' + defCur + '"]').prop('checked', true);
  });

  // Contadores de caracteres SEO
  $('#seo_title').on('input', function () { $('#seo_title_count').text($(this).val().length); });
  $('#seo_description').on('input', function () { $('#seo_desc_count').text($(this).val().length); });

  // Botón IA MIIA — generar SEO y tagline
  $('#js-ai-seo-btn').on('click', function () {
    var $btn = $(this);
    var url = $btn.data('url');
    var $status = $('#js-ai-seo-status');

    $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin text-teal"></i> Generando…');
    $status.removeClass('hidden').text('Consultando MIIA…');

    $.ajax({
      url: url,
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
      success: function (data) {
        if (data.success) {
          if (data.seo_title) {
            $('#seo_title').val(data.seo_title).trigger('input');
          }
          if (data.seo_description) {
            $('#seo_description').val(data.seo_description).trigger('input');
          }
          var hints = [];
          if (data.tagline) hints.push('Tagline: ' + data.tagline);
          if (data.about) hints.push('About: ' + data.about);
          $status.removeClass('hidden border-teal/25 bg-teal/5 text-teal')
            .addClass('border-teal/25 bg-teal/5 text-teal')
            .html('✓ Generado con MIIA. ' + (hints.length ? '<br><span class="opacity-80">' + hints.join(' · ') + '</span>' : ''));
          $status.removeClass('hidden');
          document.getElementById('sec-seo-shipping').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          $status.removeClass('hidden border-teal/25 bg-teal/5 text-teal')
            .addClass('border-rose-200 bg-rose-50 text-rose-700')
            .text('Error: ' + (data.error || 'Inténtalo de nuevo.'));
          $status.removeClass('hidden');
        }
      },
      error: function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Error de conexión.';
        $status.removeClass('hidden border-teal/25 bg-teal/5 text-teal')
          .addClass('border-rose-200 bg-rose-50 text-rose-700')
          .text('Error: ' + msg);
        $status.removeClass('hidden');
      },
      complete: function () {
        $btn.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles text-teal"></i> Generar con IA');
      }
    });
  });
})(jQuery);
</script>
@endpush
