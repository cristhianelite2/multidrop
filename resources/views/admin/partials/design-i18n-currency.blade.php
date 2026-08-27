{{--
  Idioma + moneda de plantilla/diseño.
  Vars: $formAction, $locales, $currencies, $defaultLocale, $defaultCurrency,
        $enabledLocales, $enabledCurrencies, $localeCurrencyMap, $formId
--}}
@php
    $formId = $formId ?? 'design-i18n-form';
    $locales = $locales ?? [];
    $currencies = $currencies ?? [];
    $defaultLocale = (string) ($defaultLocale ?: 'es_MX');
    $defaultCurrency = strtoupper((string) ($defaultCurrency ?: 'MXN'));
    $enabledLocales = collect($enabledLocales ?? [$defaultLocale])->map(fn ($l) => (string) $l)->all();
    if ($defaultLocale && ! in_array($defaultLocale, $enabledLocales, true)) {
        $enabledLocales[] = $defaultLocale;
    }
    $enabledCurrencies = collect($enabledCurrencies ?? [$defaultCurrency])->map(fn ($c) => strtoupper((string) $c))->all();
    if ($defaultCurrency && ! in_array($defaultCurrency, $enabledCurrencies, true)) {
        $enabledCurrencies[] = $defaultCurrency;
    }
    $localeCurrencyMap = $localeCurrencyMap ?? [];
@endphp

<div class="admin-card p-4 sm:p-5 space-y-3" data-no-collapse>
    <div>
        <h2 class="font-display text-lg font-bold text-ink">Idioma y moneda</h2>
        <p class="text-sm text-ink-soft/65">Defaults y listas compatibles de esta plantilla. El copy HTML activo usa el idioma por defecto.</p>
    </div>

    <form method="post" action="{{ $formAction }}" class="space-y-3" id="{{ $formId }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="{{ $section ?? 'i18n' }}">

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Idioma por defecto</label>
                <select name="default_locale" class="admin-input js-i18n-default-locale" required>
                    @foreach($locales as $loc)
                        <option value="{{ $loc['locale'] }}"
                                data-currency="{{ $localeCurrencyMap[$loc['locale']] ?? '' }}"
                                @selected($defaultLocale === $loc['locale'])>
                            {{ $loc['label'] ?? $loc['name'] }} · {{ $loc['locale'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-ink-soft">Moneda por defecto</label>
                <select name="default_currency" class="admin-input js-i18n-default-currency" required>
                    @foreach($currencies as $cur)
                        <option value="{{ $cur['code'] }}" @selected($defaultCurrency === $cur['code'])>
                            {{ $cur['code'] }} · {{ $cur['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-sm font-medium text-ink-soft">Idiomas compatibles</label>
                    <button type="button" class="admin-btn-secondary !py-0.5 !px-2 text-[10px] js-i18n-locales-all">Todos</button>
                </div>
                <div class="max-h-44 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                    @foreach($locales as $loc)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 hover:bg-mist/70">
                            <input type="checkbox" name="locales[]" value="{{ $loc['locale'] }}"
                                   class="js-i18n-locale-check rounded border-line text-teal"
                                   data-locale="{{ $loc['locale'] }}"
                                   @checked(in_array($loc['locale'], $enabledLocales, true))>
                            @if(!empty($loc['iso']))
                                <span class="market-flag fi fi-{{ $loc['iso'] }}"></span>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-xs text-ink">{{ $loc['label'] ?? $loc['name'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="block text-sm font-medium text-ink-soft">Monedas compatibles</label>
                    <button type="button" class="admin-btn-secondary !py-0.5 !px-2 text-[10px] js-i18n-currencies-all">Todas</button>
                </div>
                <div class="max-h-44 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                    @foreach($currencies as $cur)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 hover:bg-mist/70">
                            <input type="checkbox" name="currencies[]" value="{{ $cur['code'] }}"
                                   class="js-i18n-currency-check rounded border-line text-teal"
                                   data-code="{{ $cur['code'] }}"
                                   @checked(in_array($cur['code'], $enabledCurrencies, true))>
                            <span class="inline-flex min-w-[2.25rem] justify-center rounded bg-mist px-1 text-[10px] font-bold">{{ $cur['code'] }}</span>
                            <span class="min-w-0 flex-1 truncate text-xs text-ink">{{ $cur['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <button class="admin-btn-secondary !py-1.5 text-xs">Guardar idioma y moneda</button>
    </form>
</div>

@once
@push('scripts')
<script>
(function ($) {
  $(document).on('change', '.js-i18n-default-locale', function () {
    var $form = $(this).closest('form');
    var locale = String($(this).val() || '');
    var cur = String($(this).find('option:selected').data('currency') || '');
    if (locale) $form.find('.js-i18n-locale-check[data-locale="' + locale + '"]').prop('checked', true);
    if (cur) {
      $form.find('.js-i18n-default-currency').val(cur);
      $form.find('.js-i18n-currency-check[data-code="' + cur + '"]').prop('checked', true);
    }
  });
  $(document).on('change', '.js-i18n-default-currency', function () {
    var $form = $(this).closest('form');
    var code = String($(this).val() || '');
    if (code) $form.find('.js-i18n-currency-check[data-code="' + code + '"]').prop('checked', true);
  });
  $(document).on('click', '.js-i18n-locales-all', function () {
    $(this).closest('form').find('.js-i18n-locale-check').prop('checked', true);
  });
  $(document).on('click', '.js-i18n-currencies-all', function () {
    $(this).closest('form').find('.js-i18n-currency-check').prop('checked', true);
  });
  $(document).on('submit', 'form[id$="-i18n-form"], #tpl-meta-form, #tpl-i18n-form, #store-design-i18n-form', function () {
    var $form = $(this);
    var loc = String($form.find('.js-i18n-default-locale').val() || '');
    var cur = String($form.find('.js-i18n-default-currency').val() || '');
    if (loc) $form.find('.js-i18n-locale-check[data-locale="' + loc + '"]').prop('checked', true);
    if (cur) $form.find('.js-i18n-currency-check[data-code="' + cur + '"]').prop('checked', true);
  });
})(jQuery);
</script>
@endpush
@endonce
