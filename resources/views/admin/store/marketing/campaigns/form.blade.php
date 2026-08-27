@extends('layouts.admin')

@section('title', ($campaign->exists ? 'Editar' : 'Nueva').' campaña — '.$store->name)
@section('heading', $campaign->exists ? 'Editar campaña' : 'Nueva campaña')
@section('subheading', $campaign->exists ? $store->name : 'Presupuesto, landing y plataformas · '.$store->name)

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'campaigns'])

    <form method="post" action="{{ $campaign->exists ? route('admin.store.marketing.campaigns.update', $campaign) : route('admin.store.marketing.campaigns.store') }}" class="space-y-5 max-w-2xl">
        @csrf
        @if($campaign->exists) @method('PUT') @endif

        <div class="admin-card p-5 sm:p-6 space-y-4">
            <p class="text-sm text-ink-soft/70">
                Tope HITL: <strong>{{ number_format($budgetCap, 2) }} {{ $store->currency() }}</strong> / día. Si pides más, se recorta solo.
                Objetivo fijo: Advantage+ Sales (Meta) y Smart+ (TikTok). v1 no gasta.
            </p>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Nombre</label>
                <input type="text" name="name" value="{{ old('name', $campaign->name) }}" class="admin-input" required maxlength="120">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Plataformas</label>
                @php $plats = old('platforms', $campaign->platformList()); @endphp
                <label class="mr-4 text-sm"><input type="checkbox" name="platforms[]" value="meta" @checked(in_array('meta', $plats, true))> Meta</label>
                <label class="text-sm"><input type="checkbox" name="platforms[]" value="tiktok" @checked(in_array('tiktok', $plats, true))> TikTok</label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Presupuesto diario</label>
                    <input type="number" step="0.01" min="0" name="daily_budget" value="{{ old('daily_budget', $campaign->daily_budget) }}" class="admin-input" required>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-ink-soft">Estado</label>
                    <select name="status" class="admin-input">
                        @foreach(['draft' => 'Borrador', 'ready' => 'Listo (payload)', 'paused' => 'Pausada'] as $val => $lab)
                            <option value="{{ $val }}" @selected(old('status', $campaign->status) === $val)>{{ $lab }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Página de destino (handle)</label>
                <select name="landing_handle" class="admin-input">
                    <option value="">—</option>
                    @foreach($pages as $page)
                        <option value="{{ $page['handle'] }}" @selected(old('landing_handle', $campaign->landing_handle) === $page['handle'])>
                            {{ $page['title'] }} ({{ $page['handle'] }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">URL de landing (opcional, pisa el handle)</label>
                <input type="text" name="landing_url" value="{{ old('landing_url', $campaign->landing_url) }}" class="admin-input" maxlength="500" placeholder="https://…">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Notas</label>
                <textarea name="notes" class="admin-input" rows="3" maxlength="2000">{{ old('notes', $campaign->notes) }}</textarea>
            </div>
        </div>

        <button class="admin-btn">Guardar</button>
    </form>

    @if($campaign->exists)
        <div class="admin-card p-5 sm:p-6 mt-6 max-w-2xl space-y-3">
            <h3 class="font-semibold text-ink">Borrador Advantage+ / Smart+</h3>
            <p class="text-sm text-ink-soft/70">Arma el payload con los videos de esta campaña, URL y pixel. Queda <strong>PAUSED</strong>. No se publica ni se gasta.</p>
            <form method="post" action="{{ route('admin.store.marketing.campaigns.draft', $campaign) }}">
                @csrf
                <button class="admin-btn-secondary">Preparar borrador</button>
            </form>
            @if($campaign->draft_payload)
                <p class="text-xs text-ink-soft/55">
                    Meta: {{ $campaign->meta_draft_id ?: '—' }} · TikTok: {{ $campaign->tiktok_draft_id ?: '—' }}
                </p>
                <pre class="text-xs overflow-x-auto bg-mist/60 p-3 rounded-lg max-h-72">{{ json_encode($campaign->draft_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </div>
    @endif
@endsection
