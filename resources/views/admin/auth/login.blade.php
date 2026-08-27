<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — Multidrop Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen admin-shell flex items-center justify-center p-4 sm:p-8">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <div class="font-display text-4xl font-extrabold tracking-tight text-ink">Multidrop</div>
            <p class="mt-2 text-sm font-medium uppercase tracking-[0.2em] text-teal">Admin Lab</p>
        </div>

        <div class="admin-card p-6 sm:p-8">
            <h1 class="font-display text-2xl font-bold text-ink">Iniciar sesión</h1>
            <p class="mt-1 text-sm text-ink-soft/65">Acceso al laboratorio interno y mini-sitios.</p>

            @if($errors->any())
                <div class="mt-4 rounded-xl border border-coral/20 bg-coral/10 px-3 py-2.5 text-sm text-coral">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            @if($cloudflareRequired)
                <div class="mt-5 rounded-xl border border-amber/30 bg-amber/10 px-3 py-3 text-sm text-amber">
                    Cloudflare Access está en modo <strong>required</strong>. Autentícate en Zero Trust; el login local está deshabilitado.
                </div>
            @else
                <form method="post" action="{{ route('admin.login.attempt') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium text-ink-soft">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="admin-input">
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-ink-soft">Contraseña</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password" class="admin-input">
                    </div>
                    <label class="flex items-center gap-2 text-sm text-ink-soft">
                        <input id="remember" type="checkbox" name="remember" value="1" class="rounded border-line text-teal focus:ring-teal-bright">
                        Recordarme
                    </label>
                    <button type="submit" class="admin-btn w-full">Entrar al panel</button>
                </form>
            @endif

            <p class="mt-5 text-xs leading-relaxed text-ink-soft/60">
                @if($cloudflareEnabled)
                    Cloudflare Access: <strong class="text-teal">activo</strong> ({{ $cloudflareRequired ? 'required' : 'optional' }}).
                @else
                    Cloudflare Access: desactivado. Actívalo con <code class="rounded bg-mist px-1">CLOUDFLARE_ACCESS_ENABLED=true</code>.
                @endif
            </p>
        </div>
    </div>
</body>
</html>
