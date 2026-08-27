<?php

return [
    /*
    | Tarifas base (USD) por país cuando CJ no cotiza.
    | Se usan también como listado del select de checkout.
    */
    'countries' => [
        'MX' => ['name' => 'México', 'rate' => 4.99, 'eta_min' => 8, 'eta_max' => 16],
        'US' => ['name' => 'Estados Unidos', 'rate' => 6.99, 'eta_min' => 7, 'eta_max' => 14],
        'CA' => ['name' => 'Canadá', 'rate' => 8.99, 'eta_min' => 8, 'eta_max' => 16],
        'ES' => ['name' => 'España', 'rate' => 9.99, 'eta_min' => 9, 'eta_max' => 18],
        'AR' => ['name' => 'Argentina', 'rate' => 11.99, 'eta_min' => 12, 'eta_max' => 22],
        'CO' => ['name' => 'Colombia', 'rate' => 9.49, 'eta_min' => 10, 'eta_max' => 20],
        'CL' => ['name' => 'Chile', 'rate' => 10.49, 'eta_min' => 10, 'eta_max' => 20],
        'PE' => ['name' => 'Perú', 'rate' => 9.99, 'eta_min' => 10, 'eta_max' => 20],
        'EC' => ['name' => 'Ecuador', 'rate' => 10.99, 'eta_min' => 10, 'eta_max' => 20],
        'GT' => ['name' => 'Guatemala', 'rate' => 8.99, 'eta_min' => 9, 'eta_max' => 18],
        'CR' => ['name' => 'Costa Rica', 'rate' => 9.49, 'eta_min' => 9, 'eta_max' => 18],
        'PA' => ['name' => 'Panamá', 'rate' => 9.49, 'eta_min' => 9, 'eta_max' => 18],
        'UY' => ['name' => 'Uruguay', 'rate' => 12.49, 'eta_min' => 12, 'eta_max' => 22],
        'PY' => ['name' => 'Paraguay', 'rate' => 11.49, 'eta_min' => 12, 'eta_max' => 22],
        'BO' => ['name' => 'Bolivia', 'rate' => 11.99, 'eta_min' => 12, 'eta_max' => 22],
        'BR' => ['name' => 'Brasil', 'rate' => 12.99, 'eta_min' => 12, 'eta_max' => 22],
        'GB' => ['name' => 'Reino Unido', 'rate' => 10.99, 'eta_min' => 9, 'eta_max' => 18],
        'DE' => ['name' => 'Alemania', 'rate' => 10.49, 'eta_min' => 9, 'eta_max' => 18],
        'FR' => ['name' => 'Francia', 'rate' => 10.49, 'eta_min' => 9, 'eta_max' => 18],
        'IT' => ['name' => 'Italia', 'rate' => 10.99, 'eta_min' => 9, 'eta_max' => 18],
        'PT' => ['name' => 'Portugal', 'rate' => 10.49, 'eta_min' => 9, 'eta_max' => 18],
        'NL' => ['name' => 'Países Bajos', 'rate' => 10.49, 'eta_min' => 9, 'eta_max' => 18],
        'BE' => ['name' => 'Bélgica', 'rate' => 10.49, 'eta_min' => 9, 'eta_max' => 18],
        'AU' => ['name' => 'Australia', 'rate' => 13.99, 'eta_min' => 12, 'eta_max' => 24],
        'NZ' => ['name' => 'Nueva Zelanda', 'rate' => 14.49, 'eta_min' => 12, 'eta_max' => 24],
        'JP' => ['name' => 'Japón', 'rate' => 12.99, 'eta_min' => 9, 'eta_max' => 18],
    ],

    'default_country' => 'MX',
    'from_country' => env('SHIPPING_FROM_COUNTRY', env('CJ_FROM_COUNTRY_CODE', 'CN')),
    /*
    | Moneda de las tarifas de tabla y (por defecto) de cotizaciones CJ / markup.
    | ShippingQuoteService convierte a la moneda de vitrina del visitante vía CurrencyService.
    */
    'currency' => strtoupper((string) env('SHIPPING_CURRENCY', 'USD')),
    'try_cj' => filter_var(env('SHIPPING_TRY_CJ', true), FILTER_VALIDATE_BOOLEAN),
    'cj_timeout_seconds' => 8,
    'markup' => (float) env('SHIPPING_MARKUP', 0), // monto fijo extra sobre CJ
    'markup_percent' => (float) env('SHIPPING_MARKUP_PERCENT', 10),
];
