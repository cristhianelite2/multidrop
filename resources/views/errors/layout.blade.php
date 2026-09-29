<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $status }} · {{ $title }} | Multidrop</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #172033; background: #f4f7fb; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 28px 18px; background: radial-gradient(ellipse at 50% 0%, #e2f4f1 0, #f4f7fb 52%); }
        .card { width: min(100%, 620px); padding: clamp(28px, 6vw, 56px); text-align: center; background: #fff; border: 1px solid #e4eaf1; border-radius: 24px; box-shadow: 0 24px 80px rgba(24, 39, 75, .09); }
        .brand { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 44px; color: #172033; font-size: 15px; font-weight: 800; letter-spacing: -.03em; }
        .brand-mark { display: grid; width: 32px; height: 32px; place-items: center; border-radius: 10px; color: #fff; background: #0f766e; }
        .code { margin: 0; color: #0f766e; font-size: clamp(64px, 15vw, 104px); font-weight: 800; letter-spacing: -.08em; line-height: .95; }
        h1 { margin: 20px 0 10px; font-size: clamp(23px, 5vw, 30px); letter-spacing: -.04em; }
        p { max-width: 420px; margin: 0 auto; color: #667085; font-size: 15px; line-height: 1.7; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 30px; }
        .button { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; padding: 0 19px; border: 1px solid #dbe3eb; border-radius: 12px; color: #344054; font-size: 14px; font-weight: 700; text-decoration: none; transition: transform .15s ease, background .15s ease; }
        .button:hover { transform: translateY(-1px); background: #f8fafc; }
        .button-primary { border-color: #0f766e; color: #fff; background: #0f766e; }
        .button-primary:hover { background: #115e59; }
        .foot { margin-top: 38px; color: #98a2b3; font-size: 12px; }
        @media (max-width: 420px) { .brand { margin-bottom: 34px; } .actions { flex-direction: column; } .button { width: 100%; } }
    </style>
</head>
<body>
    <main class="card">
        <a class="brand" href="{{ route('store.home') }}" aria-label="Multidrop, ir al inicio">
            <span class="brand-mark" aria-hidden="true">M</span> Multidrop
        </a>
        <p class="code" aria-label="Código {{ $status }}">{{ $status }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            <a class="button button-primary" href="{{ route('store.home') }}">Ir al inicio</a>
            <a class="button" href="javascript:history.back()">Volver a la página anterior</a>
        </div>
        <div class="foot">Si el problema continúa, inténtalo de nuevo más tarde.</div>
    </main>
</body>
</html>
