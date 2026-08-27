@extends('layouts.admin')

@section('title', 'Administrar '.$store->name)
@section('heading', 'Administrar '.$store->name)
@section('subheading', ($store->store_type === 'mega' ? 'Mega-tienda' : 'Mini-tienda').($store->parent ? ' · bajo '.$store->parent->name : ''))

@section('content')
    <div class="admin-card relative overflow-hidden p-6 mb-6">
        <div class="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full bg-teal/10 blur-3xl"></div>
        <div class="relative flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap gap-2 mb-2">
                    <span class="admin-badge {{ $store->store_type === 'mega' ? 'bg-sky-100 text-sky-800' : 'bg-teal/10 text-teal' }}">{{ $store->store_type }}</span>
                    <span class="admin-badge {{ $store->status === 'live' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $store->status }}</span>
                    <span class="admin-badge bg-mist text-ink-soft">{{ $store->market?->code ?? '—' }}</span>
                </div>
                <p class="text-sm text-ink-soft/70 max-w-xl">
                    Catálogo, conversion engine y creativos del sitio activo.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('store.home') }}" target="_blank" class="admin-btn-secondary">Ver pública ↗</a>
                <a href="{{ route('admin.dashboard') }}" class="admin-btn-secondary">Volver al dashboard</a>
            </div>
        </div>
    </div>

    <div class="admin-blocks">
        @foreach($modules as $module)
            @canperm($module['perm'])
                <a href="{{ route($module['route']) }}" class="admin-card p-5 transition hover:-translate-y-0.5 hover:border-teal/40 hover:shadow-md group">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-display text-lg font-bold text-ink group-hover:text-teal transition">{{ $module['title'] }}</h2>
                        <span class="text-ink-soft/40 group-hover:text-teal">→</span>
                    </div>
                    <p class="mt-2 text-sm text-ink-soft/65">{{ $module['desc'] }}</p>
                    <div class="mt-4 text-xs font-semibold uppercase tracking-[0.12em] text-ink-soft/50">{{ $module['stat'] }}</div>
                </a>
            @endcanperm
        @endforeach
    </div>
@endsection
