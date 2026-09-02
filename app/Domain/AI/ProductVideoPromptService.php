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
        $messages = $this->buildMessages($context);

        $result = $this->callMiia($messages, withJsonFormat: true);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'MIIA no respondió.'),
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $parsed = $this->parseJson((string) ($result['content'] ?? ''));
        if ($parsed === []) {
            Log::info('ProductVideoPromptService: reintento sin response_format JSON', [
                'snippet' => mb_substr((string) ($result['content'] ?? ''), 0, 500),
            ]);
            $retry = $this->callMiia($messages, withJsonFormat: false);
            if ($retry['success'] ?? false) {
                $parsed = $this->parseJson((string) ($retry['content'] ?? ''));
                if ($parsed !== []) {
                    $result = $retry;
                }
            }
        }

        if ($parsed === []) {
            return [
                'success' => false,
                'error' => 'MIIA devolvió un formato inválido. Vuelve a intentar (si persiste, prueba con menos duración).',
                'provider' => $result['provider'] ?? 'miia',
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
            'casting_notes' => trim((string) ($parsed['casting_notes'] ?? data_get($creative, 'talent.profile', ''))),
            'camera_notes' => trim((string) ($parsed['camera_notes'] ?? data_get($creative, 'camera.style', ''))),
            'generated_at' => now()->toIso8601String(),
            'product_id' => $product->id,
        ];

        return [
            'success' => true,
            'prompt' => [
                'name' => mb_substr($name, 0, 120),
                'hook' => mb_substr($hook, 0, 240),
                'script' => mb_substr($script, 0, self::SCRIPT_MAX_CHARS),
                'audience' => mb_substr(trim((string) ($parsed['audience'] ?? data_get($creative, 'channel.audience', ''))), 0, 240),
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

        $imageUrls = $this->media->publicImageUrls($product, 6);
        $videoUrls = $this->media->publicVideoUrls($product, 3);
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
    protected function buildMessages(array $context): array
    {
        $minSeg = (int) ($context['min_segments'] ?? 7);
        $seconds = (int) ($context['target_seconds'] ?? 21);

        $system = <<<TXT
Eres un director creativo senior de TikTok Shop y performance ads (UGC + product demo).
Tu entrega NO es un guion corto: es un BRIEF DE PRODUCCIÓN COMPLETO listo para Creatify o un editor humano.

OBJETIVO: maximizar compra impulsiva con ritmo TikTok, credibilidad UGC y claridad del producto.

REGLAS:
- Responde SOLO JSON válido (sin markdown).
- Mínimo {$minSeg} segmentos, cada uno de MÁXIMO 3 segundos (nunca más de 3s por segmento).
- Cada segmento debe especificar: voz, talento, cámara, visual, texto en pantalla, audio/SFX y transición.
- El bloque creative_direction debe ser MUY detallado (cámara, persona, vestuario, set, luz, color, música, captions).
- Casting: el perfil del talento debe alinearse con primary_market y casting_market_hint (edad, género, etnia/apariencia coherente con el canal, estilo, vestuario, energía).
- Cámara: especifica POV, lente equivalente, movimiento (handheld, push-in, orbit), encuadre y profundidad de campo.
- No inventes reseñas, precios ni specs que no estén en los datos.
- recommended_format: "ugc" | "b_roll" | "mixed"
- visual_style Creatify: "DynamicProductTemplate" (masivo) o "CinematicTemplate" (premium)
- script_style Creatify: "DontWorryWriter" | "StoryTimeWriter" | "ShoppableVideo" (elige el más adecuado)

JSON EXACTO:
{
  "summary": "1-2 frases del producto",
  "product_angle": "ángulo de venta principal",
  "hook": "frase gancho (máx 14 palabras)",
  "audience": "audiencia psicográfica detallada",
  "casting_notes": "resumen casting en 2-3 líneas",
  "camera_notes": "resumen look de cámara en 2-3 líneas",
  "recommended_format": "ugc|b_roll|mixed",
  "visual_style": "DynamicProductTemplate",
  "script_style": "DontWorryWriter",
  "prompt_name": "nombre corto",
  "creative_direction": {
    "channel": {
      "platform": "TikTok",
      "market": "MX",
      "tone": "urgente|confiable|aspiracional|...",
      "audience": "..."
    },
    "talent": {
      "profile": "edad, género, etnia/apariencia según mercado, arquetipo",
      "wardrobe": "qué viste y por qué",
      "energy": "cómo actúa y habla",
      "setting": "dónde graba (cocina, baño, gym, escritorio...)"
    },
    "camera": {
      "format": "9:16 vertical",
      "style": "UGC handheld + inserts producto",
      "lens": "equivalente 24-35mm selfie / 50mm producto",
      "movement": "push-in, whip pan, jump cuts...",
      "framing": "reglas de encuadre"
    },
    "lighting": {
      "key": "ventana lateral / ring light suave",
      "mood": "cálido confiable / clínico / premium",
      "color_grade": "alto contraste TikTok, piel natural"
    },
    "audio": {
      "voice": "acento, ritmo, emoción",
      "music": "género trending sin copyright",
      "sfx": "whoosh, pop, ding en momentos clave"
    },
    "captions": {
      "style": "karaoke palabra a palabra, fuente bold",
      "position": "tercio inferior, safe zone",
      "emphasis_words": ["palabras a resaltar"]
    },
    "brand": {
      "product_hero_shots": "cómo mostrar el producto",
      "cta": "texto y acción final"
    }
  },
  "segments": [
    {
      "index": 1,
      "start": 0,
      "end": 3,
      "duration": 3,
      "type": "hook|problem|agitation|solution|demo|proof|objection|urgency|cta",
      "voiceover": "texto exacto que se dice",
      "talent": "qué hace la persona (mirada, gestos, manos)",
      "camera": "ángulo, movimiento, distancia",
      "visual": "qué se ve (producto, lifestyle, pantalla split)",
      "text_on_screen": "texto overlay si aplica",
      "audio": "música/SFX en ese tramo",
      "transition": "jump cut|match cut|zoom|swipe",
      "media_hint": "product_closeup|lifestyle|review|price|cta|supplier_broll"
    }
  ]
}
TXT;

        $userText = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts = [
            ['type' => 'text', 'text' => "Analiza producto + imágenes. Genera brief de producción COMPLETO y guion segmentado (~{$seconds}s, mínimo {$minSeg} segmentos de máx 3s). Sé específico en cámara, talento y mercado:\n\n".$userText],
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
                'talent' => trim((string) ($row['talent'] ?? '')),
                'camera' => trim((string) ($row['camera'] ?? '')),
                'visual' => trim((string) ($row['visual'] ?? '')),
                'text_on_screen' => trim((string) ($row['text_on_screen'] ?? '')),
                'audio' => trim((string) ($row['audio'] ?? '')),
                'transition' => trim((string) ($row['transition'] ?? '')),
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

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array<string, mixed>
     */
    protected function callMiia(array $messages, bool $withJsonFormat = true): array
    {
        $options = [
            'temperature' => 0.72,
            'max_tokens' => 8000,
            'timeout' => 180,
        ];
        if ($withJsonFormat) {
            $options['response_format'] = ['type' => 'json_object'];
        }

        return $this->ai->chat('product_video_prompt', $messages, $options);
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
        // Comentarios // y /* */ que algunos modelos insertan
        $json = preg_replace('/^\s*\/\/.*$/m', '', $json) ?? $json;
        $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json) ?? $json;
        // Comas finales ilegales
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        return trim($json);
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
        $decoded['audience'] = $decoded['audience'] ?? $decoded['audiencia'] ?? null;
        $decoded['product_angle'] = $decoded['product_angle'] ?? $decoded['angulo'] ?? $decoded['angulo_venta'] ?? null;
        $decoded['summary'] = $decoded['summary'] ?? $decoded['resumen'] ?? null;

        return $decoded;
    }
}
