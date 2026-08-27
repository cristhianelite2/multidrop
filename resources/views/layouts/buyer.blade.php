<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('buyer.brand')) — {{ !empty($sandbox) ? ($theme->name.' · '.__('buyer.sandbox')) : 'Multidrop' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body.buyer-shell { font-family: Outfit, system-ui, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; }
        .buyer-layout { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        .buyer-side { background: #0f172a; color: #e2e8f0; padding: 20px 14px; }
        .buyer-side a { color: #cbd5e1; text-decoration: none; display: block; padding: 10px 12px; border-radius: 10px; font-size: 14px; }
        .buyer-side a:hover, .buyer-side a.is-active { background: rgba(15,118,110,.35); color: #fff; }
        .buyer-side a.buyer-side__store { margin: 4px 0 10px; border: 1px solid #334155; color: #e2e8f0; font-weight: 600; }
        .buyer-side a.buyer-side__store:hover { border-color: #0f766e; color: #fff; }
        .buyer-brand { font-weight: 700; font-size: 18px; color: #fff; margin-bottom: 6px; padding: 0 8px; }
        .buyer-brand-sub { font-size: 11px; color: #94a3b8; padding: 0 8px 14px; }
        .buyer-main { padding: 24px 20px 48px; }
        .buyer-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; margin-bottom: 16px; }
        .buyer-btn { background: #0f766e; color: #fff; border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .buyer-btn-secondary { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .buyer-input { width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 12px; font: inherit; }
        .buyer-muted { color: #64748b; font-size: 14px; }
        .buyer-alert { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
        .buyer-alert-ok { background: #ecfdf5; color: #065f46; }
        .buyer-alert-err { background: #fef2f2; color: #991b1b; }
        .buyer-item { display:grid; grid-template-columns:56px 1fr auto; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid #e2e8f0; }
        .buyer-item img { width:56px; height:56px; object-fit:cover; border-radius:8px; background:#f1f5f9; }

        .md-pipe { display:flex; align-items:flex-start; gap:0; width:100%; overflow-x:auto; padding:4px 0 8px; }
        .md-pipe__step { position:relative; flex:1 1 0; min-width:88px; display:flex; flex-direction:column; align-items:center; text-align:center; padding:0 4px; }
        .md-pipe__title { font-size:11px; font-weight:600; color:#0f172a; line-height:1.25; min-height:2.5em; display:flex; align-items:flex-end; justify-content:center; margin-bottom:8px; }
        .md-pipe__step.is-todo .md-pipe__title { color:#94a3b8; }
        .md-pipe__status { font-size:10px; font-weight:700; line-height:1; margin-bottom:8px; color:#94a3b8; text-transform:uppercase; letter-spacing:.03em; }
        .md-pipe__step.is-done .md-pipe__status { color:#0f766e; }
        .md-pipe__step.is-current .md-pipe__status,
        .md-pipe__step.is-warn .md-pipe__status { color:#0284c7; }
        .md-pipe__step.is-error .md-pipe__status { color:#b91c1c; }
        .md-pipe__icon {
            width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            background:#e2e8f0; color:#94a3b8; border:2px solid #cbd5e1; z-index:1; transition:background .2s,color .2s,border-color .2s,box-shadow .2s;
        }
        .md-pipe__step.is-done .md-pipe__icon { background:#0f766e; color:#fff; border-color:#0f766e; }
        .md-pipe__step.is-current .md-pipe__icon { background:#0ea5e9; color:#fff; border-color:#0284c7; box-shadow:0 0 0 4px rgba(14,165,233,.25); }
        .md-pipe__step.is-warn .md-pipe__icon { background:#f59e0b; color:#fff; border-color:#d97706; }
        .md-pipe__step.is-error .md-pipe__icon { background:#dc2626; color:#fff; border-color:#b91c1c; }
        .md-pipe__step.is-todo .md-pipe__icon { background:#f1f5f9; color:#94a3b8; border-color:#e2e8f0; }
        .md-pipe__date { margin-top:8px; font-size:11px; color:#64748b; line-height:1.2; min-height:1.2em; }
        .md-pipe__date.is-empty { color:#cbd5e1; }
        .md-pipe__step.is-current .md-pipe__date { color:#0284c7; font-weight:600; }
        .md-pipe__step.is-done .md-pipe__date { color:#0f766e; }
        .md-pipe__line {
            position:absolute; top:calc(2.5em + 8px + 22px); left:calc(50% + 24px); right:calc(-50% + 24px); height:3px; background:#e2e8f0; z-index:0;
        }
        .md-pipe__step.is-done .md-pipe__line { background:#0f766e; }
        .md-pipe--compact .md-pipe__step { min-width:70px; }
        .md-pipe--compact .md-pipe__title { font-size:10px; min-height:2.4em; margin-bottom:6px; }
        .md-pipe--compact .md-pipe__status { font-size:9px; margin-bottom:6px; }
        .md-pipe--compact .md-pipe__icon { width:34px; height:34px; }
        .md-pipe--compact .md-pipe__date { font-size:10px; margin-top:6px; }
        .md-pipe--compact .md-pipe__line { top:calc(2.4em + 6px + 17px); left:calc(50% + 18px); right:calc(-50% + 18px); }
        .md-pipe--compact .md-ico { width:16px; height:16px; }
        .md-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; vertical-align:middle; background:#e2e8f0; }
        .md-dot.is-done { background:#0f766e; }
        .md-dot.is-current { background:#0ea5e9; }
        .md-dot.is-todo { background:#cbd5e1; }
        .md-notify { display:grid; gap:8px; margin-top:12px; }
        .md-notify__row { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:10px; }
        .md-notify__row input { width:18px; height:18px; accent-color:#0f766e; }
        .md-notify__row label { flex:1; font-size:14px; cursor:pointer; }

        @media (max-width: 800px) {
            .buyer-layout { grid-template-columns: 1fr; }
            .buyer-side { display: flex; flex-wrap: wrap; gap: 6px; }
            .buyer-side a { display: inline-block; }
            .md-pipe__step { min-width:76px; }
        }
    </style>
    @stack('head')
</head>
<body class="buyer-shell">
@php
    $theme = $theme ?? request()->route('theme');
    $isSandbox = ($theme ?? null) instanceof \App\Models\Theme
        && (request()->routeIs('theme.sandbox.cuenta*') || !empty($sandbox));
    $sandboxStoreUrl = $isSandbox ? route('theme.sandbox.show', $theme->slug) : null;
    $buyerStoreUrl = null;
    if (! $isSandbox) {
        $buyerUser = Auth::guard('buyer')->user();
        if ($buyerUser) {
            $storeSlug = session('buyer_portal_store_slug');
            if (! $storeSlug) {
                $storeSlug = optional($buyerUser->ordersQuery()->first()?->store)->slug;
            }
            if ($storeSlug) {
                $buyerStoreUrl = route('store.design.show', $storeSlug);
            }
        }
    }
    $nav = fn (string ...$patterns) => collect($patterns)->contains(fn ($p) => request()->routeIs($p));
@endphp
<div class="buyer-layout">
    <aside class="buyer-side">
        <div class="buyer-brand">{{ __('buyer.brand') }}</div>
        @if($isSandbox)
            <div class="buyer-brand-sub">{{ __('buyer.sandbox') }} · {{ $theme->name }}</div>
            <a href="{{ route('theme.sandbox.cuenta.dashboard', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.dashboard') ? 'is-active' : '' }}">{{ __('buyer.nav.home') }}</a>
            <a href="{{ $sandboxStoreUrl }}" class="buyer-side__store">{{ __('buyer.nav.go_store') }}</a>
            <a href="{{ route('theme.sandbox.cuenta.profile', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.profile') ? 'is-active' : '' }}">{{ __('buyer.nav.profile') }}</a>
            <a href="{{ route('theme.sandbox.cuenta.orders', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.orders*') ? 'is-active' : '' }}">{{ __('buyer.nav.orders') }}</a>
            <a href="{{ route('theme.sandbox.cuenta.tracking', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.tracking') ? 'is-active' : '' }}">{{ __('buyer.nav.tracking') }}</a>
            <a href="{{ route('theme.sandbox.cuenta.claims', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.claims*') ? 'is-active' : '' }}">{{ __('buyer.nav.claims') }}</a>
            <a href="{{ route('theme.sandbox.cuenta.security', $theme->slug) }}" class="{{ $nav('theme.sandbox.cuenta.security') ? 'is-active' : '' }}">{{ __('buyer.nav.security') }}</a>
            <form method="post" action="{{ route('theme.sandbox.cuenta.logout', $theme->slug) }}" style="margin-top:16px;padding:0 8px">
                @csrf
                <button class="buyer-btn-secondary" style="width:100%;background:transparent;color:#94a3b8;border-color:#334155">{{ __('buyer.nav.logout') }}</button>
            </form>
        @else
            <a href="{{ route('buyer.dashboard') }}" class="{{ $nav('buyer.dashboard') ? 'is-active' : '' }}">{{ __('buyer.nav.home') }}</a>
            @if($buyerStoreUrl)
                <a href="{{ $buyerStoreUrl }}" class="buyer-side__store">{{ __('buyer.nav.go_store') }}</a>
            @endif
            <a href="{{ route('buyer.profile') }}" class="{{ $nav('buyer.profile') ? 'is-active' : '' }}">{{ __('buyer.nav.profile') }}</a>
            <a href="{{ route('buyer.orders.index') }}" class="{{ $nav('buyer.orders.*') ? 'is-active' : '' }}">{{ __('buyer.nav.orders') }}</a>
            <a href="{{ route('buyer.tracking') }}" class="{{ $nav('buyer.tracking') ? 'is-active' : '' }}">{{ __('buyer.nav.tracking') }}</a>
            <a href="{{ route('buyer.claims.index') }}" class="{{ $nav('buyer.claims.*') ? 'is-active' : '' }}">{{ __('buyer.nav.claims') }}</a>
            <a href="{{ route('buyer.security') }}" class="{{ $nav('buyer.security') ? 'is-active' : '' }}">{{ __('buyer.nav.security') }}</a>
            <form method="post" action="{{ route('buyer.logout') }}" style="margin-top:16px;padding:0 8px">
                @csrf
                <button class="buyer-btn-secondary" style="width:100%;background:transparent;color:#94a3b8;border-color:#334155">{{ __('buyer.nav.logout') }}</button>
            </form>
        @endif
    </aside>
    <main class="buyer-main">
        <div style="margin-bottom:18px">
            <h1 style="margin:0 0 4px;font-size:1.5rem">@yield('heading')</h1>
            <p class="buyer-muted" style="margin:0">@yield('subheading')</p>
        </div>
        @if(session('success'))
            <div class="buyer-alert buyer-alert-ok">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="buyer-alert buyer-alert-err">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="buyer-alert buyer-alert-err">{{ $errors->first() }}</div>
        @endif
        @yield('content')
    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
@stack('scripts')
</body>
</html>
