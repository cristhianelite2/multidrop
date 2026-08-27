@extends('layouts.admin')

@section('title', 'Marketing — '.$store->name)
@section('heading', 'Marketing')
@section('subheading', 'Campañas, prompts y videos · '.$store->name)

@section('content')
    @include('admin.store.marketing._nav', ['tab' => 'hub'])

    <div class="grid gap-4 sm:grid-cols-3 mb-5">
        <a href="{{ route('admin.store.marketing.campaigns.index') }}" class="admin-card p-5 block hover:border-teal/40">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Campañas</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $campaignCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/60">Presupuesto diario, landing y borrador Advantage+/Smart+</p>
        </a>
        <a href="{{ route('admin.store.marketing.prompts.index') }}" class="admin-card p-5 block hover:border-teal/40">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Prompts</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $promptCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/60">Hooks y scripts para Creatify</p>
        </a>
        <a href="{{ route('admin.store.marketing.videos.index') }}" class="admin-card p-5 block hover:border-teal/40">
            <div class="text-xs uppercase tracking-wide text-ink-soft/50">Videos</div>
            <div class="mt-1 font-display text-2xl font-bold text-ink">{{ $videoCount }}</div>
            <p class="mt-1 text-xs text-ink-soft/60">Upload o generación · metadata de software/IA eliminada</p>
        </a>
    </div>

    <div class="admin-card p-5 sm:p-6 space-y-3 max-w-2xl">
        <h2 class="font-semibold text-ink">Integración</h2>
        <p class="text-sm text-ink-soft/70">
            Creatify genera el MP4. El gasto en Meta/TikTok no se activa en v1: solo se prepara un borrador <strong>PAUSED</strong> (Advantage+ / Smart+).
            Tope HITL: <strong>{{ number_format($budgetCap, 2) }}</strong> / día.
        </p>
        <div class="flex flex-wrap gap-2 text-sm">
            <span class="admin-badge {{ $creatify['ok'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                Creatify: {{ $creatify['message'] }}
            </span>
            <span class="admin-badge {{ $ffmpeg ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">
                ffmpeg: {{ $ffmpeg ? 'disponible (limpia metadata)' : 'no encontrado — los videos se guardan sin strip' }}
            </span>
        </div>
        @unless($creatify['ok'])
            <p class="text-xs text-ink-soft/55">Pon <code>CREATIFY_API_ID</code> y <code>CREATIFY_API_KEY</code> en <code>.env</code>. Campañas, prompts y upload funcionan igual sin keys.</p>
        @endunless
    </div>
@endsection
