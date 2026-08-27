<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Multidrop Admin')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body class="admin-shell min-h-screen">
@php
    $navActive = fn (string ...$patterns) => collect($patterns)->contains(fn ($p) => request()->routeIs($p));
@endphp

<div class="admin-layout min-h-screen" data-admin-layout>
    {{-- Sidebar desktop --}}
    <aside class="admin-sidebar hidden lg:flex lg:flex-col" data-admin-sidebar>
        <div class="admin-sidebar-head">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand min-w-0 flex-1">
                <div class="font-display text-lg font-extrabold tracking-tight text-ink leading-none truncate">Multidrop</div>
                <div class="admin-brand-sub mt-0.5 text-[10px] font-medium uppercase tracking-[0.14em] text-teal">Admin</div>
            </a>
            <button type="button" class="admin-sidebar-toggle" data-sidebar-compact title="Comprimir menú" aria-label="Comprimir menú">«</button>
        </div>

        <div class="admin-sidebar-switcher px-2 pb-2">
            @include('admin.partials.store-switcher-trigger')
        </div>

        <nav class="admin-sidebar-nav flex-1 overflow-y-auto px-1.5 pb-3">
            <div class="admin-nav-section" data-nav-section="operacion">
                <button type="button" class="admin-nav-section-toggle" data-nav-section-toggle>
                    <span>Operación</span><span class="admin-nav-chevron">▾</span>
                </button>
                <div class="admin-nav-section-body" data-nav-section-body>
                    @canperm('lab.dashboard', 'admin.access')
                        <a href="{{ route('admin.dashboard') }}" class="admin-nav-link {{ $navActive('admin.dashboard') ? 'admin-nav-link-active' : '' }}" title="Dashboard">
                            <span class="admin-nav-ico"><i class="fa-solid fa-gauge-high"></i></span><span class="admin-nav-txt">Dashboard</span>
                        </a>
                    @endcanperm
                    @canperm('store.manage')
                        <a href="{{ route('admin.store.hub') }}" class="admin-nav-link {{ $navActive('admin.store.hub', 'admin.stores.*') ? 'admin-nav-link-active' : '' }}" title="Tienda">
                            <span class="admin-nav-ico"><i class="fa-solid fa-store"></i></span><span class="admin-nav-txt">Tienda</span>
                            @if(($adminGlobalSalesUnread ?? 0) > 0)
                                <span class="admin-badge bg-emerald-100 text-emerald-800">{{ $adminGlobalSalesUnread }}</span>
                            @endif
                        </a>
                    @endcanperm
                    @canperm('lab.cj')
                        <a href="{{ route('admin.lab.cj') }}" class="admin-nav-link {{ $navActive('admin.lab.cj*') ? 'admin-nav-link-active' : '' }}" title="CJ Search">
                            <span class="admin-nav-ico"><i class="fa-solid fa-magnifying-glass"></i></span><span class="admin-nav-txt">CJ Search</span>
                        </a>
                    @endcanperm
                </div>
            </div>

            @canperm('store.manage')
                <div class="admin-nav-section" data-nav-section="tienda">
                    <button type="button" class="admin-nav-section-toggle" data-nav-section-toggle>
                        <span>Tienda</span><span class="admin-nav-chevron">▾</span>
                    </button>
                    <div class="admin-nav-section-body" data-nav-section-body>
                        <a href="{{ route('admin.store.stats') }}" class="admin-nav-link {{ $navActive('admin.store.stats') ? 'admin-nav-link-active' : '' }}" title="Estadísticas"><span class="admin-nav-ico"><i class="fa-solid fa-chart-line"></i></span><span class="admin-nav-txt">Estadísticas</span></a>
                        <a href="{{ route('admin.store.general.edit') }}" class="admin-nav-link {{ $navActive('admin.store.general.*') ? 'admin-nav-link-active' : '' }}" title="General"><span class="admin-nav-ico"><i class="fa-solid fa-sliders"></i></span><span class="admin-nav-txt">General</span></a>
                        <a href="{{ route('admin.store.design.edit') }}" class="admin-nav-link {{ $navActive('admin.store.design.*') ? 'admin-nav-link-active' : '' }}" title="Diseño"><span class="admin-nav-ico"><i class="fa-solid fa-palette"></i></span><span class="admin-nav-txt">Diseño</span></a>
                        <a href="{{ route('admin.store.products.index') }}" class="admin-nav-link {{ $navActive('admin.store.products.*') ? 'admin-nav-link-active' : '' }}" title="Productos"><span class="admin-nav-ico"><i class="fa-solid fa-box-open"></i></span><span class="admin-nav-txt">Productos</span></a>
                        <a href="{{ route('admin.store.marketing.index') }}" class="admin-nav-link {{ $navActive('admin.store.marketing.*') ? 'admin-nav-link-active' : '' }}" title="Marketing"><span class="admin-nav-ico"><i class="fa-solid fa-bullhorn"></i></span><span class="admin-nav-txt">Marketing</span></a>
                        @if(($currentStore ?? null)?->commerceEnabled())
                            <a href="{{ route('admin.store.promotions.index') }}" class="admin-nav-link {{ $navActive('admin.store.promotions.*') ? 'admin-nav-link-active' : '' }}" title="Promos"><span class="admin-nav-ico"><i class="fa-solid fa-percent"></i></span><span class="admin-nav-txt">Promos</span></a>
                            <a href="{{ route('admin.store.orders.index') }}" class="admin-nav-link {{ $navActive('admin.store.orders.*') ? 'admin-nav-link-active' : '' }}" title="Pedidos"><span class="admin-nav-ico"><i class="fa-solid fa-receipt"></i></span><span class="admin-nav-txt">Pedidos</span>@if(($adminStorePulse['new_sales_unread'] ?? 0) > 0)<span class="admin-badge bg-emerald-100 text-emerald-800">{{ $adminStorePulse['new_sales_unread'] }}</span>@endif</a>
                            <a href="{{ route('admin.store.claims.index') }}" class="admin-nav-link {{ $navActive('admin.store.claims.*') ? 'admin-nav-link-active' : '' }}" title="Reclamos"><span class="admin-nav-ico"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="admin-nav-txt">Reclamos</span>@if(($adminStorePulse['claims_open'] ?? 0) > 0)<span class="admin-badge bg-amber-100 text-amber-800">{{ $adminStorePulse['claims_open'] }}</span>@endif</a>
                            <a href="{{ route('admin.store.customers.index') }}" class="admin-nav-link {{ $navActive('admin.store.customers.*') ? 'admin-nav-link-active' : '' }}" title="Clientes"><span class="admin-nav-ico"><i class="fa-solid fa-users"></i></span><span class="admin-nav-txt">Clientes</span></a>
                        @endif
                        @php
                            $moduleLinks = [
                                ['key' => 'upsell', 'route' => 'admin.store.upsells.index', 'match' => 'admin.store.upsells.*', 'label' => 'Upsell', 'icon' => 'fa-solid fa-arrow-up'],
                                ['key' => 'cross_sell', 'route' => 'admin.store.cross-sells.index', 'match' => 'admin.store.cross-sells.*', 'label' => 'Cross Sell', 'icon' => 'fa-solid fa-right-left'],
                                ['key' => 'urgency', 'route' => 'admin.store.urgency.edit', 'match' => 'admin.store.urgency.*', 'label' => 'Urgencia', 'icon' => 'fa-solid fa-clock'],
                                ['key' => 'roulette', 'route' => 'admin.store.roulette.index', 'match' => 'admin.store.roulette.*', 'label' => 'Ruleta', 'icon' => 'fa-solid fa-dharmachakra'],
                                ['key' => 'social_proof', 'route' => 'admin.store.social-proof.edit', 'match' => 'admin.store.social-proof.*', 'label' => 'Prueba social', 'icon' => 'fa-solid fa-comments'],
                                ['key' => 'newsletter', 'route' => 'admin.store.newsletter.edit', 'match' => 'admin.store.newsletter.*', 'label' => 'Newsletter', 'icon' => 'fa-solid fa-envelope'],
                                ['key' => 'cookies', 'route' => 'admin.store.cookies.edit', 'match' => 'admin.store.cookies.*', 'label' => 'Cookies', 'icon' => 'fa-solid fa-cookie-bite'],
                                ['key' => 'combos', 'route' => 'admin.store.combos.index', 'match' => 'admin.store.combos.*', 'label' => 'Combos', 'icon' => 'fa-solid fa-layer-group'],
                            ];
                        @endphp
                        @if($moduleLinks)
                            <div class="admin-nav-section admin-nav-section--nested" data-nav-section="modulos">
                                <button type="button" class="admin-nav-section-toggle" data-nav-section-toggle>
                                    <span class="inline-flex items-center gap-2"><span class="admin-nav-ico"><i class="fa-solid fa-puzzle-piece"></i></span><span>Módulos</span></span>
                                    <span class="admin-nav-chevron">▾</span>
                                </button>
                                <div class="admin-nav-section-body" data-nav-section-body>
                                    @foreach($moduleLinks as $mod)
                                        <a href="{{ route($mod['route']) }}" class="admin-nav-link {{ $navActive($mod['match']) ? 'admin-nav-link-active' : '' }}" title="{{ $mod['label'] }}">
                                            <span class="admin-nav-ico"><i class="{{ $mod['icon'] }}"></i></span><span class="admin-nav-txt">{{ $mod['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endcanperm

            @canperm('settings.general', 'users.view', 'roles.view', 'profile.update', 'store.manage')
                <div class="admin-nav-section" data-nav-section="plataforma">
                    <button type="button" class="admin-nav-section-toggle" data-nav-section-toggle>
                        <span>Plataforma</span><span class="admin-nav-chevron">▾</span>
                    </button>
                    <div class="admin-nav-section-body" data-nav-section-body>
                        @canperm('store.manage')
                            <a href="{{ route('admin.templates.index') }}" class="admin-nav-link {{ $navActive('admin.templates.*') ? 'admin-nav-link-active' : '' }}" title="Plantillas"><span class="admin-nav-ico"><i class="fa-solid fa-copy"></i></span><span class="admin-nav-txt">Plantillas</span></a>
                            <a href="{{ route('admin.sandbox.orders.index') }}" class="admin-nav-link {{ $navActive('admin.sandbox.orders.*') ? 'admin-nav-link-active' : '' }}" title="Sandbox CJ"><span class="admin-nav-ico"><i class="fa-solid fa-flask"></i></span><span class="admin-nav-txt">Sandbox CJ</span></a>
                        @endcanperm
                        @canperm('settings.general')
                            <a href="{{ route('admin.settings.general') }}" class="admin-nav-link {{ $navActive('admin.settings.*') ? 'admin-nav-link-active' : '' }}" title="General"><span class="admin-nav-ico"><i class="fa-solid fa-gear"></i></span><span class="admin-nav-txt">General</span></a>
                        @endcanperm
                        @canperm('users.view')
                            <a href="{{ route('admin.users.index') }}" class="admin-nav-link {{ $navActive('admin.users.*') ? 'admin-nav-link-active' : '' }}" title="Admins"><span class="admin-nav-ico"><i class="fa-solid fa-user-shield"></i></span><span class="admin-nav-txt">Admins</span></a>
                        @endcanperm
                        @canperm('roles.view')
                            <a href="{{ route('admin.roles.index') }}" class="admin-nav-link {{ $navActive('admin.roles.*') ? 'admin-nav-link-active' : '' }}" title="Roles"><span class="admin-nav-ico"><i class="fa-solid fa-user-tag"></i></span><span class="admin-nav-txt">Roles</span></a>
                        @endcanperm
                        @canperm('profile.update')
                            <a href="{{ route('admin.profile.edit') }}" class="admin-nav-link {{ $navActive('admin.profile.*') ? 'admin-nav-link-active' : '' }}" title="Perfil"><span class="admin-nav-ico"><i class="fa-solid fa-id-badge"></i></span><span class="admin-nav-txt">Perfil</span></a>
                        @endcanperm
                    </div>
                </div>
            @endcanperm
        </nav>

        <div class="admin-sidebar-foot border-t border-line/70 p-2">
            <div class="admin-user-row flex items-center gap-2">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-teal to-sky-500 text-xs font-semibold text-white">
                    {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                </div>
                <div class="admin-user-meta min-w-0 flex-1">
                    <div class="truncate text-xs font-semibold text-ink">{{ auth()->user()->name }}</div>
                    <div class="truncate text-[10px] text-ink-soft/60">{{ auth()->user()->email }}</div>
                </div>
            </div>
            <form action="{{ route('admin.logout') }}" method="post" class="mt-2">
                @csrf
                <button type="submit" class="admin-btn-secondary w-full !px-2 !py-1.5 text-xs">Salir</button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="admin-main min-w-0">
        <header class="sticky top-0 z-30 border-b border-line/70 bg-white/75 backdrop-blur-xl">
            <div class="flex items-center gap-3 px-3 py-2.5 sm:px-5">
                <button type="button" data-mobile-nav-toggle class="admin-btn-secondary !px-2.5 !py-1.5 lg:hidden" aria-label="Menú">☰</button>

                <div class="min-w-0 flex-1">
                    <div class="font-display text-base font-bold text-ink truncate sm:text-lg">@yield('heading', 'Panel')</div>
                    @hasSection('subheading')
                        <p class="truncate text-xs text-ink-soft/65 sm:text-sm">@yield('subheading')</p>
                    @endif
                </div>

                <div class="hidden sm:block lg:hidden w-48">
                    @include('admin.partials.store-switcher-trigger')
                </div>

                @if($currentStore ?? null)
                    <a href="{{ route('store.design.show', ['slug' => $currentStore->slug]) }}"
                       target="_blank" rel="noopener"
                       title="Ver tienda: {{ $currentStore->name }}"
                       class="hidden md:flex items-center gap-2 rounded-full border border-line bg-white px-2.5 py-1 text-[11px] hover:border-teal/50 hover:bg-teal/5 transition-colors cursor-pointer">
                        <span class="admin-badge {{ ($currentStore->store_type ?? '') === 'mega' ? 'bg-sky-100 text-sky-800' : 'bg-teal/10 text-teal' }}">
                            {{ $currentStore->store_type }}
                        </span>
                        <span class="font-semibold text-ink">{{ $currentStore->name }}</span>
                        @include('admin.partials.store-locale-currency', ['store' => $currentStore])
                        @if(($adminStorePulse['new_sales_unread'] ?? 0) > 0)
                            <span class="admin-badge bg-emerald-100 text-emerald-800">Nuevas ventas: {{ $adminStorePulse['new_sales_unread'] }}</span>
                        @else
                            <span class="admin-badge bg-slate-100 text-slate-700">Nuevas ventas: 0</span>
                        @endif
                        @if(($adminStorePulse['claims_open'] ?? 0) > 0)
                            <span class="admin-badge bg-amber-100 text-amber-800">Reclamos: {{ $adminStorePulse['claims_open'] }}</span>
                        @else
                            <span class="admin-badge bg-slate-100 text-slate-700">Reclamos: 0</span>
                        @endif
                        <span class="text-ink-soft/40">↗</span>
                    </a>
                @endif
            </div>
        </header>

        <div data-mobile-nav class="hidden lg:hidden border-b border-line bg-white px-3 py-3 space-y-1">
            @include('admin.partials.store-switcher-trigger')
            @canperm('lab.dashboard', 'admin.access')
                <a href="{{ route('admin.dashboard') }}" class="admin-nav-link">Dashboard</a>
            @endcanperm
            @canperm('store.manage')
                <a href="{{ route('admin.store.hub') }}" class="admin-nav-link">Tienda</a>
                <a href="{{ route('admin.store.stats') }}" class="admin-nav-link">Estadísticas tienda</a>
                <a href="{{ route('admin.store.general.edit') }}" class="admin-nav-link">General tienda</a>
                <a href="{{ route('admin.store.design.edit') }}" class="admin-nav-link">Diseño</a>
                <a href="{{ route('admin.store.marketing.index') }}" class="admin-nav-link">Marketing</a>
                <a href="{{ route('admin.templates.index') }}" class="admin-nav-link">Plantillas</a>
                <a href="{{ route('admin.sandbox.orders.index') }}" class="admin-nav-link">Sandbox CJ</a>
                @if(($currentStore ?? null)?->commerceEnabled())
                    <a href="{{ route('admin.store.orders.index') }}" class="admin-nav-link">Pedidos @if(($adminStorePulse['new_sales_unread'] ?? 0) > 0)({{ $adminStorePulse['new_sales_unread'] }})@endif</a>
                    <a href="{{ route('admin.store.claims.index') }}" class="admin-nav-link">Reclamos @if(($adminStorePulse['claims_open'] ?? 0) > 0)({{ $adminStorePulse['claims_open'] }})@endif</a>
                    <a href="{{ route('admin.store.customers.index') }}" class="admin-nav-link">Clientes</a>
                @endif
            @endcanperm
            @canperm('lab.cj')
                <a href="{{ route('admin.lab.cj') }}" class="admin-nav-link">CJ Search</a>
            @endcanperm
            @canperm('settings.general')
                <a href="{{ route('admin.settings.general') }}" class="admin-nav-link">General</a>
            @endcanperm
        </div>

        <main class="px-3 py-4 sm:px-5 lg:px-6">
            @yield('content')
        </main>
    </div>
</div>

@include('admin.partials.store-switcher-panel')
@include('admin.partials.toast')

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
@stack('scripts')
</body>
</html>
