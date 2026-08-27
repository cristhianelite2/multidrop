@extends('layouts.admin')

@section('title', 'Estadísticas — '.$store->name)
@section('heading', 'Estadísticas de tienda')
@section('subheading', $store->name.' · '.strtoupper((string) $store->store_type))

@section('content')
    @php
        $maxRevenue = max(1, (float) collect($chart14 ?? [])->max('revenue'));
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
        <div class="admin-card p-5">
            <div class="text-xs uppercase tracking-[0.12em] text-ink-soft/55">Nuevas ventas</div>
            <div class="mt-2 text-3xl font-display font-bold text-ink">{{ $stats['new_sales_unread'] }}</div>
            <div class="text-sm text-ink-soft/70">Sin leer por admin</div>
        </div>
        <div class="admin-card p-5">
            <div class="text-xs uppercase tracking-[0.12em] text-ink-soft/55">Reclamos abiertos</div>
            <div class="mt-2 text-3xl font-display font-bold text-ink">{{ $stats['claims_open'] }}</div>
            <div class="text-sm text-ink-soft/70">Open + En proceso</div>
        </div>
        <div class="admin-card p-5">
            <div class="text-xs uppercase tracking-[0.12em] text-ink-soft/55">Ventas 30 días</div>
            <div class="mt-2 text-3xl font-display font-bold text-ink">{{ $stats['paid_30'] }} <span class="text-sm text-ink-soft/70">/ {{ $stats['orders_30'] }}</span></div>
            <div class="text-sm text-ink-soft/70">Conversión pagada: {{ number_format((float) $stats['conversion_30'], 1) }}%</div>
        </div>
        <div class="admin-card p-5">
            <div class="text-xs uppercase tracking-[0.12em] text-ink-soft/55">Ingresos 30 días</div>
            <div class="mt-2 text-3xl font-display font-bold text-ink">${{ number_format((float) $stats['revenue_30'], 2) }}</div>
            <div class="text-sm text-ink-soft/70">{{ $store->market?->currency ?? 'MXN' }}</div>
        </div>
    </div>

    <div class="admin-blocks">
        <div class="admin-card p-5 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink mb-4">Ingresos diarios (últimos 14 días)</h2>
            <div class="space-y-2">
                @forelse($chart14 as $row)
                    @php $w = $maxRevenue > 0 ? max(2, (int) round(($row['revenue'] / $maxRevenue) * 100)) : 2; @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="text-ink-soft/75">{{ $row['day'] }}</span>
                            <span class="text-ink-soft/75">${{ number_format((float) $row['revenue'], 2) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-mist overflow-hidden">
                            <div class="h-full bg-teal" style="width: {{ $w }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-soft/60">Sin datos para graficar.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink mb-4">Órdenes y pagos (14 días)</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                            <th class="py-2.5 pr-3">Día</th>
                            <th class="py-2.5 pr-3">Órdenes</th>
                            <th class="py-2.5 pr-3">Pagadas</th>
                            <th class="py-2.5 pr-3">% Pagadas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chart14 as $row)
                            @php $conv = $row['orders'] > 0 ? ($row['paid'] / $row['orders']) * 100 : 0; @endphp
                            <tr class="border-b border-line/70 last:border-0">
                                <td class="py-2.5 pr-3">{{ $row['day'] }}</td>
                                <td class="py-2.5 pr-3">{{ $row['orders'] }}</td>
                                <td class="py-2.5 pr-3">{{ $row['paid'] }}</td>
                                <td class="py-2.5 pr-3">{{ number_format($conv, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 rounded-2xl border border-dashed border-line bg-mist/30 px-3 py-2 text-xs text-ink-soft/70">
                Visitas/Ventas: tracking de visitas aún no está habilitado en BD; por ahora se muestra conversión de órdenes pagadas.
            </div>
        </div>
    </div>
@endsection

