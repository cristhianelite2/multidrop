@extends('layouts.admin')

@section('title', ($rule->exists ? 'Editar' : 'Nueva').' upsell')
@section('heading', $rule->exists ? 'Editar upsell' : 'Nueva upsell')
@section('subheading', $store->name)

@section('content')
    <form method="post" action="{{ $rule->exists ? route('admin.store.upsells.update', $rule) : route('admin.store.upsells.store') }}" class="space-y-5">
        @csrf
        @if($rule->exists) @method('PUT') @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Productos</h2>
                @php $starId = $store->starProductId(); @endphp
                <p class="text-xs text-ink-soft/70">
                    Tip: en mini-tiendas el trigger suele ser el
                    <strong class="text-ink">producto estrella</strong>
                    (combos / upsell giran alrededor de él).
                    @if($starId)
                        Estrella actual: ID {{ $starId }}.
                    @endif
                </p>
                @if($products->isEmpty())
                    <div class="rounded-xl border border-amber/30 bg-amber/10 px-3 py-2 text-sm text-amber">
                        Este sitio no tiene productos. Crea productos primero o administra la mega si el catálogo vive ahí.
                    </div>
                @endif
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Producto trigger</label>
                    <select name="trigger_product_id" required class="admin-input">
                        <option value="">—</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('trigger_product_id', $rule->trigger_product_id ?: $starId) == $p->id)>
                                {{ $p->name }} ({{ $p->status }})@if($store->isStarProduct($p)) ★@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Producto oferta</label>
                    <select name="offer_product_id" required class="admin-input">
                        <option value="">—</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('offer_product_id', $rule->offer_product_id) == $p->id)>
                                {{ $p->name }} ({{ $p->status }})@if($store->isStarProduct($p)) ★@endif
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Oferta</h2>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Descuento %</label>
                    <input type="number" step="0.01" name="discount_percent" value="{{ old('discount_percent', $rule->discount_percent) }}" required class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Posición</label>
                    <select name="position" class="admin-input">
                        <option value="pre_pay" @selected(old('position', $rule->position) === 'pre_pay')>Pre-pago</option>
                        <option value="post_pay" @selected(old('position', $rule->position) === 'post_pay')>Post-pago</option>
                    </select>
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
            <a href="{{ route('admin.store.upsells.index') }}" class="admin-btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection
