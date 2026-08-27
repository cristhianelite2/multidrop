<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Services\Buyer\BuyerPortalLocale;
use App\Services\Currency\CurrencyService;
use Illuminate\Http\Request;

/**
 * Preferencias de visitante (idioma / moneda) en el storefront.
 * Query: ?md_locale=es_MX&md_currency=MXN — se guardan en sesión.
 */
class StorefrontVisitorPrefs
{
    public function __construct(
        protected BuyerPortalLocale $locales,
        protected CurrencyService $currency,
    ) {}

    public function capture(Request $request, Store $store): void
    {
        $locale = trim((string) $request->query('md_locale', ''));
        $currency = strtoupper(trim((string) $request->query('md_currency', '')));

        if ($locale !== '') {
            $this->setLocale($store, $locale);
        }
        if ($currency !== '') {
            $this->setCurrency($store, $currency);
        }
    }

    public function setLocale(Store $store, string $locale): void
    {
        $allowed = $this->settingsLocales($store);
        $normalized = $this->locales->normalize($locale);
        // Prefer exact match from store list
        $pick = null;
        foreach ($allowed as $loc) {
            if (strcasecmp($loc, $locale) === 0 || $this->locales->normalize($loc) === $normalized) {
                $pick = $loc;
                break;
            }
        }
        if ($pick === null && $allowed !== []) {
            return;
        }
        if ($pick === null) {
            $pick = $locale;
        }
        session([$this->key($store, 'locale') => $pick]);
    }

    public function setCurrency(Store $store, string $currency): void
    {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            return;
        }
        $allowed = $this->settingsCurrencies($store);
        if ($allowed !== [] && ! in_array($currency, $allowed, true)) {
            return;
        }
        session([$this->key($store, 'currency') => $currency]);
    }

    public function locale(Store $store): string
    {
        $fromSession = trim((string) session($this->key($store, 'locale'), ''));
        $allowed = $this->settingsLocales($store);
        if ($fromSession !== '' && ($allowed === [] || in_array($fromSession, $allowed, true))) {
            return $fromSession;
        }

        return $this->settingsDefaultLocale($store);
    }

    public function currency(Store $store): string
    {
        $fromSession = strtoupper(trim((string) session($this->key($store, 'currency'), '')));
        $allowed = $this->settingsCurrencies($store);
        if (preg_match('/^[A-Z]{3}$/', $fromSession) && ($allowed === [] || in_array($fromSession, $allowed, true))) {
            return $fromSession;
        }

        return $this->settingsDefaultCurrency($store);
    }

    /**
     * @return list<string>
     */
    protected function settingsLocales(Store $store): array
    {
        // Sin leer overrides (evita circularidad al aplicar prefs)
        $raw = data_get($store->siteSettings(), 'locales', []);
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $loc) {
                $loc = trim((string) $loc);
                if ($loc !== '') {
                    $list[] = $loc;
                }
            }
        }
        $default = $this->settingsDefaultLocale($store);
        if ($default !== '' && ! in_array($default, $list, true)) {
            array_unshift($list, $default);
        }

        return array_values(array_unique($list));
    }

    protected function settingsDefaultLocale(Store $store): string
    {
        $locale = trim((string) data_get($store->siteSettings(), 'default_locale', ''));
        if ($locale !== '') {
            return $locale;
        }

        return (string) ($store->market?->locale ?: 'es_MX');
    }

    /**
     * @return list<string>
     */
    protected function settingsCurrencies(Store $store): array
    {
        $raw = data_get($store->siteSettings(), 'currencies', []);
        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $code) {
                $code = strtoupper(trim((string) $code));
                if (preg_match('/^[A-Z]{3}$/', $code)) {
                    $list[] = $code;
                }
            }
        }
        $default = $this->settingsDefaultCurrency($store);
        if ($default !== '' && ! in_array($default, $list, true)) {
            array_unshift($list, $default);
        }

        return array_values(array_unique($list));
    }

    protected function settingsDefaultCurrency(Store $store): string
    {
        $code = strtoupper(trim((string) data_get($store->siteSettings(), 'currency', '')));
        if ($code === '') {
            $code = strtoupper(trim((string) data_get($store->siteSettings(), 'default_currency', '')));
        }
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        return strtoupper((string) ($store->market?->currency ?: 'MXN'));
    }

    /**
     * Bind overrides for el request actual (cart / prices).
     */
    public function applyOverrides(Store $store): void
    {
        app()->instance('storefront.locale_override', $this->locale($store));
        app()->instance('storefront.currency_override', $this->currency($store));
    }

    protected function key(Store $store, string $field): string
    {
        return 'md.sf.'.(int) $store->id.'.'.$field;
    }
}
