@extends('layouts.admin')

@section('title', 'Productos — '.$store->name)
@section('heading', 'Productos')
@section('subheading', 'Catálogo de '.$store->name)

@section('content')
    @php
        $locales = $locales ?? [];
        $hasMiia = $has_miia ?? false;
        $currencies = $currencies ?? [];
        $localeCurrencyMap = $locale_currency_map ?? [];
        $filters = $filters ?? [
            'q' => '',
            'status' => '',
            'source' => '',
            'flag' => '',
            'sort' => 'newest',
            'per_page' => 20,
        ];
        $activeFilters = (int) ($active_filters ?? 0);
        $hasFilters = $activeFilters > 0 || ($filters['sort'] ?? 'newest') !== 'newest' || (int) ($filters['per_page'] ?? 20) !== 20;
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <div class="flex flex-wrap items-center gap-2">
            <form method="post" action="{{ route('admin.store.products.recalculate-prices') }}" class="inline" onsubmit="return confirm('¿Recalcular el precio sugerido de todos los productos (sin incluir envío)? No cambia el precio de venta hasta que lo apliques.');">
                @csrf
                <button type="submit" class="admin-btn-secondary">Recalcular sugeridos</button>
            </form>
            <a href="{{ route('admin.store.products.create') }}" class="admin-btn">Nuevo producto</a>
        </div>
    </div>

    <form method="get" action="{{ route('admin.store.products.index') }}" id="products-filter-form" class="admin-card mb-5 p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h2 class="font-display text-base font-bold text-ink">Buscar y filtrar</h2>
                @if($activeFilters > 0)
                    <span class="admin-badge bg-teal/10 text-teal">{{ $activeFilters }} activo(s)</span>
                @endif
            </div>
            @if($hasFilters)
                <a href="{{ route('admin.store.products.index') }}" class="text-xs text-ink-soft/60 hover:text-teal underline">Limpiar filtros</a>
            @endif
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div class="sm:col-span-2 lg:col-span-2 xl:col-span-2">
                <label class="mb-1 block text-[11px] font-medium text-ink-soft/60">Búsqueda</label>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="admin-input !py-1.5 text-sm"
                       placeholder="Nombre, SKU, ID, CJ PID, AliExpress ID…" autocomplete="off">
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium text-ink-soft/60">Estado</label>
                <select name="status" class="admin-input !py-1.5 text-sm">
                    <option value="">Todos</option>
                    <option value="live" @selected($filters['status'] === 'live')>Publicado</option>
                    <option value="draft" @selected($filters['status'] === 'draft')>Borrador</option>
                    <option value="paused" @selected($filters['status'] === 'paused')>Pausado</option>
                    <option value="archived" @selected($filters['status'] === 'archived')>Archivado</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium text-ink-soft/60">Origen</label>
                <select name="source" class="admin-input !py-1.5 text-sm">
                    <option value="">Todos</option>
                    <option value="cj" @selected($filters['source'] === 'cj')>CJ Dropshipping</option>
                    <option value="aliexpress" @selected($filters['source'] === 'aliexpress')>AliExpress</option>
                    <option value="manual" @selected($filters['source'] === 'manual')>Manual</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium text-ink-soft/60">Marca</label>
                <select name="flag" class="admin-input !py-1.5 text-sm">
                    <option value="">Todas</option>
                    <option value="star" @selected($filters['flag'] === 'star')>Producto estrella</option>
                    <option value="featured" @selected($filters['flag'] === 'featured')>Destacado</option>
                    <option value="has_variants" @selected($filters['flag'] === 'has_variants')>Con variantes</option>
                    <option value="no_variants" @selected($filters['flag'] === 'no_variants')>Sin variantes</option>
                    <option value="no_image" @selected($filters['flag'] === 'no_image')>Sin imagen</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium text-ink-soft/60">Ordenar</label>
                <select name="sort" class="admin-input !py-1.5 text-sm">
                    <option value="newest" @selected($filters['sort'] === 'newest')>Más recientes</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Más antiguos</option>
                    <option value="name_asc" @selected($filters['sort'] === 'name_asc')>Nombre A–Z</option>
                    <option value="name_desc" @selected($filters['sort'] === 'name_desc')>Nombre Z–A</option>
                    <option value="price_asc" @selected($filters['sort'] === 'price_asc')>Precio ↑</option>
                    <option value="price_desc" @selected($filters['sort'] === 'price_desc')>Precio ↓</option>
                    <option value="stock_desc" @selected($filters['sort'] === 'stock_desc')>Stock ↓</option>
                </select>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
            <div class="flex flex-wrap items-center gap-2 text-xs text-ink-soft/55">
                <label class="inline-flex items-center gap-1.5">
                    <span>Por página</span>
                    <select name="per_page" class="admin-input !py-1 !px-2 !w-auto text-xs">
                        <option value="20" @selected((int) $filters['per_page'] === 20)>20</option>
                        <option value="50" @selected((int) $filters['per_page'] === 50)>50</option>
                        <option value="100" @selected((int) $filters['per_page'] === 100)>100</option>
                    </select>
                </label>
                <span>·</span>
                <span>{{ $products->total() }} resultado{{ $products->total() === 1 ? '' : 's' }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="admin-btn !py-1.5 !px-3 text-sm">Filtrar</button>
            </div>
        </div>
    </form>

    {{-- La barra bulk se renderiza fuera del flujo del documento (ver @push al final) --}}

    <form method="post" action="{{ route('admin.store.products.bulk') }}" id="products-bulk-form">
        @csrf
        <input type="hidden" name="action" id="bulk-action" value="">
        <input type="hidden" name="locale" id="bulk-locale" value="">
        <input type="hidden" name="currency" id="bulk-currency" value="">
        <input type="hidden" name="status" id="bulk-status" value="">

        {{-- Placeholder invisible para que el form conserve su estructura --}}
        <div id="bulk-toolbar-placeholder" style="display:none"></div>

        <div class="admin-card overflow-hidden">
            <div class="border-b border-line px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-base font-bold text-ink">Catálogo</h2>
                <span class="text-xs text-ink-soft/55">
                    {{ $products->firstItem() ? $products->firstItem().'–'.$products->lastItem() : '0' }}
                    de {{ $products->total() }}
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                        <th class="w-8 px-3 py-2.5">
                            <input type="checkbox" id="bulk-check-all" class="rounded border-line text-teal focus:ring-teal/30" title="Seleccionar todos">
                        </th>
                        <th class="px-3 py-2.5 font-semibold">Producto</th>
                        <th class="px-3 py-2.5 font-semibold whitespace-nowrap">Var.</th>
                        <th class="px-3 py-2.5 font-semibold whitespace-nowrap">Precio</th>
                        <th class="px-3 py-2.5 font-semibold whitespace-nowrap">Stock</th>
                        <th class="px-3 py-2.5 font-semibold whitespace-nowrap">Estado</th>
                        <th class="px-3 py-2.5 font-semibold"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($products as $product)
                        @php
                            $translations = is_array(data_get($product->creative_data, 'translations'))
                                ? data_get($product->creative_data, 'translations')
                                : [];
                            $productLocales = [];
                            foreach ($translations as $locale => $row) {
                                if (! is_string($locale) || ! is_array($row)) {
                                    continue;
                                }
                                $hasContent = trim((string) ($row['name'] ?? '')) !== ''
                                    || trim((string) ($row['description'] ?? '')) !== '';
                                if ($hasContent) {
                                    $productLocales[] = $locale;
                                }
                            }
                            $defaultLocale = (string) data_get($product->creative_data, 'default_locale', '');
                            $variantsCount = (int) ($product->variants_count ?? 0);
                            if ($variantsCount === 0) {
                                $embedded = data_get($product->verified_data, 'variants', []);
                                if (is_array($embedded) && $embedded !== []) {
                                    $variantsCount = count($embedded);
                                }
                            }
                            $isCj = $product->isFromCj();
                            $isAe = $product->isFromAliExpress();
                            $storeViewUrl = ($product->slug && in_array($product->status, ['live', 'draft'], true))
                                ? route('store.design.page', ['slug' => $store->slug, 'handle' => $product->slug])
                                : null;
                        @endphp
                        <tr class="border-b border-line/70 last:border-0 hover:bg-mist/20 cursor-pointer" data-product-row>
                            <td class="px-3 py-2 align-middle">
                                <input
                                    type="checkbox"
                                    name="ids[]"
                                    value="{{ $product->id }}"
                                    class="bulk-row-check rounded border-line text-teal focus:ring-teal/30"
                                    data-cj="{{ $isCj ? '1' : '0' }}"
                                    data-ae="{{ $isAe ? '1' : '0' }}"
                                >
                            </td>
                            <td class="px-3 py-2" style="max-width:260px">
                                <div class="flex items-center gap-2">
                                    @if($product->image_url)
                                        <img src="{{ $product->image_url }}" alt="" class="h-8 w-8 shrink-0 rounded object-cover border border-line bg-mist" loading="lazy">
                                    @endif
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold text-ink leading-tight" style="overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:200px" title="{{ $product->name }}">
                                            {{ $product->name }}
                                        </div>
                                        <div class="flex items-center gap-1 mt-0.5" style="overflow:hidden;white-space:nowrap;max-width:200px">
                                            @if($product->sku)
                                                <span class="text-[11px] text-ink-soft/55" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $product->sku }}</span>
                                            @endif
                                            @if($isCj)
                                                <span class="admin-badge bg-amber/10 text-amber !text-[10px] shrink-0" title="CJ Dropshipping">CJ</span>
                                            @elseif($isAe)
                                                <span class="admin-badge bg-orange-50 text-orange-600 !text-[10px] shrink-0" title="AliExpress">AE</span>
                                            @endif
                                            @if($store->isStarProduct($product))
                                                <span class="admin-badge bg-amber/15 text-amber !text-[10px] shrink-0">★</span>
                                            @elseif($product->is_featured)
                                                <span class="admin-badge bg-teal/10 text-teal !text-[10px] shrink-0">★</span>
                                            @endif
                                            @if($productLocales)
                                                @foreach(array_slice($productLocales, 0, 3) as $locale)
                                                    <span class="admin-badge shrink-0 !text-[9px] !px-1 {{ $locale === $defaultLocale ? 'bg-teal/10 text-teal' : 'bg-mist text-ink-soft' }}">{{ $locale }}</span>
                                                @endforeach
                                                @if(count($productLocales) > 3)
                                                    <span class="text-[10px] text-ink-soft/50">+{{ count($productLocales) - 3 }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="admin-badge {{ $variantsCount > 0 ? 'bg-teal/10 text-teal' : 'bg-mist text-ink-soft' }}">
                                    {{ $variantsCount }}
                                </span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-sm">
                                {{ $product->currency }} {{ number_format((float) $product->price, 2) }}
                            </td>
                            <td class="px-3 py-2 text-sm">{{ $product->stock ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @php
                                    $stBadge = match($product->status) {
                                        'live'     => 'bg-teal/10 text-teal',
                                        'draft'    => 'bg-mist text-ink-soft',
                                        'paused'   => 'bg-amber/10 text-amber',
                                        'archived' => 'bg-red-50 text-red-400',
                                        default    => 'bg-mist text-ink-soft',
                                    };
                                    $stLabel = match($product->status) {
                                        'live'     => 'Publicado',
                                        'draft'    => 'Borrador',
                                        'paused'   => 'Pausado',
                                        'archived' => 'Archivado',
                                        default    => $product->status,
                                    };
                                @endphp
                                <span class="admin-badge {{ $stBadge }}">{{ $stLabel }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    @if($storeViewUrl)
                                        <a href="{{ $storeViewUrl }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="admin-btn-secondary !px-2 !py-1 text-xs inline-flex items-center justify-center"
                                           title="Ver en tienda"
                                           aria-label="Ver en tienda">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>
                                    @else
                                        <span class="inline-flex h-[26px] w-[26px] items-center justify-center rounded border border-line/40 text-ink-soft/25 cursor-not-allowed"
                                              title="{{ $product->slug ? 'Publica el producto (live o borrador) para verlo en tienda' : 'Sin slug — guarda el producto primero' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </span>
                                    @endif
                                    <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" href="{{ route('admin.store.products.edit', $product) }}">Editar</a>
                                    <button type="button" class="admin-btn-danger !px-2.5 !py-1 text-xs" data-single-delete="{{ $product->id }}">✕</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-ink-soft/60">
                                @if($hasFilters)
                                    Ningún producto coincide con los filtros.
                                    <a href="{{ route('admin.store.products.index') }}" class="text-teal underline">Limpiar filtros</a>
                                @else
                                    Sin productos en este sitio.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($products->hasPages())
                <div class="flex justify-between border-t border-line px-4 py-3 text-sm">
                    <a href="{{ $products->previousPageUrl() }}" class="{{ $products->onFirstPage() ? 'pointer-events-none text-ink-soft/30' : 'text-teal' }}">Anterior</a>
                    <span class="text-ink-soft/60">{{ $products->currentPage() }}/{{ $products->lastPage() }}</span>
                    <a href="{{ $products->nextPageUrl() }}" class="{{ $products->hasMorePages() ? 'text-teal' : 'pointer-events-none text-ink-soft/30' }}">Siguiente</a>
                </div>
            @endif
        </div>
    </form>

    {{-- Eliminar individual (fuera del form bulk para no anidar forms) --}}
    <form id="single-delete-form" method="post" class="hidden">
        @csrf
        @method('DELETE')
    </form>
@endsection

@push('scripts')
<script>
(function ($) {
  var $form       = $('#products-bulk-form');
  var $checkAll   = $('#bulk-check-all');
  var destroyBase = @json(url('/admin/store/products'));
  var localeCurrencyMap = @json($localeCurrencyMap);
  var hasMiia = @json($hasMiia);
  var localesJson = @json($locales);
  var currenciesJson = @json($currencies);

  $('#products-filter-form').on('change', 'select', function () {
    $('#products-filter-form').trigger('submit');
  });

  /* ── 1. Crear el toolbar como overlay en el <body> ───────────── */
  var localeOpts = '<option value="">Idioma\u2026</option>';
  $.each(localesJson, function (_, loc) {
    localeOpts += '<option value="' + loc.locale + '">' + loc.label + '</option>';
  });
  var currencyOpts = '<option value="__auto__">Moneda auto</option><option value="">Sin convertir</option>';
  $.each(currenciesJson, function (_, row) {
    currencyOpts += '<option value="' + row.code + '">' + row.code + '</option>';
  });
  var toolbarHtml = [
    '<div id="bulk-toolbar" style="',
      'display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;',
      'background:#f0fdfa;border-top:1.5px solid rgba(20,184,166,.3);',
      'box-shadow:0 -4px 24px rgba(15,118,110,.15);',
      'padding:10px 20px;gap:8px;flex-wrap:wrap;align-items:center;',
    '">',
      '<span style="font-size:13px;font-weight:600;color:#0f172a;">',
        '<span id="bulk-count">0</span> seleccionado(s)',
      '</span>',
      '<span style="width:1px;height:16px;background:#e2e8f0;display:inline-block;"></span>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="status" data-status="live">Publicar</button>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="status" data-status="draft">Borrador</button>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="status" data-status="paused">Pausar</button>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="status" data-status="archived">Archivar</button>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="sync_cj" title="Solo productos importados de CJ">Sincronizar CJ</button>',
      '<select id="bulk-translate-locale" class="admin-input" style="width:auto;padding:4px 8px;font-size:12px;"' + (hasMiia ? '' : ' disabled') + '>',
        localeOpts,
      '</select>',
      '<select id="bulk-translate-currency" class="admin-input" style="width:auto;padding:4px 8px;font-size:12px;"' + (hasMiia ? '' : ' disabled') + '>',
        currencyOpts,
      '</select>',
      '<button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-bulk="translate"' + (hasMiia ? '' : ' disabled title="Configura MIIA en General"') + '>Traducir</button>',
      '<button type="button" class="admin-btn-danger !px-3 !py-1.5 text-xs" style="margin-left:auto" data-bulk="delete">Eliminar</button>',
    '</div>'
  ].join('');

  $('body').append(toolbarHtml);
  var $toolbar = $('#bulk-toolbar');
  var $count   = $('#bulk-count');

  /* ── 2. Helpers ────────────────────────────────────────────────── */
  function selectedChecks() {
    return $form.find('.bulk-row-check:checked');
  }

  function refreshToolbar() {
    var n = selectedChecks().length;
    $count.text(n);
    if (n > 0) {
      $toolbar.css('display', 'flex');
    } else {
      $toolbar.css('display', 'none');
    }
    var total = $form.find('.bulk-row-check').length;
    $checkAll.prop('checked', total > 0 && n === total);
    $checkAll.prop('indeterminate', n > 0 && n < total);
  }

  /* ── 3. Eventos de selección ───────────────────────────────────── */
  $checkAll.on('change', function () {
    $form.find('.bulk-row-check').prop('checked', this.checked);
    refreshToolbar();
  });

  $form.on('change', '.bulk-row-check', refreshToolbar);

  $form.on('click', '[data-product-row]', function (e) {
    var $t = $(e.target);
    if ($t.is('a, button, input, select, label') || $t.closest('a, button, input, select').length) return;
    var $cb = $(this).find('.bulk-row-check');
    $cb.prop('checked', !$cb.prop('checked'));
    refreshToolbar();
  });

  /* ── 4. Eliminar unitario ──────────────────────────────────────── */
  $('[data-single-delete]').on('click', function () {
    if (!confirm('¿Eliminar producto?')) return;
    var id = $(this).data('single-delete');
    var $f = $('#single-delete-form');
    $f.attr('action', destroyBase + '/' + id);
    $f.trigger('submit');
  });

  /* ── 5. Acciones bulk ─────────────────────────────────────────── */
  $toolbar.on('change', '#bulk-translate-locale', function () {
    var locale = String($(this).val() || '');
    var $cur = $toolbar.find('#bulk-translate-currency');
    if ($cur.val() === '__auto__') {
      $cur.data('suggested', localeCurrencyMap[locale] || '');
    }
  });

  $toolbar.on('click', '[data-bulk]', function () {
    var action = $(this).data('bulk');
    var n = selectedChecks().length;
    if (!n) { alert('Selecciona al menos un producto.'); return; }

    $('#bulk-action').val(action);
    $('#bulk-locale').val('');
    $('#bulk-currency').val('');
    $('#bulk-status').val('');

    if (action === 'delete') {
      if (!confirm('¿Eliminar ' + n + ' producto(s)? Esta acción no se puede deshacer.')) return;
    } else if (action === 'status') {
      var st = String($(this).data('status') || '');
      if (!st) return;
      if (!confirm('¿Cambiar estado a «' + st + '» en ' + n + ' producto(s)?')) return;
      $('#bulk-status').val(st);
    } else if (action === 'sync_cj') {
      var cj = selectedChecks().filter(function () { return $(this).data('cj') == 1; }).length;
      if (cj === 0) { alert('Ninguno de los seleccionados es de CJ.'); return; }
      if (!confirm('¿Sincronizar ' + cj + ' producto(s) CJ? (se omitirán los que no sean CJ)')) return;
    } else if (action === 'translate') {
      var locale = String($toolbar.find('#bulk-translate-locale').val() || '');
      if (!locale) { alert('Elige un idioma para traducir.'); return; }
      var curSel = String($toolbar.find('#bulk-translate-currency').val());
      var currency = curSel === '__auto__' ? (localeCurrencyMap[locale] || '') : (curSel || '');
      var msg = '¿Traducir ' + n + ' producto(s) a ' + locale + ' con MIIA?';
      msg += currency ? '\nTambién convertir precios a ' + currency + '.' : '\nSin conversión de moneda.';
      if (!confirm(msg)) return;
      $('#bulk-locale').val(locale);
      $('#bulk-currency').val(currency);
    }

    $form.trigger('submit');
  });

  refreshToolbar();
})(jQuery);
</script>
@endpush
