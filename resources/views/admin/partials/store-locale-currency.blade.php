@php
    $localeCode = $store->configuredLocale();
    $currencyCode = $store->configuredCurrency();
    $localeIso = $store->localeFlagIso();
    $currencyIso = $store->currencyFlagIso();
@endphp
<span class="inline-flex items-center gap-1">
    @if($localeIso !== '')
        <span class="market-flag fi fi-{{ $localeIso }}" title="{{ $localeCode }}"></span>
    @endif
    <span>{{ $localeCode }}</span>
</span>
<span class="mx-1">·</span>
<span class="inline-flex items-center gap-1">
    @if($currencyIso !== '')
        <span class="market-flag fi fi-{{ $currencyIso }}" title="{{ $currencyCode }}"></span>
    @endif
    <span>{{ $currencyCode }}</span>
</span>
