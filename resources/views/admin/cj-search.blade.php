@extends('layouts.admin')

@section('title', 'Product Hunter')
@section('heading', 'Product Hunter')
@section('subheading', 'AliExpress o CJ: extrae la ficha, busca coincidencias en CJ y elige qué importar')

@section('content')
    @php
        $mode = $mode ?? 'prompt';
        $totalPages = $perPage > 0 ? (int) ceil(($total ?? 0) / $perPage) : 1;
        $flag = $market ? $market->flagOrFallback() : '🏳️';
        $showResults = ($mode === 'prompt' && ($prompt ?? '') !== '') || ($mode === 'keyword' && ($q ?? '') !== '');
        $cjIso = strtolower((string) ($countryCode ?? ''));
        if ($cjIso === 'uk') {
            $cjIso = 'gb';
        }
        if (strlen($cjIso) !== 2) {
            $cjIso = '';
        }
    @endphp

    <div class="admin-card p-5 sm:p-6 mb-5 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink">Product Hunter</h2>
        <div class="flex flex-wrap items-center gap-2 text-sm text-ink-soft">
            <span class="admin-badge bg-mist text-ink-soft">Tienda activa</span>
            <span class="font-semibold text-ink">{{ $store?->name ?? '—' }}</span>
            <span class="text-ink-soft/40">·</span>
            <span class="inline-flex items-center gap-1.5">
                @if($cjIso)
                    <span class="market-flag fi fi-{{ $cjIso }}" title="{{ strtoupper($cjIso) }}"></span>
                @else
                    <span>{{ $flag }}</span>
                @endif
                {{ $market?->name ?? 'Sin mercado' }}
            </span>
            <span class="text-ink-soft/40">·</span>
            <span class="inline-flex items-center gap-1.5">
                País CJ:
                @if($cjIso)
                    <span class="market-flag fi fi-{{ $cjIso }}" title="{{ strtoupper($countryCode) }}"></span>
                @else
                    <code class="text-xs">{{ $countryCode }}</code>
                @endif
            </span>
            <span class="text-ink-soft/40">·</span>
            <span>Moneda sitio: <strong>{{ $market?->currency ?? '—' }}</strong></span>
            <span class="text-ink-soft/40">·</span>
            <span class="text-xs text-ink-soft/55">Búsqueda catálogo global (MX sin almacén CJ local)</span>
        </div>

        <div class="rounded-xl border border-line bg-mist/30 p-3 sm:p-4 space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <label for="cj-crawl-url" class="text-sm font-medium text-ink-soft">URL AliExpress, CJ, PID o SKU</label>
                <span class="text-[11px] text-ink-soft/50">AliExpress → busca en CJ · CJ → detalle directo</span>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                <input type="text" id="cj-crawl-url" class="admin-input flex-1"
                       placeholder="https://es.aliexpress.com/item/10050….html  o  URL/PID de CJ"
                       autocomplete="off">
                <button type="button" id="cj-crawl-btn" class="admin-btn shrink-0">
                    Buscar
                </button>
            </div>
            <p id="cj-crawl-status" class="hidden text-xs text-ink-soft/60"></p>
        </div>

        <details class="rounded-xl border border-line bg-white p-3 sm:p-4" id="ph-html-box">
            <summary class="cursor-pointer text-sm font-medium text-ink">Pegar HTML de AliExpress / plugin Chrome</summary>
            <p class="mt-2 text-xs text-ink-soft/70">
                Si Cloudflare o el scrape fallan: abre la ficha en el navegador, copia el HTML (o usa el plugin) y pégalo aquí.
                Extrae título, precio, envío, variantes y reseñas cuando vengan en la página.
            </p>
            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <div class="space-y-2">
                    <label class="text-xs font-medium text-ink-soft">HTML o JSON del plugin</label>
                    <textarea id="ph-html-input" rows="7" class="admin-input font-mono text-xs" placeholder="Pega el HTML de la ficha, o el JSON {url, html, snapshot}"></textarea>
                    <input type="url" id="ph-html-url" class="admin-input text-sm" placeholder="URL del item (opcional si ya va en el HTML)">
                    <p class="text-[11px] text-ink-soft/55">Tip: en AliExpress, abre la pestaña <strong>Descripción</strong> (#nav-description) y espera a que cargue el contenido antes de copiar el HTML. El enlace del menú solo no basta.</p>
                    <button type="button" id="ph-html-btn" class="admin-btn">Parsear HTML</button>
                </div>
                <div class="space-y-2 rounded-xl border border-dashed border-line bg-mist/30 p-3 text-xs text-ink-soft/80">
                    <p class="font-semibold text-ink">Plugin Chrome</p>
                    <ol class="list-decimal space-y-1 pl-4">
                        <li>Descarga el ZIP e instálalo en <code>chrome://extensions</code> (modo desarrollador → cargar descomprimida).</li>
                        <li>Copia el token, ábrelo en el popup de la extensión y pulsa Guardar.</li>
                        <li>En una ficha AliExpress pulsa <strong>Enviar a Product Hunter</strong>.</li>
                    </ol>
                    <p class="break-all">Origen: <code>{{ $pluginOrigin ?? url('/') }}</code></p>
                    <p>Token: <code id="ph-plugin-token" class="select-all">{{ $pluginToken ?? '' }}</code>
                        <button type="button" class="ml-1 text-teal hover:underline" id="ph-copy-token">Copiar</button>
                    </p>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <a href="{{ $pluginDownloadUrl ?? route('admin.lab.cj.extension') }}" class="admin-btn !px-3 !py-1.5 text-xs">Descargar plugin (.zip)</a>
                        <form method="post" action="{{ route('admin.lab.cj.plugin-token') }}" onsubmit="return confirm('Se invalidará el token anterior en la extensión.');">
                            @csrf
                            <button type="submit" class="admin-btn-secondary !px-3 !py-1.5 text-xs">Regenerar token</button>
                        </form>
                    </div>
                </div>
            </div>
        </details>

        <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft/45">Buscar solo en CJ</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.lab.cj', ['mode' => 'prompt']) }}"
               class="admin-badge {{ $mode === 'prompt' ? 'bg-teal/10 text-teal ring-1 ring-teal/20' : 'bg-mist text-ink-soft' }}">
                MIIA + MCP
            </a>
            <a href="{{ route('admin.lab.cj', ['mode' => 'keyword']) }}"
               class="admin-badge {{ $mode === 'keyword' ? 'bg-teal/10 text-teal ring-1 ring-teal/20' : 'bg-mist text-ink-soft' }}">
                Keyword directa
            </a>
        </div>

        @if($mode === 'prompt')
            @if(! ($has_miia ?? false))
                <div class="rounded-xl border border-amber/30 bg-amber/10 px-4 py-3 text-sm text-amber">
                    Configura MIIA en
                    <a href="{{ route('admin.settings.general') }}" class="font-semibold underline">General</a>
                    para el plan de keywords y para mejorar prompts.
                </div>
            @endif
            @if(! $has_cj_token)
                <div class="rounded-xl border border-amber/30 bg-amber/10 px-4 py-3 text-sm text-amber">
                    Autoriza la API Key de CJ en
                    <a href="{{ route('admin.settings.general') }}" class="font-semibold underline">General</a>
                    para obtener el token MCP.
                </div>
            @endif

            <form method="post" action="{{ route('admin.lab.cj.run') }}" class="space-y-3" id="cj-prompt-form">
                @csrf
                <input type="hidden" name="mode" value="prompt">
                <div>
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-sm font-medium text-ink-soft">Prompt para MIIA</label>
                        <button type="button"
                                id="cj-improve-prompt"
                                class="admin-btn-secondary !px-3 !py-1.5 text-xs"
                                @disabled(! ($has_miia ?? false))
                                title="Mejorar con MIIA (ia.ceballosleon.com)">
                            ✨ Mejorar con MIIA
                        </button>
                    </div>
                    <textarea name="prompt" id="cj-prompt-input" rows="8" required class="admin-input"
                              placeholder="Ej. Necesito power banks de 20000mAh baratos para vender en México, con buen margen y envío razonable">{{ $prompt ?? '' }}</textarea>
                    <p id="cj-improve-status" class="mt-1.5 hidden text-xs text-ink-soft/60"></p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-full sm:w-32">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Cantidad</label>
                        <select name="per_page" class="admin-input">
                            @foreach([12, 20, 24, 40] as $n)
                                <option value="{{ $n }}" @selected(($perPage ?? 20) === $n)>{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="admin-btn" @disabled(! ($has_miia ?? false) || ! $has_cj_token)>
                        Preguntar con MCP
                    </button>
                </div>
                <p class="text-xs text-ink-soft/55">
                    Usa <strong>Mejorar con MIIA</strong> para reescribir tu brief; luego MIIA genera 5–8 keywords y busca en CJ.
                </p>
            </form>
        @else
            <form method="get" action="{{ route('admin.lab.cj') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <input type="hidden" name="mode" value="keyword">
                <div class="flex-1">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Keyword</label>
                    <input type="text" name="q" value="{{ $q ?? '' }}" required class="admin-input" placeholder="power bank…">
                </div>
                <div class="w-full sm:w-28">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Página</label>
                    <input type="number" name="page" min="1" value="{{ $page ?? 1 }}" class="admin-input">
                </div>
                <div class="w-full sm:w-32">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Por página</label>
                    <select name="per_page" class="admin-input">
                        @foreach([12, 20, 24, 40] as $n)
                            <option value="{{ $n }}" @selected(($perPage ?? 20) === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="admin-btn">Buscar en CJ</button>
            </form>
        @endif
    </div>

    <div id="ph-hunt" class="hidden mb-5 space-y-4">
        <div class="admin-card p-5 sm:p-6 space-y-4" id="ph-ae-card" data-collapse-default="0">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Ficha AliExpress</h2>
                    <p id="ph-ae-meta" class="mt-1 text-xs text-ink-soft/60"></p>
                </div>
                <span id="ph-ae-mode" class="admin-badge bg-mist text-ink-soft"></span>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,320px)_1fr]">
                <div class="space-y-3">
                    <img id="ph-ae-img" src="" alt="" class="ph-ae-zoom aspect-square w-full cursor-zoom-in rounded-xl border border-line object-contain bg-white">
                    <div id="ph-ae-thumbs" class="flex flex-wrap gap-1.5"></div>
                    <div>
                        <h3 class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Videos</h3>
                        <div id="ph-ae-videos" class="space-y-2"></div>
                    </div>
                </div>
                <div class="min-w-0 flex flex-col gap-4">
                    <h3 id="ph-ae-title" class="font-display text-lg sm:text-xl font-bold text-ink leading-snug"></h3>

                    <div class="overflow-hidden rounded-2xl border border-line bg-white shadow-sm">
                        <div class="border-b border-line bg-gradient-to-br from-teal/5 via-white to-mist/30 px-4 py-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Precio</p>
                            <p id="ph-ae-price" class="mt-0.5 text-2xl font-bold text-teal"></p>
                        </div>
                        <div class="grid divide-y border-line sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                            <div class="px-4 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Envío</p>
                                <p id="ph-ae-ship" class="mt-1 text-sm font-medium text-ink"></p>
                            </div>
                            <div class="px-4 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Entrega estimada</p>
                                <p id="ph-ae-ship-time" class="mt-1 text-sm text-ink-soft"></p>
                            </div>
                        </div>
                    </div>

                    <div id="ph-ae-badges" class="flex flex-wrap gap-2"></div>
                </div>
            </div>

            <div id="ph-ae-variants-wrap">
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <h3 id="ph-ae-variants-title" class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Variaciones</h3>
                    <span id="ph-ae-variant-count" class="text-[11px] text-ink-soft/50"></span>
                </div>
                <div id="ph-ae-attributes" class="hidden mb-3 grid gap-2 sm:grid-cols-2"></div>
                <div id="ph-ae-variants-table-wrap" class="overflow-auto rounded-xl border border-line">
                    <table class="min-w-full text-xs">
                        <thead class="bg-mist/60 text-ink-soft/70">
                            <tr>
                                <th class="px-2 py-1.5 text-left font-medium">Img</th>
                                <th class="px-2 py-1.5 text-left font-medium">Variante</th>
                                <th class="px-2 py-1.5 text-left font-medium">SKU</th>
                                <th class="px-2 py-1.5 text-left font-medium">Precio</th>
                                <th class="px-2 py-1.5 text-left font-medium">Stock</th>
                            </tr>
                        </thead>
                        <tbody id="ph-ae-variants"></tbody>
                    </table>
                </div>
            </div>

            <div id="ph-ae-details-wrap" class="hidden">
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <button type="button" class="ph-ae-collapse-btn group inline-flex items-center gap-1.5 text-left" data-target="ph-ae-details-body" aria-expanded="true">
                        <i class="fa-solid fa-chevron-down text-[10px] text-ink-soft/50 transition-transform group-[.is-collapsed]:-rotate-90"></i>
                        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Detalles</h3>
                    </button>
                    <div class="flex items-center gap-2">
                        <span id="ph-ae-details-count" class="text-[11px] text-ink-soft/50"></span>
                        <button type="button" class="ph-ae-collapse-link text-[11px] text-teal hover:underline" data-target="ph-ae-details-body">Contraer</button>
                    </div>
                </div>
                <div id="ph-ae-details-body" class="overflow-auto rounded-xl border border-line">
                    <table class="min-w-full text-xs">
                        <tbody id="ph-ae-details" class="divide-y divide-line/60"></tbody>
                    </table>
                </div>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <button type="button" class="ph-ae-collapse-btn group inline-flex items-center gap-1.5 text-left" data-target="ph-ae-desc" aria-expanded="true">
                        <i class="fa-solid fa-chevron-down text-[10px] text-ink-soft/50 transition-transform group-[.is-collapsed]:-rotate-90"></i>
                        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Descripción</h3>
                    </button>
                    <button type="button" class="ph-ae-collapse-link text-[11px] text-teal hover:underline" data-target="ph-ae-desc">Contraer</button>
                </div>
                <div id="ph-ae-desc" class="ph-ae-desc max-h-[32rem] overflow-auto rounded-xl border border-line bg-white p-4 text-sm text-ink leading-relaxed"></div>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <button type="button" class="ph-ae-collapse-btn group inline-flex items-center gap-1.5 text-left" data-target="ph-ae-reviews" aria-expanded="true">
                        <i class="fa-solid fa-chevron-down text-[10px] text-ink-soft/50 transition-transform group-[.is-collapsed]:-rotate-90"></i>
                        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">Reseñas</h3>
                    </button>
                    <div class="flex items-center gap-2">
                        <span id="ph-ae-review-count" class="text-[11px] text-ink-soft/50"></span>
                        <button type="button" class="ph-ae-collapse-link text-[11px] text-teal hover:underline" data-target="ph-ae-reviews">Contraer</button>
                    </div>
                </div>
                <div id="ph-ae-reviews" class="max-h-[28rem] overflow-auto space-y-2"></div>
            </div>

            <label class="flex items-center gap-2 rounded-xl border border-line bg-mist/40 px-3 py-2 text-sm cursor-pointer">
                <input type="radio" name="ph-choice" id="ph-choice-ae" value="aliexpress" checked>
                <span>Ninguno me gusta — importar AliExpress (cumplimiento manual)</span>
            </label>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-lg font-bold text-ink">Coincidencias en CJ</h2>
                <span id="ph-cj-count" class="admin-badge bg-mist text-ink-soft">0</span>
            </div>
            <p id="ph-cj-empty" class="hidden text-sm text-ink-soft/60">No encontré un equivalente claro en CJ. Puedes importar AliExpress o buscar por keyword abajo.</p>
            <div id="ph-cj-grid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" id="ph-import-btn" class="admin-btn">+ Catálogo</button>
            <p id="ph-import-status" class="hidden text-xs text-ink-soft/60"></p>
        </div>
    </div>

    @if($error)
        <div class="mb-4 rounded-2xl border border-coral/20 bg-coral/10 px-4 py-3 text-sm text-coral">{{ $error }}</div>
    @endif

    @if($showResults)
        @if(!empty($answer))
            <div class="admin-card mb-4 p-5 sm:p-6">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-lg font-bold text-ink">Respuesta MIIA</h2>
                    @if($provider)
                        <span class="admin-badge bg-teal/10 text-teal">{{ $provider }}</span>
                    @endif
                    @if($via)
                        <span class="admin-badge bg-mist text-ink-soft">vía {{ $via }}</span>
                    @endif
                    @if(!empty($keywords) && is_array($keywords))
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach($keywords as $kw)
                                <span class="admin-badge bg-mist text-ink-soft">{{ $kw }}</span>
                            @endforeach
                        </div>
                    @elseif($keyword)
                        <span class="admin-badge bg-mist text-ink-soft">keyword: {{ $keyword }}</span>
                    @endif
                </div>
                <p class="text-sm text-ink-soft leading-relaxed whitespace-pre-wrap">{{ $answer }}</p>
            </div>
        @endif

        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="font-display text-lg font-bold text-ink">Productos</h2>
                <p class="text-sm text-ink-soft/65">
                    {{ number_format($total) }} resultados
                    @if($mode === 'keyword')
                        · página {{ $page }} de {{ max(1, $totalPages) }} · «{{ $q }}»
                    @elseif(!empty($keywords) && is_array($keywords))
                        · {{ count($keywords) }} keywords del prompt
                    @elseif($keyword)
                        · «{{ $keyword }}»
                    @endif
                </p>
                @if($mode === 'prompt' && !empty($keywords) && is_array($keywords))
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach($keywords as $kw)
                            <span class="rounded-md border border-line bg-white px-1.5 py-0.5 text-[10px] text-ink-soft">{{ $kw }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-3">
                @if($products->isNotEmpty())
                    <div>
                        <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-ink-soft/55">Moneda de cobro</label>
                        <select id="cj-display-currency" class="admin-input !py-1.5 !text-sm w-36">
                            @foreach(($currencies ?? ['USD','MXN']) as $cur)
                                <option value="{{ $cur }}" @selected(($displayCurrency ?? 'MXN') === $cur)>{{ $cur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <details class="text-xs text-ink-soft/60">
                        <summary class="cursor-pointer hover:text-teal">Detalle técnico</summary>
                        <pre class="mt-2 max-h-64 max-w-xl overflow-auto rounded-xl bg-ink p-3 text-[10px] text-emerald-200">{{ json_encode(['via' => $via ?? null, 'trace' => $tool_trace ?? [], 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                @endif
            </div>
        </div>

        @if($products->isEmpty())
            <div class="admin-card p-8 text-center text-sm text-ink-soft/60">Sin productos para esta búsqueda / país.</div>
        @else
            <p class="mb-3 text-[11px] text-ink-soft/55">
                Precio sugerido ≈ costo + envío estimado + fees (~4.5%) con margen objetivo ~42%. El envío es estimado por peso (no cotización CJ real).
            </p>
            <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                @foreach($products as $p)
                    @php
                        $pr = $p['pricing'] ?? [];
                        $inCatalog = in_array((string) ($p['pid'] ?? ''), $catalogPids ?? [], true);
                    @endphp
                    <article
                        class="admin-card flex gap-2.5 overflow-hidden !rounded-xl p-2"
                        data-cj-card
                        data-pid="{{ $p['pid'] }}"
                        data-sku="{{ $p['sku'] }}"
                        data-title="{{ $p['title'] }}"
                        data-image="{{ $p['image'] }}"
                        data-category="{{ $p['category'] }}"
                        data-cj-url="{{ $p['cj_url'] }}"
                        data-weight="{{ $p['weight'] }}"
                        data-has-video="{{ !empty($p['has_video']) ? '1' : '0' }}"
                        data-price-usd="{{ $p['price'] ?? '' }}"
                        data-cost-usd="{{ $pr['cost_usd'] ?? '' }}"
                        data-ship-usd="{{ $pr['ship_usd'] ?? '' }}"
                        data-fees-usd="{{ $pr['fees_usd'] ?? '' }}"
                        data-sell-usd="{{ $pr['sell_usd'] ?? '' }}"
                        data-profit-usd="{{ $pr['profit_usd'] ?? '' }}"
                        data-margin-pct="{{ $pr['margin_pct'] ?? '' }}"
                        data-in-catalog="{{ $inCatalog ? '1' : '0' }}"
                    >
                        <button type="button"
                                class="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-mist cj-zoom-image {{ $p['image'] ? 'cursor-zoom-in' : 'cursor-default' }}"
                                @if($p['image']) data-full-image="{{ $p['image'] }}" data-full-title="{{ $p['title'] }}" @endif
                                title="Ver galería">
                            @if($p['image'])
                                <img src="{{ $p['image'] }}" alt="" class="h-full w-full object-cover pointer-events-none" loading="lazy">
                            @else
                                <div class="flex h-full items-center justify-center text-[10px] text-ink-soft/40">N/A</div>
                            @endif
                            @if($p['free_shipping'])
                                <span class="absolute left-0.5 top-0.5 rounded bg-teal/90 px-1 text-[9px] font-semibold text-white">FS</span>
                            @endif
                            @if(!empty($p['has_video']))
                                <span class="absolute bottom-0.5 right-0.5 rounded bg-ink/80 px-1 text-[9px] font-semibold text-white pointer-events-none" title="Tiene video en CJ">▶</span>
                            @endif
                            <span class="absolute left-0.5 bottom-0.5 rounded bg-ink/80 px-1 text-[9px] font-semibold text-white cj-image-count" title="Imágenes">
                                📷 {{ (int) ($p['image_count'] ?? ($p['image'] ? 1 : 0)) }}
                            </span>
                        </button>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center gap-1">
                                <div class="min-w-0 flex-1 text-[10px] font-semibold uppercase tracking-wide text-ink-soft/40 truncate">{{ $p['category'] ?: '—' }}</div>
                                @if(!empty($p['has_video']))
                                    <button type="button" class="shrink-0 rounded bg-sky-100 px-1 py-0.5 text-[9px] font-semibold text-sky-800 cj-play-video hover:bg-sky-200" title="Ver video">
                                        ▶ Video
                                    </button>
                                @endif
                            </div>
                            <h3 class="font-display text-[12px] font-bold leading-snug text-ink line-clamp-2" title="{{ $p['title'] }}">{{ $p['title'] }}</h3>
                            @if(!empty($p['matched_keyword']))
                                <div class="text-[10px] text-teal/80 truncate" title="{{ $p['matched_keyword'] }}">kw: {{ $p['matched_keyword'] }}</div>
                            @endif
                            <div class="text-[10px] text-ink-soft/45 truncate">
                                SKU {{ $p['sku'] ?: '—' }}
                                @if($p['weight']) · {{ $p['weight'] }}g @endif
                            </div>

                            <div class="rounded-lg border border-line/70 bg-mist/40 px-1.5 py-1 text-[10px] leading-tight">
                                <div class="flex justify-between gap-2"><span class="text-ink-soft/55">Costo CJ</span><span data-money="cost">—</span></div>
                                <div class="flex justify-between gap-2"><span class="text-ink-soft/55">Envío est.</span><span data-money="ship">—</span></div>
                                <div class="flex justify-between gap-2"><span class="text-ink-soft/55">Fees</span><span data-money="fees">—</span></div>
                                <div class="my-0.5 border-t border-line/60"></div>
                                <div class="flex justify-between gap-2 font-semibold text-teal"><span>Precio sugerido</span><span data-money="sell">—</span></div>
                                <div class="flex justify-between gap-2 font-semibold text-ink"><span>Ganancia</span><span data-money="profit">—</span></div>
                                <div class="flex justify-between gap-2 text-ink-soft/55"><span>Margen</span><span data-margin>—</span></div>
                            </div>

                            <div class="flex flex-wrap gap-1 pt-0.5">
                                @if(!empty($p['has_video']))
                                    <button type="button" class="admin-btn-secondary !px-2 !py-1 text-[10px] cj-play-video">▶ Ver video</button>
                                @endif
                                @if($p['cj_url'])
                                    <a href="{{ $p['cj_url'] }}" target="_blank" rel="noopener" class="admin-btn-secondary !px-2 !py-1 text-[10px]">CJ ↗</a>
                                @endif
                                <button type="button" class="admin-btn-secondary !px-2 !py-1 text-[10px] cj-copy-pid" data-pid="{{ $p['pid'] }}">PID</button>
                                <button type="button"
                                        class="admin-btn !px-2 !py-1 text-[10px] cj-add-catalog {{ $inCatalog ? '!bg-ink-soft/40' : '' }}"
                                        @disabled($inCatalog || !($store ?? null))
                                        data-in-catalog="{{ $inCatalog ? '1' : '0' }}">
                                    {{ $inCatalog ? 'En catálogo' : '+ Catálogo' }}
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            @if(!($store ?? null))
                <p class="mt-3 text-xs text-amber">Selecciona una tienda activa para poder agregar productos al catálogo.</p>
            @endif

            @if($mode === 'keyword' && $totalPages > 1)
                <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                    @if($page > 1)
                        <a class="admin-btn-secondary" href="{{ route('admin.lab.cj', ['mode' => 'keyword', 'q' => $q, 'page' => $page - 1, 'per_page' => $perPage]) }}">← Anterior</a>
                    @endif
                    <span class="text-sm text-ink-soft">Pág. {{ $page }} / {{ $totalPages }}</span>
                    @if($page < $totalPages)
                        <a class="admin-btn-secondary" href="{{ route('admin.lab.cj', ['mode' => 'keyword', 'q' => $q, 'page' => $page + 1, 'per_page' => $perPage]) }}">Siguiente →</a>
                    @endif
                </div>
            @endif
        @endif
    @endif

    {{-- Modal imagen / carrusel --}}
    <div id="cj-image-modal" class="fixed inset-0 z-[80] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true">
        <div class="relative max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div class="min-w-0">
                    <div id="cj-image-modal-title" class="truncate text-sm font-semibold text-ink"></div>
                    <div id="cj-image-modal-counter" class="text-[11px] text-ink-soft/60"></div>
                </div>
                <button type="button" id="cj-image-modal-close" class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div class="relative bg-mist/40 p-3 sm:p-4">
                <div id="cj-image-modal-loading" class="hidden py-16 text-center text-sm text-ink-soft/60">Cargando galería…</div>
                <img id="cj-image-modal-img" src="" alt="" class="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain">
                <button type="button" id="cj-image-prev" class="absolute left-2 top-1/2 hidden -translate-y-1/2 rounded-full border border-line bg-white/95 px-3 py-2 text-sm font-bold text-ink shadow hover:border-teal/40" aria-label="Anterior">‹</button>
                <button type="button" id="cj-image-next" class="absolute right-2 top-1/2 hidden -translate-y-1/2 rounded-full border border-line bg-white/95 px-3 py-2 text-sm font-bold text-ink shadow hover:border-teal/40" aria-label="Siguiente">›</button>
            </div>
            <div id="cj-image-thumbs" class="hidden max-h-24 overflow-x-auto border-t border-line bg-white px-3 py-2">
                <div class="flex gap-2" id="cj-image-thumbs-inner"></div>
            </div>
        </div>
    </div>

    {{-- Modal video --}}
    <div id="cj-video-modal" class="fixed inset-0 z-[85] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true">
        <div class="relative max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div id="cj-video-modal-title" class="truncate text-sm font-semibold text-ink">Video</div>
                <button type="button" id="cj-video-modal-close" class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div class="bg-ink p-3 sm:p-4">
                <div id="cj-video-modal-loading" class="py-16 text-center text-sm text-white/70">Cargando video…</div>
                <div id="cj-video-modal-error" class="hidden py-10 text-center text-sm text-coral"></div>
                <video id="cj-video-modal-player" class="mx-auto hidden max-h-[70vh] w-full rounded-lg bg-black" controls playsinline></video>
                <div id="cj-video-modal-list" class="mt-3 hidden flex flex-wrap gap-2"></div>
            </div>
        </div>
    </div>

    {{-- Modal crawl detalle producto CJ --}}
    <div id="cj-crawl-modal" class="fixed inset-0 z-[90] hidden items-center justify-center bg-ink/70 p-3 sm:p-4" role="dialog" aria-modal="true">
        <div class="relative flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div class="min-w-0">
                    <div id="cj-crawl-modal-title" class="truncate text-sm font-semibold text-ink">Producto CJ</div>
                    <div id="cj-crawl-modal-sub" class="truncate text-[11px] text-ink-soft/60"></div>
                </div>
                <button type="button" id="cj-crawl-modal-close" class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div id="cj-crawl-modal-loading" class="hidden px-4 py-16 text-center text-sm text-ink-soft/60">Obteniendo detalle, galería, variantes, reseñas y comentarios desde CJ…</div>
            <div id="cj-crawl-modal-error" class="hidden px-4 py-10 text-center text-sm text-coral"></div>
            <div id="cj-crawl-modal-body" class="hidden flex-1 overflow-y-auto p-4 sm:p-5">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,240px)_1fr]">
                    <div class="space-y-2">
                        <div class="overflow-hidden rounded-xl bg-mist/50">
                            <img id="cj-crawl-main-img" src="" alt="" class="mx-auto max-h-64 w-full object-contain">
                        </div>
                        <div id="cj-crawl-thumbs" class="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto"></div>
                    </div>
                    <div class="min-w-0 space-y-3">
                        <div class="flex flex-wrap gap-1.5 text-[11px]">
                            <span id="cj-crawl-badge-cat" class="admin-badge bg-mist text-ink-soft"></span>
                            <span id="cj-crawl-badge-sku" class="admin-badge bg-mist text-ink-soft"></span>
                            <span id="cj-crawl-badge-weight" class="admin-badge bg-mist text-ink-soft"></span>
                            <span id="cj-crawl-badge-video" class="admin-badge hidden bg-sky-100 text-sky-800">▶ Video</span>
                        </div>
                        <div class="rounded-xl border border-line/70 bg-mist/30 px-3 py-2 text-xs leading-relaxed space-y-1" id="cj-crawl-pricing"></div>
                        <div class="flex flex-wrap gap-2">
                            <a id="cj-crawl-open-cj" href="#" target="_blank" rel="noopener" class="admin-btn-secondary !px-3 !py-1.5 text-xs">Abrir en CJ ↗</a>
                            <button type="button" id="cj-crawl-play-video" class="admin-btn-secondary !px-3 !py-1.5 text-xs hidden">▶ Ver video</button>
                            <button type="button" id="cj-crawl-copy-pid" class="admin-btn-secondary !px-3 !py-1.5 text-xs">Copiar PID</button>
                            <button type="button" id="cj-crawl-add-catalog" class="admin-btn !px-3 !py-1.5 text-xs">+ Catálogo</button>
                        </div>
                        <div>
                            <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Descripción corta</div>
                            <p id="cj-crawl-desc-short" class="text-sm text-ink-soft leading-relaxed"></p>
                        </div>
                        <div>
                            <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Descripción larga</div>
                            <div id="cj-crawl-desc-long" class="prose prose-sm max-w-none max-h-56 overflow-auto rounded-xl border border-line bg-mist/20 p-3 text-sm text-ink-soft"></div>
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between gap-2">
                                <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Variantes</div>
                                <span id="cj-crawl-variant-count" class="text-[11px] text-ink-soft/45"></span>
                            </div>
                            <div class="max-h-56 overflow-auto rounded-xl border border-line">
                                <table class="w-full text-left text-[11px]">
                                    <thead class="sticky top-0 bg-mist text-ink-soft/70">
                                        <tr>
                                            <th class="px-2 py-1.5 font-medium">Quitar</th>
                                            <th class="px-2 py-1.5 font-medium">Img</th>
                                            <th class="px-2 py-1.5 font-medium">Nombre</th>
                                            <th class="px-2 py-1.5 font-medium">SKU</th>
                                            <th class="px-2 py-1.5 font-medium">USD</th>
                                            <th class="px-2 py-1.5 font-medium">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody id="cj-crawl-variants"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Reseñas</div>
                            <span id="cj-crawl-review-count" class="text-[11px] text-ink-soft/45"></span>
                        </div>
                        <div id="cj-crawl-reviews" class="max-h-72 space-y-2 overflow-auto rounded-xl border border-line p-2"></div>
                    </div>
                    <div>
                        <div class="mb-1.5 flex items-center justify-between">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Comentarios</div>
                            <span id="cj-crawl-comment-count" class="text-[11px] text-ink-soft/45"></span>
                        </div>
                        <div id="cj-crawl-comments" class="max-h-72 space-y-2 overflow-auto rounded-xl border border-line p-2"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script type="application/json" id="cj-fx-rates">{!! json_encode($fxRates ?? ['USD' => 1], JSON_UNESCAPED_UNICODE) !!}</script>
<script>
(function ($) {
  var rates = {};
  try { rates = JSON.parse($('#cj-fx-rates').text() || '{}') || {}; } catch (e) { rates = { USD: 1 }; }
  var importUrl = @json($importUrl ?? route('admin.lab.cj.import'));
  var improvePromptUrl = @json($improvePromptUrl ?? route('admin.lab.cj.improve-prompt'));
  var crawlUrl = @json($crawlUrl ?? route('admin.lab.cj.crawl'));
  var huntUrl = @json($huntUrl ?? route('admin.lab.cj.hunt'));
  var huntHtmlUrl = @json($huntHtmlUrl ?? route('admin.lab.cj.hunt-html'));
  var captureUrlBase = @json(url('/admin/lab/cj/capture'));
  var importAliExpressUrl = @json($importAliExpressUrl ?? route('admin.lab.cj.import-aliexpress'));
  var videosUrlBase = @json(url('/admin/lab/cj/videos'));
  var imagesUrlBase = @json(url('/admin/lab/cj/images'));
  var csrf = $('meta[name="csrf-token"]').attr('content');
  var videoCache = {};
  var imageCache = {};
  var galleryState = { pid: null, images: [], index: 0 };
  var imageQueue = [];
  var imageQueueRunning = false;
  var crawlProduct = null;
  var huntAe = null;
  var displayCurrency = @json($displayCurrency ?? 'MXN');

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function countryFlagIso(code) {
    var iso = String(code || '').trim().toLowerCase();
    if (iso === 'uk') iso = 'gb';
    return /^[a-z]{2}$/.test(iso) ? iso : '';
  }

  function countryFlagEl(code) {
    var iso = countryFlagIso(code);
    if (!iso) return null;
    return $('<span class="market-flag fi fi-' + iso + '"/>').attr('title', String(code).toUpperCase());
  }

  function reviewAvatarEl(r) {
    var $wrap = $('<div class="shrink-0"/>');
    if (r.avatar) {
      var $av = aeImg(r.avatar, r.author || '').addClass('h-10 w-10 rounded-full object-cover border border-line bg-white');
      bindAeImgFallback($av, r.avatar);
      $wrap.append($av);
    } else {
      var initial = String(r.author || 'C').trim().charAt(0).toUpperCase() || 'C';
      $wrap.append($('<div class="flex h-10 w-10 items-center justify-center rounded-full border border-line bg-mist text-[11px] font-semibold text-ink-soft"/>').text(initial));
    }
    return $wrap;
  }

  function closeCrawlModal() {
    $('#cj-crawl-modal').addClass('hidden').removeClass('flex');
    crawlProduct = null;
    if ($('#cj-image-modal').hasClass('hidden') && $('#cj-video-modal').hasClass('hidden')) {
      $('body').css('overflow', '');
    }
  }

  function openCrawlModalShell() {
    $('#cj-crawl-modal').removeClass('hidden').addClass('flex');
    $('body').css('overflow', 'hidden');
    $('#cj-crawl-modal-loading').removeClass('hidden');
    $('#cj-crawl-modal-error').addClass('hidden').text('');
    $('#cj-crawl-modal-body').addClass('hidden');
    $('#cj-crawl-modal-title').text('Producto CJ');
    $('#cj-crawl-modal-sub').text('');
  }

  function renderCrawlProduct(p) {
    crawlProduct = p || null;
    if (!p) return;

    var cur = $('#cj-display-currency').length ? String($('#cj-display-currency').val() || displayCurrency) : displayCurrency;
    var pr = p.pricing || {};
    var title = p.title || 'Producto CJ';
    $('#cj-crawl-modal-title').text(title);
    $('#cj-crawl-modal-sub').text((p.pid || '') + (p.resolved_via ? ' · vía ' + p.resolved_via : ''));

    var images = (p.images && p.images.length) ? p.images : (p.image ? [p.image] : []);
    var main = images[0] || '';
    $('#cj-crawl-main-img').attr('src', main).attr('alt', title);
    var $thumbs = $('#cj-crawl-thumbs').empty();
    images.forEach(function (url, i) {
      var $t = $('<button type="button" class="h-12 w-12 overflow-hidden rounded-md border border-line bg-white"/>');
      $t.append($('<img/>').attr('src', url).addClass('h-full w-full object-cover'));
      if (i === 0) $t.addClass('ring-2 ring-teal');
      $t.on('click', function () {
        $('#cj-crawl-main-img').attr('src', url);
        $thumbs.find('button').removeClass('ring-2 ring-teal');
        $t.addClass('ring-2 ring-teal');
      });
      $thumbs.append($t);
    });

    $('#cj-crawl-badge-cat').text(p.category || 'Sin categoría');
    $('#cj-crawl-badge-sku').text('SKU ' + (p.sku || '—'));
    $('#cj-crawl-badge-weight').text(p.weight ? (p.weight + ' g') : 'Peso —');
    if (p.has_video) {
      $('#cj-crawl-badge-video').removeClass('hidden');
      $('#cj-crawl-play-video').removeClass('hidden');
    } else {
      $('#cj-crawl-badge-video').addClass('hidden');
      $('#cj-crawl-play-video').addClass('hidden');
    }

    var priceHtml = '';
    priceHtml += '<div class="flex justify-between gap-2"><span class="text-ink-soft/55">Costo CJ</span><span>' + escapeHtml(money(pr.cost_usd != null ? pr.cost_usd : p.price, cur)) + '</span></div>';
    priceHtml += '<div class="flex justify-between gap-2"><span class="text-ink-soft/55">Envío est.</span><span>' + escapeHtml(money(pr.ship_usd, cur)) + '</span></div>';
    priceHtml += '<div class="flex justify-between gap-2"><span class="text-ink-soft/55">Fees</span><span>' + escapeHtml(money(pr.fees_usd, cur)) + '</span></div>';
    priceHtml += '<div class="my-0.5 border-t border-line/60"></div>';
    priceHtml += '<div class="flex justify-between gap-2 font-semibold text-teal"><span>Precio sugerido</span><span>' + escapeHtml(money(pr.sell_usd, cur)) + '</span></div>';
    priceHtml += '<div class="flex justify-between gap-2 font-semibold text-ink"><span>Ganancia</span><span>' + escapeHtml(money(pr.profit_usd, cur)) + '</span></div>';
    priceHtml += '<div class="flex justify-between gap-2 text-ink-soft/55"><span>Margen</span><span>' + (pr.margin_pct != null ? Number(pr.margin_pct).toFixed(1) + '%' : '—') + '</span></div>';
    $('#cj-crawl-pricing').html(priceHtml);

    if (p.cj_url) {
      $('#cj-crawl-open-cj').attr('href', p.cj_url).removeClass('pointer-events-none opacity-40');
    } else {
      $('#cj-crawl-open-cj').attr('href', '#').addClass('pointer-events-none opacity-40');
    }

    var $add = $('#cj-crawl-add-catalog');
    if (p.in_catalog) {
      $add.prop('disabled', true).text('En catálogo').addClass('!bg-ink-soft/40');
    } else {
      $add.prop('disabled', false).text('+ Catálogo').removeClass('!bg-ink-soft/40');
    }

    $('#cj-crawl-desc-short').text(p.description_short || p.summary || 'Sin descripción corta.');
    var longHtml = p.description_html || p.description_long || '';
    if (longHtml && /<[a-z][\s\S]*>/i.test(longHtml)) {
      $('#cj-crawl-desc-long').html(longHtml);
    } else {
      $('#cj-crawl-desc-long').text(p.description || longHtml || 'Sin descripción larga en CJ.');
    }

    var variants = p.variants || [];
    var localCount = p.variants_with_local_stock;
    var countLabel = (p.variant_count || variants.length) + ' variante(s)';
    if (localCount != null && p.market_country) {
      countLabel += ' · ' + localCount + ' con stock ' + p.market_country;
    }
    $('#cj-crawl-variant-count').text(countLabel);
    var $tb = $('#cj-crawl-variants').empty();
    if (!variants.length) {
      $tb.append('<tr><td colspan="6" class="px-2 py-3 text-ink-soft/50">Sin variantes en la respuesta</td></tr>');
    } else {
      variants.forEach(function (v) {
        var img = v.image
          ? '<img src="' + escapeHtml(v.image) + '" alt="" class="h-10 w-10 rounded object-cover border border-line">'
          : '<span class="text-ink-soft/40">—</span>';
        $tb.append(
          '<tr class="border-t border-line/60" data-cj-variant-row>' +
            '<td class="px-2 py-1.5"><label class="inline-flex items-center gap-1 text-[11px] text-ink-soft"><input type="checkbox" class="cj-variant-skip rounded border-line" data-vid="' + escapeHtml(v.vid || '') + '"> quitar</label></td>' +
            '<td class="px-2 py-1.5">' + img + '</td>' +
            '<td class="px-2 py-1.5 text-ink">' + escapeHtml(v.name || '—') + '</td>' +
            '<td class="px-2 py-1.5 text-ink-soft">' + escapeHtml(v.sku || '—') + '</td>' +
            '<td class="px-2 py-1.5">' + (v.price != null ? Number(v.price).toFixed(2) : '—') + '</td>' +
            '<td class="px-2 py-1.5">' + (v.stock != null ? v.stock : '—') + '</td>' +
          '</tr>'
        );
      });
    }

    function renderNotes(target, rows, emptyText) {
      var $box = $(target).empty();
      if (!rows || !rows.length) {
        $box.append('<p class="px-2 py-3 text-xs text-ink-soft/50">' + emptyText + '</p>');
        return;
      }
      rows.forEach(function (r) {
        var stars = '';
        var score = parseInt(r.score, 10) || 0;
        if (score > 0) {
          stars = '<span class="text-amber">' + '★★★★★'.slice(0, score) + '☆☆☆☆☆'.slice(0, 5 - score) + '</span>';
        }
        var photos = '';
        (r.images || []).forEach(function (url) {
          photos += '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener"><img src="' + escapeHtml(url) + '" alt="" class="h-12 w-12 rounded object-cover border border-line"></a>';
        });
        $box.append(
          '<article class="rounded-lg border border-line/70 bg-mist/20 p-2">' +
            '<div class="mb-1 flex flex-wrap items-center gap-1.5 text-[11px]">' +
              '<strong class="text-ink">' + escapeHtml(r.author || 'Comprador') + '</strong>' +
              (r.country ? '<span class="admin-badge bg-mist text-ink-soft">' + escapeHtml(r.country) + '</span>' : '') +
              stars +
              (r.date ? '<span class="text-ink-soft/50">' + escapeHtml(r.date) + '</span>' : '') +
            '</div>' +
            (r.comment ? '<p class="text-xs text-ink-soft whitespace-pre-wrap">' + escapeHtml(r.comment) + '</p>' : '') +
            (photos ? '<div class="mt-1.5 flex flex-wrap gap-1">' + photos + '</div>' : '') +
          '</article>'
        );
      });
    }
    var reviews = p.reviews || [];
    var comments = p.comments && p.comments.length ? p.comments : reviews.filter(function (r) {
      return (r.comment && String(r.comment).trim()) || (r.images && r.images.length);
    });
    $('#cj-crawl-review-count').text((p.review_count || reviews.length) + (p.rating_avg ? ' · ★ ' + p.rating_avg : ''));
    $('#cj-crawl-comment-count').text(p.comment_count || comments.length);
    renderNotes('#cj-crawl-reviews', reviews, 'Sin reseñas en CJ para este producto.');
    renderNotes('#cj-crawl-comments', comments, 'Sin comentarios con texto o fotos.');

    $('#cj-crawl-modal-loading').addClass('hidden');
    $('#cj-crawl-modal-error').addClass('hidden');
    $('#cj-crawl-modal-body').removeClass('hidden');
  }

  function looksLikeAliExpress(url) {
    return /aliexpress\./i.test(url) || /^(100\d{10,16}|\d{13,16})$/.test(url);
  }

  function runHunt(url) {
    var $btn = $('#cj-crawl-btn');
    var $status = $('#cj-crawl-status');
    var original = $btn.text();
    $btn.prop('disabled', true).text('Buscando…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Extrayendo AliExpress y buscando coincidencias en CJ…');
    $('#ph-hunt').addClass('hidden');
    huntAe = null;

    $.ajax({
      url: huntUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: { _token: csrf, url: url }
    }).done(function (res) {
      if (res && res.success && res.aliexpress) {
        renderHunt(res);
        $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text('Ficha lista. Elige CJ o AliExpress.');
      } else {
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'No se pudo extraer AliExpress');
      }
    }).fail(function (xhr) {
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral')
        .text((xhr.responseJSON && xhr.responseJSON.error) || 'Error al buscar AliExpress');
    }).always(function () {
      $btn.prop('disabled', false).text(original);
    });
  }

  function formatAeMoney(amount, currency) {
    if (amount === '' || amount === null || typeof amount === 'undefined' || isNaN(Number(amount))) return '—';
    var n = Number(amount);
    var cur = String(currency || '').toUpperCase();
    return n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (cur ? (' ' + cur) : '');
  }

  function aeImg(url, alt) {
    var $img = $('<img/>').attr({
      src: url || '',
      alt: alt || '',
      referrerpolicy: 'no-referrer',
      loading: 'lazy',
      decoding: 'async'
    });
    return $img;
  }

  function setAeSectionCollapsed(targetId, collapsed) {
    var $body = $('#' + targetId);
    if (!$body.length) return;
    var $btns = $('.ph-ae-collapse-btn[data-target="' + targetId + '"]');
    var $links = $('.ph-ae-collapse-link[data-target="' + targetId + '"]');
    if (collapsed) {
      $body.addClass('hidden');
      $btns.addClass('is-collapsed').attr('aria-expanded', 'false');
      $btns.find('i').css('transform', 'rotate(-90deg)');
      $links.text('Expandir');
    } else {
      $body.removeClass('hidden');
      $btns.removeClass('is-collapsed').attr('aria-expanded', 'true');
      $btns.find('i').css('transform', 'rotate(0deg)');
      $links.text('Contraer');
    }
  }

  function toggleAeSection(targetId) {
    var $body = $('#' + targetId);
    if (!$body.length) return;
    setAeSectionCollapsed(targetId, !$body.hasClass('hidden'));
  }

  $(document).on('click', '.ph-ae-collapse-btn, .ph-ae-collapse-link', function (e) {
    e.preventDefault();
    var target = $(this).data('target');
    if (target) toggleAeSection(String(target));
  });

  function prepareAeHtml(html) {
    if (!html) return '';
    var out = String(html);
    out = out.replace(/<template[^>]*shadowrootmode[^>]*>([\s\S]*?)<\/template>/gi, '$1');
    out = out.replace(/<template\b[^>]*>[\s\S]*?<\/template>/gi, '');
    out = out.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
    out = out.replace(/<picture\b[^>]*>[\s\S]*?<\/picture>/gi, '');
    out = out.replace(/<img\b[^>]*>/gi, '');
    out = out.replace(/<figure\b[^>]*>[\s\S]*?<\/figure>/gi, '');
    return out;
  }

  function bindAeImgFallback($img, fallbackUrl) {
    if (!$img || !$img.length) return;
    $img.off('error.phAeImg').on('error.phAeImg', function () {
      var fb = fallbackUrl || '';
      if (fb && this.src !== fb) {
        this.src = fb;
      }
    });
  }

  function openHuntGallery(startIndex) {
    var ae = huntAe || {};
    var imgs = (ae.images && ae.images.length) ? ae.images : (ae.image ? [ae.image] : []);
    if (!imgs.length) return;
    closeVideoModal();
    $('#cj-image-modal').removeClass('hidden').addClass('flex');
    $('body').addClass('overflow-hidden');
    setGallery(imgs, ae.title || 'Galería AliExpress', ae.product_id || 'ae', startIndex || 0);
  }

  function renderHunt(res) {
    huntAe = res.aliexpress || null;
    var ae = huntAe || {};
    $('#ph-hunt').removeClass('hidden');
    $('#ph-ae-title').text(ae.title || 'Producto AliExpress');
    $('#ph-ae-meta').text((ae.product_id || '') + (ae.url ? ' · ' + ae.url : ''));
    $('#ph-ae-mode').text(ae.source_note || ae.source_mode || 'AliExpress');
    var imgs = (ae.images && ae.images.length) ? ae.images : (ae.image ? [ae.image] : []);
    var mainImg = imgs[0] || '';
    var $mainImgEl = $('#ph-ae-img');
    $mainImgEl.attr({
      src: mainImg,
      alt: ae.title || '',
      referrerpolicy: 'no-referrer',
      loading: 'lazy',
      decoding: 'async'
    });
    bindAeImgFallback($mainImgEl, mainImg);
    $mainImgEl.off('click.phAe').on('click.phAe', function () { openHuntGallery(0); });
    var $thumbs = $('#ph-ae-thumbs').empty();
    if (!imgs.length) {
      $thumbs.append($('<p class="text-xs text-ink-soft/50"/>').text('Sin imágenes'));
    }
    imgs.forEach(function (u, i) {
      var $t = $('<button type="button" class="h-12 w-12 overflow-hidden rounded-md border border-line bg-white"/>');
      var $ti = aeImg(u, '').addClass('h-full w-full object-cover');
      bindAeImgFallback($ti, u);
      $t.append($ti);
      if (i === 0) $t.addClass('ring-2 ring-teal');
      $t.on('click', function () {
        $('#ph-ae-img').attr('src', u);
        $thumbs.find('button').removeClass('ring-2 ring-teal');
        $t.addClass('ring-2 ring-teal');
      });
      $t.on('dblclick', function () { openHuntGallery(i); });
      $thumbs.append($t);
    });

    var $videos = $('#ph-ae-videos').empty();
    var vids = ae.videos || [];
    if (!vids.length) {
      $videos.append($('<p class="text-xs text-ink-soft/50"/>').text(ae.has_video ? 'Hay video pero no pude extraer la URL.' : 'Sin videos en la captura'));
    } else {
      vids.forEach(function (v, i) {
        var url = v.url || v.play_url || v;
        if (!url) return;
        var $wrap = $('<div class="space-y-1"/>');
        $wrap.append($('<video controls playsinline class="w-full max-h-56 rounded-lg bg-black"/>').attr('src', url));
        $wrap.append($('<a class="text-[11px] text-teal underline" target="_blank" rel="noopener"/>').attr('href', url).text('Video ' + (i + 1) + ' · abrir'));
        $videos.append($wrap);
      });
    }

    var priceTxt = formatAeMoney(ae.price, ae.currency);
    if (ae.compare_at_price) priceTxt += ' · antes ' + formatAeMoney(ae.compare_at_price, ae.currency);
    $('#ph-ae-price').text(ae.price != null && ae.price !== '' ? priceTxt : 'Precio no detectado');

    var $badges = $('#ph-ae-badges').empty();
    function addBadge(label, cls) {
      if (!label) return;
      $badges.append($('<span class="admin-badge ' + (cls || 'bg-mist text-ink-soft') + '"/>').text(label));
    }
    if (ae.currency) addBadge(ae.currency, 'bg-teal/10 text-teal');
    if (ae.rating) addBadge('★ ' + ae.rating, 'bg-amber/10 text-amber');
    if (ae.review_count != null) addBadge(ae.review_count + ' reseñas');
    if (ae.orders != null) addBadge(ae.orders + ' pedidos');
    if (ae.category) addBadge(ae.category);

    var shipTxt = '';
    if (ae.shipping_price === 0 || (ae.shipping_note && /gratis|free/i.test(ae.shipping_note))) {
      shipTxt = ae.shipping_note && /gratis|free/i.test(ae.shipping_note) ? ae.shipping_note : 'Envío gratis';
    } else if (ae.shipping_price != null && ae.shipping_price !== '') {
      shipTxt = formatAeMoney(ae.shipping_price, ae.shipping_currency || ae.currency);
      if (ae.shipping_note) shipTxt += ' · ' + ae.shipping_note;
    } else if (ae.shipping_note) {
      shipTxt = ae.shipping_note;
    } else {
      shipTxt = '—';
    }
    $('#ph-ae-ship').text(shipTxt);
    var shipTime = ae.shipping_time ? String(ae.shipping_time).replace(/^env[ií]o\s*:\s*/i, '').trim() : '';
    $('#ph-ae-ship-time').text(shipTime || '—');

    var attrs = ae.attributes || [];
    var vars = ae.variants || [];
    var singleOption = attrs.length > 0 && (vars.length <= 1 || (vars.length === 1 && vars[0].is_product_option));
    var $attrBox = $('#ph-ae-attributes').empty();
    if (singleOption) {
      $('#ph-ae-variants-title').text('Características del producto');
      attrs.forEach(function (a) {
        $attrBox.append(
          $('<div class="rounded-xl border border-line bg-mist/20 px-3 py-2 text-sm"/>').append(
            $('<div class="text-[11px] uppercase tracking-wide text-ink-soft/55"/>').text(a.name || 'Opción'),
            $('<div class="font-medium text-ink"/>').text(a.value || '—')
          )
        );
      });
      $attrBox.removeClass('hidden');
      $('#ph-ae-variants-table-wrap').addClass('hidden');
    } else {
      $('#ph-ae-variants-title').text('Variaciones');
      $attrBox.addClass('hidden');
      $('#ph-ae-variants-table-wrap').removeClass('hidden');
    }

    $('#ph-ae-variant-count').text(singleOption ? (attrs.length + ' opción') : (vars.length ? (vars.length + ' SKU') : '0'));
    var $tb = $('#ph-ae-variants').empty();
    if (!vars.length) {
      if (!singleOption) {
        $tb.append('<tr><td colspan="5" class="px-2 py-3 text-ink-soft/50">Sin variaciones detectadas</td></tr>');
      }
    } else if (!singleOption) {
      vars.forEach(function (v) {
        var $tr = $('<tr class="border-t border-line/60"/>');
        var $imgTd = $('<td class="px-2 py-1.5"/>');
        if (v.image) {
          var $vi = aeImg(v.image, v.name || '').addClass('h-10 w-10 rounded object-cover border border-line bg-mist');
          bindAeImgFallback($vi, mainImg);
          $imgTd.append($vi);
        } else {
          $imgTd.append($('<span class="text-ink-soft/40"/>').text('—'));
        }
        $tr.append(
          $imgTd,
          $('<td class="px-2 py-1.5 text-ink"/>').text(v.name || '—'),
          $('<td class="px-2 py-1.5 text-ink-soft"/>').text(v.sku || v.vid || '—'),
          $('<td class="px-2 py-1.5"/>').text(v.price != null ? formatAeMoney(v.price, ae.currency) : '—'),
          $('<td class="px-2 py-1.5"/>').text(v.stock != null ? v.stock : '—')
        );
        $tb.append($tr);
      });
    } else if (singleOption && vars.length === 1) {
      var v = vars[0];
      var optImg = v.image || mainImg;
      if (optImg) {
        var $optWrap = $('<div class="sm:col-span-2 flex items-center gap-3 rounded-xl border border-line bg-white p-3"/>');
        var $optImg = aeImg(optImg, v.name || '').addClass('h-20 w-20 shrink-0 rounded-lg border border-line object-cover bg-mist');
        bindAeImgFallback($optImg, mainImg);
        $optWrap.append(
          $optImg,
          $('<div class="min-w-0"/>').append(
            $('<div class="text-[11px] uppercase tracking-wide text-ink-soft/55"/>').text('Opción seleccionada'),
            $('<div class="font-medium text-ink"/>').text(v.name || attrs.map(function (a) { return a.name + ': ' + a.value; }).join(' · '))
          )
        );
        $attrBox.prepend($optWrap);
      }
    }

    var $rev = $('#ph-ae-reviews').empty();
    var revMeta = [];
    if (ae.rating) revMeta.push('★ ' + ae.rating);
    if (ae.review_count) revMeta.push(ae.review_count + ' reseñas');
    if (ae.orders) revMeta.push(ae.orders + ' pedidos');
    $('#ph-ae-review-count').text(revMeta.join(' · ') || ((ae.reviews || []).length + ' en captura'));
    var reviews = ae.reviews || [];
    if (!reviews.length) {
      $rev.append($('<p class="text-xs text-ink-soft/50"/>').text('Sin reseñas disponibles para este producto.'));
    } else {
      reviews.forEach(function (r) {
        var score = parseInt(r.score, 10) || 0;
        var stars = score > 0 ? ('★★★★★'.slice(0, score) + '☆☆☆☆☆'.slice(0, 5 - score)) : '';
        var $art = $('<article class="flex gap-3 rounded-lg border border-line/70 bg-mist/20 p-3 text-xs"/>');
        var $body = $('<div class="min-w-0 flex-1"/>');
        $art.append(reviewAvatarEl(r));
        $body.append(
          $('<div class="mb-1 flex flex-wrap items-center gap-1.5"/>').append(
            $('<strong class="text-ink"/>').text(r.author || 'Comprador'),
            countryFlagEl(r.country),
            stars ? $('<span class="text-amber"/>').text(stars) : null,
            r.date ? $('<span class="text-ink-soft/50"/>').text(r.date) : null
          )
        );
        if (r.sku_info) $body.append($('<p class="mb-1 text-[11px] text-ink-soft/60"/>').text(r.sku_info));
        if (r.comment) $body.append($('<p class="text-ink-soft whitespace-pre-wrap leading-relaxed"/>').text(r.comment));
        else if (score > 0) $body.append($('<p class="text-ink-soft/50 italic"/>').text('Solo calificación (sin comentario de texto)'));
        if (r.images && r.images.length) {
          var $photos = $('<div class="mt-1.5 flex flex-wrap gap-1"/>');
          r.images.forEach(function (url) {
            var $a = $('<a target="_blank" rel="noopener"/>').attr('href', url);
            var $ri = aeImg(url, '').addClass('h-12 w-12 rounded object-cover border border-line');
            bindAeImgFallback($ri, url);
            $photos.append($a.append($ri));
          });
          $body.append($photos);
        }
        $art.append($body);
        $rev.append($art);
      });
    }

    var desc = prepareAeHtml(ae.description_html || ae.description || ae.description_short || '');
    var $desc = $('#ph-ae-desc').empty();
    if (desc && /<[a-z][\s\S]*>/i.test(String(desc))) {
      $desc.html(desc);
    } else if (desc) {
      $desc.text(desc);
    } else {
      $desc.append($('<p class="text-ink-soft/50"/>').text('Sin descripción en la captura.'));
    }
    setAeSectionCollapsed('ph-ae-desc', false);
    setAeSectionCollapsed('ph-ae-reviews', false);
    setAeSectionCollapsed('ph-ae-details-body', false);

    var details = ae.details || [];
    var $detailsWrap = $('#ph-ae-details-wrap');
    var $detailsBody = $('#ph-ae-details').empty();
    if (details.length) {
      $detailsWrap.removeClass('hidden');
      $('#ph-ae-details-count').text(details.length + ' característica(s)');
      details.forEach(function (d) {
        $detailsBody.append(
          '<tr class="hover:bg-mist/20">' +
            '<th class="w-2/5 bg-mist/30 px-3 py-2 text-left font-medium text-ink-soft align-top">' + escapeHtml(d.name || '—') + '</th>' +
            '<td class="px-3 py-2 text-ink align-top">' + escapeHtml(d.value || '—') + '</td>' +
          '</tr>'
        );
      });
    } else {
      $detailsWrap.addClass('hidden');
      $('#ph-ae-details-count').text('');
    }

    $('#ph-choice-ae').prop('checked', true);

    var matches = res.matches || [];
    $('#ph-cj-count').text(matches.length);
    var $grid = $('#ph-cj-grid').empty();
    if (!matches.length) {
      $('#ph-cj-empty').removeClass('hidden');
    } else {
      $('#ph-cj-empty').addClass('hidden');
      matches.forEach(function (m) {
        var pid = String(m.pid || '');
        var $card = $('<label class="flex cursor-pointer flex-col gap-2 rounded-xl border border-line bg-white p-3"/>');
        $card.append(
          $('<div class="flex items-start gap-2"/>').append(
            $('<input type="radio" name="ph-choice" class="mt-1 ph-cj-radio"/>')
              .val(pid)
              .attr('data-pid', pid)
              .attr('data-sku', m.sku || '')
              .attr('data-title', m.title || '')
              .attr('data-image', m.image || '')
              .attr('data-category', m.category || '')
              .attr('data-cj-url', m.cj_url || '')
              .attr('data-weight', m.weight || '')
              .attr('data-has-video', m.has_video ? '1' : '0')
              .attr('data-price-usd', m.price || '')
              .attr('data-cost-usd', m.price || '')
              .attr('data-ship-usd', (m.pricing && m.pricing.ship_usd != null) ? m.pricing.ship_usd : '')
              .attr('data-sell-usd', (m.pricing && m.pricing.sell_usd != null) ? m.pricing.sell_usd : ''),
            $('<img/>').attr('src', m.image || '').addClass('h-16 w-16 rounded-lg object-cover border border-line bg-mist'),
            $('<div class="min-w-0 flex-1"/>').append(
              $('<div class="text-sm font-semibold text-ink leading-snug"/>').text(m.title || 'CJ'),
              $('<div class="mt-1 flex flex-wrap gap-1"/>').append(
                $('<span class="admin-badge bg-teal/10 text-teal"/>').text(m.match_by || 'cj'),
                m.in_catalog ? $('<span class="admin-badge bg-mist text-ink-soft"/>').text('En catálogo') : null
              ),
              $('<div class="mt-1 text-xs text-ink-soft/70"/>').text(
                (m.pricing && m.pricing.sell_usd != null) ? ('Sugerido ~$' + m.pricing.sell_usd + ' USD') : (m.price != null ? ('$' + m.price) : '')
              )
            )
          )
        );
        var $actions = $('<div class="flex gap-2"/>');
        $actions.append(
          $('<button type="button" class="admin-btn-secondary !px-2 !py-1 text-xs ph-cj-preview"/>').text('Ver').attr('data-pid', pid)
        );
        $card.append($actions);
        $grid.append($card);
      });
    }
  }

  function runHuntHtml() {
    var raw = String($('#ph-html-input').val() || '').trim();
    var url = String($('#ph-html-url').val() || $('#cj-crawl-url').val() || '').trim();
    if (!raw) {
      alert('Pega el HTML (o el JSON del plugin) primero.');
      return;
    }
    var payload = { url: url, html: raw };
    if (raw.charAt(0) === '{') {
      try {
        var decoded = JSON.parse(raw);
        if (decoded && (decoded.html || decoded.snapshot)) {
          payload.html = decoded.html || '';
          payload.snapshot = decoded.snapshot || {};
          if (!payload.url && decoded.url) payload.url = decoded.url;
        }
      } catch (e) {}
    }
    var $btn = $('#ph-html-btn');
    var $status = $('#cj-crawl-status');
    var original = $btn.text();
    $btn.prop('disabled', true).text('Parseando…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Parseando HTML pegado…');
    $('#ph-hunt').addClass('hidden');
    $.ajax({
      url: huntHtmlUrl,
      method: 'POST',
      dataType: 'json',
      contentType: 'application/json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      data: JSON.stringify(payload)
    }).done(function (res) {
      if (res && res.success && res.aliexpress) {
        renderHunt(res);
        $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text('Ficha lista desde HTML.');
      } else {
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'No se pudo parsear');
      }
    }).fail(function (xhr) {
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral')
        .text((xhr.responseJSON && xhr.responseJSON.error) || 'Error al parsear HTML');
    }).always(function () {
      $btn.prop('disabled', false).text(original);
    });
  }

  $('#ph-html-btn').on('click', runHuntHtml);
  $('#ph-copy-token').on('click', function () {
    var t = String($('#ph-plugin-token').text() || '');
    if (!t) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function () {
        $('#ph-copy-token').text('Copiado');
        setTimeout(function () { $('#ph-copy-token').text('Copiar'); }, 1500);
      });
    }
  });

  (function loadCaptureFromPlugin() {
    var id = '';
    try { id = String(new URLSearchParams(location.search).get('capture') || ''); } catch (e) { id = ''; }
    if (!id) return;
    $('#cj-crawl-status').removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Cargando captura del plugin…');
    $.ajax({
      url: captureUrlBase + '/' + encodeURIComponent(id),
      method: 'GET',
      dataType: 'json',
      headers: { 'Accept': 'application/json' }
    }).done(function (res) {
      if (res && res.success && res.aliexpress) {
        renderHunt(res);
        $('#cj-crawl-status').removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text('Captura del plugin lista.');
      } else {
        $('#cj-crawl-status').removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'Captura no disponible');
      }
    }).fail(function (xhr) {
      $('#cj-crawl-status').removeClass('text-ink-soft/60 text-teal').addClass('text-coral')
        .text((xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo cargar la captura');
    });
  })();

  function runCrawl() {
    var url = String($('#cj-crawl-url').val() || '').trim();
    var $btn = $('#cj-crawl-btn');
    var $status = $('#cj-crawl-status');
    if (!url) {
      alert('Pega una URL de AliExpress o CJ, un PID o un SKU.');
      return;
    }
    if ($btn.prop('disabled')) return;

    if (looksLikeAliExpress(url)) {
      runHunt(url);
      return;
    }

    var original = $btn.text();
    $btn.prop('disabled', true).text('Crawleando…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Extrayendo detalle, galería, variantes, reseñas y comentarios…');
    openCrawlModalShell();

    $.ajax({
      url: crawlUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: { _token: csrf, url: url }
    }).done(function (res) {
      if (res && res.success && res.product) {
        renderCrawlProduct(res.product);
        $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text('Producto cargado.');
      } else {
        var err = (res && res.error) || 'No se pudo crawlear';
        $('#cj-crawl-modal-loading').addClass('hidden');
        $('#cj-crawl-modal-body').addClass('hidden');
        $('#cj-crawl-modal-error').removeClass('hidden').text(err);
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
      }
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Error al crawlear la URL';
      $('#cj-crawl-modal-loading').addClass('hidden');
      $('#cj-crawl-modal-body').addClass('hidden');
      $('#cj-crawl-modal-error').removeClass('hidden').text(err);
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
    }).always(function () {
      $btn.prop('disabled', false).text(original);
    });
  }

  $('#cj-crawl-btn').on('click', runCrawl);
  $('#cj-crawl-url').on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      runCrawl();
    }
  });
  $('#cj-crawl-modal-close').on('click', closeCrawlModal);
  $('#cj-crawl-modal').on('click', function (e) {
    if (e.target === this) closeCrawlModal();
  });
  $('#cj-crawl-copy-pid').on('click', function () {
    if (!crawlProduct || !crawlProduct.pid) return;
    var pid = String(crawlProduct.pid);
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(pid).then(function () { alert('PID copiado'); });
    } else {
      window.prompt('PID:', pid);
    }
  });
  $('#cj-crawl-play-video').on('click', function () {
    if (!crawlProduct || !crawlProduct.pid) return;
    openVideoModal(String(crawlProduct.pid), crawlProduct.title || 'Video');
  });
  $('#cj-crawl-add-catalog').on('click', function () {
    var $btn = $(this);
    if (!crawlProduct || $btn.prop('disabled')) return;
    var p = crawlProduct;
    var pr = p.pricing || {};
    var excludeVids = [];
    $('#cj-crawl-variants .cj-variant-skip:checked').each(function () {
      var vid = String($(this).attr('data-vid') || '').trim();
      if (vid) excludeVids.push(vid);
    });
    $btn.prop('disabled', true).text('…');
    $.ajax({
      url: importUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: {
        _token: csrf,
        pid: p.pid,
        sku: p.sku,
        title: p.title,
        image: p.image,
        price_usd: p.price,
        weight: p.weight,
        category: p.category,
        cj_url: p.cj_url,
        has_video: p.has_video ? 1 : 0,
        sell_usd: pr.sell_usd,
        ship_usd: pr.ship_usd,
        cost_usd: pr.cost_usd != null ? pr.cost_usd : p.price,
        exclude_vids: excludeVids
      }
    }).done(function (res) {
      if (res && res.success) {
        crawlProduct.in_catalog = true;
        $btn.prop('disabled', true).text('En catálogo').addClass('!bg-ink-soft/40');
        alert(res.message || 'Agregado al catálogo');
      } else {
        $btn.prop('disabled', false).text('+ Catálogo');
        alert((res && res.error) || 'No se pudo importar');
      }
    }).fail(function (xhr) {
      $btn.prop('disabled', false).text('+ Catálogo');
      var msg = 'Error al importar';
      if (xhr.responseJSON) {
        if (xhr.responseJSON.error) msg = xhr.responseJSON.error;
        else if (xhr.responseJSON.message) msg = xhr.responseJSON.message;
        else if (xhr.responseJSON.errors) {
          var parts = [];
          $.each(xhr.responseJSON.errors, function (k, arr) {
            parts = parts.concat(arr);
          });
          if (parts.length) msg = parts.join('\n');
        }
      }
      alert(msg);
    });
  });

  $('#cj-improve-prompt').on('click', function () {
    var $btn = $(this);
    var $ta = $('#cj-prompt-input');
    var $status = $('#cj-improve-status');
    var prompt = String($ta.val() || '').trim();
    if (!prompt) {
      alert('Escribe un prompt primero.');
      return;
    }
    if ($btn.prop('disabled')) return;

    var originalLabel = $btn.text();
    $btn.prop('disabled', true).text('Mejorando…');
    $status.removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('MIIA (ia.ceballosleon.com) está reescribiendo el prompt…');

    $.ajax({
      url: improvePromptUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: { _token: csrf, prompt: prompt }
    }).done(function (res) {
      if (res && res.success && res.prompt) {
        $ta.val(res.prompt);
        $status.removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text('Prompt mejorado con MIIA. Revisa y pulsa «Preguntar con MCP».');
      } else {
        $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'No se pudo mejorar');
      }
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Error al llamar MIIA';
      $status.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
    }).always(function () {
      $btn.prop('disabled', false).text(originalLabel);
    });
  });

  function money(usd, currency) {
    if (usd === '' || usd === null || typeof usd === 'undefined' || isNaN(Number(usd))) return '—';
    var rate = Number(rates[currency] || 1);
    var val = Number(usd) * rate;
    var digits = (currency === 'HUF' || currency === 'ISK' || currency === 'CZK') ? 0 : 2;
    return val.toLocaleString('es-MX', { minimumFractionDigits: digits, maximumFractionDigits: digits }) + ' ' + currency;
  }

  function refreshCards() {
    var currency = $('#cj-display-currency').val() || 'USD';
    $('[data-cj-card]').each(function () {
      var $c = $(this);
      $c.find('[data-money=cost]').text(money($c.data('cost-usd'), currency));
      $c.find('[data-money=ship]').text(money($c.data('ship-usd'), currency));
      $c.find('[data-money=fees]').text(money($c.data('fees-usd'), currency));
      $c.find('[data-money=sell]').text(money($c.data('sell-usd'), currency));
      $c.find('[data-money=profit]').text(money($c.data('profit-usd'), currency));
      var m = $c.data('margin-pct');
      $c.find('[data-margin]').text(m === '' || m == null || isNaN(Number(m)) ? '—' : (Number(m).toFixed(1) + '%'));
    });
    try { localStorage.setItem('md.cj.displayCurrency', currency); } catch (e) {}
  }

  try {
    var saved = localStorage.getItem('md.cj.displayCurrency');
    if (saved && $('#cj-display-currency option[value="'+saved+'"]').length) {
      $('#cj-display-currency').val(saved);
    }
  } catch (e) {}

  $('#cj-display-currency').on('change', refreshCards);
  refreshCards();

  function updateCardImageCount(pid, count) {
    var $card = $('[data-cj-card][data-pid="'+pid+'"]');
    if (!$card.length) return;
    $card.attr('data-image-count', count);
    $card.find('.cj-image-count').text('📷 ' + count);
  }

  function fetchProductImages(pid) {
    return $.ajax({
      url: imagesUrlBase + '/' + encodeURIComponent(pid),
      method: 'GET',
      dataType: 'json',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
    }).then(function (res) {
      if (res && res.success) {
        imageCache[pid] = res.images || [];
        updateCardImageCount(pid, imageCache[pid].length || 0);
        return imageCache[pid];
      }
      return $.Deferred().reject(res).promise();
    });
  }

  function processImageQueue() {
    if (imageQueueRunning) return;
    imageQueueRunning = true;

    function next() {
      if (!imageQueue.length) {
        imageQueueRunning = false;
        return;
      }
      var pid = imageQueue.shift();
      if (!pid || imageCache[pid]) {
        next();
        return;
      }
      fetchProductImages(pid).always(function () {
        setTimeout(next, 1300);
      });
    }
    next();
  }

  function enqueueImageCounts() {
    $('[data-cj-card]').each(function () {
      var pid = String($(this).data('pid') || '');
      if (pid && !imageCache[pid] && imageQueue.indexOf(pid) === -1) {
        imageQueue.push(pid);
      }
    });
    processImageQueue();
  }

  function renderGallerySlide() {
    var images = galleryState.images || [];
    var idx = galleryState.index || 0;
    if (!images.length) return;
    if (idx < 0) idx = images.length - 1;
    if (idx >= images.length) idx = 0;
    galleryState.index = idx;
    $('#cj-image-modal-img').attr('src', images[idx]);
    $('#cj-image-modal-counter').text((idx + 1) + ' / ' + images.length);
    $('#cj-image-thumbs-inner .cj-thumb').removeClass('ring-2 ring-teal').eq(idx).addClass('ring-2 ring-teal');
    var multi = images.length > 1;
    $('#cj-image-prev').toggleClass('hidden', !multi);
    $('#cj-image-next').toggleClass('hidden', !multi);
    $('#cj-image-thumbs').toggleClass('hidden', !multi);
  }

  function setGallery(images, title, pid, startIndex) {
    galleryState.pid = pid || null;
    galleryState.images = images && images.length ? images : [];
    galleryState.index = startIndex || 0;
    $('#cj-image-modal-title').text(title || 'Galería');
    $('#cj-image-modal-loading').addClass('hidden');
    $('#cj-image-modal-img').removeClass('hidden');

    var $inner = $('#cj-image-thumbs-inner').empty();
    galleryState.images.forEach(function (url, i) {
      var $t = $('<button type="button" class="cj-thumb h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-line bg-mist"></button>');
      $t.append($('<img>').attr('src', url).addClass('h-full w-full object-cover'));
      $t.on('click', function () {
        galleryState.index = i;
        renderGallerySlide();
      });
      $inner.append($t);
    });
    renderGallerySlide();
  }

  function openImageModalFromCard($card) {
    closeVideoModal();
    var pid = String($card.data('pid') || '');
    var title = $card.data('title') || 'Galería';
    var fallback = $card.find('.cj-zoom-image').data('full-image') || $card.data('image') || '';

    $('#cj-image-modal').removeClass('hidden').addClass('flex');
    $('body').addClass('overflow-hidden');
    $('#cj-image-modal-title').text(title);
    $('#cj-image-modal-counter').text('');
    $('#cj-image-prev, #cj-image-next, #cj-image-thumbs').addClass('hidden');

    if (imageCache[pid] && imageCache[pid].length) {
      setGallery(imageCache[pid], title, pid, 0);
      return;
    }

    if (fallback) {
      setGallery([fallback], title, pid, 0);
    }
    $('#cj-image-modal-loading').removeClass('hidden').text('Cargando galería…');

    // Prioridad: sacar de cola y fetch ya
    imageQueue = imageQueue.filter(function (p) { return p !== pid; });
    fetchProductImages(pid).done(function (images) {
      if (!images || !images.length) {
        $('#cj-image-modal-loading').addClass('hidden');
        if (!fallback) {
          $('#cj-image-modal-counter').text('Sin imágenes');
        }
        return;
      }
      setGallery(images, title, pid, 0);
    }).fail(function () {
      $('#cj-image-modal-loading').addClass('hidden');
      $('#cj-image-modal-counter').text('No se pudo cargar la galería completa');
    });
  }

  function closeImageModal() {
    $('#cj-image-modal').addClass('hidden').removeClass('flex');
    $('#cj-image-modal-img').attr('src', '');
    $('#cj-image-thumbs-inner').empty();
    galleryState = { pid: null, images: [], index: 0 };
    if ($('#cj-video-modal').hasClass('hidden')) {
      $('body').removeClass('overflow-hidden');
    }
  }

  function closeVideoModal() {
    var player = document.getElementById('cj-video-modal-player');
    if (player) {
      try { player.pause(); } catch (e) {}
      player.removeAttribute('src');
      player.load();
    }
    $('#cj-video-modal').addClass('hidden').removeClass('flex');
    $('#cj-video-modal-player').addClass('hidden');
    $('#cj-video-modal-list').addClass('hidden').empty();
    $('#cj-video-modal-loading').removeClass('hidden').text('Cargando video…');
    $('#cj-video-modal-error').addClass('hidden').text('');
    if ($('#cj-image-modal').hasClass('hidden')) {
      $('body').removeClass('overflow-hidden');
    }
  }

  function playVideoInModal(video) {
    var player = document.getElementById('cj-video-modal-player');
    $('#cj-video-modal-loading').addClass('hidden');
    $('#cj-video-modal-error').addClass('hidden');
    $('#cj-video-modal-player').removeClass('hidden');
    $('#cj-video-modal-title').text(video.name || 'Video CJ');
    player.src = video.play_url;
    player.play().catch(function () {});
  }

  function openVideoModal(pid, title) {
    if (!pid) return;
    closeImageModal();
    $('#cj-video-modal-title').text(title || 'Video');
    $('#cj-video-modal').removeClass('hidden').addClass('flex');
    $('body').addClass('overflow-hidden');
    $('#cj-video-modal-loading').removeClass('hidden').text('Cargando video…');
    $('#cj-video-modal-error').addClass('hidden');
    $('#cj-video-modal-player').addClass('hidden');
    $('#cj-video-modal-list').addClass('hidden').empty();

    function render(videos) {
      if (!videos || !videos.length) {
        $('#cj-video-modal-loading').addClass('hidden');
        $('#cj-video-modal-error').removeClass('hidden').text('Este producto no tiene videos reproducibles en CJ.');
        return;
      }
      playVideoInModal(videos[0]);
      if (videos.length > 1) {
        var $list = $('#cj-video-modal-list').removeClass('hidden').empty();
        videos.forEach(function (v, idx) {
          var label = (v.name || ('Video ' + (idx + 1)));
          if (v.duration) label += ' · ' + Math.round(v.duration) + 's';
          var $b = $('<button type="button" class="admin-btn-secondary !px-2 !py-1 text-[10px]"></button>').text(label);
          $b.on('click', function () { playVideoInModal(v); });
          $list.append($b);
        });
      }
    }

    if (videoCache[pid]) {
      render(videoCache[pid]);
      return;
    }

    $.ajax({
      url: videosUrlBase + '/' + encodeURIComponent(pid),
      method: 'GET',
      dataType: 'json',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
    }).done(function (res) {
      if (!res || !res.success) {
        $('#cj-video-modal-loading').addClass('hidden');
        $('#cj-video-modal-error').removeClass('hidden').text((res && res.error) || 'No se pudo cargar el video');
        return;
      }
      videoCache[pid] = res.videos || [];
      render(videoCache[pid]);
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Error al consultar videos CJ';
      $('#cj-video-modal-loading').addClass('hidden');
      $('#cj-video-modal-error').removeClass('hidden').text(err);
    });
  }

  $(document).on('click', '.cj-zoom-image', function () {
    openImageModalFromCard($(this).closest('[data-cj-card]'));
  });
  $(document).on('click', '.cj-play-video', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $card = $(this).closest('[data-cj-card]');
    openVideoModal($card.data('pid'), $card.data('title'));
  });
  $('#cj-image-prev').on('click', function () {
    galleryState.index -= 1;
    renderGallerySlide();
  });
  $('#cj-image-next').on('click', function () {
    galleryState.index += 1;
    renderGallerySlide();
  });
  $('#cj-image-modal-close').on('click', closeImageModal);
  $('#cj-video-modal-close').on('click', closeVideoModal);
  $('#cj-image-modal').on('click', function (e) {
    if (e.target === this) closeImageModal();
  });
  $('#cj-video-modal').on('click', function (e) {
    if (e.target === this) closeVideoModal();
  });
  $(document).on('keydown', function (e) {
    if ($('#cj-image-modal').hasClass('hidden') === false) {
      if (e.key === 'ArrowLeft') { galleryState.index -= 1; renderGallerySlide(); }
      if (e.key === 'ArrowRight') { galleryState.index += 1; renderGallerySlide(); }
    }
    if (e.key === 'Escape') {
      closeImageModal();
      closeVideoModal();
      closeCrawlModal();
    }
  });

  // Cargar conteos de galería en segundo plano (1 req/s por límite CJ)
  enqueueImageCounts();
  $(document).on('click', '.cj-copy-pid', function () {
    var pid = $(this).data('pid');
    if (!pid) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(String(pid)).then(function () {
        alert('PID copiado');
      });
    } else {
      window.prompt('PID:', pid);
    }
  });

  $(document).on('click', '.ph-cj-preview', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var pid = String($(this).attr('data-pid') || '');
    if (!pid) return;
    $('#cj-crawl-url').val(pid);
    runCrawl();
  });

  $('#ph-import-btn').on('click', function () {
    var $btn = $(this);
    var $st = $('#ph-import-status').removeClass('hidden text-coral text-teal').addClass('text-ink-soft/60').text('Importando…');
    var $checked = $('input[name="ph-choice"]:checked');
    var choice = String($checked.val() || 'aliexpress');
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);

    function doneImport(res) {
      if (res && res.success) {
        $st.removeClass('text-ink-soft/60 text-coral').addClass('text-teal').text(res.message || 'Listo');
        var msg = res.message || 'Agregado';
        if (res.edit_url && confirm(msg + '\n\n¿Abrir el producto en el catálogo?')) {
          window.open(res.edit_url, '_blank');
        }
      } else {
        $st.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text((res && res.error) || 'No se pudo importar');
      }
      $btn.prop('disabled', false);
    }
    function failImport(xhr) {
      var err = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al importar';
      $st.removeClass('text-ink-soft/60 text-teal').addClass('text-coral').text(err);
      $btn.prop('disabled', false);
    }

    if (choice === 'aliexpress') {
      if (!huntAe) {
        alert('No hay ficha AliExpress');
        $btn.prop('disabled', false);
        return;
      }
      $.ajax({
        url: importAliExpressUrl,
        method: 'POST',
        contentType: 'application/json',
        dataType: 'json',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        data: JSON.stringify({
          product: huntAe,
          url: huntAe.url || '',
          product_id: huntAe.product_id || '',
          title: huntAe.title || '',
          image: huntAe.image || ''
        })
      }).done(doneImport).fail(failImport);
      return;
    }

    $.ajax({
      url: importUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: {
        _token: csrf,
        pid: $checked.attr('data-pid'),
        sku: $checked.attr('data-sku'),
        title: $checked.attr('data-title'),
        image: $checked.attr('data-image'),
        category: $checked.attr('data-category'),
        cj_url: $checked.attr('data-cj-url'),
        weight: $checked.attr('data-weight'),
        has_video: $checked.attr('data-has-video') === '1' ? 1 : 0,
        price_usd: $checked.attr('data-price-usd'),
        cost_usd: $checked.attr('data-cost-usd'),
        ship_usd: $checked.attr('data-ship-usd'),
        sell_usd: $checked.attr('data-sell-usd')
      }
    }).done(doneImport).fail(failImport);
  });

  $(document).on('click', '.cj-add-catalog', function () {
    var $btn = $(this);
    var $card = $btn.closest('[data-cj-card]');
    if ($btn.prop('disabled') || String($card.data('in-catalog')) === '1') return;

    $btn.prop('disabled', true).text('…');

    $.ajax({
      url: importUrl,
      method: 'POST',
      dataType: 'json',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      data: {
        _token: csrf,
        pid: $card.data('pid'),
        sku: $card.data('sku'),
        title: $card.data('title'),
        image: $card.data('image'),
        category: $card.data('category'),
        cj_url: $card.data('cj-url'),
        weight: $card.data('weight'),
        has_video: String($card.data('has-video')) === '1' ? 1 : 0,
        price_usd: $card.data('price-usd'),
        cost_usd: $card.data('cost-usd'),
        ship_usd: $card.data('ship-usd'),
        sell_usd: $card.data('sell-usd')
      }
    }).done(function (res) {
      if (res && res.success) {
        $card.attr('data-in-catalog', '1').data('in-catalog', 1);
        $btn.addClass('!bg-ink-soft/40').text('En catálogo').prop('disabled', true);
        var msg = res.message || 'Agregado';
        if (res.edit_url && confirm(msg + '\n\n¿Abrir el producto en el catálogo?')) {
          window.open(res.edit_url, '_blank');
        } else {
          alert(msg);
        }
      } else {
        alert((res && res.error) ? res.error : 'No se pudo agregar');
        $btn.prop('disabled', false).text('+ Catálogo');
      }
    }).fail(function (xhr) {
      var err = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'Error al agregar';
      if (xhr.responseJSON && xhr.responseJSON.errors) {
        err = Object.values(xhr.responseJSON.errors).flat().join('\n');
      }
      alert(err);
      $btn.prop('disabled', false).text('+ Catálogo');
    });
  });
})(jQuery);
</script>
@endpush
