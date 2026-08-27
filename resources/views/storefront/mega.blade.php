<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mega tienda — Multidrop</title>
    <style>
        body {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            background: linear-gradient(160deg, #1e293b 0%, #0f172a 40%, #134e4a 100%);
            color: #f8fafc;
            min-height: 100vh;
        }
        .hero {
            padding: 64px 24px 40px;
            max-width: 960px;
            margin: 0 auto;
        }
        h1 { font-size: clamp(2rem, 5vw, 3.4rem); margin: 0 0 12px; letter-spacing: -0.02em; }
        p { color: #cbd5e1; font-size: 1.1rem; max-width: 40rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            max-width: 960px;
            margin: 0 auto;
            padding: 0 24px 64px;
        }
        .card {
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 18px;
            padding: 20px;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(8px);
        }
        .sector { text-transform: uppercase; letter-spacing: .12em; font-size: 12px; color: #5eead4; font-family: system-ui, sans-serif; }
        a { color: #99f6e4; }
        .admin { font-family: system-ui, sans-serif; font-size: 13px; opacity: .7; }
    </style>
</head>
<body>
    <div class="hero">
        <div class="admin"><a href="{{ route('admin.lab.dashboard') }}">Lab interno</a></div>
        <h1>Mega tienda</h1>
        <p>Mini-tiendas agrupadas por sector, idioma y necesidad. Cada una puede migrar después a dominio propio sin reconstruir el catálogo.</p>
    </div>
    <div class="grid">
        @forelse($miniStores as $store)
            <div class="card">
                <div class="sector">{{ $store->sector ?: 'general' }}</div>
                <h2 style="margin:8px 0">{{ $store->name }}</h2>
                <p style="margin:0;color:#94a3b8">/{{ $store->slug }} · {{ $store->status }}</p>
            </div>
        @empty
            <div class="card">Aún no hay mini-tiendas. Ejecuta el seeder.</div>
        @endforelse
    </div>
</body>
</html>
