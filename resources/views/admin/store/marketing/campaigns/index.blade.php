@extends('layouts.admin')

@section('title', 'Campañas — '.$store->name)
@section('heading', 'Campañas')
@section('subheading', 'Productos y videos · tope '.$budgetCap.' '.$store->currency().'/día')

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-soft/70">Cada campaña agrupa productos (o combos) y los videos de cada uno.</p>
        <a href="{{ route('admin.store.marketing.campaigns.create') }}" class="admin-btn">Nueva campaña</a>
    </div>

    <div class="space-y-4">
        @forelse($campaigns as $c)
            @php
                $videoCounts = is_array($c->product_video_counts ?? null) ? $c->product_video_counts : [];
            @endphp
            <div class="admin-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'productos']) }}" class="font-semibold text-ink hover:text-teal">{{ $c->name }}</a>
                        <div class="mt-0.5 text-xs text-ink-soft/55">
                            {{ implode(' · ', $c->platformList()) ?: '—' }}
                            · {{ number_format((float) $c->daily_budget, 2) }} {{ $c->currency }}/día
                            · {{ $c->products_count }} {{ $c->products_count === 1 ? 'producto' : 'productos' }}
                            · {{ $c->videos_count }} {{ $c->videos_count === 1 ? 'video' : 'videos' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="admin-badge {{ $c->status === 'ready' ? 'bg-emerald-100 text-emerald-800' : ($c->status === 'paused' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700') }}">{{ $c->status }}</span>
                        <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.campaigns.edit', ['campaign' => $c, 'tab' => 'productos']) }}">Abrir</a>
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
                    @if($c->products->isNotEmpty())
                        <ul class="divide-y divide-line/70">
                            @foreach($c->products as $p)
                                @php $n = (int) ($videoCounts[$p->id] ?? 0); @endphp
                                <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                    <div class="min-w-0 flex items-center gap-2">
                                        @if($p->image_url)
                                            <img src="{{ $p->image_url }}" alt="" class="h-8 w-8 rounded object-cover border border-line shrink-0">
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-medium text-ink">
                                                {{ $p->localizedName() }}
                                                @if(data_get($p->creative_data, 'is_combo'))
                                                    <span class="admin-badge !text-[10px] !px-1.5 !py-0 ml-1 bg-sky-100 text-sky-800">combo</span>
                                                @endif
                                            </div>
                                            @if($p->sku)
                                                <div class="text-[11px] text-ink-soft/50">{{ $p->sku }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-xs text-ink-soft/60">{{ $n }} {{ $n === 1 ? 'video' : 'videos' }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-ink-soft/60">Sin productos. Ábrela para agregar un producto o combo.</p>
                    @endif
                </div>
            </div>
        @empty
            <div class="admin-card px-4 py-10 text-center text-ink-soft/60">
                Aún no hay campañas. Crea una para agregar productos y videos.
            </div>
        @endforelse
    </div>
@endsection
