<?php

namespace App\Domain\AI;

/**
 * Limpia fichas capturadas de AliExpress antes de guardarlas en Multidrop:
 * quita menciones a marketplace/China y reescribe título + descripción con MIIA.
 */
class AliExpressListingSanitizeService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected ProductNameCompressionService $names,
        protected ProductDescriptionGenerationService $descriptions,
    ) {}

    /**
     * @param  array<string, mixed>  $product  Ficha unificada del fetcher
     * @return array{product: array<string, mixed>, sanitized: bool, warnings: list<string>}
     */
    public function sanitizeForStore(array $product): array
    {
        $warnings = [];
        $aiSummary = $this->normalizeAiSummary(
            (string) ($product['ai_summary'] ?? $product['aiSummary'] ?? '')
        );
        $aiSummary = $this->stripMarketplaceMentions($aiSummary);

        $title = $this->stripMarketplaceMentions(trim((string) ($product['title'] ?? '')));
        $sourceDesc = trim(strip_tags((string) (
            $product['description'] ?? $product['description_html'] ?? ''
        )));
        $sourceDesc = $this->stripMarketplaceMentions($sourceDesc);

        $details = [];
        foreach (is_array($product['details'] ?? null) ? $product['details'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->stripMarketplaceMentions(trim((string) ($row['name'] ?? $row['label'] ?? '')));
            $value = $this->stripMarketplaceMentions(trim((string) ($row['value'] ?? '')));
            if ($name === '' && $value === '') {
                continue;
            }
            $details[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        if (! $this->ai->hasMiia()) {
            $warnings[] = 'MIIA no configurada: se aplicó limpieza local sin reescritura IA.';
            $product = $this->applyLocalOnly($product, $title, $sourceDesc, $aiSummary, $details);

            return ['product' => $product, 'sanitized' => true, 'warnings' => $warnings];
        }

        $optimizedTitle = $this->optimizeTitle($title !== '' ? $title : 'Producto');
        if (! ($optimizedTitle['success'] ?? false)) {
            $warnings[] = $optimizedTitle['error'] ?? 'No se pudo optimizar el título con MIIA.';
            $finalTitle = mb_substr($title !== '' ? $title : 'Producto', 0, 190);
        } else {
            $finalTitle = mb_substr((string) $optimizedTitle['name'], 0, 190);
        }

        $optimizedDesc = $this->descriptions->rewriteFromImport(
            $finalTitle,
            $sourceDesc,
            $aiSummary,
            $details
        );
        if (! ($optimizedDesc['success'] ?? false)) {
            $warnings[] = $optimizedDesc['error'] ?? 'No se pudo optimizar la descripción con MIIA.';
            $finalDescPlain = $sourceDesc !== '' ? $sourceDesc : ($aiSummary !== '' ? $aiSummary : '');
        } else {
            $finalDescPlain = (string) ($optimizedDesc['description'] ?? '');
        }

        $finalDescHtml = $this->plainToSimpleHtml($finalDescPlain);

        $product['title'] = $finalTitle;
        $product['description'] = mb_substr($finalDescPlain, 0, 4000);
        $product['description_html'] = mb_substr($finalDescHtml, 0, 20000);
        $product['description_short'] = mb_substr($finalTitle, 0, 280);
        $product['details'] = $details;
        $product['ai_summary'] = $aiSummary !== '' ? mb_substr($aiSummary, 0, 4000) : null;
        $product['sanitized_with_miia'] = true;
        $product['badge_hint'] = ! empty($product['has_video']) ? 'Video' : null;

        return ['product' => $product, 'sanitized' => true, 'warnings' => $warnings];
    }

    /**
     * @param  list<array{name?: string, value?: string}>  $details
     * @return array<string, mixed>
     */
    protected function applyLocalOnly(
        array $product,
        string $title,
        string $sourceDesc,
        string $aiSummary,
        array $details
    ): array {
        $plain = $sourceDesc !== '' ? $sourceDesc : $aiSummary;
        $product['title'] = mb_substr($title !== '' ? $title : 'Producto', 0, 190);
        $product['description'] = mb_substr($plain, 0, 4000);
        $product['description_html'] = mb_substr($this->plainToSimpleHtml($plain), 0, 20000);
        $product['description_short'] = mb_substr($product['title'], 0, 280);
        $product['details'] = $details;
        $product['ai_summary'] = $aiSummary !== '' ? mb_substr($aiSummary, 0, 4000) : null;
        $product['sanitized_with_miia'] = false;
        $product['badge_hint'] = ! empty($product['has_video']) ? 'Video' : null;

        return $product;
    }

    /**
     * @return array{success: bool, name?: string, error?: string}
     */
    protected function optimizeTitle(string $title): array
    {
        $system = <<<'TXT'
Eres copywriter ecommerce. Reescribe títulos de producto para una tienda online propia (no marketplace).
Reglas:
- Elimina cualquier mención a AliExpress, Alibaba, 1688, China, CN, PRC, envío desde China, "Made in China".
- Conserva tipo de producto, marca (si hay) y 2-4 keywords útiles de búsqueda.
- Sin emojis de relleno, sin hashtags, sin códigos SKU largos del marketplace.
- Máximo 80 caracteres. Un solo título, sin comillas ni explicaciones.
- PROHIBIDO: contar caracteres, notas, markdown, JSON o texto extra.
TXT;

        $result = $this->ai->chat('product_compress_name', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Optimiza este título de producto:\n\n{$title}"],
        ]);

        if (! ($result['success'] ?? false)) {
            // Fallback al compresor existente (acorta / limpia meta)
            return $this->names->compress($title);
        }

        $compressed = $this->stripMarketplaceMentions(
            $this->cleanupTitleLine((string) ($result['content'] ?? ''))
        );
        if ($compressed === '') {
            return $this->names->compress($title);
        }

        return [
            'success' => true,
            'name' => mb_substr($compressed, 0, 190),
        ];
    }

    protected function cleanupTitleLine(string $title): string
    {
        $title = trim(explode("\n", $title)[0]);
        $title = preg_replace('/^```[\w]*\s*/', '', $title) ?? $title;
        $title = preg_replace('/\s*```$/', '', $title) ?? $title;
        $title = preg_replace('/^(?:t[ií]tulo|nombre|title)\s*:\s*/iu', '', $title) ?? $title;
        $title = preg_replace('/\s*[\(\[]\s*\d+\s*(?:car[aá]cter(?:es)?|chars?|characters?)\s*[\)\]]\s*$/iu', '', $title) ?? $title;
        $title = str_replace(['**', '__', '`'], '', $title);

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title, " \t\n\r\0\x0B\"'«»");
    }

    public function stripMarketplaceMentions(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $patterns = [
            '/\bAli\s*Express\b/iu',
            '/\baliexpress(?:\.(?:com|us|ru|es|pt|fr|it|de|nl|pl|ko|jp))?\/?/iu',
            '/\bAlibaba\b/iu',
            '/\b1688\b/iu',
            '/\bMade\s+in\s+China\b/iu',
            '/\bFabricad[oa]s?\s+en\s+(?:la\s+)?China\b/iu',
            '/\bEnv[ií]o\s+desde\s+China\b/iu',
            '/\bShipping\s+from\s+China\b/iu',
            '/\bChina\s+Mainland\b/iu',
            '/\bMainland\s+China\b/iu',
            '/\bdesde\s+China\b/iu',
            '/\bfrom\s+China\b/iu',
            '/\bChina\b/iu',
            '/\bPRC\b/',
            '/\bCN\b(?=[\s,.;:\-]|$)/u',
            '/\s*[-|·]\s*$/u',
            '/\s{2,}/u',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, $pattern === '/\s{2,}/u' ? ' ' : '', $text) ?? $text;
        }

        $text = preg_replace('/\s*([,;:·|])\s*([,;:·|])+/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B\"'«»-|·");
    }

    public function normalizeAiSummary(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^```[\w]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        // Quitar encabezado típico de AE
        $text = preg_replace(
            '/^(?:🔹\s*)?(?:Resumen\s+de\s+IA\s+del\s+art[ií]culo|AI\s+(?:item\s+)?(?:summary|overview)|Resumo\s+de\s+IA(?:\s+do\s+artigo)?)\s*[:：]?\s*/iu',
            '',
            $text
        ) ?? $text;
        // Disclaimer legal de AE
        $text = preg_replace(
            '/(?:🔹\s*)?(?:Aviso\s+legal|Legal\s+notice|Disclaimer)[\s\S]{0,400}$/iu',
            '',
            $text
        ) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function plainToSimpleHtml(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }

        $blocks = preg_split("/\n{2,}/", $plain) ?: [$plain];
        $html = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $lines = preg_split("/\n+/", $block) ?: [];
            $isList = true;
            foreach ($lines as $line) {
                if (! preg_match('/^\s*[-•*]\s+/u', $line)) {
                    $isList = false;
                    break;
                }
            }
            if ($isList && count($lines) > 1) {
                $items = [];
                foreach ($lines as $line) {
                    $item = trim(preg_replace('/^\s*[-•*]\s+/u', '', $line) ?? $line);
                    if ($item !== '') {
                        $items[] = '<li>'.e($item).'</li>';
                    }
                }
                if ($items !== []) {
                    $html[] = '<ul>'.implode('', $items).'</ul>';
                }
            } else {
                $html[] = '<p>'.nl2br(e($block), false).'</p>';
            }
        }

        return implode("\n", $html);
    }
}
