@extends('layouts.admin')

@section('title', 'Plantilla — '.$theme->name)
@section('heading', $theme->name)
@section('subheading', 'Edición global · sandbox /t/'.$theme->slug)

@section('content')
@php
    $checkout = $design['checkout'] ?? [];
    $pages = $design['pages'] ?? [];
    $assets = $design['assets'] ?? [];
    $stores = $stores ?? collect();
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('admin.templates.index') }}" class="admin-btn-secondary">← Plantillas</a>
    <button type="button" class="admin-btn" data-open-sandbox data-id="{{ $theme->id }}" data-name="{{ $theme->name }}">Probar flujo</button>
    <a href="{{ route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => 'catalog']) }}" target="_blank" class="admin-btn-secondary">Catálogo</a>
    <a href="{{ route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => 'cart']) }}" target="_blank" class="admin-btn-secondary">Carrito</a>
    <a href="{{ route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => 'checkout']) }}" target="_blank" class="admin-btn-secondary">Checkout</a>
    <a href="{{ route('theme.sandbox.track', $theme->slug) }}" target="_blank" class="admin-btn-secondary">Seguimiento</a>
</div>

<div class="admin-blocks mb-5">
<div class="admin-card p-4 sm:p-5 space-y-3">
    <h2 class="font-display text-lg font-bold text-ink">Datos</h2>
    <p class="text-sm text-ink-soft/65">Nombre y descripción de esta plantilla global.</p>
    <form method="post" action="{{ route('admin.templates.update', $theme) }}" class="space-y-3">
        @csrf @method('PUT')
        <input type="hidden" name="section" value="meta">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Nombre</label>
                <input name="name" value="{{ old('name', $theme->name) }}" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Slug sandbox</label>
                <input value="{{ $theme->slug }}" class="admin-input font-mono text-sm" readonly>
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium text-ink-soft">Descripción</label>
                <textarea name="description" rows="2" class="admin-input text-sm">{{ old('description', $theme->description) }}</textarea>
            </div>
        </div>
        <button class="admin-btn-secondary !py-1.5 text-xs">Guardar datos</button>
    </form>
</div>

<div class="admin-card p-4 sm:p-5 space-y-3">
    <h2 class="font-display text-lg font-bold text-ink">Aplicar a tienda</h2>
    <p class="text-sm text-ink-soft/65">Asigna una copia a la tienda. Los cambios posteriores en la tienda no afectan la biblioteca. La tienda no puede borrar esta global.</p>
    <form method="post" action="{{ route('admin.templates.apply', $theme) }}" class="space-y-3">
        @csrf
        <select name="store_id" class="admin-input" required>
            <option value="">Selecciona tienda o mini-tienda…</option>
            @foreach($stores as $s)
                <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->store_type }} · {{ $s->slug }})</option>
            @endforeach
        </select>
        <input name="name" class="admin-input" value="{{ $theme->name }}" placeholder="Nombre de la copia en la tienda">
        <button class="admin-btn">Asignar copia</button>
    </form>
</div>
</div>

<div class="admin-blocks mb-5">
<div class="admin-card p-4 sm:p-5 space-y-3" data-collapse-default="1">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold text-ink">Prompt / brief</h2>
            <p class="text-sm text-ink-soft/65">Para pedir otra versión o iterar con Claude.</p>
        </div>
        <button type="button" class="admin-btn-secondary !py-1.5 text-xs" id="copy-tpl-prompt">Copiar prompt</button>
    </div>
    <textarea id="tpl-prompt-text" rows="32" class="admin-input font-mono text-[11px] leading-relaxed" readonly>{{ $designerPrompt }}</textarea>
    <form method="post" action="{{ route('admin.templates.update', $theme) }}" class="space-y-2">
        @csrf @method('PUT')
        <input type="hidden" name="section" value="notes">
        <label class="mb-1 block text-sm font-medium text-ink-soft">Notas del brief</label>
        <textarea name="prompt_notes" rows="3" class="admin-input text-sm">{{ old('prompt_notes', $design['prompt_notes'] ?? '') }}</textarea>
        <button class="admin-btn-secondary !py-1.5 text-xs">Guardar notas</button>
    </form>
</div>
</div>

<div class="admin-card overflow-hidden mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <div>
            <h2 class="font-display text-base font-bold text-ink">Páginas</h2>
            <p class="text-xs text-ink-soft/60">Landing, catálogo, producto, carrito, checkout y páginas libres.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="post" action="{{ route('admin.templates.seed', $theme) }}" onsubmit="return confirm('¿Crear páginas base faltantes?')">
                @csrf
                <button class="admin-btn-secondary !py-1.5 text-xs">Crear plantilla base</button>
            </form>
            <button type="button" class="admin-btn !py-1.5 text-xs" id="open-create-page">+ Nueva página</button>
        </div>
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
                            <a class="admin-btn !px-2.5 !py-1 text-xs" href="{{ route('admin.templates.editor', [$theme, $p['id']]) }}">Editor visual</a>
                            <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" href="{{ route('admin.templates.pages.edit', [$theme, $p['id']]) }}">Código</a>
                            <a class="admin-btn-secondary !px-2.5 !py-1 text-xs" target="_blank"
                               href="{{ ($p['handle'] ?? '') === 'index' ? route('theme.sandbox.show', $theme->slug) : route('theme.sandbox.page', ['theme' => $theme->slug, 'handle' => $p['handle']]) }}">Probar</a>
                            @if(($p['type'] ?? '') !== 'landing')
                                <form method="post" action="{{ route('admin.templates.pages.destroy', [$theme, $p['id']]) }}" onsubmit="return confirm('¿Eliminar página?')">
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

<div id="create-page-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <div class="admin-card w-full max-w-md p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Nueva página</h3>
            <button type="button" class="text-ink-soft" id="close-create-page">×</button>
        </div>
        <form method="post" action="{{ route('admin.templates.pages.store', $theme) }}" class="space-y-3">
            @csrf
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Tipo</label>
                <select name="type" class="admin-input">
                    @foreach($pageTypes as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Título</label>
                <input name="title" class="admin-input" required>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Handle</label>
                <input name="handle" class="admin-input font-mono text-sm" placeholder="index, catalog, about…">
                <p class="mt-1 text-xs text-ink-soft/55">Sandbox: /t/{{ $theme->slug }}/pages/{handle}</p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="with_starter" value="1" checked class="rounded border-line text-teal">
                Cargar HTML base del tipo
            </label>
            <button class="admin-btn w-full">Crear y editar</button>
        </form>
    </div>
</div>

<form method="post" action="{{ route('admin.templates.update', $theme) }}" class="space-y-5" id="design-global-form">
    @csrf @method('PUT')
    <input type="hidden" name="section" value="theme">
    <div class="admin-card overflow-hidden mb-5">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-mist/40 px-3 py-2">
            <h2 class="font-display text-sm font-bold text-ink">Theme global</h2>
        </div>
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 p-2" data-design-tabs>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="css">CSS global</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="modules">Módulos</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="js">JS global</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="checkout">Checkout</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="assets">Assets</button>
        </div>
        <div class="p-3 sm:p-4 space-y-3" data-tab-panel="css">
            <div class="flex gap-2">
                <button type="button" class="admin-btn-secondary !py-1.5 text-xs" data-load-starter-css>Cargar CSS base</button>
            </div>
            <textarea name="global_css" rows="14" class="admin-input font-mono text-[12px]" spellcheck="false">{{ old('global_css', $design['global_css'] ?? '') }}</textarea>
        </div>
        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="modules">
            <p class="text-sm text-ink-soft/70">
                CSS de plugins (Upsell, Cross Sell, Urgencia, Ruleta, Prueba social, Newsletter). Hereda <code>--md-primary</code> / <code>--md-checkout-*</code>.
                Vacío = CSS base de módulos.
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
            <p class="text-sm text-ink-soft/70">Colores sandbox (`--md-checkout-*`).</p>
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
            <p class="text-sm text-ink-soft/70">Sube un asset suelto. Copia la URL al HTML.</p>
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
                        <form method="post" action="{{ route('admin.templates.assets.destroy', [$theme, $asset['id']]) }}">
                            @csrf @method('DELETE')
                            <button class="admin-btn-danger !px-2 !py-1 text-xs">Eliminar</button>
                        </form>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-ink-soft/55 text-sm">Sin assets aún.</li>
                @endforelse
            </ul>
        </div>
    </div>
    <button class="admin-btn">Guardar theme global</button>
</form>

<form id="asset-upload-form" method="post" action="{{ route('admin.templates.assets.upload', $theme) }}" enctype="multipart/form-data" class="hidden">
    @csrf
</form>
<script type="application/json" id="starter-global-css">@json($starterGlobalCss ?? '')</script>
<script type="application/json" id="starter-modules-css">@json($starterModulesCss ?? '')</script>

@php $sandboxModuleOptions = $sandboxModuleOptions ?? []; @endphp
<div id="tpl-sandbox-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <form method="post" action="{{ route('admin.templates.sandbox', $theme) }}" id="tpl-sandbox-form" class="admin-card w-full max-w-md p-5 space-y-4" target="_blank">
        @csrf
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Probar «{{ $theme->name }}»</h3>
            <button type="button" class="text-ink-soft" id="close-tpl-sandbox">×</button>
        </div>
        <p class="text-sm text-ink-soft/70">Módulos activos en el sandbox (todos marcados por defecto). Al abrir se reinicia carrito y sesión del flujo.</p>
        <div class="space-y-2">
            @foreach($sandboxModuleOptions as $opt)
                <label class="flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" name="modules[{{ $opt['key'] }}]" value="1" checked class="rounded border-line text-teal">
                    <span>{{ $opt['label'] }}</span>
                    <span class="text-xs text-ink-soft/55">{{ $opt['group'] }}</span>
                </label>
            @endforeach
        </div>
        <button class="admin-btn w-full">Abrir sandbox</button>
    </form>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
  $('[data-open-sandbox]').on('click', function () {
    $('#tpl-sandbox-modal').removeClass('hidden').addClass('flex');
  });
  $('#close-tpl-sandbox').on('click', function () {
    $('#tpl-sandbox-modal').addClass('hidden').removeClass('flex');
  });
  $('#tpl-sandbox-modal').on('click', function (e) {
    if (e.target === this) $(this).addClass('hidden').removeClass('flex');
  });
  $('#copy-tpl-prompt').on('click', function () {
    var text = $('#tpl-prompt-text').val();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (window.AdminToast) AdminToast.success('Prompt copiado');
      });
    }
  });
  function openModal(show) {
    $('#create-page-modal').toggleClass('hidden', !show).toggleClass('flex', show);
  }
  $('#open-create-page').on('click', function () { openModal(true); });
  $('#close-create-page').on('click', function () { openModal(false); });
  $('#create-page-modal').on('click', function (e) {
    if (e.target === this) openModal(false);
  });
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
})(jQuery);
</script>
@endpush
