<?php

namespace App\Services\Api;

use Illuminate\Support\Str;

/**
 * Exporta la API pública Multidrop (/api/v1) como Postman Collection v2.1,
 * formato que GetMan importa nativamente.
 *
 * @see https://mock.ceballosleon.com/help/import
 */
class MultidropApiCollectionExporter
{
    /**
     * @return array<string, mixed>
     */
    public function collection(string $baseUrl, string $token = ''): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        return [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => 'Multidrop API v1',
                'description' => "API pública REST de Multidrop.\n\n"
                    ."Autenticación: Bearer token (Authorization: Bearer {{api_token}}) o header X-Multidrop-Token.\n\n"
                    ."Compatible con GetMan / Postman Collection v2.1.\n"
                    .'Documentación de importación: https://mock.ceballosleon.com/help/import',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    ['key' => 'token', 'value' => '{{api_token}}', 'type' => 'string'],
                ],
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => $baseUrl, 'type' => 'string'],
                ['key' => 'api_token', 'value' => $token, 'type' => 'string'],
                ['key' => 'store_id', 'value' => '1', 'type' => 'string'],
                ['key' => 'product_id', 'value' => '1', 'type' => 'string'],
                ['key' => 'campaign_id', 'value' => '1', 'type' => 'string'],
                ['key' => 'media_id', 'value' => '1', 'type' => 'string'],
            ],
            'item' => [
                $this->folder('Tiendas', [
                    $this->request('Listar tiendas', 'GET', '{{base_url}}/stores', [
                        'description' => 'Filtros: q, status, store_type, per_page.',
                        'query' => [
                            ['key' => 'q', 'value' => '', 'description' => 'Búsqueda por nombre/slug', 'disabled' => true],
                            ['key' => 'status', 'value' => 'live', 'disabled' => true],
                            ['key' => 'per_page', 'value' => '20', 'disabled' => false],
                        ],
                    ]),
                    $this->request('Crear tienda', 'POST', '{{base_url}}/stores', [
                        'description' => 'Crea una tienda nueva.',
                        'body' => [
                            'name' => 'Mi tienda',
                            'slug' => 'mi-tienda',
                            'status' => 'draft',
                            'store_type' => 'principal',
                        ],
                    ]),
                    $this->request('Ver tienda', 'GET', '{{base_url}}/stores/{{store_id}}', [
                        'description' => 'Detalle con settings, theme y contacto.',
                    ]),
                    $this->request('Actualizar tienda', 'PUT', '{{base_url}}/stores/{{store_id}}', [
                        'description' => 'También acepta PATCH.',
                        'body' => [
                            'name' => 'Mi tienda actualizada',
                            'status' => 'live',
                        ],
                    ]),
                    $this->request('Eliminar tienda', 'DELETE', '{{base_url}}/stores/{{store_id}}', [
                        'description' => '409 si la tienda tiene pedidos.',
                    ]),
                    $this->request('Productos de la tienda', 'GET', '{{base_url}}/stores/{{store_id}}/products', [
                        'description' => 'Buscar productos por tienda. Filtros: q, status, is_featured, sort.',
                        'query' => [
                            ['key' => 'q', 'value' => '', 'disabled' => true],
                            ['key' => 'status', 'value' => 'live', 'disabled' => true],
                            ['key' => 'per_page', 'value' => '20', 'disabled' => false],
                        ],
                    ]),
                ]),
                $this->folder('Productos', [
                    $this->request('Listar productos', 'GET', '{{base_url}}/products', [
                        'description' => 'Filtros: q, status, source, store_id, per_page.',
                        'query' => [
                            ['key' => 'store_id', 'value' => '{{store_id}}', 'disabled' => false],
                            ['key' => 'q', 'value' => '', 'disabled' => true],
                            ['key' => 'per_page', 'value' => '20', 'disabled' => false],
                        ],
                    ]),
                    $this->request('Crear producto', 'POST', '{{base_url}}/products', [
                        'body' => [
                            'store_id' => 1,
                            'name' => 'Producto de ejemplo',
                            'sku' => 'SKU-001',
                            'price' => 199.99,
                            'currency' => 'MXN',
                            'status' => 'draft',
                            'is_featured' => false,
                        ],
                    ]),
                    $this->request('Ver producto', 'GET', '{{base_url}}/products/{{product_id}}'),
                    $this->request('Actualizar producto', 'PUT', '{{base_url}}/products/{{product_id}}', [
                        'description' => 'También acepta PATCH.',
                        'body' => [
                            'name' => 'Producto actualizado',
                            'price' => 249.99,
                            'status' => 'live',
                        ],
                    ]),
                    $this->request('Eliminar producto', 'DELETE', '{{base_url}}/products/{{product_id}}'),
                ]),
                $this->folder('Campañas', [
                    $this->request('Listar campañas', 'GET', '{{base_url}}/campaigns', [
                        'query' => [
                            ['key' => 'store_id', 'value' => '{{store_id}}', 'disabled' => false],
                            ['key' => 'status', 'value' => 'draft', 'disabled' => true],
                            ['key' => 'per_page', 'value' => '20', 'disabled' => false],
                        ],
                    ]),
                    $this->request('Crear campaña', 'POST', '{{base_url}}/campaigns', [
                        'body' => [
                            'store_id' => 1,
                            'name' => 'Campaña de ejemplo',
                            'status' => 'draft',
                            'daily_budget' => 50,
                        ],
                    ]),
                    $this->request('Ver campaña', 'GET', '{{base_url}}/campaigns/{{campaign_id}}'),
                    $this->request('Actualizar campaña', 'PUT', '{{base_url}}/campaigns/{{campaign_id}}', [
                        'body' => [
                            'name' => 'Campaña actualizada',
                            'status' => 'ready',
                        ],
                    ]),
                    $this->request('Eliminar campaña', 'DELETE', '{{base_url}}/campaigns/{{campaign_id}}'),
                    $this->request('Productos de la campaña', 'GET', '{{base_url}}/campaigns/{{campaign_id}}/products'),
                    $this->request('Agregar producto a la campaña', 'POST', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}'),
                    $this->request('Quitar producto de la campaña', 'DELETE', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}'),
                ]),
                $this->folder('Multimedia de campaña', [
                    $this->request('Listar multimedia', 'GET', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media'),
                    $this->request('Subir multimedia (URL)', 'POST', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media', [
                        'description' => 'Envía `url` o archivo multipart `file` (jpg/png/gif/webp/mp4/webm/mov).',
                        'body' => [
                            'url' => 'https://ejemplo.com/imagen.jpg',
                            'name' => 'Imagen principal',
                            'kind' => 'image',
                        ],
                    ]),
                    $this->request('Subir multimedia (archivo)', 'POST', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media', [
                        'description' => 'Multipart form-data con el campo `file`.',
                        'formdata' => true,
                    ]),
                    $this->request('Ver multimedia', 'GET', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media/{{media_id}}'),
                    $this->request('Actualizar multimedia', 'PUT', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media/{{media_id}}', [
                        'body' => [
                            'name' => 'Media renombrada',
                            'kind' => 'image',
                        ],
                    ]),
                    $this->request('Eliminar multimedia', 'DELETE', '{{base_url}}/campaigns/{{campaign_id}}/products/{{product_id}}/media/{{media_id}}'),
                ]),
            ],
        ];
    }

    /**
     * Entorno Postman importable en GetMan.
     *
     * @return array<string, mixed>
     */
    public function environment(string $baseUrl, string $token = '', string $name = 'Multidrop'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => $name,
            '_postman_variable_scope' => 'environment',
            '_postman_exported_at' => now()->toIso8601String(),
            '_postman_exported_using' => 'Multidrop',
            'values' => [
                ['key' => 'base_url', 'value' => rtrim($baseUrl, '/'), 'type' => 'default', 'enabled' => true],
                ['key' => 'api_token', 'value' => $token, 'type' => 'secret', 'enabled' => true],
                ['key' => 'store_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
                ['key' => 'product_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
                ['key' => 'campaign_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
                ['key' => 'media_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function folder(string $name, array $items): array
    {
        return [
            'name' => $name,
            'item' => $items,
        ];
    }

    /**
     * @param  array{description?: string, query?: list<array<string, mixed>>, body?: array<string, mixed>|null, formdata?: bool}  $opts
     * @return array<string, mixed>
     */
    protected function request(string $name, string $method, string $rawUrl, array $opts = []): array
    {
        $header = [
            ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text'],
        ];

        $url = ['raw' => $rawUrl];
        if (! empty($opts['query'])) {
            $url['query'] = $opts['query'];
            $parts = parse_url(str_replace(['{{base_url}}', '{{store_id}}', '{{product_id}}', '{{campaign_id}}', '{{media_id}}'], ['https://host/api/v1', '1', '1', '1', '1'], $rawUrl));
            if (is_array($parts)) {
                $url['host'] = ['{{base_url}}'];
                $path = trim((string) ($parts['path'] ?? ''), '/');
                if ($path !== '') {
                    // Keep path relative to variable for readability
                }
            }
        }

        $request = [
            'method' => strtoupper($method),
            'header' => $header,
            'url' => $url,
            'auth' => ['type' => 'inherit'],
        ];

        if (! empty($opts['description'])) {
            $request['description'] = $opts['description'];
        }

        if (! empty($opts['formdata'])) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'multipart/form-data', 'type' => 'text', 'disabled' => true];
            $request['body'] = [
                'mode' => 'formdata',
                'formdata' => [
                    ['key' => 'file', 'type' => 'file', 'src' => [], 'disabled' => false],
                    ['key' => 'name', 'value' => 'Archivo', 'type' => 'text', 'disabled' => false],
                    ['key' => 'kind', 'value' => 'image', 'type' => 'text', 'disabled' => true],
                ],
            ];
        } elseif (array_key_exists('body', $opts) && is_array($opts['body'])) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
            $request['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($opts['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        return [
            'name' => $name,
            'request' => $request,
            'response' => [],
        ];
    }
}
