@php
    $networks = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'Twitter / X',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'gmail' => 'Gmail',
    ];
    $scConnection = $scConnection ?? ['ok' => false];
    $availableNetworks = ($scConnection['ok'] ?? false) && ! empty($scConnection['enabled_networks'])
        ? array_values(array_filter($scConnection['enabled_networks'], fn ($n) => isset($networks[$n])))
        : array_keys($networks);
    $defaultChannels = array_values(array_intersect(
        \App\Services\SellerCentral\PublicationPlannerService::DEFAULT_CHANNELS,
        $availableNetworks
    ));
    if ($defaultChannels === []) {
        $defaultChannels = ['facebook', 'instagram', 'tiktok', 'youtube'];
    }
    $connectedNetworks = ($scConnection['ok'] ?? false) ? ($scConnection['connected_networks'] ?? []) : [];
    $publicationThemes = \App\Services\SellerCentral\PublicationPlannerService::THEMES;
    $badge = [
        'draft' => 'bg-slate-100 text-slate-700',
        'approved' => 'bg-indigo-100 text-indigo-800',
        'scheduled' => 'bg-sky-100 text-sky-800',
        'published' => 'bg-emerald-100 text-emerald-800',
        'error' => 'bg-rose-100 text-rose-800',
        'cancelled' => 'bg-amber-100 text-amber-800',
        'sent' => 'bg-emerald-100 text-emerald-800',
        'partial' => 'bg-amber-100 text-amber-800',
    ];
    $statusLabel = [
        'draft' => 'Borrador', 'approved' => 'Aprobada', 'scheduled' => 'Programada',
        'published' => 'Publicada', 'error' => 'Error', 'cancelled' => 'Cancelada',
        'sent' => 'Enviado', 'partial' => 'Parcial',
    ];
    $statusShort = [
        'draft' => 'Borr.', 'approved' => 'Apr.', 'scheduled' => 'Prog.',
        'published' => 'Pub.', 'error' => 'Err.', 'cancelled' => 'Canc.',
        'sent' => 'Env.', 'partial' => 'Parc.',
    ];
    $networkIcons = [
        'facebook' => 'fa-brands fa-facebook',
        'instagram' => 'fa-brands fa-instagram',
        'twitter' => 'fa-brands fa-x-twitter',
        'linkedin' => 'fa-brands fa-linkedin',
        'tiktok' => 'fa-brands fa-tiktok',
        'youtube' => 'fa-brands fa-youtube',
        'gmail' => 'fa-solid fa-envelope',
    ];
    $networkIconColor = [
        'facebook' => 'text-[#1877F2]',
        'instagram' => 'text-[#E4405F]',
        'twitter' => 'text-ink',
        'linkedin' => 'text-[#0A66C2]',
        'tiktok' => 'text-ink',
        'youtube' => 'text-[#FF0000]',
        'gmail' => 'text-[#EA4335]',
    ];
    $defaultStart = \Carbon\Carbon::tomorrow()->format('Y-m-d');
    $planProducts = $planProducts ?? collect();
    $focusProduct = $focusProduct ?? null;
    $defaultProductIds = old('products');
    if ($defaultProductIds === null) {
        $defaultProductIds = $focusProduct
            ? [(string) $focusProduct->id]
            : $campaign->products->pluck('id')->map(fn ($id) => (string) $id)->all();
    } else {
        $defaultProductIds = \Illuminate\Support\Arr::wrap($defaultProductIds);
    }
    $publicationPlans = $publicationPlans ?? collect();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-ink">Generación de publicaciones</h3>
            <p class="mt-1 text-sm text-ink-soft/70">
                MIIA redacta el plan desde los productos. Valida cada borrador y prográmalo en Seller Central.
                @if(! ($scConnection['ok'] ?? false))
                    <a href="{{ route('admin.store.marketing.sellercentral.index') }}" class="text-teal hover:underline">Configura la API key</a>
                    para poder programarlos.
                @endif
            </p>
        </div>
    </div>

    <div class="admin-card overflow-hidden relative" data-sc-generate-card>
        <div class="border-b border-line px-4 py-3">
            <h3 class="font-semibold text-ink">Generar plan con IA</h3>
            <p class="mt-0.5 text-xs text-ink-soft/55">
                Elige un tema editorial (o mix). MIIA escribe cada publicación con gancho, desarrollo y CTA — no un texto genérico.
            </p>
        </div>
        <form method="post" action="{{ route('admin.store.marketing.sellercentral.generate') }}" class="space-y-4 p-4 sm:p-6" data-sc-plan-form>
            @csrf
            <input type="hidden" name="redirect_campaign_id" value="{{ $campaign->id }}">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Días</label>
                    <input type="number" name="days" value="{{ old('days', 7) }}" min="1" max="{{ config('multidrop.marketing.sellercentral.max_days', 60) }}" class="admin-input" required>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Publicaciones por día</label>
                    <input type="number" name="per_day" value="{{ old('per_day', 2) }}" min="1" max="{{ config('multidrop.marketing.sellercentral.max_per_day', 10) }}" class="admin-input" required>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Inicio</label>
                    <input type="date" name="start_date" value="{{ old('start_date', $defaultStart) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Formato</label>
                    <select name="format" class="admin-input">
                        <option value="image" @selected(old('format', 'image') === 'image')>Imagen del producto</option>
                        <option value="text" @selected(old('format') === 'text')>Solo texto</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tema / tipo de publicación</label>
                    <select name="theme" class="admin-input">
                        @foreach($publicationThemes as $themeKey => $themeMeta)
                            <option value="{{ $themeKey }}" @selected(old('theme', 'mix') === $themeKey)>{{ $themeMeta['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-ink-soft/50">Define el ángulo creativo (problema/solución, tip, historia, lifestyle…).</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Notas para MIIA <span class="font-normal text-ink-soft/45">(opcional)</span></label>
                    <textarea name="theme_notes" rows="3" maxlength="400" class="admin-input" placeholder="Ej. tono cercano mexicano, menciona uso en oficina, evita descuentos inventados…">{{ old('theme_notes') }}</textarea>
                </div>
            </div>

            <div>
                <span class="mb-1.5 block text-sm font-medium text-ink-soft">Canales</span>
                <div class="flex flex-wrap gap-3">
                    @foreach($networks as $key => $label)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="channels[]" value="{{ $key }}" class="h-4 w-4 rounded border-line accent-teal"
                                   @checked(in_array($key, \Illuminate\Support\Arr::wrap(old('channels', $defaultChannels)), true))>
                            {{ $label }}
                            @if(in_array($key, $connectedNetworks, true))
                                <span class="text-[10px] font-medium text-emerald-700">conectado</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                @if(! ($scConnection['ok'] ?? false))
                    <p class="mt-1 text-xs text-amber-700">Sin API key aún puedes generar borradores locales. Para programarlos en Seller Central, guarda la llave del proyecto.</p>
                @endif
            </div>

            <div>
                <span class="mb-1.5 block text-sm font-medium text-ink-soft">
                    Productos fuente
                    <span class="text-xs font-normal text-ink-soft/50">
                        @if($focusProduct)
                            (producto de la campaña: {{ \Illuminate\Support\Str::limit($focusProduct->localizedName(), 60) }})
                        @else
                            (por defecto: productos de esta campaña)
                        @endif
                    </span>
                </span>
                <select name="products[]" multiple size="6" class="admin-input w-full sm:w-2/3 lg:w-1/2">
                    @foreach($planProducts as $p)
                        <option value="{{ $p->id }}" @selected(in_array((string) $p->id, \Illuminate\Support\Arr::wrap($defaultProductIds), true))>
                            {{ \Illuminate\Support\Str::limit($p->name, 90) }} · {{ $p->status }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="admin-btn inline-flex items-center gap-2" data-sc-generate-btn>
                    <span data-sc-generate-label>Generar publicaciones con MIIA</span>
                </button>
            </div>
        </form>

        <div class="hidden absolute inset-0 z-20 flex items-center justify-center bg-white/80 backdrop-blur-[1px]" data-sc-generate-loading aria-live="polite" aria-busy="false">
            <div class="mx-4 w-full max-w-sm rounded-xl border border-line bg-white p-5 shadow-lg text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-teal/10 text-teal">
                    <i class="fa-solid fa-spinner fa-spin text-2xl" aria-hidden="true"></i>
                </div>
                <p class="font-semibold text-ink">MIIA está redactando…</p>
                <p class="mt-1 text-sm text-ink-soft/70" data-sc-generate-loading-msg>Generando publicaciones. Esto puede tardar varios minutos según días y canales.</p>
                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-mist">
                    <div class="h-full w-1/3 rounded-full bg-teal animate-pulse" style="animation: scGenBar 1.4s ease-in-out infinite;"></div>
                </div>
                <p class="mt-3 text-xs text-ink-soft/50">No cierres ni recargues esta página.</p>
            </div>
        </div>
        <style>
            @keyframes scGenBar {
                0% { transform: translateX(-120%); }
                100% { transform: translateX(320%); }
            }
            [data-sc-generate-loading] .bg-teal { animation: scGenBar 1.4s ease-in-out infinite; }
        </style>
    </div>

    @forelse($publicationPlans as $plan)
        <div class="admin-card overflow-hidden" data-no-collapse data-sc-plan-list="{{ $plan->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="admin-badge {{ $badge[$plan->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statusLabel[$plan->status] ?? $plan->status }}</span>
                    <span class="text-sm font-semibold text-ink">Plan #{{ $plan->id }}</span>
                    <span class="text-xs text-ink-soft/55">
                        {{ $plan->days }} día(s) × {{ $plan->per_day }}/día · desde {{ $plan->start_date->format('d/m/Y') }}
                        · <span data-sc-visible-count>{{ $plan->publications->count() }}</span>/<span data-sc-total-count>{{ $plan->publications->count() }}</span> publicaciones
                    </span>
                    <span class="text-xs text-ink-soft/45">{{ $plan->generated_at?->format('d/m H:i') }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            class="admin-btn !px-3 !py-1.5 text-xs"
                            data-sc-send-plan
                            data-url="{{ route('admin.store.marketing.sellercentral.plans.send', $plan) }}"
                            data-pending="{{ $plan->pendingCount() }}"
                            @if($plan->pendingCount() === 0 || ! ($scConnection['ok'] ?? false)) disabled @endif>
                        Programar todo
                    </button>
                    <form method="post" action="{{ route('admin.store.marketing.sellercentral.plans.delete', $plan) }}" onsubmit="return confirm('¿Eliminar este plan y sus publicaciones?')">
                        @csrf
                        <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar plan</button>
                    </form>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-2 border-b border-line bg-mist/20 px-4 py-2.5" data-sc-filters>
                <div class="min-w-[12rem] flex-1">
                    <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Buscar</label>
                    <input type="search" data-sc-filter-q class="admin-input !py-1.5 !text-xs" placeholder="Tema, contenido, producto…">
                </div>
                <div class="w-[9rem]">
                    <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Estado</label>
                    <select data-sc-filter-status class="admin-input !py-1.5 !text-xs">
                        <option value="">Todos</option>
                        @foreach($statusLabel as $st => $lab)
                            <option value="{{ $st }}">{{ $lab }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-[9rem]">
                    <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Red</label>
                    <select data-sc-filter-network class="admin-input !py-1.5 !text-xs">
                        <option value="">Todas</option>
                        @foreach($networks as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="admin-btn-secondary !px-2.5 !py-1.5 text-xs" data-sc-filter-reset>Limpiar</button>
            </div>

            <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.bulk') }}" data-sc-bulk-form class="border-b border-line px-4 py-2">
                @csrf
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-ink-soft/60" data-sc-selected-count>0 seleccionadas</span>
                    <select name="action" class="admin-input !w-auto !py-1 !text-xs" data-sc-bulk-action required>
                        <option value="">Acción masiva…</option>
                        <option value="approve">Programar seleccionadas</option>
                        <option value="cancel">Cancelar seleccionadas</option>
                        <option value="delete">Eliminar seleccionadas</option>
                    </select>
                    <button type="submit" class="admin-btn !px-2.5 !py-1 text-xs" data-sc-bulk-run disabled>Aplicar</button>
                    <div class="hidden" data-sc-bulk-ids></div>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full table-fixed divide-y divide-line text-sm">
                    <thead class="bg-mist/40 text-left text-[10px] uppercase tracking-wide text-ink-soft/50">
                        <tr>
                            <th class="w-8 px-2 py-1.5">
                                <input type="checkbox" class="h-3.5 w-3.5 rounded border-line accent-teal" data-sc-check-all title="Seleccionar visibles">
                            </th>
                            <th class="w-[6.75rem] px-2 py-1.5">Cuándo</th>
                            <th class="w-[8.5rem] px-2 py-1.5">Producto</th>
                            <th class="px-2 py-1.5">Contenido</th>
                            <th class="w-[6.25rem] px-2 py-1.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line" data-sc-pub-tbody>
                        @foreach($plan->publications->sortBy('scheduled_at') as $pub)
                            @php
                                $productName = $pub->product?->name ? (string) $pub->product->name : '';
                                $netLabel = $networks[$pub->network] ?? $pub->network;
                                $searchBlob = mb_strtolower(trim(($pub->topic ?? '').' '.($pub->content ?? '').' '.$productName.' '.$netLabel));
                                $contentPreview = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', trim((string) ($pub->content ?? ''))), 90, '…');
                                $mediaCount = is_array($pub->media_urls) ? count($pub->media_urls) : 0;
                                $netIcon = $networkIcons[$pub->network] ?? 'fa-solid fa-share-nodes';
                                $netColor = $networkIconColor[$pub->network] ?? 'text-ink-soft';
                            @endphp
                            <tr data-sc-pub-row="{{ $pub->id }}"
                                data-sc-status="{{ $pub->status }}"
                                data-sc-network="{{ $pub->network }}"
                                data-sc-search="{{ e($searchBlob) }}">
                                <td class="px-2 py-1.5 align-middle">
                                    <input type="checkbox" value="{{ $pub->id }}" class="h-3.5 w-3.5 rounded border-line accent-teal" data-sc-check>
                                </td>
                                <td class="px-2 py-1.5 align-middle">
                                    <div class="flex items-start gap-1.5">
                                        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded bg-mist/60 {{ $netColor }}" title="{{ $netLabel }}{{ $mediaCount ? ' · '.$mediaCount.' media' : '' }}">
                                            <i class="{{ $netIcon }} text-[13px]" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0 leading-tight">
                                            @if($pub->scheduled_at)
                                                <span class="block text-xs font-semibold text-ink">{{ $pub->scheduled_at->format('d/m') }} <span class="font-normal text-ink-soft/55">{{ $pub->scheduled_at->format('H:i') }}</span></span>
                                            @else
                                                <span class="block text-[11px] text-ink-soft/40">Sin fecha</span>
                                            @endif
                                            <span class="mt-0.5 inline-flex items-center rounded px-1 py-px text-[9px] font-semibold leading-none {{ $badge[$pub->status] ?? 'bg-slate-100 text-slate-700' }}" title="{{ $statusLabel[$pub->status] ?? $pub->status }}">{{ $statusShort[$pub->status] ?? ($statusLabel[$pub->status] ?? $pub->status) }}</span>
                                            @if($mediaCount > 0)
                                                <span class="ml-0.5 text-[9px] text-ink-soft/45" title="{{ $mediaCount }} media">·{{ $mediaCount }}m</span>
                                            @endif
                                            @if($pub->error_message)
                                                <p class="mt-0.5 max-w-[5.5rem] truncate text-[9px] text-rose-700" title="{{ $pub->error_message }}">{{ $pub->error_message }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-2 py-1.5 align-middle">
                                    <p class="truncate text-[11px] leading-snug text-ink-soft" title="{{ $productName !== '' ? $productName : '' }}">{{ $productName !== '' ? $productName : '—' }}</p>
                                </td>
                                <td class="relative px-2 py-1.5 pr-10 align-middle">
                                    @if($pub->topic)
                                        <p class="mb-px truncate text-[10px] font-medium text-ink-soft/50">{{ $pub->topic }}</p>
                                    @endif
                                    <div class="min-w-0" data-sc-content-wrap>
                                        <p class="text-xs leading-snug text-ink-soft" data-sc-content-preview>{{ $contentPreview }}</p>
                                        <p class="hidden whitespace-pre-wrap text-xs leading-relaxed text-ink" data-sc-content-full>{{ $pub->content }}</p>
                                        @if(mb_strlen(trim((string) ($pub->content ?? ''))) > 90)
                                            <button type="button"
                                                    class="absolute right-0 top-2 inline-flex h-7 w-7 items-center justify-center rounded border border-line bg-white text-ink-soft/65 hover:border-teal/40 hover:text-teal"
                                                    data-sc-toggle-content
                                                    title="Ver texto completo"
                                                    aria-expanded="false"
                                                    aria-label="Ver texto completo">
                                                <i class="fa-solid fa-ellipsis text-[11px]" data-sc-content-icon aria-hidden="true"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-2 py-1.5 align-middle text-right">
                                    <div class="inline-flex items-center justify-end gap-0.5">
                                        <button type="button"
                                                class="inline-flex h-7 w-7 items-center justify-center rounded border border-line bg-white text-ink-soft/70 hover:border-teal/40 hover:text-teal"
                                                data-sc-toggle-edit="{{ $pub->id }}"
                                                title="Editar"
                                                aria-label="Editar">
                                            <i class="fa-solid fa-pen text-[11px]" aria-hidden="true"></i>
                                        </button>
                                        @if(in_array($pub->status, ['draft', 'error', 'approved'], true))
                                            <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.approve', $pub) }}">
                                                @csrf
                                                <button class="inline-flex h-7 w-7 items-center justify-center rounded border border-teal/30 bg-teal/10 text-teal hover:bg-teal/20" title="Programar" aria-label="Programar">
                                                    <i class="fa-solid fa-calendar-check text-[11px]" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endif
                                        @if(in_array($pub->status, ['scheduled'], true) && $pub->sellercentral_post_id)
                                            <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.cancel', $pub) }}">
                                                @csrf
                                                <button class="inline-flex h-7 w-7 items-center justify-center rounded border border-line bg-white text-ink-soft/70 hover:border-amber-400 hover:text-amber-700" title="Cancelar" aria-label="Cancelar" onclick="return confirm('¿Cancelar esta publicación?')">
                                                    <i class="fa-solid fa-ban text-[11px]" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        @endif
                                        <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.delete', $pub) }}" onsubmit="return confirm('¿Eliminar esta publicación?')">
                                            @csrf
                                            <button class="inline-flex h-7 w-7 items-center justify-center rounded border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100" title="Eliminar" aria-label="Eliminar">
                                                <i class="fa-solid fa-trash text-[11px]" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr class="hidden" data-sc-edit-row="{{ $pub->id }}">
                                <td colspan="5" class="bg-mist/30 px-3 py-3">
                                    <form method="post" action="{{ route('admin.store.marketing.sellercentral.posts.update', $pub) }}" class="space-y-2">
                                        @csrf
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div class="w-[11.5rem]">
                                                <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Programada</label>
                                                @php
                                                    $dt = $pub->scheduled_at ? $pub->scheduled_at->format('Y-m-d\TH:i') : '';
                                                @endphp
                                                <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $dt) }}" class="admin-input !py-1.5 !text-xs">
                                            </div>
                                            <div class="w-[8.5rem]">
                                                <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Red</label>
                                                <select name="network" class="admin-input !py-1.5 !text-xs">
                                                    @foreach($networks as $key => $label)
                                                        <option value="{{ $key }}" @selected($pub->network === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="w-[7rem]">
                                                <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Formato</label>
                                                <select name="format" class="admin-input !py-1.5 !text-xs">
                                                    @foreach(['text' => 'Texto', 'image' => 'Imagen', 'carousel' => 'Carrusel', 'video' => 'Video'] as $f => $fl)
                                                        <option value="{{ $f }}" @selected($pub->format === $f)>{{ $fl }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="min-w-[12rem] flex-1">
                                                <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Tema</label>
                                                <input type="text" name="topic" value="{{ old('topic', $pub->topic) }}" maxlength="255" class="admin-input !py-1.5 !text-xs" placeholder="Ángulo / tema">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="mb-0.5 block text-[11px] font-medium text-ink-soft">Contenido</label>
                                            <textarea name="content" rows="6" class="admin-input font-sans text-sm leading-relaxed" required>{{ old('content', $pub->content) }}</textarea>
                                        </div>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                                <span class="text-[11px] font-medium text-ink-soft">Media</span>
                                                @forelse($pub->media_urls ?? [] as $m)
                                                    <a href="{{ $m['url'] ?? '#' }}" target="_blank" rel="noopener" class="max-w-[10rem] truncate rounded border border-line px-1.5 py-0.5 text-[11px] text-teal hover:underline" title="{{ $m['url'] ?? '' }}">{{ basename(parse_url($m['url'] ?? '', PHP_URL_PATH) ?: $m['url'] ?? '') ?: 'media' }}</a>
                                                @empty
                                                    <span class="text-[11px] text-ink-soft/45">sin media</span>
                                                @endforelse
                                            </div>
                                            <div class="flex gap-1.5">
                                                <button class="admin-btn !px-3 !py-1 text-xs">Guardar</button>
                                                <button type="button" class="admin-btn-secondary !px-3 !py-1 text-xs" data-sc-toggle-edit="{{ $pub->id }}">Cerrar</button>
                                            </div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="hidden px-4 py-6 text-center text-sm text-ink-soft/55" data-sc-empty-filter>Ninguna publicación coincide con el filtro.</p>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-line px-4 py-8 text-center">
            <p class="text-sm text-ink-soft/60">Aún no hay planes. Usa el formulario de arriba para generar publicaciones con MIIA.</p>
        </div>
    @endforelse
</div>
