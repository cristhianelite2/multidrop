@extends('layouts.admin')

@section('title', 'Urgencia — '.$store->name)
@section('heading', 'Urgencia')
@section('subheading', 'Timer y barra superior de '.$store->name)

@section('content')
    <div class="mb-5">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
    </div>

    <form method="post" action="{{ route('admin.store.urgency.update') }}" class="space-y-5">
        @csrf
        @method('PUT')

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Textos</h2>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre de la oferta flash</label>
                    <input name="name" value="{{ old('name', $offer->name) }}" required class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto barra de urgencia</label>
                    <input name="bar_text" value="{{ old('bar_text', $barText) }}" class="admin-input" placeholder="Oferta por tiempo limitado">
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Timer y stock</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Inicia</label>
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', optional($offer->starts_at)->format('Y-m-d\TH:i')) }}" class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Termina (timer)</label>
                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', optional($offer->ends_at)->format('Y-m-d\TH:i')) }}" class="admin-input">
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Umbral stock bajo</label>
                    <input type="number" name="stock_threshold" value="{{ old('stock_threshold', $offer->stock_threshold) }}" class="admin-input">
                </div>
                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $offer->is_active ?? true)) class="rounded border-line text-teal">
                        Urgencia activa
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                        <input type="hidden" name="show_stock" value="0">
                        <input type="checkbox" name="show_stock" value="1" @checked(old('show_stock', $showStock)) class="rounded border-line text-teal">
                        Mostrar aviso de stock
                    </label>
                </div>
            </div>
        </div>

        <button class="admin-btn">Guardar urgencia</button>
    </form>
@endsection
