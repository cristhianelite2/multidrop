<?php

return [

    'default' => env('PAYMENT_DEFAULT', 'mercadopago'),

    'enabled' => array_filter(explode(',', env('PAYMENT_ENABLED', 'stripe,paypal,mercadopago'))),
    // Base pública opcional para callbacks/retornos de pasarelas (testing con dominio externo).
    // Ejemplo: https://tu-dominio-publico.com
    'public_base_url' => env('PAYMENTS_PUBLIC_BASE_URL'),

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    ],

];
