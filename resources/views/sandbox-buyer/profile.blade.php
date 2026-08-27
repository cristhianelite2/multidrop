@extends('layouts.buyer')

@section('title', __('buyer.profile.title'))
@section('heading', __('buyer.profile.heading'))
@section('subheading', __('buyer.profile.sub'))

@section('content')
<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">{{ __('buyer.profile.data') }}</h2>
    <form method="post" action="{{ route('theme.sandbox.cuenta.profile.update', $theme->slug) }}" style="display:grid;gap:12px;max-width:420px">
        @csrf
        @method('PUT')
        <div>
            <label class="buyer-muted">{{ __('buyer.profile.email') }}</label>
            <input class="buyer-input" value="{{ $buyer->email }}" disabled>
        </div>
        <div>
            <label class="buyer-muted">{{ __('buyer.profile.name') }}</label>
            <input class="buyer-input" name="name" value="{{ old('name', $buyer->name) }}">
        </div>
        <div>
            <label class="buyer-muted">{{ __('buyer.profile.phone') }}</label>
            <input class="buyer-input" name="phone" value="{{ old('phone', $buyer->phone) }}">
        </div>
        <button class="buyer-btn" type="submit">{{ __('buyer.profile.save') }}</button>
    </form>
</div>

<div class="buyer-card" style="max-width:440px">
    <h2 style="margin:0 0 8px;font-size:1.05rem">{{ __('buyer.profile.password') }}</h2>
    @if($buyer->hasPassword())
        <p class="buyer-muted" style="margin-top:0">{{ __('buyer.profile.password_has') }}</p>
    @else
        <p class="buyer-muted" style="margin-top:0">{{ __('buyer.profile.password_new') }}</p>
    @endif
    <form method="post" action="{{ route('theme.sandbox.cuenta.security.password', $theme->slug) }}" style="display:grid;gap:12px">
        @csrf
        @method('PUT')
        @if($buyer->hasPassword())
            <div>
                <label class="buyer-muted">{{ __('buyer.profile.current_password') }}</label>
                <input class="buyer-input" type="password" name="current_password" required autocomplete="current-password">
            </div>
        @endif
        <div>
            <label class="buyer-muted">{{ $buyer->hasPassword() ? __('buyer.profile.new_password') : __('buyer.profile.password_label') }}</label>
            <input class="buyer-input" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label class="buyer-muted">{{ __('buyer.profile.confirm') }}</label>
            <input class="buyer-input" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </div>
        <button class="buyer-btn" type="submit">{{ $buyer->hasPassword() ? __('buyer.profile.update_password') : __('buyer.profile.assign') }}</button>
    </form>
</div>

@php $addr = is_array($recent?->address) ? $recent->address : []; @endphp
@if($recent)
<div class="buyer-card">
    <h2 style="margin:0 0 8px;font-size:1.05rem">{{ __('buyer.profile.address') }}</h2>
    <p class="buyer-muted" style="margin:0">
        {{ $addr['address'] ?? '—' }}<br>
        {{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}<br>
        {{ $addr['country'] ?? '' }}
    </p>
</div>
@endif
@endsection
