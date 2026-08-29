<?php

return [

    /*
    | AliExpress Affiliate (portals.aliexpress.com).
    | No coloca pedidos: solo ficha de producto para Product Hunter.
    */
    'app_key' => env('ALIEXPRESS_APP_KEY'),

    'app_secret' => env('ALIEXPRESS_APP_SECRET'),

    'tracking_id' => env('ALIEXPRESS_TRACKING_ID'),

    'ship_to' => strtoupper((string) env('ALIEXPRESS_SHIP_TO', 'MX')),

    'target_currency' => strtoupper((string) env('ALIEXPRESS_CURRENCY', 'USD')),

    'target_language' => strtoupper((string) env('ALIEXPRESS_LANGUAGE', 'ES')),

    'gateway' => env('ALIEXPRESS_GATEWAY', 'https://api-sg.aliexpress.com/sync'),

    'timeout' => (int) env('ALIEXPRESS_TIMEOUT', 25),

    /** Producto de ejemplo para “Probar API” si el admin no pega otro ID. */
    'test_product_id' => env('ALIEXPRESS_TEST_PRODUCT_ID', '1005005993442954'),

];
