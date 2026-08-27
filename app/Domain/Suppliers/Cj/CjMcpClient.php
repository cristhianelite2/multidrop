<?php

namespace App\Domain\Suppliers\Cj;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente MCP remoto de CJ Dropshipping (Streamable HTTP / JSON-RPC).
 * URL: config('cj.mcp_base_url')/{accessToken}
 *
 * @see https://developers.cjdropshipping.com/en/api/api2/mcp.html
 */
class CjMcpClient
{
    protected ?string $sessionId = null;

    protected int $rpcId = 1;

    public function __construct(
        protected CjConnector $connector
    ) {}

    public function mcpUrl(): ?string
    {
        return $this->connector->mcpServerUrl();
    }

    /**
     * @return array{success: bool, tools?: array, error?: string, raw?: mixed}
     */
    public function listTools(): array
    {
        $init = $this->ensureSession();
        if (! ($init['success'] ?? false)) {
            return $init;
        }

        return $this->rpc('tools/list', new \stdClass);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{success: bool, content?: mixed, structured?: mixed, error?: string, via?: string, raw?: mixed}
     */
    public function callTool(string $name, array $arguments = []): array
    {
        // El catálogo REST de CJ es más estable que el tool MCP (texto libre + matching débil).
        if ($name === 'search_products') {
            $rest = $this->connector->searchProducts([
                'keyword' => $arguments['keyWord'] ?? $arguments['keyword'] ?? $arguments['q'] ?? null,
                'page' => $arguments['pageNum'] ?? $arguments['page'] ?? 1,
                'per_page' => $arguments['pageSize'] ?? $arguments['size'] ?? 20,
                'country_code' => $arguments['countryCode'] ?? $arguments['country_code'] ?? null,
                'category_id' => $arguments['categoryId'] ?? $arguments['category_id'] ?? null,
            ]);

            $list = data_get($rest, 'data.list');
            if (($rest['success'] ?? false) && is_array($list) && $list !== []) {
                return [
                    'success' => true,
                    'via' => 'rest',
                    'structured' => $rest['data'] ?? $rest,
                    'content' => $rest['data'] ?? $rest,
                    'raw' => $rest,
                ];
            }

            $mcp = $this->callToolViaMcp($name, $arguments);
            if ($mcp['success'] ?? false) {
                $mcp['via'] = 'mcp';
                $mcp['rest_error'] = ($rest['success'] ?? false) ? 'empty_list' : ($rest['error'] ?? null);

                return $mcp;
            }

            return [
                'success' => false,
                'error' => 'REST: '.($rest['error'] ?? 'sin resultados').' | MCP: '.($mcp['error'] ?? 'fail'),
                'raw' => ['rest' => $rest, 'mcp' => $mcp],
            ];
        }

        $mcp = $this->callToolViaMcp($name, $arguments);
        if ($mcp['success'] ?? false) {
            $mcp['via'] = 'mcp';
        }

        return $mcp;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function callToolViaMcp(string $name, array $arguments): array
    {
        $url = $this->mcpUrl();
        if (! $url) {
            return ['success' => false, 'error' => 'Sin access token CJ. Autoriza la API Key en General.'];
        }

        $init = $this->ensureSession();
        if (! ($init['success'] ?? false) && empty($this->sessionId)) {
            // Intentar call directo (servidores stateless / token-in-URL)
            $direct = $this->rpc('tools/call', [
                'name' => $name,
                'arguments' => $arguments ?: new \stdClass,
            ], useSession: false);

            if ($direct['success'] ?? false) {
                return $this->normalizeToolResult($direct);
            }

            return [
                'success' => false,
                'error' => $init['error'] ?? $direct['error'] ?? 'No se pudo inicializar MCP CJ',
                'raw' => ['init' => $init, 'direct' => $direct],
            ];
        }

        $result = $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments ?: new \stdClass,
        ]);

        return $this->normalizeToolResult($result);
    }

    /**
     * @param  array<string, mixed>  $rpcResult
     * @return array{success: bool, content?: mixed, structured?: mixed, error?: string, raw?: mixed}
     */
    protected function normalizeToolResult(array $rpcResult): array
    {
        if (! ($rpcResult['success'] ?? false)) {
            return $rpcResult;
        }

        $result = $rpcResult['result'] ?? $rpcResult['raw']['result'] ?? null;
        if (! is_array($result)) {
            return [
                'success' => true,
                'content' => $result,
                'structured' => $result,
                'raw' => $rpcResult,
            ];
        }

        if (! empty($result['isError'])) {
            $text = $this->extractTextContent($result['content'] ?? null);

            return [
                'success' => false,
                'error' => $text ?: 'MCP tool error',
                'raw' => $rpcResult,
            ];
        }

        $structured = $result['structuredContent'] ?? null;
        $text = $this->extractTextContent($result['content'] ?? null);

        $parsed = $structured;
        if ($parsed === null && is_string($text)) {
            $decoded = json_decode($text, true);
            $parsed = json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
        }

        return [
            'success' => true,
            'content' => $parsed ?? $text,
            'structured' => $parsed,
            'raw' => $rpcResult,
        ];
    }

    protected function extractTextContent(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            } elseif (is_string($block)) {
                $parts[] = $block;
            }
        }

        return $parts ? implode("\n", $parts) : null;
    }

    /**
     * @return array{success: bool, error?: string, raw?: mixed}
     */
    protected function ensureSession(): array
    {
        if ($this->sessionId) {
            return ['success' => true];
        }

        $url = $this->mcpUrl();
        if (! $url) {
            return ['success' => false, 'error' => 'Sin MCP URL (falta access token CJ).'];
        }

        $response = $this->postJsonRpc($url, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass,
            'clientInfo' => [
                'name' => 'multidrop-admin',
                'version' => '1.0.0',
            ],
        ], useSession: false);

        if (! ($response['success'] ?? false)) {
            // Probar versión de protocolo más nueva
            $response = $this->postJsonRpc($url, 'initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'multidrop-admin',
                    'version' => '1.0.0',
                ],
            ], useSession: false);
        }

        if (! ($response['success'] ?? false)) {
            return $response;
        }

        $session = $response['session_id'] ?? null;
        if (is_string($session) && $session !== '') {
            $this->sessionId = $session;
        }

        // notification initialized (best-effort)
        $this->postJsonRpc($url, 'notifications/initialized', new \stdClass, useSession: true, isNotification: true);

        return ['success' => true, 'raw' => $response];
    }

    /**
     * @param  array<string, mixed>|\stdClass  $params
     * @return array{success: bool, result?: mixed, error?: string, raw?: mixed, session_id?: string}
     */
    protected function rpc(string $method, array|\stdClass $params, bool $useSession = true): array
    {
        $url = $this->mcpUrl();
        if (! $url) {
            return ['success' => false, 'error' => 'Sin MCP URL'];
        }

        return $this->postJsonRpc($url, $method, $params, $useSession);
    }

    /**
     * @param  array<string, mixed>|\stdClass  $params
     * @return array{success: bool, result?: mixed, error?: string, raw?: mixed, session_id?: string}
     */
    protected function postJsonRpc(
        string $url,
        string $method,
        array|\stdClass $params,
        bool $useSession = true,
        bool $isNotification = false
    ): array {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];

        if (! $isNotification) {
            $payload['id'] = $this->rpcId++;
        }

        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];

        if ($useSession && $this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $response = Http::timeout((int) config('cj.timeout', 60))
                ->withHeaders($headers)
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json')
                ->post($url);

            $sessionHeader = $response->header('Mcp-Session-Id') ?: $response->header('mcp-session-id');
            if (is_string($sessionHeader) && $sessionHeader !== '') {
                $this->sessionId = $sessionHeader;
            }

            $body = $response->body();
            $json = $this->parsePossiblySseJson($body);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => is_array($json)
                        ? (string) data_get($json, 'error.message', $body)
                        : ($body !== '' ? $body : 'HTTP '.$response->status()),
                    'raw' => $json ?? $body,
                    'session_id' => $this->sessionId,
                ];
            }

            if ($isNotification) {
                return ['success' => true, 'session_id' => $this->sessionId];
            }

            if (is_array($json) && isset($json['error'])) {
                return [
                    'success' => false,
                    'error' => (string) (data_get($json, 'error.message') ?: json_encode($json['error'])),
                    'raw' => $json,
                    'session_id' => $this->sessionId,
                ];
            }

            return [
                'success' => true,
                'result' => is_array($json) ? ($json['result'] ?? $json) : $json,
                'raw' => $json,
                'session_id' => $this->sessionId,
            ];
        } catch (\Throwable $e) {
            Log::warning('CJ MCP RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function parsePossiblySseJson(string $body): mixed
    {
        $trim = trim($body);
        if ($trim === '') {
            return null;
        }

        if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
            return json_decode($trim, true);
        }

        // SSE: event: message\ndata: {...}
        $dataLines = [];
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if ($dataLines) {
            $joined = implode('', $dataLines);
            $decoded = json_decode($joined, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return ['raw_text' => Str::limit($body, 2000)];
    }
}
