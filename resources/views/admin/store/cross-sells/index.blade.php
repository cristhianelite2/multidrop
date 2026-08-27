@extends('layouts.admin')

@section('title', 'Cross Sell — '.$store->name)
@section('heading', 'Cross Sell')
@section('subheading', $store->name)

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.cross-sells.create') }}" class="admin-btn">Nueva regla</a>
    </div>

    <form method="post" action="{{ route('admin.store.cross-sells.offer') }}" class="admin-card p-5 sm:p-6 space-y-4 max-w-3xl mb-8">
        @csrf
        @method('PUT')
        <h2 class="font-display text-base font-bold text-ink">Descuento mágico (checkout)</h2>
        <p class="text-sm text-ink-soft/70">
            Aparece <strong>arriba de Contact y Order summary</strong>. Al agregar un complemento aplica un descuento
            <strong>EXTRA</strong> además del cupón. Usa <code>{value}</code> en el texto tentador.
        </p>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $offer['enabled']))>
            Mostrar oferta mágica en checkout
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Título</label>
                <input type="text" name="headline" value="{{ old('headline', $offer['headline']) }}" class="admin-input" required maxlength="100">
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Subtítulo</label>
                <input type="text" name="subtitle" value="{{ old('subtitle', $offer['subtitle']) }}" class="admin-input" maxlength="200">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Badge</label>
                <input type="text" name="badge" value="{{ old('badge', $offer['badge']) }}" class="admin-input" maxlength="40">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto del botón</label>
                <input type="text" name="cta" value="{{ old('cta', $offer['cta']) }}" class="admin-input" required maxlength="50">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tipo descuento extra</label>
                <select name="extra_discount_type" class="admin-input">
                    <option value="percent" @selected(old('extra_discount_type', $offer['extra_discount_type']) === 'percent')>Porcentaje</option>
                    <option value="fixed" @selected(old('extra_discount_type', $offer['extra_discount_type']) === 'fixed')>Monto fijo</option>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Valor</label>
                <input type="number" step="0.01" min="1" name="extra_discount_value" value="{{ old('extra_discount_value', $offer['extra_discount_value']) }}" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Máx. productos mostrados</label>
                <input type="number" min="1" max="8" name="max_products" value="{{ old('max_products', $offer['max_products']) }}" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Caducidad (minutos)</label>
                <input type="number" min="3" max="120" name="expires_minutes" value="{{ old('expires_minutes', $offer['expires_minutes'] ?? 15) }}" class="admin-input" required>
                <p class="mt-1 text-xs text-ink-soft/60">Countdown visible en checkout (por sesión).</p>
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto tentador (usa {value})</label>
                <input type="text" name="hint" value="{{ old('hint', $offer['hint']) }}" class="admin-input" maxlength="220">
                <p class="mt-1 text-xs text-ink-soft/60">Vista previa: {{ $offer['hint_display'] }}</p>
            </div>
        </div>
        <button class="admin-btn">Guardar oferta mágica</button>
    </form>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Reglas de productos</h2>
            <p class="text-xs text-ink-soft/60 mt-1">Si no hay reglas, se sugieren otros productos de la tienda.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Trigger</th>
                    <th class="px-4 py-3 font-semibold">Complemento</th>
                    <th class="px-4 py-3 font-semibold">Prioridad</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">{{ $rule->triggerProduct?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $rule->offerProduct?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $rule->priority }}</td>
                        <td class="px-4 py-3"><span class="admin-badge {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $rule->is_active ? 'activo' : 'off' }}</span></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.cross-sells.edit', $rule) }}">Editar</a>
                                <form method="post" action="{{ route('admin.store.cross-sells.destroy', $rule) }}" onsubmit="return confirm('¿Eliminar?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-ink-soft/60">Sin reglas de cross-sell.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
