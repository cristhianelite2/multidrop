@extends('layouts.admin')

@section('title', 'Administradores — Multidrop')
@section('heading', 'Administradores')
@section('subheading', 'Usuarios, roles y permisos del lab')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-soft/65">Gestiona quién entra al panel y con qué alcance.</p>
        @canperm('users.create')
            <a class="admin-btn" href="{{ route('admin.users.create') }}">Nuevo admin</a>
        @endcanperm
    </div>

    <div class="admin-card overflow-hidden">
        <div class="border-b border-line px-4 py-3">
            <h2 class="font-display text-base font-bold text-ink">Administradores</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Nombre</th>
                    <th class="px-4 py-3 font-semibold">Email</th>
                    <th class="px-4 py-3 font-semibold">Roles</th>
                    <th class="px-4 py-3 font-semibold">Estado</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-ink">{{ $user->name }}</div>
                            @if($user->is_superuser)
                                <span class="admin-badge mt-1 bg-amber/15 text-amber">super</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-soft">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach($user->roles as $role)
                                    <span class="admin-badge bg-teal/10 text-teal">{{ $role->slug }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if($user->is_active)
                                <span class="admin-badge bg-emerald-100 text-emerald-800">activo</span>
                            @else
                                <span class="admin-badge bg-coral/10 text-coral">inactivo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 justify-end">
                                @canperm('users.update')
                                    <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.users.edit', $user) }}">Editar</a>
                                @endcanperm
                                @canperm('users.delete')
                                    @if(!$user->is_superuser && $user->id !== auth()->id())
                                        <form method="post" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('¿Eliminar administrador?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                        </form>
                                    @endif
                                @endcanperm
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm">
            @if($users->onFirstPage())
                <span class="text-ink-soft/40">Anterior</span>
            @else
                <a class="admin-btn-secondary !py-1.5 !px-3 text-xs" href="{{ $users->previousPageUrl() }}">Anterior</a>
            @endif
            <span class="text-ink-soft/60">Pág. {{ $users->currentPage() }} / {{ $users->lastPage() }}</span>
            @if($users->hasMorePages())
                <a class="admin-btn-secondary !py-1.5 !px-3 text-xs" href="{{ $users->nextPageUrl() }}">Siguiente</a>
            @else
                <span class="text-ink-soft/40">Siguiente</span>
            @endif
        </div>
    </div>
@endsection
