@extends('layouts.admin')

@section('title', ($product->exists ? 'Editar' : 'Nuevo').' producto')
@section('heading', $product->exists ? 'Editar producto' : 'Nuevo producto')
@section('subheading', $store->name)

@section('content')
@php
    $verified = is_array($product->verified_data) ? $product->verified_data : [];
    $creative = is_array($product->creative_data) ? $product->creative_data : [];
    $translations = is_array($creative['translations'] ?? null) ? $creative['translations'] : [];
    $defaultLocale = old('default_locale', $creative['default_locale'] ?? ($store->defaultLocale() ?? 'es_MX'));
    $cjImages = array_values(array_filter($verified['images'] ?? []));
    $editableImages = old('verified_images');
    if (! is_array($editableImages)) {
        $editableImages = $cjImages;
    } else {
        $editableImages = array_values(array_filter($editableImages, fn ($u) => is_string($u) && trim($u) !== ''));
    }
    $editableVideos = old('verified_videos');
    if (! is_array($editableVideos)) {
        $editableVideos = array_values(array_filter(
            is_array($verified['videos'] ?? null) ? $verified['videos'] : [],
            fn ($v) => is_array($v) && trim((string) ($v['url'] ?? '')) !== ''
        ));
    }
    $cjVideos = $cj_videos ?? [];
    if ($cjVideos === []) {
        foreach (array_values(array_filter($verified['videos'] ?? [], fn ($v) => is_array($v))) as $v) {
            if (empty($v['url'])) {
                continue;
            }
            if ($product->exists && $product->isFromCj()) {
                $v['play_url'] = route('admin.lab.cj.video-proxy', ['u' => $v['url']]);
            } else {
                $v['play_url'] = (string) $v['url'];
            }
            $cjVideos[] = $v;
        }
    }
    $cjVariants = $product->exists ? $product->variants : collect();
    $isCj = $product->exists && $product->isFromCj();
    $isAe = $product->exists && $product->isFromAliExpress();
    $cjReviews = $product->exists ? $product->reviews() : [];
    $cjComments = $product->exists ? $product->comments() : [];
    $cjRatingAvg = $product->exists ? $product->ratingAvg() : null;
    $cjReviewCount = $product->exists ? $product->reviewCount() : 0;
    $cjCommentCount = $product->exists ? $product->commentCount() : 0;
    $cjDetails = $product->exists ? $product->details() : [];
    $editableReviews = old('verified_reviews', $cjReviews);
    if (! is_array($editableReviews)) {
        $editableReviews = [];
    }
    $editableDetails = old('verified_details', $cjDetails);
    if (! is_array($editableDetails)) {
        $editableDetails = [];
    }
    $editableRating = old('verified_rating_avg', $cjRatingAvg ?? $verified['rating_avg'] ?? $verified['rating'] ?? '');
    $editableReviewCount = old('verified_review_count', $cjReviewCount);
    $sourceOriginLabel = $isCj ? 'CJ Dropshipping' : ($isAe ? 'AliExpress' : 'Manual');
    $sourceOriginUrl = $isAe
        ? (string) ($verified['aliexpress_url'] ?? '')
        : ($isCj ? (string) ($verified['cj_url'] ?? '') : '');
    if ($isAe) {
        $aePid = (string) ($verified['aliexpress_product_id'] ?? '');
        $badOrigin = $sourceOriginUrl === ''
            || ! preg_match('#^https?://(?:[a-z0-9-]+\.)*aliexpress\.#i', $sourceOriginUrl)
            || ! preg_match('#/(?:item|i)/\d{10,20}#i', $sourceOriginUrl);
        if ($badOrigin && $aePid !== '' && preg_match('/^\d{10,20}$/', $aePid)) {
            $sourceOriginUrl = 'https://www.aliexpress.com/item/'.$aePid.'.html';
        }
    }
    $sourceCaptureLabel = match ((string) ($verified['source_mode'] ?? '')) {
        'html' => 'HTML pegado (Product Hunter)',
        'plugin' => 'Plugin / captura del navegador',
        'cloudflare' => 'Cloudflare Browser Rendering',
        'scrape' => 'Scrape HTML directo',
        default => trim((string) ($verified['source_note'] ?? '')),
    };
    $locales = $locales ?? [];
    $hasMiia = $has_miia ?? false;

    // Sembrar locale default con datos principales si aún no hay traducción
    if (! isset($translations[$defaultLocale]) || ! is_array($translations[$defaultLocale])) {
        $translations[$defaultLocale] = [];
    }
    if (trim((string) ($translations[$defaultLocale]['name'] ?? '')) === '' && $product->name) {
        $translations[$defaultLocale]['name'] = $product->name;
    }
    if (trim((string) ($translations[$defaultLocale]['description'] ?? '')) === '' && $product->description) {
        $translations[$defaultLocale]['description'] = $product->description;
    }
    $descHtml = app(\App\Services\Storefront\ProductDescriptionHtml::class);
    foreach ($translations as $locKey => $row) {
        if (! is_array($row)) {
            continue;
        }
        if (! empty($row['description'])) {
            $translations[$locKey]['description'] = $descHtml->normalizeSpaces((string) $row['description']);
        }
    }
    if (! empty($product->description)) {
        $product->setAttribute('description', $descHtml->normalizeSpaces((string) $product->description));
    }
    if (trim((string) ($translations[$defaultLocale]['badge'] ?? '')) === '' && $product->badge) {
        $translations[$defaultLocale]['badge'] = $product->badge;
    }

    $activeLocale = old('_active_locale', $defaultLocale);
    $activeMeta = collect($locales)->firstWhere('locale', $activeLocale) ?: ($locales[0] ?? null);
    if (! $activeMeta && $locales) {
        $activeMeta = $locales[0];
        $activeLocale = $activeMeta['locale'];
    }
    $activeT = $translations[$activeLocale] ?? [];
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.store.products.index') }}" class="admin-btn-secondary">← Catálogo</a>
    @if($isCj && !empty($verified['cj_url']))
        <a href="{{ $verified['cj_url'] }}" target="_blank" rel="noopener" class="admin-btn-secondary">Ver en CJ ↗</a>
    @endif
    @if($isCj)
        <button type="button" id="btn-sync-cj" class="admin-btn-secondary">↻ Sincronizar desde CJ</button>
    @endif

    {{-- Idioma activo + traducir + % --}}
    <div class="ml-auto flex flex-wrap items-center gap-2">
        <div class="relative" id="locale-picker">
            <button type="button" id="locale-picker-btn"
                    class="admin-btn-secondary !px-3 !py-1.5 inline-flex items-center gap-2 min-w-[12rem]">
                <span id="locale-picker-flag" class="market-flag fi {{ !empty($activeMeta['iso']) ? 'fi-'.$activeMeta['iso'] : '' }}"></span>
                <span id="locale-picker-label" class="text-left text-sm font-medium text-ink truncate max-w-[9rem]">
                    {{ $activeMeta['name'] ?? $activeLocale }}
                </span>
                <span class="text-ink-soft/50 text-xs">▾</span>
            </button>
            <div id="locale-picker-menu"
                 class="absolute right-0 z-30 mt-1 hidden max-h-72 w-64 overflow-y-auto rounded-xl border border-line bg-white p-1 shadow-lg">
                @foreach($locales as $loc)
                    <button type="button"
                            class="locale-option flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left hover:bg-mist/70"
                            data-locale="{{ $loc['locale'] }}"
                            data-name="{{ $loc['name'] }}"
                            data-iso="{{ $loc['iso'] }}">
                        <span class="market-flag fi fi-{{ $loc['iso'] }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink">{{ $loc['name'] }}</span>
                            <span class="block text-[10px] text-ink-soft/50">{{ $loc['locale'] }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
            <input type="hidden" id="active-locale" value="{{ $activeLocale }}">
        </div>

        <div id="translation-pct-wrap"
             class="inline-flex items-center gap-2 rounded-xl border border-line bg-mist/40 px-3 py-1.5"
             title="Completitud de nombre, badge y descripción en este idioma">
            <div class="h-2 w-16 overflow-hidden rounded-full bg-line/70">
                <div id="translation-pct-bar" class="h-full rounded-full bg-teal transition-all" style="width: 0%"></div>
            </div>
            <span id="translation-pct-label" class="text-xs font-semibold text-ink tabular-nums">0%</span>
            <span id="translation-pct-detail" class="text-[10px] text-ink-soft/55">0/3</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <label class="inline-flex items-center gap-1.5 text-xs text-ink-soft">
                <input type="checkbox" id="translate-convert-currency" class="rounded border-line text-teal" checked
                       @disabled(! $hasMiia || ! $product->exists)>
                Convertir precio
            </label>
            <select id="translate-currency" class="admin-input !w-auto !py-1.5 text-xs min-w-[7.5rem]"
                    @disabled(! $hasMiia || ! $product->exists)
                    title="Moneda destino al traducir">
                <option value="">Moneda (auto)</option>
                @foreach(($currencies ?? []) as $row)
                    <option value="{{ $row['code'] }}">{{ $row['code'] }}</option>
                @endforeach
            </select>
            <button type="button" id="btn-translate-miia" class="admin-btn !px-3 !py-1.5 text-sm"
                    @disabled(! $hasMiia || ! $product->exists)
                    title="Traducir nombre, badge y descripción; opcionalmente convertir moneda">
                ✨ Traducir en este idioma
            </button>
        </div>
    </div>
</div>

@if(! $hasMiia)
    <div class="mb-4 rounded-xl border border-amber/30 bg-amber/10 px-4 py-3 text-sm text-amber">
        Configura la key MIIA en <a href="{{ route('admin.settings.general') }}" class="underline font-semibold">General</a> para traducir idiomas.
    </div>
@endif

<form method="post"
      action="{{ $product->exists ? route('admin.store.products.update', $product) : route('admin.store.products.store') }}"
      class="space-y-5"
      id="product-form">
    @csrf
    @if($product->exists) @method('PUT') @endif
    <input type="hidden" name="default_locale" id="default-locale-input" value="{{ $defaultLocale }}">

    {{-- Hidden stores for all locales (same form fields, swapped by JS) --}}
    <div id="i18n-hidden" class="hidden">
        @foreach($locales as $loc)
            @php
                $t = $translations[$loc['locale']] ?? [];
                $tName = old('translations.'.$loc['locale'].'.name', $t['name'] ?? '');
                $tDesc = old('translations.'.$loc['locale'].'.description', $t['description'] ?? '');
                $tBadge = old('translations.'.$loc['locale'].'.badge', $t['badge'] ?? '');
            @endphp
            <input type="hidden" name="translations[{{ $loc['locale'] }}][name]" value="{{ $tName }}" data-store-name="{{ $loc['locale'] }}">
            <input type="hidden" name="translations[{{ $loc['locale'] }}][badge]" value="{{ $tBadge }}" data-store-badge="{{ $loc['locale'] }}">
            <textarea name="translations[{{ $loc['locale'] }}][description]" data-store-desc="{{ $loc['locale'] }}">{{ $tDesc }}</textarea>
        @endforeach
    </div>

    <div class="admin-blocks">
    <div class="admin-card p-5 sm:p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Contenido del producto</h2>
                <p class="text-sm text-ink-soft/65">
                    Mismo formulario para cada idioma. Editando:
                    <strong id="content-locale-name" class="text-ink">{{ $activeMeta['name'] ?? $activeLocale }}</strong>
                    <span id="content-locale-code" class="text-ink-soft/50">({{ $activeLocale }})</span>
                </p>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-ink-soft">
                <input type="checkbox" id="set-as-default-locale" class="rounded border-line text-teal" @checked($activeLocale === $defaultLocale)>
                Usar este idioma como principal
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                <div class="flex gap-2 items-stretch">
                    <input id="field-name" value="{{ old('name', $activeT['name'] ?? $product->name) }}" class="admin-input flex-1 min-w-0" autocomplete="off">
                    <button type="button" id="btn-compress-name" class="admin-btn-secondary shrink-0 !px-2.5 min-w-[2.4rem]"
                            title="{{ $hasMiia ? 'Acortar nombre con IA' : 'Configura MIIA en General para acortar con IA' }}"
                            data-title-idle="{{ $hasMiia ? 'Acortar nombre con IA' : 'Configura MIIA en General para acortar con IA' }}"
                            @disabled(! $hasMiia)>
                        <span id="btn-compress-name-icon" aria-hidden="true">✨</span>
                        <span class="sr-only" id="btn-compress-name-label">Acortar nombre con IA</span>
                    </button>
                </div>
                <input type="hidden" name="name" id="main-name" value="{{ old('name', $product->name) }}" required>
            </div>
            <div class="sm:col-span-2 sm:max-w-xs">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Badge</label>
                <input id="field-badge" value="{{ $activeT['badge'] ?? $product->badge }}" class="admin-input" autocomplete="off">
                <input type="hidden" name="badge" id="main-badge" value="{{ old('badge', $product->badge) }}">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Descripción</label>
                <textarea id="field-description" rows="6" class="admin-input">{{ $activeT['description'] ?? $product->description }}</textarea>
                <textarea name="description" id="main-description" class="hidden">{{ old('description', $product->description) }}</textarea>
            </div>
        </div>
        <p id="translate-status" class="hidden text-xs text-ink-soft/60"></p>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink">Datos de catálogo</h2>
        <p class="text-sm text-ink-soft/65">Comunes a todos los idiomas (precio, stock, URL, etc.).</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Slug</label>
                <input name="slug" value="{{ old('slug', $product->slug) }}" class="admin-input">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">SKU</label>
                <input name="sku" value="{{ old('sku', $product->sku) }}" class="admin-input">
            </div>
            <div class="sm:col-span-2">
                @php
                    $verifiedPricing = is_array($verified['pricing'] ?? null) ? $verified['pricing'] : [];
                    $marketPurchase = $product->exists ? $product->marketplacePurchasePrice() : null;
                    $hasPricing = $product->exists && (
                        data_get($verified, 'cost_usd') !== null
                        || data_get($verifiedPricing, 'cost_usd') !== null
                        || ($isAe && data_get($verified, 'price') !== null)
                    );
                    $fxSvc = app(\App\Services\Currency\CurrencyService::class);
                    $priceCurrency = strtoupper((string) old('currency', $product->currency ?? 'MXN'));
                    $costUsd = (float) (data_get($verifiedPricing, 'cost_usd') ?? data_get($verified, 'cost_usd') ?? 0);
                    if ($costUsd <= 0 && $isAe && data_get($verified, 'price')) {
                        $aeSrcCur = strtoupper((string) (data_get($verified, 'currency') ?: 'USD'));
                        $aePrice = (float) data_get($verified, 'price');
                        $costUsd = (float) $fxSvc->convert($aePrice, $aeSrcCur, 'USD', false);
                    }
                    $shipUsd = (float) (data_get($verifiedPricing, 'ship_usd') ?? data_get($verified, 'ship_usd') ?? 0);
                    $feesPct = (float) (data_get($verifiedPricing, 'fees_pct') ?? 0.045);
                    $targetMargin = (float) (data_get($verifiedPricing, 'target_margin_pct') ?? 0.42);
                    $landedUsd = $costUsd;
                    $sellUsdSuggest = (float) (data_get($verifiedPricing, 'sell_usd') ?? data_get($verified, 'sell_usd') ?? 0);
                    $profitUsdSuggest = (float) (data_get($verifiedPricing, 'profit_usd') ?? 0);
                    $marginSuggest = data_get($verifiedPricing, 'margin_pct');
                    $cjSuggestUsd = data_get($verified, 'suggest_sell_price_usd');
                    $suggestedLocal = $sellUsdSuggest > 0
                        ? round($fxSvc->convert($sellUsdSuggest, 'USD', $priceCurrency, true), 2)
                        : null;
                    $costLocal = $costUsd > 0 ? round($fxSvc->convert($costUsd, 'USD', $priceCurrency, false), 2) : null;
                    if ($costLocal === null && $marketPurchase !== null) {
                        $costLocal = (float) $marketPurchase;
                    }
                    $inputPurchase = old('purchase_price', $product->purchase_price ?? $marketPurchase);
                    if ($inputPurchase !== null && $inputPurchase !== '') {
                        $inputPurchase = number_format((float) $inputPurchase, 2, '.', '');
                    }
                    $shipLocal = $shipUsd > 0 ? round($fxSvc->convert($shipUsd, 'USD', $priceCurrency, false), 2) : null;
                    $landedLocal = $landedUsd > 0 ? round($fxSvc->convert($landedUsd, 'USD', $priceCurrency, false), 2) : null;
                    $purchaseForCalc = ($inputPurchase !== null && $inputPurchase !== '' && (float) $inputPurchase > 0)
                        ? (float) $inputPurchase
                        : $costLocal;
                    $inputPrice = old('price', $product->price);
                    // Si no hay precio de vitrina (o coincide con compra), usar sugerido en el input
                    $priceLooksLikeCost = $purchaseForCalc !== null && $purchaseForCalc > 0
                        && ($inputPrice === null || $inputPrice === '' || (float) $inputPrice <= (float) $purchaseForCalc);
                    if (($inputPrice === null || $inputPrice === '' || (float) $inputPrice <= 0 || $priceLooksLikeCost) && $suggestedLocal) {
                        $inputPrice = $suggestedLocal;
                    }
                    if ($inputPrice !== null && $inputPrice !== '') {
                        $inputPrice = number_format((float) $inputPrice, 2, '.', '');
                    }
                    $pairMoney = function ($local, $usd, $cur) {
                        $main = $local !== null ? number_format((float) $local, 2).' '.$cur : '—';
                        if (strtoupper((string) $cur) === 'USD') {
                            return $main;
                        }

                        return $main.' <span class="text-ink-soft/45">('.number_format((float) $usd, 2).' USD)</span>';
                    };
                    $sellNow = (float) ($inputPrice ?: 0);
                    $feesLocal = $sellNow > 0 ? $sellNow * $feesPct : null;
                    $feesUsdNow = $feesLocal !== null ? (float) $fxSvc->convert($feesLocal, $priceCurrency, 'USD', false) : 0;
                    $profitLocalNow = ($sellNow > 0 && $purchaseForCalc !== null && $feesLocal !== null)
                        ? $sellNow - $purchaseForCalc - $feesLocal
                        : null;
                    $profitUsdNow = $profitLocalNow !== null ? (float) $fxSvc->convert($profitLocalNow, $priceCurrency, 'USD', false) : 0;
                    $cjSuggestLocal = $cjSuggestUsd ? (float) $fxSvc->convert((float) $cjSuggestUsd, 'USD', $priceCurrency, false) : null;
                @endphp
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Precio de compra</label>
                <div class="mb-4">
                    <div class="relative max-w-xs">
                        <input type="number" step="0.01" min="0" name="purchase_price" id="product-purchase-price"
                               value="{{ $inputPurchase }}" class="admin-input pr-16"
                               data-marketplace="{{ $marketPurchase !== null ? number_format((float) $marketPurchase, 2, '.', '') : '' }}"
                               placeholder="{{ $marketPurchase !== null ? number_format((float) $marketPurchase, 2, '.', '') : '' }}">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-medium text-ink-soft/55 purchase-currency-suffix">{{ $priceCurrency }}</span>
                    </div>
                    @if($marketPurchase !== null)
                        <p class="mt-1.5 text-xs text-ink-soft/55">
                            Marketplace: {{ number_format((float) $marketPurchase, 2) }} {{ $priceCurrency }}
                        </p>
                    @endif
                </div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Precio de venta</label>
                <div class="grid gap-3 lg:grid-cols-2">
                    <div>
                        <div class="relative">
                            <input type="number" step="0.01" min="0" name="price" id="product-price" value="{{ $inputPrice }}" required class="admin-input pr-16"
                                   data-suggested="{{ $suggestedLocal !== null ? number_format((float) $suggestedLocal, 2, '.', '') : '' }}">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-medium text-ink-soft/55" id="price-currency-suffix">{{ $priceCurrency }}</span>
                        </div>
                        @if($hasPricing && $suggestedLocal)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" id="use-suggested-price" class="admin-btn-secondary !px-2.5 !py-1 text-xs">
                                    Usar sugerido ({{ number_format((float)$suggestedLocal, 2) }} {{ $priceCurrency }})
                                </button>
                                <span class="text-xs text-ink-soft/50">≈ {{ number_format($sellUsdSuggest, 2) }} USD</span>
                            </div>
                        @endif
                    </div>

                    @if($hasPricing)
                        <div id="price-breakdown"
                             class="rounded-2xl border border-line bg-mist/40 p-3 text-xs space-y-1.5"
                             data-cost-usd="{{ $costUsd }}"
                             data-ship-usd="{{ $shipUsd }}"
                             data-landed-usd="{{ $landedUsd }}"
                             data-fees-pct="{{ $feesPct }}"
                             data-target-margin="{{ $targetMargin }}"
                             data-sell-usd="{{ $sellUsdSuggest }}"
                             data-currency="{{ $priceCurrency }}">
                            <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Desglose de costos</div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Precio de compra</span>
                                <span class="font-medium text-ink" id="bd-cost">{!! $pairMoney($purchaseForCalc, $costUsd, $priceCurrency) !!}</span>
                            </div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Envío estimado</span>
                                <span class="font-medium text-ink" id="bd-ship">{!! $pairMoney($shipLocal, $shipUsd, $priceCurrency) !!} <span class="text-ink-soft/40 font-normal">se cobra aparte</span></span>
                            </div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Costo producto</span>
                                <span class="font-medium text-ink" id="bd-landed">{!! $pairMoney($purchaseForCalc, $landedUsd, $priceCurrency) !!}</span>
                            </div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Comisión / fees (~{{ number_format($feesPct * 100, 1) }}%)</span>
                                <span class="font-medium text-ink" id="bd-fees">{!! $feesLocal !== null ? $pairMoney($feesLocal, $feesUsdNow, $priceCurrency) : '—' !!}</span>
                            </div>
                            @if($cjSuggestUsd)
                                <div class="flex justify-between gap-2">
                                    <span class="text-ink-soft/65">Sugerido CJ</span>
                                    <span class="font-medium text-ink">{!! $pairMoney($cjSuggestLocal, $cjSuggestUsd, $priceCurrency) !!}</span>
                                </div>
                            @endif
                            <div class="my-1 border-t border-line/70"></div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Precio sugerido (margen ~{{ number_format($targetMargin * 100, 0) }}%)</span>
                                <span class="font-semibold text-teal" id="bd-suggested">
                                    {!! $suggestedLocal !== null ? $pairMoney($suggestedLocal, $sellUsdSuggest, $priceCurrency) : '—' !!}
                                </span>
                            </div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Ganancia estimada</span>
                                <span class="font-semibold text-ink" id="bd-profit">
                                    {!! $profitLocalNow !== null ? $pairMoney($profitLocalNow, $profitUsdNow, $priceCurrency) : '—' !!}
                                </span>
                            </div>
                            <div class="flex justify-between gap-2">
                                <span class="text-ink-soft/65">Margen</span>
                                <span class="font-semibold text-ink" id="bd-margin">
                                    {{ $marginSuggest !== null ? number_format((float)$marginSuggest, 1).'%' : '—' }}
                                </span>
                            </div>
                            <p class="pt-1 text-[10px] leading-relaxed text-ink-soft/45">
                                Fees y ganancia se recalculan al cambiar el precio o la moneda. El envío es estimado (no cotización CJ real).
                            </p>
                        </div>
                    @endif
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Precio compare</label>
                <input type="number" step="0.01" min="0" name="compare_at_price"
                       value="{{ old('compare_at_price', $product->compare_at_price) !== null && old('compare_at_price', $product->compare_at_price) !== '' ? number_format((float) old('compare_at_price', $product->compare_at_price), 2, '.', '') : '' }}"
                       class="admin-input">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Moneda</label>
                @php
                    $currencyList = $currencies ?? [];
                    if ($currencyList === []) {
                        $currencyList = [
                            ['code' => 'MXN', 'label' => 'Peso mexicano'],
                            ['code' => 'USD', 'label' => 'Dólar estadounidense'],
                            ['code' => 'EUR', 'label' => 'Euro'],
                        ];
                    }
                    $currentCurrency = old('currency', $product->currency ?? 'MXN');
                    $currentRow = collect($currencyList)->firstWhere('code', $currentCurrency);
                    $currentLabel = is_array($currentRow) ? ($currentRow['label'] ?? $currentCurrency) : $currentCurrency;
                @endphp
                <div id="currency-combobox" class="relative" data-prev="{{ $currentCurrency }}">
                    <input type="hidden" name="currency" id="product-currency" value="{{ $currentCurrency }}" required>
                    <button type="button" id="currency-trigger" class="admin-input flex w-full items-center justify-between gap-2 text-left" aria-haspopup="listbox" aria-expanded="false">
                        <span id="currency-trigger-label" class="truncate">{{ $currentCurrency }} — {{ $currentLabel }}</span>
                        <span class="shrink-0 text-ink-soft/50" aria-hidden="true">▾</span>
                    </button>
                    <div id="currency-dropdown" class="absolute z-30 mt-1 hidden w-full overflow-hidden rounded-xl border border-line bg-white shadow-lg">
                        <div class="border-b border-line p-2">
                            <input type="search" id="currency-search" class="admin-input !py-1.5 text-sm" placeholder="Buscar moneda (código o nombre)…" autocomplete="off">
                        </div>
                        <ul id="currency-options" class="max-h-56 overflow-y-auto py-1" role="listbox">
                            @foreach($currencyList as $row)
                                <li>
                                    <button
                                        type="button"
                                        class="currency-option flex w-full px-3 py-2 text-left text-sm hover:bg-mist/70 {{ $currentCurrency === $row['code'] ? 'bg-teal/10 text-teal font-medium' : 'text-ink' }}"
                                        data-code="{{ $row['code'] }}"
                                        data-label="{{ $row['label'] }}"
                                        role="option"
                                        aria-selected="{{ $currentCurrency === $row['code'] ? 'true' : 'false' }}"
                                    >
                                        <span class="font-mono font-semibold">{{ $row['code'] }}</span>
                                        <span class="ml-2 text-ink-soft/70">{{ $row['label'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <p id="currency-empty" class="hidden px-3 py-3 text-sm text-ink-soft/60">Sin resultados</p>
                    </div>
                </div>
                <p class="mt-1 text-xs text-ink-soft/55">Moneda principal. Al cambiar, se convierten precio y compare con las tasas de General (respetando .99 / entero / múltiplos).</p>
            </div>
            <div class="sm:col-span-2" id="prices-by-currency">
                @php
                    $roundingModes = \App\Services\Currency\CurrencyService::ROUNDING_MODES;
                    $fxSvc = $fxSvc ?? app(\App\Services\Currency\CurrencyService::class);
                    $savedPrices = old('prices', $product->currencyPrices());
                    if (! is_array($savedPrices)) {
                        $savedPrices = [];
                    }
                    $seedCodes = [];
                    foreach (array_filter([
                        ...array_keys($savedPrices),
                        'USD', 'MXN', 'EUR', 'GBP', 'CAD', 'AUD',
                    ]) as $code) {
                        $code = strtoupper((string) $code);
                        if (preg_match('/^[A-Z]{3}$/', $code)) {
                            $seedCodes[$code] = true;
                        }
                    }
                    unset($seedCodes[$currentCurrency]);
                @endphp
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink-soft">Precios por moneda</label>
                        <p class="text-xs text-ink-soft/55">
                            Cada moneda tiene su estrategia de redondeo. En Auto se aplica al convertir con el tipo de cambio
                            (el valor inicial sale de
                            <a href="{{ route('admin.settings.general') }}" class="text-teal underline">General</a>).
                            En Fijo se aplica al precio que escribas.
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <button type="button" id="fill-fx-prices" class="admin-btn-secondary !py-1 !px-2 text-xs">Fijar FX en vacíos</button>
                            <button type="button" id="suggest-ai-prices" class="admin-btn !py-1 !px-2 text-xs"
                                    title="{{ $hasMiia ? 'Calcula precio de venta desde compra + fees + margen y elige vitrina atractiva (p. ej. 499 MXN, no 512.99)' : 'Precio de vitrina por mercado. Configura MIIA en General para IA.' }}">
                                ✨ Sugerir precios IA
                            </button>
                        </div>
                        <p id="suggest-prices-status" class="hidden max-w-xs text-right text-[11px] text-ink-soft/60"></p>
                    </div>
                </div>
                <div class="mt-2">
                    <input type="search" id="currency-price-search" class="admin-input !py-1.5 text-sm" placeholder="Buscar moneda (MXN, euro, libra, dólar…)" autocomplete="off">
                </div>
                <div class="mt-2 overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-full text-xs">
                        <thead>
                        <tr class="border-b border-line bg-mist/40 text-left text-[10px] uppercase tracking-wide text-ink-soft/50">
                            <th class="px-3 py-2">Moneda</th>
                            <th class="px-3 py-2">Redondeo</th>
                            <th class="px-3 py-2">Precio</th>
                            <th class="px-3 py-2">Compare</th>
                            <th class="px-3 py-2">Fijar</th>
                            <th class="px-2 py-2 w-8"></th>
                        </tr>
                        </thead>
                        <tbody id="currency-price-rows">
                        @foreach(array_keys($seedCodes) as $code)
                            @php
                                $row = is_array($savedPrices[$code] ?? null) ? $savedPrices[$code] : [];
                                $locked = ! empty($row['locked']) || (isset($row['price']) && (float) $row['price'] > 0);
                                $pVal = isset($row['price']) && (float) $row['price'] > 0 ? number_format((float) $row['price'], 2, '.', '') : '';
                                $cVal = isset($row['compare_at_price']) && (float) $row['compare_at_price'] > 0 ? number_format((float) $row['compare_at_price'], 2, '.', '') : '';
                                $roundMode = (string) ($row['rounding'] ?? $fxSvc->roundingFor($code));
                                if (! isset($roundingModes[$roundMode])) {
                                    $roundMode = $fxSvc->roundingFor($code);
                                }
                                $meta = collect($currencyList)->firstWhere('code', $code);
                                $rowLabel = is_array($meta) ? ($meta['label'] ?? $code) : ($code);
                            @endphp
                            <tr class="border-b border-line/70 currency-price-row" data-code="{{ $code }}" data-search="{{ strtolower($code.' '.$rowLabel) }}">
                                <td class="px-3 py-2 min-w-[220px]">
                                    <select class="admin-input !py-1.5 text-xs js-ccy-select" aria-label="Moneda">
                                        @foreach($currencyList as $opt)
                                            <option value="{{ $opt['code'] }}" data-label="{{ $opt['label'] }}" data-rounding="{{ $roundingModes[$opt['rounding'] ?? 'none'] ?? ($opt['rounding'] ?? '') }}" @selected($opt['code'] === $code)>
                                                {{ $opt['code'] }} — {{ $opt['label'] }}
                                            </option>
                                        @endforeach
                                        @if(! collect($currencyList)->firstWhere('code', $code))
                                            <option value="{{ $code }}" selected>{{ $code }} — {{ $rowLabel }}</option>
                                        @endif
                                    </select>
                                </td>
                                <td class="px-3 py-2 min-w-[240px]">
                                    <select name="prices[{{ $code }}][rounding]" class="admin-input !py-1.5 text-xs js-ccy-rounding" aria-label="Redondeo">
                                        @foreach($roundingModes as $mode => $label)
                                            <option value="{{ $mode }}" @selected($mode === $roundMode)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0" name="prices[{{ $code }}][price]" value="{{ $pVal }}"
                                           class="admin-input !py-1.5 font-mono js-ccy-price" data-code="{{ $code }}" placeholder="FX">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" min="0" name="prices[{{ $code }}][compare_at_price]" value="{{ $cVal }}"
                                           class="admin-input !py-1.5 font-mono js-ccy-compare" data-code="{{ $code }}">
                                </td>
                                <td class="px-3 py-2">
                                    <label class="inline-flex items-center gap-1.5 text-ink-soft">
                                        <input type="hidden" name="prices[{{ $code }}][locked]" value="0">
                                        <input type="checkbox" name="prices[{{ $code }}][locked]" value="1" class="rounded border-line text-teal js-ccy-lock" @checked($locked)>
                                        <span class="js-ccy-lock-label">{{ $locked ? 'Fijo' : 'Auto' }}</span>
                                    </label>
                                </td>
                                <td class="px-2 py-2">
                                    <button type="button" class="js-ccy-remove text-ink-soft/40 hover:text-coral" title="Quitar">&times;</button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p id="currency-price-empty" class="hidden mt-2 text-xs text-ink-soft/55">Ninguna moneda coincide con la búsqueda.</p>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                    <div class="relative min-w-[240px] flex-1" id="add-price-currency-box">
                        <label class="mb-1 block text-[11px] text-ink-soft/60">Añadir moneda</label>
                        <button type="button" id="add-price-currency-trigger" class="admin-input flex w-full items-center justify-between gap-2 !py-1.5 text-left text-xs" aria-haspopup="listbox" aria-expanded="false">
                            <span id="add-price-currency-label" class="truncate text-ink-soft/60">Buscar y añadir…</span>
                            <span class="shrink-0 text-ink-soft/50">▾</span>
                        </button>
                        <div id="add-price-currency-dropdown" class="absolute z-30 mt-1 hidden w-full overflow-hidden rounded-xl border border-line bg-white shadow-lg">
                            <div class="border-b border-line p-2">
                                <input type="search" id="add-price-currency-search" class="admin-input !py-1.5 text-sm" placeholder="Buscar moneda…" autocomplete="off">
                            </div>
                            <ul id="add-price-currency-options" class="max-h-56 overflow-y-auto py-1" role="listbox">
                                @foreach($currencyList as $row)
                                    <li>
                                        <button type="button" class="add-ccy-option flex w-full px-3 py-2 text-left text-sm hover:bg-mist/70 text-ink"
                                                data-code="{{ $row['code'] }}" data-label="{{ $row['label'] ?? '' }}"
                                                data-rounding="{{ $roundingModes[$row['rounding'] ?? 'none'] ?? ($row['rounding'] ?? '') }}">
                                            <span class="font-mono font-semibold">{{ $row['code'] }}</span>
                                            <span class="ml-2 text-ink-soft/70">{{ $row['label'] ?? $row['code'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                            <p id="add-price-currency-empty" class="hidden px-3 py-3 text-sm text-ink-soft/60">Sin resultados</p>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Stock</label>
                <input type="number" name="stock" value="{{ old('stock', $product->stock) }}" class="admin-input">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Estado</label>
                <select name="status" class="admin-input">
                    @foreach(['draft','live','paused','archived'] as $st)
                        <option value="{{ $st }}" @selected(old('status', $product->status) === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Imagen URL principal</label>
                <input name="image_url" id="product-main-image-url" value="{{ old('image_url', $product->image_url) }}" class="admin-input">
                <div id="main-image-media-path" class="mt-2 flex flex-wrap items-center gap-2 {{ $product->image_url ? '' : 'hidden' }}">
                    <code class="js-main-image-path-label max-w-full truncate rounded bg-mist px-2 py-1 text-[11px] text-ink-soft/80">{{ $product->image_url }}</code>
                    <button type="button" class="js-copy-main-image-path admin-btn-secondary !py-0.5 !px-2 text-[11px]">Copiar ruta</button>
                    <button type="button" class="js-copy-main-image-url admin-btn-secondary !py-0.5 !px-2 text-[11px]">Copiar URL</button>
                    <a href="{{ route('admin.store.products.media.download', ['product' => $product, 'url' => $product->image_url]) }}" class="js-main-image-download admin-btn-secondary !py-0.5 !px-2 text-[11px]">Descargar</a>
                </div>
                @if($product->image_url)
                    <img src="{{ $product->image_url }}" alt="" class="mt-2 h-24 w-24 rounded-lg object-cover border border-line js-zoomable cursor-zoom-in hover:opacity-80 transition-opacity">
                @endif
            </div>
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
            <input type="hidden" name="is_featured" value="0">
            <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured)) class="rounded border-line text-teal">
            Destacado en catálogo
        </label>
        <label class="inline-flex items-start gap-2 text-sm text-ink-soft">
            <input type="hidden" name="is_star" value="0">
            <input type="checkbox" name="is_star" value="1" class="mt-0.5 rounded border-line text-teal"
                @checked(old('is_star', $product->exists && $store->isStarProduct($product)))>
            <span>
                <span class="font-medium text-ink">Producto estrella</span>
                <span class="block text-xs text-ink-soft/60">
                    Ancla de la mini-tienda: hero, urgencia, combos/upsell, cross-sell y prueba social giran alrededor de este producto.
                    @if($store->isMini())
                        En mini-tiendas suele ser el producto principal.
                    @endif
                </span>
            </span>
        </label>
    </div>

    @if($product->exists)
        <div class="admin-card p-5 sm:p-6 space-y-4 admin-card-span-2" id="product-similar-import-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Importar de producto similar</h2>
                    <p class="mt-1 text-sm text-ink-soft/65">
                        Enriquece este producto con imágenes, videos, reseñas o descripción de otra ficha
                        <strong>AliExpress</strong> o <strong>CJ Dropshipping</strong> (URL, PID o SKU).
                    </p>
                </div>
            </div>
            <div class="rounded-2xl border border-line bg-mist/30 p-4 space-y-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="similar-import-url">URL del producto similar</label>
                    <input type="url" id="similar-import-url" class="admin-input font-mono text-sm" placeholder="https://www.aliexpress.com/item/… o https://cjdropshipping.com/product/…">
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-ink-soft">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" class="js-similar-section rounded border-line text-teal" value="images" checked> Imágenes</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" class="js-similar-section rounded border-line text-teal" value="videos" checked> Videos</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" class="js-similar-section rounded border-line text-teal" value="reviews" checked> Reseñas</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" class="js-similar-section rounded border-line text-teal" value="description" checked> Descripción</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" class="js-similar-section rounded border-line text-teal" value="details"> Detalles</label>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                    <input type="checkbox" id="similar-import-replace" class="rounded border-line text-coral">
                    Reemplazar secciones marcadas (en lugar de añadir)
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" id="btn-similar-preview" class="admin-btn-secondary">Vista previa</button>
                    <button type="button" id="btn-similar-import" class="admin-btn">Importar al producto</button>
                </div>
                <p id="similar-import-status" class="hidden text-sm"></p>
                <div id="similar-import-preview" class="hidden rounded-xl border border-dashed border-line bg-white/70 p-3 text-sm text-ink-soft"></div>
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-5 admin-card-span-2" id="product-media-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Imágenes y videos</h2>
                    <p class="mt-1 text-sm text-ink-soft/65">
                        Galería de la ficha y material promocional.
                        @if($isCj)
                            Puedes reimportar desde CJ con «Sincronizar desde CJ» o editar aquí.
                        @elseif($isAe)
                            Importadas desde AliExpress; puedes subir archivos, quitar, reordenar o añadir URLs.
                        @else
                            Sube archivos o añade URLs de imágenes y videos para la vitrina.
                        @endif
                    </p>
                </div>
                <span class="admin-badge bg-mist text-ink-soft">{{ count($editableImages) }} img · {{ count($editableVideos) }} video(s)</span>
            </div>

            <input type="hidden" name="verified_images_present" value="1">
            <input type="hidden" name="verified_videos_present" value="1">

            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-display text-base font-bold text-ink">Galería de imágenes</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.store.products.media.download-zip', ['product' => $product, 'kind' => 'images']) }}" class="admin-btn-secondary !py-1 !px-2 text-xs" id="btn-download-all-images">Descargar ZIP</a>
                        <label for="product-image-upload" class="admin-btn-secondary !py-1 !px-2 text-xs cursor-pointer">Subir archivo</label>
                        <input type="file" id="product-image-upload" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="sr-only">
                        <button type="button" id="btn-add-image-url" class="admin-btn-secondary !py-1 !px-2 text-xs">+ Añadir URL</button>
                    </div>
                </div>
                <p class="mb-2 text-xs text-ink-soft/55">Arrastra con ⋮⋮ o usa el menú ⋯ de cada imagen (mover, copiar, descargar, quitar). Guarda el producto para aplicar.</p>
                <p id="product-image-upload-status" class="mb-2 hidden text-xs text-ink-soft/60"></p>
                <div id="product-images-grid" class="flex flex-wrap gap-3 min-h-[5rem] overflow-visible rounded-xl border border-dashed border-line bg-mist/20 p-3"></div>
                <p id="product-images-empty" class="mt-2 text-sm text-ink-soft/55 {{ count($editableImages) ? 'hidden' : '' }}">Sin imágenes en la galería. Sube un archivo, añade una URL o importa desde el marketplace.</p>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-xs font-medium text-ink-soft">Nueva imagen (URL)</label>
                        <input type="url" id="new-image-url" class="admin-input text-sm" placeholder="https://…">
                    </div>
                    <button type="button" id="btn-push-image-url" class="admin-btn-secondary !py-2 text-sm shrink-0">Añadir</button>
                </div>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-display text-base font-bold text-ink">Videos</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.store.products.media.download-zip', ['product' => $product, 'kind' => 'videos']) }}" class="admin-btn-secondary !py-1 !px-2 text-xs" id="btn-download-all-videos">Descargar ZIP</a>
                        <label for="product-video-upload" class="admin-btn-secondary !py-1 !px-2 text-xs cursor-pointer">Subir archivo</label>
                        <input type="file" id="product-video-upload" accept="video/mp4,video/webm,video/quicktime,video/x-m4v,.mp4,.webm,.mov,.m4v" class="sr-only">
                        <button type="button" id="btn-add-video-row" class="admin-btn-secondary !py-1 !px-2 text-xs">+ Añadir URL</button>
                    </div>
                </div>
                <p class="mb-2 text-xs text-ink-soft/55">Arrastra con ⋮⋮ o usa el menú ⋯ de cada video (mover, copiar, descargar, quitar). Guarda el producto para aplicar.</p>
                <p id="product-video-upload-status" class="mb-2 hidden text-xs text-ink-soft/60"></p>
                <div id="product-videos-list" class="grid gap-4 sm:grid-cols-2"></div>
                <p id="product-videos-empty" class="text-sm text-ink-soft/55 {{ count($editableVideos) ? 'hidden' : '' }}">Sin videos. Sube un archivo MP4/WebM o pega la URL del marketplace.</p>
                @if($isCj)
                    <p class="mt-2 text-xs text-ink-soft/50">Videos CJ se reproducen en admin vía proxy (Referer).</p>
                @endif
            </div>

            <div id="verified-images-hidden" class="hidden" aria-hidden="true"></div>
            <div id="verified-videos-hidden" class="hidden" aria-hidden="true"></div>
        </div>
    @endif

    @if($product->exists)
        <div class="admin-card p-5 sm:p-6 space-y-4 admin-card-span-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-ink">
                    @if($isAe)
                        Origen del producto
                    @elseif($isCj)
                        Fuente CJ Dropshipping
                    @else
                        Origen del producto
                    @endif
                </h2>
                @if($isCj)
                    <span class="admin-badge bg-teal/10 text-teal">PID {{ $verified['cj_pid'] ?? '—' }}</span>
                @elseif($isAe)
                    <span class="admin-badge bg-amber/10 text-amber">AliExpress · {{ $verified['aliexpress_product_id'] ?? '—' }}</span>
                @else
                    <span class="admin-badge bg-mist text-ink-soft">Sin proveedor vinculado</span>
                @endif
            </div>

            @if($isAe)
                <div class="grid gap-3 sm:grid-cols-2 text-sm">
                    <div>
                        <span class="text-ink-soft/55">Origen</span>
                        <div class="font-medium text-ink">{{ $sourceOriginLabel }}</div>
                        @if($sourceCaptureLabel !== '')
                            <p class="mt-0.5 text-xs text-ink-soft/55">{{ $sourceCaptureLabel }}</p>
                        @endif
                    </div>
                    <div class="sm:col-span-1">
                        <span class="text-ink-soft/55">URL de origen</span>
                        @if($sourceOriginUrl !== '')
                            <div class="mt-0.5 font-medium break-all">
                                <a class="text-teal underline" href="{{ $sourceOriginUrl }}" target="_blank" rel="noopener">{{ $sourceOriginUrl }}</a>
                            </div>
                        @else
                            <div class="font-medium text-ink-soft/50">—</div>
                        @endif
                    </div>
                </div>
            @elseif($isCj)
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                    <div><span class="text-ink-soft/55">SKU CJ</span><div class="font-medium text-ink">{{ $verified['product_sku'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Categoría</span><div class="font-medium text-ink">{{ $verified['category'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Tipo</span><div class="font-medium text-ink">{{ $verified['product_type'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Proveedor</span><div class="font-medium text-ink">{{ $verified['supplier_name'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Peso</span><div class="font-medium text-ink">{{ $verified['weight_g'] ?? '—' }} g</div></div>
                    <div><span class="text-ink-soft/55">Peso empaque</span><div class="font-medium text-ink">{{ $verified['packed_weight_g'] ?? '—' }} g</div></div>
                    <div><span class="text-ink-soft/55">Costo USD</span><div class="font-medium text-ink">{{ $verified['cost_usd'] ?? $verified['sell_price_usd'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Envío est. USD</span><div class="font-medium text-ink">{{ $verified['ship_usd'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Material</span><div class="font-medium text-ink">{{ $verified['material'] ?? '—' }}</div></div>
                    <div class="sm:col-span-2"><span class="text-ink-soft/55">Keywords</span><div class="font-medium text-ink">{{ $verified['product_key'] ?? '—' }}</div></div>
                    <div class="sm:col-span-2 lg:col-span-3"><span class="text-ink-soft/55">Props</span><div class="font-medium text-ink whitespace-pre-wrap">{{ $verified['product_props'] ?? '—' }}</div></div>
                    <div><span class="text-ink-soft/55">Sync</span><div class="font-medium text-ink text-xs">{{ $verified['synced_at'] ?? $verified['imported_at'] ?? '—' }}</div></div>
                </div>

                @if(!empty($verified['description_short']))
                    <div>
                        <div class="mb-1 text-sm font-medium text-ink-soft">Descripción corta (CJ)</div>
                        <p class="rounded-xl border border-line bg-mist/30 p-3 text-sm text-ink-soft">{{ $verified['description_short'] }}</p>
                    </div>
                @endif
                @if(!empty($verified['description_html']))
                    <div>
                        <div class="mb-1 text-sm font-medium text-ink-soft">Descripción larga (HTML CJ)</div>
                        <div class="rounded-xl border border-line bg-mist/30 p-3 text-sm text-ink-soft max-h-56 overflow-auto prose prose-sm max-w-none">{!! $verified['description_html'] !!}</div>
                    </div>
                @elseif(!empty($verified['description_en']) && empty($product->description))
                    <div>
                        <div class="mb-1 text-sm font-medium text-ink-soft">Descripción CJ (EN)</div>
                        <p class="rounded-xl border border-line bg-mist/30 p-3 text-sm text-ink-soft whitespace-pre-wrap max-h-48 overflow-auto">{{ $verified['description_en'] }}</p>
                    </div>
                @endif

                <div>
                    <p class="text-sm text-ink-soft/55">Las reseñas, el ranking y los detalles se editan en la sección <strong class="text-ink">Reseñas, ranking y detalles</strong> más abajo. Imágenes y videos en la sección superior.</p>
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-display text-base font-bold text-ink">
                            Comentarios
                            ({{ $cjCommentCount }})
                        </h3>
                        <span class="text-xs text-ink-soft/55">Texto y fotos de compradores (CJ)</span>
                    </div>
                    @if($cjComments)
                        <div class="space-y-3 max-h-[28rem] overflow-y-auto rounded-xl border border-line p-3">
                            @foreach($cjComments as $comment)
                                <article class="rounded-lg border border-line/70 bg-mist/20 p-3">
                                    <div class="mb-1 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="font-semibold text-ink">{{ $comment['author'] ?? 'Comprador' }}</span>
                                        @if(!empty($comment['country']))
                                            <span class="admin-badge bg-mist text-ink-soft">{{ $comment['country'] }}</span>
                                        @endif
                                        @if(!empty($comment['date']))
                                            <span class="text-ink-soft/50">{{ \Illuminate\Support\Str::limit((string) $comment['date'], 32, '') }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($comment['comment']))
                                        <p class="text-sm text-ink-soft whitespace-pre-wrap">{{ $comment['comment'] }}</p>
                                    @endif
                                    @if(!empty($comment['images']) && is_array($comment['images']))
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($comment['images'] as $cimg)
                                                <img src="{{ $cimg }}" alt="" class="h-14 w-14 rounded-md object-cover border border-line js-zoomable cursor-zoom-in hover:opacity-80 transition-opacity" loading="lazy">
                                            @endforeach
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-ink-soft/55">Sin comentarios con texto o fotos. Pulsa «Sincronizar desde CJ».</p>
                    @endif
                </div>

                <div>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-display text-base font-bold text-ink">Variantes ({{ $cjVariants->count() }})</h3>
                        <span class="text-xs text-ink-soft/55">Incluye imagen, SKU, precio USD, peso y stock CJ</span>
                    </div>
                    @if($cjVariants->isEmpty())
                        <p class="text-sm text-ink-soft/55">Sin variantes guardadas. Pulsa «Sincronizar desde CJ» para traerlas.</p>
                    @else
                        {{-- El form bulk se inyecta fuera del <form> principal vía JS (HTML no permite forms anidados) --}}

                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table id="variants-table" class="w-full min-w-[720px] text-left text-xs">
                                <thead class="bg-mist text-ink-soft/70">
                                    <tr>
                                        <th class="w-8 px-2 py-2">
                                            <input type="checkbox" id="var-check-all" class="rounded border-line text-teal focus:ring-teal/30" title="Seleccionar todas">
                                        </th>
                                        <th class="px-2 py-2 font-medium">Img</th>
                                        <th class="px-2 py-2 font-medium">Variante</th>
                                        <th class="px-2 py-2 font-medium">SKU</th>
                                        <th class="px-2 py-2 font-medium">VID</th>
                                        <th class="px-2 py-2 font-medium">USD</th>
                                        <th class="px-2 py-2 font-medium">Peso</th>
                                        <th class="px-2 py-2 font-medium">Stock</th>
                                        <th class="px-2 py-2 font-medium">Medidas</th>
                                        <th class="px-2 py-2 font-medium"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($cjVariants as $variant)
                                        @php $opt = is_array($variant->options) ? $variant->options : []; @endphp
                                        <tr class="border-t border-line/70 hover:bg-mist/20">
                                            <td class="px-2 py-1.5 align-middle">
                                                <input type="checkbox" class="var-row-check rounded border-line text-teal focus:ring-teal/30"
                                                       value="{{ $variant->id }}" title="Seleccionar">
                                            </td>
                                            <td class="px-2 py-1.5">
                                                @if(!empty($opt['image']))
                                                    <img src="{{ $opt['image'] }}" alt="" class="h-10 w-10 rounded-md object-cover border border-line cursor-zoom-in hover:opacity-80 transition-opacity" loading="lazy" title="Ver imagen">
                                                @else
                                                    <span class="text-ink-soft/40">—</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-1.5 font-medium text-ink">{{ $variant->name }}</td>
                                            <td class="px-2 py-1.5 text-ink-soft">{{ $variant->sku }}</td>
                                            <td class="px-2 py-1.5 text-[10px] text-ink-soft/60">{{ $opt['vid'] ?? '—' }}</td>
                                            <td class="px-2 py-1.5">{{ $variant->price !== null ? number_format((float)$variant->price, 2) : '—' }}</td>
                                            <td class="px-2 py-1.5">{{ $opt['weight_g'] ?? '—' }}</td>
                                            <td class="px-2 py-1.5">{{ $opt['stock'] ?? '—' }}</td>
                                            <td class="px-2 py-1.5 text-ink-soft/70">
                                                @if(!empty($opt['length']) || !empty($opt['width']) || !empty($opt['height']))
                                                    {{ $opt['length'] ?? '—' }}×{{ $opt['width'] ?? '—' }}×{{ $opt['height'] ?? '—' }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-2 py-1.5 text-right">
                                                <button type="button" class="text-coral hover:underline text-[11px] js-var-single-delete"
                                                        data-url="{{ route('admin.store.products.variants.destroy', [$product, $variant]) }}">Quitar</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-ink-soft/60">Producto creado manualmente. Importa desde <a class="text-teal underline" href="{{ route('admin.lab.cj') }}">Product Hunter</a> para vincular un proveedor.</p>
            @endif
        </div>
    @endif

    @if($product->exists)
        <div class="admin-card p-5 sm:p-6 space-y-5 admin-card-span-2">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Reseñas, ranking y detalles</h2>
                    <p class="mt-1 text-sm text-ink-soft/60">Edita la prueba social y las características que verá el comprador en la vitrina.</p>
                </div>
                @if(!empty($verified['reviews_synced_at']))
                    <span class="admin-badge bg-mist text-ink-soft text-[11px]">Sync {{ \Illuminate\Support\Carbon::parse($verified['reviews_synced_at'])->diffForHumans() }}</span>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <input type="hidden" name="verified_reviews_present" value="1">
                <input type="hidden" name="verified_details_present" value="1">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Rating promedio</label>
                    <input type="number" step="0.1" min="0" max="5" name="verified_rating_avg"
                           value="{{ $editableRating !== '' && $editableRating !== null ? number_format((float) $editableRating, 1, '.', '') : '' }}"
                           class="admin-input" placeholder="4.8">
                    <p class="mt-1 text-[11px] text-ink-soft/50">Escala 0–5. Se muestra en la ficha del producto.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Total reseñas</label>
                    <input type="number" step="1" min="0" name="verified_review_count"
                           value="{{ $editableReviewCount !== '' && $editableReviewCount !== null ? (int) $editableReviewCount : '' }}"
                           class="admin-input" placeholder="{{ count($editableReviews) }}">
                    <p class="mt-1 text-[11px] text-ink-soft/50">Número visible junto al rating (puede ser mayor que las filas guardadas).</p>
                </div>
            </div>

            <div>
                <div id="review-toolbar" class="mb-2 flex flex-wrap items-center justify-between gap-2 {{ count($editableReviews) ? '' : 'hidden' }}">
                    <h3 class="font-display text-base font-bold text-ink">Reseñas (<span id="verified-reviews-count">{{ count($editableReviews) }}</span>)</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="btn-delete-reviews" class="admin-btn-secondary !py-1 !px-2 text-xs text-coral" disabled>Eliminar seleccionadas</button>
                        <button type="button" id="btn-add-review" class="admin-btn-secondary !py-1 !px-2 text-xs">+ Añadir reseña</button>
                    </div>
                </div>
                <div id="review-select-bar" class="mb-2 flex items-center gap-2 text-xs text-ink-soft {{ count($editableReviews) ? '' : 'hidden' }}">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" id="review-check-all" class="rounded border-line text-teal">
                        <span>Seleccionar todas</span>
                    </label>
                </div>
                <div id="verified-reviews-list" class="max-h-[32rem] overflow-y-auto rounded-xl border border-line divide-y divide-line/70 {{ count($editableReviews) ? '' : 'hidden' }}"></div>
                <p id="verified-reviews-empty" class="text-sm text-ink-soft/55 {{ count($editableReviews) ? 'hidden' : '' }}">Sin reseñas. Importa desde un producto similar, Product Hunter o añade una manualmente.</p>
                <div id="verified-reviews-hidden" class="hidden" aria-hidden="true"></div>
            </div>

            <div id="review-edit-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-ink/50 p-4" role="dialog" aria-modal="true" aria-labelledby="review-modal-title">
                <div class="admin-card w-full max-w-lg max-h-[90vh] overflow-y-auto p-5 shadow-xl">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <h3 id="review-modal-title" class="font-display text-lg font-bold text-ink">Editar reseña</h3>
                        <button type="button" id="review-modal-close" class="text-ink-soft/50 hover:text-ink text-xl leading-none" aria-label="Cerrar">&times;</button>
                    </div>
                    <div class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">Autor</label>
                                <input type="text" id="rm-author" class="admin-input text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">País (ISO)</label>
                                <div class="flex items-center gap-2">
                                    <span id="rm-flag" class="market-flag hidden"></span>
                                    <input type="text" id="rm-country" maxlength="2" class="admin-input text-sm uppercase" placeholder="MX">
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">Estrellas (1–5)</label>
                                <input type="number" id="rm-score" min="1" max="5" step="1" class="admin-input text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">Fecha</label>
                                <input type="text" id="rm-date" class="admin-input text-sm" placeholder="06 AGO 2026">
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">Foto del usuario (URL)</label>
                                <input type="url" id="rm-avatar" class="admin-input text-sm" placeholder="https://…">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink-soft">SKU / variante</label>
                                <input type="text" id="rm-sku-info" class="admin-input text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft">Comentario</label>
                            <textarea id="rm-comment" rows="4" class="admin-input text-sm"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink-soft">Fotos de la reseña (una URL por línea)</label>
                            <textarea id="rm-images" rows="2" class="admin-input text-xs font-mono"></textarea>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" id="review-modal-cancel" class="admin-btn-secondary">Cancelar</button>
                        <button type="button" id="review-modal-save" class="admin-btn">Guardar reseña</button>
                    </div>
                </div>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-display text-base font-bold text-ink">Detalles / especificaciones ({{ count($editableDetails) }})</h3>
                    <button type="button" id="btn-add-detail" class="admin-btn-secondary !py-1 !px-2 text-xs">+ Añadir fila</button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-line">
                    <table class="min-w-full text-sm">
                        <thead>
                        <tr class="border-b border-line bg-mist/40 text-left text-[10px] uppercase tracking-wide text-ink-soft/50">
                            <th class="px-3 py-2 w-[38%]">Característica</th>
                            <th class="px-3 py-2">Valor</th>
                            <th class="px-2 py-2 w-16"></th>
                        </tr>
                        </thead>
                        <tbody id="verified-details-rows">
                        @forelse($editableDetails as $di => $detail)
                            <tr class="verified-detail-row border-t border-line/70">
                                <td class="px-3 py-2 align-top">
                                    <input type="text" name="verified_details[{{ $di }}][name]" value="{{ $detail['name'] ?? '' }}" class="admin-input !py-1.5 text-sm w-full">
                                </td>
                                <td class="px-3 py-2 align-top">
                                    <input type="text" name="verified_details[{{ $di }}][value]" value="{{ $detail['value'] ?? '' }}" class="admin-input !py-1.5 text-sm w-full">
                                </td>
                                <td class="px-2 py-2 align-top text-right">
                                    <button type="button" class="js-remove-detail text-xs text-coral hover:underline">Quitar</button>
                                </td>
                            </tr>
                        @empty
                            <tr id="verified-details-empty"><td colspan="3" class="px-3 py-4 text-sm text-ink-soft/55">Sin detalles. Importa desde AliExpress o añade filas manualmente.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
    </div>

    <div class="admin-form-actions flex flex-wrap gap-3">
        <button class="admin-btn" id="btn-save-product">{{ $product->exists ? 'Guardar' : 'Crear' }}</button>
        <a href="{{ route('admin.store.products.index') }}" class="admin-btn-secondary">Cancelar</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function ($) {
  var csrf = $('meta[name="csrf-token"]').attr('content');
  var syncUrl = @json($isCj ? route('admin.store.products.sync-cj', $product) : null);
  var translateUrl = @json($product->exists ? route('admin.store.products.translate', $product) : null);
  var compressNameUrl = @json(route('admin.store.products.compress-name'));
  var suggestPricesUrl = @json(route('admin.store.products.suggest-prices'));
  var defaultLocale = @json($defaultLocale);
  var activeLocale = String($('#active-locale').val() || defaultLocale);

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function storeGet(locale) {
    return {
      name: String($('[data-store-name="'+locale+'"]').val() || ''),
      badge: String($('[data-store-badge="'+locale+'"]').val() || ''),
      description: String($('[data-store-desc="'+locale+'"]').val() || '')
    };
  }

  function storeSet(locale, data) {
    $('[data-store-name="'+locale+'"]').val(data.name || '');
    $('[data-store-badge="'+locale+'"]').val(data.badge || '');
    $('[data-store-desc="'+locale+'"]').val(data.description || '');
  }

  function readVisible() {
    return {
      name: String($('#field-name').val() || ''),
      badge: String($('#field-badge').val() || ''),
      description: String($('#field-description').val() || '')
    };
  }

  function writeVisible(data) {
    $('#field-name').val(data.name || '');
    $('#field-badge').val(data.badge || '');
    $('#field-description').val(data.description || '');
  }

  function syncMainFromLocale(locale) {
    if (locale !== defaultLocale) return;
    var data = storeGet(locale);
    $('#main-name').val(data.name);
    $('#main-badge').val(data.badge);
    $('#main-description').val(data.description);
  }

  function persistActive() {
    storeSet(activeLocale, readVisible());
    syncMainFromLocale(activeLocale);
  }

  function translationPct(data) {
    var fields = ['name', 'badge', 'description'];
    var filled = 0;
    fields.forEach(function (k) {
      if (String(data[k] || '').trim() !== '') filled += 1;
    });
    var pct = Math.round((filled / fields.length) * 100);
    return { filled: filled, total: fields.length, pct: pct };
  }

  function updatePct() {
    var info = translationPct(readVisible());
    $('#translation-pct-bar').css('width', info.pct + '%');
    $('#translation-pct-label').text(info.pct + '%');
    $('#translation-pct-detail').text(info.filled + '/' + info.total);
    var $wrap = $('#translation-pct-wrap');
    $wrap.toggleClass('border-teal/40 bg-teal/5', info.pct === 100);
    $wrap.toggleClass('border-amber/40 bg-amber/5', info.pct > 0 && info.pct < 100);
  }

  function setPicker(locale, name, iso) {
    $('#active-locale').val(locale);
    $('#locale-picker-label').text(name || locale);
    $('#content-locale-name').text(name || locale);
    $('#content-locale-code').text('(' + locale + ')');
    var $flag = $('#locale-picker-flag');
    $flag.attr('class', 'market-flag fi' + (iso ? (' fi-' + iso) : ''));
    $('#set-as-default-locale').prop('checked', locale === defaultLocale);
  }

  function switchLocale(locale, name, iso) {
    if (!locale || locale === activeLocale) {
      $('#locale-picker-menu').addClass('hidden');
      return;
    }
    persistActive();
    activeLocale = locale;
    writeVisible(storeGet(locale));
    setPicker(locale, name, iso);
    updatePct();
    $('#locale-picker-menu').addClass('hidden');
    $(document).trigger('locale:changed', [locale]);
  }

  $('#locale-picker-btn').on('click', function (e) {
    e.stopPropagation();
    $('#locale-picker-menu').toggleClass('hidden');
  });
  $(document).on('click', function () {
    $('#locale-picker-menu').addClass('hidden');
  });
  $('#locale-picker-menu').on('click', function (e) { e.stopPropagation(); });

  $('.locale-option').on('click', function () {
    switchLocale(
      String($(this).data('locale') || ''),
      String($(this).data('name') || ''),
      String($(this).data('iso') || '')
    );
  });

  $('#field-name, #field-badge, #field-description').on('input change', function () {
    persistActive();
    updatePct();
  });

  function sanitizeProductName(raw) {
    var name = String(raw || '').trim();
    if (!name) return '';
    name = name.split('\n')[0].trim();
    name = name.replace(/^(?:t[ií]tulo|nombre|title)\s*:\s*/iu, '');
    name = name.replace(/[\s*_[\]]+[\(\[]\s*\d+\s*(?:car[aá]cter(?:es)?|chars?|characters?)\s*[\)\]]\s*$/iu, '');
    name = name.replace(/\s*[\(\[]\s*\d+\s*(?:car[aá]cter(?:es)?|chars?|characters?)\s*[\)\]]\s*$/iu, '');
    name = name.replace(/^\*+(.+?)\*+$/u, '$1');
    name = name.replace(/\*+([^*]+)\*+$/u, '$1');
    name = name.replace(/[*_`]/g, '');
    return name.replace(/\s+/g, ' ').trim();
  }

  $('#btn-compress-name').on('click', function () {
    if (!compressNameUrl) return;
    var name = sanitizeProductName($('#field-name').val());
    if (!name) {
      alert('Escribe un nombre primero.');
      return;
    }
    var $btn = $(this);
    var $icon = $('#btn-compress-name-icon');
    var $label = $('#btn-compress-name-label');
    var idleTitle = String($btn.data('title-idle') || 'Acortar nombre con IA');
    $btn.prop('disabled', true).attr('aria-busy', 'true').attr('title', 'Acortando nombre…');
    $label.text('Acortando nombre…');
    $icon.html('<i class="fa-solid fa-spinner fa-spin text-teal" aria-hidden="true"></i>');
    $.ajax({
      url: compressNameUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 120000,
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: { _token: csrf, name: name }
    }).done(function (res) {
      if (res && res.success && res.name) {
        $('#field-name').val(sanitizeProductName(res.name)).trigger('input');
        if (window.AdminToast) AdminToast.success(res.message || 'Nombre acortado');
      } else {
        alert((res && res.error) || 'No se pudo acortar el nombre.');
      }
    }).fail(function (xhr) {
      var res = xhr.responseJSON || {};
      alert(res.error || 'Error al acortar el nombre.');
    }).always(function () {
      $btn.prop('disabled', false).attr('aria-busy', 'false').attr('title', idleTitle);
      $label.text('Acortar nombre con IA');
      $icon.text('✨');
    });
  });

  $('#set-as-default-locale').on('change', function () {
    if (!$(this).is(':checked')) {
      // no permitir quedar sin default: re-check
      $(this).prop('checked', true);
      return;
    }
    persistActive();
    defaultLocale = activeLocale;
    $('#default-locale-input').val(defaultLocale);
    // copiar al principal
    var data = storeGet(defaultLocale);
    $('#main-name').val(data.name);
    $('#main-badge').val(data.badge);
    $('#main-description').val(data.description);
  });

  $('#product-form').on('submit', function (e) {
    persistActive();
    syncMainFromLocale(defaultLocale);
    var main = storeGet(defaultLocale);
    if (!String(main.name || '').trim()) {
      main = readVisible();
      $('#main-name').val(main.name);
      $('#main-badge').val(main.badge);
      $('#main-description').val(main.description);
    }
    if (!String($('#main-name').val() || '').trim()) {
      e.preventDefault();
      alert('El nombre del idioma principal no puede estar vacío.');
      return false;
    }
  });

  $('#btn-sync-cj').on('click', function () {
    if (!syncUrl) return;
    var $btn = $(this);
    if (!confirm('¿Re-sincronizar todo el detalle desde CJ (variantes, imágenes, videos, reseñas, descripción)?')) return;
    $btn.prop('disabled', true).text('Sincronizando…');
    $.ajax({
      url: syncUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: { _token: csrf }
    }).done(function (res) {
      alert((res && res.message) || 'Listo');
      if (res && res.redirect) window.location.href = res.redirect;
      else window.location.reload();
    }).fail(function (xhr) {
      alert((xhr.responseJSON && xhr.responseJSON.error) || 'Error al sincronizar');
      $btn.prop('disabled', false).text('↻ Sincronizar desde CJ');
    });
  });

  var localeCurrencyMap = @json($locale_currency_map ?? []);

  function suggestTranslateCurrency(locale) {
    var suggested = localeCurrencyMap[locale] || '';
    var $sel = $('#translate-currency');
    if (!$sel.length) return;
    // Si está en "auto" o vacío, no fuerza; solo preselecciona sugerida
    if (!$sel.data('manual')) {
      $sel.val(suggested || '');
    }
  }

  $('#translate-currency').on('change', function () {
    $(this).data('manual', true);
  });

  // Cuando cambia el idioma activo, sugerir moneda
  $(document).on('locale:changed', function (e, locale) {
    suggestTranslateCurrency(locale || activeLocale);
  });
  suggestTranslateCurrency(activeLocale);

  $('#btn-translate-miia').on('click', function () {
    if (!translateUrl) {
      alert('Guarda el producto primero para poder traducir.');
      return;
    }
    var $btn = $(this);
    var $status = $('#translate-status');
    persistActive();
    var locale = activeLocale;
    var convert = $('#translate-convert-currency').is(':checked') ? 1 : 0;
    var cur = String($('#translate-currency').val() || '');
    if (convert && !cur) {
      cur = localeCurrencyMap[locale] || '';
    }
    $btn.prop('disabled', true).text('Traduciendo…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60')
      .text('MIIA está traduciendo a ' + locale + (convert && cur ? (' · convirtiendo a ' + cur) : '') + '…');

    $.ajax({
      url: translateUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: {
        _token: csrf,
        locale: locale,
        apply_to_main: locale === defaultLocale ? 1 : 0,
        convert_currency: convert,
        currency: cur || null
      }
    }).done(function (res) {
      if (!(res && res.success && res.translation)) {
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'Falló');
        return;
      }
      var t = res.translation;
      storeSet(locale, {
        name: t.name || '',
        badge: t.badge || '',
        description: t.description || ''
      });
      writeVisible(storeGet(locale));
      syncMainFromLocale(locale);
      updatePct();

      if (res.pricing && res.pricing.price != null) {
        var code = String(res.pricing.currency || '').toUpperCase();
        if (code && typeof upsertCurrencyPriceRow === 'function') {
          upsertCurrencyPriceRow(code, res.pricing.price, res.pricing.compare_at_price, true);
        }
      }

      $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal')
        .text((res.message || 'Traducción lista') + ' · ' + translationPct(readVisible()).pct + '%. Guarda el producto.');
      if (window.AdminToast) AdminToast.success(res.message || 'Traducción lista');
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Error MIIA';
      if (xhr.responseJSON && xhr.responseJSON.raw_preview) {
        err += '\n\nRespuesta: ' + xhr.responseJSON.raw_preview;
      }
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
      if (window.AdminToast) AdminToast.error(err);
    }).always(function () {
      $btn.prop('disabled', false).text('✨ Traducir en este idioma');
    });
  });

  // init
  persistActive();
  updatePct();

  // —— Conversión de moneda (precio + compare) ——
  var fx = @json($fx ?? ['base' => 'USD', 'rates' => ['USD' => 1], 'rounding' => []]);
  var rates = fx.rates || {};
  var rounding = fx.rounding || {};

  function money2(n) {
    var x = Number(n);
    if (!isFinite(x)) return 0;
    return Math.round((x + Number.EPSILON) * 100) / 100;
  }

  function money2str(n) {
    return money2(n).toFixed(2);
  }

  function applyRound(amount, mode) {
    amount = Number(amount);
    if (!isFinite(amount)) return 0;
    var neg = amount < 0;
    amount = Math.abs(amount);
    var out;
    switch (mode) {
      case 'cent_00': out = Math.round(amount); break;
      case 'cent_99': out = amount < 1 ? money2(amount) : Math.floor(amount) + 0.99; break;
      case 'cent_95': out = amount < 1 ? money2(amount) : Math.floor(amount) + 0.95; break;
      case 'cent_49': out = amount < 1 ? money2(amount) : Math.floor(amount) + 0.49; break;
      case 'nearest_5': out = Math.round(amount / 5) * 5; break;
      case 'nearest_10': out = Math.round(amount / 10) * 10; break;
      case 'psych':
        if (amount < 8) out = money2(amount);
        else if (amount < 25) out = Math.floor(amount) + 0.99;
        else if (amount < 80) out = Math.floor(amount / 5) * 5 + 4.99;
        else out = Math.floor(amount / 10) * 10 + 9.99;
        break;
      default: out = money2(amount);
    }
    out = money2(out);
    return neg ? -out : out;
  }

  function convertMoney(amount, from, to, doRound, mode) {
    from = String(from || 'USD').toUpperCase();
    to = String(to || 'USD').toUpperCase();
    amount = Number(amount);
    if (!isFinite(amount)) return 0;
    var roundMode = mode || rounding[to] || 'none';
    if (from === to) {
      return doRound === false ? money2(amount) : applyRound(amount, roundMode);
    }
    var fromRate = Number(rates[from] || 1);
    var toRate = Number(rates[to] || 1);
    if (fromRate <= 0) fromRate = 1;
    var inBase = amount / fromRate;
    var converted = inBase * toRate;
    return doRound === false ? money2(converted) : applyRound(converted, roundMode);
  }

  function moneyFmt(n, cur) {
    if (n == null || !isFinite(Number(n))) return '—';
    return money2str(n) + (cur ? (' ' + cur) : '');
  }

  function setMoneyInput($el, value) {
    if (!$el || !$el.length) return;
    if (value == null || value === '' || !isFinite(Number(value))) {
      $el.val('');
      return;
    }
    $el.val(money2str(value));
  }

  function clampMoneyInput($el) {
    var raw = String($el.val() || '').trim();
    if (raw === '') return;
    var n = Number(raw);
    if (!isFinite(n)) {
      $el.val('');
      return;
    }
    $el.val(money2str(n));
  }

  function refreshPriceBreakdown() {
    var $bd = $('#price-breakdown');
    if (! $bd.length) return;
    var cur = String($('#product-currency').val() || $bd.data('currency') || 'MXN').toUpperCase();
    var costUsd = Number($bd.data('cost-usd') || 0);
    var shipUsd = Number($bd.data('ship-usd') || 0);
    var landedUsd = Number($bd.data('landed-usd') || (costUsd + shipUsd));
    var feesPct = Number($bd.data('fees-pct') || 0.045);
    var sellUsdSuggest = Number($bd.data('sell-usd') || 0);
    var sellLocal = Number($('#product-price').val() || 0);
    var purchaseLocal = Number($('#product-purchase-price').val() || 0);

    function moneyPair(local, usd, cur) {
      var main = moneyFmt(local, cur);
      if (String(cur || '').toUpperCase() === 'USD') return main;
      return main + ' <span class="text-ink-soft/45">(' + moneyFmt(usd, 'USD') + ')</span>';
    }

    var costLocal = purchaseLocal > 0
      ? purchaseLocal
      : convertMoney(costUsd, 'USD', cur, false);
    var costUsdDisplay = purchaseLocal > 0
      ? convertMoney(purchaseLocal, cur, 'USD', false)
      : costUsd;
    var shipLocal = convertMoney(shipUsd, 'USD', cur, false);
    var productCostUsd = costUsdDisplay;
    var feesLocal = sellLocal > 0 ? sellLocal * feesPct : 0;
    var profitLocal = sellLocal > 0 ? (sellLocal - costLocal - feesLocal) : null;
    var margin = (sellLocal > 0 && profitLocal != null) ? (profitLocal / sellLocal) * 100 : null;
    var suggestedLocal = sellUsdSuggest > 0 ? convertMoney(sellUsdSuggest, 'USD', cur, true) : null;
    var feesUsd = sellLocal > 0 ? convertMoney(feesLocal, cur, 'USD', false) : 0;
    var profitUsd = profitLocal != null ? convertMoney(profitLocal, cur, 'USD', false) : 0;

    $('#bd-cost').html(moneyPair(costLocal, costUsdDisplay, cur));
    $('#bd-ship').html(moneyPair(shipLocal, shipUsd, cur) + ' <span class="text-ink-soft/40 font-normal">se cobra aparte</span>');
    $('#bd-landed').html(moneyPair(costLocal, productCostUsd, cur));
    $('#bd-fees').html(moneyPair(feesLocal, feesUsd, cur));
    $('#bd-suggested').html(suggestedLocal != null ? moneyPair(suggestedLocal, sellUsdSuggest, cur) : '—');
    var $profit = $('#bd-profit');
    $profit.html(profitLocal != null ? moneyPair(profitLocal, profitUsd, cur) : '—');
    $profit.toggleClass('text-teal', profitLocal != null && profitLocal > 0);
    $profit.toggleClass('text-coral', profitLocal != null && profitLocal < 0);
    $('#bd-margin').text(margin != null ? (margin.toFixed(1) + '%') : '—');
    $('#price-currency-suffix').text(cur);
    $('.purchase-currency-suffix').text(cur);
    $bd.attr('data-currency', cur);

    var $btn = $('#use-suggested-price');
    if ($btn.length && suggestedLocal != null) {
      $btn.text('Usar sugerido (' + moneyFmt(suggestedLocal, cur) + ')');
      $('#product-price').attr('data-suggested', money2str(suggestedLocal));
    }
  }

  $('#product-price, #product-purchase-price, input[name="compare_at_price"]').on('blur', function () {
    var cur = String($('#product-currency').val() || 'MXN').toUpperCase();
    var n = Number($(this).val());
    if (isFinite(n) && n > 0) {
      setMoneyInput($(this), applyRound(n, rounding[cur] || 'none'));
    } else {
    clampMoneyInput($(this));
    }
    refreshPriceBreakdown();
    refreshCurrencyPricePreviews();
  });
  $('#product-price, #product-purchase-price').on('input change', refreshPriceBreakdown);
  $('#use-suggested-price').on('click', function () {
    var suggested = money2($('#product-price').attr('data-suggested') || 0);
    if (suggested > 0) {
      setMoneyInput($('#product-price'), suggested);
      $('#product-price').trigger('change');
    }
  });

  $('#product-currency').on('change', function () {
    var $sel = $(this);
    var $box = $('#currency-combobox');
    var from = String($box.attr('data-prev') || $sel.val()).toUpperCase();
    var to = String($sel.val() || '').toUpperCase();
    if (!to || from === to) {
      $box.attr('data-prev', to);
      refreshPriceBreakdown();
      syncPriceRowsToBaseCurrency();
      return;
    }
    var $price = $('input[name="price"]');
    var $compare = $('input[name="compare_at_price"]');
    var price = Number($price.val());
    var compare = Number($compare.val());
    if (isFinite(price) && price > 0) {
      setMoneyInput($price, convertMoney(price, from, to));
    }
    if (isFinite(compare) && compare > 0) {
      setMoneyInput($compare, convertMoney(compare, from, to));
    }
    $('[data-variant-price], input[name*="[price]"]').each(function () {
      var $v = $(this);
      if (!$v.is('input')) return;
      var v = Number($v.val());
      if (isFinite(v) && v > 0) setMoneyInput($v, convertMoney(v, from, to));
    });
    $box.attr('data-prev', to);
    refreshPriceBreakdown();
    refreshCurrencyPricePreviews();
    syncPriceRowsToBaseCurrency();
  });

  refreshPriceBreakdown();
  clampMoneyInput($('#product-price'));
  clampMoneyInput($('#product-purchase-price'));
  clampMoneyInput($('input[name="compare_at_price"]'));

  var roundingLabels = @json(\App\Services\Currency\CurrencyService::ROUNDING_MODES);
  var storefrontCurrencies = @json($currencyList);

  function roundingSelectHtml(selected) {
    selected = String(selected || 'none');
    var html = '';
    Object.keys(roundingLabels).forEach(function (mode) {
      html += '<option value="' + mode + '"' + (mode === selected ? ' selected' : '') + '>' +
        String(roundingLabels[mode] || mode).replace(/</g, '&lt;') + '</option>';
    });
    return html;
  }

  function rowRounding($row) {
    var mode = String($row.find('.js-ccy-rounding').val() || '').trim();
    if (mode && roundingLabels[mode]) return mode;
    var code = String($row.attr('data-code') || '').toUpperCase();
    return rounding[code] || 'none';
  }

  function baseCurrency() {
    return String($('#product-currency').val() || 'MXN').toUpperCase();
  }

  function suggestCharmCompare(price, currency) {
    price = Number(price) || 0;
    currency = String(currency || 'USD').toUpperCase();
    if (price <= 0) return 0;
    var up = price * 1.32;
    if (currency === 'MXN') {
      var bucket = Math.ceil(up / 100) * 100;
      var opts = [bucket - 1, bucket + 49, bucket + 99, bucket + 199];
      for (var i = 0; i < opts.length; i++) {
        if (opts[i] > price) return opts[i];
      }
      return bucket + 199;
    }
    if (currency === 'JPY' || currency === 'KRW') {
      var mod = currency === 'KRW' ? 1000 : 100;
      var b = Math.ceil(up / mod) * mod;
      return b > price ? b - (currency === 'KRW' ? 100 : 20) : b + mod - 20;
    }
    if (currency === 'EUR' || currency === 'CHF' || currency === 'BRL') {
      return Math.floor(up) + 0.90;
    }
    return Math.floor(up) + 0.99;
  }

  function reindexVerifiedDetails() {
    $('#verified-details-rows .verified-detail-row').each(function (i) {
      $(this).find('[name^="verified_details["]').each(function () {
        var $el = $(this);
        var field = String($el.attr('name') || '').replace(/^verified_details\[\d+\]/, '');
        if (field) $el.attr('name', 'verified_details[' + i + ']' + field);
      });
    });
  }

  function reviewCountryIso(code) {
    var iso = String(code || '').trim().toLowerCase();
    if (iso === 'uk') iso = 'gb';
    return /^[a-z]{2}$/.test(iso) ? iso : '';
  }

  function reviewFlagHtml(code) {
    var iso = reviewCountryIso(code);
    if (!iso) return '';
    return '<span class="market-flag fi fi-' + iso + '" title="' + escapeHtml(String(code).toUpperCase()) + '"></span>';
  }

  function reviewStarsText(score) {
    score = parseInt(score, 10) || 0;
    if (score < 1) return '';
    return '★★★★★'.slice(0, score) + '☆☆☆☆☆'.slice(0, 5 - score);
  }

  function reviewImagesToText(images) {
    if (!images) return '';
    if (Array.isArray(images)) return images.filter(Boolean).join('\n');
    return String(images);
  }

  function reviewImagesFromText(text) {
    return String(text || '').split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
  }

  var verifiedReviewsData = @json(array_values($editableReviews));
  var reviewModalIdx = null;

  function syncModalReviewFlag() {
    var iso = reviewCountryIso($('#rm-country').val());
    var $flag = $('#rm-flag');
    if (iso) {
      $flag.removeClass('hidden').attr('class', 'market-flag fi fi-' + iso);
    } else {
      $flag.addClass('hidden').removeClass(function (i, c) {
        return (c.match(/\bfi-\S+/g) || []).join(' ');
      });
    }
  }

  function syncReviewsHiddenInputs() {
    var $hidden = $('#verified-reviews-hidden').empty();
    verifiedReviewsData.forEach(function (r, i) {
      var prefix = 'verified_reviews[' + i + ']';
      var fields = {
        author: r.author || '',
        country: r.country || '',
        score: r.score != null && r.score !== '' ? r.score : '',
        date: r.date || '',
        avatar: r.avatar || '',
        sku_info: r.sku_info || '',
        comment: r.comment || '',
        images: reviewImagesToText(r.images)
      };
      $.each(fields, function (key, val) {
        $('<input>', { type: 'hidden', name: prefix + '[' + key + ']', value: val }).appendTo($hidden);
      });
    });
  }

  function updateDeleteReviewsBtn() {
    var n = $('.js-review-check:checked').length;
    $('#btn-delete-reviews').prop('disabled', n === 0).text(n > 0 ? ('Eliminar (' + n + ')') : 'Eliminar seleccionadas');
    var total = $('.js-review-check').length;
    var checked = n === total && total > 0;
    $('#review-check-all').prop('checked', checked).prop('indeterminate', n > 0 && n < total);
  }

  function renderReviewsList() {
    var $list = $('#verified-reviews-list').empty();
    $('#verified-reviews-count').text(verifiedReviewsData.length);
    var has = verifiedReviewsData.length > 0;
    $('#verified-reviews-empty').toggleClass('hidden', has);
    $('#verified-reviews-list').toggleClass('hidden', !has);
    $('#review-toolbar').toggleClass('hidden', !has);
    $('#review-select-bar').toggleClass('hidden', !has);
    $('#review-check-all').prop('checked', false).prop('indeterminate', false);
    if (!has) {
      syncReviewsHiddenInputs();
      updateDeleteReviewsBtn();
      return;
    }
    verifiedReviewsData.forEach(function (r, i) {
      var score = parseInt(r.score, 10) || 0;
      var comment = String(r.comment || '').trim();
      var excerpt = comment;
      if (excerpt.length > 160) excerpt = excerpt.slice(0, 160) + '…';
      if (!excerpt && score > 0) excerpt = 'Solo calificación (sin comentario de texto)';
      var avatar = String(r.avatar || '').trim();
      var initial = (String(r.author || 'C').trim().charAt(0) || 'C').toUpperCase();
      var imgHtml = avatar
        ? '<img src="' + escapeHtml(avatar) + '" alt="" class="h-9 w-9 shrink-0 rounded-full border border-line object-cover bg-white" loading="lazy" referrerpolicy="no-referrer">'
        : '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line bg-mist text-[11px] font-semibold text-ink-soft">' + escapeHtml(initial) + '</div>';
        var $row = $(
        '<div class="verified-review-item flex items-start gap-3 px-3 py-2.5 hover:bg-mist/25 cursor-pointer" data-index="' + i + '" title="Clic para seleccionar">' +
          '<input type="checkbox" class="js-review-check mt-1.5 shrink-0 rounded border-line text-teal">' +
          imgHtml +
          '<div class="min-w-0 flex-1 js-review-body">' +
            '<div class="flex flex-wrap items-center gap-1.5 text-xs">' +
              '<strong class="text-ink">' + escapeHtml(r.author || 'Comprador') + '</strong>' +
              reviewFlagHtml(r.country) +
              (score > 0 ? '<span class="text-amber font-medium">' + reviewStarsText(score) + '</span>' : '') +
              (r.date ? '<span class="text-ink-soft/50">' + escapeHtml(r.date) + '</span>' : '') +
            '</div>' +
            (r.sku_info ? '<p class="mt-0.5 text-[10px] text-ink-soft/60">' + escapeHtml(r.sku_info) + '</p>' : '') +
            (excerpt ? '<p class="mt-1 text-sm text-ink-soft leading-snug whitespace-pre-wrap">' + escapeHtml(excerpt) + '</p>' : '') +
            ((r.images && r.images.length) ? '<p class="mt-1 text-[10px] text-ink-soft/50">' + r.images.length + ' foto(s)</p>' : '') +
          '</div>' +
          '<button type="button" class="js-edit-review admin-btn-secondary !py-1 !px-2.5 text-xs shrink-0">Editar</button>' +
        '</div>'
      );
      $list.append($row);
    });
    syncReviewsHiddenInputs();
    updateDeleteReviewsBtn();
  }

  function openReviewModal(idx) {
    reviewModalIdx = idx;
    var r = idx === null ? {} : (verifiedReviewsData[idx] || {});
    $('#review-modal-title').text(idx === null ? 'Nueva reseña' : 'Editar reseña');
    $('#rm-author').val(r.author || '');
    $('#rm-country').val(r.country || '');
    $('#rm-score').val(r.score != null && r.score !== '' ? r.score : '');
    $('#rm-date').val(r.date || '');
    $('#rm-avatar').val(r.avatar || '');
    $('#rm-sku-info').val(r.sku_info || '');
    $('#rm-comment').val(r.comment || '');
    $('#rm-images').val(reviewImagesToText(r.images));
    syncModalReviewFlag();
    $('#review-edit-modal').removeClass('hidden').addClass('flex');
    $('body').css('overflow', 'hidden');
  }

  function closeReviewModal() {
    $('#review-edit-modal').addClass('hidden').removeClass('flex');
    reviewModalIdx = null;
    if (!$('#cj-image-modal').hasClass('flex') && !$('#cj-crawl-modal').hasClass('flex')) {
      $('body').css('overflow', '');
    }
  }

  function saveReviewModal() {
    var r = {
      author: String($('#rm-author').val() || '').trim(),
      country: String($('#rm-country').val() || '').trim().toUpperCase(),
      score: $('#rm-score').val() !== '' ? parseInt($('#rm-score').val(), 10) : null,
      date: String($('#rm-date').val() || '').trim(),
      avatar: String($('#rm-avatar').val() || '').trim(),
      sku_info: String($('#rm-sku-info').val() || '').trim(),
      comment: String($('#rm-comment').val() || '').trim(),
      images: reviewImagesFromText($('#rm-images').val())
    };
    if (!r.author && !r.comment && !(r.score >= 1 && r.score <= 5)) {
      alert('Indica al menos autor, comentario o calificación.');
      return;
    }
    if (reviewModalIdx === null) {
      verifiedReviewsData.push(r);
    } else {
      verifiedReviewsData[reviewModalIdx] = r;
    }
    renderReviewsList();
    closeReviewModal();
  }

  $('#btn-add-review').on('click', function () { openReviewModal(null); });
  $(document).on('click', '.js-edit-review', function () {
    var idx = parseInt($(this).closest('.verified-review-item').attr('data-index'), 10);
    if (!isNaN(idx)) openReviewModal(idx);
  });
  $('#review-modal-save').on('click', saveReviewModal);
  $('#review-modal-cancel, #review-modal-close').on('click', closeReviewModal);
  $('#review-edit-modal').on('click', function (e) {
    if (e.target === this) closeReviewModal();
  });
  $('#rm-country').on('input', syncModalReviewFlag);
  $(document).on('keydown.reviewModal', function (e) {
    if (e.key === 'Escape' && $('#review-edit-modal').hasClass('flex')) closeReviewModal();
  });

  $('#review-check-all').on('change', function () {
    var on = $(this).is(':checked');
    $('.js-review-check').prop('checked', on).each(function () {
      $(this).closest('.verified-review-item').toggleClass('bg-teal/5 ring-1 ring-inset ring-teal/20', on);
    });
    updateDeleteReviewsBtn();
  });
  $(document).on('change', '.js-review-check', function () {
    $(this).closest('.verified-review-item').toggleClass('bg-teal/5 ring-1 ring-inset ring-teal/20', $(this).is(':checked'));
    updateDeleteReviewsBtn();
  });

  $(document).on('click', '.verified-review-item', function (e) {
    var $t = $(e.target);
    if ($t.closest('.js-edit-review').length) return;
    if ($t.is('a, button, input, textarea, select, label') || $t.closest('a, button, input, textarea, select, label').length) return;
    var $cb = $(this).find('.js-review-check');
    $cb.prop('checked', !$cb.prop('checked')).trigger('change');
  });

  $('#btn-delete-reviews').on('click', function () {
    var indexes = [];
    $('.js-review-check:checked').each(function () {
      var idx = parseInt($(this).closest('.verified-review-item').attr('data-index'), 10);
      if (!isNaN(idx)) indexes.push(idx);
    });
    if (!indexes.length) return;
    if (!confirm('¿Eliminar ' + indexes.length + ' reseña(s) seleccionada(s)?')) return;
    indexes.sort(function (a, b) { return b - a; });
    indexes.forEach(function (i) { verifiedReviewsData.splice(i, 1); });
    renderReviewsList();
  });

  $('#product-form').on('submit', function () {
    syncReviewsHiddenInputs();
    syncMediaHiddenInputs();
  });

  var verifiedImagesData = @json(array_values($editableImages));
  var verifiedVideosData = @json(array_values($editableVideos));
  var mediaVideoProxyUrl = @json(route('admin.lab.cj.video-proxy'));
  var mediaPublicPrefix = @json(\App\Services\Storage\MediaUrl::prefix());
  var productIsCj = @json($isCj);
  var productUploadImageUrl = @json($product->exists ? route('admin.store.products.upload-image', $product) : null);
  var productUploadVideoUrl = @json($product->exists ? route('admin.store.products.upload-video', $product) : null);
  var mediaDownloadUrl = @json($media_download_url ?? null);

  function mediaPaths(url) {
    url = String(url || '').trim();
    if (!url) {
      return { url: '', path: '', label: '' };
    }
    var path = '';
    var needle = '/' + mediaPublicPrefix + '/';
    var idx = url.indexOf(needle);
    if (idx >= 0) {
      path = url.slice(idx + needle.length);
    } else if (/\/storage\//i.test(url)) {
      var storageMatch = url.match(/\/storage\/(.+)$/i);
      if (storageMatch && storageMatch[1]) {
        path = storageMatch[1];
      }
    }
    return {
      url: url,
      path: path,
      label: path || url
    };
  }

  function copyMediaText(text, $btn) {
    text = String(text || '');
    if (!text) return;
    function done() {
      var orig = $btn.text();
      $btn.text('¡Copiado!');
      setTimeout(function () { $btn.text(orig); }, 1200);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        var $tmp = $('<textarea>').val(text).appendTo('body').select();
        document.execCommand('copy');
        $tmp.remove();
        done();
      });
      return;
    }
    var $tmp = $('<textarea>').val(text).appendTo('body').select();
    document.execCommand('copy');
    $tmp.remove();
    done();
  }

  function mediaDirectDownloadUrl(url) {
    url = String(url || '').trim();
    if (!url) return '';
    if (/\/f\//i.test(url)) {
      return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'download=1';
    }
    if (mediaDownloadUrl) {
      return mediaDownloadUrl + (mediaDownloadUrl.indexOf('?') >= 0 ? '&' : '?') + 'url=' + encodeURIComponent(url);
    }
    return url;
  }

  function mediaItemMenuHtml(opts) {
    opts = opts || {};
    var paths = opts.paths || {};
    var url = String(paths.url || '').trim();
    var dl = url ? mediaDirectDownloadUrl(url) : '';
    var lines = [];
    var placement = opts.placement || (opts.openDown ? 'below' : 'right');
    var panelMin = opts.panelMinWidth || (placement === 'below' ? '14.5rem' : '10.5rem');
    var itemClass = 'js-media-menu-item block w-full px-3 py-2 text-left text-xs text-ink hover:bg-mist whitespace-nowrap';

    if (opts.showMain) {
      lines.push('<button type="button" class="' + itemClass + ' js-img-main">★ Imagen principal</button>');
    }
    if (opts.showImageMove) {
      lines.push('<button type="button" class="' + itemClass + ' js-img-up">↑ Mover antes</button>');
      lines.push('<button type="button" class="' + itemClass + ' js-img-down">↓ Mover después</button>');
    }
    if (opts.showVideoMove) {
      lines.push('<button type="button" class="' + itemClass + ' js-vid-up">↑ Mover antes</button>');
      lines.push('<button type="button" class="' + itemClass + ' js-vid-down">↓ Mover después</button>');
    }
    if (url) {
      lines.push('<button type="button" class="' + itemClass + ' js-copy-media" data-copy="' + escapeHtml(paths.path || paths.url) + '">Copiar ruta</button>');
      lines.push('<button type="button" class="' + itemClass + ' js-copy-media" data-copy="' + escapeHtml(paths.url) + '">Copiar URL</button>');
      if (dl) {
        lines.push('<a class="' + itemClass + ' js-download-media" href="' + escapeHtml(dl) + '" download>Descargar</a>');
      }
    }
    if (opts.deleteClass) {
      lines.push('<button type="button" class="js-media-menu-item ' + opts.deleteClass + ' block w-full border-t border-line px-3 py-2 text-left text-xs text-coral hover:bg-coral/10 whitespace-nowrap">Quitar</button>');
    }
    if (!lines.length) return '';

    var mediaIndexAttr = (opts.mediaIndex != null && opts.mediaIndex !== '') ? (' data-media-index="' + escapeHtml(String(opts.mediaIndex)) + '"') : '';
    var triggerClass = opts.triggerClass || 'rounded bg-ink/75 px-1.5 py-0.5 text-[11px] font-bold leading-none text-white hover:bg-ink';
    return '<div class="media-item-menu relative">' +
      '<button type="button" class="js-media-menu-trigger ' + triggerClass + '" title="Opciones">⋯</button>' +
      '<div class="js-media-menu-panel hidden overflow-hidden rounded-lg border border-line bg-white py-1 shadow-lg" data-placement="' + placement + '" data-panel-min="' + escapeHtml(panelMin) + '"' + mediaIndexAttr + '">' +
        lines.join('') +
      '</div>' +
    '</div>';
  }

  var activeMediaMenuTrigger = null;
  var activeMediaMenuPanel = null;

  function positionMediaMenuPanel($trigger, $panel) {
    if (!$trigger.length || !$panel.length) return;
    var rect = $trigger[0].getBoundingClientRect();
    var placement = String($panel.attr('data-placement') || 'right');
    var minW = String($panel.attr('data-panel-min') || '10.5rem');
    $panel.css({
      position: 'fixed',
      zIndex: 10050,
      minWidth: minW,
      maxHeight: 'calc(100vh - 16px)',
      overflowY: 'auto'
    });
    $panel.removeClass('hidden');
    var panelW = $panel.outerWidth();
    var panelH = $panel.outerHeight();
    var top;
    var left;
    if (placement === 'below') {
      top = rect.bottom + 6;
      left = rect.right - panelW;
      if (left < 8) left = 8;
      if (top + panelH > window.innerHeight - 8) {
        top = Math.max(8, rect.top - panelH - 6);
      }
    } else {
      top = rect.top + (rect.height / 2) - (panelH / 2);
      left = rect.right + 8;
      if (left + panelW > window.innerWidth - 8) {
        left = rect.left - panelW - 8;
      }
      if (top < 8) top = 8;
      if (top + panelH > window.innerHeight - 8) {
        top = Math.max(8, window.innerHeight - panelH - 8);
      }
    }
    $panel.css({ top: top + 'px', left: left + 'px' });
  }

  function mediaMenuItemIndex($el) {
    var $panel = $el.closest('.js-media-menu-panel');
    if ($panel.length) {
      var fromPanel = Number($panel.attr('data-media-index'));
      if (!isNaN(fromPanel)) return fromPanel;
    }
    var $item = $el.closest('.product-image-item, .product-video-item');
    if ($item.length) {
      return Number($item.attr('data-index'));
    }
    return NaN;
  }

  function closeAllMediaMenus() {
    $('.js-media-menu-panel').each(function () {
      var $panel = $(this);
      $panel.addClass('hidden').css({ position: '', top: '', left: '', zIndex: '', minWidth: '', maxHeight: '', overflowY: '' });
      var $home = $panel.data('media-menu-home');
      if ($home && $home.length) {
        $home.append($panel);
        $panel.removeData('media-menu-home');
      }
    });
    activeMediaMenuTrigger = null;
    activeMediaMenuPanel = null;
  }

  function updateMainImagePathRow() {
    var url = String($('#product-main-image-url').val() || '').trim();
    var $row = $('#main-image-media-path');
    if (!url) {
      $row.addClass('hidden');
      return;
    }
    var paths = mediaPaths(url);
    $row.removeClass('hidden');
    $row.find('.js-main-image-path-label').text(paths.label).attr('title', paths.label);
    $row.find('.js-copy-main-image-path').attr('data-copy', paths.path || paths.url);
    $row.find('.js-copy-main-image-url').attr('data-copy', paths.url);
    var dl = mediaDirectDownloadUrl(url);
    var $dl = $row.find('.js-main-image-download');
    if (dl) {
      $dl.attr('href', dl).removeClass('hidden');
    } else {
      $dl.addClass('hidden');
    }
  }

  function isHostedMediaUrl(url) {
    url = String(url || '');
    return /\/storage\//i.test(url) || /\/f\//i.test(url);
  }

  function mediaVideoPlayUrl(url) {
    url = String(url || '').trim();
    if (!url) return '';
    if (isHostedMediaUrl(url)) {
      return url;
    }
    if (productIsCj) {
      return mediaVideoProxyUrl + (mediaVideoProxyUrl.indexOf('?') >= 0 ? '&' : '?') + 'u=' + encodeURIComponent(url);
    }
    return url;
  }

  function moveArrayItem(arr, from, to) {
    if (!arr || from === to || from < 0 || to < 0 || from >= arr.length || to >= arr.length) return;
    var item = arr.splice(from, 1)[0];
    arr.splice(to, 0, item);
  }

  var mediaDragFrom = -1;

  function syncMediaHiddenInputs() {
    var $imgHidden = $('#verified-images-hidden').empty();
    verifiedImagesData.forEach(function (url, i) {
      $('<input>', { type: 'hidden', name: 'verified_images[' + i + ']', value: url }).appendTo($imgHidden);
    });
    var $vidHidden = $('#verified-videos-hidden').empty();
    verifiedVideosData.forEach(function (v, i) {
      var prefix = 'verified_videos[' + i + ']';
      $('<input>', { type: 'hidden', name: prefix + '[url]', value: v.url || '' }).appendTo($vidHidden);
      $('<input>', { type: 'hidden', name: prefix + '[name]', value: v.name || '' }).appendTo($vidHidden);
      $('<input>', { type: 'hidden', name: prefix + '[cover]', value: v.cover || '' }).appendTo($vidHidden);
    });
  }

  function pushImageUrl(raw) {
    var url = String(raw || '').trim();
    if (!url) return false;
    if (verifiedImagesData.indexOf(url) >= 0) return false;
    verifiedImagesData.push(url);
    return true;
  }

  function removeImageAt(index) {
    var removed = verifiedImagesData[index];
    verifiedImagesData.splice(index, 1);
    var main = String($('input[name=image_url]').val() || '').trim();
    if (removed && main === removed) {
      $('input[name=image_url]').val(verifiedImagesData[0] || '');
      updateMainImagePathRow();
    }
    renderProductImages();
  }

  function renderProductImages() {
    closeAllMediaMenus();
    var $grid = $('#product-images-grid').empty();
    var main = String($('input[name=image_url]').val() || '').trim();
    var has = verifiedImagesData.length > 0;
    $('#product-images-empty').toggleClass('hidden', has);
    verifiedImagesData.forEach(function (url, i) {
      var isMain = main !== '' && main === url;
      var paths = mediaPaths(url);
      var $item = $(
        '<div class="product-image-item js-media-drag-item relative w-40 shrink-0" data-index="' + i + '" draggable="true">' +
          '<div class="relative h-24 w-full overflow-hidden rounded-lg border border-line bg-mist group">' +
          '<span class="absolute left-1 top-1 z-10 rounded bg-ink/75 px-1.5 py-0.5 text-[9px] font-semibold text-white">' + (i + 1) + '</span>' +
          (isMain ? '<span class="absolute right-8 top-1 z-10 rounded bg-teal/90 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">Principal</span>' : '') +
          '<button type="button" class="js-media-drag-handle absolute left-1 bottom-1 z-10 cursor-grab rounded bg-ink/70 px-1 py-0.5 text-[10px] text-white active:cursor-grabbing" title="Arrastrar para mover">⋮⋮</button>' +
          '<div class="absolute right-1 top-1 z-30">' +
            mediaItemMenuHtml({
              paths: paths,
              showMain: !isMain,
              showImageMove: true,
              deleteClass: 'js-img-del',
              mediaIndex: i,
              placement: 'right'
            }) +
          '</div>' +
          '<button type="button" class="js-zoomable block h-full w-full cursor-zoom-in" data-src="' + escapeHtml(url) + '">' +
            '<img src="' + escapeHtml(url) + '" alt="" class="h-full w-full object-cover pointer-events-none" loading="lazy" referrerpolicy="no-referrer">' +
          '</button>' +
          '</div>' +
        '</div>'
      );
      $grid.append($item);
    });
    syncMediaHiddenInputs();
  }

  function renderProductVideos() {
    closeAllMediaMenus();
    var $list = $('#product-videos-list').empty();
    var has = verifiedVideosData.length > 0;
    $('#product-videos-empty').toggleClass('hidden', has);
    verifiedVideosData.forEach(function (v, i) {
      var url = String(v.url || '').trim();
      var play = mediaVideoPlayUrl(url);
      var name = String(v.name || ('Video ' + (i + 1)));
      var cover = String(v.cover || '').trim();
      var posterAttr = cover ? ' poster="' + escapeHtml(cover) + '"' : '';
      var urlPaths = mediaPaths(url);
      var coverPaths = mediaPaths(cover);
      var $card = $(
        '<div class="product-video-item js-media-drag-item overflow-hidden rounded-xl border border-line bg-white" data-index="' + i + '" draggable="true">' +
          '<div class="flex items-center justify-between gap-2 border-b border-line bg-mist/30 px-3 py-2">' +
            '<div class="flex items-center gap-2 min-w-0">' +
              '<button type="button" class="js-media-drag-handle shrink-0 cursor-grab rounded border border-line bg-white px-1.5 py-0.5 text-[10px] text-ink-soft active:cursor-grabbing" title="Arrastrar para mover">⋮⋮</button>' +
              '<span class="truncate text-xs font-semibold text-ink">Video ' + (i + 1) + '</span>' +
            '</div>' +
            mediaItemMenuHtml({
              paths: urlPaths,
              showVideoMove: true,
              deleteClass: 'js-vid-del',
              mediaIndex: i,
              placement: 'below',
              panelMinWidth: '15rem',
              triggerClass: 'rounded border border-line bg-white px-2 py-0.5 text-sm font-bold leading-none text-ink-soft hover:bg-mist'
            }) +
          '</div>' +
          '<div class="bg-ink/95">' +
            (play
              ? '<video class="mx-auto max-h-56 w-full" controls playsinline preload="metadata"' + posterAttr + ' src="' + escapeHtml(play) + '"></video>'
              : '<div class="flex h-32 items-center justify-center text-xs text-white/50">Sin URL de video</div>') +
          '</div>' +
          '<div class="space-y-2 p-3">' +
            '<div><label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-ink-soft/55">URL del video</label>' +
            '<input type="url" class="js-vid-url admin-input !py-1.5 text-xs font-mono" value="' + escapeHtml(url) + '" placeholder="https://…"></div>' +
            '<div class="grid gap-2 sm:grid-cols-2">' +
              '<div><label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-ink-soft/55">Nombre</label>' +
              '<input type="text" class="js-vid-name admin-input !py-1.5 text-xs" value="' + escapeHtml(name) + '"></div>' +
              '<div><label class="mb-1 block text-[10px] font-medium uppercase tracking-wide text-ink-soft/55">Poster (URL)</label>' +
              '<div class="flex items-end gap-2">' +
                '<input type="url" class="js-vid-cover admin-input !py-1.5 text-xs font-mono flex-1" value="' + escapeHtml(cover) + '" placeholder="https://…">' +
                (cover ? mediaItemMenuHtml({
                  paths: coverPaths,
                  placement: 'below',
                  panelMinWidth: '15rem',
                  triggerClass: 'shrink-0 rounded border border-line bg-white px-2 py-1.5 text-sm font-bold leading-none text-ink-soft hover:bg-mist'
                }) : '') +
              '</div></div>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
      $list.append($card);
    });
    syncMediaHiddenInputs();
  }

  function renderProductMedia() {
    renderProductImages();
    renderProductVideos();
  }

  $('#btn-push-image-url, #btn-add-image-url').on('click', function () {
    if (pushImageUrl($('#new-image-url').val())) {
      $('#new-image-url').val('');
      renderProductImages();
    }
  });
  $('#new-image-url').on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $('#btn-push-image-url').trigger('click');
    }
  });

  $(document).on('click', '.js-img-main', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i)) return;
    var url = verifiedImagesData[i];
    if (!url) return;
    $('input[name=image_url]').val(url);
    updateMainImagePathRow();
    renderProductImages();
  });
  $(document).on('click', '.js-img-del', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i)) return;
    if (!confirm('¿Quitar esta imagen de la galería?')) return;
    removeImageAt(i);
  });
  $(document).on('click', '.js-img-up', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i) || i <= 0) return;
    var tmp = verifiedImagesData[i - 1];
    verifiedImagesData[i - 1] = verifiedImagesData[i];
    verifiedImagesData[i] = tmp;
    renderProductImages();
  });
  $(document).on('click', '.js-img-down', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i) || i >= verifiedImagesData.length - 1) return;
    var tmp = verifiedImagesData[i + 1];
    verifiedImagesData[i + 1] = verifiedImagesData[i];
    verifiedImagesData[i] = tmp;
    renderProductImages();
  });

  $('#btn-add-video-row').on('click', function () {
    verifiedVideosData.push({ url: '', name: 'Video ' + (verifiedVideosData.length + 1), cover: '' });
    renderProductVideos();
  });
  $('#product-videos-list').on('input', '.js-vid-url, .js-vid-name, .js-vid-cover', function () {
    var $item = $(this).closest('.product-video-item');
    var i = Number($item.attr('data-index'));
    if (!verifiedVideosData[i]) return;
    verifiedVideosData[i] = {
      url: String($item.find('.js-vid-url').val() || '').trim(),
      name: String($item.find('.js-vid-name').val() || '').trim(),
      cover: String($item.find('.js-vid-cover').val() || '').trim()
    };
    syncMediaHiddenInputs();
  });
  $('#product-videos-list').on('change', '.js-vid-url, .js-vid-cover', function () {
    renderProductVideos();
  });
  $(document).on('click', '.js-vid-del', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i)) return;
    if (!confirm('¿Quitar este video?')) return;
    verifiedVideosData.splice(i, 1);
    renderProductVideos();
  });
  $(document).on('click', '.js-vid-up', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i) || i <= 0) return;
    moveArrayItem(verifiedVideosData, i, i - 1);
    renderProductVideos();
  });
  $(document).on('click', '.js-vid-down', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var i = mediaMenuItemIndex($(this));
    if (isNaN(i) || i >= verifiedVideosData.length - 1) return;
    moveArrayItem(verifiedVideosData, i, i + 1);
    renderProductVideos();
  });

  function bindMediaDragReorder($container, itemSelector, dataRef, renderFn) {
    $container.on('dragstart', itemSelector, function (e) {
      if ($(e.target).closest('.js-img-main, .js-img-up, .js-img-down, .js-img-del, .js-vid-up, .js-vid-down, .js-vid-del, .js-zoomable, .js-media-menu-trigger, .js-media-menu-panel, .js-media-menu-item, input, textarea, select, a, video, label').length) {
        e.preventDefault();
        return;
      }
      mediaDragFrom = Number($(this).attr('data-index'));
      if (isNaN(mediaDragFrom)) return;
      e.originalEvent.dataTransfer.effectAllowed = 'move';
      e.originalEvent.dataTransfer.setData('text/plain', String(mediaDragFrom));
      $(this).addClass('opacity-60 ring-2 ring-teal/40');
    });
    $container.on('dragend', itemSelector, function () {
      mediaDragFrom = -1;
      $(itemSelector).removeClass('opacity-60 ring-2 ring-teal/40 ring-teal/50');
    });
    $container.on('dragover', itemSelector, function (e) {
      if (mediaDragFrom < 0) return;
      e.preventDefault();
      $(this).addClass('ring-2 ring-teal/50');
    });
    $container.on('dragleave', itemSelector, function () {
      $(this).removeClass('ring-2 ring-teal/50');
    });
    $container.on('drop', itemSelector, function (e) {
      e.preventDefault();
      e.stopPropagation();
      $(itemSelector).removeClass('ring-2 ring-teal/50');
      var to = Number($(this).attr('data-index'));
      if (mediaDragFrom < 0 || isNaN(to) || mediaDragFrom === to) {
        mediaDragFrom = -1;
        return;
      }
      moveArrayItem(dataRef, mediaDragFrom, to);
      mediaDragFrom = -1;
      renderFn();
    });
  }

  bindMediaDragReorder($('#product-images-grid'), '.product-image-item', verifiedImagesData, renderProductImages);
  bindMediaDragReorder($('#product-videos-list'), '.product-video-item', verifiedVideosData, renderProductVideos);

  $(document).on('click', '.js-media-menu-trigger', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $trigger = $(this);
    var $menu = $trigger.closest('.media-item-menu');
    var $panel = $menu.find('.js-media-menu-panel');
    var willOpen = $panel.hasClass('hidden');
    closeAllMediaMenus();
    if (!willOpen) return;
    var $item = $trigger.closest('.product-image-item, .product-video-item');
    if ($item.length) {
      $panel.attr('data-media-index', $item.attr('data-index') || '');
    }
    $panel.data('media-menu-home', $menu);
    $('body').append($panel);
    positionMediaMenuPanel($trigger, $panel);
    activeMediaMenuTrigger = $trigger;
    activeMediaMenuPanel = $panel;
  });
  $(document).on('click', function (e) {
    if ($(e.target).closest('.js-media-menu-panel, .js-media-menu-trigger, .media-item-menu').length) return;
    closeAllMediaMenus();
  });
  $(document).on('click', '.js-media-menu-panel', function (e) {
    e.stopPropagation();
  });
  $(document).on('click', '.js-media-menu-item', function (e) {
    if ($(this).hasClass('js-download-media')) return;
    e.stopPropagation();
    setTimeout(closeAllMediaMenus, 0);
  });
  $(window).on('scroll resize', function () {
    if (!activeMediaMenuTrigger || !activeMediaMenuPanel) return;
    if (activeMediaMenuPanel.hasClass('hidden')) return;
    positionMediaMenuPanel(activeMediaMenuTrigger, activeMediaMenuPanel);
  });

  $(document).on('click', '.js-copy-media', function (e) {
    e.preventDefault();
    copyMediaText($(this).attr('data-copy') || '', $(this));
  });
  $('#main-image-media-path').on('click', '.js-copy-main-image-path, .js-copy-main-image-url', function (e) {
    e.preventDefault();
    copyMediaText($(this).attr('data-copy') || '', $(this));
  });
  $('#product-main-image-url').on('input change', updateMainImagePathRow);

  function uploadProductImageFiles(files) {
    if (!productUploadImageUrl) return;
    var list = [];
    Array.prototype.forEach.call(files || [], function (file) {
      if (file && /^image\/(jpeg|png|gif|webp)$/i.test(String(file.type || ''))) {
        list.push(file);
      }
    });
    if (!list.length) return;
    var $status = $('#product-image-upload-status');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Subiendo ' + list.length + ' imagen(es)…');
    var pending = list.length;
    var uploaded = 0;
    var failed = 0;
    list.forEach(function (file) {
      var fd = new FormData();
      fd.append('file', file);
      fd.append('_token', csrf);
      $.ajax({
        url: productUploadImageUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
      }).done(function (res) {
        if (res && res.success && res.url && pushImageUrl(res.url)) {
          uploaded++;
        } else {
          failed++;
        }
      }).fail(function () {
        failed++;
      }).always(function () {
        pending--;
        if (pending === 0) {
          renderProductImages();
          var msg = uploaded > 0 ? ('✓ ' + uploaded + ' imagen(es) subida(s).') : 'No se pudo subir.';
          if (failed) msg += ' (' + failed + ' error(es))';
          $status.text(msg).toggleClass('text-coral', uploaded === 0).toggleClass('text-teal', uploaded > 0);
          $('#product-image-upload').val('');
        }
      });
    });
  }

  function uploadProductVideoFiles(files) {
    if (!productUploadVideoUrl) return;
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;
    var $status = $('#product-video-upload-status');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Subiendo video…');
    var pending = list.length;
    var uploaded = 0;
    var failed = 0;
    list.forEach(function (file) {
      var fd = new FormData();
      fd.append('file', file);
      fd.append('_token', csrf);
      $.ajax({
        url: productUploadVideoUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
      }).done(function (res) {
        if (res && res.success && res.url) {
          verifiedVideosData.push({
            url: res.url,
            name: res.name || file.name || ('Video ' + (verifiedVideosData.length + 1)),
            cover: ''
          });
          uploaded++;
        } else {
          failed++;
        }
      }).fail(function (xhr) {
        failed++;
        var err = (xhr.responseJSON && xhr.responseJSON.message) || '';
        if (err && pending === 1) {
          $status.text(err);
        }
      }).always(function () {
        pending--;
        if (pending === 0) {
          renderProductVideos();
          var msg = uploaded > 0 ? ('✓ ' + uploaded + ' video(s) subido(s). Guarda el producto.') : 'No se pudo subir el video.';
          if (failed) msg += ' (' + failed + ' error(es))';
          $status.text(msg).toggleClass('text-coral', uploaded === 0).toggleClass('text-teal', uploaded > 0);
          $('#product-video-upload').val('');
        }
      });
    });
  }

  $('#product-image-upload').on('change', function () {
    uploadProductImageFiles(this.files);
  });
  $('#product-video-upload').on('change', function () {
    uploadProductVideoFiles(this.files);
  });
  $('#product-images-grid').on('dragover', function (e) {
    var dt = e.originalEvent && e.originalEvent.dataTransfer;
    if (dt && dt.types && Array.prototype.indexOf.call(dt.types, 'Files') >= 0) {
      e.preventDefault();
      $(this).addClass('ring-2 ring-teal/40');
    }
  }).on('dragleave', function (e) {
    var dt = e.originalEvent && e.originalEvent.dataTransfer;
    if (dt && dt.types && Array.prototype.indexOf.call(dt.types, 'Files') >= 0) {
      $(this).removeClass('ring-2 ring-teal/40');
    }
  }).on('drop', function (e) {
    var dt = e.originalEvent && e.originalEvent.dataTransfer;
    if (!dt || !dt.files || !dt.files.length) return;
    e.preventDefault();
    $(this).removeClass('ring-2 ring-teal/40');
    uploadProductImageFiles(dt.files);
  });

  if ($('#product-media-card').length) {
    renderProductMedia();
    updateMainImagePathRow();
  }

  var similarImportPreviewUrl = @json($similar_import_preview_url ?? null);
  var similarImportUrl = @json($similar_import_url ?? null);

  function similarImportSections() {
    var sections = [];
    $('.js-similar-section:checked').each(function () {
      sections.push(String($(this).val() || ''));
    });
    return sections;
  }

  function setSimilarImportStatus(msg, ok) {
    var $st = $('#similar-import-status');
    $st.removeClass('hidden text-teal text-coral text-ink-soft/70')
      .addClass(ok ? 'text-teal' : 'text-coral')
      .text(msg);
  }

  function renderSimilarPreview(res) {
    var counts = (res && res.counts) || {};
    var source = (res && res.source) === 'cj' ? 'CJ Dropshipping' : 'AliExpress';
    var parts = [];
    if (counts.images) parts.push(counts.images + ' img');
    if (counts.videos) parts.push(counts.videos + ' video(s)');
    if (counts.reviews) parts.push(counts.reviews + ' reseñas');
    if (counts.description) parts.push('descripción');
    if (counts.details) parts.push(counts.details + ' detalles');
    var html = '<div class="font-medium text-ink">' + escapeHtml(res.title || 'Producto similar') + '</div>' +
      '<div class="mt-1 text-xs text-ink-soft/70">Origen: ' + escapeHtml(source) + '</div>' +
      '<div class="mt-2">Disponible: ' + escapeHtml(parts.join(' · ') || 'sin contenido importable') + '</div>';
    $('#similar-import-preview').removeClass('hidden').html(html);
  }

  $('#btn-similar-preview').on('click', function () {
    if (!similarImportPreviewUrl) return;
    var url = String($('#similar-import-url').val() || '').trim();
    if (!url) {
      setSimilarImportStatus('Pega la URL del producto similar.', false);
      return;
    }
    var $btn = $(this).prop('disabled', true);
    setSimilarImportStatus('Consultando producto…', true);
    $('#similar-import-preview').addClass('hidden').empty();
    $.ajax({
      url: similarImportPreviewUrl,
      method: 'POST',
      data: { _token: csrf, url: url },
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (!res || !res.success) {
        setSimilarImportStatus((res && res.error) || 'No se pudo obtener vista previa.', false);
        return;
      }
      renderSimilarPreview(res);
      setSimilarImportStatus('Vista previa lista. Revisa y pulsa Importar.', true);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al consultar el producto.';
      setSimilarImportStatus(msg, false);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  $('#btn-similar-import').on('click', function () {
    if (!similarImportUrl) return;
    var url = String($('#similar-import-url').val() || '').trim();
    var sections = similarImportSections();
    if (!url) {
      setSimilarImportStatus('Pega la URL del producto similar.', false);
      return;
    }
    if (!sections.length) {
      setSimilarImportStatus('Marca al menos una sección para importar.', false);
      return;
    }
    if (!confirm('¿Importar contenido del producto similar a este borrador?')) return;
    var $btn = $(this).prop('disabled', true);
    var $previewBtn = $('#btn-similar-preview').prop('disabled', true);
    setSimilarImportStatus('Importando… puede tardar un minuto.', true);
    $.ajax({
      url: similarImportUrl,
      method: 'POST',
      data: {
        _token: csrf,
        url: url,
        sections: sections,
        replace: $('#similar-import-replace').is(':checked') ? '1' : '0'
      },
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      if (!res || !res.success) {
        setSimilarImportStatus((res && res.error) || 'La importación falló.', false);
        return;
      }
      setSimilarImportStatus(res.message || 'Importación completada.', true);
      window.setTimeout(function () { window.location.reload(); }, 700);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al importar.';
      setSimilarImportStatus(msg, false);
    }).always(function () {
      $btn.prop('disabled', false);
      $previewBtn.prop('disabled', false);
    });
  });

  renderReviewsList();

  function detailRowHtml(idx) {
    return '<tr class="verified-detail-row border-t border-line/70">' +
      '<td class="px-3 py-2 align-top"><input type="text" name="verified_details[' + idx + '][name]" class="admin-input !py-1.5 text-sm w-full"></td>' +
      '<td class="px-3 py-2 align-top"><input type="text" name="verified_details[' + idx + '][value]" class="admin-input !py-1.5 text-sm w-full"></td>' +
      '<td class="px-2 py-2 align-top text-right"><button type="button" class="js-remove-detail text-xs text-coral hover:underline">Quitar</button></td>' +
    '</tr>';
  }

  $('#btn-add-detail').on('click', function () {
    $('#verified-details-empty').remove();
    var idx = $('#verified-details-rows .verified-detail-row').length;
    $('#verified-details-rows').append(detailRowHtml(idx));
  });

  $(document).on('click', '.js-remove-detail', function () {
    $(this).closest('.verified-detail-row').remove();
    reindexVerifiedDetails();
    if (!$('#verified-details-rows .verified-detail-row').length) {
      $('#verified-details-rows').html('<tr id="verified-details-empty"><td colspan="3" class="px-3 py-4 text-sm text-ink-soft/55">Sin detalles. Importa desde AliExpress o añade filas manualmente.</td></tr>');
    }
  });

  function currencyMeta(code) {
    code = String(code || '').toUpperCase();
    for (var i = 0; i < storefrontCurrencies.length; i++) {
      if (String(storefrontCurrencies[i].code).toUpperCase() === code) return storefrontCurrencies[i];
    }
    return { code: code, label: code, rounding: rounding[code] || 'none' };
  }

  function usedPriceCodes(exceptRow) {
    var used = {};
    used[baseCurrency()] = true;
    $('#currency-price-rows .currency-price-row').each(function () {
      if (exceptRow && this === exceptRow) return;
      var code = String($(this).attr('data-code') || '').toUpperCase();
      if (code) used[code] = true;
    });
    return used;
  }

  function currencySelectHtml(selected) {
    selected = String(selected || '').toUpperCase();
    var html = '';
    storefrontCurrencies.forEach(function (row) {
      var code = String(row.code || '').toUpperCase();
      var sel = code === selected ? ' selected' : '';
      html += '<option value="' + code + '" data-label="' + String(row.label || '').replace(/"/g, '&quot;') + '"' + sel + '>' +
        code + ' — ' + (row.label || code) + '</option>';
    });
    if (selected && !storefrontCurrencies.some(function (r) { return String(r.code).toUpperCase() === selected; })) {
      html += '<option value="' + selected + '" selected>' + selected + '</option>';
    }
    return html;
  }

  function refreshSelectAvailability() {
    $('#currency-price-rows .currency-price-row').each(function () {
      var row = this;
      var current = String($(row).attr('data-code') || '').toUpperCase();
      var used = usedPriceCodes(row);
      $(row).find('.js-ccy-select option').each(function () {
        var v = String($(this).val() || '').toUpperCase();
        $(this).prop('disabled', !!used[v] && v !== current);
      });
    });
    var used = usedPriceCodes(null);
    $('#add-price-currency-options .add-ccy-option').each(function () {
      var v = String($(this).data('code') || '').toUpperCase();
      var taken = !!used[v];
      $(this).toggleClass('opacity-40 pointer-events-none', taken);
      $(this).closest('li').toggle(!taken);
    });
  }

  function remapRowCode($row, next) {
    var prev = String($row.attr('data-code') || '').toUpperCase();
    next = String(next || '').toUpperCase();
    if (!next || prev === next) return;
    var used = usedPriceCodes($row.get(0));
    if (used[next]) {
      $row.find('.js-ccy-select').val(prev);
      return;
    }
    var meta = currencyMeta(next);
    var roundKey = rounding[next] || meta.rounding || 'none';
    $row.attr('data-code', next);
    $row.attr('data-search', (next + ' ' + (meta.label || '')).toLowerCase());
    var $round = $row.find('.js-ccy-rounding');
    if ($round.length) {
      $round.val(roundKey);
    }
    $row.find('.js-ccy-price, .js-ccy-compare, .js-ccy-lock, .js-ccy-rounding, input[type="hidden"]').each(function () {
      var name = String($(this).attr('name') || '');
      if (name.indexOf('prices[') === 0) {
        $(this).attr('name', name.replace('prices[' + prev + ']', 'prices[' + next + ']'));
      }
      if ($(this).is('.js-ccy-price, .js-ccy-compare')) {
        $(this).attr('data-code', next);
      }
    });
    refreshSelectAvailability();
    refreshCurrencyPricePreviews();
    filterCurrencyRows($('#currency-price-search').val());
  }

  function syncLockLabel($row) {
    var on = $row.find('.js-ccy-lock').is(':checked');
    $row.find('.js-ccy-lock-label').text(on ? 'Fijo' : 'Auto');
  }

  function syncPriceRowsToBaseCurrency() {
    var base = baseCurrency();
    $('#currency-price-rows .currency-price-row').each(function () {
      var code = String($(this).attr('data-code') || '').toUpperCase();
      $(this).toggle(code !== base);
    });
    refreshSelectAvailability();
    filterCurrencyRows($('#currency-price-search').val());
  }

  function filterCurrencyRows(q) {
    q = String(q || '').toLowerCase().trim();
    var visible = 0;
    var base = baseCurrency();
    $('#currency-price-rows .currency-price-row').each(function () {
      var $row = $(this);
      var code = String($row.attr('data-code') || '').toUpperCase();
      if (code === base) {
        $row.hide();
        return;
      }
      var hay = String($row.attr('data-search') || $row.text() || '').toLowerCase();
      var show = !q || hay.indexOf(q) !== -1;
      $row.toggle(show);
      if (show) visible += 1;
    });
    $('#currency-price-empty').toggleClass('hidden', visible > 0);
  }

  function refreshCurrencyPricePreviews() {
    var from = baseCurrency();
    var price = Number($('#product-price').val() || 0);
    var compare = Number($('input[name="compare_at_price"]').val() || 0);
    $('#currency-price-rows .currency-price-row').each(function () {
      var $row = $(this);
      var code = String($row.attr('data-code') || '').toUpperCase();
      if (!code || code === from) return;
      var mode = rowRounding($row);
      var fxPrice = (isFinite(price) && price > 0) ? convertMoney(price, from, code, true, mode) : null;
      var fxCompare = (isFinite(compare) && compare > 0) ? convertMoney(compare, from, code, true, mode) : null;
      $row.find('.js-ccy-price').attr('placeholder', fxPrice != null ? money2str(fxPrice) : 'FX');
      $row.find('.js-ccy-compare').attr('placeholder', fxCompare != null ? money2str(fxCompare) : '');
      if ($row.find('.js-ccy-lock').is(':checked')) return;
      $row.find('.js-ccy-price').val('');
      $row.find('.js-ccy-compare').val('');
    });
  }

  function upsertCurrencyPriceRow(code, price, compare, lock, skipRound) {
    code = String(code || '').toUpperCase();
    if (!code) return;
    var $row = $('#currency-price-rows .currency-price-row[data-code="' + code + '"]');
    if (!$row.length) {
      var meta = currencyMeta(code);
      var roundKey = rounding[code] || meta.rounding || 'none';
      $row = $('<tr class="border-b border-line/70 currency-price-row"></tr>')
        .attr('data-code', code)
        .attr('data-search', (code + ' ' + (meta.label || '')).toLowerCase());
      $row.html(
        '<td class="px-3 py-2 min-w-[220px]"><select class="admin-input !py-1.5 text-xs js-ccy-select" aria-label="Moneda">' + currencySelectHtml(code) + '</select></td>' +
        '<td class="px-3 py-2 min-w-[240px]"><select name="prices[' + code + '][rounding]" class="admin-input !py-1.5 text-xs js-ccy-rounding" aria-label="Redondeo">' + roundingSelectHtml(roundKey) + '</select></td>' +
        '<td class="px-3 py-2"><input type="number" step="0.01" min="0" name="prices[' + code + '][price]" class="admin-input !py-1.5 font-mono js-ccy-price" data-code="' + code + '" placeholder="FX"></td>' +
        '<td class="px-3 py-2"><input type="number" step="0.01" min="0" name="prices[' + code + '][compare_at_price]" class="admin-input !py-1.5 font-mono js-ccy-compare" data-code="' + code + '"></td>' +
        '<td class="px-3 py-2"><label class="inline-flex items-center gap-1.5 text-ink-soft">' +
          '<input type="hidden" name="prices[' + code + '][locked]" value="0">' +
          '<input type="checkbox" name="prices[' + code + '][locked]" value="1" class="rounded border-line text-teal js-ccy-lock">' +
          '<span class="js-ccy-lock-label">Auto</span></label></td>' +
        '<td class="px-2 py-2"><button type="button" class="js-ccy-remove text-ink-soft/40 hover:text-coral" title="Quitar">&times;</button></td>'
      );
      $('#currency-price-rows').append($row);
    }
    if (skipRound) {
      $row.find('.js-ccy-rounding').val('none');
    }
    if (price != null && isFinite(Number(price)) && Number(price) > 0) {
      var p = Number(price);
      setMoneyInput($row.find('.js-ccy-price'), skipRound ? p : applyRound(p, rowRounding($row)));
    }
    if (compare != null && isFinite(Number(compare)) && Number(compare) > 0) {
      var c = Number(compare);
      setMoneyInput($row.find('.js-ccy-compare'), skipRound ? c : applyRound(c, rowRounding($row)));
    }
    if (lock) {
      $row.find('.js-ccy-lock').prop('checked', true);
    }
    syncLockLabel($row);
    syncPriceRowsToBaseCurrency();
  }

  $(document).on('change', '.js-ccy-lock', function () {
    syncLockLabel($(this).closest('.currency-price-row'));
    if (!$(this).is(':checked')) refreshCurrencyPricePreviews();
  });
  $(document).on('input', '.js-ccy-price, .js-ccy-compare', function () {
    var $row = $(this).closest('.currency-price-row');
    if (!$row.find('.js-ccy-lock').is(':checked')) {
      $row.find('.js-ccy-lock').prop('checked', true);
      syncLockLabel($row);
    }
  });
  $(document).on('blur', '.js-ccy-price, .js-ccy-compare', function () {
    var $row = $(this).closest('.currency-price-row');
    var n = Number($(this).val());
    if (isFinite(n) && n > 0) {
      setMoneyInput($(this), applyRound(n, rowRounding($row)));
    }
  });
  $(document).on('change', '.js-ccy-rounding', function () {
    var $row = $(this).closest('.currency-price-row');
    var mode = rowRounding($row);
    $row.find('.js-ccy-price, .js-ccy-compare').each(function () {
      var n = Number($(this).val());
      if (isFinite(n) && n > 0) {
        setMoneyInput($(this), applyRound(n, mode));
      }
    });
    refreshCurrencyPricePreviews();
  });
  $(document).on('change', '.js-ccy-select', function () {
    remapRowCode($(this).closest('.currency-price-row'), $(this).val());
  });
  $(document).on('click', '.js-ccy-remove', function () {
    $(this).closest('.currency-price-row').remove();
    refreshSelectAvailability();
    filterCurrencyRows($('#currency-price-search').val());
  });
  $('#currency-price-search').on('input', function () {
    filterCurrencyRows($(this).val());
  });
  $('#fill-fx-prices').on('click', function () {
    var from = baseCurrency();
    var price = Number($('#product-price').val() || 0);
    var compare = Number($('input[name="compare_at_price"]').val() || 0);
    $('#currency-price-rows .currency-price-row').each(function () {
      var $row = $(this);
      var code = String($row.attr('data-code') || '').toUpperCase();
      if (!code || code === from) return;
      if ($row.find('.js-ccy-lock').is(':checked') && String($row.find('.js-ccy-price').val() || '') !== '') return;
      if (!(isFinite(price) && price > 0)) return;
      var mode = rowRounding($row);
      setMoneyInput($row.find('.js-ccy-price'), convertMoney(price, from, code, true, mode));
      if (isFinite(compare) && compare > 0) {
        setMoneyInput($row.find('.js-ccy-compare'), convertMoney(compare, from, code, true, mode));
      }
      $row.find('.js-ccy-lock').prop('checked', true);
      syncLockLabel($row);
    });
  });

  $('#suggest-ai-prices').on('click', function () {
    var $btn = $(this);
    var $status = $('#suggest-prices-status');
    var base = baseCurrency();
    var currencies = [];
    $('#currency-price-rows .currency-price-row').each(function () {
      var $row = $(this);
      var code = String($row.attr('data-code') || '').toUpperCase();
      if (!/^[A-Z]{3}$/.test(code)) return;
      currencies.push({ code: code, rounding: rowRounding($row) });
    });
    if (!currencies.some(function (c) { return String(c.code).toUpperCase() === base; })) {
      currencies.unshift({ code: base, rounding: 'auto' });
    }
    if (!currencies.length) {
      alert('Añade al menos una moneda en la tabla para sugerir precios.');
      return;
    }
    var $bd = $('#price-breakdown');
    var $purchase = $('#product-purchase-price');
    var purchasePrice = Number($purchase.val() || 0);
    if (!(purchasePrice > 0)) {
      purchasePrice = Number($purchase.attr('data-marketplace') || $purchase.attr('placeholder') || 0);
    }
    var costUsd = Number($bd.attr('data-cost-usd') || 0);
    if (!(purchasePrice > 0) && !(costUsd > 0)) {
      alert('Indica el precio de compra (o importa el producto desde marketplace) para calcular el precio de venta.');
      return;
    }
    if (!(Number($purchase.val() || 0) > 0) && purchasePrice > 0) {
      setMoneyInput($purchase, purchasePrice);
      $purchase.trigger('change');
    }
    $btn.prop('disabled', true).text('✨ Pensando…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60')
      .text('Calculando precio de venta desde compra + fees + margen…');

    $.ajax({
      url: suggestPricesUrl,
      method: 'POST',
      dataType: 'json',
      timeout: 120000,
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: {
        _token: csrf,
        name: String($('#field-name').val() || ''),
        purchase_price: purchasePrice,
        purchase_currency: base,
        cost_usd: costUsd,
        ship_usd: 0,
        fees_pct: Number($bd.attr('data-fees-pct') || 0.045),
        target_margin: Number($bd.attr('data-target-margin') || 0.42),
        base_price: Number($('#product-price').val() || 0),
        base_currency: base,
        compare_at: Number($('input[name="compare_at_price"]').val() || 0),
        currencies: currencies
      }
    }).done(function (res) {
      if (!(res && res.success && res.prices)) {
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral')
          .text((res && res.error) || 'No se pudieron sugerir precios.');
        return;
      }
      var n = 0;
      var base = baseCurrency();
      var baseRow = res.prices[base] || null;
      $.each(res.prices, function (code, row) {
        if (!row || row.price == null) return;
        upsertCurrencyPriceRow(code, row.price, row.compare_at_price, true, true);
        if (String(code).toUpperCase() === base) {
          setMoneyInput($('#product-price'), row.price);
          if (row.compare_at_price != null && Number(row.compare_at_price) > Number(row.price)) {
            setMoneyInput($('input[name="compare_at_price"]'), row.compare_at_price);
          }
          $('#product-price').trigger('change');
        }
        n++;
      });
      if (baseRow && baseRow.price != null) {
        setMoneyInput($('#product-price'), baseRow.price);
        if (baseRow.compare_at_price != null && Number(baseRow.compare_at_price) > Number(baseRow.price)) {
          setMoneyInput($('input[name="compare_at_price"]'), baseRow.compare_at_price);
        } else {
          var charmCompare = suggestCharmCompare(Number(baseRow.price), base);
          if (charmCompare > Number(baseRow.price)) {
            setMoneyInput($('input[name="compare_at_price"]'), charmCompare);
          }
        }
        $('input[name="compare_at_price"]').trigger('change');
        $('#product-price').trigger('change');
      }
      $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal')
        .text(res.message || ('Precios sugeridos en ' + n + ' moneda(s). Guarda el producto.'));
      if (window.AdminToast) AdminToast.success(res.message || 'Precios sugeridos');
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al sugerir precios';
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
      if (window.AdminToast) AdminToast.error(err);
    }).always(function () {
      $btn.prop('disabled', false).text('✨ Sugerir precios IA');
    });
  });

  (function initAddCurrencySearch() {
    var $box = $('#add-price-currency-box');
    if (!$box.length) return;
    var $trigger = $('#add-price-currency-trigger');
    var $dropdown = $('#add-price-currency-dropdown');
    var $search = $('#add-price-currency-search');
    var $options = $('#add-price-currency-options');
    var $empty = $('#add-price-currency-empty');

    function open() {
      refreshSelectAvailability();
      $dropdown.removeClass('hidden');
      $trigger.attr('aria-expanded', 'true');
      $search.val('');
      filter('');
      setTimeout(function () { $search.trigger('focus'); }, 0);
    }
    function close() {
      $dropdown.addClass('hidden');
      $trigger.attr('aria-expanded', 'false');
    }
    function filter(q) {
      q = String(q || '').toLowerCase().trim();
      var visible = 0;
      $options.find('.add-ccy-option').each(function () {
        var $btn = $(this);
        if ($btn.hasClass('pointer-events-none')) {
          $btn.closest('li').hide();
          return;
        }
        var code = String($btn.data('code') || '').toLowerCase();
        var label = String($btn.data('label') || '').toLowerCase();
        var show = !q || code.indexOf(q) !== -1 || label.indexOf(q) !== -1;
        $btn.closest('li').toggle(show);
        if (show) visible += 1;
      });
      $empty.toggleClass('hidden', visible > 0);
    }
    $trigger.on('click', function (e) {
      e.preventDefault();
      if ($dropdown.hasClass('hidden')) open(); else close();
    });
    $search.on('input', function () { filter($(this).val()); });
    $search.on('keydown', function (e) {
      if (e.key === 'Escape') { close(); $trigger.trigger('focus'); }
      if (e.key === 'Enter') {
        e.preventDefault();
        var $first = $options.find('.add-ccy-option').filter(function () {
          return $(this).closest('li').is(':visible');
        }).first();
        if ($first.length) {
          upsertCurrencyPriceRow(String($first.data('code') || ''), null, null, false);
          refreshCurrencyPricePreviews();
          close();
        }
      }
    });
    $options.on('click', '.add-ccy-option', function () {
      if ($(this).hasClass('pointer-events-none')) return;
      upsertCurrencyPriceRow(String($(this).data('code') || ''), null, null, false);
      refreshCurrencyPricePreviews();
      close();
    });
    $(document).on('click', function (e) {
      if (!$(e.target).closest('#add-price-currency-box').length) close();
    });
  })();

  $('#product-price').on('change', refreshCurrencyPricePreviews);
  syncPriceRowsToBaseCurrency();
  refreshCurrencyPricePreviews();

  // —— Combobox buscador de moneda ——
  (function initCurrencySearch() {
    var $box = $('#currency-combobox');
    if (! $box.length) return;
    var $trigger = $('#currency-trigger');
    var $dropdown = $('#currency-dropdown');
    var $search = $('#currency-search');
    var $options = $('#currency-options');
    var $empty = $('#currency-empty');
    var $hidden = $('#product-currency');
    var $label = $('#currency-trigger-label');

    function open() {
      $dropdown.removeClass('hidden');
      $trigger.attr('aria-expanded', 'true');
      $search.val('');
      filter('');
      setTimeout(function () { $search.trigger('focus'); }, 0);
    }
    function close() {
      $dropdown.addClass('hidden');
      $trigger.attr('aria-expanded', 'false');
    }
    function filter(q) {
      q = String(q || '').toLowerCase().trim();
      var visible = 0;
      $options.find('.currency-option').each(function () {
        var code = String($(this).data('code') || '').toLowerCase();
        var label = String($(this).data('label') || '').toLowerCase();
        var show = !q || code.indexOf(q) !== -1 || label.indexOf(q) !== -1;
        $(this).closest('li').toggle(show);
        if (show) visible += 1;
      });
      $empty.toggleClass('hidden', visible > 0);
      $options.toggleClass('hidden', visible === 0);
    }
    function select(code, label) {
      code = String(code || '').toUpperCase();
      $hidden.val(code).trigger('change');
      $label.text(code + ' — ' + (label || code));
      $options.find('.currency-option').removeClass('bg-teal/10 text-teal font-medium').addClass('text-ink');
      $options.find('.currency-option[data-code="' + code + '"]')
        .addClass('bg-teal/10 text-teal font-medium').removeClass('text-ink');
      close();
    }

    $trigger.on('click', function (e) {
      e.preventDefault();
      if ($dropdown.hasClass('hidden')) open(); else close();
    });
    $search.on('input', function () { filter($(this).val()); });
    $search.on('keydown', function (e) {
      if (e.key === 'Escape') { close(); $trigger.trigger('focus'); }
      if (e.key === 'Enter') {
        e.preventDefault();
        var $first = $options.find('.currency-option').filter(function () {
          return $(this).closest('li').is(':visible');
        }).first();
        if ($first.length) select($first.data('code'), $first.data('label'));
      }
    });
    $options.on('click', '.currency-option', function () {
      select($(this).data('code'), $(this).data('label'));
    });
    $(document).on('click', function (e) {
      if (! $(e.target).closest('#currency-combobox').length) close();
    });
  })();

  /* ── Bulk variantes ────────────────────────────────────────────── */
  (function () {
    var $table = $('#variants-table');
    if (!$table.length) return;

    var bulkAction = @json(route('admin.store.products.variants.bulk-destroy', $product));
    var csrfToken  = $('meta[name="csrf-token"]').attr('content');
    var $checkAll  = $('#var-check-all');

    /* Form oculto FUERA del form principal (HTML no permite forms anidados) */
    var $bulkForm = $('<form>', {
      id: 'variants-bulk-form',
      method: 'POST',
      action: bulkAction,
      css: { display: 'none' }
    }).append('<input type="hidden" name="_token" value="' + csrfToken + '">')
      .append('<input type="hidden" name="_method" value="DELETE">');
    $('body').append($bulkForm);

    /* Toolbar overlay al final del body */
    var $toolbar = $([
      '<div id="var-bulk-toolbar" style="',
        'display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;',
        'background:#fef2f2;border-top:1.5px solid rgba(239,68,68,.25);',
        'box-shadow:0 -4px 24px rgba(185,28,28,.12);',
        'padding:10px 20px;gap:10px;flex-wrap:wrap;align-items:center;">',
        '<span style="font-size:13px;font-weight:600;color:#0f172a;">',
          '<span id="var-bulk-count">0</span> variante(s)',
        '</span>',
        '<span style="width:1px;height:16px;background:#fca5a5;display:inline-block;"></span>',
        '<button type="button" id="var-bulk-delete" class="admin-btn-danger !px-3 !py-1.5 text-xs">',
          'Eliminar seleccionadas',
        '</button>',
        '<button type="button" id="var-bulk-cancel" class="admin-btn-secondary !px-3 !py-1.5 text-xs" style="margin-left:auto">',
          'Cancelar',
        '</button>',
      '</div>'
    ].join(''));
    $('body').append($toolbar);

    var $count = $toolbar.find('#var-bulk-count');

    function selectedChecks() {
      return $table.find('.var-row-check:checked');
    }

    function refreshVarToolbar() {
      var n = selectedChecks().length;
      $count.text(n);
      $toolbar.css('display', n > 0 ? 'flex' : 'none');
      var total = $table.find('.var-row-check').length;
      $checkAll.prop('checked', total > 0 && n === total);
      $checkAll.prop('indeterminate', n > 0 && n < total);
    }

    $checkAll.on('change', function () {
      $table.find('.var-row-check').prop('checked', this.checked);
      refreshVarToolbar();
    });

    $table.on('change', '.var-row-check', refreshVarToolbar);

    $toolbar.on('click', '#var-bulk-delete', function () {
      var $sel = selectedChecks();
      if (!$sel.length) return;
      if (!confirm('¿Eliminar ' + $sel.length + ' variante(s)? No se volverán a importar en sync CJ.')) return;
      $bulkForm.find('input[name="ids[]"]').remove();
      $sel.each(function () {
        $bulkForm.append('<input type="hidden" name="ids[]" value="' + $(this).val() + '">');
      });
      $bulkForm[0].submit();
    });

    $toolbar.on('click', '#var-bulk-cancel', function () {
      $table.find('.var-row-check').prop('checked', false);
      $checkAll.prop('checked', false).prop('indeterminate', false);
      refreshVarToolbar();
    });

    /* El lightbox global se inicializa más abajo */

    /* Eliminar individual (no puede ser <form> anidado) */
    $table.on('click', '.js-var-single-delete', function () {
      if (!confirm('¿Eliminar esta variante? No se volverá a importar en la próxima sync CJ.')) return;
      var url = $(this).data('url');
      var $f = $('<form>', { method: 'POST', action: url, css: { display: 'none' } })
        .append('<input type="hidden" name="_token" value="' + csrfToken + '">')
        .append('<input type="hidden" name="_method" value="DELETE">');
      $('body').append($f);
      $f[0].submit();
    });
  })();

  /* ── Lightbox global (imagen principal, galería, reseñas, comentarios, variantes) ── */
  (function () {
    var $lb = $(
      '<div id="prod-lightbox" style="display:none;position:fixed;inset:0;z-index:99999;' +
      'background:rgba(0,0,0,.85);cursor:zoom-out;align-items:center;justify-content:center;padding:16px;">' +
      '<img id="prod-lightbox-img" src="" style="max-width:90vw;max-height:88vh;border-radius:10px;' +
      'box-shadow:0 8px 48px rgba(0,0,0,.7);object-fit:contain;">' +
      '</div>'
    );
    $('body').append($lb);
    var $img = $lb.find('#prod-lightbox-img');

    function openLightbox(src) {
      $img.attr('src', src);
      $lb.css('display', 'flex');
    }

    /* Imágenes con clase js-zoomable */
    $(document).on('click', 'img.js-zoomable', function () {
      openLightbox($(this).attr('src'));
    });

    /* Botones/contenedores js-zoomable con data-src (galería CJ) */
    $(document).on('click', 'button.js-zoomable', function () {
      openLightbox($(this).data('src') || $(this).find('img').attr('src'));
    });

    $lb.on('click', function () { $lb.css('display', 'none'); });
    $(document).on('keydown.prodlb', function (e) { if (e.key === 'Escape') $lb.css('display', 'none'); });
  })();

})(jQuery);
</script>
@endpush
