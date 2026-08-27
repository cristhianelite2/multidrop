<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gracias — {{ $theme->name }}</title>
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
            color: var(--paper);
            font: 600 12px/1.4 var(--font-b);
            padding: 10px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
        }
        .banner a { color: var(--amber); }
        .wrap { max-width: 720px; margin: 0 auto; padding: 28px 16px 64px; }
        .hero {
            text-align: center;
            padding: 28px 12px 20px;
        }
        .hero__mark {
            width: 56px; height: 56px; margin: 0 auto 14px;
            border-radius: 50%;
            display: grid; place-items: center;
            background: color-mix(in srgb, var(--charge) 22%, transparent);
            color: var(--charge);
            font-size: 1.6rem;
        }
        .hero h1 {
            margin: 0 0 8px;
            font: 700 clamp(1.6rem, 4vw, 2.2rem)/1.1 var(--font-d);
            letter-spacing: .02em;
            text-transform: uppercase;
        }
        .hero p { margin: 0; color: var(--muted); }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 14px;
        }
        .card h2 {
            margin: 0 0 14px;
            font: 700 1.15rem/1.2 var(--font-d);
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--amber);
        }
        .meta {
            display: grid;
            gap: 10px;
            grid-template-columns: 1fr;
        }
        @media (min-width: 560px) {
            .meta { grid-template-columns: 1fr 1fr; }
        }
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
        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
        }
        .status--ok { background: color-mix(in srgb, var(--charge) 22%, transparent); color: var(--charge); }
        .status--warn { background: color-mix(in srgb, var(--amber) 22%, transparent); color: var(--amber); }
        .status--err { background: color-mix(in srgb, var(--signal) 22%, transparent); color: var(--signal); }
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
        .item__name { font-weight: 600; font-size: .95rem; margin: 0 0 4px; }
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
            margin-top: 4px;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--amber);
            color: #14181A;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 3px;
            font-weight: 700;
            font-size: .9rem;
        }
        .btn:hover { filter: brightness(1.05); }
        .btn-ghost {
            display: inline-flex;
            align-items: center;
            color: var(--paper);
            text-decoration: none;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 3px;
            font-weight: 600;
            font-size: .9rem;
        }
        .muted { color: var(--muted); }
        .err { color: var(--signal); font-weight: 600; }
        .empty { text-align: center; padding: 40px 16px; }
    </style>
</head>
<body>
@php
    $home = route('theme.sandbox.show', $theme->slug);
    $checkout = route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => 'checkout']);
@endphp
<div class="banner">
    <span>Sandbox · {{ $theme->name }} · confirmación</span>
    <a href="{{ $home }}">Volver al flujo</a>
</div>

<div class="wrap">
    @if($error || ! $order)
        <div class="card empty">
            <h1 style="font-family:var(--font-d);text-transform:uppercase">No encontramos la confirmación</h1>
            <p class="muted">{{ $error ?: 'Completa el checkout de prueba primero.' }}</p>
            <p style="margin-top:18px"><a class="btn" href="{{ $checkout }}">Ir al checkout</a></p>
        </div>
    @else
        @php
            $status = $order['fulfillment_status'] ?? 'unfulfilled';
            $statusClass = match ($status) {
                'submitted', 'shipped' => 'status--ok',
                'skipped' => 'status--warn',
                'error' => 'status--err',
                default => 'status--warn',
            };
            $statusLabel = match ($status) {
                'submitted' => 'CJ recibió el pedido',
                'shipped' => 'CJ reporta envío',
                'skipped' => 'No se envió a CJ (sin VID)',
                'error' => 'CJ rechazó o falló',
                'unfulfilled' => 'Pendiente de fulfillment',
                default => $status,
            };
            $currency = $order['currency'] ?? 'MXN';
            $items = $order['items'] ?? [];
            $trackUrl = route('theme.sandbox.cuenta.enter', $theme->slug).'?number='.urlencode($order['number']).'&email='.urlencode($order['email']);
        @endphp

        <div class="hero">
            <div class="hero__mark" aria-hidden="true">✓</div>
            <h1>¡Gracias por tu compra!</h1>
            <p>Pago simulado recibido · Pedido <strong style="color:var(--amber);font-family:var(--font-m)">{{ $order['number'] }}</strong></p>
        </div>

        <div class="card">
            <h2>Estado del pedido</h2>
            <div class="meta">
                <div class="meta__row">
                    <span class="meta__label">Fulfillment CJ</span>
                    <span class="status {{ $statusClass }}">{{ $statusLabel }}</span>
                </div>
                <div class="meta__row">
                    <span class="meta__label">Confirmación</span>
                    <span class="meta__value">{{ $order['email'] }}</span>
                </div>
                @if(!empty($order['cj_order_id']))
                    <div class="meta__row">
                        <span class="meta__label">ID CJ</span>
                        <span class="meta__value"><code>{{ $order['cj_order_id'] }}</code></span>
                    </div>
                @endif
                @if(!empty($order['name']))
                    <div class="meta__row">
                        <span class="meta__label">Cliente</span>
                        <span class="meta__value">{{ $order['name'] }}</span>
                    </div>
                @endif
            </div>
            @if(!empty($order['cj_error']))
                <p class="err" style="margin:14px 0 0">{{ $order['cj_error'] }}</p>
            @endif
            <p class="muted" style="margin:14px 0 0;font-size:.85rem">Sandbox: no se envía correo real.</p>
        </div>

        <div class="card">
            <h2>Productos</h2>
            <div class="items">
                @forelse($items as $item)
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
                        <span>Descuento @if(!empty($order['coupon'])) ({{ $order['coupon'] }}) @endif</span>
                        <span>−${{ number_format((float) $order['discount'], 2) }}</span>
                    </div>
                @endif
                <div class="totals__row totals__row--total">
                    <span>Total</span>
                    <span>${{ number_format((float) ($order['total'] ?? 0), 2) }} {{ $currency }}</span>
                </div>
            </div>

            <div class="actions">
                <a class="btn" href="{{ $trackUrl }}">Seguir mi pedido</a>
                <a class="btn-ghost" href="{{ $home }}">Volver a la tienda</a>
            </div>

            @if(!empty($debugAdmin))
                <p class="muted" style="margin-top:16px;font-size:.8rem">
                    Admin → <a href="{{ url('/admin/sandbox/orders') }}" style="color:var(--amber)">Sandbox CJ</a>
                </p>
            @endif
        </div>
    @endif
</div>
</body>
</html>
