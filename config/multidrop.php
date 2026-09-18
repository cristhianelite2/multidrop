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

    /*
    |--------------------------------------------------------------------------
    | API interna (Bearer token)
    |--------------------------------------------------------------------------
    | Token para consumir /api/v1. Se puede pisar por tienda/plataforma con
    | PlatformSetting::put('api.public_token', $token, 'api', true).
    */
    'api_token' => env('MULTIDROP_API_TOKEN', ''),


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
        /*
         * Remotion Ads Studio (tools/remotion-ads): Whisper + MIIA + render local.
         * mode=local  -> el pipeline corre en la misma máquina que la app (dev/XAMPP).
         * mode=remote -> la app envía el job a un bridge por HTTP (túnel Cloudflare) y
         *                el render corre en la máquina donde vive tools/remotion-ads.
         */
        'remotion' => [
            'root' => env('REMOTION_ADS_ROOT', base_path('tools/remotion-ads')),
            'python' => env('REMOTION_ADS_PYTHON', 'python'),
            'timeout_seconds' => (int) env('REMOTION_ADS_TIMEOUT', 1800),
            'default_preset' => env('REMOTION_ADS_PRESET', 'product_presenter'),
            'mode' => env('REMOTION_ADS_MODE', 'local'),
            // Solo aplica con mode=remote: URL pública del bridge (túnel) y token compartido.
            'remote_url' => env('REMOTION_ADS_URL', ''),
            'remote_token' => env('REMOTION_ADS_TOKEN', ''),
            // En local/XAMPP: sync lanza `php artisan marketing:remotion-process` en background
            // (artisan serve es single-thread; afterResponse bloquearía el poll del UI).
            'sync' => filter_var(
                env('REMOTION_ADS_SYNC', env('APP_ENV', 'production') === 'local'),
                FILTER_VALIDATE_BOOL
            ),
        ],
        /*
         * Iframe de Seller Central para administrar publicaciones desde la campaña.
         * Por tienda se puede pisar en settings.marketing.sellercentral_embed_url.
         */
        'sellercentral' => [
            'embed_url' => env(
                'SELLERCENTRAL_EMBED_URL',
                'https://sellercentral.ceballosleon.com/embed/OTCTUN3Hh0rBRPMQvARtlt2t65IfgbNui67GzMf55spY0X5Y'
            ),
            /*
             * API externa de Seller Central (no iframe): /api/v1/key/*.
             * Autenticación: Authorization: Bearer <api_key del proyecto>.
             * Por tienda se puede pisar en settings.marketing.sellercentral_base_url.
             */
            'base_url' => env('SELLERCENTRAL_BASE_URL', 'https://sellercentral.ceballosleon.com'),
            // Máximo de publicaciones por plan generado por MIIA.
            'max_posts_per_plan' => (int) env('SELLERCENTRAL_MAX_POSTS_PER_PLAN', 100),
            'max_days' => (int) env('SELLERCENTRAL_MAX_DAYS', 60),
            'max_per_day' => (int) env('SELLERCENTRAL_MAX_PER_DAY', 10),
        ],
    ],

];
