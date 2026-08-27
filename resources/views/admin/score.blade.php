@extends('layouts.admin')

@section('title', 'Product Score')
@section('heading', 'Product Score')
@section('subheading', 'Scoring rápido de margen y fit')

@section('content')
    <div class="admin-blocks">
    <div class="admin-card p-5 sm:p-6">
        <h2 class="font-display text-lg font-bold text-ink mb-4">Parámetros</h2>
        <form method="get" action="{{ route('admin.lab.score') }}" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Precio venta</label>
                    <input type="number" step="0.01" name="sell" value="{{ $inputs['sell'] ?? 599 }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Costo producto</label>
                    <input type="number" step="0.01" name="cost" value="{{ $inputs['cost'] ?? 180 }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Costo envío</label>
                    <input type="number" step="0.01" name="ship" value="{{ $inputs['ship'] ?? 90 }}" class="admin-input">
                </div>
            </div>
            <button type="submit" class="admin-btn">Calcular score</button>
        </form>
    </div>

    @isset($score)
        <div class="admin-card p-5 sm:p-6">
            <div class="flex flex-wrap items-center gap-3 mb-3">
                <h2 class="font-display text-2xl font-bold text-ink">Score: {{ $score['score'] }}</h2>
                <span class="admin-badge bg-teal/10 text-teal">{{ $score['band'] }}</span>
            </div>
            <p class="text-sm text-ink-soft/70 mb-4">Margen normalizado: {{ round($margin, 1) }}</p>
            <pre class="overflow-auto rounded-xl bg-ink p-4 text-xs text-amber-100">{{ json_encode($score['breakdown'], JSON_PRETTY_PRINT) }}</pre>
        </div>
    @else
        <div class="admin-card p-5 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink mb-2">Resultado</h2>
            <p class="text-sm text-ink-soft/65">Completa los costos y pulsa calcular.</p>
        </div>
    @endisset
    </div>
@endsection
