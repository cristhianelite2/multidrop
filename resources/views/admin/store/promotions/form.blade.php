@extends('layouts.admin')

@section('title', ($coupon->exists ? 'Editar' : 'Nuevo').' cupón')
@section('heading', $coupon->exists ? 'Editar cupón' : 'Nuevo cupón')
@section('subheading', $store->name)

@section('content')
    <form method="post" action="{{ $coupon->exists ? route('admin.store.promotions.update', $coupon) : route('admin.store.promotions.store') }}" class="space-y-5">
        @csrf
        @if($coupon->exists) @method('PUT') @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Cupón</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Código</label>
                        <input name="code" value="{{ old('code', $coupon->code) }}" required class="admin-input uppercase">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tipo</label>
                        <select name="type" class="admin-input">
                            <option value="percent" @selected(old('type', $coupon->type) === 'percent')>Porcentaje</option>
                            <option value="fixed" @selected(old('type', $coupon->type) === 'fixed')>Monto fijo</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Valor</label>
                        <input type="number" step="0.01" name="value" value="{{ old('value', $coupon->value) }}" required class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mín. subtotal</label>
                        <input type="number" step="0.01" name="min_subtotal" value="{{ old('min_subtotal', $coupon->min_subtotal) }}" class="admin-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Máx. redenciones</label>
                        <input type="number" name="max_redemptions" value="{{ old('max_redemptions', $coupon->max_redemptions) }}" class="admin-input">
                    </div>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active ?? true)) class="rounded border-line text-teal">
                    Activo
                </label>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Vigencia</h2>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Inicia</label>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', optional($coupon->starts_at)->format('Y-m-d\TH:i')) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Termina</label>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', optional($coupon->ends_at)->format('Y-m-d\TH:i')) }}" class="admin-input">
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button class="admin-btn">Guardar</button>
            <a href="{{ route('admin.store.promotions.index') }}" class="admin-btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection
