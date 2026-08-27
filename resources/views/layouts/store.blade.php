<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'BAZA — Tu tienda online')</title>
    <meta name="description" content="@yield('meta', 'E-commerce de propósito general. Ofertas, envíos y miles de productos.')">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f6f8;
            --surface: #ffffff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #0f766e;
            --brand-2: #115e59;
            --accent: #f59e0b;
            --danger: #e11d48;
            --radius: 16px;
            --shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            color: var(--ink);
            background: var(--bg);
        }
        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }
        button, input { font: inherit; }
        .wrap { width: min(1200px, calc(100% - 24px)); margin: 0 auto; }
        .search input { font-size: 16px; min-width: 0; }
        .search button { min-height: 44px; }
        @media (max-width: 480px) {
            .grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .trust { grid-template-columns: 1fr; }
            .top-actions .icon-btn { min-height: 44px; }
        }

        /* announcement */
        .announce {
            background: var(--ink);
            color: #fff;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            padding: 9px 14px;
        }
        .announce b { color: #5eead4; }

        /* header marketplace */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            position: sticky; top: 0; z-index: 50;
        }
        .topbar-inner {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 18px;
            align-items: center;
            padding: 12px 0;
        }
        @media (max-width: 820px) {
            .topbar-inner { grid-template-columns: auto 1fr; }
            .top-actions .hide-sm { display: none; }
            .search { grid-column: 1 / -1; order: 3; }
        }
        .logo {
            display: flex; align-items: center; gap: 10px;
            font-weight: 800; font-size: 1.35rem; letter-spacing: -.04em;
        }
        .logo img { width: 42px; height: 42px; object-fit: contain; }
        .search {
            display: flex; align-items: stretch;
            border: 1.5px solid var(--line);
            border-radius: 999px;
            overflow: hidden;
            background: #f8fafc;
        }
        .search:focus-within { border-color: var(--brand); background: #fff; }
        .search input {
            flex: 1; border: 0; outline: 0; background: transparent;
            padding: 12px 16px; min-width: 0;
        }
        .search button {
            border: 0; background: var(--brand); color: #fff;
            padding: 0 18px; font-weight: 700; cursor: pointer;
        }
        .top-actions { display: flex; gap: 10px; align-items: center; }
        .icon-btn {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
        }
        .cats-nav {
            display: flex; gap: 8px; overflow-x: auto;
            padding: 0 0 12px;
            scrollbar-width: none;
        }
        .cats-nav::-webkit-scrollbar { display: none; }
        .cats-nav a {
            flex: 0 0 auto;
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
        }
        .cats-nav a:hover { color: var(--ink); border-color: #cbd5e1; }

        /* promo roulette / carousel */
        .roulette-wrap { padding: 18px 0 8px; }
        .roulette {
            position: relative;
            border-radius: 22px;
            overflow: hidden;
            background: #0f172a;
            min-height: 280px;
            box-shadow: var(--shadow);
        }
        .roulette-track {
            display: flex;
            transition: transform .55s cubic-bezier(.22,1,.36,1);
            will-change: transform;
        }
        .slide {
            min-width: 100%;
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            min-height: 280px;
            color: #fff;
            position: relative;
        }
        @media (max-width: 800px) {
            .slide { grid-template-columns: 1fr; min-height: 360px; }
            .slide-media { min-height: 180px; order: -1; }
        }
        .slide-copy {
            padding: clamp(22px, 4vw, 42px);
            display: flex; flex-direction: column; justify-content: center;
            gap: 10px;
            z-index: 1;
        }
        .slide-kicker {
            font-size: 12px; font-weight: 800; letter-spacing: .12em;
            text-transform: uppercase; color: #5eead4;
        }
        .slide h2 {
            margin: 0; font-size: clamp(1.7rem, 4vw, 2.6rem);
            line-height: 1.05; letter-spacing: -.03em; max-width: 14ch;
        }
        .slide p { margin: 0; color: #cbd5e1; max-width: 34rem; }
        .slide-cta {
            display: inline-flex; width: fit-content; margin-top: 8px;
            background: #fff; color: var(--ink);
            border-radius: 999px; padding: 12px 18px; font-weight: 800;
        }
        .slide-media {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, #134e4a, #0f172a);
        }
        .slide-media img {
            width: 100%; height: 100%; object-fit: cover; min-height: 280px;
            mix-blend-mode: normal; opacity: .95;
        }
        .slide.s2 .slide-media { background: linear-gradient(135deg, #7c2d12, #0f172a); }
        .slide.s3 .slide-media { background: linear-gradient(135deg, #1e3a8a, #0f172a); }
        .slide.s4 .slide-media { background: linear-gradient(135deg, #365314, #0f172a); }
        .slide.s5 .slide-media { background: linear-gradient(135deg, #4c1d95, #0f172a); }

        .roulette-nav {
            position: absolute; inset: auto 14px 14px auto;
            display: flex; gap: 8px; z-index: 2;
        }
        .roulette-nav button {
            width: 40px; height: 40px; border-radius: 999px;
            border: 1px solid rgba(255,255,255,.25);
            background: rgba(15,23,42,.55); color: #fff;
            cursor: pointer; font-weight: 800;
        }
        .roulette-dots {
            position: absolute; left: 18px; bottom: 18px;
            display: flex; gap: 7px; z-index: 2;
        }
        .roulette-dots button {
            width: 8px; height: 8px; border-radius: 999px; border: 0;
            background: rgba(255,255,255,.35); cursor: pointer; padding: 0;
        }
        .roulette-dots button.active { background: #fff; width: 22px; }

        /* trust */
        .trust {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding: 16px 0 8px;
        }
        @media (max-width: 800px) { .trust { grid-template-columns: 1fr 1fr; } }
        .trust div {
            background: #fff; border: 1px solid var(--line); border-radius: 14px;
            padding: 14px; font-size: 13px; font-weight: 600; color: var(--muted);
        }
        .trust strong { display: block; color: var(--ink); margin-bottom: 2px; }

        /* catalog */
        .section { padding: 18px 0 40px; }
        .section-head {
            display: flex; justify-content: space-between; align-items: end;
            gap: 12px; margin: 10px 0 14px;
        }
        .section-head h2 { margin: 0; font-size: 1.45rem; letter-spacing: -.02em; }
        .section-head p { margin: 0; color: var(--muted); font-size: 14px; }

        .filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .chip {
            border: 1px solid var(--line); background: #fff; color: var(--muted);
            border-radius: 999px; padding: 8px 12px; font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .chip.active, .chip:hover { background: var(--ink); color: #fff; border-color: var(--ink); }

        .grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        @media (max-width: 1000px) { .grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 700px) { .grid { grid-template-columns: repeat(2, 1fr); } }

        .card {
            background: #fff; border: 1px solid var(--line); border-radius: 18px;
            overflow: hidden; display: flex; flex-direction: column;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
        .card .shot {
            aspect-ratio: 1; background: #f1f5f9; position: relative; overflow: hidden;
        }
        .card .shot img { width: 100%; height: 100%; object-fit: cover; }
        .badge {
            position: absolute; top: 10px; left: 10px;
            background: #fff; border: 1px solid var(--line);
            border-radius: 999px; padding: 4px 9px;
            font-size: 11px; font-weight: 800; text-transform: uppercase;
        }
        .meta { padding: 12px 12px 14px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .meta h3 {
            margin: 0; font-size: .92rem; line-height: 1.3; font-weight: 700;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
            min-height: 2.5em;
        }
        .price { font-weight: 800; font-size: 1.15rem; }
        .price s { color: var(--muted); font-size: .85rem; font-weight: 600; margin-left: 6px; }
        .stock { font-size: 12px; color: var(--muted); font-weight: 600; }
        .stock.low { color: var(--danger); }

        .deal {
            margin: 8px 0 22px;
            background: #fff; border: 1px solid var(--line); border-radius: 18px;
            padding: 16px; display: grid; grid-template-columns: 1.2fr .8fr; gap: 14px;
        }
        @media (max-width: 720px) { .deal { grid-template-columns: 1fr; } }
        .deal h3 { margin: 0 0 4px; }
        .deal p { margin: 0; color: var(--muted); font-size: 14px; }
        .deal-row { display: flex; gap: 8px; }
        .deal input {
            flex: 1; border: 1px solid var(--line); border-radius: 12px; padding: 11px 12px; background: #f8fafc;
        }
        .btn {
            border: 0; border-radius: 999px; background: var(--brand); color: #fff;
            font-weight: 800; padding: 11px 16px; cursor: pointer;
        }
        #coupon-msg { min-height: 18px; margin-top: 8px; font-size: 13px; font-weight: 700; color: var(--brand); }

        footer.site {
            border-top: 1px solid var(--line); background: #fff;
            padding: 28px 0 40px; color: var(--muted); font-size: 13px; margin-top: 20px;
        }

        .product-page {
            display: grid; grid-template-columns: 1fr 1fr; gap: 24px; padding: 24px 0 48px;
        }
        @media (max-width: 860px) { .product-page { grid-template-columns: 1fr; } }
        .gallery {
            background: #fff; border: 1px solid var(--line); border-radius: 20px; overflow: hidden;
        }
        .gallery img { width: 100%; aspect-ratio: 1; object-fit: cover; }
        .product-page h1 { margin: 8px 0 10px; font-size: clamp(1.4rem, 3vw, 2rem); letter-spacing: -.02em; }
        .upsell {
            margin-top: 14px; border: 1px dashed var(--brand); border-radius: 14px;
            padding: 12px; background: rgba(15,118,110,.05);
        }
    </style>
</head>
<body>
@php
    $endsAt = isset($offer) && $offer?->ends_at ? \Illuminate\Support\Carbon::parse($offer->ends_at)->toIso8601String() : null;
@endphp

<div class="announce" id="urgency-bar" data-ends-at="{{ $endsAt }}">
    Envío a todo México · Ofertas del día terminan en <b id="countdown">--:--:--</b>
    @isset($coupon) · Cupón <b>{{ $coupon->code }}</b> @endisset
</div>

<header class="topbar">
    <div class="wrap">
        <div class="topbar-inner">
            <a class="logo" href="{{ route('store.home') }}">
                <img src="{{ asset('media/brand/baza-logo.png') }}" alt="BAZA">
                <span>BAZA</span>
            </a>
            <form class="search" action="{{ route('store.home') }}" method="get" id="search-form">
                <input type="search" name="q" id="search-q" placeholder="Buscar en BAZA..." value="{{ request('q') }}">
                <button type="submit">Buscar</button>
            </form>
            <div class="top-actions">
                <a class="icon-btn hide-sm" href="{{ route('admin.lab.dashboard') }}">Lab</a>
                <a class="icon-btn" href="#shop">Catálogo</a>
                <span class="icon-btn">🛒 0</span>
            </div>
        </div>
        <nav class="cats-nav">
            <a href="#shop">Destacados</a>
            <a href="#shop" data-jump="lighting">Iluminación</a>
            <a href="#shop" data-jump="powerbank">Energía</a>
            <a href="#shop" data-jump="fan">Hogar</a>
            <a href="#shop" data-jump="flashlight">Portátil</a>
            <a href="#shop" data-jump="power">Backup</a>
            <a href="#deal">Cupones</a>
        </nav>
    </div>
</header>

@yield('content')

<footer class="site">
    <div class="wrap">
        © {{ date('Y') }} BAZA · E-commerce de propósito general · Multidrop
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
(function(){
  var ends = $('#urgency-bar').data('ends-at');
  if(!ends) return;
  function tick(){
    var d = new Date(ends).getTime() - Date.now();
    if(d<=0){ $('#countdown').text('00:00:00'); return; }
    var h=Math.floor(d/3600000), m=Math.floor((d%3600000)/60000), s=Math.floor((d%60000)/1000);
    $('#countdown').text(String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'));
  }
  tick(); setInterval(tick,1000);
})();
</script>
@stack('scripts')
</body>
</html>
