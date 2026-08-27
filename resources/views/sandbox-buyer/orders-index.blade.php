@extends('layouts.buyer')

@section('title', 'Compras')
@section('heading', 'Compras')
@section('subheading', 'Pedidos sandbox de esta plantilla')

@section('content')
<div class="buyer-card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead>
        <tr style="background:#f8fafc;text-align:left">
            <th style="padding:12px 16px">Pedido</th>
            <th style="padding:12px 16px">Plantilla</th>
            <th style="padding:12px 16px">Total</th>
            <th style="padding:12px 16px">Estado</th>
            <th style="padding:12px 16px"></th>
        </tr>
        </thead>
        <tbody>
        @forelse($orders as $order)
            <tr style="border-top:1px solid #e2e8f0">
                <td style="padding:12px 16px">
                    <strong>{{ $order->number }}</strong>
                    <div class="buyer-muted">{{ $order->created_at?->format('d/m/Y H:i') }}</div>
                </td>
                <td style="padding:12px 16px">{{ $theme->name }}</td>
                <td style="padding:12px 16px">${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</td>
                <td style="padding:12px 16px">{{ $order->payment_status }} / {{ $order->fulfillment_status }}</td>
                <td style="padding:12px 16px"><a href="{{ route('theme.sandbox.cuenta.orders.show', [$theme->slug, $order->id]) }}">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="5" style="padding:20px" class="buyer-muted">Sin compras todavía.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
