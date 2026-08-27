@extends('layouts.buyer')

@section('title', 'Perfil')
@section('heading', 'Perfil')
@section('subheading', 'Datos de contacto de tu cuenta comprador')

@section('content')
<div class="buyer-card">
    <form method="post" action="{{ route('buyer.profile.update') }}" style="display:grid;gap:12px;max-width:420px">
        @csrf
        @method('PUT')
        <div>
            <label class="buyer-muted">Email (de tus pedidos)</label>
            <input class="buyer-input" value="{{ $buyer->email }}" disabled>
        </div>
        <div>
            <label class="buyer-muted">Nombre</label>
            <input class="buyer-input" name="name" value="{{ old('name', $buyer->name) }}">
        </div>
        <div>
            <label class="buyer-muted">Teléfono</label>
            <input class="buyer-input" name="phone" value="{{ old('phone', $buyer->phone) }}">
        </div>
        <button class="buyer-btn" type="submit">Guardar</button>
    </form>
</div>
@php
    $recent = $buyer->ordersQuery()->first();
    $addr = $recent?->shipping_address ?? [];
@endphp
@if($recent)
<div class="buyer-card">
    <h2 style="margin:0 0 8px;font-size:1.05rem">Dirección reciente</h2>
    <p class="buyer-muted" style="margin:0">
        {{ $addr['address'] ?? '—' }}<br>
        {{ $addr['city'] ?? '' }} {{ $addr['state'] ?? '' }} {{ $addr['zip'] ?? '' }}<br>
        {{ $addr['country'] ?? '' }}
    </p>
</div>
@endif
@endsection
