@extends('layouts.buyer')

@section('title', 'Reclamos')
@section('heading', 'Reclamos')
@section('subheading', 'Reporta un problema con un pedido sandbox')

@section('content')
<div class="buyer-card">
    <h2 style="margin:0 0 12px;font-size:1.05rem">Nuevo reclamo</h2>
    <form method="post" action="{{ route('theme.sandbox.cuenta.claims.store', $theme->slug) }}" style="display:grid;gap:12px;max-width:520px">
        @csrf
        <div>
            <label class="buyer-muted">Pedido</label>
            <select class="buyer-input" name="order_id" required>
                <option value="">Selecciona…</option>
                @foreach($orders as $order)
                    <option value="{{ $order->id }}" @selected((string)old('order_id', request('order')) === (string)$order->id)>
                        {{ $order->number }} — {{ $theme->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="buyer-muted">Asunto</label>
            <input class="buyer-input" name="subject" value="{{ old('subject') }}" required maxlength="160">
        </div>
        <div>
            <label class="buyer-muted">Detalle</label>
            <textarea class="buyer-input" name="body" rows="5" required maxlength="5000">{{ old('body') }}</textarea>
        </div>
        <button class="buyer-btn" type="submit">Enviar reclamo</button>
    </form>
</div>

<div class="buyer-card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead>
        <tr style="background:#f8fafc;text-align:left">
            <th style="padding:12px 16px">Asunto</th>
            <th style="padding:12px 16px">Pedido</th>
            <th style="padding:12px 16px">Estado</th>
            <th style="padding:12px 16px"></th>
        </tr>
        </thead>
        <tbody>
        @forelse($claims as $claim)
            <tr style="border-top:1px solid #e2e8f0">
                <td style="padding:12px 16px">{{ $claim['subject'] }}</td>
                <td style="padding:12px 16px">{{ $claim['order_number'] ?? '—' }}</td>
                <td style="padding:12px 16px">{{ $claim['status'] ?? 'open' }}</td>
                <td style="padding:12px 16px"><a href="{{ route('theme.sandbox.cuenta.claims.show', [$theme->slug, $claim['id']]) }}">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="4" style="padding:20px" class="buyer-muted">Sin reclamos.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
