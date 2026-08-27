<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order ? 'Gracias — '.$order->number : 'Seguimiento' }} — {{ $store->name }}</title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --teal: #0f766e;
            --teal-soft: #ccfbf1;
            --paper: #f8fafc;
            --card: #ffffff;
        }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", system-ui, sans-serif; margin: 0; background:
            radial-gradient(900px 420px at 10% -10%, rgba(15,118,110,.12), transparent 55%),
            radial-gradient(700px 380px at 100% 0%, rgba(245,158,11,.08), transparent 50%),
            var(--paper);
            color: var(--ink); }
        .wrap { max-width: 680px; margin: 24px auto; padding: 0 16px 56px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 20px 18px; margin-bottom: 16px; box-shadow: 0 10px 30px rgba(15,23,42,.04); }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        @media (max-width: 480px) {
            .line { grid-template-columns: 52px 1fr; }
            .line-prices { text-align: left; grid-column: 2; }
        }
        .eyebrow { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 8px; font-weight: 700; }
        h1 { margin: 0 0 10px; font-size: clamp(1.45rem, 3vw, 1.85rem); line-height: 1.2; }
        h2 { margin: 0 0 12px; font-size: 1.15rem; }
        .lede { margin: 0 0 14px; color: #334155; line-height: 1.55; }
        .muted { color: var(--muted); }
        .thanks-icon {
            width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center;
            background: linear-gradient(145deg, var(--teal), #14b8a6); color: #fff; font-size: 1.4rem; font-weight: 800;
            margin-bottom: 14px; box-shadow: 0 8px 20px rgba(15,118,110,.25);
        }
        .steps { display: grid; gap: 10px; margin: 18px 0 8px; }
        .step {
            display: grid; grid-template-columns: 28px 1fr; gap: 10px; align-items: start;
            padding: 12px 14px; border-radius: 12px; background: #f8fafc; border: 1px solid var(--line);
        }
        .step-num {
            width: 28px; height: 28px; border-radius: 999px; background: var(--teal-soft); color: var(--teal);
            display: grid; place-items: center; font-size: 12px; font-weight: 800;
        }
        .step strong { display: block; margin-bottom: 2px; }
        .step span { font-size: 13px; color: var(--muted); line-height: 1.4; }
        input, button { font: inherit; font-size: 16px; padding: 11px 12px; border-radius: 10px; border: 1px solid #cbd5e1; width: 100%; box-sizing: border-box; }
        button, .btn {
            background: var(--teal); color: #fff; border: 0; cursor: pointer; font-weight: 700;
            display: inline-block; text-decoration: none; text-align: center; padding: 11px 16px; border-radius: 10px;
        }
        .btn-secondary { background: #fff; color: var(--teal); border: 1px solid color-mix(in srgb, var(--teal) 35%, #cbd5e1); }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px 14px; margin: 0 0 14px; font-size: 14px; }
        .pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
            background: #f1f5f9; font-size: 12px; font-weight: 700; color: #334155;
        }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
        td:last-child { text-align: right; white-space: nowrap; }
        .back { color: var(--muted); text-decoration: none; font-size: 14px; }
        .back:hover { color: var(--teal); }
        .alert { color: #b45309; margin: 12px 0 0; }
        .md-platform-contact {
            margin-top: 4px; padding: 16px; border-radius: 14px; background: #f8fafc; border: 1px dashed #cbd5e1;
        }
        .savings-banner {
            margin: 0 0 16px; padding: 14px 16px; border-radius: 14px;
            background: linear-gradient(135deg, #ecfdf5, #f0fdfa 55%, #fffbeb);
            border: 1px solid #99f6e4;
        }
        .savings-banner > .savings-head {
            display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px;
            margin-bottom: 10px;
        }
        .savings-banner strong { color: #0f766e; font-size: 1.15rem; }
        .savings-banner .hint { font-size: 13px; color: #334155; }
        .savings-split {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
        }
        @media (max-width: 520px) {
            .savings-split { grid-template-columns: 1fr; }
        }
        .savings-pill {
            background: #fff; border: 1px solid #d1fae5; border-radius: 12px; padding: 10px 12px;
        }
        .savings-pill .label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; margin-bottom: 2px; }
        .savings-pill .amt { font-size: 1.05rem; font-weight: 800; color: #0f766e; }
        .savings-pill .sub { display: block; font-size: 12px; color: #64748b; margin-top: 2px; }
        .line-save {
            display: inline-block; margin: 4px 6px 0 0; font-size: 11px; font-weight: 800; color: #0f766e;
            background: #ccfbf1; padding: 2px 8px; border-radius: 999px;
        }
        .line-save--discount {
            color: #b45309; background: #fef3c7;
        }
        .totals-mini .save-price { color: #0f766e; font-weight: 700; }
        .totals-mini .save-discount { color: #b45309; font-weight: 700; }
        .line {
            display: grid; grid-template-columns: 64px 1fr auto; gap: 12px; align-items: center;
            padding: 12px 0; border-bottom: 1px solid var(--line);
        }
        .line:last-child { border-bottom: 0; }
        .line-img, .line-ph {
            width: 64px; height: 64px; border-radius: 12px; object-fit: cover; background: #f1f5f9;
            border: 1px solid var(--line);
        }
        .line-ph { display: grid; place-items: center; color: #94a3b8; font-size: 11px; font-weight: 700; }
        .line-name { font-size: 14px; font-weight: 650; line-height: 1.35; margin: 0 0 4px; }
        .line-meta { font-size: 12px; color: var(--muted); }
        .line-prices { text-align: right; white-space: nowrap; }
        .line-now { font-weight: 750; font-size: 14px; }
        .line-was { display: block; font-size: 12px; color: #94a3b8; text-decoration: line-through; }
        .totals-mini { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line); font-size: 14px; }
        .totals-mini .row { display: flex; justify-content: space-between; gap: 12px; margin: 6px 0; }
        .totals-mini .row.total { font-weight: 800; font-size: 15px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <p><a class="back" href="{{ route('store.design.show', $store->slug) }}">← {{ $store->name }}</a></p>

    @if($order)
        <div class="card">
            <div class="thanks-icon" aria-hidden="true">✓</div>
            <p class="eyebrow">Pedido recibido</p>
            @php
                $graciasNombre = $order->customer_name
                    ? trim((string) \Illuminate\Support\Str::before(trim((string) $order->customer_name), ' '))
                    : '';
                $graciasTitulo = $graciasNombre !== '' ? ('¡Gracias, '.$graciasNombre.'!') : '¡Gracias!';
            @endphp
            <h1>{{ $graciasTitulo }}</h1>
            <p class="lede">
                Ya estamos trabajando en tu pedido <strong>{{ $order->number }}</strong>.
                Recibirás un correo con las indicaciones, actualizaciones del envío y cómo darle seguimiento en cualquier momento.
            </p>
            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div><strong>Confirmación por email</strong><span>Revisa {{ $order->customer_email }} (también spam).</span></div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div><strong>Preparación y envío</strong><span>Te avisamos cuando haya guía o cambio de estado.</span></div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div><strong>Seguimiento cuando quieras</strong><span>Con tu número de pedido y email puedes volver a esta página.</span></div>
                </div>
            </div>
            <div class="actions">
                @if($order->access_token)
                    <a class="btn" href="{{ route('buyer.track.enter', ['slug' => $store->slug, 'token' => $order->access_token]) }}">Entrar a mi cuenta</a>
                @endif
                <a class="btn btn-secondary" href="{{ route('store.design.show', $store->slug) }}">Seguir comprando</a>
            </div>
        </div>

        <div class="card">
            <h2>Resumen del pedido</h2>
            <div class="meta">
                <span class="pill">Pago: {{ $order->payment_status }}</span>
                <span class="pill">Envío: {{ $order->fulfillment_status }}</span>
                <span class="pill">Total: ${{ number_format((float) $order->total, 2) }} {{ $order->currency }}</span>
            </div>

            @if(!empty($orderSavings) && ($orderSavings['total_save'] ?? 0) > 0)
                <div class="savings-banner">
                    <div class="savings-head">
                        <div>
                            <span class="hint">¡Gran compra!</span><br>
                            <strong>Ahorraste ${{ number_format((float) $orderSavings['total_save'], 2) }} {{ $order->currency }}</strong>
                        </div>
                    </div>
                    <div class="savings-split">
                        <div class="savings-pill">
                            <span class="label">Por precio</span>
                            <span class="amt">−${{ number_format((float) ($orderSavings['price_save'] ?? 0), 2) }}</span>
                            <span class="sub">Precio de comparación vs precio de venta</span>
                        </div>
                        <div class="savings-pill">
                            <span class="label">Por descuento</span>
                            <span class="amt">−${{ number_format((float) ($orderSavings['discount_save'] ?? 0), 2) }}</span>
                            <span class="sub">Combos, cupones y ofertas</span>
                        </div>
                    </div>
                </div>
            @endif

            <div class="lines">
                @foreach($order->items as $item)
                    @php
                        $img = $item->imageUrl();
                        $compare = $item->compareLineTotal();
                        $priceSave = $item->priceSave();
                        $discountSave = $item->discountSave();
                        $compareUnit = $item->compareAtUnit();
                    @endphp
                    <div class="line">
                        @if($img)
                            <img class="line-img" src="{{ $img }}" alt="" loading="lazy">
                        @else
                            <div class="line-ph" aria-hidden="true">MD</div>
                        @endif
                        <div>
                            <p class="line-name">{{ $item->name }}</p>
                            <p class="line-meta">Cantidad: {{ $item->qty }}
                                @if($compareUnit)
                                    · Antes ${{ number_format($compareUnit, 2) }} c/u
                                @endif
                            </p>
                            @if($priceSave > 0)
                                <span class="line-save">−${{ number_format($priceSave, 2) }} por precio</span>
                            @endif
                            @if($discountSave > 0)
                                <span class="line-save line-save--discount">−${{ number_format($discountSave, 2) }} por descuento{{ $item->isComboLine() ? ' (combo)' : '' }}</span>
                            @endif
                        </div>
                        <div class="line-prices">
                            @if($compare && $compare > (float) $item->total)
                                <span class="line-was">${{ number_format($compare, 2) }}</span>
                            @endif
                            <span class="line-now">${{ number_format((float) $item->total, 2) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="totals-mini">
                @if(!empty($orderSavings) && ($orderSavings['list_subtotal'] ?? 0) > 0)
                    <div class="row muted"><span>Precio de comparación</span><span>${{ number_format((float) $orderSavings['list_subtotal'], 2) }}</span></div>
                @endif
                @if(!empty($orderSavings) && ($orderSavings['price_save'] ?? 0) > 0)
                    <div class="row save-price"><span>Ahorro por precio</span><span>−${{ number_format((float) $orderSavings['price_save'], 2) }}</span></div>
                @endif
                @if(!empty($orderSavings) && ($orderSavings['line_discount_save'] ?? 0) > 0)
                    <div class="row save-discount"><span>Ahorro por descuento (combo/promo)</span><span>−${{ number_format((float) $orderSavings['line_discount_save'], 2) }}</span></div>
                @endif
                @if(!empty($orderSavings) && ($orderSavings['coupon_save'] ?? 0) > 0)
                    <div class="row save-discount"><span>Cupón{{ $order->coupon_code ? ' · '.$order->coupon_code : '' }}</span><span>−${{ number_format((float) $orderSavings['coupon_save'], 2) }}</span></div>
                @endif
                @if(!empty($orderSavings) && ($orderSavings['magic_save'] ?? 0) > 0)
                    <div class="row save-discount"><span>Descuento mágico</span><span>−${{ number_format((float) $orderSavings['magic_save'], 2) }}</span></div>
                @endif
                <div class="row"><span>Subtotal</span><span>${{ number_format((float) $order->subtotal, 2) }}</span></div>
                <div class="row"><span>Envío</span><span>@if((float)$order->shipping > 0)${{ number_format((float) $order->shipping, 2) }}@else Por cotizar / incluido @endif</span></div>
                <div class="row total"><span>Total</span><span>${{ number_format((float) $order->total, 2) }} {{ $order->currency }}</span></div>
            </div>

            @forelse($order->fulfillments as $f)
                <p style="margin:14px 0 0;font-size:14px">
                    Logística: {{ $f->external_order_id ?: '—' }} · {{ $f->status }}
                    @if($f->tracking_number)
                        <br>Guía: <strong>{{ $f->tracking_number }}</strong> {{ $f->carrier ? '('.$f->carrier.')' : '' }}
                    @endif
                </p>
            @empty
                <p class="muted" style="margin:14px 0 0;font-size:14px">Aún no hay guía de envío — te avisaremos por correo.</p>
            @endforelse
        </div>

        @include('partials.platform-contact', [
            'title' => '¿Necesitas ayuda?',
            'intro' => 'Escríbenos y te atendemos. También puedes abrir un reclamo desde tu cuenta de comprador.',
            'boxClass' => 'card md-platform-contact',
            'boxStyle' => 'margin-bottom:0',
        ])
    @else
        <div class="card">
            <p class="eyebrow">Consulta</p>
            <h1>Seguimiento de pedido</h1>
            <p class="lede muted">Ingresa tu email y número de pedido. No necesitas cuenta.</p>
            <form method="post" action="{{ route('store.order.track.lookup', $store->slug) }}" style="display:grid;gap:10px">
                @csrf
                <input name="number" value="{{ old('number', $number ?? '') }}" placeholder="Número de pedido (MD-XXXX)" required>
                <input name="email" type="email" value="{{ old('email', $email ?? '') }}" placeholder="Email" required>
                <button type="submit">Ver pedido</button>
            </form>
            @if($error)
                <p class="alert">{{ $error }}</p>
            @endif
        </div>

        @include('partials.platform-contact', [
            'title' => 'Contacto',
            'intro' => 'Si no encuentras tu pedido, contáctanos con el número y el email de compra.',
            'boxClass' => 'card md-platform-contact',
        ])
    @endif
</div>
</body>
</html>
