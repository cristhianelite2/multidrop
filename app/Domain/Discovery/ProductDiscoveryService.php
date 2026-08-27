<?php

namespace App\Domain\Discovery;

use App\Domain\AI\AiTaskRouter;
use App\Domain\Suppliers\Contracts\SupplierInterface;

class ProductDiscoveryService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected SupplierInterface $supplier,
    ) {}

    /**
     * IA propone keywords/productos a partir de un problema; luego busca en el supplier.
     *
     * @return array{brief: array, import_candidates: array, ai: array}
     */
    public function proposeImportList(string $problem, string $marketCode = 'MX', array $context = []): array
    {
        $system = <<<'PROMPT'
Eres un estratega de product discovery para dropshipping.
Responde SOLO JSON válido con esta forma:
{
  "problem_code": "snake_case",
  "market": "XX",
  "needs": ["..."],
  "solution_angles": ["..."],
  "search_keywords": ["..."],
  "suggested_product_types": ["..."],
  "urgency_hooks": ["..."],
  "notes": "..."
}
No inventes datos de stock ni precios. No uses markdown.
PROMPT;

        $user = json_encode([
            'problem' => $problem,
            'market' => $marketCode,
            'context' => $context,
            'instruction' => 'Genera keywords accionables para buscar productos en un catálogo de proveedores.',
        ], JSON_UNESCAPED_UNICODE);

        $ai = $this->ai->chat('product_discovery', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['temperature' => 0.4]);

        $brief = $this->parseJsonContent($ai['content'] ?? '');

        $candidates = [];
        foreach (array_slice($brief['search_keywords'] ?? [], 0, 5) as $keyword) {
            $result = $this->supplier->searchProducts([
                'keyword' => $keyword,
                'page' => 1,
                'per_page' => 10,
            ]);
            $candidates[] = [
                'keyword' => $keyword,
                'result' => $result,
            ];
        }

        return [
            'brief' => $brief,
            'import_candidates' => $candidates,
            'ai' => [
                'provider' => $ai['provider'] ?? null,
                'success' => $ai['success'] ?? false,
                'error' => $ai['error'] ?? null,
            ],
        ];
    }

    protected function parseJsonContent(string $content): array
    {
        $content = trim($content);
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'search_keywords' => [],
            'notes' => 'No se pudo parsear respuesta IA',
            'raw' => $content,
        ];
    }
}
