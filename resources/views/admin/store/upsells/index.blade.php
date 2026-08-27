@extends('layouts.admin')

@section('title', 'Upsell — '.$store->name)
@section('heading', 'Upsell')
@section('subheading', $store->name)

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.upsells.create') }}" class="admin-btn">Nueva regla</a>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Reglas</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Trigger</th>
                    <th class="px-4 py-3 font-semibold">Oferta</th>
                    <th class="px-4 py-3 font-semibold">Dto</th>
                    <th class="px-4 py-3 font-semibold">Posición</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($rules as $rule)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">{{ $rule->triggerProduct?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $rule->offerProduct?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $rule->discount_percent }}%</td>
                        <td class="px-4 py-3">{{ $rule->position }}</td>
                        <td class="px-4 py-3"><span class="admin-badge {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $rule->is_active ? 'activo' : 'off' }}</span></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.upsells.edit', $rule) }}">Editar</a>
                                <form method="post" action="{{ route('admin.store.upsells.destroy', $rule) }}" onsubmit="return confirm('¿Eliminar?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-soft/60">Sin reglas de upsell. @if($store->products_count ?? false)@else Asegura productos live en este sitio.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
