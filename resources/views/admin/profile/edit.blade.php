@extends('layouts.admin')

@section('title', 'Mi perfil — Multidrop')
@section('heading', 'Mi perfil')
@section('subheading', 'Datos de acceso y seguridad')

@section('content')
    <div class="admin-blocks">
        <div class="admin-card p-5 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink mb-4">Datos</h2>
            <form method="post" action="{{ route('admin.profile.update') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                    <input id="name" name="name" value="{{ old('name', $user->name) }}" required class="admin-input">
                </div>
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-ink-soft">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required class="admin-input">
                </div>
                <div>
                    <label for="phone" class="mb-1.5 block text-sm font-medium text-ink-soft">Teléfono</label>
                    <input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="admin-input">
                </div>
                <button type="submit" class="admin-btn">Guardar perfil</button>
            </form>
        </div>

        <div class="admin-card p-5 sm:p-6">
            <h2 class="font-display text-lg font-bold text-ink mb-4">Contraseña</h2>
            <form method="post" action="{{ route('admin.profile.password') }}" class="space-y-4">
                @csrf
                @method('PUT')
                <div>
                    <label for="current_password" class="mb-1.5 block text-sm font-medium text-ink-soft">Contraseña actual</label>
                    <input id="current_password" type="password" name="current_password" required class="admin-input">
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-ink-soft">Nueva contraseña</label>
                    <input id="password" type="password" name="password" required class="admin-input">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-ink-soft">Confirmar</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required class="admin-input">
                </div>
                <button type="submit" class="admin-btn">Cambiar contraseña</button>
            </form>
        </div>
        <div class="admin-card p-5 sm:p-6 admin-card-span-2">
        <h2 class="font-display text-lg font-bold text-ink mb-3">Accesos actuales</h2>
        @if($user->is_superuser)
            <p class="mb-3"><span class="admin-badge bg-amber/15 text-amber">superusuario</span> Bypass total de permisos.</p>
        @endif
        <p class="text-sm text-ink-soft/60 mb-2">Roles</p>
        <div class="flex flex-wrap gap-2 mb-4">
            @forelse($user->roles as $role)
                <span class="admin-badge bg-teal/10 text-teal">{{ $role->name }}</span>
            @empty
                <span class="text-sm text-ink-soft/50">Sin roles asignados</span>
            @endforelse
        </div>
        <p class="text-sm text-ink-soft/60">
            Último login: {{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}
            ({{ $user->last_login_ip ?? '—' }})
        </p>
        </div>
    </div>
@endsection
