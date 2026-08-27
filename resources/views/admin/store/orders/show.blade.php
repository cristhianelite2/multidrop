@extends('layouts.admin')

@section('title', $order->number.' — Pedidos')
@section('heading', $order->number)
@section('subheading', $order->customer_name.' · '.$order->customer_email)

@section('content')
    <div class="mb-5 flex flex-wrap gap-2">
        <a href="{{ route('admin.store.orders.index') }}" class="admin-btn-secondary">← Pedidos</a>
        @unless($order->isPaid())
            <form method="post" action="{{ route('admin.store.orders.mark-paid', $order) }}" onsubmit="return confirm('¿Marcar como pagado?')">
                @csrf
                <button class="admin-btn-secondary">Marcar pagado</button>
            </form>
        @endunless
        @if($order->isPaid())
            <form method="post" action="{{ route('admin.store.orders.fulfill', $order) }}">
                @csrf
                <button class="admin-btn">Reenviar a CJ</button>
            </form>
        @endif
        <a class="admin-btn-secondary" target="_blank" href="{{ route('store.order.track', $store->slug) }}?number={{ $order->number }}&email={{ urlencode($order->customer_email) }}">Vista cliente</a>
        <a class="admin-btn-secondary" href="{{ route('admin.store.claims.index') }}">Reclamos</a>
    </div>

    <div class="admin-blocks">
        <div class="admin-card p-5">
            <h2 class="font-display text-lg font-bold mb-3">Línea de tiempo</h2>
            @include('buyer.partials.order-timeline', ['order' => $order, 'compact' => false])
        </div>
        <div class="admin-card p-5 space-y-2">
            <h2 class="font-display text-lg font-bold">Resumen</h2>
            <p>Pago: <strong>{{ $order->payment_status }}</strong> · {{ $order->payment_provider ?: '—' }}</p>
            <p>Envío: <strong>{{ $order->fulfillment_status }}</strong></p>
            <p>Subtotal ${{ number_format((float)$order->subtotal,2) }} · Desc. ${{ number_format((float)$order->discount,2) }} · Total <strong>${{ number_format((float)$order->total,2) }} {{ $order->currency }}</strong></p>
            @if($order->coupon_code)<p>Cupón: {{ $order->coupon_code }}</p>@endif
            @if(!empty($buyerAccount))
                <p class="pt-2 text-sm">Cuenta comprador: <strong>{{ $buyerAccount->email }}</strong>
                    {{ $buyerAccount->name ? '· '.$buyerAccount->name : '' }}
                    · <a class="text-teal hover:underline" href="{{ route('admin.store.claims.index') }}">ver reclamos</a>
                </p>
            @endif
        </div>
        <div class="admin-card p-5 space-y-2">
            <h2 class="font-display text-lg font-bold">Envío</h2>
            @php $addr = $order->shipping_address ?? []; @endphp
            <p>{{ $addr['name'] ?? $order->customer_name }}</p>
            <p>{{ $addr['address'] ?? '' }}</p>
            <p>{{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}</p>
            <p>{{ $addr['country'] ?? '' }} · {{ $addr['phone'] ?? $order->customer_phone }}</p>
        </div>
        <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Artículos</h2>
        </div>
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase text-ink-soft/50">
                <th class="px-4 py-3">Producto</th>
                <th class="px-4 py-3">Qty</th>
                <th class="px-4 py-3">Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->items as $item)
                <tr class="border-b border-line/70">
                    <td class="px-4 py-3">{{ $item->name }}</td>
                    <td class="px-4 py-3">{{ $item->qty }}</td>
                    <td class="px-4 py-3">${{ number_format((float)$item->total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>

        <div class="admin-card p-5 space-y-2">
        <h2 class="font-display text-lg font-bold">Fulfillment CJ</h2>
        @forelse($order->fulfillments as $f)
            <p>ID: {{ $f->external_order_id ?: '—' }} · {{ $f->status }}</p>
            @if($f->tracking_number)
                <p>Guía: <strong>{{ $f->tracking_number }}</strong> {{ $f->carrier }}</p>
            @endif
            @if((config('multidrop.sandbox_cj_debug') || app()->environment('local')) && is_array($f->raw))
                <pre class="mt-2 overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode($f->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        @empty
            <p class="text-sm text-ink-soft/60">Sin fulfillment todavía.</p>
        @endforelse
        @if((config('multidrop.sandbox_cj_debug') || app()->environment('local')) && data_get($order->meta, 'cj_create'))
            <h3 class="font-display text-sm font-bold pt-2">meta.cj_create</h3>
            <pre class="overflow-auto rounded-xl bg-mist/60 p-3 text-[11px] leading-relaxed">{{ json_encode(data_get($order->meta, 'cj_create'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
        </div>

        <div class="admin-card p-5 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-display text-lg font-bold">Reclamos</h2>
                <a href="{{ route('admin.store.claims.index') }}" class="text-sm text-teal hover:underline">Ver todos</a>
            </div>
            @forelse($order->claims as $claim)
                <div class="rounded-xl border border-line bg-mist/30 p-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2">
                        <strong>{{ $claim->subject }}</strong>
                        <span>{{ $claim->statusLabel() }}</span>
                    </div>
                    <p class="mt-1 text-ink-soft/70 line-clamp-2">{{ $claim->body }}</p>
                    <a href="{{ route('admin.store.claims.show', $claim) }}" class="text-teal hover:underline">Responder</a>
                </div>
            @empty
                <p class="text-sm text-ink-soft/60">Sin reclamos en este pedido.</p>
            @endforelse
        </div>
    </div>
@endsection
