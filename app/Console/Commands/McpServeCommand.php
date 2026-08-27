<?php

namespace App\Console\Commands;

use App\Services\Mcp\ProductCatalogService;
use Illuminate\Console\Command;

/**
 * Servidor MCP mínimo (JSON-RPC 2.0 por STDIN/STDOUT).
 * Solo lectura: search_products y get_product.
 */
class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve';

    protected $description = 'Arranca el servidor MCP de catálogo (stdio, solo lectura)';

    public function handle(ProductCatalogService $catalog): int
    {
        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->error('No se pudo abrir STDIN.');

            return self::FAILURE;
        }

        stream_set_blocking($stdin, true);

        while (! feof($stdin)) {
            $message = $this->readMessage($stdin);
            if ($message === null) {
                break;
            }
            if ($message === '') {
                continue;
            }

            $request = json_decode($message, true);
            if (! is_array($request)) {
                $this->writeMessage($this->errorResponse(null, -32700, 'Parse error'));
                continue;
            }

            $response = $this->dispatch($request, $catalog);
            if ($response !== null) {
                $this->writeMessage(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            }
        }

        fclose($stdin);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    protected function dispatch(array $request, ProductCatalogService $catalog): ?array
    {
        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if ($method === 'notifications/initialized' || str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->ok($id, [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo' => [
                        'name' => 'multidrop-catalog',
                        'version' => '1.0.0',
                    ],
                    'capabilities' => [
                        'tools' => new \stdClass,
                    ],
                ]),
                'ping' => $this->ok($id, new \stdClass),
                'tools/list' => $this->ok($id, ['tools' => $this->tools()]),
                'tools/call' => $this->callTool($id, $params, $catalog),
                default => $this->errorResponse($id, -32601, 'Method not found: '.$method),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorResponse($id, -32603, 'Internal error');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function tools(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Busca productos del catálogo por nombre (o SKU/slug). Solo lectura. Máximo 20 resultados.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Texto a buscar en nombre, SKU o slug',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 20,
                            'description' => 'Cantidad máxima de resultados (default 10, máximo 20)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_product',
                'description' => 'Obtiene un producto por ID. Solo lectura. No incluye datos de clientes ni pedidos.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'ID numérico del producto',
                        ],
                    ],
                    'required' => ['product_id'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function callTool(mixed $id, array $params, ProductCatalogService $catalog): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === 'search_products') {
            $query = trim((string) ($args['query'] ?? ''));
            if ($query === '') {
                throw new \InvalidArgumentException('query es obligatorio y no puede estar vacío.');
            }
            $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
            if ($limit < 1) {
                throw new \InvalidArgumentException('limit debe ser un entero entre 1 y 20.');
            }
            $limit = min(20, $limit);
            $rows = $catalog->search($query, $limit);

            return $this->toolResult($id, $rows);
        }

        if ($name === 'get_product') {
            $productId = (int) ($args['product_id'] ?? 0);
            if ($productId < 1) {
                throw new \InvalidArgumentException('product_id debe ser un entero positivo.');
            }
            $row = $catalog->get($productId);
            if ($row === null) {
                return $this->toolResult($id, ['error' => 'Producto no encontrado', 'product_id' => $productId]);
            }

            return $this->toolResult($id, $row);
        }

        return $this->errorResponse($id, -32602, 'Herramienta desconocida: '.$name);
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>
     */
    protected function toolResult(mixed $id, mixed $payload): array
    {
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';

        return $this->ok($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ok(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param  resource  $stdin
     */
    protected function readMessage($stdin): ?string
    {
        $header = '';
        while (($line = fgets($stdin)) !== false) {
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
            $header .= $line;
        }
        if ($header === '' && feof($stdin)) {
            return null;
        }

        $length = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $header, $m)) {
            $length = (int) $m[1];
        }

        if ($length < 1) {
            // Fallback: una línea JSON (útil para pruebas manuales)
            $line = trim($header);
            if ($line !== '' && str_starts_with($line, '{')) {
                return $line;
            }
            $raw = fgets($stdin);
            if ($raw === false) {
                return feof($stdin) ? null : '';
            }

            return trim($raw);
        }

        $body = '';
        while (strlen($body) < $length && ! feof($stdin)) {
            $chunk = fread($stdin, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }

    protected function writeMessage(string $json): void
    {
        $payload = $json."\n";
        fwrite(STDOUT, 'Content-Length: '.strlen($payload)."\r\n\r\n".$payload);
        fflush(STDOUT);
    }
}
