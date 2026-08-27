@extends('layouts.admin')

@section('title', 'Discovery IA')
@section('heading', 'Discovery IA')
@section('subheading', 'Problema → keywords → listado a importar')

@section('content')
@php
    $selectedCode = old('market', $input['market'] ?? 'MX');
    $selected = $markets->firstWhere('code', $selectedCode) ?? $markets->first();
    $isoOf = function ($m) {
        $iso = strtoupper((string) ($m->code ?? ''));
        if ($iso === 'UK') {
            $iso = 'GB';
        }

        return strlen($iso) === 2 ? strtolower($iso) : '';
    };
    $selectedIso = $isoOf($selected);
@endphp

    <div class="admin-card p-5 sm:p-6 mb-5">
        <h2 class="font-display text-lg font-bold text-ink mb-4">Consulta</h2>
        <form method="post" action="{{ route('admin.lab.discovery.run') }}" class="space-y-4" id="discovery-form">
            @csrf
            <div>
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Problema / necesidad</label>
                <textarea name="problem" rows="4" required class="admin-input">{{ old('problem', $input['problem'] ?? 'Regiones de México con calor extremo y apagones frecuentes necesitan iluminación y energía de respaldo') }}</textarea>
            </div>

            <div class="max-w-xl">
                <label class="mb-1.5 block text-sm font-medium text-ink-soft">Mercado / país</label>
                <input type="hidden" name="market" id="market-code" value="{{ $selected->code ?? 'MX' }}">

                <div id="market-picker" class="relative">
                    <button type="button" id="market-toggle" class="admin-input flex w-full items-center gap-3 text-left">
                        <span id="market-flag" class="market-flag fi {{ $selectedIso ? 'fi-'.$selectedIso : '' }}" @if($selectedIso) data-iso="{{ $selectedIso }}" @endif></span>
                        <span class="min-w-0 flex-1">
                            <span id="market-label" class="block truncate font-semibold text-ink">{{ $selected->name ?? 'Selecciona país' }}</span>
                            <span id="market-meta" class="block truncate text-xs text-ink-soft/60">
                                {{ $selected->code ?? '' }} · {{ $selected->currency ?? '' }} · {{ ($regionLabels[$selected->region ?? ''] ?? '') }}
                            </span>
                        </span>
                        <span class="text-ink-soft/50">▾</span>
                    </button>

                    <div id="market-menu" class="absolute left-0 right-0 z-40 mt-2 hidden overflow-hidden rounded-2xl border border-line bg-white shadow-xl shadow-ink/10">
                        <div class="border-b border-line p-2">
                            <input type="search" id="market-search" placeholder="Buscar país, código o moneda…" class="admin-input" autocomplete="off">
                        </div>
                        <div id="market-list" class="max-h-80 overflow-y-auto p-1.5">
                            @php $currentRegion = null; @endphp
                            @foreach($markets as $m)
                                @php $iso = $isoOf($m); @endphp
                                @if($currentRegion !== ($m->region ?? 'other'))
                                    @php $currentRegion = $m->region ?? 'other'; @endphp
                                    <div class="market-region px-2.5 py-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-soft/45" data-region="{{ $currentRegion }}">
                                        {{ $regionLabels[$currentRegion] ?? 'Otros' }}
                                    </div>
                                @endif
                                <button
                                    type="button"
                                    class="market-option flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-mist {{ ($selected->code ?? '') === $m->code ? 'bg-teal/10 ring-1 ring-teal/20' : '' }}"
                                    data-code="{{ $m->code }}"
                                    data-name="{{ $m->name }}"
                                    data-iso="{{ $iso }}"
                                    data-currency="{{ $m->currency }}"
                                    data-region="{{ $regionLabels[$m->region ?? ''] ?? '' }}"
                                    data-search="{{ strtolower(($m->name ?? '').' '.($m->code ?? '').' '.($m->currency ?? '').' '.($regionLabels[$m->region ?? ''] ?? '')) }}"
                                >
                                    <span class="market-flag fi {{ $iso ? 'fi-'.$iso : '' }}"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-ink">{{ $m->name }}</span>
                                        <span class="block truncate text-[11px] text-ink-soft/60">{{ $m->code }} · {{ $m->currency }} · {{ $m->locale }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        <div id="market-empty" class="hidden px-3 py-6 text-center text-sm text-ink-soft/60">Sin resultados</div>
                    </div>
                </div>
                <p class="mt-2 text-xs text-ink-soft/55">{{ $markets->count() }} mercados · banderas SVG (flag-icons) · América del Norte, Europa y Oceanía</p>
            </div>

            <button type="submit" class="admin-btn">Generar listado con IA</button>
        </form>
    </div>

    @isset($result)
        <div class="admin-blocks">
            <div class="admin-card p-5 sm:p-6">
                <div class="mb-3 flex items-center gap-2">
                    <h2 class="font-display text-lg font-bold text-ink">Brief IA</h2>
                    <span class="admin-badge bg-teal/10 text-teal">{{ $result['ai']['provider'] ?? 'n/a' }}</span>
                </div>
                @if(!($result['ai']['success'] ?? false))
                    <p class="mb-3 text-sm text-coral">{{ $result['ai']['error'] ?? 'Error IA' }}</p>
                @endif
                <pre class="overflow-auto rounded-xl bg-ink p-4 text-xs text-teal-bright">{{ json_encode($result['brief'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
            <div class="admin-card p-5 sm:p-6">
                <h2 class="font-display text-lg font-bold text-ink mb-3">Candidatos a importar</h2>
                <pre class="overflow-auto rounded-xl bg-ink p-4 text-xs text-sky-200">{{ json_encode($result['import_candidates'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endisset
@endsection

@push('scripts')
<script>
(function ($) {
  var $menu = $('#market-menu');
  var $toggle = $('#market-toggle');
  var $search = $('#market-search');
  var $empty = $('#market-empty');

  function closeMenu() {
    $menu.addClass('hidden');
  }

  function openMenu() {
    $menu.removeClass('hidden');
    $search.val('').trigger('input').focus();
  }

  function setFlagEl($el, iso) {
    $el.removeClass(function (i, cls) {
      return (cls.match(/(^|\s)fi-\S+/g) || []).join(' ');
    });
    if (iso) {
      $el.addClass('fi-' + iso).attr('data-iso', iso);
    } else {
      $el.removeAttr('data-iso');
    }
  }

  $toggle.on('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($menu.hasClass('hidden')) openMenu();
    else closeMenu();
  });

  $menu.on('click', function (e) { e.stopPropagation(); });
  $(document).on('click', closeMenu);

  $menu.on('click', '.market-option', function () {
    var $o = $(this);
    var iso = String($o.data('iso') || '');
    $('#market-code').val($o.data('code'));
    setFlagEl($('#market-flag'), iso);
    $('#market-label').text($o.data('name'));
    $('#market-meta').text($o.data('code') + ' · ' + $o.data('currency') + ' · ' + $o.data('region'));
    $('.market-option').removeClass('bg-teal/10 ring-1 ring-teal/20');
    $o.addClass('bg-teal/10 ring-1 ring-teal/20');
    closeMenu();
  });

  $search.on('input', function () {
    var q = $.trim($(this).val()).toLowerCase();
    var visible = 0;

    $('.market-region').addClass('hidden');
    $('.market-option').each(function () {
      var $o = $(this);
      var match = !q || String($o.data('search')).indexOf(q) !== -1;
      $o.toggleClass('hidden', !match);
      if (match) visible++;
    });

    $('.market-region').each(function () {
      var $r = $(this);
      var has = false;
      $r.nextUntil('.market-region').filter('.market-option').each(function () {
        if (!$(this).hasClass('hidden')) has = true;
      });
      $r.toggleClass('hidden', !has);
    });

    $empty.toggleClass('hidden', visible > 0);
  });
})(jQuery);
</script>
@endpush
