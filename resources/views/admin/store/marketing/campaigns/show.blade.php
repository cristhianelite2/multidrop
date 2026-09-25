@extends('layouts.admin')

@php
    $allowedTabs = ['productos', 'publicaciones', 'generacion', 'campana'];
    $tab = in_array($tab ?? '', $allowedTabs, true) ? $tab : 'productos';
    $statusLabel = ['draft' => 'Borrador', 'ready' => 'Listo', 'paused' => 'Pausada'][$campaign->status] ?? $campaign->status;
    $ctas = [
        'SHOP_NOW' => 'Comprar ahora',
        'LEARN_MORE' => 'Más info',
        'SIGN_UP' => 'Registrarse',
        'ORDER_NOW' => 'Pedir ahora',
        'GET_OFFER' => 'Ver oferta',
    ];
    $sellercentralEmbedUrl = trim((string) ($sellercentralEmbedUrl ?? ''));
    $catalogProducts = $catalogProducts ?? collect();
    $catalogProductCount = $catalogProducts->count();
    $storeCurrency = $store->currency();
    $catalogProductsJson = $catalogProducts->map(function ($p) use ($storeCurrency) {
        $quote = $p->quoteIn($storeCurrency);

        return [
            'id' => $p->id,
            'name' => $p->localizedName(),
            'slug' => $p->slug,
            'sku' => $p->sku,
            'status' => $p->status,
            'image_url' => $p->image_url,
            'is_combo' => (bool) data_get($p->creative_data, 'is_combo', false),
            'price' => (float) ($quote['price'] ?? 0),
            'price_label' => $p->formattedPriceIn($storeCurrency),
            'currency' => $quote['currency'] ?? $storeCurrency,
        ];
    })->values();
    $promptsByProduct = $promptsByProduct ?? collect();
    $videosByProduct = $videosByProduct ?? collect();
    $unassignedPrompts = $promptsByProduct->get('0', collect());
    $unassignedVideos = $videosByProduct->get('0', collect());
    $focusProduct = $focusProduct ?? null;
    $listUrl = route('admin.store.marketing.campaigns.edit', ['campaign' => $campaign, 'tab' => 'productos']);
@endphp

@section('title', $campaign->name.' — Marketing')
@section('heading', $campaign->name)
@section('subheading', implode(' · ', $campaign->platformList()) ?: 'Campaña')

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <style>
        /* El <video> trae tamaño intrínseco (~1920px) y rompe el layout; forzar miniatura fija. */
        [data-md-fold-collapsed] .md-fold-chevron { transform: rotate(-90deg); }
        button.md-open-video-modal:not(.md-product-list-thumb) {
            width: 56px !important;
            height: 100px !important;
            max-width: 56px !important;
            max-height: 100px !important;
            min-width: 56px !important;
            min-height: 100px !important;
            padding: 0 !important;
            flex-shrink: 0 !important;
            display: block !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
        }
        button.md-open-video-modal:not(.md-product-list-thumb) video {
            width: 56px !important;
            height: 100px !important;
            max-width: 56px !important;
            max-height: 100px !important;
            object-fit: cover !important;
            display: block !important;
        }
        #md-video-modal-player {
            width: 169px !important;
            height: 300px !important;
            max-width: 169px !important;
            max-height: 300px !important;
            object-fit: contain !important;
            display: block !important;
            background: #000 !important;
        }
        #md-video-modal.flex {
            display: flex !important;
        }
        #md-camp-add-modal.flex {
            display: flex !important;
        }
        #md-ai-modal.flex {
            display: flex !important;
        }
        #md-hf-review-modal.flex {
            display: flex !important;
        }
        .md-hf-asset:has(.hf-thumb-fallback) .md-hf-thumb,
        .md-hf-thumb-fallback .md-hf-thumb {
            background: linear-gradient(135deg, #eef2f7, #f8fafc);
        }
        .hf-thumb-glyph {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            font-size: 20px;
            color: #64748b;
            background: linear-gradient(135deg, #eef2f7, #f8fafc);
        }
        .md-hf-asset:has(.md-hf-asset-cb:not(:checked))::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.55);
        }
        a.md-product-list-thumb,
        button.md-product-list-thumb {
            width: 72px !important;
            height: 112px !important;
            display: block !important;
            overflow: hidden !important;
            border-radius: 0.5rem;
            border: 1px solid var(--line, #e5e7eb);
            background: #000;
        }
        a.md-product-list-thumb video,
        button.md-product-list-thumb video {
            width: 72px !important;
            height: 112px !important;
            object-fit: cover !important;
            display: block !important;
        }
    </style>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-soft/70">
            <a href="{{ route('admin.store.marketing.campaigns.index') }}" class="hover:text-teal">Campañas</a>
            <span class="text-ink-soft/40"> / </span>
            @if($focusProduct)
                <a href="{{ $listUrl }}" class="hover:text-teal">{{ $campaign->name }}</a>
                <span class="text-ink-soft/40"> / </span>
                {{ $focusProduct->localizedName() }}
            @else
                {{ $campaign->name }}
            @endif
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <span class="admin-badge {{ $campaign->status === 'ready' ? 'bg-emerald-100 text-emerald-800' : ($campaign->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">{{ $statusLabel }}</span>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.duplicate', $campaign) }}">
                @csrf
                <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Duplicar</button>
            </form>
        </div>
    </div>

    @unless($focusProduct)
    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Productos</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $campaign->products->count() }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">en esta campaña</p>
        </div>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Videos</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $campaign->videos->count() }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ $campaign->prompts->count() }} prompts</p>
        </div>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Presupuesto / día</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ number_format((float) $campaign->daily_budget, 2) }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">Tope HITL {{ number_format($budgetCap, 2) }} {{ $store->currency() }}</p>
        </div>
    </div>
    @endunless

    <div class="admin-card overflow-hidden">
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 px-2 pt-2" data-campaign-tabs>
            @foreach([
                'productos' => 'Productos',
                'publicaciones' => 'Publicaciones',
                'generacion' => 'Generación de publicaciones',
                'campana' => 'Campaña',
            ] as $key => $label)
                <button type="button"
                        data-tab="{{ $key }}"
                        class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-teal text-teal' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Productos --}}
        <div class="p-4 sm:p-6 space-y-6 {{ $tab === 'productos' ? '' : 'hidden' }}" data-tab-panel="productos">
            @if($focusProduct)
                @php
                    $pp = $promptsByProduct->get((string) $focusProduct->id, collect());
                    $pv = $videosByProduct->get((string) $focusProduct->id, collect());
                    // Remotion/Creatify anidados en el prompt se muestran en Opción 1.
                    // HyperFrames debe seguir en productVideos para Opción 3 (aunque tengan prompt_id del scrape).
                    $nestedIds = $pp->flatMap(fn ($pr) => $pr->videos->pluck('id'))->all();
                    $pvLoose = $pv->reject(function ($v) use ($nestedIds) {
                        if (($v->source ?: '') === 'hyperframes') {
                            return false;
                        }

                        return in_array($v->id, $nestedIds, true);
                    });
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a href="{{ $listUrl }}" class="text-sm text-teal hover:underline">← Productos de la campaña</a>
                </div>
                @include('admin.store.marketing.campaigns._product_card', [
                    'product' => $focusProduct,
                    'campaign' => $campaign,
                    'productPrompts' => $pp,
                    'productVideos' => $pvLoose,
                    'ctas' => $ctas,
                    'maxMb' => $maxMb,
                    'ffmpeg' => $ffmpeg,
                    'hyperframes' => $hyperframes ?? ['ok' => false],
                    'notebooklm' => $notebooklm ?? ['ok' => false],
                    'notebookJobs' => ($notebookJobsByProduct ?? collect())->get((string) $focusProduct->id, collect()),
                ])
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-ink">Productos de la campaña</h3>
                        <p class="mt-0.5 text-sm text-ink-soft/60">{{ $campaign->products->count() }} {{ $campaign->products->count() === 1 ? 'producto' : 'productos' }}</p>
                    </div>
                    <button type="button" class="admin-btn !px-3 !py-1.5 text-sm" data-md-camp-add-open>Agregar producto o combo</button>
                </div>

                <div class="space-y-4">
                    @forelse($campaign->products as $product)
                        @php
                            $pp = $promptsByProduct->get((string) $product->id, collect());
                            $pv = $videosByProduct->get((string) $product->id, collect());
                            $allVideos = $pv->concat($pp->flatMap(fn ($pr) => $pr->videos))->unique('id')->values();
                        @endphp
                        @include('admin.store.marketing.campaigns._product_list_card', [
                            'product' => $product,
                            'campaign' => $campaign,
                            'productPrompts' => $pp,
                            'productVideos' => $allVideos,
                        ])
                    @empty
                        <div class="rounded-xl border border-dashed border-line px-4 py-8 text-center">
                            <p class="text-sm text-ink-soft/60">Aún no hay productos en esta campaña.</p>
                            <button type="button" class="admin-btn mt-3 !px-3 !py-1.5 text-sm" data-md-camp-add-open>Agregar producto o combo</button>
                        </div>
                    @endforelse
                </div>

                @if($unassignedVideos->isNotEmpty())
                    <div class="rounded-xl border border-dashed border-line overflow-hidden">
                        <div class="border-b border-line bg-mist/30 px-4 py-3">
                            <div class="font-semibold text-ink">Sin producto asignado</div>
                            <div class="mt-0.5 text-xs text-ink-soft/55">{{ $unassignedVideos->count() }} {{ $unassignedVideos->count() === 1 ? 'video' : 'videos' }}</div>
                        </div>
                        <div class="p-4 flex gap-2 overflow-x-auto pb-1">
                            @foreach($unassignedVideos as $v)
                                <button type="button" class="md-product-list-thumb md-open-video-modal" data-url="{{ $v->publicUrl() }}" data-title="{{ $v->ad_headline ?: ($v->original_name ?: 'Video') }}">
                                    <video src="{{ $v->publicUrl() }}" muted playsinline preload="metadata"></video>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>

        {{-- Publicaciones --}}
        <div class="p-4 sm:p-6 space-y-4 {{ $tab === 'publicaciones' ? '' : 'hidden' }}" data-tab-panel="publicaciones">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-ink">Administrar publicaciones</h3>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Panel de Seller Central. Para cambiar la URL del embed, ve a
                        <button type="button" class="text-teal hover:underline" data-tab-jump="campana">Campaña</button>.
                    </p>
                </div>
            </div>
            @if($sellercentralEmbedUrl !== '')
                <div class="overflow-hidden rounded-xl border border-line bg-white">
                    <iframe
                        src="{{ $sellercentralEmbedUrl }}"
                        title="Seller Central — publicaciones"
                        style="width:100%;height:800px;border:0"
                        allow="clipboard-write"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                    ></iframe>
                </div>
            @else
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
                    Falta la URL del embed. Ve a Campaña, pégala y guarda.
                </p>
            @endif
        </div>

        {{-- Generación de publicaciones --}}
        <div class="p-4 sm:p-6 {{ $tab === 'generacion' ? '' : 'hidden' }}" data-tab-panel="generacion">
            @include('admin.store.marketing.campaigns._generacion', [
                'campaign' => $campaign,
                'scConnection' => $scConnection ?? ['ok' => false],
                'publicationPlans' => $publicationPlans ?? collect(),
                'planProducts' => $planProducts ?? collect(),
                'focusProduct' => $focusProduct ?? null,
            ])
        </div>

        {{-- Campaña --}}
        <div class="p-4 sm:p-6 space-y-5 {{ $tab === 'campana' ? '' : 'hidden' }}" data-tab-panel="campana">
            <form method="post" action="{{ route('admin.store.marketing.campaigns.update', $campaign) }}" class="space-y-4" data-no-fixed-actions id="md-campaign-main-form">
                @csrf
                @method('PUT')
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                    <input type="text" name="name" value="{{ old('name', $campaign->name) }}" class="admin-input" required maxlength="120">
                </div>
                <div>
                    <button class="admin-btn">Guardar campaña</button>
                </div>
            </form>

            <div class="border-t border-line pt-5 space-y-3" id="md-sellercentral-resumen">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-ink">Publicaciones (Seller Central)</h3>
                        <p class="mt-1 text-sm text-ink-soft/70">Integra el panel embebido de Seller Central para administrar publicaciones desde esta campaña.</p>
                    </div>
                    <button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-xs" data-tab-jump="publicaciones">Administrar publicaciones</button>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL del embed</label>
                    <input type="url" name="sellercentral_embed_url" form="md-campaign-main-form" value="{{ old('sellercentral_embed_url', $sellercentralEmbedUrl) }}" class="admin-input" maxlength="500" placeholder="https://sellercentral.ceballosleon.com/embed/…">
                    <p class="mt-1 text-xs text-ink-soft/50">Se guarda con «Guardar campaña» (por tienda). Vacío o igual al default usa la configuración global.</p>
                </div>
                @if($sellercentralEmbedUrl !== '')
                    <p class="text-xs text-ink-soft/55 truncate">Embed activo: {{ $sellercentralEmbedUrl }}</p>
                @else
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
                        Configura la URL del embed y guarda la campaña para habilitar la pestaña Publicaciones.
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- Modal agregar producto --}}
    <div id="md-camp-add-modal" class="fixed inset-0 z-[190] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="relative w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h3 class="font-semibold text-ink">Agregar producto o combo</h3>
                    <p class="mt-0.5 text-xs text-ink-soft/55">Busca por nombre, SKU o elige de la lista. También puedes pegar SKUs o IDs.</p>
                </div>
                <button type="button" data-md-camp-add-close class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.products.attach', $campaign) }}" class="p-4 space-y-3" id="md-camp-add-form">
                @csrf
                <div class="grid gap-3 lg:grid-cols-2">
                    <div class="space-y-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="md-camp-add-search">Buscar</label>
                        <input type="search" id="md-camp-add-search" class="admin-input" placeholder="Nombre, SKU o slug…" autocomplete="off">
                        <div id="md-camp-add-list" class="max-h-72 overflow-y-auto rounded-lg border border-line divide-y divide-line bg-white" role="listbox" aria-multiselectable="true" aria-label="Lista de productos">
                            <p class="px-3 py-4 text-sm text-ink-soft/55">Cargando catálogo…</p>
                        </div>
                        <p class="text-xs text-ink-soft/55" id="md-camp-add-hint">Clic para seleccionar varios. Los ya agregados no aparecen.</p>
                    </div>
                    <div class="space-y-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="md-camp-sku-list">Lista (SKU o ID)</label>
                        <textarea name="sku_list" id="md-camp-sku-list" class="admin-input font-mono text-xs" rows="8" placeholder="Uno por línea, o separados por coma"></textarea>
                        <div id="md-camp-add-selected" class="hidden rounded-lg border border-line bg-mist/30 px-3 py-2 text-xs text-ink-soft/70"></div>
                    </div>
                </div>
                <div id="md-camp-add-hidden"></div>
                <div class="flex flex-wrap justify-end gap-2 pt-1">
                    <button type="button" class="admin-btn-secondary" data-md-camp-add-close>Cancelar</button>
                    <button class="admin-btn">Agregar a la campaña</button>
                </div>
            </form>
        </div>
    </div>

    </div>

    @if($focusProduct)
    {{-- Modal MIIA --}}
    <div id="md-ai-modal" class="fixed inset-0 z-[195] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3 shrink-0">
                <div>
                    <h3 class="font-semibold text-ink">Generar prompt con MIIA</h3>
                    <p class="mt-0.5 text-xs text-ink-soft/55">Segmentos máx. 3s · TikTok / Creatify</p>
                </div>
                <button type="button" data-md-ai-close class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div class="overflow-y-auto p-4 sm:p-5 space-y-4" id="md-ai-prompt-box" data-md-prompt-miia="v1">
                <p class="text-sm text-ink-soft/70">Los prompts alimentan Remotion (local: Whisper + MIIA + subtítulos karaoke). Genera uno con IA analizando un producto en segmentos de 3 segundos.</p>
                <input type="hidden" id="md-ai-language" value="{{ $store->configuredLocale() }}">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="sm:col-span-2 space-y-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="md-ai-product-search">Producto</label>
                        <input type="search" id="md-ai-product-search" class="admin-input" placeholder="Buscar por nombre, SKU o slug…" autocomplete="off">
                        <select id="md-ai-product" class="admin-input" size="6" aria-label="Lista de productos">
                            <option value="">— Cargando catálogo… —</option>
                            @foreach($catalogProducts as $prod)
                                <option value="{{ $prod->id }}">#{{ $prod->id }} · {{ $prod->name }} @if($prod->status !== 'live')({{ $prod->status }})@endif</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-ink-soft/55" id="md-ai-product-hint">
                            @if($catalogProductCount > 0)
                                {{ $catalogProductCount }} producto(s) en catálogo. Haz clic en uno de la lista.
                            @else
                                Se cargará el catálogo automáticamente…
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Duración objetivo</label>
                        <select id="md-ai-length" class="admin-input">
                            <option value="15">15 s (~5 segmentos)</option>
                            <option value="21" selected>21 s (~7 segmentos)</option>
                            <option value="24">24 s (~8 segmentos)</option>
                            <option value="30">30 s (~10 segmentos)</option>
                            <option value="36">36 s (~12 segmentos)</option>
                            <option value="45">45 s (~15 segmentos)</option>
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="admin-btn" id="md-ai-generate">Analizar producto y generar</button>
                    <button type="button" class="admin-btn-secondary hidden" id="md-ai-save">Guardar en esta campaña</button>
                    <button type="button" class="admin-btn-secondary hidden" id="md-ai-creatify" @disabled(! $creatify['ok'])>Guardar y enviar a Creatify</button>
                </div>
                <p class="text-sm text-ink-soft/70" id="md-ai-msg"></p>
                <div id="md-ai-analysis" class="hidden rounded-lg border border-line bg-mist/40 p-3 text-sm space-y-2 max-h-64 overflow-y-auto"></div>
                <div id="md-ai-segments-wrap" class="hidden overflow-x-auto">
                    <table class="w-full text-sm min-w-[900px]">
                        <thead>
                            <tr class="text-left text-ink-soft/60 border-b border-line">
                                <th class="py-2 pr-2">Tiempo</th>
                                <th class="py-2 pr-2">Tipo</th>
                                <th class="py-2 pr-2">Voz</th>
                                <th class="py-2 pr-2">Talento</th>
                                <th class="py-2 pr-2">Cámara</th>
                                <th class="py-2">Visual</th>
                            </tr>
                        </thead>
                        <tbody id="md-ai-segments"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Modal revisión HyperFrames: edita guion, plan, archivos y estilo antes de renderizar --}}
    <div id="md-hf-review-modal" class="fixed inset-0 z-[220] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between gap-3 border-b border-line bg-mist/30 px-4 py-3">
                <div class="min-w-0">
                    <h3 class="font-semibold text-ink">Revisar y personalizar video</h3>
                    <p class="truncate text-[11px] text-ink-soft/55 md-hf-review-product"></p>
                </div>
                <button type="button" class="shrink-0 admin-btn-secondary !px-2.5 !py-1 text-xs" id="md-hf-review-close">Cancelar</button>
            </div>
            <div class="overflow-y-auto p-4 space-y-5 md-hf-review-body"></div>
            <div class="flex items-center justify-between gap-3 border-t border-line bg-mist/30 px-4 py-3">
                <p class="text-[11px] text-ink-soft/55 md-hf-review-hint">Edita textos, marca los archivos que quieres usar y elige el estilo. El video se genera con lo que ves aquí.</p>
                <button type="button" class="shrink-0 admin-btn md-hf-review-submit">Enviar a HyperFrames</button>
            </div>
        </div>
    </div>

    {{-- Modal preview video: debe vivir en content (antes del JS) --}}
    <div id="md-video-modal" class="fixed inset-0 z-[200] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="relative flex w-full max-w-[220px] flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
                <div id="md-video-modal-title" class="truncate text-sm font-semibold text-ink">Video</div>
                <button type="button" data-md-video-close class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div class="bg-ink p-3 flex justify-center">
                <video id="md-video-modal-player" controls playsinline preload="metadata" class="rounded-lg bg-black" style="width:169px;height:300px;max-width:169px;max-height:300px;object-fit:contain;display:block"></video>
            </div>
        </div>
    </div>

    {{-- Modal JSON de publicación --}}
    <div id="md-publication-modal" class="fixed inset-0 z-[210] hidden items-center justify-center bg-ink/70 p-4" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h3 class="font-semibold text-ink">JSON de publicación</h3>
                    <p class="mt-0.5 text-xs text-ink-soft/55">Copia o descarga el JSON para importarlo en Seller Central. Respeta los límites de cada red.</p>
                </div>
                <button type="button" data-md-publication-close class="admin-btn-secondary !px-2.5 !py-1 text-xs">Cerrar</button>
            </div>
            <div class="overflow-y-auto p-4 sm:p-5">
                <textarea id="md-publication-json" readonly class="admin-input w-full font-mono text-xs" rows="20"></textarea>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3">
                <button type="button" class="admin-btn-secondary !px-3 !py-1.5 text-sm" data-md-publication-copy>Copiar</button>
                <button type="button" class="admin-btn !px-3 !py-1.5 text-sm" data-md-publication-download>Descargar</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function ($) {
  var initial = @json($tab);
  function activate(tab) {
    if (!$('[data-campaign-tabs] [data-tab="'+tab+'"]').length) tab = 'productos';
    $('[data-campaign-tabs] [data-tab]').removeClass('border-teal text-teal').addClass('border-transparent text-ink-soft/65');
    $('[data-campaign-tabs] [data-tab="'+tab+'"]').addClass('border-teal text-teal').removeClass('border-transparent text-ink-soft/65');
    $('[data-tab-panel]').addClass('hidden');
    $('[data-tab-panel="'+tab+'"]').removeClass('hidden');
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    if (focusProductId && (tab === 'productos' || tab === 'generacion')) {
      url.searchParams.set('product', String(focusProductId));
    } else if (tab !== 'productos' && tab !== 'generacion') {
      url.searchParams.delete('product');
    }
    history.replaceState({}, '', url.toString());
  }
  var listUrl = @json($listUrl);
  var focusProductId = @json($focusProduct?->id);
  var focusProductName = @json($focusProduct ? $focusProduct->localizedName() : '');
  $('[data-campaign-tabs] [data-tab]').on('click', function () {
    var tab = $(this).data('tab');
    if (tab === 'productos' && focusProductId) {
      window.location.href = listUrl;
      return;
    }
    activate(tab);
  });
  $(document).on('click', '[data-tab-jump]', function () {
    activate($(this).data('tab-jump'));
  });
  activate(initial);

  function copyTextToClipboard(text, btn) {
    function flash(ok) {
      if (!btn) return;
      var prev = btn.textContent;
      btn.textContent = ok ? 'Copiado' : 'Error';
      setTimeout(function () { btn.textContent = prev; }, 1800);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flash(true); }).catch(function () { flash(false); });
      return;
    }
    var tmp = document.createElement('textarea');
    tmp.value = text;
    tmp.setAttribute('readonly', '');
    tmp.style.position = 'absolute';
    tmp.style.left = '-9999px';
    document.body.appendChild(tmp);
    tmp.select();
    try { flash(document.execCommand('copy')); } catch (e) { flash(false); }
    document.body.removeChild(tmp);
  }
  $(document).on('click', '.md-copy-video-url', function () {
    var url = this.getAttribute('data-url') || '';
    if (!url) return;
    copyTextToClipboard(url, this);
  });

  function mdVideoBulkRefresh(group) {
    var checks = document.querySelectorAll('.md-video-bulk-check[data-bulk-group="' + group + '"]');
    var n = 0;
    checks.forEach(function (c) { if (c.checked) n++; });
    var countEl = document.querySelector('.md-video-bulk-count[data-bulk-group="' + group + '"]');
    if (countEl) countEl.textContent = n + (n === 1 ? ' seleccionado' : ' seleccionados');
    var runBtn = document.querySelector('.md-video-bulk-run[data-bulk-group="' + group + '"]');
    if (runBtn) runBtn.disabled = n < 1;
    var all = document.querySelector('.md-video-bulk-all[data-bulk-group="' + group + '"]');
    if (all) {
      all.checked = n > 0 && n === checks.length;
      all.indeterminate = n > 0 && n < checks.length;
    }
  }
  window.mdVideoBulkSubmit = function (form) {
    var group = form.getAttribute('data-bulk-group') || '';
    var box = form.querySelector('.md-video-bulk-ids');
    if (!box) return false;
    box.innerHTML = '';
    var selected = document.querySelectorAll('.md-video-bulk-check[data-bulk-group="' + group + '"]:checked');
    if (!selected.length) {
      alert('Selecciona al menos un video.');
      return false;
    }
    if (!confirm('¿Eliminar ' + selected.length + ' video(s) seleccionado(s)?')) {
      return false;
    }
    selected.forEach(function (c) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ids[]';
      input.value = c.value;
      box.appendChild(input);
    });
    return true;
  };
  $(document).on('change', '.md-video-bulk-check', function () {
    mdVideoBulkRefresh(this.getAttribute('data-bulk-group') || '');
  });
  $(document).on('change', '.md-video-bulk-all', function () {
    var group = this.getAttribute('data-bulk-group') || '';
    var on = this.checked;
    document.querySelectorAll('.md-video-bulk-check[data-bulk-group="' + group + '"]').forEach(function (c) {
      c.checked = on;
    });
    mdVideoBulkRefresh(group);
  });

  function closeMdVideoModal() {
    var modal = document.getElementById('md-video-modal');
    var player = document.getElementById('md-video-modal-player');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
    if (player) {
      try { player.pause(); } catch (e) {}
      player.removeAttribute('src');
      player.load();
    }
  }
  function openMdVideoModal(url, title) {
    var modal = document.getElementById('md-video-modal');
    var player = document.getElementById('md-video-modal-player');
    var titleEl = document.getElementById('md-video-modal-title');
    if (!modal || !player || !url) {
      console.warn('md-video-modal no disponible', { modal: !!modal, player: !!player, url: url });
      return;
    }
    if (titleEl) titleEl.textContent = title || 'Video';
    player.setAttribute('style', 'width:169px;height:300px;max-width:169px;max-height:300px;object-fit:contain;display:block;background:#000');
    player.src = url;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    var playPromise = player.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {});
    }
  }
  $(document).on('click', '.md-open-video-modal', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openMdVideoModal(this.getAttribute('data-url') || '', this.getAttribute('data-title') || 'Video');
  });
  $(document).on('click', '[data-md-video-close]', function (e) {
    e.preventDefault();
    closeMdVideoModal();
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeMdVideoModal();
  });
  $(document).on('click', '#md-video-modal', function (e) {
    if (e.target === this) closeMdVideoModal();
  });

  function openPublicationModal() {
    var modal = document.getElementById('md-publication-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
  }
  function closePublicationModal() {
    var modal = document.getElementById('md-publication-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
  }
  $(document).on('click', '.md-publication-json', function () {
    var btn = this;
    var url = btn.getAttribute('data-url');
    if (!url) return;
    var prev = btn.textContent;
    btn.textContent = 'Generando…';
    btn.disabled = true;
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (j) {
        var area = document.getElementById('md-publication-json');
        if (area) area.value = JSON.stringify(j, null, 2);
        openPublicationModal();
      })
      .catch(function () {
        if (window.alert) window.alert('No se pudo generar el JSON de publicación.');
      })
      .then(function () {
        btn.textContent = prev;
        btn.disabled = false;
      });
  });
  $(document).on('click', '[data-md-publication-close]', function (e) {
    e.preventDefault();
    closePublicationModal();
  });
  $(document).on('click', '[data-md-publication-copy]', function () {
    var area = document.getElementById('md-publication-json');
    if (area && area.value) copyTextToClipboard(area.value, this);
  });
  $(document).on('click', '[data-md-publication-download]', function () {
    var area = document.getElementById('md-publication-json');
    if (!area || !area.value) return;
    var blob = new Blob([area.value], { type: 'application/json;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'publication-' + campaignId + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  });
  $(document).on('click', '#md-publication-modal', function (e) {
    if (e.target === this) closePublicationModal();
  });
  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var pubModal = document.getElementById('md-publication-modal');
    if (pubModal && !pubModal.classList.contains('hidden')) {
      closePublicationModal();
    }
  });

  var campaignId = @json($campaign->id);
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var token = csrf ? csrf.getAttribute('content') : '';
  var attachedProductIds = @json($campaign->products->pluck('id')->map(fn ($id) => (int) $id)->values());

  // NotebookLM: registrar pronto para que un error JS posterior no deje el botón muerto.
  var nlmPollers = {};
  var nlmPollUrl = @json(route('admin.store.marketing.notebooklm.poll', absolute: false));
  var nlmGenerateUrl = @json(route('admin.store.marketing.notebooklm.generate', absolute: false));
  var nlmCancelUrl = @json(route('admin.store.marketing.notebooklm.cancel', absolute: false));
  var nlmRetryUrl = @json(route('admin.store.marketing.notebooklm.retry', absolute: false));
  var nlmDefaultPoll = {{ (int) data_get($notebooklm ?? [], 'poll_seconds', 8) }};

  function nlmCsrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : (token || '');
  }
  function nlmEls(productId) {
    return {
      msg: document.querySelector('.md-notebooklm-msg[data-product-id="' + productId + '"]'),
      process: document.querySelector('.md-notebooklm-process[data-product-id="' + productId + '"]'),
      status: document.querySelector('.md-notebooklm-status[data-product-id="' + productId + '"]'),
      badge: document.querySelector('.md-notebooklm-badge[data-product-id="' + productId + '"]'),
      steps: document.querySelector('.md-notebooklm-steps[data-product-id="' + productId + '"]'),
      go: document.querySelector('.md-notebooklm-go[data-product-id="' + productId + '"]'),
      retry: document.querySelector('.md-notebooklm-retry[data-product-id="' + productId + '"]'),
      cancel: document.querySelector('.md-notebooklm-cancel[data-product-id="' + productId + '"]')
    };
  }
  function nlmMode(productId) {
    var el = document.querySelector('input.md-notebooklm-mode[data-product-id="' + productId + '"]:checked');
    return el ? el.value : 'assets';
  }
  function nlmInstructions(productId) {
    var el = document.querySelector('.md-notebooklm-instructions[data-product-id="' + productId + '"]');
    return el ? String(el.value || '').trim() : '';
  }
  function nlmParseJson(r) {
    return r.text().then(function (t) {
      var j = null;
      try { j = t ? JSON.parse(t) : null; } catch (e) {
        j = { ok: false, message: 'Respuesta inválida del servidor (HTTP ' + r.status + ')' };
      }
      return { okHttp: r.ok, j: j || { ok: false, message: 'Sin respuesta' } };
    });
  }
  function nlmRenderJob(productId, job) {
    var els = nlmEls(productId);
    if (!job) return;
    if (els.process) {
      els.process.classList.remove('hidden');
      els.process.setAttribute('data-job-id', job.id || '');
    }
    if (els.status) els.status.textContent = job.status_label || job.status || '—';
    if (els.badge) els.badge.textContent = job.status || '—';
    if (els.steps) {
      var steps = Array.isArray(job.steps) ? job.steps : [];
      if (!steps.length) {
        els.steps.innerHTML = '<li class="text-ink-soft/50">Esperando pasos del agente NotebookLM…</li>';
      } else {
        els.steps.innerHTML = steps.map(function (s) {
          var color = s.ok === false ? 'text-coral' : (s.ok === true ? 'text-teal' : 'text-ink-soft/40');
          var msg = s.message ? (' — ' + String(s.message).replace(/</g, '&lt;')) : '';
          return '<li class="flex gap-2"><span class="shrink-0 ' + color + '">•</span><span><span class="font-medium text-ink-soft/70">' +
            String(s.step || 'step').replace(/</g, '&lt;') + '</span>' + msg + '</span></li>';
        }).join('');
      }
    }
    if (els.go) els.go.disabled = !job.is_terminal;
    if (els.cancel) els.cancel.disabled = !!job.is_terminal;
    if (els.retry) {
      var showRetry = job.status === 'error';
      els.retry.classList.toggle('hidden', !showRetry);
      els.retry.disabled = !showRetry;
      if (showRetry && job.id) els.retry.setAttribute('data-job-id', String(job.id));
    }
    if (els.msg) {
      if (job.status === 'completed') els.msg.textContent = 'Video recibido y guardado en el producto.';
      else if (job.status === 'error') els.msg.textContent = job.error_message || 'Error en la generación. Puedes reintentar.';
      else els.msg.textContent = job.status_label || 'Procesando…';
    }
  }
  function nlmStopPoll(productId) {
    if (nlmPollers[productId]) {
      clearTimeout(nlmPollers[productId]);
      delete nlmPollers[productId];
    }
  }
  function nlmPoll(productId, jobId, seconds) {
    nlmStopPoll(productId);
    var wait = Math.max(4, parseInt(seconds || nlmDefaultPoll, 10) || nlmDefaultPoll) * 1000;
    nlmPollers[productId] = setTimeout(function () {
      fetch(nlmPollUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': nlmCsrf()
        },
        body: JSON.stringify({ job_id: jobId })
      }).then(nlmParseJson)
        .then(function (pack) {
          var job = pack.j && pack.j.job ? pack.j.job : null;
          if (!job) {
            var els = nlmEls(productId);
            if (els.msg) els.msg.textContent = (pack.j && pack.j.message) || 'No se pudo consultar el estado.';
            nlmPoll(productId, jobId, nlmDefaultPoll);
            return;
          }
          nlmRenderJob(productId, job);
          if (job.is_terminal) {
            nlmStopPoll(productId);
            if (job.status === 'completed') {
              setTimeout(function () { window.location.reload(); }, 1200);
            }
            return;
          }
          nlmPoll(productId, jobId, nlmDefaultPoll);
        })
        .catch(function () {
          var els = nlmEls(productId);
          if (els.msg) els.msg.textContent = 'Error de red al consultar NotebookLM.';
          nlmPoll(productId, jobId, nlmDefaultPoll);
        });
    }, wait);
  }
  $(document).on('click', '.md-notebooklm-go', function (e) {
    e.preventDefault();
    var btn = this;
    if (btn.disabled) return;
    var productId = btn.getAttribute('data-product-id');
    var campId = btn.getAttribute('data-campaign-id') || campaignId;
    var els = nlmEls(productId);
    btn.disabled = true;
    if (els.cancel) els.cancel.disabled = false;
    if (els.msg) els.msg.textContent = 'Enviando a Seller Central…';
    if (els.process) els.process.classList.remove('hidden');
    fetch(nlmGenerateUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': nlmCsrf()
      },
      body: JSON.stringify({
        campaign_id: parseInt(campId, 10),
        product_id: parseInt(productId, 10),
        mode: nlmMode(productId),
        instructions: nlmInstructions(productId)
      })
    }).then(nlmParseJson)
      .then(function (pack) {
        if (!pack.okHttp || !pack.j || !pack.j.ok || !pack.j.job) {
          btn.disabled = false;
          if (els.cancel) els.cancel.disabled = true;
          if (pack.j && pack.j.job) nlmRenderJob(productId, pack.j.job);
          if (els.msg) els.msg.textContent = (pack.j && pack.j.message) || 'No se pudo iniciar NotebookLM.';
          return;
        }
        nlmRenderJob(productId, pack.j.job);
        nlmPoll(productId, pack.j.job.id, pack.j.poll_seconds || nlmDefaultPoll);
      })
      .catch(function (err) {
        btn.disabled = false;
        if (els.cancel) els.cancel.disabled = true;
        if (els.msg) els.msg.textContent = 'Error de red al iniciar NotebookLM.';
        console.error('NotebookLM generate', err);
      });
  });
  $(document).on('click', '.md-notebooklm-retry', function (e) {
    e.preventDefault();
    var btn = this;
    if (btn.disabled) return;
    var productId = btn.getAttribute('data-product-id');
    var jobId = btn.getAttribute('data-job-id');
    var els = nlmEls(productId);
    if (!jobId) {
      if (els.msg) els.msg.textContent = 'No hay job fallido para reintentar.';
      return;
    }
    btn.disabled = true;
    if (els.go) els.go.disabled = true;
    if (els.cancel) els.cancel.disabled = false;
    if (els.msg) els.msg.textContent = 'Reintentando en Seller Central…';
    if (els.process) els.process.classList.remove('hidden');
    fetch(nlmRetryUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': nlmCsrf()
      },
      body: JSON.stringify({ job_id: parseInt(jobId, 10) })
    }).then(nlmParseJson)
      .then(function (pack) {
        if (!pack.okHttp || !pack.j || !pack.j.ok || !pack.j.job) {
          btn.disabled = false;
          if (els.go) els.go.disabled = false;
          if (els.cancel) els.cancel.disabled = true;
          if (pack.j && pack.j.job) nlmRenderJob(productId, pack.j.job);
          if (els.msg) els.msg.textContent = (pack.j && pack.j.message) || 'No se pudo reintentar.';
          return;
        }
        btn.classList.add('hidden');
        nlmRenderJob(productId, pack.j.job);
        nlmPoll(productId, pack.j.job.id, pack.j.poll_seconds || nlmDefaultPoll);
      })
      .catch(function (err) {
        btn.disabled = false;
        if (els.go) els.go.disabled = false;
        if (els.msg) els.msg.textContent = 'Error de red al reintentar.';
        console.error('NotebookLM retry', err);
      });
  });
  $(document).on('click', '.md-notebooklm-cancel', function (e) {
    e.preventDefault();
    var productId = this.getAttribute('data-product-id');
    var process = document.querySelector('.md-notebooklm-process[data-product-id="' + productId + '"]');
    var jobId = process ? process.getAttribute('data-job-id') : '';
    if (!jobId) return;
    var els = nlmEls(productId);
    this.disabled = true;
    if (els.msg) els.msg.textContent = 'Deteniendo…';
    nlmStopPoll(productId);
    fetch(nlmCancelUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': nlmCsrf()
      },
      body: JSON.stringify({ job_id: parseInt(jobId, 10) })
    }).then(nlmParseJson)
      .then(function (pack) {
        if (pack.j && pack.j.job) nlmRenderJob(productId, pack.j.job);
        if (els.go) els.go.disabled = false;
        if (els.msg) els.msg.textContent = (pack.j && pack.j.message) || 'Detenido.';
      })
      .catch(function () {
        if (els.msg) els.msg.textContent = 'No se pudo detener.';
      });
  });
  document.querySelectorAll('.md-notebooklm-process[data-job-bootstrap]').forEach(function (el) {
    var productId = el.getAttribute('data-product-id');
    var raw = el.getAttribute('data-job-bootstrap');
    if (!productId || !raw) return;
    try {
      var job = JSON.parse(raw);
      nlmRenderJob(productId, job);
      if (job && job.id && !job.is_terminal) {
        nlmPoll(productId, job.id, nlmDefaultPoll);
      }
    } catch (err) {}
  });

  function productosUrl(productId) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'productos');
    if (productId) {
      url.searchParams.set('product', String(productId));
    }
    return url.toString();
  }
  function pollCreatify(jobId, promptId, onMsg, onDone) {
    fetch(@json(route('admin.store.marketing.creatify.poll')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId, campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { if (onDone) onDone(res.message || 'Error'); return; }
      if (res.status === 'done') {
        if (onMsg) onMsg('Video listo. Recargando…');
        window.location.href = productosUrl();
        return;
      }
      if (onMsg) onMsg('Generando… ' + (res.progress || 0) + '% (' + (res.status || 'pending') + ')');
      setTimeout(function () { pollCreatify(jobId, promptId, onMsg, onDone); }, 4000);
    }).catch(function () { if (onDone) onDone('Error de red al consultar el job'); });
  }
  function startCreatify(promptId, onMsg, onDone) {
    fetch(@json(route('admin.store.marketing.creatify.generate')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (pack) {
      if (!pack.j.ok) { if (onDone) onDone(pack.j.message || 'No se pudo generar'); return; }
      if (onMsg) onMsg('Job ' + pack.j.job_id + '…');
      pollCreatify(pack.j.job_id, promptId, onMsg, onDone);
    }).catch(function () { if (onDone) onDone('Error de red'); });
  }

  function remotionMsgEl(promptId) {
    return document.querySelector('.md-remotion-msg[data-prompt-id="' + promptId + '"]');
  }
  function remotionProgressLabel(res) {
    var msg = (res && res.message) ? String(res.message) : 'Procesando…';
    var step = res && res.step != null ? parseInt(res.step, 10) : NaN;
    var steps = res && res.steps != null ? parseInt(res.steps, 10) : NaN;
    if (!isNaN(step) && !isNaN(steps) && steps > 0 && !/^\d+\s*\/\s*\d+/.test(msg)) {
      return 'Paso ' + step + '/' + steps + ' — ' + msg;
    }
    return msg;
  }
  function pollRemotion(jobId, promptId, btn) {
    fetch(@json(route('admin.store.marketing.remotion.poll')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      var el = remotionMsgEl(promptId);
      if (res.failed || res.status === 'failed') {
        if (el) el.textContent = res.message || 'Falló Remotion';
        if (btn) btn.disabled = false;
        return;
      }
      if (res.done || res.status === 'done') {
        if (el) el.textContent = 'Video listo. Recargando…';
        window.location.href = productosUrl();
        return;
      }
      if (res.status === 'queued') {
        if (el) el.textContent = remotionProgressLabel(res) || 'En cola — si no avanza en 30s, ejecuta php artisan queue:work.';
      } else if (el) {
        el.textContent = remotionProgressLabel(res);
      }
      setTimeout(function () { pollRemotion(jobId, promptId, btn); }, 2000);
    }).catch(function () {
      var el = remotionMsgEl(promptId);
      if (el) el.textContent = 'Error de red al consultar Remotion';
      if (btn) btn.disabled = false;
    });
  }
  $(document).on('click', '.md-remotion-go', function () {
    var btn = this;
    var promptId = btn.getAttribute('data-prompt-id');
    if (!promptId) return;
    var el = remotionMsgEl(promptId);
    var presetEl = document.querySelector('.md-remotion-preset[data-prompt-id="' + promptId + '"]');
    var voiceEl = document.querySelector('.md-remotion-voice[data-prompt-id="' + promptId + '"]');
    var fd = new FormData();
    fd.append('campaign_id', campaignId);
    fd.append('prompt_id', promptId);
    fd.append('preset', presetEl ? presetEl.value : 'product_presenter');
    if (voiceEl && voiceEl.files && voiceEl.files[0]) {
      fd.append('voice', voiceEl.files[0]);
    }
    btn.disabled = true;
    if (el) el.textContent = '0/6 Enviando job…';
    fetch(@json(route('admin.store.marketing.remotion.generate')), {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: fd
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (pack) {
      if (!pack.j.ok) {
        if (el) el.textContent = pack.j.message || 'No se pudo iniciar Remotion';
        btn.disabled = false;
        return;
      }
      if (pack.j.status === 'done') {
        if (el) el.textContent = 'Video listo. Recargando…';
        window.location.href = productosUrl();
        return;
      }
      if (pack.j.status === 'failed') {
        if (el) el.textContent = pack.j.message || 'Falló Remotion';
        btn.disabled = false;
        return;
      }
      if (el) el.textContent = pack.j.message || '0/6 Arrancando pipeline…';
      pollRemotion(pack.j.job_id, promptId, btn);
    }).catch(function () {
      if (el) el.textContent = 'Error de red';
      btn.disabled = false;
    });
  });

  function hfMsgEl(productId) {
    return document.querySelector('.md-hyperframes-msg[data-product-id="' + productId + '"]');
  }
  var hfJobs = {};
  function hfProgressEls(productId) {
    return {
      wrap: document.querySelector('.md-hyperframes-progress[data-product-id="' + productId + '"]'),
      step: document.querySelector('.md-hyperframes-step[data-product-id="' + productId + '"]'),
      bar: document.querySelector('.md-hyperframes-bar[data-product-id="' + productId + '"]'),
      cancel: document.querySelector('.md-hyperframes-cancel[data-product-id="' + productId + '"]')
    };
  }
  function hfSetCancelVisible(productId, visible, jobId) {
    var els = hfProgressEls(productId);
    if (!els.cancel) return;
    if (visible) {
      els.cancel.hidden = false;
      els.cancel.style.display = '';
      if (jobId) {
        els.cancel.setAttribute('data-job-id', jobId);
        els.cancel.disabled = false;
      } else {
        els.cancel.removeAttribute('data-job-id');
        els.cancel.disabled = true;
      }
    } else {
      els.cancel.hidden = true;
      els.cancel.removeAttribute('data-job-id');
      els.cancel.disabled = true;
    }
  }
  function hfSetMsg(productId, message, isError) {
    var el = hfMsgEl(productId);
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('text-red-700', !!isError);
    el.classList.toggle('font-medium', !!isError);
    el.classList.toggle('text-ink-soft/55', !isError);
  }
  function hfUpdateProgress(productId, res) {
    var els = hfProgressEls(productId);
    var step = res && res.step != null ? parseInt(res.step, 10) : 0;
    var steps = res && res.steps != null ? parseInt(res.steps, 10) : 8;
    if (isNaN(step)) step = 0;
    if (isNaN(steps) || steps < 1) steps = 8;
    if (els.wrap) {
      els.wrap.classList.remove('hidden');
      els.wrap.classList.remove('border-red-200', 'bg-red-50/60');
      els.wrap.classList.add('border-line', 'bg-mist/40');
    }
    if (els.step) els.step.textContent = step + '/' + steps;
    if (els.bar) {
      els.bar.classList.remove('bg-red-500');
      els.bar.classList.add('bg-teal');
      els.bar.style.width = Math.max(4, Math.min(100, Math.round((step / steps) * 100))) + '%';
    }
    var hint = els.wrap ? els.wrap.querySelector('.md-hyperframes-progress-hint') : null;
    if (hint) hint.textContent = 'Puedes detener el render en cualquier momento.';
    var msg = (res && res.message) ? String(res.message) : 'Procesando HyperFrames…';
    hfSetMsg(productId, msg, false);
    var jobId = (hfJobs[productId] && hfJobs[productId].jobId) || null;
    hfSetCancelVisible(productId, true, jobId);
  }
  /** Termina el job en UI. Nunca oculta el panel en error: el mensaje debe quedar visible. */
  function hfFinish(productId, btn, message, opts) {
    opts = opts || {};
    if (hfJobs[productId]) {
      hfJobs[productId].stopped = true;
      delete hfJobs[productId];
    }
    hfSetCancelVisible(productId, false);
    hfSetMsg(productId, message || '', !!opts.error);
    if (btn) btn.disabled = false;
    var els = hfProgressEls(productId);
    if (opts.error) {
      if (els.wrap) {
        els.wrap.classList.remove('hidden');
        els.wrap.classList.remove('border-line', 'bg-mist/40');
        els.wrap.classList.add('border-red-200', 'bg-red-50/60');
      }
      if (els.bar) {
        els.bar.classList.remove('bg-teal');
        els.bar.classList.add('bg-red-500');
      }
      var hint = els.wrap ? els.wrap.querySelector('.md-hyperframes-progress-hint') : null;
      if (hint) hint.textContent = 'Revisa el error arriba y vuelve a intentar.';
    }
  }
  function pollHyperframes(jobId, productId, btn) {
    if (hfJobs[productId] && hfJobs[productId].stopped) return;
    fetch(@json(route('admin.store.marketing.hyperframes.poll')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId })
    }).then(function (r) {
      return r.json().then(function (j) { return { okHttp: r.ok, j: j }; }).catch(function () {
        return { okHttp: false, j: { ok: false, failed: true, message: 'Respuesta inválida al consultar HyperFrames (HTTP ' + r.status + ')' } };
      });
    }).then(function (pack) {
      var res = pack.j || {};
      if (hfJobs[productId] && hfJobs[productId].stopped) return;
      if (res.review && res.review_payload) {
        if (hfRegenResume && String(hfRegenResume.productId) === String(productId) && String(hfRegenResume.jobId) === String(jobId)) {
          var rev = hfRegenResume;
          hfRegenResume = null;
          hfSetMsg(productId, 'Guion regenerado. Aplicando tu personalización…', false);
          fetch(@json(route('admin.store.marketing.hyperframes.confirm')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({
              job_id: jobId,
              texts: rev.edits.texts,
              visual_style: rev.edits.visual_style,
              exclude_images: rev.edits.exclude_images,
              exclude_videos: rev.edits.exclude_videos
            })
          }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j }; }).catch(function () {
              return { ok: false, j: { ok: false, message: 'Respuesta inválida al aplicar la personalización (HTTP ' + r.status + ')' } };
            });
          }).then(function (pack) {
            if (!pack.j || !pack.j.ok) {
              hfFinish(productId, btn, (pack.j && pack.j.message) ? pack.j.message : 'No se pudo aplicar la personalización.', { error: true });
              return;
            }
            hfSetMsg(productId, pack.j.message || 'Render en curso…', false);
            if (hfJobs[productId]) {
              hfJobs[productId].jobId = pack.j.job_id;
              hfJobs[productId].stopped = false;
            }
            hfSetCancelVisible(productId, true, pack.j.job_id);
            pollHyperframes(pack.j.job_id, productId, btn);
          }).catch(function () {
            hfFinish(productId, btn, 'Error de red al aplicar la personalización.', { error: true });
          });
          return;
        }
        hfSetMsg(productId, 'Guion y medios listos. Revisa la personalización…', false);
        hfOpenReview(productId, btn, res.review_payload, jobId);
        return;
      }
      if (res.cancelled || res.status === 'cancelled') {
        hfUpdateProgress(productId, res);
        hfFinish(productId, btn, res.message || 'Generación detenida', { error: false });
        return;
      }
      if (res.failed || res.status === 'failed' || (!pack.okHttp && res.message)) {
        hfUpdateProgress(productId, res);
        hfFinish(productId, btn, res.message || 'Falló HyperFrames', { error: true });
        return;
      }
      if (res.done || res.status === 'done') {
        hfUpdateProgress(productId, res);
        var queue = (hfJobs[productId] && hfJobs[productId].queue) ? hfJobs[productId].queue : [];
        var doneCount = (hfJobs[productId] && hfJobs[productId].doneCount) ? hfJobs[productId].doneCount : 0;
        doneCount += 1;
        if (hfJobs[productId]) hfJobs[productId].doneCount = doneCount;
        if (queue.length > 0) {
          hfSetMsg(productId, 'Video ' + doneCount + ' listo. Siguiente fuente…', false);
          hfStartNextHyperframes(productId, btn);
          return;
        }
        hfSetCancelVisible(productId, false);
        var total = (hfJobs[productId] && hfJobs[productId].total) ? hfJobs[productId].total : doneCount;
        hfSetMsg(productId, (total > 1 ? (total + ' videos listos') : 'Video listo') + '. Recargando…', false);
        var els = hfProgressEls(productId);
        if (els.bar) els.bar.style.width = '100%';
        if (els.step) els.step.textContent = '8/8';
        setTimeout(function () {
          window.location.href = productosUrl(productId);
        }, 3500);
        return;
      }
      if (res.status === 'unknown') {
        hfFinish(productId, btn, res.message || 'Job HyperFrames no encontrado (¿cache reiniciado?)', { error: true });
        return;
      }
      hfUpdateProgress(productId, res);
      hfSetCancelVisible(productId, true, jobId);
      var t = setTimeout(function () { pollHyperframes(jobId, productId, btn); }, 2000);
      if (hfJobs[productId]) hfJobs[productId].timer = t;
    }).catch(function () {
      if (hfJobs[productId] && hfJobs[productId].stopped) return;
      hfFinish(productId, btn, 'Error de red al consultar HyperFrames', { error: true });
    });
  }
  $(document).on('click', '.md-hyperframes-cancel', function () {
    var cancelBtn = this;
    var productId = cancelBtn.getAttribute('data-product-id');
    var jobId = cancelBtn.getAttribute('data-job-id') || (hfJobs[productId] && hfJobs[productId].jobId);
    if (!productId || !jobId) return;
    cancelBtn.disabled = true;
    if (hfJobs[productId]) {
      hfJobs[productId].stopped = true;
      hfJobs[productId].queue = [];
    }
    var goBtn = document.querySelector('.md-hyperframes-go[data-product-id="' + productId + '"]');
    hfSetMsg(productId, 'Deteniendo generación…', false);
    fetch(@json(route('admin.store.marketing.hyperframes.cancel')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      hfFinish(productId, goBtn, (res && res.message) ? res.message : 'Generación detenida');
    }).catch(function () {
      hfFinish(productId, goBtn, 'No se pudo confirmar la cancelación (el job puede seguir un momento).', { error: true });
    });
  });

  function hfCollectSources(productId) {
    return [{ use_miia: true, prompt_id: null, label: 'MIIA' }];
  }

  function hfStartNextHyperframes(productId, btn) {
    var state = hfJobs[productId];
    if (!state || state.stopped) return;
    var next = (state.queue || []).shift();
    if (!next) {
      hfFinish(productId, btn, 'Listo');
      return;
    }
    var campaign = btn.getAttribute('data-campaign-id') || campaignId;
    var styleSel = document.querySelector('.md-hyperframes-style[data-product-id="' + productId + '"]');
    var visualStyle = styleSel ? styleSel.value : 'signal';
    var idx = (state.doneCount || 0) + 1;
    var total = state.total || 1;
    var startMsg = idx + '/' + total + ' · ' + next.label + ' · estilo ' + visualStyle + '…';
    hfUpdateProgress(productId, { step: 0, steps: 8, message: startMsg });
    hfSetCancelVisible(productId, true, null);
    var body = {
      campaign_id: parseInt(campaign, 10),
      product_id: parseInt(productId, 10),
      use_miia: !!next.use_miia,
      visual_style: visualStyle
    };
    if (!next.use_miia && next.prompt_id) body.prompt_id = next.prompt_id;
    fetch(@json(route('admin.store.marketing.hyperframes.generate')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, j: j }; }).catch(function () {
        return { ok: false, j: { ok: false, message: 'Respuesta inválida del servidor al iniciar HyperFrames (HTTP ' + r.status + ')' } };
      });
    }).then(function (pack) {
      if (!pack.j || !pack.j.ok) {
        hfFinish(productId, btn, (pack.j && pack.j.message) ? pack.j.message : 'No se pudo iniciar HyperFrames', { error: true });
        return;
      }
      hfSetMsg(productId, pack.j.message || startMsg, false);
      if (hfJobs[productId]) {
        hfJobs[productId].jobId = pack.j.job_id;
        hfJobs[productId].stopped = false;
      }
      hfSetCancelVisible(productId, true, pack.j.job_id);
      pollHyperframes(pack.j.job_id, productId, btn);
    }).catch(function () {
      hfFinish(productId, btn, 'Error de red al iniciar HyperFrames. Si el scrape Cloudflare tarda, espera e intenta de nuevo.', { error: true });
    });
  }

  $(document).on('click', '.md-hyperframes-go', function () {
    var btn = this;
    var productId = btn.getAttribute('data-product-id');
    var campaign = btn.getAttribute('data-campaign-id') || campaignId;
    if (!productId) return;
    var sources = hfCollectSources(productId);
    if (!sources.length) {
      hfSetMsg(productId, 'Marca “Usar MIIA para guiones” y/o al menos un prompt.', true);
      return;
    }
    var styleSel = document.querySelector('.md-hyperframes-style[data-product-id="' + productId + '"]');
    if (!styleSel || !styleSel.value) {
      hfSetMsg(productId, 'Elige un estilo visual.', true);
      return;
    }
    btn.disabled = true;
    hfJobs[productId] = {
      jobId: null,
      stopped: false,
      timer: null,
      queue: sources.slice(),
      doneCount: 0,
      total: sources.length
    };
    hfUpdateProgress(productId, {
      step: 0,
      steps: 8,
      message: '0/8 Preparando ' + sources.length + ' render(s) · ' + styleSel.value + '…'
    });
    hfStartNextHyperframes(productId, btn);
  });

  // ---- Modal de revisión HyperFrames: edita textos, archivos y estilo antes de renderizar ----
  var hfReview = { productId: null, btn: null, payload: null, jobId: null };
  var hfRegenResume = null;

  function hfEsc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function hfReviewEls() {
    var modal = document.getElementById('md-hf-review-modal');
    return {
      modal: modal,
      body: modal ? modal.querySelector('.md-hf-review-body') : null,
      product: modal ? modal.querySelector('.md-hf-review-product') : null,
      hint: modal ? modal.querySelector('.md-hf-review-hint') : null
    };
  }

  function hfReviewInput(label, path, value, opts) {
    opts = opts || {};
    var v = hfEsc(value == null ? '' : value);
    var multi = opts.rows || (value && String(value).length > 90);
    var field = multi
      ? '<textarea data-hf-path="' + hfEsc(path) + '" class="admin-input w-full text-sm" rows="' + (opts.rows || 3) + '">' + v + '</textarea>'
      : '<input type="text" data-hf-path="' + hfEsc(path) + '" class="admin-input w-full text-sm" value="' + v + '">';
    return '<div class="space-y-1">' +
      '<label class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">' + hfEsc(label) + '</label>' +
      field +
      '</div>';
  }

  function hfAssetGroup(label, items, kind) {
    var html = '<div class="space-y-1.5">';
    html += '<p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">' + hfEsc(label) + '</p>';
    html += '<div class="flex flex-wrap gap-2">';
    items.forEach(function (item) {
      var sizeKb = Math.max(1, Math.round((item.size || 0) / 1024));
      var name = String(item.name || '');
      var thumb = '';
      if (kind === 'image' && item.url) {
        thumb = '<img src="' + hfEsc(item.url) + '" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover" onerror="this.parentNode.classList.add(\'hf-thumb-fallback\');this.remove()">';
      } else if (kind === 'image') {
        thumb = '<span class="hf-thumb-glyph">🖼</span>';
      } else {
        thumb = '<span class="hf-thumb-glyph"><i class="fa-solid fa-play"></i></span>';
      }
      html += '<label class="md-hf-asset relative h-[140px] w-[140px] shrink-0 cursor-pointer overflow-hidden rounded-xl border border-line bg-mist/40 select-none" title="' + hfEsc(name) + '">' +
        '<input type="checkbox" class="md-hf-asset-cb absolute left-1.5 top-1.5 z-[1] h-3.5 w-3.5 rounded border-line accent-teal" data-hf-asset="' + hfEsc(name) + '" checked>' +
        '<span class="md-hf-thumb absolute inset-0">' + thumb + '</span>' +
        '<span class="absolute inset-x-0 bottom-0 truncate bg-white/80 px-1 py-0.5 text-[9px] text-ink">' + hfEsc(name) + '</span>' +
        '<span class="absolute right-1.5 top-1.5 z-[1] rounded-full bg-ink/60 px-1 py-px text-[8px] font-semibold text-white">' + sizeKb + ' KB</span>' +
        '</label>';
    });
    html += '</div>';
    html += '</div>';
    return html;
  }

  function hfOpenReview(productId, btn, payload, jobId) {
    var els = hfReviewEls();
    if (!els.modal || !els.body) return;
    hfReview = { productId: productId, btn: btn, payload: payload || {}, jobId: jobId };

    var prod = payload.product || {};
    var hasPlan = !!payload.has_plan;
    var brief = payload.brief || {};
    var html = '';

    if (els.product) {
      els.product.textContent = (prod.name || 'Producto') + (prod.sku ? ' · ' + prod.sku : '');
    }

    html += '<div class="rounded-xl border border-line bg-mist/30 px-3 py-2 text-xs text-ink-soft/70 grid grid-cols-2 gap-x-4 gap-y-1">';
    if (prod.price != null) html += '<div>Precio: <strong class="text-ink">' + hfEsc(prod.currency || '') + ' ' + hfEsc(String(prod.price)) + '</strong></div>';
    if (prod.compare_at_price != null) html += '<div>Antes: <s class="text-ink-soft/60">' + hfEsc(String(prod.compare_at_price)) + '</s></div>';
    if (prod.badge) html += '<div>Badge: ' + hfEsc(prod.badge) + '</div>';
    if (prod.product_url) html += '<div class="col-span-2 truncate"><a class="text-teal underline" href="' + hfEsc(prod.product_url) + '" target="_blank" rel="noopener">' + hfEsc(prod.product_url) + '</a></div>';
    html += '</div>';

    html += '<div class="space-y-1"><label class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Estilo visual</label><select id="md-hf-review-style" class="admin-input w-full max-w-md text-sm">';
    Object.keys(payload.styles || {}).forEach(function (k) {
      var s = payload.styles[k] || {};
      html += '<option value="' + hfEsc(k) + '"' + (k === (payload.options || {}).visual_style ? ' selected' : '') + '>' + hfEsc(s.label || k) + (s.hint ? ' — ' + hfEsc(s.hint) : '') + '</option>';
    });
    html += '</select></div>';

    var hookPath = hasPlan ? 'plan.creative.hook' : 'prompt.hook';
    var ctaPath = hasPlan ? 'plan.creative.cta' : 'prompt.cta';
    html += '<div class="space-y-2 rounded-xl border border-line p-3">';
    html += '<p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Guion</p>';
    html += hfReviewInput('Hook', hookPath, brief.hook, {});
    html += hfReviewInput('CTA', ctaPath, brief.cta, {});
    html += hfReviewInput('Guion completo (texto)', 'prompt.script', brief.script, { rows: 6 });
    html += '</div>';

    var extra = (payload.creative || []).filter(function (c) {
      return c.path !== 'plan.creative.hook' && c.path !== 'plan.creative.cta';
    });
    if (extra.length) {
      html += '<div class="space-y-2 rounded-xl border border-line p-3">';
      html += '<p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Dirección creativa</p>';
      extra.forEach(function (c) { html += hfReviewInput(c.label, c.path, c.value, {}); });
      html += '</div>';
    }

    var scenes = payload.scenes || [];
    if (scenes.length) {
      html += '<div class="space-y-2"><p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Escenas del plan (' + scenes.length + ')</p>';
      scenes.forEach(function (sc, i) {
        html += '<div class="rounded-xl border border-line p-3 space-y-2"><p class="text-[11px] font-medium uppercase tracking-wide text-teal">Escena ' + (i + 1) + (sc.purpose ? ' · ' + hfEsc(sc.purpose) : '') + '</p>';
        sc.nodes.forEach(function (n) { html += hfReviewInput(n.label, n.path, n.value, {}); });
        html += '</div>';
      });
      html += '</div>';
    }

    var dialogue = payload.dialogue || [];
    if (dialogue.length) {
      html += '<div class="space-y-2"><p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Narración</p>';
      dialogue.forEach(function (d) { html += hfReviewInput(d.label, d.path, d.value, {}); });
      html += '</div>';
    }

    var files = payload.files || {};
    var images = files.images || [];
    var videos = files.videos || [];
    html += '<div class="space-y-2 rounded-xl border border-line p-3">';
    html += '<p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Archivos que se enviarán</p>';
    if (!images.length && !videos.length) html += '<p class="text-xs text-ink-soft/60">No se detectaron medios descargados para este producto.</p>';
    if (images.length) html += hfAssetGroup('Imágenes', images, 'image');
    if (videos.length) html += hfAssetGroup('Videos de producto', videos, 'video');
    html += '</div>';

    els.body.innerHTML = html;
    els.modal.classList.remove('hidden');
    els.modal.classList.add('flex');
    els.modal.setAttribute('aria-hidden', 'false');
  }

  function hfHideReview() {
    var els = hfReviewEls();
    if (!els.modal) return;
    els.modal.classList.add('hidden');
    els.modal.classList.remove('flex');
    els.modal.setAttribute('aria-hidden', 'true');
  }

  function hfCollectReviewEdits() {
    var els = hfReviewEls();
    var texts = {};
    if (els.body) {
      els.body.querySelectorAll('[data-hf-path]').forEach(function (el) {
        var path = el.getAttribute('data-hf-path');
        if (!path) return;
        texts[path] = el.value == null ? '' : String(el.value);
      });
    }
    var styleSel = document.getElementById('md-hf-review-style');
    var excludeImages = [];
    var excludeVideos = [];
    if (els.body) {
      els.body.querySelectorAll('[data-hf-asset]').forEach(function (cb) {
        if (cb.checked) return;
        var name = cb.getAttribute('data-hf-asset');
        if (!name) return;
        var dot = name.lastIndexOf('.');
        if (dot === -1) return;
        var ext = name.slice(dot + 1).toLowerCase();
        if (['mp4', 'webm', 'mov', 'm4v'].indexOf(ext) !== -1) excludeVideos.push(name);
        else excludeImages.push(name);
      });
    }
    return {
      texts: texts,
      visual_style: styleSel ? styleSel.value : (hfReview.payload.options || {}).visual_style,
      exclude_images: excludeImages,
      exclude_videos: excludeVideos
    };
  }

  $(document).on('click', '#md-hf-review-close', function () {
    var state = hfReview;
    if (!state.jobId) { hfHideReview(); return; }
    hfHideReview();
    var productId = state.productId;
    if (hfJobs[productId]) {
      hfJobs[productId].stopped = true;
      hfJobs[productId].queue = [];
    }
    var goBtn = document.querySelector('.md-hyperframes-go[data-product-id="' + productId + '"]');
    hfSetMsg(productId, 'Cancelando revisión…', false);
    fetch(@json(route('admin.store.marketing.hyperframes.cancel')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: state.jobId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      hfFinish(productId, goBtn, (res && res.message) ? res.message : 'Generación cancelada');
    }).catch(function () {
      hfFinish(productId, goBtn, 'No se pudo confirmar la cancelación; el job puede quedar en pausa.', { error: true });
    });
  });

  $(document).on('click', '.md-hf-review-submit', function () {
    var state = hfReview;
    var btn = this;
    var isRegen = !!(state && state.payload && state.payload.video_id);
    if (!state || (!state.jobId && !isRegen)) return;
    var productId = state.productId;
    var goBtn = state.btn || document.querySelector('.md-hyperframes-go[data-product-id="' + productId + '"]');
    var edits = hfCollectReviewEdits();
    var hint = hfReviewEls().hint;

    btn.disabled = true;
    if (hint) { hint.textContent = isRegen ? 'Regenerando guion y encolando render…' : 'Enviando y comenzando render…'; hint.classList.remove('text-red-700'); }

    var endpoint = isRegen
      ? @json(route('admin.store.marketing.hyperframes.regenerate'))
      : @json(route('admin.store.marketing.hyperframes.confirm'));
    var payload = isRegen ? {
      campaign_id: parseInt(state.payload.campaign_id, 10) || 0,
      product_id: parseInt(state.payload.product_id, 10) || parseInt(productId, 10) || 0,
      video_id: parseInt(state.payload.video_id, 10) || 0,
      texts: edits.texts,
      visual_style: edits.visual_style
    } : {
      job_id: state.jobId,
      texts: edits.texts,
      visual_style: edits.visual_style,
      exclude_images: edits.exclude_images,
      exclude_videos: edits.exclude_videos
    };

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, j: j }; }).catch(function () {
        return { ok: false, j: { ok: false, message: 'Respuesta inválida del servidor (HTTP ' + r.status + ')' } };
      });
    }).then(function (pack) {
      btn.disabled = false;
      if (!pack.j || !pack.j.ok) {
        if (hint) { hint.textContent = (pack.j && pack.j.message) ? pack.j.message : 'No se pudo completar la operación.'; hint.classList.add('text-red-700'); }
        return;
      }
      hfHideReview();
      hfSetMsg(productId, pack.j.message || (isRegen ? 'Regenerando video…' : 'Render iniciado con tu revisión…'), false);
      if (isRegen) {
        if (hfJobs[productId]) hfJobs[productId].stopped = true;
        hfJobs[productId] = { jobId: pack.j.job_id, stopped: false, timer: null, queue: [], doneCount: 0, total: 1 };
        hfRegenResume = { productId: productId, jobId: pack.j.job_id, edits: edits };
      } else {
        if (hfJobs[productId]) {
          hfJobs[productId].jobId = pack.j.job_id;
          hfJobs[productId].stopped = false;
          hfJobs[productId].queue = [];
        }
      }
      hfSetCancelVisible(productId, true, pack.j.job_id);
      pollHyperframes(pack.j.job_id, productId, goBtn);
    }).catch(function () {
      btn.disabled = false;
      if (hint) { hint.textContent = isRegen ? 'Error de red al regenerar. Intenta de nuevo.' : 'Error de red al confirmar la revisión. Intenta de nuevo.'; hint.classList.add('text-red-700'); }
    });
  });

  $(document).on('click', '[data-md-hf-regen]', function () {
    var regenBtn = this;
    var videoId = regenBtn.getAttribute('data-video-id');
    var productId = regenBtn.getAttribute('data-product-id');
    if (!videoId || !productId) return;
    regenBtn.disabled = true;
    var goBtn = document.querySelector('.md-hyperframes-go[data-product-id="' + productId + '"]');
    hfSetMsg(productId, 'Cargando configuración del video…', false);
    fetch(@json(route('admin.store.marketing.hyperframes.regenerate-payload')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ video_id: parseInt(videoId, 10) })
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, j: j }; }).catch(function () {
        return { ok: false, j: { ok: false, message: 'Respuesta inválida al cargar el video (HTTP ' + r.status + ')' } };
      });
    }).then(function (pack) {
      regenBtn.disabled = false;
      if (!pack.j || !pack.j.ok || !pack.j.payload) {
        hfSetMsg(productId, (pack.j && pack.j.message) ? pack.j.message : 'No se pudo abrir el modal de regeneración.', true);
        return;
      }
      hfOpenReview(productId, goBtn, pack.j.payload, null);
      var submitBtn = document.querySelector('.md-hf-review-submit');
      if (submitBtn) submitBtn.textContent = 'Regenerar video';
    }).catch(function () {
      regenBtn.disabled = false;
      hfSetMsg(productId, 'Error de red al cargar la configuración del video.', true);
    });
  });

  var aiState = { productId: null, segments: [], analysis: {}, prompt: null, savedPromptId: null };
  var aiGen = document.getElementById('md-ai-generate');
  var aiSave = document.getElementById('md-ai-save');
  var aiCf = document.getElementById('md-ai-creatify');
  var aiMsg = document.getElementById('md-ai-msg');
  var aiProductSelect = document.getElementById('md-ai-product');
  var aiProductSearch = document.getElementById('md-ai-product-search');
  var aiProductHint = document.getElementById('md-ai-product-hint');
  var catalogProductsAll = @json($catalogProductsJson);

  function renderProductOptions(products) {
    if (!aiProductSelect) return;
    var list = products || [];
    aiProductSelect.innerHTML = '';
    if (!list.length) {
      var empty = document.createElement('option');
      empty.value = '';
      empty.textContent = '— No hay productos en esta tienda —';
      aiProductSelect.appendChild(empty);
      if (aiProductHint) {
        aiProductHint.innerHTML = 'No hay productos. <a class="text-teal underline" href="{{ route('admin.store.products.create') }}">Crear producto</a>';
      }
      return;
    }
    list.forEach(function (p) {
      var opt = document.createElement('option');
      opt.value = String(p.id);
      var status = p.status && p.status !== 'live' ? ' (' + p.status + ')' : '';
      var sku = p.sku ? ' · ' + p.sku : '';
      var combo = p.is_combo ? ' · combo' : '';
      opt.textContent = '#' + p.id + ' · ' + (p.name || 'Producto') + sku + combo + status;
      aiProductSelect.appendChild(opt);
    });
    if (aiProductHint) {
      aiProductHint.textContent = list.length + ' producto(s). Selecciona uno de la lista.';
    }
  }

  function filterProductOptions() {
    if (!aiProductSearch) return;
    var q = (aiProductSearch.value || '').toLowerCase().trim();
    if (!q) {
      renderProductOptions(catalogProductsAll);
      return;
    }
    var filtered = catalogProductsAll.filter(function (p) {
      var hay = ((p.name || '') + ' ' + (p.sku || '') + ' ' + (p.slug || '') + ' ' + p.id + (p.is_combo ? ' combo' : '')).toLowerCase();
      return hay.indexOf(q) !== -1;
    });
    renderProductOptions(filtered);
  }

  function loadCatalogProducts() {
    if (!aiProductSelect && !campAddList) return;
    var url = @json(route('admin.store.marketing.prompts.catalog-products'));
    var q = aiProductSearch ? aiProductSearch.value.trim() : '';
    if (q) url += (url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.message || 'No se pudo cargar el catálogo');
        catalogProductsAll = res.products || [];
        renderProductOptions(catalogProductsAll);
        renderCampAddList();
        if (focusProductId) selectAiProduct(focusProductId, focusProductName, false);
      })
      .catch(function (err) {
        if (aiProductHint) aiProductHint.textContent = err.message || 'Error al cargar productos';
        if (aiProductSelect && aiProductSelect.options.length <= 1) {
          aiProductSelect.innerHTML = '<option value="">— Error al cargar productos —</option>';
        }
        renderCampAddList();
      });
  }

  if (aiProductSearch) {
    var searchTimer;
    aiProductSearch.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (catalogProductsAll.length) {
          filterProductOptions();
        } else {
          loadCatalogProducts();
        }
      }, 300);
    });
  }

  function openAiModal() {
    var modal = document.getElementById('md-ai-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
  }
  function closeAiModal() {
    var modal = document.getElementById('md-ai-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
  }
  function selectAiProduct(productId, productName, scroll) {
    if (!aiProductSelect || !productId) return;
    var id = String(productId);
    aiProductSelect.value = id;
    if (aiProductSelect.value !== id) {
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = productName ? productName : ('#' + id);
      aiProductSelect.appendChild(opt);
      aiProductSelect.value = id;
    }
    if (scroll === false) return;
    openAiModal();
  }
  $(document).on('click', '[data-md-ai-open]', function (e) {
    e.preventDefault();
    var pid = this.getAttribute('data-use-in-prompt');
    if (pid) selectAiProduct(pid, this.getAttribute('data-product-name') || '');
    else openAiModal();
  });
  $(document).on('click', '[data-md-ai-close]', function (e) {
    e.preventDefault();
    closeAiModal();
  });
  $(document).on('click', '#md-ai-modal', function (e) {
    if (e.target === this) closeAiModal();
  });

  var campAddSearch = document.getElementById('md-camp-add-search');
  var campAddList = document.getElementById('md-camp-add-list');
  var campAddForm = document.getElementById('md-camp-add-form');
  var campAddHint = document.getElementById('md-camp-add-hint');
  var campAddHidden = document.getElementById('md-camp-add-hidden');
  var campAddSelected = document.getElementById('md-camp-add-selected');
  var campAddPicked = {};

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function updateCampAddSelectedSummary() {
    if (!campAddSelected) return;
    var ids = Object.keys(campAddPicked);
    if (!ids.length) {
      campAddSelected.classList.add('hidden');
      campAddSelected.textContent = '';
      return;
    }
    campAddSelected.classList.remove('hidden');
    campAddSelected.textContent = ids.length === 1
      ? '1 producto seleccionado'
      : ids.length + ' productos seleccionados';
  }

  function renderCampAddList() {
    if (!campAddList) return;
    var q = campAddSearch ? (campAddSearch.value || '').toLowerCase().trim() : '';
    var attached = {};
    (attachedProductIds || []).forEach(function (id) { attached[String(id)] = true; });
    var list = (catalogProductsAll || []).filter(function (p) {
      if (attached[String(p.id)]) return false;
      if (!q) return true;
      var hay = ((p.name || '') + ' ' + (p.sku || '') + ' ' + (p.slug || '') + ' ' + p.id + (p.is_combo ? ' combo' : '')).toLowerCase();
      return hay.indexOf(q) !== -1;
    });
    campAddList.innerHTML = '';
    if (!list.length) {
      var empty = document.createElement('p');
      empty.className = 'px-3 py-4 text-sm text-ink-soft/55';
      if (!catalogProductsAll.length) {
        empty.textContent = 'No hay productos disponibles.';
      } else if (q) {
        empty.textContent = 'Sin coincidencias.';
      } else {
        empty.textContent = 'Todos los productos ya están en la campaña.';
      }
      campAddList.appendChild(empty);
      updateCampAddSelectedSummary();
      return;
    }
    list.forEach(function (p) {
      var id = String(p.id);
      var selected = !!campAddPicked[id];
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.setAttribute('role', 'option');
      btn.setAttribute('aria-selected', selected ? 'true' : 'false');
      btn.dataset.productId = id;
      btn.className = 'flex w-full items-center gap-3 px-2.5 py-2 text-left transition hover:bg-mist/50 ' + (selected ? 'bg-teal/10' : '');
      var imgHtml = p.image_url
        ? '<img src="' + escHtml(p.image_url) + '" alt="" class="h-12 w-12 shrink-0 rounded-md border border-line object-cover bg-mist">'
        : '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md border border-line bg-mist text-[10px] text-ink-soft/45">N/A</span>';
      var meta = [];
      if (p.sku) meta.push(escHtml(p.sku));
      if (p.is_combo) meta.push('combo');
      var priceLabel = p.price_label || (p.price ? (Number(p.price).toFixed(2) + (p.currency ? ' ' + p.currency : '')) : '—');
      btn.innerHTML =
        imgHtml +
        '<span class="min-w-0 flex-1">' +
          '<span class="block truncate text-sm font-medium text-ink">' + escHtml(p.name || ('#' + id)) + '</span>' +
          '<span class="mt-0.5 block truncate text-[11px] text-ink-soft/55">#' + escHtml(id) + (meta.length ? ' · ' + meta.join(' · ') : '') + '</span>' +
        '</span>' +
        '<span class="shrink-0 text-right text-sm font-semibold text-ink tabular-nums">' + escHtml(priceLabel) + '</span>';
      btn.addEventListener('click', function () {
        if (campAddPicked[id]) {
          delete campAddPicked[id];
        } else {
          campAddPicked[id] = true;
        }
        renderCampAddList();
      });
      campAddList.appendChild(btn);
    });
    if (campAddHint) {
      var n = Object.keys(campAddPicked).length;
      campAddHint.textContent = list.length + ' resultado(s)' + (n ? ' · ' + n + ' seleccionado(s)' : '') + '. Clic para marcar.';
    }
    updateCampAddSelectedSummary();
  }
  if (campAddSearch) {
    var addTimer;
    campAddSearch.addEventListener('input', function () {
      clearTimeout(addTimer);
      addTimer = setTimeout(renderCampAddList, 180);
    });
  }
  if (campAddForm) {
    campAddForm.addEventListener('submit', function () {
      if (!campAddHidden) return;
      campAddHidden.innerHTML = '';
      Object.keys(campAddPicked).forEach(function (id) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = id;
        campAddHidden.appendChild(input);
      });
    });
  }

  function openCampAddModal() {
    var modal = document.getElementById('md-camp-add-modal');
    if (!modal) return;
    campAddPicked = {};
    renderCampAddList();
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    if (campAddSearch) {
      setTimeout(function () { campAddSearch.focus(); }, 50);
    }
  }
  function closeCampAddModal() {
    var modal = document.getElementById('md-camp-add-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden', 'true');
  }
  $(document).on('click', '[data-md-camp-add-open]', function (e) {
    e.preventDefault();
    if (!catalogProductsAll.length) {
      loadCatalogProducts();
    }
    openCampAddModal();
  });
  $(document).on('click', '[data-md-camp-add-close]', function (e) {
    e.preventDefault();
    closeCampAddModal();
  });
  $(document).on('click', '#md-camp-add-modal', function (e) {
    if (e.target === this) closeCampAddModal();
  });
  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var addModal = document.getElementById('md-camp-add-modal');
    if (addModal && !addModal.classList.contains('hidden')) {
      closeCampAddModal();
      return;
    }
    var aiModal = document.getElementById('md-ai-modal');
    if (aiModal && !aiModal.classList.contains('hidden')) {
      closeAiModal();
    }
  });

  if (catalogProductsAll.length) {
    renderCampAddList();
    if (aiProductSelect) {
      renderProductOptions(catalogProductsAll);
      if (focusProductId) selectAiProduct(focusProductId, focusProductName, false);
    }
  } else if (campAddList || aiProductSelect) {
    loadCatalogProducts();
  }

  function fillPromptForm(data) {
    var map = {
      'md-prompt-name': data.name || '',
      'md-prompt-hook': data.hook || '',
      'md-prompt-script': data.script || '',
      'md-prompt-audience': data.audience || '',
      'md-prompt-style': data.style || 'DynamicProductTemplate',
      'md-prompt-product-id': aiState.productId || ''
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.value = map[id];
    });
    var segEl = document.getElementById('md-prompt-segments');
    var anaEl = document.getElementById('md-prompt-analysis');
    if (segEl) segEl.value = JSON.stringify(aiState.segments || []);
    if (anaEl) anaEl.value = JSON.stringify(aiState.analysis || {});
  }

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function renderAiPreview(res) {
    var analysisBox = document.getElementById('md-ai-analysis');
    var segWrap = document.getElementById('md-ai-segments-wrap');
    var segBody = document.getElementById('md-ai-segments');
    if (analysisBox && res.analysis) {
      analysisBox.classList.remove('hidden');
      var cd = res.analysis.creative_direction || {};
      var talent = cd.talent || {};
      var camera = cd.camera || {};
      var html = ''
        + '<div><strong>Resumen:</strong> ' + esc(res.analysis.summary || '—') + '</div>'
        + '<div><strong>Ángulo:</strong> ' + esc(res.analysis.product_angle || '—') + '</div>'
        + '<div><strong>Casting:</strong> ' + esc(res.analysis.casting_notes || talent.profile || '—') + '</div>'
        + '<div><strong>Talento:</strong> ' + esc([talent.wardrobe, talent.energy, talent.setting].filter(Boolean).join(' · ') || '—') + '</div>'
        + '<div><strong>Cámara:</strong> ' + esc(res.analysis.camera_notes || [camera.style, camera.lens, camera.movement].filter(Boolean).join(' · ') || '—') + '</div>'
        + '<div><strong>Formato:</strong> ' + esc(res.analysis.recommended_format || 'mixed') + '</div>';
      analysisBox.innerHTML = html;
    }
    if (segBody && res.segments) {
      segBody.innerHTML = '';
      res.segments.forEach(function (s) {
        var tr = document.createElement('tr');
        tr.className = 'border-b border-line/60 align-top';
        tr.innerHTML =
          '<td class="py-2 pr-2 whitespace-nowrap">' + (s.start ?? 0) + '–' + (s.end ?? 0) + 's</td>' +
          '<td class="py-2 pr-2">' + esc(s.type || '') + '</td>' +
          '<td class="py-2 pr-2 max-w-[200px]">' + esc(s.voiceover || '') + '</td>' +
          '<td class="py-2 pr-2 max-w-[180px] text-ink-soft/80">' + esc(s.talent || '') + '</td>' +
          '<td class="py-2 pr-2 max-w-[160px] text-ink-soft/80">' + esc(s.camera || '') + '</td>' +
          '<td class="py-2 text-ink-soft/70 max-w-[180px]">' + esc(s.visual || '') + '</td>';
        segBody.appendChild(tr);
      });
      if (segWrap) segWrap.classList.remove('hidden');
    }
    if (aiSave) aiSave.classList.remove('hidden');
    if (aiCf) aiCf.classList.remove('hidden');
  }

  function callAiGenerate(save, sendCreatify) {
    var productId = aiProductSelect && aiProductSelect.value;
    if (!productId) { if (aiMsg) aiMsg.textContent = 'Elige un producto.'; return; }
    var lengthEl = document.getElementById('md-ai-length');
    var langEl = document.getElementById('md-ai-language');
    if (aiGen) aiGen.disabled = true;
    if (aiSave) aiSave.disabled = true;
    if (aiCf) aiCf.disabled = true;
    if (aiMsg) aiMsg.textContent = 'MIIA analizando producto y redactando brief completo (cámara, talento, segmentos)… puede tardar 2–3 min.';
    fetch(@json(route('admin.store.marketing.prompts.generate-from-product')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({
        product_id: parseInt(productId, 10),
        video_length: lengthEl ? parseInt(lengthEl.value, 10) : 21,
        language: langEl ? langEl.value : @json($store->configuredLocale()),
        target_platform: 'Tiktok',
        save: !!save,
        campaign_id: campaignId
      })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (pack) {
      if (aiGen) aiGen.disabled = false;
      if (aiSave) aiSave.disabled = false;
      if (aiCf) aiCf.disabled = false;
      if (!pack.j.ok) {
        if (aiMsg) aiMsg.textContent = pack.j.message || 'Error al generar';
        if (pack.j.debug_snippet) console.warn('MIIA respuesta (truncada):', pack.j.debug_snippet);
        return;
      }
      aiState.productId = productId;
      aiState.segments = pack.j.segments || [];
      aiState.analysis = pack.j.analysis || {};
      aiState.prompt = pack.j.prompt || {};
      aiState.savedPromptId = pack.j.prompt_id || aiState.savedPromptId;
      fillPromptForm(pack.j.prompt || {});
      renderAiPreview(pack.j);
      var media = pack.j.media || {};
      var extra = (media.image_urls && media.image_urls.length ? ' · ' + media.image_urls.length + ' imgs' : '') +
        (media.video_urls && media.video_urls.length ? ' · ' + media.video_urls.length + ' videos' : '');
      if (aiMsg) aiMsg.textContent = 'Prompt listo para ' + (pack.j.product && pack.j.product.name ? pack.j.product.name : 'producto') + extra + (pack.j.prompt_id ? ' · Guardado #' + pack.j.prompt_id : '');
      if (save && pack.j.prompt_id && sendCreatify && aiCf) {
        if (aiMsg) aiMsg.textContent = 'Prompt guardado. Enviando a Creatify…';
        aiCf.disabled = true;
        startCreatify(pack.j.prompt_id, function (t) { if (aiMsg) aiMsg.textContent = t; }, function (err) {
          if (aiMsg) aiMsg.textContent = err;
          aiCf.disabled = false;
        });
      } else if (save && pack.j.prompt_id && !sendCreatify) {
        window.location.reload();
      } else if (save && !pack.j.prompt_id) {
        window.location.reload();
      }
    }).catch(function () {
      if (aiGen) aiGen.disabled = false;
      if (aiSave) aiSave.disabled = false;
      if (aiCf) aiCf.disabled = false;
      if (aiMsg) aiMsg.textContent = 'Error de red';
    });
  }

  if (aiGen) aiGen.addEventListener('click', function () { callAiGenerate(false, false); });
  if (aiSave) aiSave.addEventListener('click', function () { callAiGenerate(true, false); });
  if (aiCf) aiCf.addEventListener('click', function () { callAiGenerate(true, true); });

  // Colapsar Remotion / Subir video
  $(document).on('click', '[data-md-fold-toggle]', function () {
    var $fold = $(this).closest('[data-md-fold]');
    var $body = $fold.find('> [data-md-fold-body]');
    var collapsed = $fold.attr('data-md-fold-collapsed') !== undefined;
    if (collapsed) {
      $fold.removeAttr('data-md-fold-collapsed');
      $body.prop('hidden', false);
      this.setAttribute('aria-expanded', 'true');
    } else {
      $fold.attr('data-md-fold-collapsed', '');
      $body.prop('hidden', true);
      this.setAttribute('aria-expanded', 'false');
    }
  });

  // Seller Central: editar / programar plan / estado generar
  function scBtn(el, on) {
    if (!el) return;
    el.disabled = on;
    el.classList.toggle('opacity-60', on);
  }
  $(document).on('click', '[data-sc-toggle-edit]', function () {
    var id = this.getAttribute('data-sc-toggle-edit');
    $('[data-sc-edit-row="' + id + '"]').toggleClass('hidden');
  });

  $(document).on('click', '[data-sc-toggle-content]', function () {
    var btn = this;
    var wrap = btn.closest('[data-sc-content-wrap]');
    if (!wrap) return;
    var preview = wrap.querySelector('[data-sc-content-preview]');
    var full = wrap.querySelector('[data-sc-content-full]');
    var icon = btn.querySelector('[data-sc-content-icon]');
    if (!preview || !full) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    if (open) {
      preview.classList.remove('hidden');
      full.classList.add('hidden');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('title', 'Ver texto completo');
      btn.setAttribute('aria-label', 'Ver texto completo');
      if (icon) {
        icon.className = 'fa-solid fa-ellipsis text-[11px]';
        icon.setAttribute('data-sc-content-icon', '');
      }
    } else {
      preview.classList.add('hidden');
      full.classList.remove('hidden');
      btn.setAttribute('aria-expanded', 'true');
      btn.setAttribute('title', 'Ocultar texto');
      btn.setAttribute('aria-label', 'Ocultar texto');
      if (icon) {
        icon.className = 'fa-solid fa-chevron-up text-[10px]';
        icon.setAttribute('data-sc-content-icon', '');
      }
    }
  });

  function scPlanRoot(el) {
    return el.closest('[data-sc-plan-list]');
  }
  function scUpdateSelection(root) {
    if (!root) return;
    var checked = root.querySelectorAll('[data-sc-check]:checked');
    var visibleChecks = Array.prototype.filter.call(root.querySelectorAll('[data-sc-check]'), function (c) {
      var row = c.closest('[data-sc-pub-row]');
      return row && !row.classList.contains('hidden');
    });
    var countEl = root.querySelector('[data-sc-selected-count]');
    var runBtn = root.querySelector('[data-sc-bulk-run]');
    var n = checked.length;
    if (countEl) countEl.textContent = n + (n === 1 ? ' seleccionada' : ' seleccionadas');
    if (runBtn) runBtn.disabled = n === 0;
    var all = root.querySelector('[data-sc-check-all]');
    if (all) {
      all.checked = visibleChecks.length > 0 && visibleChecks.every(function (c) { return c.checked; });
      all.indeterminate = n > 0 && !all.checked;
    }
  }
  function scApplyFilters(root) {
    if (!root) return;
    var q = ((root.querySelector('[data-sc-filter-q]') || {}).value || '').toLowerCase().trim();
    var status = (root.querySelector('[data-sc-filter-status]') || {}).value || '';
    var network = (root.querySelector('[data-sc-filter-network]') || {}).value || '';
    var rows = root.querySelectorAll('[data-sc-pub-row]');
    var visible = 0;
    rows.forEach(function (row) {
      var ok = true;
      if (status && row.getAttribute('data-sc-status') !== status) ok = false;
      if (ok && network && row.getAttribute('data-sc-network') !== network) ok = false;
      if (ok && q) {
        var blob = row.getAttribute('data-sc-search') || '';
        if (blob.indexOf(q) === -1) ok = false;
      }
      row.classList.toggle('hidden', !ok);
      var edit = root.querySelector('[data-sc-edit-row="' + row.getAttribute('data-sc-pub-row') + '"]');
      if (edit && !ok) edit.classList.add('hidden');
      if (!ok) {
        var cb = row.querySelector('[data-sc-check]');
        if (cb) cb.checked = false;
      }
      if (ok) visible++;
    });
    var countEl = root.querySelector('[data-sc-visible-count]');
    if (countEl) countEl.textContent = String(visible);
    var empty = root.querySelector('[data-sc-empty-filter]');
    if (empty) empty.classList.toggle('hidden', visible > 0);
    var table = root.querySelector('table');
    if (table) table.classList.toggle('hidden', visible === 0);
    scUpdateSelection(root);
  }
  $(document).on('input change', '[data-sc-filter-q], [data-sc-filter-status], [data-sc-filter-network]', function () {
    scApplyFilters(scPlanRoot(this));
  });
  $(document).on('click', '[data-sc-filter-reset]', function () {
    var root = scPlanRoot(this);
    if (!root) return;
    var q = root.querySelector('[data-sc-filter-q]');
    var st = root.querySelector('[data-sc-filter-status]');
    var nw = root.querySelector('[data-sc-filter-network]');
    if (q) q.value = '';
    if (st) st.value = '';
    if (nw) nw.value = '';
    scApplyFilters(root);
  });
  $(document).on('change', '[data-sc-check-all]', function () {
    var root = scPlanRoot(this);
    if (!root) return;
    var on = this.checked;
    root.querySelectorAll('[data-sc-pub-row]').forEach(function (row) {
      if (row.classList.contains('hidden')) return;
      var cb = row.querySelector('[data-sc-check]');
      if (cb) cb.checked = on;
    });
    scUpdateSelection(root);
  });
  $(document).on('change', '[data-sc-check]', function () {
    scUpdateSelection(scPlanRoot(this));
  });
  $(document).on('change', '[data-sc-bulk-action]', function () {
    scUpdateSelection(scPlanRoot(this));
  });
  $(document).on('submit', '[data-sc-bulk-form]', function (e) {
    var form = this;
    var root = scPlanRoot(form);
    var action = (form.querySelector('[data-sc-bulk-action]') || {}).value || '';
    var idsBox = form.querySelector('[data-sc-bulk-ids]');
    var checked = root ? root.querySelectorAll('[data-sc-check]:checked') : [];
    if (!action || !checked.length) {
      e.preventDefault();
      return;
    }
    var labels = { approve: 'programar', delete: 'eliminar', cancel: 'cancelar' };
    if (!confirm('¿' + (labels[action] || action) + ' ' + checked.length + ' publicación(es)?')) {
      e.preventDefault();
      return;
    }
    if (idsBox) {
      idsBox.innerHTML = '';
      checked.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = cb.value;
        idsBox.appendChild(input);
      });
    }
  });
  document.querySelectorAll('[data-sc-plan-list]').forEach(function (root) {
    scUpdateSelection(root);
  });

  $(document).on('click', '[data-sc-send-plan]', function () {
    var b = this;
    if (b.disabled) return;
    var pending = parseInt(b.getAttribute('data-pending') || '0', 10);
    var msg = pending === 1
      ? '¿Programar esta publicación en Seller Central?'
      : '¿Programar ' + pending + ' publicaciones en Seller Central?';
    if (!confirm(msg)) return;
    scBtn(b, true);
    fetch(b.getAttribute('data-url'), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
    })
      .then(function () { window.location.reload(); })
      .catch(function () {
        scBtn(b, false);
        if (window.AdminToast) window.AdminToast.error('No se pudo enviar el plan.');
      });
  });
  var planForm = document.querySelector('[data-sc-plan-form]');
  if (planForm) {
    planForm.addEventListener('submit', function () {
      var card = planForm.closest('[data-sc-generate-card]') || planForm.parentElement;
      var gen = planForm.querySelector('[data-sc-generate-btn]');
      var label = planForm.querySelector('[data-sc-generate-label]');
      var loading = card ? card.querySelector('[data-sc-generate-loading]') : null;
      var days = parseInt((planForm.querySelector('[name="days"]') || {}).value || '0', 10);
      var perDay = parseInt((planForm.querySelector('[name="per_day"]') || {}).value || '0', 10);
      var channels = planForm.querySelectorAll('[name="channels[]"]:checked').length;
      var total = Math.max(0, days) * Math.max(0, perDay);
      var msg = loading ? loading.querySelector('[data-sc-generate-loading-msg]') : null;

      scBtn(gen, true);
      if (label) {
        label.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Generando…';
      }
      if (msg) {
        msg.textContent = total > 0
          ? ('Redactando ~' + total + ' publicación(es)' + (channels ? ' en ' + channels + ' canal(es)' : '') + '. Puede tardar varios minutos.')
          : 'Generando publicaciones. Esto puede tardar varios minutos.';
      }
      if (loading) {
        loading.classList.remove('hidden');
        loading.setAttribute('aria-busy', 'true');
      }
      if (card) card.classList.add('pointer-events-none');
    });
  }

  // NotebookLM handlers ya registrados al inicio del script.
})(jQuery);
</script>
@endpush
