@extends('layouts.buyer')

@section('title', 'Inicio')
@section('heading', 'Hola'.($buyer->name ? ', '.$buyer->name : ''))
@section('subheading', $buyer->email)

@section('content')
<div class="buyer-card">
    <p class="buyer-muted" style="margin-top:0">Desde aquí ves tus compras en todas las tiendas Multidrop, el seguimiento y los reclamos.</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px">
        <a class="buyer-btn" href="{{ route('buyer.orders.index') }}">Ver compras</a>
        <a class="buyer-btn-secondary" href="{{ route('buyer.tracking') }}">Seguimiento</a>
        <a class="buyer-btn-secondary" href="{{ route('buyer.claims.index') }}">Reclamos ({{ $claimsOpen }} abiertos)</a>
        @unless($buyer->hasPassword())
            <a class="buyer-btn-secondary" href="{{ route('buyer.security') }}">Crear contraseña</a>
        @endunless
    </div>
</div>

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.1rem">Últimas compras</h2>
    @forelse($orders as $order)
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:10px 0;border-bottom:1px solid #e2e8f0">
            <div>
                <strong>{{ $order->number }}</strong>
                <span class="buyer-muted"> · {{ $order->store?->name }}</span>
                <div class="buyer-muted">{{ $order->created_at?->format('d/m/Y') }} · {{ $order->paymentStatusLabel() }} / {{ $order->fulfillmentStatusLabel() }}</div>
                <div style="margin-top:8px">
                    @include('buyer.partials.order-timeline', ['order' => $order, 'compact' => true, 'showTechnical' => false])
                </div>
            </div>
            <div style="text-align:right">
                <div>${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</div>
                <a href="{{ route('buyer.orders.show', $order) }}">Detalle</a>
            </div>
        </div>
    @empty
        <p class="buyer-muted">Aún no hay pedidos ligados a este email.</p>
    @endforelse
</div>
@endsection
