<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Cupón listo' : 'Confirmación' }} — {{ $store->name }}</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:system-ui,sans-serif; background:linear-gradient(160deg,#0f172a,#134e4a); color:#f8fafc; padding:24px; }
        .card { max-width:420px; width:100%; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18); border-radius:20px; padding:28px 24px; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.35); }
        h1 { margin:0 0 10px; font-size:1.45rem; }
        p { margin:0 0 12px; opacity:.9; line-height:1.45; }
        .code { display:inline-block; margin:12px 0; padding:12px 16px; border-radius:12px; background:rgba(0,0,0,.28); font:800 1.25rem/1.2 ui-monospace,monospace; letter-spacing:.06em; }
        a.btn { display:inline-block; margin-top:10px; padding:12px 20px; border-radius:999px; background:#f59e0b; color:#0f172a; font-weight:800; text-decoration:none; }
        .muted { font-size:.85rem; opacity:.75; }
    </style>
</head>
<body>
<div class="card">
    @if($ok)
        <h1>¡Suscripción confirmada!</h1>
        <p>{{ $message }}</p>
        @if($couponCode)
            <div class="code">{{ $couponCode }}</div>
            <p>
                @if($couponHint)<strong>{{ $couponHint }}</strong>@endif
                @if($days) · válido {{ $days }} días@endif
                @if($expires)<br><span class="muted">Hasta {{ $expires }}</span>@endif
            </p>
            <p class="muted">También te lo enviamos por correo.</p>
        @endif
        <p><a class="btn" href="{{ $shopUrl }}">Ir a la tienda</a></p>
    @else
        <h1>No se pudo confirmar</h1>
        <p>{{ $message }}</p>
        <p><a class="btn" href="{{ $shopUrl }}">Volver</a></p>
    @endif
</div>
</body>
</html>
