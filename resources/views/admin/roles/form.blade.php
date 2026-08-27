@extends('layouts.admin')

@section('title', ($role->exists ? 'Editar' : 'Crear').' rol — Multidrop')
@section('heading', $role->exists ? 'Editar rol' : 'Nuevo rol')
@section('subheading', 'Define el paquete de permisos')

@section('content')
    <form method="post" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="space-y-5">
        @csrf
        @if($role->exists)
            @method('PUT')
        @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Datos</h2>
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                    <input id="name" name="name" value="{{ old('name', $role->name) }}" required class="admin-input">
                </div>
                <div>
                    <label for="slug" class="mb-1.5 block text-sm font-medium text-ink-soft">Slug</label>
                    <input id="slug" name="slug" value="{{ old('slug', $role->slug) }}" @if($role->is_system) readonly @endif class="admin-input">
                </div>
                <div>
                    <label for="description" class="mb-1.5 block text-sm font-medium text-ink-soft">Descripción</label>
                    <input id="description" name="description" value="{{ old('description', $role->description) }}" class="admin-input">
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-3">
                <h2 class="font-display text-lg font-bold text-ink">Permisos</h2>
                @foreach($permissions as $group => $items)
                    <h3 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-[0.14em] text-ink-soft/50">{{ $group }}</h3>
                    <div class="grid gap-2">
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
            <button type="submit" class="admin-btn">{{ $role->exists ? 'Guardar rol' : 'Crear rol' }}</button>
            <a class="admin-btn-secondary" href="{{ route('admin.roles.index') }}">Cancelar</a>
        </div>
    </form>
@endsection
