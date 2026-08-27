@extends('layouts.buyer')

@section('title', 'Seguridad')
@section('heading', 'Seguridad')
@section('subheading', 'Contraseña opcional para entrar sin el número de pedido')

@section('content')
<div class="buyer-card" style="max-width:440px">
    @if($buyer->hasPassword())
        <p class="buyer-muted" style="margin-top:0">Ya tienes contraseña. Puedes cambiarla aquí.</p>
    @else
        <p class="buyer-muted" style="margin-top:0">Crea una contraseña para iniciar sesión solo con email.</p>
    @endif
    <form method="post" action="{{ route('buyer.security.password') }}" style="display:grid;gap:12px">
        @csrf
        @method('PUT')
        @if($buyer->hasPassword())
            <div>
                <label class="buyer-muted">Contraseña actual</label>
                <input class="buyer-input" type="password" name="current_password" required autocomplete="current-password">
            </div>
        @endif
        <div>
            <label class="buyer-muted">Nueva contraseña</label>
            <input class="buyer-input" type="password" name="password" required minlength="8" autocomplete="new-password">
        </div>
        <div>
            <label class="buyer-muted">Confirmar</label>
            <input class="buyer-input" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </div>
        <button class="buyer-btn" type="submit">{{ $buyer->hasPassword() ? 'Actualizar' : 'Crear contraseña' }}</button>
    </form>
</div>
@endsection
