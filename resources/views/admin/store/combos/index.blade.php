@extends('layouts.admin')

@section('title', 'Combos — '.$store->name)
@section('heading', 'Combos')
@section('subheading', 'Packs de piezas o productos con precio especial · '.$store->name)

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.combos.create') }}" class="admin-btn">Nuevo combo</a>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Combos</h2>
            <p class="mt-0.5 text-xs text-ink-soft/60">Si publicas como producto, aparece en el catálogo con sus propias imágenes.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Combo</th>
                    <th class="px-4 py-3 font-semibold">Estrategia</th>
                    <th class="px-4 py-3 font-semibold">Productos</th>
                    <th class="px-4 py-3 font-semibold">Descuento</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($combos as $combo)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3 min-w-0">
                                @if($combo->coverImage())
                                    <img src="{{ $combo->coverImage() }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover border border-line">
                                @endif
                                <div class="min-w-0">
                                    <div class="font-semibold text-ink truncate max-w-[16rem]">{{ $combo->name }}</div>
                                    <div class="text-xs text-ink-soft/55">/{{ $combo->slug }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($combo->strategy === 'qty') Comprar {{ $combo->qty_min }} piezas
                            @elseif($combo->strategy === 'pair') Comprar X e Y
                            @else Ambas ({{ $combo->qty_min }} + mix)
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-ink-soft">
                            {{ $combo->items->pluck('product.name')->filter()->join(', ') ?: '—' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            @if($combo->discount_type === 'percent')
                                {{ rtrim(rtrim(number_format((float) $combo->discount_value, 2), '0'), '.') }}%
                            @else
                                {{ number_format((float) $combo->discount_value, 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="admin-badge {{ $combo->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $combo->is_active ? 'activo' : 'off' }}
                            </span>
                            @if($combo->publish_as_product)
                                <span class="admin-badge bg-teal/10 text-teal">producto</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.combos.edit', $combo) }}">Editar</a>
                                <form method="post" action="{{ route('admin.store.combos.destroy', $combo) }}" onsubmit="return confirm('¿Eliminar este combo y su producto de vitrina?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-soft/60">Aún no hay combos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
