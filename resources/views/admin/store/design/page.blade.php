@extends('layouts.admin')

@section('title', ($page['title'] ?? 'Página').' — Diseño')
@section('heading', $page['title'] ?? 'Página')
@section('subheading', ($pageTypes[$page['type']] ?? $page['type']).' · handle «'.$page['handle'].'»')

@section('content')
@php
    $designBackUrl = $designBackUrl ?? route('admin.store.design.edit');
    $designEditorUrl = $designEditorUrl ?? route('admin.store.design.editor', $page['id']);
    $designPreviewUrl = $designPreviewUrl ?? route('admin.store.design.preview', ['page' => $page['id']]);
    $designPublicUrl = $designPublicUrl ?? (($page['handle'] ?? '') === 'index'
        ? route('store.design.show', $store->slug)
        : route('store.design.page', ['slug' => $store->slug, 'handle' => $page['handle']]));
    $pageUpdateUrl = $pageUpdateUrl ?? route('admin.store.design.pages.update', $page['id']);
    $pageDestroyUrl = $pageDestroyUrl ?? route('admin.store.design.pages.destroy', $page['id']);
    $hideAiFix = $hideAiFix ?? false;
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ $designBackUrl }}" class="admin-btn-secondary">← Páginas</a>
    @if(($page['type'] ?? '') === 'page')
    <a href="{{ $designEditorUrl }}" class="admin-btn">Editor visual</a>
    @endif
    <a href="{{ $designPreviewUrl }}" target="_blank" class="admin-btn-secondary">Preview</a>
    <a href="{{ $designPreviewUrl.(str_contains($designPreviewUrl, '?') ? '&' : '?').'md_device=desktop' }}" target="_blank" class="admin-btn-secondary">Preview PC</a>
    <a href="{{ $designPreviewUrl.(str_contains($designPreviewUrl, '?') ? '&' : '?').'md_device=mobile' }}" target="_blank" class="admin-btn-secondary">Preview móvil</a>
    <a href="{{ $designPublicUrl }}" target="_blank" class="admin-btn-secondary">{{ !empty($hideAiFix) ? 'Probar' : 'Pública' }}</a>
    @unless($hideAiFix)
    <button type="button" class="admin-btn !py-2 text-xs" id="open-ai-fix"
        @disabled(!($has_miia ?? false))
        title="{{ ($has_miia ?? false) ? 'MIIA corrige esta página' : 'Configura MIIA en General' }}">
        Resolver con MIIA
    </button>
    @endunless
</div>

@unless($hideAiFix)
<div id="ai-fix-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink/40 p-4">
    <div class="admin-card w-full max-w-lg p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="font-display text-base font-bold text-ink">Resolver con MIIA</h3>
            <button type="button" class="text-ink-soft" id="close-ai-fix">×</button>
        </div>
        <p class="text-sm text-ink-soft/70">Describe qué falla en «{{ $page['title'] }}». Se enviará el HTML/CSS/JS de esta página (y opcionalmente el global).</p>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Problema</label>
            <textarea id="ai-fix-problem" rows="5" class="admin-input text-sm" placeholder="Ej: data-md-products no pinta cards; faltan tokens de tienda; el menú móvil no abre…"></textarea>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Alcance</label>
            <select id="ai-fix-scope" class="admin-input text-sm">
                <option value="page">Solo esta página</option>
                <option value="both">Página + CSS/JS global</option>
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
@endunless

<form method="post" action="{{ $pageUpdateUrl }}" class="space-y-5" id="page-form">
    @csrf
    @method('PUT')

    <div class="admin-blocks">
    <div class="admin-card p-4 sm:p-5 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink">Metadatos</h2>
        <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1 block text-sm font-medium text-ink-soft">Título</label>
            <input name="title" value="{{ old('title', $page['title']) }}" class="admin-input" required>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Handle</label>
            <input name="handle" value="{{ old('handle', $page['handle']) }}" class="admin-input font-mono text-sm" @disabled(($page['type'] ?? '') === 'landing')>
            @if(($page['type'] ?? '') === 'landing')
                <input type="hidden" name="handle" value="index">
            @endif
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-ink-soft">Estado</label>
            <select name="status" class="admin-input">
                <option value="draft" @selected(old('status', $page['status']) === 'draft')>draft</option>
                <option value="live" @selected(old('status', $page['status']) === 'live')>live</option>
            </select>
        </div>
        </div>
    </div>

    <div class="admin-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-mist/40 px-3 py-2">
            <h2 class="font-display text-sm font-bold text-ink">Código de la página</h2>
        </div>
        <div class="flex flex-wrap gap-1 border-b border-line bg-mist/40 p-2" data-page-tabs>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="layout">Módulos</button>
            @if(($page['type'] ?? '') === 'page')
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="html">HTML estático</button>
            @endif
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="css">CSS página</button>
            <button type="button" class="admin-btn-secondary !py-1.5 !px-3 text-xs" data-tab="js">JS página</button>
        </div>

        <div class="p-3 sm:p-4 space-y-3" data-tab-panel="layout">
            <p class="text-xs text-ink-soft/70">Orden de módulos de plataforma. El HTML no se edita aquí; solo el CSS de la plantilla lo estiliza. Ruleta, prueba social, newsletter, cookies y upsell se muestran según PC/móvil en <a class="text-teal underline" href="{{ route('admin.store.general.edit') }}">General de la tienda</a>.</p>
            <ol id="md-mod-order" class="space-y-2">
                @php
                    $modRegistry = app(\App\Services\Storefront\Modules\ModuleRegistry::class);
                    $pageModKeys = $modRegistry->keysOf(is_array($page['modules'] ?? null) ? $page['modules'] : []);
                @endphp
                @forelse($pageModKeys as $i => $modKey)
                    <li class="flex items-center gap-2 rounded-xl border border-line bg-white px-3 py-2">
                        <input type="hidden" data-mod-key name="modules[]" value="{{ $modKey }}">
                        <span class="font-mono text-sm flex-1">{{ $modKey }}</span>
                        <button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-mod-up>↑</button>
                        <button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-mod-down>↓</button>
                        <button type="button" class="admin-btn-danger !py-1 !px-2 text-xs" data-mod-del>×</button>
                    </li>
                @empty
                    <li class="text-xs text-ink-soft/60" data-mod-empty>Sin módulos (se usará el layout default al guardar vacío).</li>
                @endforelse
            </ol>
            <div class="flex flex-wrap gap-2 items-center">
                <select id="md-mod-add-key" class="admin-input text-sm max-w-[200px]">
                    @foreach(($moduleCatalog ?? []) as $k)
                        <option value="{{ $k }}">{{ $k }}</option>
                    @endforeach
                </select>
                <button type="button" class="admin-btn-secondary !py-1.5 text-xs" id="md-mod-add">Añadir</button>
            </div>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="html">
            <div class="flex flex-wrap gap-2">
                <button type="button" class="admin-btn-secondary !py-1.5 text-xs" data-load-starter>Cargar plantilla {{ $page['type'] }}</button>
            </div>
            <textarea name="html" rows="20" class="admin-input font-mono text-[12px] leading-relaxed" spellcheck="false">{{ old('html', $page['html'] ?? '') }}</textarea>
            <p class="text-xs text-ink-soft/60">
                Solo para páginas libres (FAQ / nosotros). Twig: <code>@{{ store.name }}</code> <code>@{{ urls.catalog }}</code>.
            </p>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="css">
            <textarea name="css" rows="16" class="admin-input font-mono text-[12px]" spellcheck="false">{{ old('css', $page['css'] ?? '') }}</textarea>
            <p class="text-xs text-ink-soft/55">Se concatena después del CSS global.</p>
        </div>

        <div class="p-3 sm:p-4 space-y-3 hidden" data-tab-panel="js">
            <textarea name="js" rows="16" class="admin-input font-mono text-[12px]" spellcheck="false">{{ old('js', $page['js'] ?? '') }}</textarea>
            <p class="text-xs text-ink-soft/55">Disponible: <code>Multidrop.*</code> (store, products, product, urls, checkout).</p>
        </div>
    </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <button class="admin-btn">Guardar página</button>
        @if(($page['type'] ?? '') !== 'landing')
            <button type="submit" form="delete-page-form" class="admin-btn-danger" onclick="return confirm('¿Eliminar página?')">Eliminar</button>
        @endif
    </div>
</form>

@if(($page['type'] ?? '') !== 'landing')
<form id="delete-page-form" method="post" action="{{ $pageDestroyUrl }}" class="hidden">
    @csrf @method('DELETE')
</form>
@endif

<script type="application/json" id="page-starter-html">@json($starterHtml)</script>
@endsection

@push('scripts')
<script>
(function ($) {
  $('[data-page-tabs] [data-tab]').on('click', function () {
    var tab = $(this).data('tab');
    $('[data-page-tabs] [data-tab]').removeClass('!bg-teal !text-white !border-teal');
    $(this).addClass('!bg-teal !text-white !border-teal');
    $('[data-tab-panel]').addClass('hidden');
    $('[data-tab-panel="'+tab+'"]').removeClass('hidden');
  }).first().trigger('click');

  function swapMod($li, dir) {
    if (dir < 0) $li.prev('li').not('[data-mod-empty]').before($li);
    else $li.next('li').not('[data-mod-empty]').after($li);
  }
  $('#md-mod-order').on('click', '[data-mod-up]', function () { swapMod($(this).closest('li'), -1); });
  $('#md-mod-order').on('click', '[data-mod-down]', function () { swapMod($(this).closest('li'), 1); });
  $('#md-mod-order').on('click', '[data-mod-del]', function () { $(this).closest('li').remove(); });
  $('#md-mod-add').on('click', function () {
    var k = String($('#md-mod-add-key').val() || '');
    if (!k) return;
    $('#md-mod-order [data-mod-empty]').remove();
    var $li = $('<li class="flex items-center gap-2 rounded-xl border border-line bg-white px-3 py-2">');
    $li.append($('<input type="hidden" data-mod-key name="modules[]">').val(k));
    $li.append($('<span class="font-mono text-sm flex-1">').text(k));
    $li.append('<button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-mod-up>↑</button>');
    $li.append('<button type="button" class="admin-btn-secondary !py-1 !px-2 text-xs" data-mod-down>↓</button>');
    $li.append('<button type="button" class="admin-btn-danger !py-1 !px-2 text-xs" data-mod-del>×</button>');
    $('#md-mod-order').append($li);
  });

  $('[data-load-starter]').on('click', function () {
    var html = '';
    try { html = JSON.parse($('#page-starter-html').text() || '""'); } catch (e) {}
    var $ta = $('textarea[name="html"]');
    if ($ta.val().trim() && !confirm('¿Reemplazar el HTML actual?')) return;
    $ta.val(html);
  });

  function openAiFix(show) {
    $('#ai-fix-modal').toggleClass('hidden', !show).toggleClass('flex', !!show);
  }
  if (!$('#ai-fix-modal').length) return;
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
    $status.removeClass('hidden text-rose text-teal').addClass('text-ink-soft/65').text('MIIA está analizando y corrigiendo…');
    $.ajax({
      url: @json(route('admin.store.design.ai-fix')),
      method: 'POST',
      data: {
        _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').first().val(),
        problem: problem,
        scope: $('#ai-fix-scope').val() || 'page',
        page_id: @json($page['id'] ?? null)
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
})(jQuery);
</script>
@endpush
