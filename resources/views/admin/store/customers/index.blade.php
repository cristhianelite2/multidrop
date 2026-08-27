@extends('layouts.admin')

@section('title', 'Clientes — '.$store->name)
@section('heading', 'Clientes')
@section('subheading', 'Lista para promociones de «'.$store->name.'»')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
        <a href="{{ route('admin.store.customers.export') }}" class="admin-btn">Exportar CSV</a>
    </div>

    <div class="admin-blocks">
    @if($coupons->isNotEmpty())
        <div class="admin-card p-4 text-sm text-ink-soft/80">
            <h2 class="font-display text-base font-bold text-ink mb-2">Cupones activos</h2>
            Cupones para copiar en campañas:
            @foreach($coupons as $c)
                <code class="mx-1 rounded bg-mist px-1.5 py-0.5 font-semibold text-ink">{{ $c->code }}</code>
            @endforeach
        </div>
    @endif

    <div class="admin-card overflow-hidden {{ ($coupons ?? collect())->isEmpty() ? 'admin-card-span-2' : '' }}">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Clientes</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Email</th>
                    <th class="px-4 py-3 font-semibold">Nombre</th>
                    <th class="px-4 py-3 font-semibold">Pedidos</th>
                    <th class="px-4 py-3 font-semibold">Gastado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($customers as $customer)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3 font-semibold">{{ $customer->email }}</td>
                        <td class="px-4 py-3">{{ $customer->name }}</td>
                        <td class="px-4 py-3">{{ $customer->orders_count }}</td>
                        <td class="px-4 py-3">${{ number_format((float) ($customer->spent ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.customers.show', $customer) }}">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-ink-soft/60">Sin clientes todavía.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $customers->links() }}</div>
    </div>
    </div>
@endsection
