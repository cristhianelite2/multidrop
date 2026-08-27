@extends('layouts.admin')

@section('title', 'Prueba social — '.$store->name)
@section('heading', 'Prueba social')
@section('subheading', 'Toasts de compras recientes (demo coherente) en '.$store->name)

@section('content')
    <div class="mb-5">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
    </div>

    <form method="post" action="{{ route('admin.store.social-proof.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="admin-card p-5 sm:p-6 space-y-4 max-w-xl">
            <p class="text-sm text-ink-soft/70">
                Cada cierto tiempo aparece abajo un aviso pequeño: nombre, país, producto comprado y “hace X minutos”.
                Los datos son ficticios pero coherentes; usa productos reales de la tienda cuando existan.
                Activa/desactiva el plugin en <strong>General → Servicios y plugins</strong>.
            </p>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Intervalo entre toasts (segundos)</label>
                <input type="number" min="4" max="60" name="interval_seconds" value="{{ old('interval_seconds', $intervalSeconds) }}" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Duración visible (segundos)</label>
                <input type="number" min="3" max="20" name="display_seconds" value="{{ old('display_seconds', $displaySeconds) }}" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Posición</label>
                <select name="position" class="admin-input">
                    <option value="bottom-left" @selected(old('position', $position) === 'bottom-left')>Abajo izquierda</option>
                    <option value="bottom-right" @selected(old('position', $position) === 'bottom-right')>Abajo derecha</option>
                </select>
            </div>
        </div>

        <button class="admin-btn">Guardar</button>
    </form>
@endsection
