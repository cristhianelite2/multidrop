<?php

return [

    'base_url' => env('CJ_API_BASE', 'https://developers.cjdropshipping.com/api2.0/v1'),

    'email' => env('CJ_EMAIL'),

    'api_key' => env('CJ_API_KEY'),

    'access_token' => env('CJ_ACCESS_TOKEN'),

    'refresh_token' => env('CJ_REFRESH_TOKEN'),

    'timeout' => (int) env('CJ_TIMEOUT', 30),

    /** País de salida del almacén CJ (ISO-2). createOrderV3 lo exige. */
    'from_country_code' => strtoupper((string) env('CJ_FROM_COUNTRY', 'CN')),

    /** Fallback si freightCalculate no devuelve una logística. */
    'default_logistic' => env('CJ_DEFAULT_LOGISTIC', 'CJPacket Ordinary'),

    /*
    | Reseñas (GET /product/productComments) al importar/sincronizar.
    | page_size máx. recomendado por CJ: 20. max_pages limita QPS.
    */
    'reviews_page_size' => (int) env('CJ_REVIEWS_PAGE_SIZE', 20),
    'reviews_max_pages' => (int) env('CJ_REVIEWS_MAX_PAGES', 5),

    /*
    | MCP remoto oficial (StreamableHTTP). El path usa el Access Token
    | obtenido con la API Key: https://developers.cjdropshipping.com/en/api/api2/mcp.html
    */
    'mcp_base_url' => env('CJ_MCP_BASE', 'https://developers.cjdropshipping.cn/mcp'),

    /*
    | Tipos de cambio aproximados USD → moneda local (lab CJ Search).
    | Puedes sobreescribir con CJ_FX_JSON={"MXN":17.5,"EUR":0.91}
    */
    'fx_rates_usd' => json_decode(env('CJ_FX_JSON', '{}'), true) ?: [],

];
