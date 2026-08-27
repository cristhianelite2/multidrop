@extends('layouts.buyer')

@section('title', __('buyer.home.title'))
@section('heading', __('buyer.home.hello', ['name' => $buyer->name ? ', '.$buyer->name : '']))
@section('subheading', __('buyer.home.sub', ['email' => $buyer->email]))

@section('content')
<div class="buyer-card">
    <p class="buyer-muted" style="margin-top:0">{{ __('buyer.home.intro') }}</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px">
        <a class="buyer-btn" href="{{ route('theme.sandbox.cuenta.orders', $theme->slug) }}">{{ __('buyer.home.view_orders') }}</a>
        <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.tracking', $theme->slug) }}">{{ __('buyer.home.tracking') }}</a>
        <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.claims', $theme->slug) }}">{{ __('buyer.home.claims_open', ['count' => $claimsOpen]) }}</a>
        @unless($buyer->hasPassword())
            <a class="buyer-btn-secondary" href="{{ route('theme.sandbox.cuenta.security', $theme->slug) }}">{{ __('buyer.home.create_password') }}</a>
        @endunless
    </div>
</div>

<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.1rem">{{ __('buyer.home.recent') }}</h2>
    @include('sandbox-buyer.partials.status-legend')
    @forelse($orders as $order)
        @php $steps = $pipelines[$order->id] ?? []; @endphp
        <div style="padding:14px 0;border-bottom:1px solid #e2e8f0">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:10px">
                <div>
                    <strong>{{ $order->number }}</strong>
                    <span class="buyer-muted"> · {{ $theme->name }}</span>
                    <div class="buyer-muted">{{ $order->created_at?->format('d/m/Y') }} · ${{ number_format((float)$order->total, 2) }} {{ $order->currency }}</div>
                </div>
                <a href="{{ route('theme.sandbox.cuenta.orders.show', [$theme->slug, $order->id]) }}">{{ __('buyer.home.detail') }}</a>
            </div>
            @include('sandbox-buyer.partials.status-pipeline', ['steps' => $steps, 'compact' => true])
            {{-- etiqueta de estado actual ya no hace falta: el título va sobre cada círculo --}}
        </div>
    @empty
        <p class="buyer-muted">{{ __('buyer.home.empty') }}</p>
    @endforelse
</div>
@endsection
