@php
    $isCombo = (bool) data_get($product->creative_data, 'is_combo', false);
    $productPrompts = $productPrompts ?? collect();
    $productVideos = $productVideos ?? collect();
    $ctas = $ctas ?? [];
    $maxMb = $maxMb ?? 80;
    $uploadedVideos = $productVideos->filter(fn ($v) => ($v->source ?: 'upload') === 'upload')->values();
    $generatedLoose = $productVideos->reject(fn ($v) => ($v->source ?: 'upload') === 'upload')->values();
    $promptVideoCount = $productPrompts->sum(fn ($pr) => $pr->videos->count());
    $videoCount = $productVideos->count() + $promptVideoCount;
    $ffmpegOk = $ffmpeg ?? true;
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

    <section class="rounded-xl border border-line overflow-hidden">
        <div class="border-b border-line bg-mist/30 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 1</p>
            <h3 class="mt-0.5 font-semibold text-ink">Generar video con Remotion</h3>
            <p class="mt-1 text-sm text-ink-soft/70">Crea el guion con MIIA y, en el prompt, genera el MP4. No sube un archivo.</p>
        </div>
        <div class="p-4 space-y-4">
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
    </section>

    <p class="text-center text-[11px] font-semibold uppercase tracking-widest text-ink-soft/40">o</p>

    <section class="rounded-xl border border-line overflow-hidden">
        <div class="border-b border-line bg-mist/30 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Opción 2</p>
            <h3 class="mt-0.5 font-semibold text-ink">Subir un video</h3>
            <p class="mt-1 text-sm text-ink-soft/70">Archivo que ya tienes. No usa MIIA ni Remotion.</p>
        </div>
        <div class="p-4 space-y-4">
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
    </section>
</div>
