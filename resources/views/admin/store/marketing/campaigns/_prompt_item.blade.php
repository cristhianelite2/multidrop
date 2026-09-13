@php
    $ctas = $ctas ?? [];
    $canRemotion = $p->product_id || $p->product || $p->hasLinkedProducts();
@endphp
<div class="rounded-xl border border-line overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 p-4">
        <div class="min-w-0">
            <div class="font-semibold text-ink">{{ $p->name }}</div>
            <p class="mt-1 text-sm text-ink-soft/70">{{ $p->hook ?: \Illuminate\Support\Str::limit($p->script, 120) }}</p>
            <p class="mt-1 text-xs text-ink-soft/50">
                {{ $p->target_platform }}
                @if($p->product)
                    · {{ \Illuminate\Support\Str::limit($p->product->name, 40) }}
                @elseif($p->product_id)
                    · Producto #{{ $p->product_id }}
                @endif
                @if(is_array($p->segments) && count($p->segments))
                    · {{ count($p->segments) }} segmentos
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            @if($canRemotion)
                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.prompts.download-zip', $p) }}" title="Imágenes, videos y prompt.txt">
                    Descargar ZIP
                </a>
            @endif
            <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.prompts.edit', $p) }}">Editar</a>
            <form method="post" action="{{ route('admin.store.marketing.prompts.destroy', $p) }}" onsubmit="return confirm('¿Eliminar este prompt?')">
                @csrf @method('DELETE')
                <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
            </form>
        </div>
    </div>
    @if($canRemotion)
        <div class="border-t border-line bg-mist/20 px-4 py-3 space-y-2">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Generar MP4 con Remotion</p>
            <div class="flex flex-wrap gap-2 items-center">
                <button type="button" class="admin-btn !px-3 !py-1.5 text-xs md-remotion-go" data-prompt-id="{{ $p->id }}">
                    Generar con Remotion
                </button>
                <label class="text-xs text-ink-soft/60 inline-flex items-center gap-1">
                    VO
                    <input type="file" accept="audio/mpeg,audio/mp3,audio/wav,audio/x-m4a,.mp3,.wav,.m4a" class="md-remotion-voice text-xs max-w-[9rem]" data-prompt-id="{{ $p->id }}">
                </label>
                <select class="admin-input !py-1 !px-2 !text-xs !w-auto md-remotion-preset" data-prompt-id="{{ $p->id }}">
                    <option value="product_presenter">Product Presenter</option>
                    <option value="quick_transition">Quick Transition</option>
                </select>
            </div>
            <p class="text-xs text-ink-soft/55 md-remotion-msg" data-prompt-id="{{ $p->id }}"></p>
        </div>
    @endif
    @if($p->videos->isNotEmpty())
        <div class="space-y-2 border-t border-line px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-soft/50">Videos generados ({{ $p->videos->count() }})</p>
            @foreach($p->videos as $pv)
                <div class="rounded-lg border border-line/80 bg-mist/30 p-2">
                    <div class="flex gap-2 items-start">
                        <button type="button" title="Reproducir video" class="relative shrink-0 md-open-video-modal overflow-hidden rounded border border-line bg-black group" style="width:56px;height:100px;max-width:56px;max-height:100px" data-url="{{ $pv->publicUrl() }}" data-title="{{ $pv->ad_headline ?: ($pv->original_name ?: 'Video Remotion') }}">
                            <video src="{{ $pv->publicUrl() }}" muted playsinline preload="metadata" width="56" height="100" class="pointer-events-none" style="width:56px;height:100px;object-fit:cover;display:block"></video>
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/35 text-xs font-semibold text-white opacity-90 group-hover:bg-black/50">▶</span>
                        </button>
                        <div class="min-w-0 flex-1 space-y-1 text-xs leading-snug">
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="admin-badge !text-[10px] !px-1.5 !py-0 {{ $pv->source === 'remotion' ? 'bg-violet-100 text-violet-800' : ($pv->source === 'creatify' ? 'bg-sky-100 text-sky-800' : 'bg-slate-100 text-slate-700') }}">{{ $pv->source === 'remotion' ? 'Remotion' : ($pv->source === 'creatify' ? 'Creatify' : 'Subido') }}</span>
                                @if($pv->ad_cta)
                                    <span class="text-[10px] text-ink-soft/50">· {{ $ctas[$pv->ad_cta] ?? $pv->ad_cta }}</span>
                                @endif
                            </div>
                            @if($pv->ad_headline)
                                <p class="font-medium text-ink line-clamp-2">{{ $pv->ad_headline }}</p>
                            @endif
                            @if($pv->ad_primary_text)
                                <p class="text-ink-soft/65 line-clamp-2">{{ $pv->ad_primary_text }}</p>
                            @endif
                            <div class="flex flex-wrap gap-1.5 pt-0.5 items-center">
                                <button type="button" class="admin-btn-secondary !px-2 !py-0.5 !text-[11px] md-copy-video-url" data-url="{{ $pv->publicUrl() }}">Copiar URL</button>
                                <form method="post" action="{{ route('admin.store.marketing.videos.destroy', $pv) }}" onsubmit="return confirm('¿Eliminar este video generado?')" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="redirect_tab" value="productos">
                                    @if(! empty($redirectProduct))
                                        <input type="hidden" name="redirect_product" value="{{ $redirectProduct }}">
                                    @endif
                                    <button type="submit" class="admin-btn-danger !px-2 !py-0.5 !text-[11px]">Eliminar</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
