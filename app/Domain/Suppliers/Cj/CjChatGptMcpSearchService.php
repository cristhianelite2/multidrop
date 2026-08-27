<?php

namespace App\Domain\Suppliers\Cj;

use App\Domain\AI\AiTaskRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orquesta MIIA + catálogo CJ: el prompt se traduce a varias keywords
 * y se fusionan resultados (no una sola búsqueda genérica).
 */
class CjChatGptMcpSearchService
{
    public function __construct(
        protected CjMcpClient $mcp,
        protected CjConnector $connector,
        protected AiTaskRouter $ai
    ) {}

    /**
     * @return array{
     *   success: bool,
     *   products: Collection,
     *   total: int,
     *   answer?: string,
     *   keyword?: string,
     *   keywords?: array<int, string>,
     *   via?: string,
     *   provider?: string,
     *   tool_trace?: array,
     *   error?: string,
     *   raw?: mixed
     * }
     */
    public function searchByPrompt(string $prompt, string $countryCode = 'US', int $perPage = 20): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'products' => collect(), 'total' => 0, 'error' => 'Escribe un prompt.'];
        }

        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'products' => collect(),
                'total' => 0,
                'error' => 'Configura la API Key de MIIA en General para usar el plan de keywords CJ.',
            ];
        }

        if (! $this->connector->mcpServerUrl() && ! config('cj.api_key') && ! config('cj.access_token')) {
            return [
                'success' => false,
                'products' => collect(),
                'total' => 0,
                'error' => 'Autoriza la API Key de CJ en General (plataforma) para obtener token MCP.',
            ];
        }

        $plan = $this->planKeywords($prompt, $countryCode);
        if (! ($plan['success'] ?? false) || empty($plan['keywords'])) {
            return [
                'success' => false,
                'products' => collect(),
                'total' => 0,
                'error' => $plan['error'] ?? 'MIIA no pudo extraer keywords del prompt.',
                'provider' => 'miia',
                'raw' => $plan['raw'] ?? null,
            ];
        }

        /** @var list<string> $keywords */
        $keywords = $plan['keywords'];
        $perKeyword = max(6, (int) ceil($perPage / max(1, min(count($keywords), 4))));
        $perKeyword = min(20, $perKeyword);

        $trace = [];
        $buckets = [];
        $via = null;

        foreach ($keywords as $kw) {
            $args = [
                'keyWord' => $kw,
                'pageNum' => 1,
                'pageSize' => $perKeyword,
                'countryCode' => $countryCode,
            ];

            $toolResult = $this->mcp->callTool('search_products', $args);
            $via = $toolResult['via'] ?? $via;
            $trace[] = [
                'tool' => 'search_products',
                'arguments' => $args,
                'via' => $toolResult['via'] ?? null,
                'success' => (bool) ($toolResult['success'] ?? false),
                'error' => $toolResult['error'] ?? null,
            ];

            $list = collect();
            if ($toolResult['success'] ?? false) {
                $extracted = $this->extractProducts($toolResult['content'] ?? $toolResult['structured'] ?? null);
                $list = $extracted['products']->map(function (array $p) use ($kw) {
                    $p['matched_keyword'] = $kw;

                    return $p;
                });
            }

            $buckets[$kw] = $list;
        }

        $products = $this->mergeDiversified($buckets, $perPage);
        $keywordLabel = implode(' · ', $keywords);

        if ($products->isEmpty()) {
            return [
                'success' => false,
                'products' => $products,
                'total' => 0,
                'keyword' => $keywordLabel,
                'keywords' => $keywords,
                'via' => $via,
                'provider' => 'miia',
                'tool_trace' => $trace,
                'answer' => $plan['rationale'] ?? null,
                'error' => 'No hubo productos CJ para las keywords del prompt: '.$keywordLabel,
            ];
        }

        $answer = $this->summarizeResults($prompt, $countryCode, $keywords, $products, (string) ($plan['rationale'] ?? ''));

        return [
            'success' => true,
            'products' => $products,
            'total' => $products->count(),
            'answer' => $answer,
            'keyword' => $keywordLabel,
            'keywords' => $keywords,
            'via' => $via,
            'provider' => 'miia',
            'tool_trace' => $trace,
        ];
    }

    /**
     * @return array{success: bool, keywords?: list<string>, rationale?: string, error?: string, raw?: mixed}
     */
    protected function planKeywords(string $prompt, string $countryCode): array
    {
        $system = <<<TXT
Eres un planner de sourcing CJ Dropshipping.
Tu ÚNICA tarea: traducir el brief del usuario en keywords de catálogo CJ.

Responde SOLO JSON válido (sin markdown):
{"keywords":["..."],"rationale":"1-2 frases en español"}

Reglas estrictas:
1) Entre 5 y 8 keywords en inglés, cortas (2-5 palabras), comerciales para search CJ.
2) Debes CUBRIR todos los ángulos / categorías / problemas del brief. Nunca reduzcas un brief multi-tema a 1 sola keyword.
3) Si el brief mezcla temas (ej. calor + apagones), incluye keywords de CADA tema.
4) Prioriza productos baratos, ligeros, portátiles, demo-friendly si el brief lo pide.
5) Evita keywords demasiado genéricas ("summer products") o irrelevantes.
6) País / mercado objetivo: {$countryCode}.
7) No inventes SKUs ni PIDs; solo keywords de búsqueda.
TXT;

        $completion = $this->chatCompletions([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ], tools: null, toolChoice: 'none', temperature: 0.2, responseFormatJson: true);

        if (! ($completion['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $completion['error'] ?? 'Falló planificación de keywords',
                'raw' => $completion,
            ];
        }

        $content = (string) ($completion['message']['content'] ?? '');
        $parsed = $this->decodePayload($content);
        $keywords = [];
        if (is_array($parsed)) {
            $raw = $parsed['keywords'] ?? $parsed['keyWords'] ?? [];
            if (is_string($raw)) {
                $raw = preg_split('/[,|·]+/', $raw) ?: [];
            }
            if (is_array($raw)) {
                foreach ($raw as $kw) {
                    $kw = trim((string) $kw);
                    if ($kw === '') {
                        continue;
                    }
                    $keywords[] = $kw;
                }
            }
        }

        $keywords = $this->normalizeKeywordList($keywords);
        if ($keywords === []) {
            return [
                'success' => false,
                'error' => 'MIIA no devolvió keywords útiles del prompt.',
                'raw' => $completion,
            ];
        }

        return [
            'success' => true,
            'keywords' => $keywords,
            'rationale' => is_array($parsed) ? (string) ($parsed['rationale'] ?? '') : '',
            'raw' => $completion,
        ];
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    protected function normalizeKeywordList(array $keywords): array
    {
        $out = [];
        $seen = [];
        foreach ($keywords as $kw) {
            $kw = trim(preg_replace('/\s+/', ' ', $kw) ?? $kw);
            if ($kw === '' || mb_strlen($kw) < 2) {
                continue;
            }
            $key = Str::lower($kw);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $kw;
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * Mezcla resultados de varias keywords (round-robin) para no sesgar a una sola.
     *
     * @param  array<string, Collection>  $buckets
     */
    protected function mergeDiversified(array $buckets, int $limit): Collection
    {
        $merged = collect();
        $seen = [];
        $queues = [];
        foreach ($buckets as $kw => $list) {
            $queues[$kw] = $list->values()->all();
        }

        $added = true;
        while ($merged->count() < $limit && $added) {
            $added = false;
            foreach ($queues as $kw => &$queue) {
                while ($queue !== []) {
                    $item = array_shift($queue);
                    $pid = (string) ($item['pid'] ?? '');
                    if ($pid === '' || isset($seen[$pid])) {
                        continue;
                    }
                    $seen[$pid] = true;
                    $merged->push($item);
                    $added = true;
                    break;
                }
                if ($merged->count() >= $limit) {
                    break;
                }
            }
            unset($queue);
        }

        return $merged->values();
    }

    protected function summarizeResults(
        string $prompt,
        string $countryCode,
        array $keywords,
        Collection $products,
        string $rationale
    ): string {
        $sample = $products->take(10)->map(fn ($p) => [
            'title' => $p['title'] ?? '',
            'price_usd' => $p['price'] ?? null,
            'keyword' => $p['matched_keyword'] ?? null,
        ])->all();

        $completion = $this->chatCompletions([
            [
                'role' => 'system',
                'content' => 'Resumes en español (3-5 frases) si el lote de productos CJ encaja con el brief. '
                    .'Sé concreto: qué ángulos del brief quedaron cubiertos y qué falta. '
                    .'Mercado: '.$countryCode.'. No inventes productos que no estén en la lista.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'brief' => Str::limit($prompt, 1800),
                    'keywords_used' => $keywords,
                    'planner_note' => $rationale,
                    'products_sample' => $sample,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], tools: null, toolChoice: 'none', temperature: 0.35);

        if (! ($completion['success'] ?? false)) {
            return $rationale !== ''
                ? $rationale
                : 'Se buscaron '.count($keywords).' keywords derivadas del prompt.';
        }

        $text = trim((string) ($completion['message']['content'] ?? ''));

        return $text !== '' ? $text : $rationale;
    }

    /**
     * @param  array<int, array{role: string, content?: mixed, tool_calls?: mixed}>  $messages
     * @param  array<int, mixed>|null  $tools
     * @return array{success: bool, message?: array, error?: string, raw?: mixed}
     */
    protected function chatCompletions(
        array $messages,
        ?array $tools = null,
        string $toolChoice = 'auto',
        float $temperature = 0.3,
        bool $responseFormatJson = false
    ): array {
        $config = config('ai.providers.miia');
        $apiKey = $config['api_key'] ?? null;
        $base = rtrim((string) ($config['base_url'] ?? 'https://ia.ceballosleon.com'), '/');
        $url = $base.'/v1/chat/completions';
        $engineOpts = $this->ai->chatOptionsFor('cj_search_plan');

        $payload = [
            'model' => $engineOpts['model'] ?? 'auto',
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if (! empty($engineOpts['services'])) {
            $payload['services'] = $engineOpts['services'];
        }

        if (is_array($tools) && $tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice === 'required'
                ? ['type' => 'function', 'function' => ['name' => 'search_products']]
                : $toolChoice;
        }

        if ($responseFormatJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::timeout((int) ($config['timeout'] ?? 90))
                ->withToken($apiKey)
                ->acceptJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->body(),
                    'raw' => $response->json(),
                ];
            }

            $json = $response->json();
            $message = $json['choices'][0]['message'] ?? null;
            if (! is_array($message)) {
                return ['success' => false, 'error' => 'Respuesta MIIA sin message', 'raw' => $json];
            }

            return ['success' => true, 'message' => $message, 'raw' => $json];
        } catch (\Throwable $e) {
            Log::error('MIIA+MCP completions failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{products: Collection, total: int}
     */
    protected function extractProducts(mixed $payload): array
    {
        $payload = $this->decodePayload($payload);

        if (! is_array($payload)) {
            return ['products' => collect(), 'total' => 0];
        }

        [$list, $total] = $this->locateProductRows($payload);

        $products = collect($list)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => CjConnector::normalizeListItem($this->mapMcpProductFields($row)))
            ->filter(fn (array $row) => ($row['pid'] ?? '') !== '' || ($row['title'] ?? '') !== 'Producto CJ')
            ->values();

        return ['products' => $products, 'total' => $total ?: $products->count()];
    }

    protected function decodePayload(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload)) {
            return null;
        }

        $trim = trim($payload);
        if ($trim === '') {
            return null;
        }

        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}\s*$/u', $trim, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        $start = strpos($trim, '{');
        $end = strrpos($trim, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trim, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<int, mixed>, 1: int}
     */
    protected function locateProductRows(array $payload): array
    {
        if (isset($payload['list']) && is_array($payload['list'])) {
            return [$payload['list'], (int) ($payload['total'] ?? count($payload['list']))];
        }

        if (isset($payload['data']['list']) && is_array($payload['data']['list'])) {
            return [$payload['data']['list'], (int) ($payload['data']['total'] ?? count($payload['data']['list']))];
        }

        if (isset($payload['products']) && is_array($payload['products'])) {
            return [$payload['products'], (int) ($payload['total'] ?? count($payload['products']))];
        }

        if (isset($payload['productList']) && is_array($payload['productList'])) {
            return [$payload['productList'], (int) ($payload['totalRecords'] ?? $payload['total'] ?? count($payload['productList']))];
        }

        if (isset($payload['content']) && is_array($payload['content'])) {
            $rows = [];
            foreach ($payload['content'] as $block) {
                if (! is_array($block)) {
                    continue;
                }
                if (isset($block['productList']) && is_array($block['productList'])) {
                    foreach ($block['productList'] as $item) {
                        $rows[] = $item;
                    }
                } elseif (isset($block['id']) || isset($block['pid']) || isset($block['nameEn'])) {
                    $rows[] = $block;
                }
            }
            if ($rows !== []) {
                return [$rows, (int) ($payload['totalRecords'] ?? $payload['total'] ?? count($rows))];
            }
        }

        if (array_is_list($payload)) {
            return [$payload, count($payload)];
        }

        return [[], 0];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapMcpProductFields(array $row): array
    {
        if (! isset($row['pid']) && isset($row['id'])) {
            $row['pid'] = (string) $row['id'];
        }
        if (! isset($row['productNameEn']) && isset($row['nameEn'])) {
            $row['productNameEn'] = (string) $row['nameEn'];
        }
        if (! isset($row['productSku']) && isset($row['sku'])) {
            $row['productSku'] = (string) $row['sku'];
        }
        if (! isset($row['productImage']) && isset($row['bigImage'])) {
            $row['productImage'] = (string) $row['bigImage'];
        }
        if (! isset($row['categoryName'])) {
            $row['categoryName'] = (string) ($row['threeCategoryName'] ?? $row['twoCategoryName'] ?? $row['oneCategoryName'] ?? '');
        }

        if (isset($row['sellPrice']) && is_string($row['sellPrice']) && str_contains($row['sellPrice'], '--')) {
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $row['sellPrice'], $m)) {
                $row['sellPrice'] = (float) $m[1];
            }
        }

        if (! empty($row['productUrl']) && empty($row['cj_url'])) {
            $row['cj_url'] = (string) $row['productUrl'];
        }

        return $row;
    }
}
