@extends('layouts.buyer')

@section('title', $order->number)
@section('heading', $order->number)
@section('subheading', $theme->name.' · '.$order->created_at?->format('d/m/Y'))

@section('content')
@php
    $items = $order->items ?? [];
    $addr = is_array($order->address) ? $order->address : [];
@endphp
<div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
    <div class="buyer-card">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Resumen</h2>
        <p>Pago: <strong>{{ $order->payment_status }}</strong></p>
        <p>Envío / CJ: <strong>{{ $order->fulfillment_status }}</strong></p>
        <p>Total: <strong>${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</strong></p>
        @if($order->cj_order_id)
            <p class="buyer-muted">ID CJ: {{ $order->cj_order_id }}</p>
        @endif
    </div>
    <div class="buyer-card">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Envío</h2>
        <p>{{ $addr['name'] ?? $order->name }}</p>
        <p class="buyer-muted">{{ $addr['address'] ?? '' }}</p>
        <p class="buyer-muted">{{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}</p>
        <p class="buyer-muted">{{ $addr['country'] ?? '' }}</p>
    </div>
</div>

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Artículos</h2>
    @forelse($items as $item)
        @php
            $qty = max(1, (int)($item['qty'] ?? 1));
            $unit = (float)($item['price'] ?? 0);
            $line = $unit * $qty;
        @endphp
        <div class="buyer-item">
            @if(!empty($item['image']))
                <img src="{{ $item['image'] }}" alt="" loading="lazy">
            @else
                <div style="width:56px;height:56px;border-radius:8px;background:#f1f5f9"></div>
            @endif
            <div>
                <strong>{{ $item['name'] ?? 'Producto' }}</strong>
                <div class="buyer-muted">Cantidad {{ $qty }} · ${{ number_format($unit, 2) }} c/u</div>
            </div>
            <strong>${{ number_format($line, 2) }}</strong>
        </div>
    @empty
        <p class="buyer-muted">Sin artículos.</p>
    @endforelse
</div>

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">{{ __('buyer.tracking.heading') }}</h2>
    @include('sandbox-buyer.partials.status-pipeline', ['steps' => $steps ?? [], 'compact' => false])
    @if($order->tracking_number)
        <p style="margin-top:14px">{{ __('buyer.tracking.guide') }}: <strong>{{ $order->tracking_number }}</strong> {{ $order->carrier ? '('.$order->carrier.')' : '' }}</p>
    @else
        <p class="buyer-muted" style="margin-top:14px">{{ __('buyer.tracking.no_guide') }}</p>
    @endif
</div>

<div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.claims', $theme->slug) }}?order={{ $order->id }}">{{ __('buyer.claims.new') }}</a>
    <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.tracking', $theme->slug) }}">{{ __('buyer.nav.tracking') }}</a>
</div>
@endsection
