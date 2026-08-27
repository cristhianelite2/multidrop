<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Entrar — Mi cuenta Multidrop</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { margin:0; font-family:Outfit,system-ui,sans-serif; background:linear-gradient(160deg,#0f172a,#134e4a 55%,#f8fafc 55%); min-height:100vh; color:#0f172a; }
        .box { max-width:420px; margin:48px auto; background:#fff; border-radius:18px; padding:28px; border:1px solid #e2e8f0; box-shadow:0 20px 50px rgba(15,23,42,.12); }
        h1 { margin:0 0 8px; font-size:1.4rem; }
        .muted { color:#64748b; font-size:14px; margin:0 0 18px; }
        label { display:block; font-size:13px; font-weight:600; margin:12px 0 6px; }
        input { width:100%; box-sizing:border-box; padding:11px 12px; border:1px solid #cbd5e1; border-radius:10px; font:inherit; }
        button { width:100%; margin-top:16px; background:#0f766e; color:#fff; border:0; border-radius:10px; padding:12px; font-weight:700; cursor:pointer; }
        .tabs { display:flex; gap:8px; margin-bottom:12px; }
        .tabs button { background:#f1f5f9; color:#0f172a; }
        .tabs button.is-on { background:#0f766e; color:#fff; }
        .err { background:#fef2f2; color:#991b1b; padding:10px; border-radius:10px; font-size:14px; margin-bottom:12px; }
        .ok { background:#ecfdf5; color:#065f46; padding:10px; border-radius:10px; font-size:14px; margin-bottom:12px; }
        .panel { display:none; } .panel.is-on { display:block; }
    </style>
    @if(!empty($turnstileSiteKey))
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
</head>
<body>
<div class="box">
    <h1>Mi cuenta</h1>
    <p class="muted">Entra con tu email y el número de pedido. Luego puedes crear una contraseña propia.</p>
    @if(session('error'))<div class="err">{{ session('error') }}</div>@endif
    @if(session('success'))<div class="ok">{{ session('success') }}</div>@endif

    <div class="tabs">
        <button type="button" class="is-on" data-tab="order">Con mi pedido</button>
        <button type="button" data-tab="password">Con contraseña</button>
    </div>

    <form method="post" action="{{ route('buyer.login.attempt') }}" id="login-order" class="panel is-on">
        @csrf
        <input type="hidden" name="mode" value="order">
        <label>Email de compra</label>
        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        <label>Número de pedido</label>
        <input name="order_number" value="{{ old('order_number') }}" required placeholder="MD-XXXXXXXX" autocomplete="off">
        @if(!empty($turnstileSiteKey))
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" style="margin-top:12px"></div>
        @endif
        <button type="submit">Entrar</button>
    </form>

    <form method="post" action="{{ route('buyer.login.attempt') }}" id="login-password" class="panel">
        @csrf
        <input type="hidden" name="mode" value="password">
        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        <label>Contraseña</label>
        <input type="password" name="password" required autocomplete="current-password">
        @if(!empty($turnstileSiteKey))
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" style="margin-top:12px"></div>
        @endif
        <button type="submit">Entrar</button>
    </form>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$('[data-tab]').on('click', function () {
  var t = $(this).data('tab');
  $('[data-tab]').removeClass('is-on');
  $(this).addClass('is-on');
  $('.panel').removeClass('is-on');
  $('#login-' + t).addClass('is-on');
});
</script>
</body>
</html>
