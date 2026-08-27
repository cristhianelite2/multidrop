@extends('layouts.admin')

@section('title', 'Cookies — '.$store->name)
@section('heading', 'Cookies')
@section('subheading', 'Banner de consentimiento UE en '.$store->name)

@section('content')
    <div class="mb-5">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
    </div>

    <form method="post" action="{{ route('admin.store.cookies.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="admin-card p-5 sm:p-6 space-y-4 max-w-2xl">
            <p class="text-sm text-ink-soft/70">
                El visitante ve un banner con <strong>Aceptar todo</strong>, <strong>Rechazar opcionales</strong> (igual de visible) y <strong>Configurar</strong>.
                Google Analytics y Meta Pixel no se cargan hasta que acepte la categoría correspondiente.
                Si apagas este plugin en <strong>General → Servicios y plugins</strong>, los píxeles vuelven a cargar al instante (tiendas fuera de la UE).
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Título</label>
                    <input type="text" name="title" value="{{ old('title', $cfg['title']) }}" class="admin-input" required maxlength="80">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto</label>
                    <textarea name="body" class="admin-input" rows="3" required maxlength="400">{{ old('body', $cfg['body']) }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL de la política de cookies</label>
                    <input type="text" name="policy_url" value="{{ old('policy_url', $cfg['policy_url']) }}" class="admin-input" maxlength="300" placeholder="/s/{{ $store->slug }}/pages/cookies o https://…">
                    <p class="mt-1 text-xs text-ink-soft/60">Opcional. Enlace interno (empieza con /) o URL completa.</p>
                </div>
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-4 max-w-2xl">
            <h3 class="font-semibold text-ink-soft">Botones</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Aceptar todo</label>
                    <input type="text" name="accept_label" value="{{ old('accept_label', $cfg['accept_label']) }}" class="admin-input" required maxlength="40">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Rechazar opcionales</label>
                    <input type="text" name="reject_label" value="{{ old('reject_label', $cfg['reject_label']) }}" class="admin-input" required maxlength="40">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Configurar</label>
                    <input type="text" name="configure_label" value="{{ old('configure_label', $cfg['configure_label']) }}" class="admin-input" required maxlength="40">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Guardar preferencias</label>
                    <input type="text" name="save_label" value="{{ old('save_label', $cfg['save_label']) }}" class="admin-input" required maxlength="40">
                </div>
            </div>
        </div>

        <div class="admin-card p-5 sm:p-6 space-y-4 max-w-2xl">
            <h3 class="font-semibold text-ink-soft">Categorías en el banner</h3>
            <p class="text-sm text-ink-soft/70">
                Necesarias siempre está activa. Si no hay ID de GA o Pixel en General de plataforma, esa categoría se oculta sola.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Etiqueta necesarias</label>
                    <input type="text" name="necessary_label" value="{{ old('necessary_label', $cfg['necessary_label']) }}" class="admin-input" maxlength="40">
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" name="analytics_enabled" value="1" @checked(old('analytics_enabled', $cfg['analytics_enabled']))>
                    Mostrar categoría Analítica (Google Analytics)
                </label>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Etiqueta analítica</label>
                    <input type="text" name="analytics_label" value="{{ old('analytics_label', $cfg['analytics_label']) }}" class="admin-input" maxlength="40">
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" name="marketing_enabled" value="1" @checked(old('marketing_enabled', $cfg['marketing_enabled']))>
                    Mostrar categoría Marketing (Meta Pixel)
                </label>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Etiqueta marketing</label>
                    <input type="text" name="marketing_label" value="{{ old('marketing_label', $cfg['marketing_label']) }}" class="admin-input" maxlength="40">
                </div>
            </div>
        </div>

        <button class="admin-btn">Guardar</button>
    </form>
@endsection
