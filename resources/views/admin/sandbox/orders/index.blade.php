@extends('layouts.admin')

@section('title', 'Sandbox CJ')
@section('heading', 'Sandbox CJ')
@section('subheading', 'Pedidos de prueba de plantillas → createOrder / tracking de CJ Dropshipping.')

@section('content')
<div class="admin-card overflow-hidden">
    <div class="border-b border-line px-4 py-3">
        <p class="text-sm text-ink-soft/70">Solo sandbox / local. No son pedidos de tienda real. La respuesta cruda de CJ se guarda aquí para depurar selección → pago → confirmación → logística.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-line bg-mist/40 text-left text-xs uppercase tracking-wide text-ink-soft/50">
                <th class="px-4 py-2.5">Pedido</th>
                <th class="px-4 py-2.5">Plantilla</th>
                <th class="px-4 py-2.5">Cliente</th>
                <th class="px-4 py-2.5">Total</th>
                <th class="px-4 py-2.5">CJ</th>
                <th class="px-4 py-2.5">Guía</th>
                <th class="px-4 py-2.5"></th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $row)
                <tr class="border-b border-line/70">
                    <td class="px-4 py-3 font-mono text-xs">{{ $row->number }}</td>
                    <td class="px-4 py-3">{{ $row->theme?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div>{{ $row->name }}</div>
                        <div class="text-xs text-ink-soft/60">{{ $row->email }}</div>
                    </td>
                    <td class="px-4 py-3">${{ number_format((float) $row->total, 2) }}</td>
                    <td class="px-4 py-3">
                        <span class="admin-badge {{ $row->cjOk() ? 'bg-teal/10 text-teal' : ($row->fulfillment_status === 'skipped' ? 'bg-amber/10 text-amber' : 'bg-rose-50 text-rose-700') }}">
                            {{ $row->fulfillment_status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs">{{ $row->tracking_number ?: '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <a class="admin-btn !py-1 !px-2 text-xs" href="{{ route('admin.sandbox.orders.show', $row) }}">Ver CJ</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-ink-soft/60">Aún no hay pedidos sandbox. Completa el flujo en /t/{plantilla}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
        <div class="px-4 py-3">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
