<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pago — {{ $store->name }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .box { max-width: 560px; width: calc(100% - 32px); margin: 32px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 22px 18px; box-sizing: border-box; }
        a { color: #0f766e; }
        .btn { display:inline-block;margin-top:12px;background:#0f766e;color:#fff;text-decoration:none;padding:12px 16px;border-radius:10px;font-weight:700;min-height:44px;box-sizing:border-box }
    </style>
</head>
<body>
<div class="box">
    <h1>
        @if($status === 'success') ¡Gracias por tu compra!
        @elseif($status === 'failure') Pago no completado
        @else Pago pendiente
        @endif
    </h1>
    @if($order)
        <p>Pedido <strong>{{ $order->number }}</strong></p>
        <p>Estado de pago: {{ $order->payment_status }}</p>
        @if($status === 'success')
            <p>Ya estamos trabajando en tu pedido. Te enviaremos un correo con indicaciones, actualizaciones y cómo darle seguimiento en cualquier momento.</p>
            <p><a class="btn" href="{{ route('store.order.track', $store->slug) }}?number={{ $order->number }}&email={{ urlencode($order->customer_email) }}">Ver agradecimiento y seguimiento</a></p>
        @else
            <p><a href="{{ route('store.order.track', $store->slug) }}?number={{ $order->number }}&email={{ urlencode($order->customer_email) }}">Seguir mi pedido</a></p>
        @endif
    @else
        <p>No encontramos el pedido. Revisa tu correo o el número de orden.</p>
    @endif
    @include('partials.platform-contact', [
        'title' => 'Contacto',
        'intro' => null,
        'boxClass' => 'md-platform-contact',
        'boxStyle' => 'margin-top:20px;padding:14px;border-radius:12px;background:#f8fafc;border:1px dashed #cbd5e1',
    ])
    <p style="margin-top:20px"><a href="{{ route('store.design.show', $store->slug) }}">Volver a la tienda</a></p>
</div>
</body>
</html>
