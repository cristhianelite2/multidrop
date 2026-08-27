@php
    $stores = $adminStores ?? collect();
    $active = $currentStore ?? null;
@endphp

{{-- Panel flotante: fuera del sidebar para no quedar limitado a 260px --}}
<div
    id="store-switcher-backdrop"
    data-switcher-backdrop
    class="fixed inset-0 z-[90] hidden bg-ink/20 backdrop-blur-[2px]"
></div>

<div
    id="store-switcher-panel"
    data-switcher-menu
    data-endpoint="{{ route('admin.context.store') }}"
    data-open="0"
    class="fixed z-[100] hidden w-[min(560px,calc(100vw-1.5rem))] max-h-[min(720px,calc(100vh-2rem))] overflow-hidden rounded-2xl border border-line bg-white shadow-2xl shadow-ink/20"
    style="left: 1rem; top: 1rem;"
    role="dialog"
    aria-label="Árbol de sitios"
>
    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-soft/50">Árbol de sitios</div>
            <div class="text-sm font-semibold text-ink">Mega y mini-tiendas anidadas</div>
        </div>
        <button type="button" data-switcher-close class="admin-btn-secondary !px-3 !py-1.5 text-xs">Cerrar</button>
    </div>

    <div class="overflow-y-auto max-h-[calc(min(720px,100vh-2rem)-64px)] p-3">
        @forelse($stores as $store)
            @php
                $depth = (int) ($store->tree_depth ?? $store->depth());
                $isActive = $active && $active->id === $store->id;
                $isMega = $store->store_type === 'mega';
                $pad = 12 + ($depth * 28);
                $pulse = data_get($adminStorePulseMap ?? [], $store->id, ['sales_unread' => 0, 'claims_open' => 0]);
            @endphp

            <div class="relative mb-1.5">
                @if($depth > 0)
                    <span class="pointer-events-none absolute bottom-2 top-0 w-px bg-line/90" style="left: {{ 24 + (($depth - 1) * 28) }}px"></span>
                    <span class="pointer-events-none absolute top-[22px] h-px bg-line/90" style="left: {{ 24 + (($depth - 1) * 28) }}px; width: 18px;"></span>
                @endif

                <div
                    class="flex flex-wrap items-center gap-3 rounded-2xl border px-3 py-2.5 transition {{ $isActive ? 'border-teal/30 bg-teal/10' : 'border-transparent hover:border-line hover:bg-mist/70' }}"
                    style="margin-left: {{ $depth * 28 }}px"
                >
                    <button
                        type="button"
                        data-store-id="{{ $store->id }}"
                        data-store-name="{{ $store->name }}"
                        class="flex min-w-0 flex-1 items-center gap-3 text-left"
                    >
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-[13px] font-bold" style="background: {{ $store->identityColor() }}; color: {{ $store->identityInk() }}">
                            {{ $store->identityIcon() }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold {{ $isActive ? 'text-teal' : 'text-ink' }}">{{ $store->name }}</span>
                                <span class="admin-badge {{ $store->status === 'live' ? 'bg-emerald-100 text-emerald-800' : ($store->status === 'paused' ? 'bg-amber/15 text-amber' : 'bg-slate-100 text-slate-600') }}">
                                    {{ $store->status }}
                                </span>
                                @if($isMega)
                                    <span class="admin-badge bg-sky-100 text-sky-800">Mega</span>
                                @else
                                    <span class="admin-badge bg-teal/10 text-teal">Mini · nivel {{ $depth }}</span>
                                @endif
                                @if(($pulse['sales_unread'] ?? 0) > 0)
                                    <span class="admin-badge bg-emerald-100 text-emerald-800">Ventas nuevas: {{ $pulse['sales_unread'] }}</span>
                                @endif
                                @if(($pulse['claims_open'] ?? 0) > 0)
                                    <span class="admin-badge bg-amber-100 text-amber-800">Reclamos: {{ $pulse['claims_open'] }}</span>
                                @endif
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-1 gap-y-1 text-[12px] text-ink-soft/65">
                                @if($store->parent)
                                    <span class="text-ink-soft/80">{{ $store->parent->name }}</span>
                                    <span class="mx-1">›</span>
                                @endif
                                <span>/{{ $store->slug }}</span>
                                <span class="mx-1">·</span>
                                <span>{{ $store->sector ?? 'general' }}</span>
                                <span class="mx-1">·</span>
                                @include('admin.partials.store-locale-currency', ['store' => $store])
                            </div>
                        </div>

                        <div class="hidden sm:block shrink-0 rounded-xl bg-mist px-3 py-1.5 text-center">
                            <div class="text-sm font-bold text-ink">{{ $store->products_count ?? 0 }}</div>
                            <div class="text-[10px] uppercase tracking-[0.08em] text-ink-soft/50">productos</div>
                        </div>
                    </button>

                    @canperm('store.manage')
                        <a
                            href="{{ route('admin.stores.manage', $store) }}"
                            class="admin-btn !px-3 !py-2 text-xs shrink-0"
                            onclick="event.stopPropagation()"
                        >Administrar</a>
                    @endcanperm
                </div>
            </div>
        @empty
            <div class="px-3 py-8 text-center text-sm text-ink-soft/60">No hay sitios configurados.</div>
        @endforelse
    </div>
</div>
