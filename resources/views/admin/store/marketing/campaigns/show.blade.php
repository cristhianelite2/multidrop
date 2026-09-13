@extends('layouts.admin')

@php
    $allowedTabs = ['productos', 'publicaciones', 'campana'];
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
                    $nestedIds = $pp->flatMap(fn ($pr) => $pr->videos->pluck('id'))->all();
                    $pvLoose = $pv->reject(fn ($v) => in_array($v->id, $nestedIds, true));
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
                        <select id="md-camp-add-list" class="admin-input" size="8" multiple aria-label="Lista de productos">
                            <option value="" disabled>— Cargando catálogo… —</option>
                        </select>
                        <p class="text-xs text-ink-soft/55" id="md-camp-add-hint">Ctrl/Cmd + clic para varios. Los ya agregados no aparecen.</p>
                    </div>
                    <div class="space-y-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft" for="md-camp-sku-list">Lista (SKU o ID)</label>
                        <textarea name="sku_list" id="md-camp-sku-list" class="admin-input font-mono text-xs" rows="8" placeholder="Uno por línea, o separados por coma"></textarea>
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
    if (tab !== 'productos') url.searchParams.delete('product');
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

  function productosUrl() {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'productos');
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

  var aiState = { productId: null, segments: [], analysis: {}, prompt: null, savedPromptId: null };
  var aiGen = document.getElementById('md-ai-generate');
  var aiSave = document.getElementById('md-ai-save');
  var aiCf = document.getElementById('md-ai-creatify');
  var aiMsg = document.getElementById('md-ai-msg');
  var aiProductSelect = document.getElementById('md-ai-product');
  var aiProductSearch = document.getElementById('md-ai-product-search');
  var aiProductHint = document.getElementById('md-ai-product-hint');
  var catalogProductsAll = [];

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
    if (!aiProductSelect) return;
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
        if (aiProductSelect.options.length <= 1) {
          aiProductSelect.innerHTML = '<option value="">— Error al cargar productos —</option>';
        }
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
  if (document.querySelector('[data-md-prompt-miia]')) {
    loadCatalogProducts();
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
      var empty = document.createElement('option');
      empty.disabled = true;
      empty.textContent = catalogProductsAll.length ? '— Sin coincidencias —' : '— Cargando catálogo… —';
      campAddList.appendChild(empty);
      return;
    }
    list.forEach(function (p) {
      var opt = document.createElement('option');
      opt.value = String(p.id);
      var sku = p.sku ? ' · ' + p.sku : '';
      var combo = p.is_combo ? ' · combo' : '';
      opt.textContent = '#' + p.id + ' · ' + (p.name || 'Producto') + sku + combo;
      campAddList.appendChild(opt);
    });
    if (campAddHint) {
      campAddHint.textContent = list.length + ' resultado(s). Ctrl/Cmd + clic para varios.';
    }
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
      if (!campAddHidden || !campAddList) return;
      campAddHidden.innerHTML = '';
      Array.prototype.forEach.call(campAddList.selectedOptions, function (opt) {
        if (!opt.value) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = opt.value;
        campAddHidden.appendChild(input);
      });
    });
  }

  function openCampAddModal() {
    var modal = document.getElementById('md-camp-add-modal');
    if (!modal) return;
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
})(jQuery);
</script>
@endpush
