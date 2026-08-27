@extends('layouts.buyer')

@section('title', $order->number)
@section('heading', $order->number)
@section('subheading', ($order->store?->name ?? 'Tienda').' · '.$order->created_at?->format('d/m/Y'))

@section('content')
<div style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
    <div class="buyer-card">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Resumen</h2>
        <p>Pago: <strong>{{ $order->paymentStatusLabel() }}</strong></p>
        <p>Plataforma de pago: <strong>{{ $order->paymentProviderLabel() }}</strong></p>
        <p>Envío: <strong>{{ $order->fulfillmentStatusLabel() }}</strong></p>
        <p>Total: <strong>${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</strong></p>
        <p>Costo de envío: <strong>${{ number_format((float)$order->shipping, 2) }} {{ $order->currency }}</strong></p>
        <p>Tiempo aproximado de entrega: <strong>{{ $etaLabel ?? '8–18 días hábiles aprox.' }}</strong></p>
        @if($order->store?->slug)
            <a class="buyer-btn-secondary" style="margin-top:8px" href="{{ route('store.order.track', $order->store->slug) }}?token={{ $order->access_token }}">Vista pública</a>
        @endif
    </div>
    <div class="buyer-card">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Envío</h2>
        @php $addr = $order->shipping_address ?? []; @endphp
        <p>{{ $addr['name'] ?? $order->customer_name }}</p>
        <p class="buyer-muted">{{ $addr['address'] ?? '' }}</p>
        <p class="buyer-muted">{{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}</p>
        <p class="buyer-muted">{{ $addr['country'] ?? '' }}</p>
    </div>
</div>

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Artículos</h2>
    @foreach($order->items as $item)
        @php
            $img = $item->imageUrl();
            $productSlug = $item->product?->slug ?? null;
            $storeSlug = $order->store?->slug ?? null;
            $productUrl = ($productSlug && $storeSlug)
                ? route('store.design.page', ['slug' => $storeSlug, 'handle' => $productSlug])
                : null;
        @endphp
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #e2e8f0">
            {{-- Imagen del producto --}}
            @if($img)
                @if($productUrl)
                    <a href="{{ $productUrl }}" target="_blank" style="flex-shrink:0;display:block;width:56px;height:56px;border-radius:8px;overflow:hidden;background:#f1f5f9">
                        <img src="{{ $img }}" alt="{{ $item->name }}"
                             style="width:100%;height:100%;object-fit:cover;display:block">
                    </a>
                @else
                    <div style="flex-shrink:0;width:56px;height:56px;border-radius:8px;overflow:hidden;background:#f1f5f9">
                        <img src="{{ $img }}" alt="{{ $item->name }}"
                             style="width:100%;height:100%;object-fit:cover;display:block">
                    </div>
                @endif
            @else
                <div style="flex-shrink:0;width:56px;height:56px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
                    <span style="font-size:22px;opacity:.35">📦</span>
                </div>
            @endif

            {{-- Nombre y precio --}}
            <div style="flex:1;min-width:0">
                @if($productUrl)
                    <a href="{{ $productUrl }}" target="_blank"
                       style="font-size:.92rem;font-weight:600;color:#0f172a;text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                       title="{{ $item->name }}">{{ $item->name }}</a>
                @else
                    <span style="font-size:.92rem;font-weight:600;color:#0f172a;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $item->name }}</span>
                @endif
                <span class="buyer-muted" style="font-size:.8rem">× {{ $item->qty }}</span>
            </div>

            {{-- Total --}}
            <div style="flex-shrink:0;text-align:right">
                <strong style="font-size:.92rem">${{ number_format((float)$item->total, 2) }}</strong>
                <div class="buyer-muted" style="font-size:.75rem">${{ number_format((float)$item->unit_price, 2) }} c/u</div>
            </div>
        </div>
    @endforeach
</div>

@php
    $paypalTx = $order->payments->first(function ($p) {
        return strtolower((string) $p->provider) === 'paypal' && is_array($p->raw) && !empty($p->raw);
    });
@endphp

@if($order->payment_provider === 'paypal')
<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Transacción PayPal</h2>
    @if($paypalTx)
        <p class="buyer-muted" style="margin-top:0">
            Referencia: <strong>{{ $paypalTx->provider_ref ?: ($order->payment_ref ?: '—') }}</strong> ·
            Estado: <strong>{{ $paypalTx->status === 'paid' ? 'Pagado' : ($paypalTx->status ?: $order->paymentStatusLabel()) }}</strong>
        </p>
        <details style="margin-top:8px">
            <summary style="cursor:pointer;font-weight:600">Ver detalle completo enviado por PayPal (JSON)</summary>
            <pre style="margin-top:10px;overflow:auto;max-height:320px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;font-size:12px;line-height:1.45">{{ json_encode($paypalTx->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <p class="buyer-muted" style="margin:0 0 8px">Aún no hay payload de PayPal guardado para este pedido.</p>
        @if(!empty(($order->meta ?? [])['paypal_capture_error']))
            <p class="buyer-muted" style="margin:0">Último error de captura: <strong>{{ ($order->meta ?? [])['paypal_capture_error'] }}</strong></p>
        @endif
    @endif
</div>
@endif

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Seguimiento</h2>
    @include('buyer.partials.order-timeline', ['order' => $order, 'compact' => false, 'showTechnical' => true])
    <hr style="border:0;border-top:1px solid #e2e8f0;margin:14px 0">
    @forelse($order->fulfillments as $f)
        <p>
            {{ $f->status }} · CJ {{ $f->external_order_id ?: '—' }}
            @if($f->tracking_number)
                <br>Guía: <strong>{{ $f->tracking_number }}</strong> {{ $f->carrier }}
            @endif
        </p>
    @empty
        <p class="buyer-muted">Aún no hay guía de envío.</p>
    @endforelse
</div>

@if($order->claims->isNotEmpty())
<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Reclamos de este pedido</h2>
    @foreach($order->claims as $claim)
        <p><a href="{{ route('buyer.claims.show', $claim) }}">{{ $claim->subject }}</a> · {{ $claim->statusLabel() }}</p>
    @endforeach
</div>
@endif

<div style="margin-top:8px">
    <a class="buyer-btn-secondary" href="{{ route('buyer.claims.index') }}?order={{ $order->id }}">Abrir reclamo</a>
</div>
@endsection
