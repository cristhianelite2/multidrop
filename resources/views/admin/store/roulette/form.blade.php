@extends('layouts.admin')

@section('title', ($slide->exists ? 'Editar' : 'Nuevo').' slide')
@section('heading', $slide->exists ? 'Editar slide' : 'Nuevo slide')
@section('subheading', $store->name)

@section('content')
    <form method="post" action="{{ $slide->exists ? route('admin.store.roulette.update', $slide) : route('admin.store.roulette.store') }}" class="space-y-5">
        @csrf
        @if($slide->exists) @method('PUT') @endif

        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">Contenido</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Kicker</label>
                        <input name="kicker" value="{{ old('kicker', $slide->kicker) }}" class="admin-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Tema</label>
                        <select name="theme_class" class="admin-input">
                            @foreach(['s1','s2','s3','s4','s5'] as $t)
                                <option value="{{ $t }}" @selected(old('theme_class', $slide->theme_class) === $t)>{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Título</label>
                        <input name="title" value="{{ old('title', $slide->title) }}" required class="admin-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">Texto</label>
                        <textarea name="text" rows="3" class="admin-input">{{ old('text', $slide->text) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="admin-card p-5 sm:p-6 space-y-4">
                <h2 class="font-display text-lg font-bold text-ink">CTA e imagen</h2>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">CTA label</label>
                    <input name="cta_label" value="{{ old('cta_label', $slide->cta_label) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">CTA URL</label>
                    <input name="cta_url" value="{{ old('cta_url', $slide->cta_url) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Imagen URL</label>
                    <input name="image_url" value="{{ old('image_url', $slide->image_url) }}" class="admin-input">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Orden</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $slide->sort_order ?? 0) }}" class="admin-input">
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $slide->is_active ?? true)) class="rounded border-line text-teal">
                    Activo
                </label>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button class="admin-btn">Guardar</button>
            <a href="{{ route('admin.store.roulette.index') }}" class="admin-btn-secondary">Cancelar</a>
        </div>
    </form>
@endsection
