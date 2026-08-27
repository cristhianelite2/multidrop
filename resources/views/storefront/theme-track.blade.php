<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi pedido — {{ $theme->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #14181A;
            --panel: #1D2226;
            --raised: #232A2E;
            --line: #2B3236;
            --paper: #EEF1F0;
            --muted: #8D9797;
            --amber: #F2A93B;
            --charge: #4FB286;
            --signal: #E5573F;
            --font-d: 'Barlow Condensed', sans-serif;
            --font-b: Inter, system-ui, sans-serif;
            --font-m: 'JetBrains Mono', ui-monospace, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--paper);
            font: 400 15px/1.5 var(--font-b);
            min-height: 100vh;
        }
        .banner {
            background: color-mix(in srgb, var(--amber) 18%, var(--panel));
            border-bottom: 1px solid var(--line);
            font: 600 12px/1.4 var(--font-b);
            padding: 10px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }
        .banner a { color: var(--amber); }
        .wrap { max-width: 720px; margin: 0 auto; padding: 28px 16px 64px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 22px;
            margin-bottom: 14px;
        }
        h1 {
            margin: 0 0 8px;
            font: 700 clamp(1.5rem, 4vw, 2rem)/1.1 var(--font-d);
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        h2 {
            margin: 0 0 14px;
            font: 700 1.1rem/1.2 var(--font-d);
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--amber);
        }
        .muted { color: var(--muted); }
        .session {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
            background: color-mix(in srgb, var(--charge) 12%, var(--panel));
            border: 1px solid color-mix(in srgb, var(--charge) 35%, var(--line));
            border-radius: 3px;
            font-size: .88rem;
        }
        .session strong { color: var(--charge); }
        .form { display: grid; gap: 12px; margin-top: 16px; }
        label { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        input {
            width: 100%;
            font: inherit;
            padding: 12px 14px;
            border-radius: 3px;
            border: 1px solid var(--line);
            background: var(--raised);
            color: var(--paper);
        }
        input:focus { outline: none; border-color: var(--amber); }
        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--amber);
            color: #14181A;
            border: 0;
            cursor: pointer;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 3px;
            font: inherit;
            font-weight: 700;
        }
        .btn-ghost, button.ghost {
            background: transparent;
            color: var(--paper);
            border: 1px solid var(--line);
        }
        .err { color: var(--signal); font-weight: 600; margin-top: 12px; }
        .meta { display: grid; gap: 10px; grid-template-columns: 1fr; }
        @media (min-width: 560px) { .meta { grid-template-columns: 1fr 1fr; } }
        .meta__row {
            background: var(--raised);
            border: 1px solid var(--line);
            border-radius: 3px;
            padding: 10px 12px;
        }
        .meta__label {
            display: block;
            font-size: .68rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .meta__value { font-weight: 600; word-break: break-word; }
        .meta__value code { font-family: var(--font-m); font-size: .85rem; color: var(--amber); }
        .items { display: flex; flex-direction: column; gap: 10px; }
        .item {
            display: grid;
            grid-template-columns: 64px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            background: var(--raised);
            border: 1px solid var(--line);
            border-radius: 3px;
        }
        .item__img, .item__ph {
            width: 64px; height: 64px;
            border-radius: 3px;
            object-fit: cover;
            background: var(--bg);
        }
        .item__ph { display: grid; place-items: center; color: var(--muted); font-size: .7rem; }
        .item__name { font-weight: 600; margin: 0 0 4px; }
        .item__qty { font-family: var(--font-m); font-size: .75rem; color: var(--muted); margin: 0; }
        .item__price { font-family: var(--font-m); font-weight: 700; color: var(--amber); white-space: nowrap; }
        .totals {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            display: grid;
            gap: 6px;
        }
        .totals__row {
            display: flex;
            justify-content: space-between;
            color: var(--muted);
            font-size: .9rem;
        }
        .totals__row--total {
            color: var(--paper);
            font-weight: 700;
            font-size: 1.05rem;
        }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    </style>
</head>
<body>
@php
    $home = route('theme.sandbox.show', $theme->slug);
    $loggedIn = ! empty($loggedIn);
    $currency = $order['currency'] ?? 'MXN';
@endphp
<div class="banner">
    <span>Sandbox · {{ $theme->name }} · seguimiento</span>
    <a href="{{ $home }}">Volver al flujo</a>
</div>

<div class="wrap">
    @if(! $loggedIn || ! $order)
        <div class="card">
            <h1>Accede a tu pedido</h1>
            <p class="muted">Inicia sesión con el email y número del checkout. Verás el detalle y el estado de envío.</p>
            <form method="post" action="{{ route('theme.sandbox.track.lookup', $theme->slug) }}" class="form">
                @csrf
                <div>
                    <label for="number">Número de pedido</label>
                    <input id="number" name="number" value="{{ old('number', $number ?? '') }}" placeholder="TPL-XXXXXX" required autocomplete="off">
                </div>
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email ?? '') }}" placeholder="tu@email.com" required autocomplete="email">
                </div>
                <button type="submit">Iniciar sesión y ver pedido</button>
            </form>
            @if($error || session('error'))
                <p class="err">{{ $error ?: session('error') }}</p>
            @endif
        </div>
    @else
        <div class="session">
            <span>Sesión activa · <strong>{{ $order['email'] }}</strong> · {{ $order['number'] }}</span>
            <form method="post" action="{{ route('theme.sandbox.track.logout', $theme->slug) }}" style="margin:0">
                @csrf
                <button type="submit" class="ghost" style="padding:8px 12px;font-size:.8rem">Cerrar sesión</button>
            </form>
        </div>

        <div class="card">
            <h1>Pedido {{ $order['number'] }}</h1>
            <p class="muted" style="margin:0 0 16px">Hola{{ !empty($order['name']) ? ', '.$order['name'] : '' }}. Aquí está el estado de tu compra sandbox.</p>
            <div class="meta">
                <div class="meta__row">
                    <span class="meta__label">Pago</span>
                    <span class="meta__value">{{ $order['payment_status'] ?? '—' }}</span>
                </div>
                <div class="meta__row">
                    <span class="meta__label">Fulfillment CJ</span>
                    <span class="meta__value">{{ $order['fulfillment_status'] ?? '—' }}</span>
                </div>
                @if(!empty($order['cj_order_id']))
                    <div class="meta__row">
                        <span class="meta__label">ID CJ</span>
                        <span class="meta__value"><code>{{ $order['cj_order_id'] }}</code></span>
                    </div>
                @endif
                @if(!empty($order['tracking_number']))
                    <div class="meta__row">
                        <span class="meta__label">Guía</span>
                        <span class="meta__value">{{ $order['tracking_number'] }} @if(!empty($order['carrier'])) ({{ $order['carrier'] }}) @endif</span>
                    </div>
                @endif
            </div>
            @if(!empty($order['cj_error']))
                <p class="err" style="margin:14px 0 0">{{ $order['cj_error'] }}</p>
            @endif
        </div>

        <div class="card">
            <h2>Productos</h2>
            <div class="items">
                @forelse(($order['items'] ?? []) as $item)
                    @php
                        $qty = max(1, (int) ($item['qty'] ?? 1));
                        $unit = (float) ($item['price'] ?? 0);
                        $line = $unit * $qty;
                        $img = $item['image'] ?? null;
                    @endphp
                    <div class="item">
                        @if($img)
                            <img class="item__img" src="{{ $img }}" alt="" loading="lazy">
                        @else
                            <div class="item__ph" aria-hidden="true">IMG</div>
                        @endif
                        <div>
                            <p class="item__name">{{ $item['name'] ?? 'Producto' }}</p>
                            <p class="item__qty">Cantidad {{ $qty }} · ${{ number_format($unit, 2) }} c/u</p>
                        </div>
                        <div class="item__price">${{ number_format($line, 2) }}</div>
                    </div>
                @empty
                    <p class="muted">Sin líneas de pedido.</p>
                @endforelse
            </div>
            <div class="totals">
                <div class="totals__row">
                    <span>Subtotal</span>
                    <span>${{ number_format((float) ($order['subtotal'] ?? 0), 2) }} {{ $currency }}</span>
                </div>
                @if(((float) ($order['discount'] ?? 0)) > 0)
                    <div class="totals__row">
                        <span>Descuento</span>
                        <span>−${{ number_format((float) $order['discount'], 2) }}</span>
                    </div>
                @endif
                <div class="totals__row totals__row--total">
                    <span>Total</span>
                    <span>${{ number_format((float) ($order['total'] ?? 0), 2) }} {{ $currency }}</span>
                </div>
            </div>
            <div class="actions">
                <a class="btn" href="{{ $home }}">Volver a la tienda</a>
            </div>
        </div>
    @endif
</div>
</body>
</html>
