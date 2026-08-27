@extends('layouts.admin')

@section('title', 'Dashboard — Multidrop')
@section('heading', 'Dashboard')
@section('subheading', 'Vista operativa de toda la plataforma')

@section('content')
@php
    $maxRev = max(1, (float) collect($chart14)->max('revenue'));
    $maxOrd = max(1, (int) collect($chart14)->max('orders'));
@endphp

{{-- Alertas rápidas --}}
@if(($stats['new_sales_unread'] ?? 0) > 0 || ($stats['claims_open'] ?? 0) > 0)
<div class="mb-5 flex flex-wrap gap-3">
    @if($stats['new_sales_unread'] > 0)
        <div class="flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-2.5">
            <span class="text-xl">🛒</span>
            <div>
                <div class="text-sm font-semibold text-emerald-800">{{ $stats['new_sales_unread'] }} venta{{ $stats['new_sales_unread'] > 1 ? 's' : '' }} nueva{{ $stats['new_sales_unread'] > 1 ? 's' : '' }} sin revisar</div>
                @if($currentStore)
                    <a class="text-xs text-emerald-700 underline" href="{{ route('admin.store.orders.index') }}">Ver pedidos →</a>
                @endif
            </div>
        </div>
    @endif
    @if($stats['claims_open'] > 0)
        <div class="flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-2.5">
            <span class="text-xl">⚠️</span>
            <div>
                <div class="text-sm font-semibold text-amber-800">{{ $stats['claims_open'] }} reclamo{{ $stats['claims_open'] > 1 ? 's' : '' }} abierto{{ $stats['claims_open'] > 1 ? 's' : '' }}</div>
                @if($currentStore)
                    <a class="text-xs text-amber-700 underline" href="{{ route('admin.store.claims.index') }}">Ver reclamos →</a>
                @endif
            </div>
        </div>
    @endif
</div>
@endif

{{-- KPIs plataforma --}}
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    @foreach([
        ['label' => 'Ventas nuevas (sin leer)', 'value' => $stats['new_sales_unread'], 'hint' => 'Pagadas · no abiertas', 'color' => 'text-emerald-700'],
        ['label' => 'Reclamos abiertos', 'value' => $stats['claims_open'], 'hint' => 'Open + En proceso', 'color' => 'text-amber-700'],
        ['label' => 'Ventas 30 días', 'value' => $stats['paid_30'].' / '.$stats['orders_30'], 'hint' => 'Pagadas / Total órdenes', 'color' => 'text-teal'],
        ['label' => 'Ingresos 30 días', 'value' => '$'.number_format((float)$stats['revenue_30'],2), 'hint' => 'Todas las tiendas', 'color' => 'text-sky-700'],
    ] as $c)
        <div class="admin-card p-5 transition hover:-translate-y-0.5 hover:shadow-md">
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-ink-soft/55">{{ $c['label'] }}</div>
            <div class="mt-2 font-display text-2xl font-bold {{ $c['color'] }}">{{ $c['value'] }}</div>
            <div class="mt-1 text-xs text-ink-soft/60">{{ $c['hint'] }}</div>
        </div>
    @endforeach
</section>

{{-- Gráficas --}}
<section class="admin-blocks mb-6">
    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink mb-4">Ingresos diarios — últimos 14 días</h2>
        <div class="space-y-2">
            @foreach($chart14 as $row)
                @php $w = max(2, (int)round(($row['revenue']/$maxRev)*100)); @endphp
                <div class="flex items-center gap-3">
                    <span class="w-10 shrink-0 text-xs text-ink-soft/60 text-right">{{ $row['day'] }}</span>
                    <div class="flex-1 h-2.5 rounded-full bg-mist overflow-hidden">
                        <div class="h-full bg-teal rounded-full transition-all" style="width:{{ $w }}%"></div>
                    </div>
                    <span class="w-20 shrink-0 text-right text-xs {{ $row['revenue']>0 ? 'font-semibold text-teal' : 'text-ink-soft/40' }}">
                        ${{ number_format((float)$row['revenue'],0) }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink mb-4">Órdenes vs Pagadas — últimos 14 días</h2>
        <div class="space-y-2">
            @foreach($chart14 as $row)
                @php $wO = max(2,(int)round(($row['orders']/$maxOrd)*100)); $wP = $row['orders']>0 ? max(2,(int)round(($row['paid']/$row['orders'])*100)) : 0; @endphp
                <div class="flex items-center gap-3">
                    <span class="w-10 shrink-0 text-xs text-ink-soft/60 text-right">{{ $row['day'] }}</span>
                    <div class="flex-1 h-2.5 rounded-full bg-mist overflow-hidden relative">
                        <div class="absolute inset-y-0 left-0 bg-slate-300 rounded-full" style="width:{{ $wO }}%"></div>
                        <div class="absolute inset-y-0 left-0 bg-teal rounded-full" style="width:{{ $wP }}%"></div>
                    </div>
                    <span class="w-20 shrink-0 text-right text-xs text-ink-soft/60">
                        {{ $row['paid'] }}<span class="text-ink-soft/40">/{{ $row['orders'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex gap-4 text-xs text-ink-soft/60">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-2 rounded bg-teal"></span> Pagadas</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-2 rounded bg-slate-300"></span> Totales</span>
        </div>
    </div>
</section>

{{-- Ventas recientes --}}
<section class="admin-blocks mb-6">
    <div class="admin-card p-5 sm:p-6 xl:col-span-2">
        <div class="flex items-center justify-between gap-3 mb-4">
            <h2 class="font-display text-base font-bold text-ink">Ventas recientes (pagadas)</h2>
        </div>
        @forelse($recentOrders as $o)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line/70 py-2.5 last:border-0">
                <div>
                    <span class="font-semibold text-sm text-ink">{{ $o->number }}</span>
                    <span class="mx-1 text-ink-soft/40">·</span>
                    <span class="text-xs text-ink-soft/70">{{ $o->store?->name ?? '—' }}</span>
                    <div class="text-xs text-ink-soft/55 mt-0.5">{{ $o->customer_email }}</div>
                </div>
                <div class="text-right">
                    <div class="font-semibold text-sm text-teal">${{ number_format((float)$o->total,2) }} {{ $o->currency }}</div>
                    <div class="text-xs text-ink-soft/55">{{ $o->created_at?->diffForHumans() }}</div>
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-ink-soft/60">Sin ventas todavía.</p>
        @endforelse
    </div>

    {{-- Estado por tienda --}}
    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-base font-bold text-ink mb-4">Estado por tienda</h2>
        <div class="space-y-2">
            @forelse($stores as $s)
                @php
                    $ns = (int)($storeNewSales[$s->id] ?? 0);
                    $cl = (int)($storeClaimsOpen[$s->id] ?? 0);
                @endphp
                <div class="flex items-center justify-between gap-2 rounded-xl border border-line px-3 py-2.5">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-ink truncate">{{ $s->name }}</div>
                        <div class="text-xs text-ink-soft/55">{{ $s->market?->code ?? '—' }} · {{ $s->store_type }}</div>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        @if($ns > 0)
                            <span class="admin-badge bg-emerald-100 text-emerald-800">{{ $ns }} venta{{ $ns>1?'s':'' }}</span>
                        @endif
                        @if($cl > 0)
                            <span class="admin-badge bg-amber-100 text-amber-800">{{ $cl }} reclamo{{ $cl>1?'s':'' }}</span>
                        @endif
                        @if($ns === 0 && $cl === 0)
                            <span class="admin-badge bg-slate-100 text-slate-600">Al día</span>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-ink-soft/60">Sin tiendas.</p>
            @endforelse
        </div>
    </div>
</section>

{{-- Estadísticas generales plataforma --}}
<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
    @foreach([
        ['label' => 'Productos', 'value' => $stats['products'], 'hint' => 'Catálogo global'],
        ['label' => 'Órdenes totales', 'value' => $stats['orders'], 'hint' => 'Historial completo'],
        ['label' => 'Tiendas live', 'value' => $stats['stores_live'], 'hint' => 'Publicadas'],
        ['label' => 'Mini-tiendas', 'value' => $stats['stores_mini'], 'hint' => 'Activas / no archivadas'],
    ] as $c)
        <div class="admin-card p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-ink-soft/55">{{ $c['label'] }}</div>
            <div class="mt-2 font-display text-3xl font-bold text-ink">{{ $c['value'] }}</div>
            <div class="mt-1 text-xs text-ink-soft/60">{{ $c['hint'] }}</div>
        </div>
    @endforeach
</section>
@endsection
