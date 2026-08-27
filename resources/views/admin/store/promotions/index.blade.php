@extends('layouts.admin')

@section('title', 'Promociones — '.$store->name)
@section('heading', 'Promociones')
@section('subheading', 'Cupones de '.$store->name)

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.promotions.create') }}" class="admin-btn">Nuevo cupón</a>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Cupones</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Código</th>
                    <th class="px-4 py-3 font-semibold">Tipo</th>
                    <th class="px-4 py-3 font-semibold">Valor</th>
                    <th class="px-4 py-3 font-semibold">Usos</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($coupons as $coupon)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3 font-semibold">{{ $coupon->code }}</td>
                        <td class="px-4 py-3">{{ $coupon->type }}</td>
                        <td class="px-4 py-3">{{ $coupon->type === 'percent' ? $coupon->value.'%' : '$'.$coupon->value }}</td>
                        <td class="px-4 py-3">{{ $coupon->redemptions_count }}@if($coupon->max_redemptions)/{{ $coupon->max_redemptions }}@endif</td>
                        <td class="px-4 py-3">
                            <span class="admin-badge {{ $coupon->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $coupon->is_active ? 'activo' : 'inactivo' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.promotions.edit', $coupon) }}">Editar</a>
                                <form method="post" action="{{ route('admin.store.promotions.destroy', $coupon) }}" onsubmit="return confirm('¿Eliminar cupón?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-soft/60">Sin cupones.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
