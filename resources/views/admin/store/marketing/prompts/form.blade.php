@extends('layouts.admin')

@section('title', ($prompt->exists ? 'Editar' : 'Nuevo').' prompt — '.$store->name)
@section('heading', $prompt->exists ? 'Editar prompt' : 'Nuevo prompt')
@section('subheading', $store->name)

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <form method="post" action="{{ $prompt->exists ? route('admin.store.marketing.prompts.update', $prompt) : route('admin.store.marketing.prompts.store') }}" class="space-y-5 max-w-2xl">
        @csrf
        @if($prompt->exists) @method('PUT') @endif

        <div class="admin-card p-5 sm:p-6 space-y-4">
            <p class="text-sm text-ink-soft/70">
                Estos campos se mandan a Creatify: script → <code>override_script</code>, audiencia, estilo visual, plataforma e idioma.
            </p>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                <input type="text" name="name" value="{{ old('name', $prompt->name) }}" class="admin-input" required maxlength="120">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Hook</label>
                <input type="text" name="hook" value="{{ old('hook', $prompt->hook) }}" class="admin-input" maxlength="240">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Script</label>
                <textarea name="script" class="admin-input" rows="6" required maxlength="4000">{{ old('script', $prompt->script) }}</textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Audiencia</label>
                <input type="text" name="audience" value="{{ old('audience', $prompt->audience) }}" class="admin-input" maxlength="240">
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Idioma</label>
                    <input type="text" name="language" value="{{ old('language', $prompt->language) }}" class="admin-input" required maxlength="16">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Plataforma destino</label>
                    <select name="target_platform" class="admin-input">
                        <option value="Tiktok" @selected(old('target_platform', $prompt->target_platform) === 'Tiktok')>TikTok</option>
                        <option value="Meta" @selected(old('target_platform', $prompt->target_platform) === 'Meta')>Meta</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Estilo visual (Creatify)</label>
                    <input type="text" name="style" value="{{ old('style', $prompt->style) }}" class="admin-input" maxlength="80" placeholder="DynamicProductTemplate">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Asignar a campaña</label>
                    <select name="campaign_id" class="admin-input">
                        <option value="">Biblioteca (ninguna)</option>
                        @foreach($campaigns as $c)
                            <option value="{{ $c->id }}" @selected((string) old('campaign_id', $prompt->campaign_id) === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <button class="admin-btn">Guardar</button>
    </form>
@endsection
