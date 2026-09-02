@extends('layouts.admin')

@section('title', 'Prompts — '.$store->name)
@section('heading', 'Prompts de video')
@section('subheading', 'Hooks y scripts para Creatify · '.$store->name)

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-soft/70">Biblioteca de prompts. Lo habitual es crearlos dentro de cada campaña.</p>
        <a href="{{ route('admin.store.marketing.prompts.create') }}" class="admin-btn">Nuevo prompt</a>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-line bg-mist/50 text-left text-xs uppercase tracking-[0.12em] text-ink-soft/50">
                    <th class="px-4 py-3 font-semibold">Prompt</th>
                    <th class="px-4 py-3 font-semibold">Plataforma</th>
                    <th class="px-4 py-3 font-semibold">Campaña</th>
                    <th class="px-4 py-3 font-semibold"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($prompts as $p)
                    <tr class="border-b border-line/70 last:border-0">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-ink">{{ $p->name }}</div>
                            <div class="text-xs text-ink-soft/55 truncate max-w-md">{{ $p->hook ?: \Illuminate\Support\Str::limit($p->script, 80) }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $p->target_platform }} · {{ $p->language }}</td>
                        <td class="px-4 py-3 text-xs text-ink-soft">{{ $p->campaign?->name ?: 'Biblioteca' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end flex-wrap gap-2">
                                @if($p->hasLinkedProducts())
                                    <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.prompts.download-zip', $p) }}">Descargar ZIP</a>
                                @endif
                                <a class="admin-btn-secondary !px-3 !py-1.5 text-xs" href="{{ route('admin.store.marketing.prompts.edit', $p) }}">Editar</a>
                                <form method="post" action="{{ route('admin.store.marketing.prompts.destroy', $p) }}" onsubmit="return confirm('¿Eliminar este prompt?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-3 !py-1.5 text-xs">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-ink-soft/60">Aún no hay prompts.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
