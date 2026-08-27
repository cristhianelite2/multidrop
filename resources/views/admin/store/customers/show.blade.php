@extends('layouts.admin')

@section('title', $customer->email.' — Clientes')
@section('heading', $customer->name ?: $customer->email)
@section('subheading', $customer->email.' · '.$customer->phone)

@section('content')
    <div class="mb-5">
        <a href="{{ route('admin.store.customers.index') }}" class="admin-btn-secondary">← Clientes</a>
    </div>

    <div class="admin-blocks">
        <div class="admin-card p-5 space-y-2">
            <h2 class="font-display text-lg font-bold text-ink">Datos</h2>
            <p><strong>{{ $customer->name ?: '—' }}</strong></p>
            <p>{{ $customer->email }}</p>
            <p>{{ $customer->phone ?: 'Sin teléfono' }}</p>
            <p class="text-sm text-ink-soft/60">{{ $customer->orders_count ?? $customer->orders->count() }} pedidos</p>
        </div>

        <div class="admin-card overflow-hidden">
            <div class="border-b border-line px-4 py-3">
                <h2 class="font-display text-base font-bold text-ink">Pedidos</h2>
            </div>
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase text-ink-soft/50">
                    <th class="px-4 py-3">Pedido</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Pago</th>
                    <th class="px-4 py-3">Fecha</th>
                </tr>
                </thead>
                <tbody>
                @forelse($customer->orders as $order)
                    <tr class="border-b border-line/70">
                        <td class="px-4 py-3"><a class="text-teal underline" href="{{ route('admin.store.orders.show', $order) }}">{{ $order->number }}</a></td>
                        <td class="px-4 py-3">${{ number_format((float)$order->total, 2) }}</td>
                        <td class="px-4 py-3">{{ $order->payment_status }}</td>
                        <td class="px-4 py-3">{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-ink-soft/60">Sin pedidos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
