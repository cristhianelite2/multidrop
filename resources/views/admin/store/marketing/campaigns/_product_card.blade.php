@php
    $isCombo = (bool) data_get($product->creative_data, 'is_combo', false);
    $productPrompts = $productPrompts ?? collect();
    $productVideos = $productVideos ?? collect();
    $ctas = $ctas ?? [];
    $maxMb = $maxMb ?? 80;
    $hyperframes = $hyperframes ?? ['ok' => false];
    $uploadedVideos = $productVideos->filter(fn ($v) => ($v->source ?: 'upload') === 'upload')->values();
    // Incluir HF ligados a prompt (scrape auto); no depender solo de productVideos “sueltos”.
    $hyperframesVideos = $productVideos
        ->filter(fn ($v) => ($v->source ?: '') === 'hyperframes')
        ->concat(
            $productPrompts->flatMap(
                fn ($pr) => $pr->videos->filter(fn ($v) => ($v->source ?: '') === 'hyperframes')
            )
        )
        ->unique('id')
        ->values();
    $notebooklmVideos = $productVideos
        ->filter(fn ($v) => ($v->source ?: '') === 'notebooklm')
        ->values();
    $generatedLoose = $productVideos
        ->reject(fn ($v) => in_array(($v->source ?: 'upload'), ['upload', 'hyperframes', 'notebooklm'], true))
        ->values();
    $promptVideoCount = $productPrompts->sum(
        fn ($pr) => $pr->videos->reject(fn ($v) => in_array(($v->source ?: ''), ['hyperframes', 'notebooklm'], true))->count()
    );
    $videoCount = $productVideos->count() + $promptVideoCount;
    $ffmpegOk = $ffmpeg ?? true;
    $notebooklm = $notebooklm ?? ['ok' => false];
    $notebookJobs = collect($notebookJobs ?? []);
    $activeNotebookJob = $notebookJobs->first(fn ($j) => $j instanceof \App\Models\SellerCentralVideoJob && $j->isActive());
    $collapseRemotion = $productPrompts->isEmpty() && $generatedLoose->isEmpty();
    $collapseUpload = $uploadedVideos->isEmpty();
    $collapseHyperframes = $hyperframesVideos->isEmpty();
    $collapseNotebook = $notebooklmVideos->isEmpty() && ! $activeNotebookJob;
@endphp
<div class="space-y-5" id="md-product-{{ $product->id }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex items-start gap-3">
            @if($product->image_url)
                <img src="{{ $product->image_url }}" alt="" class="h-12 w-12 rounded-lg border border-line object-cover shrink-0">
            @endif
            <div class="min-w-0">
                <div class="font-semibold text-ink">
                    {{ $product->localizedName() }}
                    @if($isCombo)
                        <span class="admin-badge !text-[10px] !px-1.5 !py-0 ml-1 bg-sky-100 text-sky-800">combo</span>
                    @endif
                </div>
                <div class="mt-0.5 text-xs text-ink-soft/55">
                    @if($product->sku){{ $product->sku }} · @endif
                    {{ $videoCount }} {{ $videoCount === 1 ? 'video' : 'videos' }}
                    · {{ $productPrompts->count() }} {{ $productPrompts->count() === 1 ? 'prompt' : 'prompts' }}
                </div>
            </div>
        </div>
        <form method="post" action="{{ route('admin.store.marketing.campaigns.products.detach', [$campaign, $product]) }}" onsubmit="return confirm('¿Quitar este producto de la campaña? Los videos se conservan.')">
            @csrf @method('DELETE')
            <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Quitar de la campaña</button>
        </form>
    </div>

    <div class="rounded-xl border border-line overflow-hidden bg-white" data-md-fold @if($collapseRemotion) data-md-fold-collapsed @endif>
        <button type="button" class="flex w-full items-start gap-3 border-b border-line bg-mist/30 px-4 py-3 text-left" data-md-fold-toggle aria-expanded="{{ $collapseRemotion ? 'false' : 'true' }}">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 1</p>
                <h3 class="mt-0.5 font-semibold text-ink">Generar video con Remotion</h3>
                <p class="mt-1 text-sm text-ink-soft/70">Crea el guion con MIIA y, en el prompt, genera el MP4. No sube un archivo.</p>
            </div>
            <span class="mt-1 shrink-0 text-ink-soft/50 transition-transform duration-150 md-fold-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="p-4 space-y-4" data-md-fold-body @if($collapseRemotion) hidden @endif>
            <p class="text-xs text-ink-soft/55">1. Prompt con MIIA · 2. Generar con Remotion en ese prompt</p>
            <button type="button" class="admin-btn !px-3 !py-1.5 text-sm" data-md-ai-open data-use-in-prompt="{{ $product->id }}" data-product-name="{{ $product->localizedName() }}">Generar prompt con MIIA</button>

            @forelse($productPrompts as $p)
                @include('admin.store.marketing.campaigns._prompt_item', ['p' => $p, 'ctas' => $ctas, 'redirectProduct' => $product->id])
            @empty
                <p class="text-sm text-ink-soft/60">Aún no hay prompts. Genera uno con MIIA para poder usar Remotion.</p>
            @endforelse

            @if($generatedLoose->isNotEmpty())
                <div class="space-y-2 border-t border-line pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Otros videos generados</p>
                    @foreach($generatedLoose as $v)
                        @include('admin.store.marketing.campaigns._video_item', ['v' => $v, 'redirectTab' => 'productos', 'redirectProduct' => $product->id])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <p class="text-center text-[11px] font-semibold uppercase tracking-widest text-ink-soft/40">o</p>

    <div class="rounded-xl border border-line overflow-hidden bg-white" data-md-fold @if($collapseUpload) data-md-fold-collapsed @endif>
        <button type="button" class="flex w-full items-start gap-3 border-b border-line bg-mist/30 px-4 py-3 text-left" data-md-fold-toggle aria-expanded="{{ $collapseUpload ? 'false' : 'true' }}">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 2</p>
                <h3 class="mt-0.5 font-semibold text-ink">Subir un video</h3>
                <p class="mt-1 text-sm text-ink-soft/70">Archivo que ya tienes. No usa MIIA ni Remotion.</p>
            </div>
            <span class="mt-1 shrink-0 text-ink-soft/50 transition-transform duration-150 md-fold-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="p-4 space-y-4" data-md-fold-body @if($collapseUpload) hidden @endif>
            @unless($ffmpegOk)
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                    ffmpeg no está en el PATH. El video se guarda, pero no se limpia la metadata. Define <code>FFMPEG_PATH</code> en <code>.env</code>.
                </p>
            @endunless
            <form method="post" action="{{ route('admin.store.marketing.videos.store') }}" enctype="multipart/form-data" class="space-y-3 max-w-xl">
                @csrf
                <input type="hidden" name="campaign_id" value="{{ $campaign->id }}">
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="from" value="campaign">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Archivos (mp4 / webm / mov, máx {{ $maxMb }} MB c/u)</label>
                    <input type="file" name="files[]" accept="video/mp4,video/webm,video/quicktime" class="admin-input" multiple required>
                    <p class="mt-1 text-xs text-ink-soft/55">Puedes seleccionar varios a la vez (hasta 20).</p>
                </div>
                <button class="admin-btn">Subir y limpiar</button>
            </form>

            @if($uploadedVideos->isNotEmpty())
                <div class="space-y-2 border-t border-line pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Videos subidos ({{ $uploadedVideos->count() }})</p>
                    @foreach($uploadedVideos as $v)
                        @include('admin.store.marketing.campaigns._video_item', ['v' => $v, 'redirectTab' => 'productos', 'redirectProduct' => $product->id])
                    @endforeach
                </div>
            @else
                <p class="text-sm text-ink-soft/60">Aún no hay videos subidos para este producto.</p>
            @endif
        </div>
    </div>

    <p class="text-center text-[11px] font-semibold uppercase tracking-widest text-ink-soft/40">o</p>

    <div class="rounded-xl border border-line overflow-hidden bg-white" data-md-fold @if($collapseHyperframes) data-md-fold-collapsed @endif>
        <button type="button" class="flex w-full items-start gap-3 border-b border-line bg-mist/30 px-4 py-3 text-left" data-md-fold-toggle aria-expanded="{{ $collapseHyperframes ? 'false' : 'true' }}">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 3</p>
                <h3 class="mt-0.5 font-semibold text-ink">Generar video con HyperFrames</h3>
                <p class="mt-1 text-sm text-ink-soft/70">Promo vertical 9:16 Catalog Pop (~30s): fondo claro, producto grande, tipografía kinetic. Render vía <code class="text-[11px]">hyperframes.ceballosleon.com</code>.</p>
            </div>
            <span class="mt-1 shrink-0 text-ink-soft/50 transition-transform duration-150 md-fold-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="p-4 space-y-4" data-md-fold-body @if($collapseHyperframes) hidden @endif>
            @unless($hyperframes['ok'] ?? false)
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                    HyperFrames no está listo. Arranca el bridge en el puerto <code>9014</code>
                    (<code>tools/hyperframes-ads/bridge/start-bridge.cmd</code>) y define
                    <code>HYPERFRAMES_ADS_URL=http://host.docker.internal:9014</code> (Docker) o
                    <code>http://127.0.0.1:9014</code> (XAMPP).
                </p>
            @endunless
            <div class="space-y-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50 mb-1.5 block" for="md-hf-style-{{ $product->id }}">Estilo visual</label>
                    @php
                        $hfStyles = $hyperframes['visual_styles'] ?? config('multidrop.marketing.hyperframes.visual_styles', []);
                        $hfDefaultStyle = $hyperframes['default_visual_style'] ?? config('multidrop.marketing.hyperframes.default_visual_style', 'signal');
                    @endphp
                    <select
                        id="md-hf-style-{{ $product->id }}"
                        class="admin-input !py-1.5 !px-2 !text-sm w-full max-w-md md-hyperframes-style"
                        data-product-id="{{ $product->id }}"
                    >
                        @foreach($hfStyles as $styleKey => $styleMeta)
                            <option value="{{ $styleKey }}" @selected($styleKey === $hfDefaultStyle)>
                                {{ $styleMeta['label'] ?? $styleKey }}@if(!empty($styleMeta['hint'])) — {{ $styleMeta['hint'] }}@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] text-ink-soft/50 md-hyperframes-style-hint" data-product-id="{{ $product->id }}">Cambia paleta, tipografía y energía del motion.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="admin-btn !px-3 !py-1.5 text-sm md-hyperframes-go"
                        data-campaign-id="{{ $campaign->id }}"
                        data-product-id="{{ $product->id }}"
                        data-prompt-id=""
                        @disabled(! ($hyperframes['ok'] ?? false))
                    >Generar con HyperFrames</button>
                </div>
            </div>
            <p class="text-xs text-ink-soft/55 md-hyperframes-msg" data-product-id="{{ $product->id }}" role="status" aria-live="polite"></p>
            <div class="hidden rounded-lg border border-line bg-mist/40 px-3 py-2 md-hyperframes-progress" data-product-id="{{ $product->id }}">
                <div class="flex items-center justify-between gap-2 text-[11px] font-semibold uppercase tracking-wide text-ink-soft/55">
                    <span>Progreso</span>
                    <span class="md-hyperframes-step" data-product-id="{{ $product->id }}">0/8</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white">
                    <div class="h-full w-0 rounded-full bg-teal transition-all duration-300 md-hyperframes-bar" data-product-id="{{ $product->id }}"></div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] text-ink-soft/55 md-hyperframes-progress-hint">Puedes detener el render en cualquier momento.</p>
                    <button
                        type="button"
                        class="admin-btn-danger !px-2.5 !py-1 !text-[11px] md-hyperframes-cancel"
                        data-product-id="{{ $product->id }}"
                        disabled
                    >Detener</button>
                </div>
            </div>

            @if($hyperframesVideos->isNotEmpty())
                <div class="space-y-2 border-t border-line pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Videos HyperFrames ({{ $hyperframesVideos->count() }})</p>
                    @foreach($hyperframesVideos as $v)
                        @include('admin.store.marketing.campaigns._video_item', ['v' => $v, 'redirectTab' => 'productos', 'redirectProduct' => $product->id])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <p class="text-center text-[11px] font-semibold uppercase tracking-widest text-ink-soft/40">o</p>

    <div class="rounded-xl border border-line overflow-hidden bg-white" data-md-fold @if($collapseNotebook) data-md-fold-collapsed @endif>
        <button type="button" class="flex w-full items-start gap-3 border-b border-line bg-mist/30 px-4 py-3 text-left" data-md-fold-toggle aria-expanded="{{ $collapseNotebook ? 'false' : 'true' }}">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 4</p>
                <h3 class="mt-0.5 font-semibold text-ink">Generar videos con NotebookLM</h3>
                <p class="mt-1 text-sm text-ink-soft/70">Envía contenidos (o solo la URL) a Seller Central. El agente NotebookLM genera el MP4 (hasta ~30–45 min) y lo devuelve al producto.</p>
            </div>
            <span class="mt-1 shrink-0 text-ink-soft/50 transition-transform duration-150 md-fold-chevron" aria-hidden="true">▾</span>
        </button>
        <div class="p-4 space-y-4" data-md-fold-body @if($collapseNotebook) hidden @endif>
            @unless($notebooklm['ok'] ?? false)
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                    Seller Central no está conectado.
                    <a href="{{ $notebooklm['settings_url'] ?? route('admin.store.marketing.sellercentral.index') }}" class="underline text-teal">Configura la API key</a>
                    en Marketing → Publicaciones.
                </p>
            @endunless

            <div class="space-y-3">
                <fieldset class="space-y-2">
                    <legend class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Qué enviar</legend>
                    <label class="flex items-start gap-2 rounded-lg border border-line bg-mist/20 px-3 py-2 cursor-pointer">
                        <input type="radio" name="md-nlm-mode-{{ $product->id }}" value="assets" class="mt-1 md-notebooklm-mode" data-product-id="{{ $product->id }}" checked>
                        <span>
                            <span class="block text-sm font-medium text-ink">Contenidos del producto</span>
                            <span class="block text-xs text-ink-soft/60">Imágenes de galería, videos del producto y textos de la ficha.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 rounded-lg border border-line bg-mist/20 px-3 py-2 cursor-pointer">
                        <input type="radio" name="md-nlm-mode-{{ $product->id }}" value="url_only" class="mt-1 md-notebooklm-mode" data-product-id="{{ $product->id }}">
                        <span>
                            <span class="block text-sm font-medium text-ink">Solo URL del producto</span>
                            <span class="block text-xs text-ink-soft/60">Seller Central / NotebookLM trabaja con el enlace público de la ficha.</span>
                        </span>
                    </label>
                </fieldset>
                <div>
                    <label class="mb-1 block text-xs font-medium text-ink-soft" for="md-nlm-instructions-{{ $product->id }}">Instrucciones (opcional)</label>
                    <textarea id="md-nlm-instructions-{{ $product->id }}" rows="2" class="admin-input text-sm md-notebooklm-instructions" data-product-id="{{ $product->id }}" placeholder="Ej. tono juvenil, cerrar con CTA de compra…"></textarea>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="admin-btn !px-3 !py-1.5 text-sm md-notebooklm-go"
                        data-campaign-id="{{ $campaign->id }}"
                        data-product-id="{{ $product->id }}"
                        @disabled(! ($notebooklm['ok'] ?? false) || (bool) $activeNotebookJob)
                    >Enviar a NotebookLM</button>
                    <button
                        type="button"
                        class="admin-btn-danger !px-2.5 !py-1 !text-[11px] md-notebooklm-cancel"
                        data-product-id="{{ $product->id }}"
                        @disabled(! $activeNotebookJob)
                    >Detener</button>
                </div>
            </div>

            <p class="text-xs text-ink-soft/55 md-notebooklm-msg" data-product-id="{{ $product->id }}" role="status" aria-live="polite"></p>

            <div
                class="rounded-lg border border-line bg-mist/40 px-3 py-2 md-notebooklm-process {{ $activeNotebookJob ? '' : 'hidden' }}"
                data-product-id="{{ $product->id }}"
                data-job-id="{{ $activeNotebookJob?->id }}"
                @if($activeNotebookJob) data-job-bootstrap="{{ e(json_encode($activeNotebookJob->toPollPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}" @endif
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Proceso</p>
                        <p class="mt-0.5 text-sm font-semibold text-ink md-notebooklm-status" data-product-id="{{ $product->id }}">
                            {{ $activeNotebookJob?->statusLabel() ?: '—' }}
                        </p>
                    </div>
                    <span class="admin-badge bg-amber-100 text-amber-800 md-notebooklm-badge" data-product-id="{{ $product->id }}">
                        {{ $activeNotebookJob?->status ?: 'idle' }}
                    </span>
                </div>
                <ol class="mt-3 space-y-1.5 text-xs text-ink-soft/80 md-notebooklm-steps" data-product-id="{{ $product->id }}">
                    @if($activeNotebookJob)
                        @forelse($activeNotebookJob->steps() as $step)
                            <li class="flex gap-2">
                                <span class="shrink-0 {{ $step['ok'] === false ? 'text-coral' : ($step['ok'] === true ? 'text-teal' : 'text-ink-soft/40') }}">•</span>
                                <span>
                                    <span class="font-medium text-ink-soft/70">{{ $step['step'] }}</span>
                                    @if($step['message']) — {{ $step['message'] }}@endif
                                </span>
                            </li>
                        @empty
                            <li class="text-ink-soft/50">Esperando pasos del agente NotebookLM…</li>
                        @endforelse
                    @endif
                </ol>
            </div>

            @if($notebooklmVideos->isNotEmpty())
                <div class="space-y-2 border-t border-line pt-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Videos NotebookLM ({{ $notebooklmVideos->count() }})</p>
                    @foreach($notebooklmVideos as $v)
                        @include('admin.store.marketing.campaigns._video_item', ['v' => $v, 'redirectTab' => 'productos', 'redirectProduct' => $product->id])
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
