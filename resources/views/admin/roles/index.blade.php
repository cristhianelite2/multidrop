@extends('layouts.admin')

@section('title', 'Roles — Multidrop')
@section('heading', 'Roles y accesos')
@section('subheading', 'Qué puede hacer cada tipo de administrador')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-soft/65">Roles de sistema + roles personalizados.</p>
        @canperm('roles.manage')
            <a class="admin-btn" href="{{ route('admin.roles.create') }}">Nuevo rol</a>
        @endcanperm
    </div>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Roles</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Rol</th>
                    <th class="px-4 py-3 font-semibold">Slug</th>
                    <th class="px-4 py-3 font-semibold">Permisos</th>
                    <th class="px-4 py-3 font-semibold">Usuarios</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @foreach($roles as $role)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-ink">{{ $role->name }}</div>
                            @if($role->is_system)
                                <span class="admin-badge mt-1 bg-amber/15 text-amber">sistema</span>
                            @endif
                            @if($role->description)
                                <div class="mt-1 text-xs text-ink-soft/60">{{ $role->description }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3"><code class="rounded bg-mist px-1.5 py-0.5 text-xs">{{ $role->slug }}</code></td>
                        <td class="px-4 py-3 text-ink-soft">{{ $role->permissions_count }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $role->users_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 justify-end">
                                @canperm('roles.manage')
                                    <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.roles.edit', $role) }}">Editar</a>
                                    @unless($role->is_system)
                                        <form method="post" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('¿Eliminar rol?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                        </form>
                                    @endunless
                                @endcanperm
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
