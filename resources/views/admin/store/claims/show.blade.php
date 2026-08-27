@extends('layouts.admin')

@section('title', 'Reclamo #'.$claim->id)
@section('heading', $claim->subject)
@section('subheading', ($claim->order?->number ?? '').' · '.$claim->buyer?->email)

@section('content')
<div class="mb-4 flex flex-wrap gap-2">
    <a href="{{ route('admin.store.claims.index') }}" class="admin-btn-secondary">← Reclamos</a>
    @if($claim->order)
        <a href="{{ route('admin.store.orders.show', $claim->order) }}" class="admin-btn-secondary">Ver pedido</a>
    @endif
</div>

<div class="admin-blocks">
    <div class="admin-card p-5 space-y-3">
        <h2 class="font-display text-lg font-bold">Mensaje del comprador</h2>
        <p class="text-sm text-ink-soft/60">{{ $claim->created_at?->format('d/m/Y H:i') }}</p>
        <p class="whitespace-pre-wrap">{{ $claim->body }}</p>
        @if($buyer)
            <p class="text-sm">Cuenta comprador: <strong>{{ $buyer->email }}</strong> {{ $buyer->name ? '· '.$buyer->name : '' }}</p>
        @endif
    </div>
    <div class="admin-card p-5 space-y-4">
        <h2 class="font-display text-lg font-bold">Responder</h2>
        <form method="post" action="{{ route('admin.store.claims.update', $claim) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Estado</label>
                <select name="status" class="admin-input" required>
                    @foreach(\App\Models\OrderClaim::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected(old('status', $claim->status) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Nota / respuesta (visible al comprador)</label>
                <textarea name="admin_note" rows="6" class="admin-input">{{ old('admin_note', $claim->admin_note) }}</textarea>
            </div>
            <button class="admin-btn" type="submit">Guardar</button>
        </form>
    </div>
</div>
@endsection
