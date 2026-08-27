@php
    $tab = $tab ?? 'campaigns';
@endphp
<div class="mb-6">
    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-line">
        <nav class="flex min-w-0 flex-wrap gap-1" aria-label="Marketing">
            <a href="{{ route('admin.store.marketing.campaigns.index') }}"
               class="-mb-px border-b-2 px-4 py-3 text-sm font-medium {{ $tab === 'campaigns' ? 'border-teal text-teal' : 'border-transparent text-ink-soft/65 hover:text-ink' }}">
                Campañas
            </a>
        </nav>
        <a href="{{ route('admin.store.hub') }}" class="mb-2.5 text-xs text-ink-soft/55 hover:text-ink">← Tienda</a>
    </div>
</div>
