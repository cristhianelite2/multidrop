<?php

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'miia'),

    /*
    | Mapa petición → motor MIIA (ids de /v1/models). Se sobreescribe desde
    | platform_settings.ai.task_engines (JSON).
    */
    'task_engines' => [],

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1.5'),
            'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1536'),
            'timeout' => 60,
            'image_timeout' => 180,
        ],

        'miia' => [
            'driver' => 'miia',
            'api_key' => env('MIIA_API_KEY'),
            'base_url' => env('MIIA_BASE_URL', 'https://ia.ceballosleon.com'),
            'model' => env('MIIA_MODEL', 'auto'),
            'timeout' => 90,
            'image_timeout' => 240,
            'image_size' => env('MIIA_IMAGE_SIZE', '1024x1536'),
            'image_quality' => env('MIIA_IMAGE_QUALITY', 'high'),
            'image_prompt_max' => 8000,
            'image_i2i_fallback_model' => env('MIIA_IMAGE_I2I_FALLBACK', 'google/gemini-2.0-flash-exp:free'),
            // Docs MIIA: POST {base}/v1/images/generations
            // gpt-image-* / dall-e-* → prioriza OpenAI. Sin model → proveedores free.
        ],

    ],

    'tasks' => [
        'combo_copy' => [
            'label' => 'Copy de combos',
            'hint' => 'Nombre, slug, descripción y prompt de imagen',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'combo_image' => [
            'label' => 'Imágenes promocionales de combos',
            'hint' => 'Usa gpt-image-1.5 (9:16, quality high, PNG)',
            'kind' => 'image',
            'default_engine' => 'gpt-image-1.5',
        ],
        'combo_landing' => [
            'label' => 'Landing del combo',
            'hint' => 'HTML/CSS de landing, PDP y CSS global',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'design_fix' => [
            'label' => 'Corrección de diseño',
            'hint' => 'MIIA corrige HTML/CSS/JS del theme',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'design_translate' => [
            'label' => 'Traducción de diseño',
            'hint' => 'Copy visible de plantillas y páginas',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'product_translate' => [
            'label' => 'Traducción de producto',
            'hint' => 'Nombre, badge y descripción',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'product_price' => [
            'label' => 'Sugerencia de precios',
            'hint' => 'Precio de vitrina por moneda',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'product_discovery' => [
            'label' => 'Discovery de productos',
            'hint' => 'Keywords y ángulos a partir de un problema',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'store_seo' => [
            'label' => 'SEO de tienda',
            'hint' => 'Título, meta, slogan y about',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'lab_prompt' => [
            'label' => 'Mejorar prompt (lab CJ)',
            'hint' => 'Reescribe el brief de búsqueda CJ',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
        'cj_search_plan' => [
            'label' => 'Keywords CJ (MCP)',
            'hint' => 'Plan de keywords y resumen de resultados',
            'kind' => 'chat',
            'default_engine' => 'free',
        ],
    ],

    /*
    | Modelos de imagen oficiales (ia.ceballosleon.com → Images API).
    | gpt-image-* y dall-e-* priorizan OpenAI. Sin model, MIIA usa free.
    */
    'preferred_image_engines' => [
        ['id' => 'gpt-image-1.5', 'label' => 'gpt-image-1.5', 'kind' => 'image'],
        ['id' => 'gpt-image-1', 'label' => 'gpt-image-1', 'kind' => 'image'],
        ['id' => 'dall-e-3', 'label' => 'dall-e-3', 'kind' => 'image'],
        ['id' => 'dall-e-2', 'label' => 'dall-e-2', 'kind' => 'image'],
        ['id' => 'google/gemini-2.0-flash-exp:free', 'label' => 'gemini-2.0-flash-exp:free', 'kind' => 'image'],
    ],

    /*
    | Proveedores MIIA válidos en `services` / `model` (ia.ceballosleon.com).
    | `free` no es un servicio: es model=auto (round-robin de capas gratis).
    */
    'miia_chat_services' => [
        'groq',
        'cerebras',
        'gemini',
        'openrouter',
        'cloudflare',
        'openai',
        'deepseek',
        'cohere',
        'mistral',
        'nim',
        'ollama',
    ],

    'preferred_chat_engines' => [
        ['id' => 'free', 'label' => 'free', 'kind' => 'chat'],
        ['id' => 'auto', 'label' => 'auto', 'kind' => 'chat'],
        ['id' => 'groq', 'label' => 'groq', 'kind' => 'chat'],
        ['id' => 'openai', 'label' => 'openai', 'kind' => 'chat'],
    ],

    'fallback_engines' => [
        ['id' => 'free', 'label' => 'free', 'kind' => 'chat'],
        ['id' => 'auto', 'label' => 'auto', 'kind' => 'chat'],
        ['id' => 'groq', 'label' => 'groq', 'kind' => 'chat'],
        ['id' => 'openai', 'label' => 'openai', 'kind' => 'chat'],
        ['id' => 'gpt-image-1.5', 'label' => 'gpt-image-1.5', 'kind' => 'image'],
        ['id' => 'gpt-image-1', 'label' => 'gpt-image-1', 'kind' => 'image'],
        ['id' => 'dall-e-3', 'label' => 'dall-e-3', 'kind' => 'image'],
        ['id' => 'dall-e-2', 'label' => 'dall-e-2', 'kind' => 'image'],
    ],

];
