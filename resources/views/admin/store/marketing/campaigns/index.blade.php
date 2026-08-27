@extends('layouts.admin')

@section('title', 'Campañas — '.$store->name)
@section('heading', 'Campañas')
@section('subheading', 'Videos, resultados y presupuesto · tope '.$budgetCap.' '.$store->currency().'/día')

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-soft/70">Cada campaña lleva sus videos. Ábrela para subir, generar o editar copy.</p>
        <a href="{{ route('admin.store.marketing.campaigns.create') }}" class="admin-btn">Nueva campaña</a>
    </div>

    <div class="space-y-4">
        @forelse($campaigns as $c)
            @php $k = $c->kpis ?? ['spend' => 0, 'roas' => 0]; @endphp
            <div class="admin-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('admin.store.marketing.campaigns.edit', $c) }}" class="font-semibold text-ink hover:text-teal">{{ $c->name }}</a>
                        <div class="mt-0.5 text-xs text-ink-soft/55">
                            {{ implode(' · ', $c->platformList()) ?: '—' }}
                            · {{ number_format((float) $c->daily_budget, 2) }} {{ $c->currency }}/día
                            · {{ $c->videos_count }} {{ $c->videos_count === 1 ? 'video' : 'videos' }}
                            @if(($k['spend'] ?? 0) > 0)
                                · invertido {{ number_format((float) $k['spend'], 2) }}
                                · ROAS {{ number_format((float) $k['roas'], 2) }}x
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="admin-badge {{ $c->status === 'ready' ? 'bg-emerald-100 text-emerald-800' : ($c->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">{{ $c->status }}</span>
                        <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'ads']) }}">Videos</a>
                        <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.campaigns.edit', $c) }}">Abrir</a>
                        <form method="post" action="{{ route('admin.store.marketing.campaigns.duplicate', $c) }}">
                            @csrf
                            <button class="admin-btn-secondary !px-3 !py-1.5 text-xs">Duplicar</button>
                        </form>
                        <form method="post" action="{{ route('admin.store.marketing.campaigns.destroy', $c) }}" onsubmit="return confirm('¿Eliminar esta campaña y sus videos?')">
                            @csrf @method('DELETE')
                            <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                        </form>
                    </div>
                </div>

                <div class="p-4">
                    @if($c->videos->isNotEmpty())
                        <div class="flex gap-3 overflow-x-auto pb-1">
                            @foreach($c->videos as $v)
                                <a href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'ads']) }}"
                                   class="group w-36 shrink-0">
                                    <video src="{{ $v->publicUrl() }}" preload="metadata" muted class="h-40 w-36 rounded-lg border border-line bg-black object-cover"></video>
                                    <div class="mt-1.5 truncate text-xs font-medium text-ink group-hover:text-teal">{{ $v->ad_headline ?: ($v->original_name ?: 'Video #'.$v->id) }}</div>
                                    <div class="text-[11px] text-ink-soft/50">{{ $v->source === 'creatify' ? 'Creatify' : 'Subido' }}{{ $v->ad_cta ? ' · '.$v->ad_cta : '' }}</div>
                                </a>
                            @endforeach
                            <a href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'ads']) }}"
                               class="flex h-40 w-36 shrink-0 flex-col items-center justify-center rounded-lg border border-dashed border-line text-sm text-ink-soft/60 hover:border-teal hover:text-teal">
                                + Añadir
                            </a>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-line px-4 py-6">
                            <p class="text-sm text-ink-soft/60">Esta campaña aún no tiene videos.</p>
                            <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'ads']) }}">Subir o generar</a>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="admin-card px-4 py-10 text-center text-ink-soft/60">
                Aún no hay campañas. Crea una para cargar videos y resultados.
            </div>
        @endforelse
    </div>
@endsection
