@extends('layouts.buyer')

@section('title', __('buyer.tracking.title'))
@section('heading', __('buyer.tracking.heading'))
@section('subheading', __('buyer.tracking.sub'))

@section('content')
@include('sandbox-buyer.partials.status-legend')

@forelse($orders as $order)
    @php
        $steps = $pipelines[$order->id] ?? [];
        $prefs = $notifyPrefs[$order->id] ?? [];
    @endphp
    <div class="buyer-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:14px">
            <div>
                <strong>{{ $order->number }}</strong>
                <span class="buyer-muted"> · {{ $theme->name }}</span>
                <div class="buyer-muted">
                    {{ __('buyer.tracking.payment') }} {{ $order->payment_status }}
                    · {{ __('buyer.tracking.shipping') }} {{ $order->fulfillment_status }}
                </div>
            </div>
            <a href="{{ route('theme.sandbox.cuenta.orders.show', [$theme->slug, $order->id]) }}">{{ __('buyer.tracking.detail') }}</a>
        </div>

        @include('sandbox-buyer.partials.status-pipeline', ['steps' => $steps, 'compact' => false])

        <p style="margin:14px 0 0" class="buyer-muted">
            @if($order->tracking_number)
                {{ __('buyer.tracking.guide') }}:
                <strong style="color:#0f172a">{{ $order->tracking_number }}</strong>
                {{ $order->carrier ? '('.$order->carrier.')' : '' }}
            @else
                {{ __('buyer.tracking.no_guide') }}
            @endif
        </p>

        <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e2e8f0">
            <h3 style="margin:0 0 4px;font-size:1rem">{{ __('buyer.tracking.notify_title') }}</h3>
            <p class="buyer-muted" style="margin:0 0 10px">{{ __('buyer.tracking.notify_help', ['email' => $buyer->email]) }}</p>
            <form method="post" action="{{ route('theme.sandbox.cuenta.tracking.notify', $theme->slug) }}">
                @csrf
                <input type="hidden" name="order_id" value="{{ $order->id }}">
                <div class="md-notify">
                    @foreach($steps as $step)
                        <div class="md-notify__row">
                            <input
                                type="checkbox"
                                id="notify-{{ $order->id }}-{{ $step['key'] }}"
                                name="statuses[]"
                                value="{{ $step['key'] }}"
                                @checked(!empty($prefs[$step['key']]))
                            >
                            <label for="notify-{{ $order->id }}-{{ $step['key'] }}">
                                <strong>{{ $step['label'] }}</strong>
                                <span class="buyer-muted"> — {{ $step['hint'] }}</span>
                            </label>
                        </div>
                    @endforeach
                </div>
                <button class="buyer-btn" type="submit" style="margin-top:12px">{{ __('buyer.tracking.notify_save') }}</button>
            </form>
        </div>
    </div>
@empty
    <div class="buyer-card"><p class="buyer-muted" style="margin:0">{{ __('buyer.tracking.empty') }}</p></div>
@endforelse
@endsection
