<?php

namespace App\Domain\AI;

use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\ProductMarketingMediaService;
use App\Services\Storefront\ProductDescriptionHtml;
use Illuminate\Support\Facades\Log;

class ProductVideoPromptService
{
    public const SCRIPT_MAX_CHARS = 14000;

    public function __construct(
        protected AiTaskRouter $ai,
        protected ProductMarketingMediaService $media,
        protected ProductDescriptionHtml $copy
    ) {}

    /**
     * @return array{
     *   success: bool,
     *   prompt?: array<string, mixed>,
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

        $targetSeconds = max(9, min(45, (int) ($options['video_length'] ?? 21)));
        $language = trim((string) ($options['language'] ?? 'es')) ?: 'es';
        $platform = trim((string) ($options['target_platform'] ?? 'Tiktok')) ?: 'Tiktok';
        $campaign = $this->resolveCampaign($store, $options['campaign_id'] ?? null);

        $context = $this->buildContext($store, $product, $targetSeconds, $language, $platform, $campaign);
        $messages = $this->buildMessages($store, $product, $context);
        $hasVision = count($context['image_urls'] ?? []) > 0;

        $result = $this->callMiia($messages, withJsonFormat: true, withVision: $hasVision);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'MIIA no respondió.'),
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $rawContent = (string) ($result['content'] ?? '');
        $parsed = $this->parseJson($rawContent);
        if ($parsed === []) {
            Log::info('ProductVideoPromptService: reintento sin response_format JSON', [
                'snippet' => mb_substr($rawContent, 0, 500),
            ]);
            $retry = $this->callMiia($messages, withJsonFormat: false, withVision: $hasVision);
            if ($retry['success'] ?? false) {
                $rawContent = (string) ($retry['content'] ?? '');
                $parsed = $this->parseJson($rawContent);
                if ($parsed !== []) {
                    $result = $retry;
                }
            }
        }

        if ($parsed === []) {
            $parsed = $this->repairJsonViaMiia($rawContent, $hasVision);
            if ($parsed !== []) {
                Log::info('ProductVideoPromptService: JSON reparado por segunda pasada MIIA');
            }
        }

        if ($parsed === []) {
            return [
                'success' => false,
                'error' => 'MIIA devolvió un formato inválido. Vuelve a intentar (si persiste, prueba con 15–21 s de duración).',
                'provider' => $result['provider'] ?? 'miia',
                'debug_snippet' => mb_substr($this->sanitizeRawContent($rawContent), 0, 1200),
            ];
        }

        $creative = is_array($parsed['creative_direction'] ?? null) ? $parsed['creative_direction'] : [];
        $segments = $this->normalizeSegments($parsed['segments'] ?? [], $targetSeconds);
        if ($segments === []) {
            $segments = $this->fallbackSegments($parsed, $targetSeconds);
        }

        $script = $this->formatFullScript($creative, $segments, $parsed);
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
            'creative_direction' => $creative,
            'casting_notes' => $this->segmentFieldToText($parsed['casting_notes'] ?? data_get($creative, 'talent.profile', '')),
            'camera_notes' => $this->segmentFieldToText($parsed['camera_notes'] ?? data_get($creative, 'camera.style', '')),
            'generated_at' => now()->toIso8601String(),
            'product_id' => $product->id,
        ];

        return [
            'success' => true,
            'prompt' => [
                'name' => mb_substr($name, 0, 120),
                'hook' => mb_substr($hook, 0, 240),
                'script' => mb_substr($script, 0, self::SCRIPT_MAX_CHARS),
                'audience' => mb_substr($this->segmentFieldToText($parsed['audience'] ?? data_get($creative, 'channel.audience', '')), 0, 240),
                'language' => $language,
                'style' => trim((string) ($parsed['visual_style'] ?? 'DynamicProductTemplate')) ?: 'DynamicProductTemplate',
                'script_style' => trim((string) ($parsed['script_style'] ?? 'DontWorryWriter')),
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

    protected function resolveCampaign(Store $store, mixed $campaignId): ?MarketingCampaign
    {
        $id = (int) $campaignId;
        if ($id <= 0) {
            return null;
        }

        return MarketingCampaign::query()
            ->where('store_id', $store->id)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContext(
        Store $store,
        Product $product,
        int $targetSeconds,
        string $language,
        string $platform,
        ?MarketingCampaign $campaign
    ): array {
        $desc = (string) ($product->localizedDescription() ?: $product->description ?: '');
        if ($this->copy->isGarbageCopy($desc) || $this->copy->fromEmbeddedJson($desc) !== null) {
            $parsed = $this->copy->fromEmbeddedJson($desc);
            $desc = $parsed['plain'] ?? $this->copy->prose($desc);
        }
        $desc = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', $desc) ?? $desc), 0, 2800);

        $details = [];
        foreach ($product->details() as $row) {
            $details[] = ($row['name'] ?? '').': '.($row['value'] ?? '');
            if (count($details) >= 12) {
                break;
            }
        }

        $imageUrls = $this->media->publicImageUrls($product, 6, $store);
        $videoUrls = $this->media->publicVideoUrls($product, 3, $store);
        $reviews = $this->media->reviewSnippets($product, 6);

        $countries = $store->displayCountries();
        $marketCode = strtoupper((string) ($store->market?->code ?? ($countries[0] ?? 'MX')));
        $targets = is_array($campaign?->targets) ? $campaign->targets : [];

        return [
            'store' => $store->name,
            'product_name' => $product->localizedName(),
            'description' => $desc,
            'price' => $product->price !== null ? (float) $product->price : null,
            'currency' => strtoupper((string) ($product->currency ?: $store->currency())),
            'rating_avg' => $product->ratingAvg(),
            'review_count' => $product->reviewCount(),
            'details' => $details,
            'reviews' => $reviews,
            'has_supplier_videos' => $videoUrls !== [],
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'vision_image_count' => count($this->media->visionImageParts($store, $product, 4)),
            'product_url' => $this->media->productPageUrl($store, $product),
            'target_seconds' => $targetSeconds,
            'min_segments' => (int) max(5, ceil($targetSeconds / 3)),
            'language' => $language,
            'platform' => $platform,
            'market_countries' => $countries,
            'primary_market' => $marketCode,
            'casting_market_hint' => $this->castingHint($countries, $marketCode, $language),
            'campaign' => $campaign ? [
                'name' => $campaign->name,
                'notes' => $campaign->notes,
                'targets' => $targets,
                'platforms' => $campaign->platformList(),
            ] : null,
        ];
    }

    /**
     * @param  list<string>  $countries
     */
    protected function castingHint(array $countries, string $marketCode, string $language): string
    {
        $primary = strtoupper($countries[0] ?? $marketCode);
        $map = [
            'MX' => 'Talento latino mexicano (mestizo/moreno/claro), español mexicano neutro, estética TikTok Shop LATAM',
            'CO' => 'Talento colombiano, español rioplatense-neutro, look urbano joven',
            'AR' => 'Talento argentino, español rioplatense, energía directa',
            'ES' => 'Talento español peninsular, español de España, estética europea',
            'US' => 'Talento diverso estadounidense acorde al nicho; inglés o spanglish si el guion es bilingüe',
            'BR' => 'Talento brasileño, portugués brasileño, look tropical/urbano',
        ];

        return $map[$primary] ?? 'Talento coherente con el mercado '.implode('/', $countries ?: [$marketCode]).' y el idioma '.$language;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{role: string, content: mixed}>
     */
    protected function buildMessages(Store $store, Product $product, array $context): array
    {
        $minSeg = (int) ($context['min_segments'] ?? 7);
        $seconds = (int) ($context['target_seconds'] ?? 21);
        $imageCount = (int) ($context['vision_image_count'] ?? 0);

        $system = <<<TXT
Eres un director creativo senior de TikTok Shop (UGC + demo de producto).
Genera un brief listo para Creatify.

REGLAS CRÍTICAS DE FORMATO:
- Responde ÚNICAMENTE con un objeto JSON válido RFC8259.
- PROHIBIDO: markdown, bloques ``` , comentarios // o /* */, comas finales, comillas sin escapar.
- PROHIBIDO anidar objetos dentro de "segments": talent, camera, visual y audio deben ser STRINGS (texto plano).
- "audience", "casting_notes" y "camera_notes" deben ser STRINGS (no objetos).
- creative_direction puede tener objetos, pero solo 1 nivel (valores string o arrays de strings).
- Mínimo {$minSeg} segmentos; cada segmento dura MÁXIMO 3 segundos.
- No inventes precios, reseñas ni specs que no estén en los datos.
- recommended_format: "ugc" | "b_roll" | "mixed"
- visual_style: "DynamicProductTemplate" | "CinematicTemplate"
- script_style: "DontWorryWriter" | "StoryTimeWriter" | "ShoppableVideo"

JSON EXACTO (respeta tipos):
{
  "summary": "string",
  "product_angle": "string",
  "hook": "string max 14 palabras",
  "audience": "string psicográfico",
  "casting_notes": "string 2-3 líneas",
  "camera_notes": "string 2-3 líneas",
  "recommended_format": "mixed",
  "visual_style": "DynamicProductTemplate",
  "script_style": "ShoppableVideo",
  "prompt_name": "string corto",
  "creative_direction": {
    "channel": { "platform": "TikTok", "market": "MX", "tone": "string", "audience": "string" },
    "talent": { "profile": "string", "wardrobe": "string", "energy": "string", "setting": "string" },
    "camera": { "format": "9:16", "style": "string", "lens": "string", "movement": "string", "framing": "string" },
    "lighting": { "key": "string", "mood": "string", "color_grade": "string" },
    "audio": { "voice": "string", "music": "string", "sfx": "string" },
    "captions": { "style": "string", "position": "string", "emphasis_words": ["palabra1"] },
    "brand": { "product_hero_shots": "string", "cta": "string" }
  },
  "segments": [
    {
      "index": 1,
      "start": 0,
      "end": 3,
      "duration": 3,
      "type": "hook",
      "voiceover": "texto hablado",
      "talent": "string acciones del talento",
      "camera": "string plano y movimiento",
      "visual": "string qué se ve",
      "text_on_screen": "string overlay",
      "audio": "string música/sfx",
      "transition": "jump cut",
      "media_hint": "product_closeup"
    }
  ]
}
TXT;

        $userText = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $intro = "Analiza el producto";
        if ($imageCount > 0) {
            $intro .= " y las {$imageCount} imágenes adjuntas (fotos reales del producto)";
        }
        $intro .= ". Genera brief + guion segmentado (~{$seconds}s, mínimo {$minSeg} segmentos de máx 3s):\n\n";

        $parts = [
            ['type' => 'text', 'text' => $intro.$userText],
        ];

        foreach ($this->media->visionImageParts($store, $product, 4) as $part) {
            $parts[] = $part;
        }

        $content = count($parts) === 1 ? (string) $parts[0]['text'] : $parts;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $content],
        ];
    }

    /**
     * @param  array<string, mixed>  $creative
     * @param  list<array<string, mixed>>  $segments
     * @param  array<string, mixed>  $parsed
     */
    public function formatFullScript(array $creative, array $segments, array $parsed = []): string
    {
        $blocks = [];
        $blocks[] = '=== BRIEF CREATIVO TIKTOK SHOP ===';
        $blocks[] = '';

        if ($creative !== []) {
            $blocks[] = $this->formatCreativeBlock('CANAL', data_get($creative, 'channel', []));
            $blocks[] = $this->formatCreativeBlock('TALENTO / PERSONA', data_get($creative, 'talent', []));
            $blocks[] = $this->formatCreativeBlock('CÁMARA', data_get($creative, 'camera', []));
            $blocks[] = $this->formatCreativeBlock('ILUMINACIÓN Y COLOR', data_get($creative, 'lighting', []));
            $blocks[] = $this->formatCreativeBlock('AUDIO', data_get($creative, 'audio', []));
            $blocks[] = $this->formatCreativeBlock('SUBTÍTULOS / CAPTIONS', data_get($creative, 'captions', []));
            $blocks[] = $this->formatCreativeBlock('PRODUCTO Y CTA', data_get($creative, 'brand', []));
        }

        if (trim((string) ($parsed['casting_notes'] ?? '')) !== '') {
            $blocks[] = 'CASTING: '.trim((string) $parsed['casting_notes']);
        }
        if (trim((string) ($parsed['camera_notes'] ?? '')) !== '') {
            $blocks[] = 'CÁMARA (resumen): '.trim((string) $parsed['camera_notes']);
        }
        if (trim((string) ($parsed['product_angle'] ?? '')) !== '') {
            $blocks[] = 'ÁNGULO DE VENTA: '.trim((string) $parsed['product_angle']);
        }

        $blocks[] = '';
        $blocks[] = '=== GUION POR SEGMENTOS (máx 3s c/u) ===';
        $blocks[] = '';

        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $start = (int) ($seg['start'] ?? 0);
            $end = (int) ($seg['end'] ?? ($start + 3));
            $type = strtoupper((string) ($seg['type'] ?? 'SEG'));
            $blocks[] = sprintf('[%02d-%02ds] %s', $start, $end, $type);
            foreach ([
                'VOZ' => 'voiceover',
                'TALENTO' => 'talent',
                'CÁMARA' => 'camera',
                'VISUAL' => 'visual',
                'TEXTO EN PANTALLA' => 'text_on_screen',
                'AUDIO' => 'audio',
                'TRANSICIÓN' => 'transition',
            ] as $label => $key) {
                $val = trim((string) ($seg[$key] ?? ''));
                if ($val !== '') {
                    $blocks[] = $label.': '.$val;
                }
            }
            $blocks[] = '';
        }

        return trim(implode("\n", $blocks));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     */
    protected function formatCreativeBlock(string $title, array $data): string
    {
        if ($data === []) {
            return '';
        }
        $lines = [strtoupper($title)];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $keyLabel = is_string($key) ? strtoupper(str_replace('_', ' ', $key)) : (string) $key;
            $lines[] = '- '.$keyLabel.': '.trim((string) $value);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function formatScriptFromSegments(array $segments): string
    {
        return $this->formatFullScript([], $segments);
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
                'talent' => $this->segmentFieldToText($row['talent'] ?? ''),
                'camera' => $this->segmentFieldToText($row['camera'] ?? ''),
                'visual' => $this->segmentFieldToText($row['visual'] ?? ''),
                'text_on_screen' => $this->segmentFieldToText($row['text_on_screen'] ?? ''),
                'audio' => $this->segmentFieldToText($row['audio'] ?? ''),
                'transition' => $this->segmentFieldToText($row['transition'] ?? ''),
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
            ['type' => 'hook', 'voiceover' => $hook, 'talent' => 'Mira directo a cámara, ceja arriba, energía alta', 'camera' => 'Selfie POV handheld, ligero push-in', 'visual' => 'Rostro + producto en mano'],
            ['type' => 'problem', 'voiceover' => 'Si te pasa esto a diario, no estás solo.', 'talent' => 'Gestos de frustración auténticos', 'camera' => 'Plano medio, cámara en mano', 'visual' => 'Situación del problema'],
            ['type' => 'solution', 'voiceover' => $angle, 'talent' => 'Sonríe, muestra el producto', 'camera' => 'Insert macro del producto', 'visual' => 'Demo rápida en uso'],
            ['type' => 'cta', 'voiceover' => $cta, 'talent' => 'Señala abajo con el dedo', 'camera' => 'Plano cerrado + texto overlay', 'visual' => 'Precio y botón comprar'],
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
                'talent' => $row['talent'],
                'camera' => $row['camera'],
                'visual' => $row['visual'],
                'text_on_screen' => '',
                'audio' => '',
                'transition' => 'jump cut',
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
            return 21;
        }
        $last = $segments[array_key_last($segments)];

        return max(9, min(45, (int) ($last['end'] ?? 21)));
    }

    protected function segmentFieldToText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $nested = $this->segmentFieldToText($item);
                if ($nested !== '') {
                    $parts[] = (is_string($key) ? ucfirst(str_replace('_', ' ', $key)).': ' : '').$nested;
                }

                continue;
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $parts[] = is_string($key) && ! is_numeric($key)
                ? ucfirst(str_replace('_', ' ', $key)).': '.$text
                : $text;
        }

        return trim(implode('. ', $parts));
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array<string, mixed>
     */
    protected function callMiia(array $messages, bool $withJsonFormat = true, bool $withVision = false): array
    {
        $options = [
            'temperature' => 0.65,
            'max_tokens' => 6000,
            'timeout' => 180,
        ];
        if ($withJsonFormat) {
            $options['response_format'] = ['type' => 'json_object'];
        }
        if ($withVision) {
            $options['model'] = 'gemini';
        }

        return $this->ai->chat('product_video_prompt', $messages, $options);
    }

    /**
     * @return array<string, mixed>
     */
    protected function repairJsonViaMiia(string $brokenJson, bool $withVision = false): array
    {
        $snippet = mb_substr($this->sanitizeRawContent($brokenJson), 0, 14000);
        if ($snippet === '') {
            return [];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'Eres un reparador de JSON. Devuelve SOLO un objeto JSON válido RFC8259, sin markdown ni comentarios. '
                    .'Convierte objetos anidados en strings donde haga falta. Escapa comillas internas. '
                    .'Conserva summary, hook, product_angle, creative_direction, segments (con voiceover).',
            ],
            [
                'role' => 'user',
                'content' => "Repara este JSON roto:\n\n".$snippet,
            ],
        ];

        $result = $this->callMiia($messages, withJsonFormat: true, withVision: false);
        if (! ($result['success'] ?? false)) {
            return $this->extractPartialPayload($snippet);
        }

        $parsed = $this->parseJson((string) ($result['content'] ?? ''));
        if ($parsed !== []) {
            return $parsed;
        }

        return $this->extractPartialPayload($snippet);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJson(string $content): array
    {
        $decoded = $this->decodeJsonObject($content);
        if (is_array($decoded) && $decoded !== []) {
            return $this->normalizeParsedPayload($decoded);
        }

        $partial = $this->extractPartialPayload($content);
        if ($partial !== []) {
            Log::info('ProductVideoPromptService: payload parcial extraído de JSON roto');

            return $this->normalizeParsedPayload($partial);
        }

        Log::warning('ProductVideoPromptService: JSON inválido tras sanitizar', [
            'snippet' => mb_substr($this->sanitizeRawContent($content), 0, 600),
        ]);

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $content): ?array
    {
        $content = $this->sanitizeRawContent($content);
        if ($content === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $content,
            $this->extractJsonObject($content),
            $this->repairTruncatedJson($this->extractJsonObject($content, allowPartial: true) ?? ''),
        ])));

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $candidate = $this->fixJsonSyntax($candidate);
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }

            $fixed = $this->escapeRawNewlinesInJsonStrings($candidate);
            if ($fixed !== $candidate) {
                $decoded = json_decode($fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    protected function sanitizeRawContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Quitar BOM y bloques markdown ```json ... ```
        $content = preg_replace("/^\xEF\xBB\xBF/", '', $content) ?? $content;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $fence)) {
            $content = trim($fence[1]);
        }

        // Comillas tipográficas → ASCII
        $content = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", '«', '»', '“', '”', '‘', '’'],
            ['"', '"', "'", "'", '"', '"', '"', '"', "'", "'"],
            $content
        );

        // Saltos de línea literales dentro del stream que rompen json_decode
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return trim($content);
    }

    protected function extractJsonObject(string $content, bool $allowPartial = false): ?string
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $ch = $content[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;

                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;

                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        if ($allowPartial && $depth > 0) {
            return substr($content, $start);
        }

        return null;
    }

    protected function repairTruncatedJson(string $json): ?string
    {
        $json = trim($json);
        if ($json === '' || ! str_starts_with($json, '{')) {
            return null;
        }

        // Cortar última entrada incompleta (clave sin valor, coma colgando, etc.)
        $json = preg_replace('/,\s*"[^"]*"\s*:\s*$/s', '', $json) ?? $json;
        $json = preg_replace('/,\s*$/', '', $json) ?? $json;

        $openBraces = substr_count($json, '{') - substr_count($json, '}');
        $openBrackets = substr_count($json, '[') - substr_count($json, ']');

        if ($openBraces <= 0 && $openBrackets <= 0) {
            return $json;
        }

        // Cerrar strings abiertas de forma tosca
        if (preg_match('/"[^"\\\\]*$/s', $json)) {
            $json .= '"';
        }

        $json .= str_repeat(']', max(0, $openBrackets));
        $json .= str_repeat('}', max(0, $openBraces));

        return $json;
    }

    protected function fixJsonSyntax(string $json): string
    {
        // Comentarios // inline y de línea, y /* */
        $json = preg_replace('/\/\/[^\n\r]*/', '', $json) ?? $json;
        $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json) ?? $json;
        // Patrón típico del modelo: "tipo": "producto": "texto" → coma entre valores
        $json = preg_replace('/"([^"]+)"\s*:\s*"([^"]*)"\s*:\s*"/', '"$1": "$2", "detail": "', $json) ?? $json;
        // Valores sin comillas tras dos puntos (heurística conservadora)
        $json = preg_replace('/:\s*([A-Za-zÁÉÍÓÚáéíóú][A-Za-zÁÉÍÓÚáéíóú0-9_\-\s]{0,80})(\s*[,}\]])/', ': "$1"$2', $json) ?? $json;
        // Comas finales ilegales
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        return trim($json);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractPartialPayload(string $content): array
    {
        $content = $this->sanitizeRawContent($content);
        if ($content === '') {
            return [];
        }

        $payload = [];
        foreach (['summary', 'hook', 'product_angle', 'prompt_name', 'recommended_format', 'visual_style', 'script_style'] as $key) {
            if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $content, $match)) {
                $payload[$key] = stripcslashes($match[1]);
            }
        }

        if (preg_match('/"segments"\s*:\s*\[(.*)\]\s*,\s*"(?:additional_notes|creative_direction)/s', $content, $segBlock)
            || preg_match('/"segments"\s*:\s*\[(.*)\]\s*\}/s', $content, $segBlock)) {
            $segmentsRaw = $segBlock[1];
            preg_match_all(
                '/\{[^{}]*"voiceover"\s*:\s*"((?:\\\\.|[^"\\\\])*)"[^{}]*\}/s',
                $segmentsRaw,
                $segMatches,
                PREG_SET_ORDER
            );
            $segments = [];
            $cursor = 0;
            foreach ($segMatches as $i => $segMatch) {
                $voice = stripcslashes($segMatch[1]);
                if (trim($voice) === '') {
                    continue;
                }
                $type = 'segment';
                if (preg_match('/"type"\s*:\s*"([^"]+)"/', $segMatch[0], $typeMatch)) {
                    $type = $typeMatch[1];
                }
                $segments[] = [
                    'index' => $i + 1,
                    'start' => $cursor,
                    'end' => $cursor + 3,
                    'duration' => 3,
                    'type' => $type,
                    'voiceover' => $voice,
                ];
                $cursor += 3;
            }
            if ($segments !== []) {
                $payload['segments'] = $segments;
            }
        }

        if (! isset($payload['segments'])) {
            preg_match_all('/"voiceover"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $content, $voices);
            $segments = [];
            $cursor = 0;
            foreach ($voices[1] ?? [] as $i => $voiceRaw) {
                $voice = stripcslashes($voiceRaw);
                if (trim($voice) === '') {
                    continue;
                }
                $segments[] = [
                    'index' => $i + 1,
                    'start' => $cursor,
                    'end' => $cursor + 3,
                    'duration' => 3,
                    'type' => $i === 0 ? 'hook' : 'segment',
                    'voiceover' => $voice,
                ];
                $cursor += 3;
            }
            if (count($segments) >= 3) {
                $payload['segments'] = $segments;
            }
        }

        if (($payload['hook'] ?? '') === '' && ! empty($payload['segments'][0]['voiceover'])) {
            $payload['hook'] = $payload['segments'][0]['voiceover'];
        }

        return ($payload['hook'] ?? '') !== '' || ! empty($payload['segments']) ? $payload : [];
    }

    protected function escapeRawNewlinesInJsonStrings(string $json): string
    {
        return preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"/s',
            function (array $match): string {
                return str_replace(["\r\n", "\n", "\r", "\t"], ['\\n', '\\n', '\\n', '\\t'], $match[0]);
            },
            $json
        ) ?? $json;
    }

    /**
     * Acepta respuestas con claves en español o anidadas de forma inconsistente.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    protected function normalizeParsedPayload(array $decoded): array
    {
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = array_merge($decoded, $decoded['data']);
        }

        if (! isset($decoded['segments']) && isset($decoded['guion']) && is_array($decoded['guion'])) {
            $decoded['segments'] = $decoded['guion'];
        }
        if (! isset($decoded['segments']) && isset($decoded['segmentos']) && is_array($decoded['segmentos'])) {
            $decoded['segments'] = $decoded['segmentos'];
        }

        if (! isset($decoded['creative_direction']) && isset($decoded['direccion_creativa']) && is_array($decoded['direccion_creativa'])) {
            $decoded['creative_direction'] = $decoded['direccion_creativa'];
        }

        $decoded['hook'] = $decoded['hook'] ?? $decoded['gancho'] ?? null;
        $decoded['audience'] = $this->segmentFieldToText($decoded['audience'] ?? $decoded['audiencia'] ?? null);
        $decoded['casting_notes'] = $this->segmentFieldToText($decoded['casting_notes'] ?? $decoded['notas_casting'] ?? null);
        $decoded['camera_notes'] = $this->segmentFieldToText($decoded['camera_notes'] ?? $decoded['notas_camara'] ?? null);
        $decoded['product_angle'] = $decoded['product_angle'] ?? $decoded['angulo'] ?? $decoded['angulo_venta'] ?? null;
        $decoded['summary'] = $decoded['summary'] ?? $decoded['resumen'] ?? null;

        if (isset($decoded['segments']) && is_array($decoded['segments'])) {
            foreach ($decoded['segments'] as $idx => $seg) {
                if (! is_array($seg)) {
                    continue;
                }
                foreach (['talent', 'camera', 'visual', 'audio', 'transition', 'text_on_screen'] as $field) {
                    if (isset($seg[$field]) && is_array($seg[$field])) {
                        $decoded['segments'][$idx][$field] = $this->segmentFieldToText($seg[$field]);
                    }
                }
            }
        }

        return $decoded;
    }
}
