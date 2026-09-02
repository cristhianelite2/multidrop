<?php

namespace App\Domain\AI;

use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\ProductMarketingMediaService;
use App\Services\Storefront\ProductDescriptionHtml;
use Illuminate\Support\Facades\Log;

class ProductVideoPromptService
{
    public function __construct(
        protected AiTaskRouter $ai,
        protected ProductMarketingMediaService $media,
        protected ProductDescriptionHtml $copy
    ) {}

    /**
     * @return array{
     *   success: bool,
     *   prompt?: array{
     *     name: string,
     *     hook: string,
     *     script: string,
     *     audience: string,
     *     language: string,
     *     style: string,
     *     target_platform: string,
     *     video_length: int
     *   },
     *   analysis?: array<string, mixed>,
     *   segments?: list<array<string, mixed>>,
     *   media?: array{image_urls: list<string>, video_urls: list<string>, product_url: string},
     *   error?: string,
     *   provider?: string
     * }
     */
    public function generate(Store $store, Product $product, array $options = []): array
    {
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA en Admin → General.',
            ];
        }

        $targetSeconds = max(9, min(30, (int) ($options['video_length'] ?? 15)));
        $language = trim((string) ($options['language'] ?? 'es')) ?: 'es';
        $platform = trim((string) ($options['target_platform'] ?? 'Tiktok')) ?: 'Tiktok';

        $context = $this->buildContext($store, $product, $targetSeconds, $language, $platform);
        $messages = $this->buildMessages($context);

        $result = $this->ai->chat('product_video_prompt', $messages, [
            'temperature' => 0.55,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'MIIA no respondió.'),
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $parsed = $this->parseJson((string) ($result['content'] ?? ''));
        if ($parsed === []) {
            return [
                'success' => false,
                'error' => 'MIIA devolvió un formato inválido. Vuelve a intentar.',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $segments = $this->normalizeSegments($parsed['segments'] ?? [], $targetSeconds);
        if ($segments === []) {
            $segments = $this->fallbackSegments($parsed, $targetSeconds);
        }

        $script = $this->formatScriptFromSegments($segments);
        $hook = trim((string) ($parsed['hook'] ?? ''));
        if ($hook === '' && $segments !== []) {
            $hook = trim((string) ($segments[0]['voiceover'] ?? ''));
        }

        $name = trim((string) ($parsed['prompt_name'] ?? ''));
        if ($name === '') {
            $name = 'TikTok · '.mb_substr($product->localizedName(), 0, 60);
        }

        $analysis = [
            'summary' => trim((string) ($parsed['summary'] ?? '')),
            'product_angle' => trim((string) ($parsed['product_angle'] ?? '')),
            'recommended_format' => trim((string) ($parsed['recommended_format'] ?? 'mixed')),
            'video_length_seconds' => $this->segmentsDuration($segments),
            'generated_at' => now()->toIso8601String(),
            'product_id' => $product->id,
        ];

        return [
            'success' => true,
            'prompt' => [
                'name' => mb_substr($name, 0, 120),
                'hook' => mb_substr($hook, 0, 240),
                'script' => mb_substr($script, 0, 4000),
                'audience' => mb_substr(trim((string) ($parsed['audience'] ?? '')), 0, 240),
                'language' => $language,
                'style' => trim((string) ($parsed['visual_style'] ?? 'DynamicProductTemplate')) ?: 'DynamicProductTemplate',
                'target_platform' => $platform,
                'video_length' => $this->segmentsDuration($segments),
            ],
            'analysis' => $analysis,
            'segments' => $segments,
            'media' => [
                'image_urls' => $context['image_urls'],
                'video_urls' => $context['video_urls'],
                'product_url' => $context['product_url'],
            ],
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContext(Store $store, Product $product, int $targetSeconds, string $language, string $platform): array
    {
        $desc = (string) ($product->localizedDescription() ?: $product->description ?: '');
        if ($this->copy->isGarbageCopy($desc) || $this->copy->fromEmbeddedJson($desc) !== null) {
            $parsed = $this->copy->fromEmbeddedJson($desc);
            $desc = $parsed['plain'] ?? $this->copy->prose($desc);
        }
        $desc = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', $desc) ?? $desc), 0, 2200);

        $details = [];
        foreach ($product->details() as $row) {
            $details[] = ($row['name'] ?? '').': '.($row['value'] ?? '');
            if (count($details) >= 8) {
                break;
            }
        }

        $imageUrls = $this->media->publicImageUrls($product, 6);
        $videoUrls = $this->media->publicVideoUrls($product, 3);
        $reviews = $this->media->reviewSnippets($product, 4);

        $price = $product->price;
        $currency = strtoupper((string) ($product->currency ?: $store->currency()));

        return [
            'store' => $store->name,
            'product_name' => $product->localizedName(),
            'description' => $desc,
            'price' => $price !== null ? (float) $price : null,
            'currency' => $currency,
            'rating_avg' => $product->ratingAvg(),
            'review_count' => $product->reviewCount(),
            'details' => $details,
            'reviews' => $reviews,
            'has_supplier_videos' => $videoUrls !== [],
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'product_url' => $this->media->productPageUrl($store, $product),
            'target_seconds' => $targetSeconds,
            'language' => $language,
            'platform' => $platform,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{role: string, content: mixed}>
     */
    protected function buildMessages(array $context): array
    {
        $system = <<<'TXT'
Eres un director creativo de TikTok Shop especializado en dropshipping.
Tu trabajo: analizar un producto (texto + imágenes adjuntas) y escribir un guion de anuncio vertical que PROVOQUE la compra.

REGLAS OBLIGATORIAS:
- Responde SOLO JSON válido (sin markdown).
- Divide el guion en segmentos de MÁXIMO 3 segundos cada uno (nunca más de 3s por segmento).
- Cada segmento debe tener un objetivo de conversión claro (hook, dolor, solución, prueba, urgencia, CTA).
- El voiceover debe sonar natural en español latino, directo, sin relleno.
- No inventes reseñas, precios ni características que no estén en los datos.
- recommended_format: "ugc" (hablar a cámara), "b_roll" (solo planos de producto) o "mixed".
- visual_style para Creatify: usa "DynamicProductTemplate" salvo que el producto sea premium/lujo (entonces "CinematicTemplate").

JSON exacto:
{
  "summary": "qué es el producto en una frase",
  "product_angle": "ángulo de venta principal",
  "hook": "primera frase impactante (máx 12 palabras)",
  "audience": "audiencia ideal en una línea",
  "recommended_format": "ugc|b_roll|mixed",
  "visual_style": "DynamicProductTemplate",
  "prompt_name": "nombre corto del prompt",
  "segments": [
    {
      "index": 1,
      "start": 0,
      "end": 3,
      "duration": 3,
      "type": "hook|problem|solution|proof|urgency|cta",
      "voiceover": "texto que se dice en voz",
      "visual": "qué debe verse en pantalla",
      "media_hint": "product_closeup|lifestyle|review|price|cta"
    }
  ]
}
TXT;

        $userText = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts = [
            ['type' => 'text', 'text' => "Analiza este producto y genera el guion TikTok segmentado (máx 3s por segmento, duración total ~{$context['target_seconds']}s):\n\n".$userText],
        ];

        foreach (array_slice($context['image_urls'], 0, 4) as $url) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $url],
            ];
        }

        $content = count($parts) === 1 ? (string) $parts[0]['text'] : $parts;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $content],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function formatScriptFromSegments(array $segments): string
    {
        $lines = [];
        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $start = (int) ($seg['start'] ?? 0);
            $end = (int) ($seg['end'] ?? ($start + (int) ($seg['duration'] ?? 3)));
            $type = strtoupper((string) ($seg['type'] ?? 'SEG'));
            $voice = trim((string) ($seg['voiceover'] ?? ''));
            $visual = trim((string) ($seg['visual'] ?? ''));
            if ($voice === '') {
                continue;
            }
            $line = sprintf('[%02d-%02ds] %s: %s', $start, $end, $type, $voice);
            if ($visual !== '') {
                $line .= ' | Visual: '.$visual;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $segments
     * @return list<array<string, mixed>>
     */
    protected function normalizeSegments($segments, int $targetSeconds): array
    {
        if (! is_array($segments)) {
            return [];
        }

        $out = [];
        $cursor = 0;
        foreach ($segments as $row) {
            if (! is_array($row)) {
                continue;
            }
            $duration = (int) ($row['duration'] ?? 3);
            $duration = max(1, min(3, $duration));
            $voice = trim((string) ($row['voiceover'] ?? ''));
            if ($voice === '') {
                continue;
            }
            $start = (int) ($row['start'] ?? $cursor);
            $end = min($start + $duration, $start + 3);
            $out[] = [
                'index' => count($out) + 1,
                'start' => $start,
                'end' => $end,
                'duration' => $end - $start,
                'type' => (string) ($row['type'] ?? 'segment'),
                'voiceover' => $voice,
                'visual' => trim((string) ($row['visual'] ?? '')),
                'media_hint' => (string) ($row['media_hint'] ?? ''),
            ];
            $cursor = $end;
            if ($cursor >= $targetSeconds) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<array<string, mixed>>
     */
    protected function fallbackSegments(array $parsed, int $targetSeconds): array
    {
        $hook = trim((string) ($parsed['hook'] ?? 'Mira esto.'));
        $angle = trim((string) ($parsed['product_angle'] ?? 'La solución que buscabas.'));
        $cta = 'Entra ahora y llévalo con envío a tu puerta.';

        $raw = [
            ['type' => 'hook', 'voiceover' => $hook, 'visual' => 'Primer plano del producto'],
            ['type' => 'problem', 'voiceover' => 'Si te pasa esto a diario, no estás solo.', 'visual' => 'Dolor del comprador'],
            ['type' => 'solution', 'voiceover' => $angle, 'visual' => 'Producto en uso'],
            ['type' => 'cta', 'voiceover' => $cta, 'visual' => 'Precio y botón comprar'],
        ];

        $segments = [];
        $cursor = 0;
        foreach ($raw as $row) {
            if ($cursor >= $targetSeconds) {
                break;
            }
            $dur = min(3, $targetSeconds - $cursor);
            $segments[] = [
                'index' => count($segments) + 1,
                'start' => $cursor,
                'end' => $cursor + $dur,
                'duration' => $dur,
                'type' => $row['type'],
                'voiceover' => $row['voiceover'],
                'visual' => $row['visual'],
                'media_hint' => '',
            ];
            $cursor += $dur;
        }

        return $segments;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    protected function segmentsDuration(array $segments): int
    {
        if ($segments === []) {
            return 15;
        }
        $last = $segments[array_key_last($segments)];

        return max(9, min(30, (int) ($last['end'] ?? 15)));
    }

    protected function parseJson(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        Log::warning('ProductVideoPromptService: JSON inválido', ['snippet' => mb_substr($content, 0, 400)]);

        return [];
    }
}
