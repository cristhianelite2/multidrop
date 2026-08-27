@extends('layouts.admin')

@section('title', ($user->exists ? 'Editar' : 'Crear').' admin — Multidrop')
@section('heading', $user->exists ? 'Editar administrador' : 'Nuevo administrador')
@section('subheading', 'Asigna roles y permisos directos')

@section('content')
    <form method="post" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-5">
        @csrf
        @if($user->exists)
            @method('PUT')
        @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-5">
                <h2 class="font-display text-lg font-bold text-ink">Datos</h2>
                <div class="grid gap-4 sm:grid-cols-2">
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
                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-ink-soft">Contraseña {{ $user->exists ? '(opcional)' : '' }}</label>
                        <input id="password" type="password" name="password" @if(!$user->exists) required @endif class="admin-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-ink-soft">Confirmar contraseña</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" @if(!$user->exists) required @endif class="admin-input">
                    </div>
                </div>
                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true)) class="rounded border-line text-teal">
                        Cuenta activa
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                        <input type="hidden" name="must_change_password" value="0">
                        <input type="checkbox" name="must_change_password" value="1" @checked(old('must_change_password', $user->must_change_password ?? true)) class="rounded border-line text-teal">
                        Debe cambiar contraseña
                    </label>
                    @if(auth()->user()->isSuperuser())
                        <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                            <input type="hidden" name="is_superuser" value="0">
                            <input type="checkbox" name="is_superuser" value="1" @checked(old('is_superuser', $user->is_superuser)) class="rounded border-line text-teal">
                            Superusuario
                        </label>
                    @endif
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-3">
                <h2 class="font-display text-lg font-bold text-ink">Roles</h2>
                <div class="grid gap-2">
                    @foreach($roles as $role)
                        <label class="flex gap-3 rounded-xl border border-line bg-mist/40 p-3 text-sm">
                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="mt-1 rounded border-line text-teal"
                                   @checked(in_array($role->id, old('roles', $selectedRoles), true))>
                            <span>
                                <span class="font-semibold text-ink">{{ $role->name }}</span>
                                <span class="text-ink-soft/50"> ({{ $role->slug }})</span>
                                @if($role->description)
                                    <span class="mt-1 block text-xs text-ink-soft/60">{{ $role->description }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-3 admin-card-span-2">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink">Permisos directos</h2>
                    <p class="text-sm text-ink-soft/60">Opcional. Se suman a los del rol.</p>
                </div>
                @foreach($permissions as $group => $items)
                    <h3 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-[0.14em] text-ink-soft/50">{{ $group }}</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($items as $permission)
                            <label class="flex gap-2 rounded-xl border border-line px-3 py-2 text-sm">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" class="rounded border-line text-teal"
                                       @checked(in_array($permission->id, old('permissions', $selectedPermissions), true))>
                                <span>{{ $permission->name }} <span class="text-ink-soft/50">({{ $permission->slug }})</span></span>
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="admin-btn">{{ $user->exists ? 'Guardar cambios' : 'Crear administrador' }}</button>
            <a class="admin-btn-secondary" href="{{ route('admin.users.index') }}">Cancelar</a>
        </div>
    </form>
@endsection
