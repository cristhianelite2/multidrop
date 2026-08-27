@extends('layouts.admin')

@section('title', 'Diseño — '.$store->name)
@section('heading', 'Diseño')
@section('subheading', 'Plantillas multi-página Multidrop para «'.$store->name.'».')

@section('content')
@php
    $checkout = $design['checkout'] ?? [];
    $pages = $design['pages'] ?? [];
    $assets = $design['assets'] ?? [];
    $aiFixPageId = collect($pages)->firstWhere('handle', 'index')['id']
        ?? (collect($pages)->first()['id'] ?? null);
@endphp

@php
    $storeDesigns = $storeDesigns ?? collect();
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.store.hub') }}" class="admin-btn-secondary">← Tienda</a>
    <a href="{{ route('admin.store.design.preview') }}" target="_blank" class="admin-btn-secondary">Vista previa</a>
    <a href="{{ route('store.design.show', $store->slug) }}" target="_blank" class="admin-btn-secondary">Pública /s/{{ $store->slug }}</a>
    <button type="button" class="admin-btn !py-2 text-xs" id="open-ai-fix"
        @disabled(!($has_miia ?? false))
        title="{{ ($has_miia ?? false) ? 'Describe el problema y MIIA corrige el theme' : 'Configura MIIA en General' }}">
        Resolver con MIIA
    </button>
</div>

@if(!($has_miia ?? false))
    <p class="mb-4 text-xs text-amber">MIIA no está configurada. Ve a Admin → General para añadir la API Key.</p>
@endif

{{-- Modal MIIA fix --}}
<div id="ai-fix-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <div class="admin-card w-full max-w-lg p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Resolver con MIIA</h3>
            <button type="button" class="text-ink-soft" id="close-ai-fix">×</button>
        </div>
        <p class="text-sm text-ink-soft/70">Describe el problema. MIIA recibirá el HTML/CSS/JS y el contrato Multidrop, y aplicará correcciones.</p>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Problema</label>
            <textarea id="ai-fix-problem" rows="5" class="admin-input text-sm" placeholder="Ej: No se ven los productos en el home; el nombre de la tienda no aparece; el CSS del hero está roto…"></textarea>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Alcance</label>
            <select id="ai-fix-scope" class="admin-input text-sm">
                <option value="both">Página actual (landing) + CSS/JS global</option>
                <option value="page">Solo página (landing)</option>
                <option value="global">Solo CSS/JS global</option>
            </select>
        </div>
        <p id="ai-fix-status" class="hidden text-xs text-ink-soft/65"></p>
        <div class="flex flex-wrap gap-2 justify-end">
            <button type="button" class="admin-btn-secondary" id="close-ai-fix-2">Cancelar</button>
            <button type="button" class="admin-btn" id="run-ai-fix">Resolver</button>
        </div>
    </div>
</div>

<div class="admin-blocks mb-5">
{{-- Una plantilla asignada (copia). La global nunca se edita ni se borra desde la tienda. --}}
@php
    $storeDesigns = $storeDesigns ?? collect();
    $activeDesign = $storeDesigns->firstWhere('is_active', true) ?? $storeDesigns->first();
    $otherDesigns = $storeDesigns->where('id', '!=', optional($activeDesign)->id)->values();
    $libraryThemes = $libraryThemes ?? collect();
    $installedByThemeId = $storeDesigns->filter(fn ($d) => $d->theme_id)->keyBy('theme_id');
    $activeThemeId = $activeDesign?->theme_id;
@endphp
<div class="admin-card p-4 sm:p-5 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold text-ink">Plantilla de esta tienda</h2>
            <p class="text-sm text-ink-soft/65">
                Solo <strong>una asignada</strong> a la vez. Al elegirla se crea una <strong>copia editable</strong>.
                Personalizas esa copia; la plantilla global no se modifica ni se puede eliminar desde aquí.
            </p>
        </div>
    </div>

    @if($activeDesign)
        <div class="rounded-2xl border border-teal bg-teal/5 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold text-ink truncate">{{ $activeDesign->name }}</span>
                        <span class="admin-badge bg-teal/10 text-teal">Asignada · la editas abajo</span>
                    </div>
                    <div class="text-xs text-ink-soft/60 mt-1">
                        {{ $activeDesign->originLabel() }}
                        · {{ $activeDesign->pagesCount() }} páginas
                        · {{ $activeDesign->assetsCount() }} assets
                    </div>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <form method="post" action="{{ route('admin.store.designs.duplicate', $activeDesign) }}">
                        @csrf
                        <button class="admin-btn-secondary !py-1 !px-2 text-xs" title="Guarda esta personalización en la tienda antes de cambiar de plantilla">Guardar copia</button>
                    </form>
                    @if($activeDesign->theme_id)
                        <form method="post" action="{{ route('admin.store.designs.reset', $activeDesign) }}" onsubmit="return confirm('¿Restablecer esta copia al contenido actual de la biblioteca? Se perderán los cambios locales. La plantilla global no se toca.')">
                            @csrf
                            <button class="admin-btn-secondary !py-1 !px-2 text-xs" title="Vuelve a clonar la global sobre esta copia">Restablecer desde biblioteca</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @else
        <p class="text-sm text-ink-soft/60">Aún no hay plantilla asignada. Elige una de la biblioteca o importa un ZIP (queda como copia de esta tienda).</p>
    @endif

    @if($otherDesigns->isNotEmpty())
        <div class="space-y-2">
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-soft/55">Otras copias en esta tienda</div>
            <p class="text-[11px] text-ink-soft/55">Solo puedes eliminar estas copias. Nunca se borra la plantilla global.</p>
            <div class="grid gap-2">
                @foreach($otherDesigns as $sd)
                    <div class="rounded-xl border border-line bg-white px-3 py-2.5 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-ink truncate">{{ $sd->name }}</div>
                            <div class="text-[11px] text-ink-soft/55">{{ $sd->originLabel() }} · {{ $sd->pagesCount() }} pág. · {{ $sd->assetsCount() }} assets</div>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <form method="post" action="{{ route('admin.store.designs.activate', $sd) }}">
                                @csrf
                                <button class="admin-btn !py-1 !px-2 text-xs">Usar esta</button>
                            </form>
                            <form method="post" action="{{ route('admin.store.designs.destroy', $sd) }}" onsubmit="return confirm('¿Quitar «{{ $sd->name }}» de esta tienda? La plantilla global no se elimina.')">
                                @csrf @method('DELETE')
                                <button class="admin-btn-danger !py-1 !px-2 text-xs">Eliminar copia</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-dashed border-line p-3 space-y-2">
        <div class="text-sm font-semibold text-ink">Asignar desde biblioteca</div>
        <p class="text-xs text-ink-soft/60">
            Si ya existe una copia de esa plantilla aquí, se activa (no se vuelve a clonar).
            Si es nueva, se clona y pasa a ser la asignada. La global nunca se altera.
        </p>
        @if($libraryThemes->isNotEmpty())
            <form method="post" action="#" id="smart-theme-form" class="flex flex-wrap gap-2 items-end">
                @csrf
                <select id="smart-theme-select" class="admin-input !py-1.5 text-xs flex-1 min-w-[200px]" required>
                    <option value="">Elegir plantilla global…</option>
                    @foreach($libraryThemes as $libTheme)
                        @php
                            $installed = $installedByThemeId->get($libTheme->id);
                            $isActiveLib = $activeThemeId && (int) $activeThemeId === (int) $libTheme->id;
                        @endphp
                        <option
                            value="{{ $libTheme->id }}"
                            data-id="{{ $libTheme->id }}"
                            @selected($isActiveLib)
                            @disabled($isActiveLib)
                        >
                            @if($isActiveLib)
                                ● {{ $libTheme->name }} — asignada ahora
                            @elseif($installed)
                                ✓ {{ $libTheme->name }} — hay copia · usar
                            @else
                                + {{ $libTheme->name }} ({{ $libTheme->pagesCount() }} pág.) · asignar copia
                            @endif
                        </option>
                    @endforeach
                </select>
                <button type="submit" id="smart-theme-btn" class="admin-btn !py-1.5 !px-3 text-xs" disabled>Asignar</button>
            </form>
        @else
            <p class="text-xs text-ink-soft/55">No hay plantillas en la biblioteca de plataforma. Súbelas en <a class="text-teal underline" href="{{ route('admin.templates.index') }}">Plataforma → Plantillas</a>.</p>
        @endif
    </div>
</div>
</div>

<div class="admin-card p-4 sm:p-5 space-y-4 mb-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold text-ink">Inyección (JSON + módulos)</h2>
            <p class="text-sm text-ink-soft/65">Cada módulo se renderiza en Twig con su JSON. La plantilla solo pisa CSS. Aquí ves qué se está inyectando. La visibilidad PC/móvil de plugins se configura en <a class="text-teal underline" href="{{ route('admin.store.general.edit') }}">General de la tienda</a>.</p>
        </div>
        <select id="md-inspect-handle" class="admin-input text-sm max-w-[200px]">
            @foreach($pages as $p)
                <option value="{{ $p['handle'] }}">{{ $p['title'] }} ({{ $p['handle'] }})</option>
            @endforeach
        </select>
    </div>
    <p id="md-inspect-meta" class="text-xs text-ink-soft/60">Elige una página para inspeccionar.</p>
    <div id="md-inspect-list" class="space-y-2 text-sm"></div>
</div>

<div class="admin-card p-4 sm:p-5 space-y-3 mb-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold text-ink">Brief para Claude</h2>
            <p class="text-sm text-ink-soft/65">Copia este prompt. El diseñador entrega CSS + layout.json, no HTML de catálogo.</p>
        </div>
        <button type="button" class="admin-btn-secondary !py-1.5 text-xs" id="copy-design-prompt">Copiar prompt</button>
    </div>
    <textarea id="design-prompt-text" rows="32" class="admin-input font-mono text-[11px] leading-relaxed" readonly>{{ $designerPrompt ?? '' }}</textarea>
</div>

{{-- Páginas --}}
<div class="admin-card overflow-hidden mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
            <h2 class="font-display text-base font-bold text-ink">Páginas del theme</h2>
            <p class="text-xs text-ink-soft/60">Landing, catálogo, producto, carrito, checkout y páginas libres.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="post" action="{{ route('admin.store.design.seed') }}" onsubmit="return confirm('¿Crear páginas base faltantes (landing, catalog, product, cart, checkout)?')">
                @csrf
                <button class="admin-btn-secondary !py-1.5 text-xs">Crear plantilla base</button>
            </form>
            <button type="button" class="admin-btn !py-1.5 text-xs" id="open-create-page">+ Nueva página</button>
        </div>
    </div>

    <div class="border-b border-line bg-mist/30 px-4 py-3 space-y-2">
        <p class="text-xs text-ink-soft/70">
            Empaqueta <code>theme.css</code>, <code>modules.css</code>, <code>layout.json</code> y <code>assets/</code>.
            El HTML comercial lo genera Multidrop. Páginas FAQ: <code>pages/faq.twig</code>.
        </p>
        <form id="zip-upload-form" method="post" action="{{ route('admin.store.design.zip.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
            @csrf
            <label class="admin-btn-secondary !py-1.5 text-xs cursor-pointer inline-flex items-center gap-2" id="zip-upload-trigger">
                <span id="zip-upload-label">Subir ZIP theme</span>
                <input type="file" name="zip" id="zip-upload-input" accept=".zip,application/zip,application/x-zip-compressed" class="sr-only">
            </label>
            <input type="text" name="name" class="admin-input !py-1.5 text-xs max-w-[180px]" placeholder="Nombre de la copia">
            <label class="inline-flex items-center gap-1.5 text-xs text-ink-soft">
                <input type="checkbox" name="activate" value="1" checked class="rounded border-line text-teal"> Asignar al importar
            </label>
            <span id="zip-upload-filename" class="text-xs text-ink-soft/60"></span>
            <span id="zip-upload-status" class="hidden text-xs font-medium text-teal">Subiendo e importando…</span>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-line bg-mist/40 text-left text-xs uppercase tracking-wide text-ink-soft/50">
                <th class="px-4 py-2.5">Título</th>
                <th class="px-4 py-2.5">Tipo</th>
                <th class="px-4 py-2.5">Handle</th>
                <th class="px-4 py-2.5">Estado</th>
                <th class="px-4 py-2.5"></th>
            </tr>
            </thead>
            <tbody>
            @forelse($pages as $p)
                <tr class="border-b border-line/70">
                    <td class="px-4 py-3 font-medium text-ink">{{ $p['title'] }}</td>
                    <td class="px-4 py-3"><span class="admin-badge bg-mist text-ink-soft">{{ $pageTypes[$p['type']] ?? $p['type'] }}</span></td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $p['handle'] }}</td>
                    <td class="px-4 py-3">
                        <span class="admin-badge {{ ($p['status'] ?? '') === 'live' ? 'bg-teal/10 text-teal' : 'bg-amber/10 text-amber' }}">
                            {{ $p['status'] }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            @if(($p['type'] ?? '') === 'page')
                            <a class="admin-btn !px-2.5 !py-1 text-xs" href="{{ route('admin.store.design.editor', $p['id']) }}">Editor visual</a>
                            @endif
                            <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" href="{{ route('admin.store.design.pages.edit', $p['id']) }}">Layout / CSS</a>
                            <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" target="_blank"
                               href="{{ route('admin.store.design.preview', ['page' => $p['id']]) }}">Preview</a>
                            @if(($p['type'] ?? '') !== 'landing')
                                <form method="post" action="{{ route('admin.store.design.pages.destroy', $p['id']) }}" onsubmit="return confirm('¿Eliminar página?')">
                                    @csrf @method('DELETE')
                                    <button class="admin-btn-danger !px-2.5 !py-1 text-xs">Eliminar</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-ink-soft/60">Sin páginas. Crea la plantilla base.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Modal crear --}}
<div id="create-page-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <div class="admin-card w-full max-w-md p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Nueva página</h3>
            <button type="button" class="text-ink-soft" id="close-create-page">×</button>
        </div>
        <form method="post" action="{{ route('admin.store.design.pages.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Tipo</label>
                <select name="type" class="admin-input" id="create-page-type">
                    @foreach($pageTypes as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Título</label>
                <input name="title" class="admin-input" required placeholder="Inicio, Catálogo, Nosotros…">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Handle (URL)</label>
                <input name="handle" class="admin-input font-mono text-sm" placeholder="index, catalog, about…">
                <p class="mt-1 text-xs text-ink-soft/55">Pública: /s/{{ $store->slug }}/pages/{handle}</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="with_starter" value="1" checked class="rounded border-line text-teal">
                Cargar HTML base del tipo
            </label>
            <button class="admin-btn w-full">Crear y editar</button>
        </form>
    </div>
</div>

{{-- Global + checkout + assets --}}
<form method="post" action="{{ route('admin.store.design.update') }}" class="space-y-5" id="design-global-form">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="theme">

    <div class="admin-blocks">
    <div class="admin-card p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-base font-bold text-ink">Theme activo</h2>
            <p class="text-sm text-ink-soft/65">Al activar, /s/{{ $store->slug }} sirve las páginas <strong>live</strong>.</p>
        </div>
        <label class="inline-flex items-center gap-2 text-sm font-medium text-ink-soft">
            <input type="checkbox" name="enabled" value="1" class="rounded border-line text-teal" @checked(old('enabled', $design['enabled'] ?? false))>
            Activar diseño custom
        </label>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-mist/40 px-3 py-2">
            <h2 class="font-display text-sm font-bold text-ink">Theme global</h2>
        </div>
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 p-2" data-design-tabs>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs is-tab-active" data-tab="css">CSS global</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="modules">Módulos</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="js">JS global</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="checkout">Checkout</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="assets">Assets</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="guide">Referencias</button>
        </div>

        <div class="p-3 sm:p-4 space-y-3" data-tab-panel="css">
            <div class="flex gap-2">
                <button type="button" class="admin-btn-secondary !py-1.5 text-xs" data-load-starter-css>Cargar CSS base</button>
            </div>
            <textarea name="global_css" rows="14" class="admin-input font-mono text-[12px]" spellcheck="false">{{ old('global_css', $design['global_css'] ?? '') }}</textarea>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="modules">
            <p class="text-sm text-ink-soft/70">
                Estilos de Upsell, Cross Sell, Urgencia, Ruleta y Prueba social.
                Usa tokens del theme (<code>--md-primary</code>, <code>--md-checkout-*</code>). Si dejas vacío, se aplica el CSS base de módulos.
            </p>
            <div class="flex gap-2">
                <button type="button" class="admin-btn-secondary !py-1.5 text-xs" data-load-starter-modules-css>Cargar CSS base de módulos</button>
            </div>
            <textarea name="modules_css" rows="16" class="admin-input font-mono text-[12px]" spellcheck="false" placeholder="Vacío = CSS base de módulos">{{ old('modules_css', $design['modules_css'] ?? '') }}</textarea>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="js">
            <textarea name="global_js" rows="14" class="admin-input font-mono text-[12px]" spellcheck="false">{{ old('global_js', $design['global_js'] ?? '') }}</textarea>
        </div>

        <div class="p-3 sm:p-4 space-y-4 hidden" data-tab-panel="checkout">
            <p class="text-sm text-ink-soft/70">Colores del checkout (`--md-checkout-*`). La pasarela sigue siendo Multidrop.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach([
                    'checkout_primary' => ['Primario', $checkout['primary'] ?? '#0f766e'],
                    'checkout_accent' => ['Acento', $checkout['accent'] ?? '#f59e0b'],
                    'checkout_button' => ['Botón', $checkout['button'] ?? '#0f766e'],
                    'checkout_bg' => ['Fondo', $checkout['bg'] ?? '#ffffff'],
                    'checkout_text' => ['Texto', $checkout['text'] ?? '#0f172a'],
                ] as $name => [$label, $val])
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-ink-soft">{{ $label }}</label>
                        <div class="flex gap-2">
                            <input type="color" name="{{ $name }}" value="{{ old($name, $val) }}" class="h-11 w-14 rounded-lg border border-line bg-white p-1" data-color-sync>
                            <input type="text" value="{{ old($name, $val) }}" class="admin-input font-mono text-sm" readonly data-color-hex>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="assets">
            <div class="rounded-xl border border-teal/20 bg-teal/5 p-3 space-y-2">
                <div class="font-medium text-ink text-sm">Importar theme completo (ZIP)</div>
                <p class="text-xs text-ink-soft/65">
                    Estructura recomendada: <code>index.html</code> + <code>catalog.html</code> + <code>product.html</code> +
                    <code>theme.css</code> + <code>assets/*</code>. Máx. 20&nbsp;MB.
                </p>
                <p class="text-xs text-ink-soft/55">Usa el botón <strong>Subir ZIP theme</strong> arriba de la tabla de páginas.</p>
            </div>

            <p class="text-sm text-ink-soft/70">O sube un asset suelto (imagen/fuente/CSS/JS). Copia la URL al HTML.</p>
            <div class="flex flex-wrap gap-2 items-end">
                <div class="flex-1 min-w-[200px]">
                    <input type="file" name="file" form="asset-upload-form" class="admin-input" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.css,.js,.woff,.woff2">
                </div>
                <button type="submit" form="asset-upload-form" class="admin-btn !py-2 text-xs">Subir asset</button>
            </div>
            <ul class="divide-y divide-line rounded-xl border border-line">
                @forelse($assets as $asset)
                    @php
                        $assetUrl = $asset['url'] ?? '';
                        $isImg = (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|avif|ico)(\?|$)/i', (string) ($asset['path'] ?? $assetUrl));
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                        <div class="flex min-w-0 items-center gap-3">
                            @if($isImg && $assetUrl)
                                <img src="{{ $assetUrl }}" alt="" class="h-12 w-12 rounded-lg border border-line object-cover bg-mist">
                            @endif
                            <div class="min-w-0">
                                <div class="font-medium text-ink truncate">{{ $asset['name'] ?? 'asset' }}</div>
                                <code class="text-[11px] text-ink-soft/60 break-all">{{ $assetUrl }}</code>
                            </div>
                        </div>
                        <form method="post" action="{{ route('admin.store.design.assets.destroy', $asset['id']) }}">
                            @csrf @method('DELETE')
                            <button class="admin-btn-danger !px-2 !py-1 text-xs">Eliminar</button>
                        </form>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-ink-soft/55 text-sm">Sin assets aún.</li>
                @endforelse
            </ul>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden text-sm text-ink-soft" data-tab-panel="guide">
            <h3 class="font-display text-base font-bold text-ink">Referencias rápidas</h3>
            <ul class="list-disc pl-5 space-y-1.5">
                <li>Productos: <code>&lt;div data-md-products&gt;&lt;/div&gt;</code></li>
                <li>Destacados: <code>data-md-featured="1"</code> · límite: <code>data-md-limit="8"</code></li>
                <li>PDP: <code>data-md-bind="product.name"</code>, <code>product.price_formatted</code>, <code>product.image</code></li>
                <li>JSON: <a class="text-teal underline" href="{{ route('store.design.products', $store->slug) }}" target="_blank">products.json</a></li>
                <li>Runtime: <code>Multidrop.store</code>, <code>.products</code>, <code>.product</code>, <code>.urls</code>, <code>.checkout</code></li>
            </ul>
        </div>
    </div>
    </div>

    <button class="admin-btn">Guardar theme global</button>
</form>

<form id="asset-upload-form" method="post" action="{{ route('admin.store.design.assets.upload') }}" enctype="multipart/form-data" class="hidden">
    @csrf
</form>

<script type="application/json" id="starter-global-css">@json($starterGlobalCss)</script>
<script type="application/json" id="starter-modules-css">@json($starterModulesCss ?? '')</script>
@endsection

@push('scripts')
<script>
(function ($) {
  function openModal(show) {
    $('#create-page-modal').toggleClass('hidden', !show).toggleClass('flex', show);
  }
  $('#open-create-page').on('click', function () { openModal(true); });
  $('#close-create-page').on('click', function () { openModal(false); });
  $('#create-page-modal').on('click', function (e) {
    if (e.target === this) openModal(false);
  });

  $('#copy-design-prompt').on('click', function () {
    var t = document.getElementById('design-prompt-text');
    if (!t) return;
    t.select();
    try { navigator.clipboard.writeText(t.value); } catch (e) { document.execCommand('copy'); }
    if (window.AdminToast) AdminToast.success('Prompt copiado');
  });

  function loadInspect(handle) {
    var $list = $('#md-inspect-list');
    var $meta = $('#md-inspect-meta');
    $list.html('<p class="text-ink-soft/60">Cargando…</p>');
    $.getJSON(@json(route('admin.store.design.inspect')), { handle: handle || 'index' })
      .done(function (res) {
        $meta.text('Motor: ' + (res.engine || '—') + ' · visita ' + (res.visit || '—') + ' · tipo ' + (res.type || '—') + ' · layout: ' + (res.layout || []).join(' → '));
        var html = '';
        (res.modules || []).forEach(function (m) {
          var devices = [];
          if (m.plugin) {
            if (m.desktop) devices.push('PC');
            if (m.mobile) devices.push('móvil');
          }
          html += '<details class="rounded-xl border border-line p-3 bg-white">';
          html += '<summary class="cursor-pointer font-semibold">' + m.key + (m.enabled ? '' : ' (off)') + (m.overlay ? ' · overlay' : '') + (m.plugin ? ' · plugin ' + m.plugin : '') + (devices.length ? ' · ' + devices.join('/') : '') + '</summary>';
          html += '<p class="mt-2 text-[11px] uppercase tracking-wide text-ink-soft/50">JSON inyectado</p>';
          html += '<pre class="mt-1 max-h-48 overflow-auto rounded-lg bg-mist/50 p-2 text-[11px] leading-relaxed">' + $('<div/>').text(JSON.stringify(m.json, null, 2)).html() + '</pre>';
          html += '<p class="mt-2 text-[11px] uppercase tracking-wide text-ink-soft/50">HTML Twig (solo lectura)</p>';
          html += '<pre class="mt-1 max-h-40 overflow-auto rounded-lg bg-mist/50 p-2 text-[11px]">' + $('<div/>').text(m.html || '(vacío)').html() + '</pre>';
          html += '</details>';
        });
        $list.html(html || '<p class="text-ink-soft/60">Sin módulos.</p>');
      })
      .fail(function () {
        $list.html('<p class="text-rose">No se pudo inspeccionar.</p>');
      });
  }
  $('#md-inspect-handle').on('change', function () { loadInspect($(this).val()); });
  if ($('#md-inspect-handle').length) loadInspect($('#md-inspect-handle').val());

  $('[data-design-tabs] [data-tab]').on('click', function () {
    var tab = $(this).data('tab');
    $('[data-design-tabs] [data-tab]').removeClass('!bg-teal !text-white !border-teal');
    $(this).addClass('!bg-teal !text-white !border-teal');
    $('[data-tab-panel]').addClass('hidden');
    $('[data-tab-panel="'+tab+'"]').removeClass('hidden');
  }).first().trigger('click');

  $('[data-load-starter-css]').on('click', function () {
    var css = '';
    try { css = JSON.parse($('#starter-global-css').text() || '""'); } catch (e) {}
    var $ta = $('textarea[name="global_css"]');
    if ($ta.val().trim() && !confirm('¿Reemplazar CSS global?')) return;
    $ta.val(css);
  });

  $('[data-load-starter-modules-css]').on('click', function () {
    var css = '';
    try { css = JSON.parse($('#starter-modules-css').text() || '""'); } catch (e) {}
    var $ta = $('textarea[name="modules_css"]');
    if ($ta.val().trim() && !confirm('¿Reemplazar CSS de módulos?')) return;
    $ta.val(css);
  });

  $('[data-color-sync]').on('input', function () {
    $(this).siblings('[data-color-hex]').val($(this).val());
  });

  function openAiFix(show) {
    $('#ai-fix-modal').toggleClass('hidden', !show).toggleClass('flex', !!show);
  }
  $('#open-ai-fix').on('click', function () { openAiFix(true); });
  $('#close-ai-fix, #close-ai-fix-2').on('click', function () { openAiFix(false); });
  $('#ai-fix-modal').on('click', function (e) {
    if (e.target === this) openAiFix(false);
  });
  $('#run-ai-fix').on('click', function () {
    var problem = String($('#ai-fix-problem').val() || '').trim();
    if (!problem) {
      alert('Describe el problema');
      return;
    }
    var $btn = $(this);
    var $status = $('#ai-fix-status');
    $btn.prop('disabled', true);
    $status.removeClass('hidden text-rose text-teal').addClass('text-ink-soft/65').text('MIIA está analizando y corrigiendo… esto puede tardar un minuto.');
    $.ajax({
      url: @json(route('admin.store.design.ai-fix')),
      method: 'POST',
      data: {
        _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').first().val(),
        problem: problem,
        scope: $('#ai-fix-scope').val() || 'both',
        page_id: @json($aiFixPageId)
      }
    }).done(function (res) {
      $status.removeClass('text-ink-soft/65').addClass('text-teal').text((res.message || 'Listo') + ' ' + (res.summary || ''));
      if (window.AdminToast) AdminToast.success(res.message || 'Corregido');
      setTimeout(function () { window.location.reload(); }, 900);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'No se pudo resolver';
      $status.removeClass('text-ink-soft/65').addClass('text-rose').text(msg);
      if (window.AdminToast) AdminToast.error(msg);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  var themeApplyBase = @json(url('/admin/store/themes'));
  function syncSmartThemeBtn() {
    var $opt = $('#smart-theme-select option:selected');
    var val = $opt.val() || '';
    var $btn = $('#smart-theme-btn');
    if (!val || $opt.is(':disabled')) {
      $btn.prop('disabled', true).text('Asignar');
      return;
    }
    $btn.prop('disabled', false).text('Asignar copia');
  }
  $('#smart-theme-select').on('change', syncSmartThemeBtn);
  syncSmartThemeBtn();
  $('#smart-theme-form').on('submit', function () {
    var $opt = $('#smart-theme-select option:selected');
    var id = $opt.data('id') || $opt.val();
    if (!id || $opt.is(':disabled')) return false;
    this.action = themeApplyBase + '/' + id + '/apply';
  });

  $('#zip-upload-input').on('change', function () {
    var input = this;
    if (!input.files || !input.files.length) return;
    var file = input.files[0];
    var name = file.name || 'theme.zip';
    if (!/\.zip$/i.test(name)) {
      alert('El archivo debe ser un .zip');
      input.value = '';
      return;
    }
    $('#zip-upload-filename').text(name + ' (' + Math.round(file.size / 1024) + ' KB)');
    if (!confirm('¿Importar «' + name + '»? Se crearán/actualizarán páginas y assets.')) {
      input.value = '';
      $('#zip-upload-filename').text('');
      return;
    }
    $('#zip-upload-label').text('Importando…');
    $('#zip-upload-status').removeClass('hidden');
    $('#zip-upload-trigger').addClass('pointer-events-none opacity-60');
    // Envío nativo del form (multipart)
    document.getElementById('zip-upload-form').submit();
  });
})(jQuery);
</script>
@endpush
