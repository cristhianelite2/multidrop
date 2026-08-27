@extends('layouts.admin')

@section('title', 'Plantillas')
@section('heading', 'Plantillas')
@section('subheading', 'Biblioteca de plataforma. Independiente de tiendas y mini-tiendas.')

@section('content')
@php $stores = $stores ?? collect(); @endphp

<div class="admin-blocks mb-5">
<div class="admin-card p-4 sm:p-5 space-y-3">
    <div>
        <h2 class="font-display text-lg font-bold text-ink">Subir ZIP de Claude / diseñador</h2>
        <p class="text-sm text-ink-soft/65">
            Empaqueta <code>theme.css</code>, <code>modules.css</code>, <code>layout.json</code> y <code>assets/</code>.
            El HTML de catálogo/PDP/carrito lo genera Multidrop. FAQ: <code>pages/faq.twig</code>. Máx. 20&nbsp;MB.
        </p>
    </div>
    <form id="tpl-zip-form" method="post" action="{{ route('admin.templates.store') }}" enctype="multipart/form-data" class="space-y-3">
        @csrf
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Nombre</label>
                <input name="name" class="admin-input" placeholder="Ej. Aurora solar">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Descripción</label>
                <input name="description" class="admin-input" placeholder="Opcional">
            </div>
        </div>
        <label class="admin-btn-secondary !py-2 text-xs cursor-pointer inline-flex items-center gap-2" id="tpl-zip-trigger">
            <span id="tpl-zip-label">Elegir ZIP</span>
            <input type="file" name="zip" id="tpl-zip-input" accept=".zip,application/zip,application/x-zip-compressed" class="sr-only" required>
        </label>
        <span id="tpl-zip-filename" class="text-xs text-ink-soft/60"></span>
        <button class="admin-btn">Importar plantilla</button>
    </form>
</div>

<div class="admin-card p-4 sm:p-5 space-y-3" data-collapse-default="1">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-bold text-ink">Brief para Claude</h2>
            <p class="text-sm text-ink-soft/65">Copia este prompt, pide varias plantillas y súbelas como ZIP. Incluye flujo post-compra, idioma, traducción con MIIA y anti-errores.</p>
        </div>
        <button type="button" class="admin-btn-secondary !py-1.5 text-xs" id="copy-tpl-prompt">Copiar prompt</button>
    </div>
    <textarea id="tpl-prompt-text" rows="32" class="admin-input font-mono text-[11px] leading-relaxed" readonly>{{ $designerPrompt }}</textarea>
</div>
</div>

<div class="admin-card overflow-hidden">
    <div class="border-b border-line px-4 py-3">
        <h2 class="font-display text-base font-bold text-ink">Biblioteca</h2>
        <p class="text-xs text-ink-soft/60">Edita de forma global. Al asignarla a una tienda se clona: la tienda personaliza su copia y no puede borrar esta global.</p>
    </div>
    <div class="grid gap-3 p-4 sm:grid-cols-2">
        @forelse($themes as $theme)
            <div class="rounded-2xl border border-line bg-white p-4 space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-ink">{{ $theme->name }}</div>
                        <div class="text-xs text-ink-soft/60">
                            {{ $theme->pagesCount() }} páginas · {{ $theme->assetsCount() }} assets
                            · <code>/t/{{ $theme->slug }}</code>
                        </div>
                        @if($theme->description)
                            <p class="mt-1 text-sm text-ink-soft/70">{{ $theme->description }}</p>
                        @endif
                    </div>
                    <span class="admin-badge bg-mist text-ink-soft">{{ $theme->source ?: 'zip' }}</span>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <a href="{{ route('admin.templates.edit', $theme) }}" class="admin-btn !py-1 !px-2 text-xs">Editar</a>
                    <a href="{{ route('theme.sandbox.show', $theme->slug) }}" target="_blank" class="admin-btn-secondary !py-1 !px-2 text-xs">Vista previa</a>
                    <button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-open-sandbox data-id="{{ $theme->id }}" data-name="{{ $theme->name }}">Probar flujo</button>
                    <button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-open-apply data-id="{{ $theme->id }}" data-name="{{ $theme->name }}">Asignar a tienda</button>
                    <button type="button"
                            class="admin-btn-secondary !py-1 !px-2 text-xs"
                            data-open-translate
                            data-id="{{ $theme->id }}"
                            data-name="{{ $theme->name }}"
                            data-url="{{ route('admin.templates.translate', $theme) }}"
                            @disabled(!($has_miia ?? false))
                            title="{{ ($has_miia ?? false) ? 'Traducir copy de esta plantilla global con MIIA' : 'Configura MIIA en General' }}">
                        ✨ Traducir plantilla
                    </button>
                    <form method="post" action="{{ route('admin.templates.destroy', $theme) }}" onsubmit="return confirm('¿Eliminar la plantilla GLOBAL «{{ $theme->name }}» de la biblioteca?\n\nLas copias ya asignadas a tiendas o mini-tiendas NO se borran.')">
                        @csrf @method('DELETE')
                        <button class="admin-btn-danger !py-1 !px-2 text-xs">Eliminar de biblioteca</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-sm text-ink-soft/60 sm:col-span-2">Aún no hay plantillas. Sube un ZIP para empezar.</p>
        @endforelse
    </div>
</div>

@if(!($has_miia ?? false))
    <p class="mt-4 text-xs text-amber">MIIA no está configurada. Ve a Admin → General para añadir la API Key y poder traducir plantillas.</p>
@endif

@include('admin.partials.design-translate-modal', [
    'translateUrl' => '',
    'translateLocales' => $translate_locales ?? [],
    'translateDefaultLocale' => 'es_MX',
    'has_miia' => $has_miia ?? false,
    'translateScopeLabel' => 'plantilla global',
])

<div id="tpl-apply-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <form method="post" action="#" id="tpl-apply-form" class="admin-card w-full max-w-md p-5 space-y-4">
        @csrf
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Aplicar «<span id="tpl-apply-name"></span>»</h3>
            <button type="button" class="text-ink-soft" id="close-tpl-apply">×</button>
        </div>
        <p class="text-sm text-ink-soft/70">Se clona a la tienda como única plantilla asignada. Después se edita solo esa copia, sin afectar la global. La tienda no puede eliminar esta plantilla de la biblioteca.</p>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Tienda o mini-tienda</label>
            <select name="store_id" class="admin-input" required>
                <option value="">Selecciona…</option>
                @foreach($stores as $s)
                    <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->store_type }} · {{ $s->slug }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Nombre del diseño en la tienda</label>
            <input name="name" id="tpl-apply-design-name" class="admin-input" placeholder="Opcional">
        </div>
        <button class="admin-btn w-full">Asignar copia a la tienda</button>
    </form>
</div>

@php $sandboxModuleOptions = $sandboxModuleOptions ?? []; @endphp
<div id="tpl-sandbox-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <form method="post" action="#" id="tpl-sandbox-form" class="admin-card w-full max-w-md p-5 space-y-4" target="_blank">
        @csrf
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Probar «<span id="tpl-sandbox-name"></span>»</h3>
            <button type="button" class="text-ink-soft" id="close-tpl-sandbox">×</button>
        </div>
        <p class="text-sm text-ink-soft/70">Elige módulos activos en el sandbox (todos marcados por defecto). Al abrir se reinicia carrito y sesión del flujo.</p>
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
  $('#copy-tpl-prompt').on('click', function () {
    var text = $('#tpl-prompt-text').val();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        if (window.AdminToast) AdminToast.success('Prompt copiado');
      });
    }
  });
  $('#tpl-zip-input').on('change', function () {
    var f = this.files && this.files[0];
    if (!f) return;
    $('#tpl-zip-filename').text(f.name + ' (' + Math.round(f.size / 1024) + ' KB)');
    $('#tpl-zip-label').text(f.name);
  });
  var applyBase = @json(url('/admin/templates'));
  $('[data-open-apply]').on('click', function () {
    var id = $(this).data('id');
    var name = $(this).data('name') || '';
    $('#tpl-apply-form').attr('action', applyBase + '/' + id + '/apply');
    $('#tpl-apply-name').text(name);
    $('#tpl-apply-design-name').val(name);
    $('#tpl-apply-modal').removeClass('hidden').addClass('flex');
  });
  $('#close-tpl-apply').on('click', function () {
    $('#tpl-apply-modal').addClass('hidden').removeClass('flex');
  });
  $('#tpl-apply-modal').on('click', function (e) {
    if (e.target === this) $(this).addClass('hidden').removeClass('flex');
  });
  $('[data-open-sandbox]').on('click', function () {
    var id = $(this).data('id');
    var name = $(this).data('name') || '';
    $('#tpl-sandbox-form').attr('action', applyBase + '/' + id + '/sandbox');
    $('#tpl-sandbox-name').text(name);
    $('#tpl-sandbox-modal').removeClass('hidden').addClass('flex');
  });
  $('#close-tpl-sandbox').on('click', function () {
    $('#tpl-sandbox-modal').addClass('hidden').removeClass('flex');
  });
  $('#tpl-sandbox-modal').on('click', function (e) {
    if (e.target === this) $(this).addClass('hidden').removeClass('flex');
  });

  var $tMenu = $('#design-translate-menu');
  var $tSearch = $('#design-translate-search');
  var $tEmpty = $('#design-translate-empty');
  var translateThemeName = '';

  function openTranslate(show) {
    $('#design-translate-modal').toggleClass('hidden', !show).toggleClass('flex', !!show);
    if (show) {
      $tMenu.addClass('hidden');
      $tSearch.val('').trigger('input');
    }
  }
  function setTFlag($el, iso) {
    $el.removeClass(function (i, cls) { return (cls.match(/(^|\s)fi-\S+/g) || []).join(' '); });
    if (iso) $el.addClass('fi-' + iso);
  }

  $('[data-open-translate]').on('click', function () {
    var url = String($(this).data('url') || '');
    translateThemeName = String($(this).data('name') || 'plantilla');
    $('#run-design-translate').data('url', url);
    $('#design-translate-modal h3').text('Traducir plantilla global');
    openTranslate(true);
  });
  $('[data-close-translate]').on('click', function () { openTranslate(false); });
  $('#design-translate-modal').on('click', function (e) {
    if (e.target === this) openTranslate(false);
  });
  $('#design-translate-toggle').on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $tMenu.toggleClass('hidden');
    if (!$tMenu.hasClass('hidden')) $tSearch.focus();
  });
  $tMenu.on('click', function (e) { e.stopPropagation(); });
  $(document).on('click', function () { $tMenu.addClass('hidden'); });
  $tMenu.on('click', '.design-translate-option', function () {
    var $o = $(this);
    $('#design-translate-locale').val($o.data('locale'));
    setTFlag($('#design-translate-flag'), String($o.data('iso') || ''));
    $('#design-translate-label').text($o.data('name'));
    $('#design-translate-meta').text($o.data('locale'));
    $('.design-translate-option').removeClass('bg-teal/10 ring-1 ring-teal/20');
    $o.addClass('bg-teal/10 ring-1 ring-teal/20');
    $tMenu.addClass('hidden');
  });
  $tSearch.on('input', function () {
    var q = $.trim($(this).val()).toLowerCase();
    var visible = 0;
    $('.design-translate-option').each(function () {
      var match = !q || String($(this).data('search')).indexOf(q) !== -1;
      $(this).toggleClass('hidden', !match);
      if (match) visible++;
    });
    $tEmpty.toggleClass('hidden', visible > 0);
  });
  $('#run-design-translate').on('click', function () {
    var locale = String($('#design-translate-locale').val() || '').trim();
    var url = String($(this).data('url') || '');
    if (!locale) {
      alert('Elige un idioma');
      return;
    }
    if (!url) {
      alert('Plantilla no válida');
      return;
    }
    if (!confirm('¿Traducir «' + translateThemeName + '» a ' + locale + ' con MIIA?\nAfecta la plantilla global. Las copias ya asignadas a tiendas no cambian solas.')) {
      return;
    }
    var $btn = $(this);
    var $status = $('#design-translate-status');
    $btn.prop('disabled', true);
    $status.removeClass('hidden text-rose text-teal').addClass('text-ink-soft/65').text('MIIA está traduciendo página por página… no cierres esta ventana.');
    $.ajax({
      url: url,
      method: 'POST',
      timeout: 600000,
      data: {
        _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').first().val(),
        locale: locale
      }
    }).done(function (res) {
      $status.removeClass('text-ink-soft/65').addClass('text-teal').text((res.message || 'Listo') + ' ' + (res.summary || ''));
      if (window.AdminToast) AdminToast.success(res.message || 'Traducido');
      setTimeout(function () { window.location.reload(); }, 1100);
    }).fail(function (xhr) {
      var msg = (xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || 'No se pudo traducir';
      $status.removeClass('text-ink-soft/65').addClass('text-rose').text(msg);
      if (window.AdminToast) AdminToast.error(msg);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });
})(jQuery);
</script>
@endpush
