@extends('layouts.admin')

@php
    $allowedTabs = ['resumen', 'publicaciones', 'ads', 'prompts', 'resultados', 'optimizar'];
    $tab = in_array($tab ?? '', $allowedTabs, true) ? $tab : 'resumen';
    $statusLabel = ['draft' => 'Borrador', 'ready' => 'Listo', 'paused' => 'Pausada'][$campaign->status] ?? $campaign->status;
    $ctas = [
        'SHOP_NOW' => 'Comprar ahora',
        'LEARN_MORE' => 'Más info',
        'SIGN_UP' => 'Registrarse',
        'ORDER_NOW' => 'Pedir ahora',
        'GET_OFFER' => 'Ver oferta',
    ];
    $insights = is_array($campaign->insights) ? $campaign->insights : [];
    $advice = is_array($campaign->advice) ? $campaign->advice : [];
    $sellercentralEmbedUrl = trim((string) ($sellercentralEmbedUrl ?? ''));
@endphp

@section('title', $campaign->name.' — Marketing')
@section('heading', $campaign->name)
@section('subheading', implode(' · ', $campaign->platformList()) ?: 'Campaña')

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-soft/70">
            <a href="{{ route('admin.store.marketing.campaigns.index') }}" class="hover:text-teal">Campañas</a>
            <span class="text-ink-soft/40"> / </span>
            {{ $campaign->name }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <span class="admin-badge {{ $campaign->status === 'ready' ? 'bg-emerald-100 text-emerald-800' : ($campaign->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">{{ $statusLabel }}</span>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.duplicate', $campaign) }}">
                @csrf
                <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Duplicar</button>
            </form>
        </div>
    </div>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Videos / ads</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $campaign->videos->count() }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ $campaign->prompts->count() }} prompts</p>
        </div>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Invertido</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">
                {{ ($kpis['spend'] ?? 0) > 0 ? number_format((float) $kpis['spend'], 2) : '—' }}
            </div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ $campaign->currency }} reportados</p>
        </div>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">ROAS</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">
                {{ ($kpis['spend'] ?? 0) > 0 ? number_format((float) $kpis['roas'], 2).'x' : '—' }}
            </div>
            <p class="mt-1 text-xs text-ink-soft/55">{{ (int) ($kpis['conversions'] ?? 0) }} ventas · CTR {{ number_format((float) ($kpis['ctr'] ?? 0), 2) }}%</p>
        </div>
        <div class="admin-card p-4">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Presupuesto / día</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ number_format((float) $campaign->daily_budget, 2) }}</div>
            <p class="mt-1 text-xs text-ink-soft/55">Tope HITL {{ number_format($budgetCap, 2) }} {{ $store->currency() }}</p>
        </div>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 px-2 pt-2" data-campaign-tabs>
            @foreach([
                'resumen' => 'Resumen',
                'publicaciones' => 'Publicaciones',
                'ads' => 'Videos',
                'prompts' => 'Prompts',
                'resultados' => 'Resultados',
                'optimizar' => 'Optimizar',
            ] as $key => $label)
                <button type="button"
                        data-tab="{{ $key }}"
                        class="-mb-px border-b-2 px-4 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-teal text-teal' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Resumen --}}
        <div class="p-4 sm:p-6 space-y-5 {{ $tab === 'resumen' ? '' : 'hidden' }}" data-tab-panel="resumen">
            <form method="post" action="{{ route('admin.store.marketing.campaigns.update', $campaign) }}" class="space-y-4" data-no-fixed-actions id="md-campaign-main-form">
                @csrf
                @method('PUT')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                        <input type="text" name="name" value="{{ old('name', $campaign->name) }}" class="admin-input" required maxlength="120">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Plataformas</label>
                        @php $plats = old('platforms', $campaign->platformList()); @endphp
                        <label class="mr-4 text-sm"><input type="checkbox" name="platforms[]" value="meta" @checked(in_array('meta', $plats, true))> Meta</label>
                        <label class="text-sm"><input type="checkbox" name="platforms[]" value="tiktok" @checked(in_array('tiktok', $plats, true))> TikTok</label>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Estado</label>
                        <select name="status" class="admin-input">
                            @foreach(['draft' => 'Borrador', 'ready' => 'Listo (payload)', 'paused' => 'Pausada'] as $val => $lab)
                                <option value="{{ $val }}" @selected(old('status', $campaign->status) === $val)>{{ $lab }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Presupuesto diario</label>
                        <input type="number" step="0.01" min="0" name="daily_budget" value="{{ old('daily_budget', $campaign->daily_budget) }}" class="admin-input" required>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Página de destino</label>
                        <select name="landing_handle" class="admin-input">
                            <option value="">—</option>
                            @foreach($pages as $page)
                                <option value="{{ $page['handle'] }}" @selected(old('landing_handle', $campaign->landing_handle) === $page['handle'])>
                                    {{ $page['title'] }} ({{ $page['handle'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL de landing (opcional, pisa el handle)</label>
                        <input type="text" name="landing_url" value="{{ old('landing_url', $campaign->landing_url) }}" class="admin-input" maxlength="500" placeholder="https://…">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Notas</label>
                        <textarea name="notes" class="admin-input" rows="3" maxlength="2000">{{ old('notes', $campaign->notes) }}</textarea>
                    </div>
                </div>
                <div>
                    <button class="admin-btn">Guardar campaña</button>
                </div>
            </form>

            <div class="border-t border-line pt-5 space-y-3">
                <h3 class="font-semibold text-ink">Borrador Advantage+ / Smart+</h3>
                <p class="text-sm text-ink-soft/70">Arma el payload con los videos de esta campaña. Queda <strong>PAUSED</strong>. No se publica ni se gasta en v1.</p>
                <form method="post" action="{{ route('admin.store.marketing.campaigns.draft', $campaign) }}">
                    @csrf
                    <button class="admin-btn-secondary">Preparar borrador</button>
                </form>
                @if($campaign->draft_payload)
                    <p class="text-xs text-ink-soft/55">Meta: {{ $campaign->meta_draft_id ?: '—' }} · TikTok: {{ $campaign->tiktok_draft_id ?: '—' }}</p>
                    <details>
                        <summary class="cursor-pointer text-sm text-teal">Ver payload JSON</summary>
                        <pre class="mt-2 text-xs overflow-x-auto bg-mist/60 p-3 rounded-lg max-h-64">{{ json_encode($campaign->draft_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </div>

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

        {{-- Publicaciones --}}
        <div class="p-4 sm:p-6 space-y-4 {{ $tab === 'publicaciones' ? '' : 'hidden' }}" data-tab-panel="publicaciones">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-ink">Administrar publicaciones</h3>
                    <p class="mt-1 text-sm text-ink-soft/70">
                        Panel de Seller Central. Para cambiar la URL del embed, ve a
                        <button type="button" class="text-teal hover:underline" data-tab-jump="resumen">Resumen</button>.
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
                    Falta la URL del embed. Ve a Resumen, pégala y guarda la campaña.
                </p>
            @endif
        </div>

        {{-- Anuncios --}}
        <div class="p-4 sm:p-6 space-y-6 {{ $tab === 'ads' ? '' : 'hidden' }}" data-tab-panel="ads">
            @unless($ffmpeg)
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
                    ffmpeg no está en el PATH. Los videos se guardan, pero no se limpia la metadata. Define <code>FFMPEG_PATH</code> en <code>.env</code>.
                </p>
            @endunless

            @forelse($campaign->videos as $v)
                <div class="rounded-xl border border-line p-4 space-y-4">
                    <div class="grid gap-4 lg:grid-cols-[16rem_1fr]">
                        <div>
                            <video src="{{ $v->publicUrl() }}" controls preload="metadata" class="w-full rounded-lg border border-line bg-black max-h-64"></video>
                            <p class="mt-2 truncate text-xs text-ink-soft/55">{{ $v->original_name ?: basename($v->path) }}</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span class="admin-badge {{ $v->source === 'creatify' ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-700' }}">{{ $v->source === 'creatify' ? 'Creatify' : 'Subido' }}</span>
                                <span class="admin-badge {{ $v->stripped_at ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $v->stripped_at ? 'sin huellas' : 'sin limpiar' }}</span>
                            </div>
                        </div>
                        <form method="post" action="{{ route('admin.store.marketing.videos.update', $v) }}" class="space-y-3">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Titular del anuncio</label>
                                <input type="text" name="ad_headline" value="{{ $v->ad_headline }}" class="admin-input" maxlength="120" placeholder="El hook que se lee en el feed">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto principal</label>
                                <textarea name="ad_primary_text" class="admin-input" rows="3" maxlength="500" placeholder="Cuerpo del anuncio">{{ $v->ad_primary_text }}</textarea>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-ink-soft">CTA</label>
                                <select name="ad_cta" class="admin-input">
                                    @foreach($ctas as $val => $lab)
                                        <option value="{{ $val }}" @selected(($v->ad_cta ?: 'SHOP_NOW') === $val)>{{ $lab }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($v->prompt)
                                <p class="text-xs text-ink-soft/55">Prompt: {{ $v->prompt->name }}</p>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                <button class="admin-btn">Guardar copy</button>
                                <a class="admin-btn-secondary" href="{{ route('admin.store.marketing.videos.download', $v) }}">Descargar</a>
                            </div>
                        </form>
                    </div>
                    <form method="post" action="{{ route('admin.store.marketing.videos.destroy', $v) }}" onsubmit="return confirm('¿Eliminar este anuncio?')" class="pt-1">
                        @csrf @method('DELETE')
                        <input type="hidden" name="from" value="campaign">
                        <button class="text-xs text-rose hover:underline">Eliminar video</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-ink-soft/70">Esta campaña aún no tiene anuncios. Sube un video o genera uno con Creatify.</p>
            @endforelse

            <div class="grid gap-5 lg:grid-cols-2 border-t border-line pt-6">
                <div class="space-y-3">
                    <h3 class="font-semibold text-ink">Subir video</h3>
                    <form method="post" action="{{ route('admin.store.marketing.videos.store') }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">
                        <input type="hidden" name="from" value="campaign">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prompt (opcional)</label>
                            <select name="prompt_id" class="admin-input">
                                <option value="">—</option>
                                @foreach($campaign->prompts as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Archivo (mp4 / webm / mov, máx {{ $maxMb }} MB)</label>
                            <input type="file" name="file" accept="video/mp4,video/webm,video/quicktime" class="admin-input" required>
                        </div>
                        <button class="admin-btn">Subir y limpiar</button>
                    </form>
                </div>
                <div class="space-y-3" id="md-creatify-box">
                    <h3 class="font-semibold text-ink">Generar con Creatify</h3>
                    <p class="text-sm text-ink-soft/70">Usa el landing de esta campaña + un prompt. El MP4 entra al mismo pipeline.</p>
                    <span class="admin-badge {{ $creatify['ok'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">{{ $creatify['message'] }}</span>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prompt</label>
                        <select id="md-cf-prompt" class="admin-input">
                            <option value="">—</option>
                            @foreach($campaign->prompts as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                            @foreach($libraryPrompts->where('campaign_id', null) as $p)
                                <option value="{{ $p->id }}">Biblioteca · {{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="admin-btn-secondary" id="md-cf-go" @disabled(! $creatify['ok'])>Generar video</button>
                    <p class="text-sm text-ink-soft/70" id="md-cf-msg"></p>
                </div>
            </div>
        </div>

        {{-- Prompts --}}
        <div class="p-4 sm:p-6 space-y-5 {{ $tab === 'prompts' ? '' : 'hidden' }}" data-tab-panel="prompts">
            <p class="text-sm text-ink-soft/70">Los prompts son solo el combustible de Creatify. El anuncio real vive en <strong>Videos</strong>.</p>

            @forelse($campaign->prompts as $p)
                <div class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-line p-4">
                    <div class="min-w-0">
                        <div class="font-semibold text-ink">{{ $p->name }}</div>
                        <p class="mt-1 text-sm text-ink-soft/70">{{ $p->hook ?: \Illuminate\Support\Str::limit($p->script, 120) }}</p>
                        <p class="mt-1 text-xs text-ink-soft/50">{{ $p->target_platform }} · {{ $p->language }}</p>
                    </div>
                    <div class="flex gap-2">
                        <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.prompts.edit', $p) }}">Editar</a>
                        <form method="post" action="{{ route('admin.store.marketing.prompts.destroy', $p) }}" onsubmit="return confirm('¿Eliminar este prompt?')">
                            @csrf @method('DELETE')
                            <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-soft/60">Ningún prompt en esta campaña. Si ya tienes el video, no hace falta.</p>
            @endforelse

            <form method="post" action="{{ route('admin.store.marketing.prompts.store') }}" class="space-y-3 rounded-xl border border-dashed border-line p-4">
                @csrf
                <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">
                <h3 class="font-semibold text-ink">Añadir prompt</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                        <input type="text" name="name" class="admin-input" required maxlength="120" placeholder="Hook problema + CTA">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Hook</label>
                        <input type="text" name="hook" class="admin-input" maxlength="240">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Script</label>
                        <textarea name="script" class="admin-input" rows="4" required maxlength="4000"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Audiencia</label>
                        <input type="text" name="audience" class="admin-input" maxlength="240">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Plataforma</label>
                        <select name="target_platform" class="admin-input">
                            <option value="Tiktok">TikTok</option>
                            <option value="Meta">Meta</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Idioma</label>
                        <input type="text" name="language" value="es" class="admin-input" required maxlength="16">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Estilo Creatify</label>
                        <input type="text" name="style" value="DynamicProductTemplate" class="admin-input" maxlength="80">
                    </div>
                </div>
                <button class="admin-btn">Añadir a esta campaña</button>
            </form>
        </div>

        {{-- Resultados --}}
        <div class="p-4 sm:p-6 space-y-5 {{ $tab === 'resultados' ? '' : 'hidden' }}" data-tab-panel="resultados">
            <p class="text-sm text-ink-soft/70">
                Pega aquí las cifras de Ads Manager / TikTok Ads. En v1 no hay gasto automático: esto alimenta el consejo de targets y presupuesto.
            </p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-lg bg-mist/60 p-3">
                    <div class="text-xs text-ink-soft/50">Impresiones</div>
                    <div class="font-semibold">{{ number_format((int) ($kpis['impressions'] ?? 0)) }}</div>
                </div>
                <div class="rounded-lg bg-mist/60 p-3">
                    <div class="text-xs text-ink-soft/50">Clics</div>
                    <div class="font-semibold">{{ number_format((int) ($kpis['clicks'] ?? 0)) }}</div>
                </div>
                <div class="rounded-lg bg-mist/60 p-3">
                    <div class="text-xs text-ink-soft/50">CTR</div>
                    <div class="font-semibold">{{ number_format((float) ($kpis['ctr'] ?? 0), 2) }}%</div>
                </div>
                <div class="rounded-lg bg-mist/60 p-3">
                    <div class="text-xs text-ink-soft/50">CPA</div>
                    <div class="font-semibold">{{ ($kpis['conversions'] ?? 0) > 0 ? number_format((float) $kpis['cpa'], 2) : '—' }}</div>
                </div>
                <div class="rounded-lg bg-mist/60 p-3">
                    <div class="text-xs text-ink-soft/50">Ingresos</div>
                    <div class="font-semibold">{{ number_format((float) ($kpis['revenue'] ?? 0), 2) }} {{ $campaign->currency }}</div>
                </div>
            </div>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.insights', $campaign) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @csrf
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Invertido</label>
                    <input type="number" step="0.01" min="0" name="spend" class="admin-input" value="{{ old('spend', $insights['spend'] ?? $kpis['spend'] ?? 0) }}">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Impresiones</label>
                    <input type="number" min="0" name="impressions" class="admin-input" value="{{ old('impressions', $insights['impressions'] ?? $kpis['impressions'] ?? 0) }}">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Clics</label>
                    <input type="number" min="0" name="clicks" class="admin-input" value="{{ old('clicks', $insights['clicks'] ?? $kpis['clicks'] ?? 0) }}">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Conversiones</label>
                    <input type="number" min="0" name="conversions" class="admin-input" value="{{ old('conversions', $insights['conversions'] ?? $kpis['conversions'] ?? 0) }}">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Ingresos</label>
                    <input type="number" step="0.01" min="0" name="revenue" class="admin-input" value="{{ old('revenue', $insights['revenue'] ?? $kpis['revenue'] ?? 0) }}">
                </div>
                <div class="flex items-end">
                    <button class="admin-btn">Guardar resultados</button>
                </div>
            </form>
            @if(! empty($insights['updated_at']))
                <p class="text-xs text-ink-soft/50">Última carga: {{ $insights['updated_at'] }}</p>
            @endif
        </div>

        {{-- Optimizar --}}
        <div class="p-4 sm:p-6 space-y-6 {{ $tab === 'optimizar' ? '' : 'hidden' }}" data-tab-panel="optimizar">
            <p class="text-sm text-ink-soft/70">
                Pasa el brief de esta campaña a Madgicx, n8n o tu propio cerebro de media buying.
                Ellos definen target, objetivo y presupuesto para vender más. El tope HITL no se supera.
            </p>
            <span class="admin-badge {{ $webhook ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                {{ $webhook ? 'Herramienta externa conectada' : 'Consejo local (sin webhook)' }}
            </span>
            @unless($webhook)
                <p class="text-xs text-ink-soft/55">
                    Para conectar una herramienta, pon <code>MARKETING_OPTIMIZER_WEBHOOK</code> en <code>.env</code>.
                    Recibe el brief JSON y debe devolver <code>{"advice":{"summary":"…","budget_daily":10,"targets":{},"moves":[]}}</code>.
                </p>
            @endunless

            <form method="post" action="{{ route('admin.store.marketing.campaigns.targets', $campaign) }}" class="space-y-3 rounded-xl border border-line p-4">
                @csrf
                <h3 class="font-semibold text-ink">Target actual</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Objetivo</label>
                        <select name="objective" class="admin-input">
                            @foreach(['sales' => 'Ventas', 'traffic' => 'Tráfico', 'leads' => 'Leads'] as $val => $lab)
                                <option value="{{ $val }}" @selected(($targets['objective'] ?? 'sales') === $val)>{{ $lab }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Países (ISO, separados)</label>
                        <input type="text" name="countries" class="admin-input" value="{{ old('countries', implode(', ', $targets['countries'] ?? [])) }}" placeholder="ES, MX, CO">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Audiencia</label>
                        <input type="text" name="audience" class="admin-input" maxlength="400" value="{{ old('audience', $targets['audience'] ?? '') }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Edad mín.</label>
                        <input type="number" name="age_min" min="13" max="65" class="admin-input" value="{{ old('age_min', $targets['age_min'] ?? 18) }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Edad máx.</label>
                        <input type="number" name="age_max" min="18" max="65" class="admin-input" value="{{ old('age_max', $targets['age_max'] ?? 45) }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Intereses</label>
                        <input type="text" name="interests" class="admin-input" maxlength="400" value="{{ old('interests', $targets['interests'] ?? '') }}">
                    </div>
                </div>
                <button class="admin-btn-secondary">Guardar target</button>
            </form>

            @if($advice)
                <div class="rounded-xl border border-teal/30 bg-teal/5 p-4 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-ink">Último consejo</h3>
                        <span class="admin-badge bg-slate-100 text-slate-700">{{ ($advice['source'] ?? '') === 'webhook' ? 'herramienta' : 'local' }}</span>
                    </div>
                    <p class="text-sm text-ink">{{ $advice['summary'] ?? '' }}</p>
                    @if(! empty($advice['budget_daily']))
                        <p class="text-sm text-ink-soft/70">Presupuesto sugerido: <strong>{{ number_format((float) $advice['budget_daily'], 2) }} {{ $campaign->currency }}</strong> / día</p>
                    @endif
                    @if(! empty($advice['moves']) && is_array($advice['moves']))
                        <ul class="list-disc pl-5 text-sm text-ink-soft/80 space-y-1">
                            @foreach($advice['moves'] as $move)
                                <li>{{ $move }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if($campaign->advice_at)
                        <p class="text-xs text-ink-soft/50">{{ $campaign->advice_at->diffForHumans() }}</p>
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap gap-2">
                <form method="post" action="{{ route('admin.store.marketing.campaigns.optimize', $campaign) }}">
                    @csrf
                    <button class="admin-btn-secondary">Pedir consejo</button>
                </form>
                <form method="post" action="{{ route('admin.store.marketing.campaigns.optimize', $campaign) }}">
                    @csrf
                    <input type="hidden" name="apply" value="1">
                    <button class="admin-btn">Pedir y aplicar</button>
                </form>
                <a class="admin-btn-secondary" href="{{ route('admin.store.marketing.campaigns.brief', $campaign) }}">Descargar brief JSON</a>
                <button type="button" class="admin-btn-secondary" id="md-copy-brief">Copiar brief</button>
            </div>
            <p class="text-xs text-ink-soft/50" id="md-copy-brief-msg"></p>
            <details>
                <summary class="cursor-pointer text-sm text-teal">Ver brief que se envía a la herramienta</summary>
                <pre id="md-brief-json" class="mt-2 text-xs overflow-x-auto bg-mist/60 p-3 rounded-lg max-h-80">{{ json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function ($) {
  var initial = @json($tab);
  function activate(tab) {
    if (!$('[data-campaign-tabs] [data-tab="'+tab+'"]').length) tab = 'resumen';
    $('[data-campaign-tabs] [data-tab]').removeClass('border-teal text-teal').addClass('border-transparent text-ink-soft/65');
    $('[data-campaign-tabs] [data-tab="'+tab+'"]').addClass('border-teal text-teal').removeClass('border-transparent text-ink-soft/65');
    $('[data-tab-panel]').addClass('hidden');
    $('[data-tab-panel="'+tab+'"]').removeClass('hidden');
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState({}, '', url.toString());
  }
  $('[data-campaign-tabs] [data-tab]').on('click', function () {
    activate($(this).data('tab'));
  });
  $(document).on('click', '[data-tab-jump]', function () {
    activate($(this).data('tab-jump'));
  });
  activate(initial);

  var copyBtn = document.getElementById('md-copy-brief');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var pre = document.getElementById('md-brief-json');
      var msg = document.getElementById('md-copy-brief-msg');
      var text = pre ? pre.textContent : '';
      function done(ok) { if (msg) msg.textContent = ok ? 'Brief copiado. Pégalo en tu herramienta.' : 'No se pudo copiar.'; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () { done(false); });
      } else {
        done(false);
      }
    });
  }

  var go = document.getElementById('md-cf-go');
  var msg = document.getElementById('md-cf-msg');
  if (!go) return;
  var campaignId = @json($campaign->id);
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var token = csrf ? csrf.getAttribute('content') : '';
  function say(t) { if (msg) msg.textContent = t; }
  function adsUrl() {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'ads');
    return url.toString();
  }
  function poll(jobId, promptId) {
    fetch(@json(route('admin.store.marketing.creatify.poll')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ job_id: jobId, campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { say(res.message || 'Error'); go.disabled = false; return; }
      if (res.status === 'done') {
        say('Video listo. Recargando…');
        window.location.href = adsUrl();
        return;
      }
      say('Generando… ' + (res.progress || 0) + '% (' + (res.status || 'pending') + ')');
      setTimeout(function () { poll(jobId, promptId); }, 4000);
    }).catch(function () { say('Error de red al consultar el job'); go.disabled = false; });
  }
  go.addEventListener('click', function () {
    var promptId = document.getElementById('md-cf-prompt').value;
    if (!promptId) { say('Elige un prompt.'); return; }
    go.disabled = true;
    say('Enviando a Creatify…');
    fetch(@json(route('admin.store.marketing.creatify.generate')), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ campaign_id: campaignId, prompt_id: promptId })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (pack) {
      if (!pack.j.ok) { say(pack.j.message || 'No se pudo generar'); go.disabled = false; return; }
      say('Job ' + pack.j.job_id + '…');
      poll(pack.j.job_id, promptId);
    }).catch(function () { say('Error de red'); go.disabled = false; });
  });
})(jQuery);
</script>
@endpush
