{{-- Modal Traducir plantilla (MIIA). Vars: $translateUrl, $translateLocales, $translateDefaultLocale, $has_miia, $translateScopeLabel --}}
@php
    $translateLocales = $translateLocales ?? ($translate_locales ?? []);
    $translateDefaultLocale = (string) ($translateDefaultLocale ?? $store_default_locale ?? $design_locale ?? 'es_MX');
    $selected = collect($translateLocales)->firstWhere('locale', $translateDefaultLocale)
        ?: (collect($translateLocales)->first() ?? null);
    $selectedIso = (string) ($selected['iso'] ?? '');
    $translateScopeLabel = $translateScopeLabel ?? 'plantilla';
@endphp

<div id="design-translate-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <div class="admin-card w-full max-w-lg p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Traducir {{ $translateScopeLabel }}</h3>
            <button type="button" class="text-ink-soft" data-close-translate>×</button>
        </div>
        <p class="text-sm text-ink-soft/70">
            MIIA traduce el copy visible (páginas + strings de JS) al idioma elegido.
            Conserva hooks <code class="text-xs">data-md-*</code>, tokens Mustache del theme y la lógica.
            Puede tardar varios minutos.
        </p>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-ink-soft">Idioma destino</label>
            <input type="hidden" id="design-translate-locale" value="{{ $selected['locale'] ?? $translateDefaultLocale }}">

            <div id="design-translate-picker" class="relative">
                <button type="button" id="design-translate-toggle" class="admin-input flex w-full items-center gap-3 text-left">
                    <span id="design-translate-flag" class="market-flag fi {{ $selectedIso ? 'fi-'.$selectedIso : '' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span id="design-translate-label" class="block truncate font-semibold text-ink">{{ $selected['label'] ?? $selected['name'] ?? $translateDefaultLocale }}</span>
                        <span id="design-translate-meta" class="block truncate text-xs text-ink-soft/60">{{ $selected['locale'] ?? $translateDefaultLocale }}</span>
                    </span>
                    <span class="text-ink-soft/50">▾</span>
                </button>

                <div id="design-translate-menu" class="absolute left-0 right-0 z-40 mt-2 hidden overflow-hidden rounded-2xl border border-line bg-white shadow-xl shadow-ink/10">
                    <div class="border-b border-line p-2">
                        <input type="search" id="design-translate-search" placeholder="Buscar idioma o código…" class="admin-input" autocomplete="off">
                    </div>
                    <div class="max-h-64 overflow-y-auto p-1.5">
                        @foreach($translateLocales as $loc)
                            @php
                                $iso = (string) ($loc['iso'] ?? '');
                                $isSel = ($selected['locale'] ?? '') === ($loc['locale'] ?? '');
                                $blob = strtolower(trim(($loc['label'] ?? '').' '.($loc['name'] ?? '').' '.($loc['locale'] ?? '').' '.$iso));
                            @endphp
                            <button
                                type="button"
                                class="design-translate-option flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-mist {{ $isSel ? 'bg-teal/10 ring-1 ring-teal/20' : '' }}"
                                data-locale="{{ $loc['locale'] }}"
                                data-name="{{ $loc['label'] ?? $loc['name'] }}"
                                data-iso="{{ $iso }}"
                                data-search="{{ $blob }}"
                            >
                                <span class="market-flag fi {{ $iso ? 'fi-'.$iso : '' }}"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-ink">{{ $loc['label'] ?? $loc['name'] }}</span>
                                    <span class="block truncate text-[11px] text-ink-soft/60">{{ $loc['locale'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                    <div id="design-translate-empty" class="hidden px-3 py-6 text-center text-sm text-ink-soft/60">Sin resultados</div>
                </div>
            </div>
        </div>

        <p id="design-translate-status" class="hidden text-xs text-ink-soft/65"></p>
        <div class="flex flex-wrap gap-2 justify-end">
            <button type="button" class="admin-btn-secondary" data-close-translate>Cancelar</button>
            <button type="button" class="admin-btn" id="run-design-translate"
                    @disabled(!($has_miia ?? false))
                    data-url="{{ $translateUrl }}">
                ✨ Traducir con MIIA
            </button>
        </div>
    </div>
</div>
