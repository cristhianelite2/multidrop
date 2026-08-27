@extends('layouts.buyer')

@section('title', 'Compras')
@section('heading', 'Compras')
@section('subheading', 'Pedidos de todas las tiendas con tu email')

@section('content')
@forelse($orders as $order)
    <div class="buyer-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px">
            <div>
                <strong>{{ $order->number }}</strong>
                @if($order->store?->slug)
                    <a href="{{ route('store.design.show', $order->store->slug) }}" class="buyer-muted" style="text-decoration:none" target="_blank"> · {{ $order->store->name }}</a>
                @else
                    <span class="buyer-muted"> · {{ $order->store?->name ?? '—' }}</span>
                @endif
                <div class="buyer-muted">{{ $order->created_at?->format('d/m/Y H:i') }} · ${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</div>
                <div class="buyer-muted">Plataforma de pago: <strong>{{ $order->paymentProviderLabel() }}</strong></div>
            </div>
            <a href="{{ route('buyer.orders.show', $order) }}">Ver</a>
        </div>
        <div style="margin-top:10px">
            @include('buyer.partials.order-timeline', ['order' => $order, 'compact' => true, 'showTechnical' => true])
        </div>
    </div>
@empty
    <div class="buyer-card"><p class="buyer-muted" style="margin:0">Sin compras todavía.</p></div>
@endforelse
<div style="margin-top:12px">{{ $orders->links() }}</div>
@endsection
