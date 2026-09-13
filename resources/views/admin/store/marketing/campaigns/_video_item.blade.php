@php
    $redirectTab = $redirectTab ?? 'productos';
    $title = $v->original_name ?: basename($v->path);
@endphp
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-line px-2.5 py-2">
    <button type="button" title="Reproducir" class="relative shrink-0 md-open-video-modal overflow-hidden rounded border border-line bg-black group" style="width:36px;height:64px;max-width:36px;max-height:64px" data-url="{{ $v->publicUrl() }}" data-title="{{ $title }}">
        <video src="{{ $v->publicUrl() }}" muted playsinline preload="metadata" width="36" height="64" class="pointer-events-none" style="width:36px;height:64px;object-fit:cover;display:block"></video>
        <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/35 text-[10px] font-semibold text-white opacity-90 group-hover:bg-black/50">▶</span>
    </button>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="truncate text-xs font-medium text-ink max-w-[14rem]" title="{{ $title }}">{{ $title }}</span>
            <span class="admin-badge !text-[10px] !px-1.5 !py-0 {{ $v->source === 'creatify' ? 'bg-sky-100 text-sky-800' : ($v->source === 'remotion' ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-700') }}">{{ $v->source === 'creatify' ? 'Creatify' : ($v->source === 'remotion' ? 'Remotion' : 'Subido') }}</span>
            <span class="admin-badge !text-[10px] !px-1.5 !py-0 {{ $v->stripped_at ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $v->stripped_at ? 'sin huellas' : 'sin limpiar' }}</span>
        </div>
        <div class="mt-1 flex flex-wrap items-center gap-1.5">
            <input type="text" readonly value="{{ $v->publicUrl() }}" class="admin-input !py-1 !px-2 !text-[11px] font-mono flex-1 min-w-[10rem] max-w-md" title="{{ $v->publicUrl() }}">
            <button type="button" class="admin-btn-secondary !px-2 !py-1 !text-[11px] md-copy-video-url" data-url="{{ $v->publicUrl() }}">Copiar</button>
            <a class="admin-btn-secondary !px-2 !py-1 !text-[11px]" href="{{ route('admin.store.marketing.videos.download', $v) }}">Descargar</a>
            <button type="button" class="admin-btn-secondary !px-2 !py-1 !text-[11px] md-publication-json" data-url="{{ route('admin.store.marketing.videos.publication-json', $v) }}">Generar JSON de publicación</button>
            <form method="post" action="{{ route('admin.store.marketing.videos.destroy', $v) }}" onsubmit="return confirm('¿Eliminar este video?')" class="inline">
                @csrf @method('DELETE')
                <input type="hidden" name="from" value="campaign">
                <input type="hidden" name="redirect_tab" value="{{ $redirectTab }}">
                @if(! empty($redirectProduct))
                    <input type="hidden" name="redirect_product" value="{{ $redirectProduct }}">
                @endif
                <button type="submit" class="admin-btn-danger !px-2 !py-1 !text-[11px]">Eliminar</button>
            </form>
        </div>
    </div>
</div>
