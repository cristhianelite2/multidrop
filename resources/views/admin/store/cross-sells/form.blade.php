@extends('layouts.admin')

@section('title', ($rule->exists ? 'Editar' : 'Nuevo').' cross-sell')
@section('heading', $rule->exists ? 'Editar cross-sell' : 'Nuevo cross-sell')
@section('subheading', $store->name)

@section('content')
    <form method="post" action="{{ $rule->exists ? route('admin.store.cross-sells.update', $rule) : route('admin.store.cross-sells.store') }}" class="space-y-5">
        @csrf
        @if($rule->exists) @method('PUT') @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Productos</h2>
                @php $starId = $store->starProductId(); @endphp
                <p class="text-xs text-ink-soft/70">
                    Tip: el trigger suele ser el <strong class="text-ink">producto estrella</strong>;
                    el complemento es un accesorio o upsell del flagship.
                    @if($starId)
                        Estrella actual: ID {{ $starId }}.
                    @endif
                </p>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Producto trigger</label>
                    <select name="trigger_product_id" required class="admin-input">
                        <option value="">—</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('trigger_product_id', $rule->trigger_product_id ?: $starId) == $p->id)>
                                {{ $p->name }}@if($store->isStarProduct($p)) ★@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Producto complemento</label>
                    <select name="offer_product_id" required class="admin-input">
                        <option value="">—</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('offer_product_id', $rule->offer_product_id) == $p->id)>
                                {{ $p->name }}@if($store->isStarProduct($p)) ★@endif
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Regla</h2>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Prioridad</label>
                    <input type="number" name="priority" value="{{ old('priority', $rule->priority ?? 1) }}" min="1" max="99" required class="admin-input">
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true)) class="rounded border-line text-teal">
                    Activo
                </label>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button class="admin-btn">Guardar</button>
            <a href="{{ route('admin.store.cross-sells.index') }}" class="admin-btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection
