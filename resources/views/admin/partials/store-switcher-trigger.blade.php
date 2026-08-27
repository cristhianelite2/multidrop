@php
    $active = $currentStore ?? null;
@endphp

<button
    type="button"
    data-switcher-toggle
    aria-expanded="false"
    aria-controls="store-switcher-panel"
    class="flex w-full items-center gap-3 rounded-2xl border border-line bg-gradient-to-br from-white to-mist/80 px-3 py-2.5 text-left shadow-sm transition hover:border-teal/40 hover:shadow-md"
>
    <div class="flex h-9 w-9 items-center justify-center rounded-xl text-xs font-bold" style="background: {{ $active?->identityColor() ?? '#0f172a' }}; color: {{ $active?->identityInk() ?? '#fff' }}">
        {{ $active?->identityIcon() ?? 'MD' }}
    </div>
    <div class="min-w-0 flex-1">
        <div class="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-soft/50">Sitio activo</div>
        <div data-switcher-label class="truncate text-sm font-semibold text-ink">{{ $active->name ?? 'Sin sitio' }}</div>
        <div class="truncate text-[11px] text-ink-soft/55">
            @if($active?->parent)
                {{ $active->parent->name }} /
            @endif
            {{ $active?->products_count ?? 0 }} prod.
        </div>
    </div>
    <span class="text-ink-soft/50">▾</span>
</button>
