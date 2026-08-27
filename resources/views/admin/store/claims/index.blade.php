@extends('layouts.admin')

@section('title', 'Reclamos')
@section('heading', 'Reclamos')
@section('subheading', 'Mensajes de compradores sobre pedidos de '.$store->name)

@section('content')
<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.store.claims.index') }}" class="admin-btn-secondary {{ !$status ? '!bg-teal/10' : '' }}">Todos</a>
    @foreach(\App\Models\OrderClaim::STATUSES as $key => $label)
        <a href="{{ route('admin.store.claims.index', ['status' => $key]) }}" class="admin-btn-secondary {{ $status === $key ? '!bg-teal/10' : '' }}">{{ $label }}</a>
    @endforeach
</div>

<div class="admin-card overflow-hidden">
    <table class="min-w-full text-sm">
        <thead>
        <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase text-ink-soft/50">
            <th class="px-4 py-3">Asunto</th>
            <th class="px-4 py-3">Pedido</th>
            <th class="px-4 py-3">Comprador</th>
            <th class="px-4 py-3">Estado</th>
            <th class="px-4 py-3"></th>
        </tr>
        </thead>
        <tbody>
        @forelse($claims as $claim)
            <tr class="border-b border-line/70">
                <td class="px-4 py-3">{{ $claim->subject }}</td>
                <td class="px-4 py-3">
                    @if($claim->order)
                        <a class="text-teal hover:underline" href="{{ route('admin.store.orders.show', $claim->order) }}">{{ $claim->order->number }}</a>
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3">{{ $claim->buyer?->email ?? '—' }}</td>
                <td class="px-4 py-3">{{ $claim->statusLabel() }}</td>
                <td class="px-4 py-3"><a href="{{ route('admin.store.claims.show', $claim) }}" class="text-teal hover:underline">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-8 text-ink-soft/60">Sin reclamos.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $claims->links() }}</div>
@endsection
