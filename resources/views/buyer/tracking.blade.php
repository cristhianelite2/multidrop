@extends('layouts.buyer')

@section('title', 'Seguimiento')
@section('heading', 'Seguimiento')
@section('subheading', 'Guías y estado de envío de tus pedidos')

@section('content')
@forelse($orders as $order)
    <div class="buyer-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px">
            <div>
                <strong>{{ $order->number }}</strong>
                <span class="buyer-muted"> · {{ $order->store?->name }}</span>
                <div class="buyer-muted">{{ $order->paymentStatusLabel() }} · {{ $order->fulfillmentStatusLabel() }}</div>
            </div>
            <a href="{{ route('buyer.orders.show', $order) }}">Detalle</a>
        </div>
        <div style="margin-top:10px">
            @include('buyer.partials.order-timeline', ['order' => $order, 'compact' => true, 'showTechnical' => true])
        </div>
        @forelse($order->fulfillments as $f)
            <p style="margin:10px 0 0">
                @if($f->tracking_number)
                    Guía <strong>{{ $f->tracking_number }}</strong> {{ $f->carrier ? '('.$f->carrier.')' : '' }} · {{ $f->status }}
                @else
                    {{ $f->status }} · sin guía aún
                @endif
            </p>
        @empty
            <p class="buyer-muted" style="margin:10px 0 0">Sin fulfillment todavía.</p>
        @endforelse
    </div>
@empty
    <div class="buyer-card"><p class="buyer-muted" style="margin:0">No hay pedidos para seguir.</p></div>
@endforelse
<div>{{ $orders->links() }}</div>
@endsection
