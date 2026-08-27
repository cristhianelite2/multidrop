@extends('layouts.admin')

@section('title', 'Pedidos — '.$store->name)
@section('heading', 'Pedidos')
@section('subheading', $store->name.' · '.number_format($orders->total()).' pedidos')

@section('content')
<style>
/* Pipeline ultra-compacto para la lista de pedidos admin */
.ord-pipe { display:flex; gap:0; width:100%; align-items:flex-start; padding:4px 0; }
.ord-pipe__step { position:relative; flex:1 1 0; min-width:58px; display:flex; flex-direction:column; align-items:center; text-align:center; padding:0 2px; }
.ord-pipe__title { font-size:9.5px; font-weight:600; color:#64748b; line-height:1.2; margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
.ord-pipe__step.is-done .ord-pipe__title { color:#0f766e; }
.ord-pipe__step.is-current .ord-pipe__title { color:#0284c7; }
.ord-pipe__step.is-error .ord-pipe__title { color:#b91c1c; }
.ord-pipe__icon { width:22px; height:22px; border-radius:999px; border:1.5px solid #e2e8f0; background:#f8fafc; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; }
.ord-pipe__step.is-done .ord-pipe__icon { background:#0f766e; color:#fff; border-color:#0f766e; }
.ord-pipe__step.is-current .ord-pipe__icon { background:#0ea5e9; color:#fff; border-color:#0284c7; box-shadow:0 0 0 3px rgba(14,165,233,.2); }
.ord-pipe__step.is-warn .ord-pipe__icon { background:#f59e0b; color:#fff; border-color:#d97706; }
.ord-pipe__step.is-error .ord-pipe__icon { background:#dc2626; color:#fff; border-color:#b91c1c; }
.ord-pipe__line { position:absolute; top:calc(4px + 11px); left:calc(50% + 12px); right:calc(-50% + 12px); height:1.5px; background:#e2e8f0; }
.ord-pipe__step.is-done .ord-pipe__line { background:#0f766e; }
</style>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
    <span class="text-xs text-ink-soft/60">{{ $orders->total() }} total · página {{ $orders->currentPage() }}</span>
</div>

<div class="grid gap-2">
    @forelse($orders as $order)
        @php
            $payment   = strtolower((string) ($order->payment_status ?? 'pending'));
            $fulfill   = strtolower((string) ($order->fulfillment_status ?? 'unfulfilled'));
            $orderSt   = strtolower((string) ($order->status ?? 'pending'));
            $isCancelled = in_array($orderSt, ['cancelled','canceled'], true) || in_array($payment, ['failed','rejected','cancelled','canceled'], true) || $fulfill === 'error';
            $isPaid    = in_array($payment, ['paid','approved','completed'], true);
            $isDelivered = in_array($fulfill, ['delivered','completed'], true);
            $isShipped = in_array($fulfill, ['shipped','in_transit'], true) || $isDelivered;
            $isPrep    = in_array($fulfill, ['submitted','processing','manual','skipped','unfulfilled'], true) || $isShipped;

            $pClass = match(true) {
                $isCancelled => 'bg-slate-100 text-slate-600',
                $isPaid      => 'bg-emerald-100 text-emerald-800',
                default      => 'bg-amber-100 text-amber-800',
            };

            $steps = [
                ['label'=>'Compra',   'state'=> 'done',
                 'icon'=> '✓'],
                ['label'=>'Pago',
                 'state'=> $isCancelled ? 'error' : ($isPaid ? 'done' : 'current'),
                 'icon'=> $isPaid ? '✓' : ($isCancelled ? '✕' : '…')],
                ['label'=>'Prep.',
                 'state'=> $isCancelled ? 'error' : ($isPrep ? ($isShipped ? 'done' : 'current') : 'todo'),
                 'icon'=> ($isPrep && !$isShipped) ? '…' : ($isShipped ? '✓' : '○')],
                ['label'=>'Enviado',
                 'state'=> $isCancelled ? 'error' : ($isShipped ? ($isDelivered ? 'done' : 'current') : 'todo'),
                 'icon'=> ($isShipped && !$isDelivered) ? '…' : ($isDelivered ? '✓' : '○')],
                ['label'=>'Entregado',
                 'state'=> $isCancelled ? 'error' : ($isDelivered ? 'done' : 'todo'),
                 'icon'=> $isDelivered ? '✓' : '○'],
            ];
        @endphp

        <article class="admin-card overflow-hidden">
            {{-- Cabecera compacta --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line/60 px-3 py-2">
                <span class="font-semibold text-xs text-ink">{{ $order->number }}</span>
                @if($order->admin_seen_at === null && $isPaid)
                    <span class="admin-badge bg-emerald-100 text-emerald-800 !text-[9px] !py-0">Nueva</span>
                @endif
                <span class="admin-badge {{ $pClass }}">{{ $order->payment_status }}</span>
                <span class="text-xs text-ink-soft/60 hidden sm:inline truncate max-w-[150px]">{{ $order->customer_email }}</span>
                <span class="ml-auto text-xs font-semibold text-ink">${{ number_format((float)$order->total, 2) }} <span class="font-normal text-ink-soft/55">{{ $order->currency }}</span></span>
                <span class="text-[10px] text-ink-soft/50 hidden md:inline">{{ $order->paymentProviderLabel() }}</span>
                <span class="text-[10px] text-ink-soft/50 hidden lg:inline">{{ $order->created_at?->format('d/m/y H:i') }}</span>
                <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" href="{{ route('admin.store.orders.show', $order) }}">Ver</a>
            </div>

            {{-- Timeline siempre visible, ultra-compacto --}}
            <div class="px-3 py-2 bg-mist/20">
                <div class="ord-pipe">
                    @foreach($steps as $i => $step)
                        @php $last = ($i === count($steps) - 1); @endphp
                        <div class="ord-pipe__step is-{{ $step['state'] }}">
                            <div class="ord-pipe__title">{{ $step['label'] }}</div>
                            <div class="ord-pipe__icon">{{ $step['icon'] }}</div>
                            @if(!$last)<div class="ord-pipe__line is-{{ $step['state'] }}"></div>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        </article>
    @empty
        <div class="admin-card px-4 py-10 text-center text-sm text-ink-soft/60">Sin pedidos todavía.</div>
    @endforelse
</div>

<div class="mt-4">{{ $orders->links() }}</div>
@endsection
