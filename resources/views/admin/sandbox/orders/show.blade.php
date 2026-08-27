@extends('layouts.admin')

@section('title', $order->number.' — Sandbox CJ')
@section('heading', $order->number)
@section('subheading', ($order->theme?->name ?? 'Plantilla').' · '.$order->email)

@section('content')
<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.sandbox.orders.index') }}" class="admin-btn-secondary">← Sandbox CJ</a>
    <form method="post" action="{{ route('admin.sandbox.orders.refresh', $order) }}">
        @csrf
        <button class="admin-btn-secondary">Consultar tracking CJ</button>
    </form>
    <form method="post" action="{{ route('admin.sandbox.orders.resubmit', $order) }}" onsubmit="return confirm('¿Reenviar createOrder a CJ?')">
        @csrf
        <button class="admin-btn">Reenviar a CJ</button>
    </form>
    @if($order->theme)
        <a class="admin-btn-secondary" target="_blank" href="{{ route('theme.sandbox.confirm', $order->theme->slug) }}?number={{ urlencode($order->number) }}&email={{ urlencode($order->email) }}">Confirmación cliente</a>
        <a class="admin-btn-secondary" target="_blank" href="{{ route('theme.sandbox.track', $order->theme->slug) }}?number={{ urlencode($order->number) }}&email={{ urlencode($order->email) }}">Seguimiento</a>
    @endif
</div>

<div class="admin-blocks mb-5">
    <div class="admin-card p-4 sm:p-5 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink">Pedido sandbox</h2>
        <p>Pago: <strong>{{ $order->payment_status }}</strong> · Fulfillment: <strong>{{ $order->fulfillment_status }}</strong></p>
        <p>Total ${{ number_format((float) $order->total, 2) }} {{ $order->currency }}
            @if($order->coupon) · cupón {{ $order->coupon }}@endif
        </p>
        <p>{{ $order->name }} · {{ $order->phone }}</p>
        @php $addr = $order->address ?? []; @endphp
        <p class="text-sm text-ink-soft/70">{{ $addr['address'] ?? '' }} · {{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }} · {{ $addr['country'] ?? '' }}</p>
        @if($order->cj_order_id)
            <p>ID CJ: <code>{{ $order->cj_order_id }}</code></p>
        @endif
        @if($order->tracking_number)
            <p>Guía: <strong>{{ $order->tracking_number }}</strong> {{ $order->carrier }}</p>
        @endif
        @if($order->cj_error)
            <p class="text-sm text-rose-700">{{ $order->cj_error }}</p>
        @endif
    </div>
    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Artículos</h2>
        </div>
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-line bg-mist/40 text-left text-xs uppercase text-ink-soft/50">
                <th class="px-4 py-2.5">Producto</th>
                <th class="px-4 py-2.5">Qty</th>
                <th class="px-4 py-2.5">VID</th>
            </tr>
            </thead>
            <tbody>
            @foreach(($order->items ?? []) as $item)
                <tr class="border-b border-line/70">
                    <td class="px-4 py-3">{{ $item['name'] ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $item['qty'] ?? 1 }}</td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $item['vid'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($showRaw)
<div class="admin-blocks">
    <div class="admin-card p-4 sm:p-5 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink">Payload enviado a CJ</h2>
        <pre class="overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode($order->cj_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div class="admin-card p-4 sm:p-5 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink">Respuesta createOrder</h2>
        <pre class="overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode($order->cj_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div class="admin-card p-4 sm:p-5 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink">getOrderDetail</h2>
        <pre class="overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode($order->cj_order_detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
    <div class="admin-card p-4 sm:p-5 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink">trackInfo (logística)</h2>
        <pre class="overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode($order->cj_tracking, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
@else
    <p class="text-sm text-ink-soft/65">El dump crudo de CJ solo se muestra en local (`SANDBOX_CJ_DEBUG=true`).</p>
@endif
@endsection
