<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multidrop — plataforma interna (nunca tienda pública directa)
    |--------------------------------------------------------------------------
    | La experiencia pública es una mega-tienda con mini-tiendas por
    | sector, idioma y necesidad. Los dominios/paths son configurables.
    */

    'name' => 'Multidrop',

    'storefront_mode' => env('MULTIDROP_STOREFRONT_MODE', 'path'), // path|subdomain|apex

    'default_market_code' => env('MULTIDROP_DEFAULT_MARKET', 'MX'),

    'localhost' => [
        'enabled' => env('MULTIDROP_LOCALHOST', true),
        'base_path' => env('MULTIDROP_BASE_PATH', '/html/multidrop/public'),
    ],

    'mega_store' => [
        'enabled' => true,
        'group_by' => ['sector', 'locale', 'problem'],
    ],

    'human_in_the_loop' => [
        'require_approval_for_ads' => true,
        'require_approval_for_publish' => true,
        'max_daily_ad_spend' => (float) env('MULTIDROP_MAX_DAILY_AD_SPEND', 50),
        'single_operator' => true,
    ],

    'conversion' => [
        'default_playbook' => 'problem_urgency',
        'max_active_coupons_per_session' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Servicios de plataforma (on/off por mini-tienda)
    |--------------------------------------------------------------------------
    | El código es general; cada tienda solo habilita lo que usa.
    | Default true = no romper tiendas existentes.
    */
    'services' => [
        'commerce' => [
            'label' => 'Comercio',
            'desc' => 'Carrito, cupones, promociones, checkout y pedidos',
            'icon' => '%',
            'default' => true,
            'nav' => [
                ['label' => 'Promos', 'route' => 'admin.store.promotions.index', 'match' => 'admin.store.promotions.*', 'icon' => '%'],
                ['label' => 'Pedidos', 'route' => 'admin.store.orders.index', 'match' => 'admin.store.orders.*', 'icon' => '#'],
                ['label' => 'Clientes', 'route' => 'admin.store.customers.index', 'match' => 'admin.store.customers.*', 'icon' => '☺'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins de conversión (on/off por mini-tienda)
    |--------------------------------------------------------------------------
    */
    'plugins' => [
        'upsell' => [
            'label' => 'Upsell',
            'desc' => 'Ofertas al momento del pago',
            'icon' => '↑',
            'default' => true,
            'route' => 'admin.store.upsells.index',
            'match' => 'admin.store.upsells.*',
        ],
        'cross_sell' => [
            'label' => 'Cross Sell',
            'desc' => 'Productos recomendados / complementarios',
            'icon' => '⇄',
            'default' => true,
            'route' => 'admin.store.cross-sells.index',
            'match' => 'admin.store.cross-sells.*',
        ],
        'urgency' => [
            'label' => 'Urgencia',
            'desc' => 'Timer, stock bajo y barra de urgencia',
            'icon' => '⏱',
            'default' => true,
            'route' => 'admin.store.urgency.edit',
            'match' => 'admin.store.urgency.*',
        ],
        'roulette' => [
            'label' => 'Ruleta',
            'desc' => 'Ruleta fullscreen de premios (probabilidades) + slides de carrusel',
            'icon' => '◯',
            'default' => true,
            'route' => 'admin.store.roulette.index',
            'match' => 'admin.store.roulette.*',
        ],
        'social_proof' => [
            'label' => 'Prueba social',
            'desc' => 'Toasts de compras recientes (nombre, país, producto, hace X min)',
            'icon' => '◎',
            'default' => true,
            'route' => 'admin.store.social-proof.edit',
            'match' => 'admin.store.social-proof.*',
        ],
        'newsletter' => [
            'label' => 'Newsletter',
            'desc' => 'Captura de email + cupón personalizado al confirmar (también en checkout)',
            'icon' => '✉',
            'default' => true,
            'route' => 'admin.store.newsletter.edit',
            'match' => 'admin.store.newsletter.*',
        ],
        'cookies' => [
            'label' => 'Cookies',
            'desc' => 'Banner UE de consentimiento: necesarias, analítica (GA) y marketing (Meta Pixel)',
            'icon' => '◉',
            'default' => true,
            'route' => 'admin.store.cookies.edit',
            'match' => 'admin.store.cookies.*',
        ],
        'combos' => [
            'label' => 'Combos',
            'desc' => 'Packs: compra X piezas, X e Y, o ambas, con % o precio especial',
            'icon' => '▣',
            'default' => true,
            'route' => 'admin.store.combos.index',
            'match' => 'admin.store.combos.*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox CJ (pruebas de fulfillment)
    |--------------------------------------------------------------------------
    | La respuesta cruda de CJ solo se muestra en superadmin cuando esto
    | está activo (local por defecto). Los pedidos sandbox siempre se
    | persisten; el dump JSON es solo para depurar el flujo.
    */
    'sandbox_cj_debug' => filter_var(env('SANDBOX_CJ_DEBUG', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Antifraude ligero (checkout)
    |--------------------------------------------------------------------------
    */
    'fraud' => [
        'max_orders_per_hour' => (int) env('FRAUD_MAX_ORDERS_PER_HOUR', env('MULTIDROP_FRAUD_MAX_ORDERS_HOUR', 8)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing de tienda (Creatify + borradores Advantage+/Smart+)
    |--------------------------------------------------------------------------
    */
    'marketing' => [
        'max_video_mb' => (int) env('MARKETING_MAX_VIDEO_MB', 80),
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'creatify' => [
            'base_url' => rtrim((string) env('CREATIFY_BASE_URL', 'https://api.creatify.ai'), '/'),
            'api_id' => env('CREATIFY_API_ID', ''),
            'api_key' => env('CREATIFY_API_KEY', ''),
        ],
        'optimizer' => [
            'webhook' => env('MARKETING_OPTIMIZER_WEBHOOK', ''),
        ],
    ],

];
