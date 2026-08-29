<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Browser Rendering (scraping asistido)
    |--------------------------------------------------------------------------
    | Docs: https://developers.cloudflare.com/browser-run/quick-actions/content-endpoint/
    | Token custom (no hay plantilla): Account → Browser Rendering → Edit.
    */

    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'api_token' => env('CLOUDFLARE_API_TOKEN'),
    'enabled' => (bool) env('CLOUDFLARE_BROWSER_RENDERING', false),
    'timeout' => (int) env('CLOUDFLARE_BROWSER_TIMEOUT', 90),
    'goto_timeout_ms' => (int) env('CLOUDFLARE_BROWSER_GOTO_MS', 30000),
    'wait_until' => env('CLOUDFLARE_BROWSER_WAIT_UNTIL', 'networkidle2'),
    'user_agent' => env(
        'CLOUDFLARE_BROWSER_UA',
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Mobile Safari/537.36'
    ),

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Access (protección del panel /admin)
    |--------------------------------------------------------------------------
    | 1) Crea una Application en Zero Trust → Access → Applications
    | 2) Protege la ruta /admin*
    | 3) Copia Team domain + Application Audience (AUD)
    | 4) Activa CLOUDFLARE_ACCESS_ENABLED=true
    |
    | mode:
    |   optional = si hay JWT/header CF, hace SSO; si no, login local
    |   required = solo Cloudflare Access (bloquea login por password)
    */

    'access' => [
        'enabled' => (bool) env('CLOUDFLARE_ACCESS_ENABLED', false),
        'mode' => env('CLOUDFLARE_ACCESS_MODE', 'optional'),
        'team_domain' => env('CLOUDFLARE_ACCESS_TEAM_DOMAIN'),
        'audience' => env('CLOUDFLARE_ACCESS_AUD'),
        'verify_jwt' => (bool) env('CLOUDFLARE_ACCESS_VERIFY_JWT', true),
        'header_jwt' => env('CLOUDFLARE_ACCESS_JWT_HEADER', 'Cf-Access-Jwt-Assertion'),
        'header_email' => env('CLOUDFLARE_ACCESS_EMAIL_HEADER', 'Cf-Access-Authenticated-User-Email'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Turnstile (anti-bot en checkout / login comprador)
    |--------------------------------------------------------------------------
    | Docs: https://developers.cloudflare.com/turnstile/
    | También se pueden guardar claves en Admin → General (PlatformSetting).
    */
    'turnstile' => [
        'site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY'),
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
        // Permite trabajar en localhost/127.0.0.1 sin captcha real.
        'allow_local_bypass' => (bool) env('CLOUDFLARE_TURNSTILE_ALLOW_LOCAL_BYPASS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guías de zona (Bot Fight / AI Crawl Control)
    |--------------------------------------------------------------------------
    | Se configuran en el dashboard de Cloudflare; aquí solo checklist + docs.
    */
    'docs' => [
        'browser_rendering' => 'https://developers.cloudflare.com/browser-run/quick-actions/content-endpoint/',
        'bot_fight' => 'https://developers.cloudflare.com/bots/get-started/bot-fight-mode/',
        'ai_crawl' => 'https://developers.cloudflare.com/bots/concepts/bot/#ai-crawlers',
        'turnstile' => 'https://developers.cloudflare.com/turnstile/get-started/',
        'access' => 'https://developers.cloudflare.com/cloudflare-one/policies/access/',
        'waf' => 'https://developers.cloudflare.com/waf/',
    ],

];
