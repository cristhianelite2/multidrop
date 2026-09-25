<?php

namespace App\Domain\AI;

use App\Domain\AI\Support\JsonObjectParser;
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

    public function hasMiiaAvailable(): bool
    {
        return $this->ai->hasMiia();
    }

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
        $skipVision = array_key_exists('skip_vision', $options)
            ? (bool) $options['skip_vision']
            : ! empty($options['page_scrape']);

        $context = $this->buildContext($store, $product, $targetSeconds, $language, $platform, $campaign);
        if (! empty($options['page_scrape']) && is_array($options['page_scrape'])) {
            $context = $this->mergePageScrape($context, $options['page_scrape']);
        }
        if (! empty($options['creative_angle']) && is_array($options['creative_angle'])) {
            $context['creative_angle'] = $options['creative_angle'];
            $context['problem'] = (string) ($options['creative_angle']['problem'] ?? '');
            $context['angle_type'] = (string) ($options['creative_angle']['type'] ?? '');
            $context['angle_label'] = (string) ($options['creative_angle']['label'] ?? '');
            $context['value_prop'] = (string) ($options['creative_angle']['value'] ?? '');
        }
        if ($skipVision) {
            $context['vision_image_count'] = 0;
            $context['skip_vision'] = true;
        }
        $messages = $this->buildMessages($store, $product, $context);
        $hasVision = ! $skipVision && count($context['image_urls'] ?? []) > 0 && ((int) ($context['vision_image_count'] ?? 0) > 0);

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
        $segments = $this->sanitizeSegmentsCopy($segments);
        $segments = $this->ensureSegmentOverlays($segments);
        $parsed = $this->sanitizeParsedCopy($parsed);
        $creative = $this->sanitizeCreativeCopy($creative);

        $script = $this->formatFullScript($creative, $segments, $parsed);
        $hook = trim((string) ($parsed['hook'] ?? ''));
        if ($hook === '' && $segments !== []) {
            $hook = trim((string) ($segments[0]['text_on_screen'] ?? $segments[0]['voiceover'] ?? ''));
        }
        $hook = $this->sanitizeSpokenCopy($hook);

        $name = $this->sanitizeSpokenCopy(trim((string) ($parsed['prompt_name'] ?? '')));
        if ($name === '') {
            $name = 'TikTok · '.mb_substr($product->localizedName(), 0, 60);
        }

        $analysis = [
            'summary' => $this->sanitizeSpokenCopy(trim((string) ($parsed['summary'] ?? ''))),
            'product_angle' => $this->sanitizeSpokenCopy(trim((string) ($parsed['product_angle'] ?? ''))),
            'recommended_format' => trim((string) ($parsed['recommended_format'] ?? 'mixed')),
            'video_length_seconds' => $this->segmentsDuration($segments),
            'creative_direction' => $creative,
            'casting_notes' => $this->segmentFieldToText($parsed['casting_notes'] ?? data_get($creative, 'talent.profile', '')),
            'camera_notes' => $this->segmentFieldToText($parsed['camera_notes'] ?? data_get($creative, 'camera.style', '')),
            'generated_at' => now()->toIso8601String(),
            'product_id' => $product->id,
            'source' => 'miia',
        ];

        // Beats on-screen: siempre desde segmentos MIIA (no desde scrape).
        $segBeats = $this->beatsFromSegments($segments);
        if ($segBeats !== []) {
            $analysis['beats'] = $segBeats;
        }

        // Ángulo del scrape solo rellena huecos; nunca pisa el copy MIIA.
        if (($analysis['summary'] ?? '') === '' && ! empty($context['value_prop'])) {
            $analysis['summary'] = (string) $context['value_prop'];
        }
        if (($analysis['product_angle'] ?? '') === '' && ! empty($context['value_prop'])) {
            $analysis['product_angle'] = (string) $context['value_prop'];
        }
        if (empty($analysis['problem']) && ! empty($context['problem'])) {
            $analysis['problem'] = (string) $context['problem'];
        }
        if (empty($analysis['angle_type']) && ! empty($context['angle_type'])) {
            $analysis['angle_type'] = (string) $context['angle_type'];
            $analysis['angle_label'] = (string) ($context['angle_label'] ?? '');
        }
        if (empty($analysis['value_prop'])) {
            $analysis['value_prop'] = (string) ($analysis['product_angle'] ?: ($context['value_prop'] ?? ''));
        }
        if (empty($analysis['beats']) && ! empty($context['creative_angle']['beats']) && is_array($context['creative_angle']['beats'])) {
            $analysis['beats'] = $context['creative_angle']['beats'];
        }
        if (! empty($context['page_scrape_url']) && ($context['context_source'] ?? '') !== 'product_catalog') {
            $analysis['scraped_url'] = (string) $context['page_scrape_url'];
        }
        if (($context['context_source'] ?? '') === 'product_catalog') {
            $analysis['source'] = 'miia_catalog';
        }

        $cta = trim((string) data_get($creative, 'brand.cta', ''));
        if ($cta === '') {
            foreach (array_reverse($segments) as $seg) {
                if (($seg['type'] ?? '') === 'cta' && trim((string) ($seg['voiceover'] ?? '')) !== '') {
                    $cta = $this->sanitizeSpokenCopy((string) $seg['voiceover']);
                    break;
                }
            }
        }
        if ($cta !== '') {
            $analysis['cta'] = mb_substr($cta, 0, 80);
        }

        return [
            'success' => true,
            'prompt' => [
                'name' => mb_substr($name, 0, 120),
                'hook' => mb_substr($hook, 0, 240),
                'script' => mb_substr($script, 0, self::SCRIPT_MAX_CHARS),
                'audience' => mb_substr($this->segmentFieldToText($parsed['audience'] ?? data_get($creative, 'channel.audience', '')), 0, 240),
                'language' => $language,
                'style' => trim((string) ($parsed['visual_style'] ?? 'CatalogPopTemplate')) ?: 'CatalogPopTemplate',
                'script_style' => trim((string) ($parsed['script_style'] ?? 'ProductSpotlight')) ?: 'ProductSpotlight',
                'target_platform' => $platform,
                'video_length' => $this->segmentsDuration($segments),
                'cta' => $analysis['cta'] ?? 'Compra ahora',
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
     * @param  array<string, mixed>  $scrape
     * @return array<string, mixed>
     */
    protected function mergePageScrape(array $context, array $scrape): array
    {
        $fromCatalog = ! empty($scrape['from_catalog']);
        $title = trim((string) ($scrape['title'] ?? ''));
        $desc = trim((string) ($scrape['description'] ?? ''));
        $plain = trim((string) ($scrape['plain_text'] ?? ''));
        $bullets = is_array($scrape['bullets'] ?? null) ? $scrape['bullets'] : [];

        // Catálogo gana: solo enriquecer si el scrape aporta bullets útiles y no es chrome.
        $catalogDesc = trim((string) ($context['description'] ?? ''));
        if ($desc !== '' && $fromCatalog && ($catalogDesc === '' || mb_strlen($catalogDesc) < 40)) {
            $context['description'] = mb_substr($desc, 0, 2800);
        } elseif ($desc !== '' && ! $fromCatalog && mb_strlen($catalogDesc) < 40 && mb_strlen($desc) > 40) {
            // Solo si el scrape parece ficha real (no storefront).
            if (! preg_match('/\b(inicio|carrito|pasarela|checkout|también te puede)\b/iu', $desc)) {
                $context['description'] = mb_substr($desc, 0, 2800);
            }
        }

        if ($title !== '' && mb_strlen($title) > 3 && ! preg_match('/^(inicio|home|carrito)\b/iu', $title)) {
            $context['scraped_title'] = mb_substr($title, 0, 160);
        }

        $details = is_array($context['details'] ?? null) ? $context['details'] : [];
        foreach ($bullets as $b) {
            $line = trim((string) $b);
            if ($line === '' || in_array($line, $details, true)) {
                continue;
            }
            if (preg_match('/\b(carrito|checkout|pasarela|cat[aá]logo|mxn|locale)\b/iu', $line)) {
                continue;
            }
            $details[] = $line;
            if (count($details) >= 16) {
                break;
            }
        }
        $context['details'] = $details;
        $context['page_bullets'] = array_slice($bullets, 0, 10);
        // Nunca inyectar plain_text crudo de storefront (JS/nav/checkout).
        if ($fromCatalog || ($plain !== '' && ! preg_match('/\b(pasarela|md-checkout|:root|también te puede)\b/iu', $plain))) {
            $context['page_plain_text'] = mb_substr($fromCatalog ? $plain : mb_substr($plain, 0, 1200), 0, 4500);
        } else {
            unset($context['page_plain_text']);
        }
        $context['page_scrape_url'] = (string) ($scrape['url'] ?? $scrape['source_hint'] ?? '');
        $context['context_source'] = $fromCatalog ? 'product_catalog' : 'cloudflare_browser_rendering';

        return $context;
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
        $skipVision = ! empty($context['skip_vision']) || $imageCount < 1;
        $pageNote = '';
        if (! empty($context['page_scrape_url']) && ($context['context_source'] ?? '') !== 'product_catalog') {
            $pageNote = ' Usa bullets de la ficha solo si aportan specs reales; el nombre/descripción del catálogo son la fuente principal.';
        } else {
            $pageNote = ' Fuente principal: catálogo del producto (nombre, descripción, precio, detalles). Ignora chrome de tienda.';
        }
        $angleNote = '';
        if (! empty($context['angle_label'])) {
            $angleNote = ' Ángulo creativo: '.$context['angle_label']
                .' — video tipo CATÁLOGO KINETIC (magazine pop): producto protagonista, tipografía grande, beats cortos.'
                .' Estructura: 1) gancho visual del producto, 2) beneficio concreto, 3) por qué es '
                .mb_strtolower((string) $context['angle_label'])
                .', 4) CTA. Sin relleno. Frases ≤12 palabras. PROHIBIDO hooks genéricos tipo "pierdes tiempo" si no vienen del producto.';
        }

        $system = <<<TXT
Eres director creativo de anuncios verticales tipo catálogo kinetic / magazine pop (no UGC talking-head oscuro).
Genera un brief listo para HyperFrames.{$pageNote}{$angleNote}

LOOK OBLIGATORIO:
- Fondo claro de papel / estudio luminoso (nunca dark cinematic).
- Producto grande y nítido; tipografía bold punchy.
- Energía de vitrina: sticker benefits, precio claro, CTA de compra.

CALIDAD DEL GUION (crítico):
- Cada segmento DEBE tener "text_on_screen" corto (máx 8 palabras) listo para overlay.
- voiceover persuasivo y concreto (beneficio real del producto, no genérico).
- creative_direction completo: lighting.mood, lighting.color_grade, talent.energy (baja|media|alta),
  brand.cta, captions.style, captions.position, channel.tone.
- El hook debe mencionar el producto o su beneficio distintivo (nada de "pierdes tiempo" genérico).
- PROHIBIDO inventar specs; usa solo datos del catálogo / bullets reales.

REGLAS CRÍTICAS DE FORMATO:
- Responde ÚNICAMENTE con un objeto JSON válido RFC8259.
- PROHIBIDO: markdown, bloques ``` , comentarios // o /* */, comas finales.
- PROHIBIDO anidar objetos dentro de "segments": talent, camera, visual y audio deben ser STRINGS.
- "audience", "casting_notes" y "camera_notes" deben ser STRINGS.
- Mínimo {$minSeg} segmentos; cada segmento dura MÁXIMO 3 segundos.
- No inventes precios, reseñas ni specs que no estén en los datos.
- recommended_format: "b_roll" | "mixed"
- visual_style: "CatalogPopTemplate"
- script_style: "ProductSpotlight"
- GUION SIN EMOJIS. CTA verbal de compra en tienda (no link in bio).
- PROHIBIDO mencionar: Inicio, BAZA como título, carrito, pasarela, checkout, "también te puede gustar".
- PROHIBIDO segmentos de tipo "offer" ni "cta": el precio y el CTA se integran como overlay/voiceover del último segmento narrativo, sin bloques finales aparte.

JSON EXACTO (respeta tipos):
{
  "summary": "string",
  "product_angle": "string",
  "hook": "string max 14 palabras",
  "audience": "string psicográfico",
  "casting_notes": "string 2-3 líneas",
  "camera_notes": "string 2-3 líneas",
  "recommended_format": "mixed",
  "visual_style": "CatalogPopTemplate",
  "script_style": "ProductSpotlight",
  "prompt_name": "string corto",
  "creative_direction": {
    "channel": { "platform": "TikTok", "market": "MX", "tone": "string", "audience": "string" },
    "talent": { "profile": "string", "wardrobe": "string", "energy": "string", "setting": "string" },
    "camera": { "format": "9:16", "style": "string", "lens": "string", "movement": "string", "framing": "string" },
    "lighting": { "key": "string", "mood": "bright catalog", "color_grade": "string" },
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
      "talent": "string acciones",
      "camera": "string plano",
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
        if (! $skipVision && $imageCount > 0) {
            $intro .= " y las {$imageCount} imágenes adjuntas (fotos reales del producto)";
        } elseif (($context['context_source'] ?? '') === 'product_catalog') {
            $intro .= ' desde el catálogo (nombre, descripción, detalles; sin chrome de tienda)';
        } elseif (! empty($context['page_scrape_url'])) {
            $intro .= ' priorizando catálogo; bullets de scrape solo si son specs reales';
        }
        $intro .= ". Genera brief + guion segmentado (~{$seconds}s, mínimo {$minSeg} segmentos de máx 3s):\n\n";

        $parts = [
            ['type' => 'text', 'text' => $intro.$userText],
        ];

        if (! $skipVision) {
            foreach ($this->media->visionImageParts($store, $product, 4) as $part) {
                $parts[] = $part;
            }
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
            $rowType = strtolower((string) ($row['type'] ?? 'segment'));
            if (in_array($rowType, ['offer', 'cta'], true)) {
                continue;
            }
            $duration = (int) ($row['duration'] ?? 3);
            $duration = max(1, min(3, $duration));
            $voice = $this->sanitizeSpokenCopy(trim((string) ($row['voiceover'] ?? '')));
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
                'talent' => $this->sanitizeSpokenCopy($this->segmentFieldToText($row['talent'] ?? '')),
                'camera' => $this->segmentFieldToText($row['camera'] ?? ''),
                'visual' => $this->sanitizeSpokenCopy($this->segmentFieldToText($row['visual'] ?? '')),
                'text_on_screen' => $this->sanitizeSpokenCopy($this->segmentFieldToText($row['text_on_screen'] ?? '')),
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
     * Rellena text_on_screen vacío desde el voiceover (overlays útiles para HyperFrames).
     *
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    protected function ensureSegmentOverlays(array $segments): array
    {
        foreach ($segments as $i => $seg) {
            $overlay = trim((string) ($seg['text_on_screen'] ?? ''));
            if ($overlay !== '') {
                continue;
            }
            $voice = trim((string) ($seg['voiceover'] ?? ''));
            if ($voice === '') {
                continue;
            }
            // Overlay corto: primera frase / ≤42 chars.
            $overlay = preg_replace('/[.!?].*$/u', '', $voice) ?? $voice;
            $overlay = trim((string) $overlay);
            if (mb_strlen($overlay) > 42) {
                $cut = mb_substr($overlay, 0, 42);
                $space = mb_strrpos($cut, ' ');
                $overlay = $space !== false && $space > 18 ? mb_substr($cut, 0, $space) : $cut;
                $overlay = rtrim($overlay, '.,;:—-').'…';
            }
            $segments[$i]['text_on_screen'] = $this->sanitizeSpokenCopy($overlay);
        }

        return $segments;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<string>
     */
    protected function beatsFromSegments(array $segments): array
    {
        $byType = [];
        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $line = trim((string) ($seg['text_on_screen'] ?? ''));
            if ($line === '') {
                $line = trim((string) ($seg['voiceover'] ?? ''));
            }
            $line = $this->sanitizeSpokenCopy($line);
            if ($line === '' || mb_strlen($line) < 8) {
                continue;
            }
            $type = strtolower((string) ($seg['type'] ?? 'segment'));
            if (! isset($byType[$type])) {
                $byType[$type] = mb_substr($line, 0, 52);
            }
        }

        $order = ['hook', 'problem', 'solution', 'value', 'proof', 'demo', 'benefit', 'cta', 'segment'];
        $beats = [];
        foreach ($order as $type) {
            if (! empty($byType[$type]) && ! in_array($byType[$type], $beats, true)) {
                $beats[] = $byType[$type];
            }
            if (count($beats) >= 4) {
                return $beats;
            }
        }
        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $line = trim((string) (($seg['text_on_screen'] ?? '') ?: ($seg['voiceover'] ?? '')));
            $line = $this->sanitizeSpokenCopy(mb_substr($line, 0, 52));
            if ($line === '' || in_array($line, $beats, true)) {
                continue;
            }
            $beats[] = $line;
            if (count($beats) >= 4) {
                break;
            }
        }

        return $beats;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<array<string, mixed>>
     */
    protected function fallbackSegments(array $parsed, int $targetSeconds): array
    {
        $hook = trim((string) ($parsed['hook'] ?? 'Mira esto.'));
        $angle = trim((string) ($parsed['product_angle'] ?? 'La solución que buscabas.'));
        $cta = 'Cómpralo ahora en TikTok Shop, envío a tu puerta.';

        $raw = [
            ['type' => 'hook', 'voiceover' => $hook, 'talent' => 'Mira directo a cámara, ceja arriba, energía alta', 'camera' => 'Selfie POV handheld, ligero push-in', 'visual' => 'Rostro + producto en mano'],
            ['type' => 'problem', 'voiceover' => 'Si te pasa esto a diario, no estás solo.', 'talent' => 'Gestos de frustración auténticos', 'camera' => 'Plano medio, cámara en mano', 'visual' => 'Situación del problema'],
            ['type' => 'solution', 'voiceover' => $angle, 'talent' => 'Sonríe, muestra el producto', 'camera' => 'Insert macro del producto', 'visual' => 'Demo rápida en uso'],
            ['type' => 'cta', 'voiceover' => $cta, 'talent' => 'Muestra el producto a cámara con sonrisa', 'camera' => 'Plano cerrado + texto overlay', 'visual' => 'Producto y botón comprar'],
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
     * @return list<array<string, mixed>>
     */
    protected function sanitizeSegmentsCopy(array $segments): array
    {
        foreach ($segments as $i => $seg) {
            if (! is_array($seg)) {
                continue;
            }
            foreach (['voiceover', 'text_on_screen', 'talent', 'visual'] as $key) {
                if (isset($seg[$key])) {
                    $segments[$i][$key] = $this->sanitizeSpokenCopy((string) $seg[$key]);
                }
            }
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    protected function sanitizeParsedCopy(array $parsed): array
    {
        foreach (['hook', 'summary', 'product_angle', 'prompt_name'] as $key) {
            if (isset($parsed[$key]) && is_string($parsed[$key])) {
                $parsed[$key] = $this->sanitizeSpokenCopy($parsed[$key]);
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $creative
     * @return array<string, mixed>
     */
    protected function sanitizeCreativeCopy(array $creative): array
    {
        if (isset($creative['brand']) && is_array($creative['brand'])) {
            foreach (['cta', 'product_hero_shots'] as $key) {
                if (isset($creative['brand'][$key]) && is_string($creative['brand'][$key])) {
                    $creative['brand'][$key] = $this->sanitizeSpokenCopy((string) $creative['brand'][$key]);
                }
            }
        }
        if (isset($creative['captions']) && is_array($creative['captions'])) {
            if (isset($creative['captions']['emphasis_words']) && is_array($creative['captions']['emphasis_words'])) {
                $creative['captions']['emphasis_words'] = array_values(array_filter(array_map(
                    fn ($w) => $this->sanitizeSpokenCopy((string) $w),
                    $creative['captions']['emphasis_words']
                )));
            }
            foreach (['style', 'position'] as $key) {
                if (isset($creative['captions'][$key]) && is_string($creative['captions'][$key])) {
                    $creative['captions'][$key] = $this->sanitizeSpokenCopy((string) $creative['captions'][$key]);
                }
            }
        }

        return $creative;
    }

    /**
     * Quita emojis, hashtags y "link en bio" del guion hablado / overlays.
     */
    protected function sanitizeSpokenCopy(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{2600}-\x{27BF}]/u', '', $text) ?? $text;
        $text = str_replace(["\u{FE0F}", "\u{200D}", "\u{20E3}"], '', $text);

        $text = preg_replace(
            '/\b(?:link|enlace)\s+(?:en|in)\s+(?:la\s+)?bio\b(?:\s*[:.\-]?\s*)?/iu',
            '',
            $text
        ) ?? $text;
        $text = preg_replace('/\blink\s+in\s+bio\b(?:\s*[:.\-]?\s*)?/iu', '', $text) ?? $text;
        $text = preg_replace('/#[\p{L}\p{N}_]+/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\w)@[\p{L}\p{N}_.]+/u', '', $text) ?? $text;

        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;

        return trim($text);
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
        $decoded = JsonObjectParser::decode($content);
        if (is_array($decoded) && $decoded !== []) {
            return $this->normalizeParsedPayload($decoded);
        }

        $partial = $this->extractPartialPayload($content);
        if ($partial !== []) {
            Log::info('ProductVideoPromptService: payload parcial extraído de JSON roto');

            return $this->normalizeParsedPayload($partial);
        }

        JsonObjectParser::logFailure('ProductVideoPromptService', $content);

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $content): ?array
    {
        return JsonObjectParser::decode($content);
    }

    protected function sanitizeRawContent(string $content): string
    {
        return JsonObjectParser::sanitize($content);
    }

    protected function extractJsonObject(string $content, bool $allowPartial = false): ?string
    {
        return JsonObjectParser::extractObject($content, $allowPartial);
    }

    protected function repairTruncatedJson(string $json): ?string
    {
        return JsonObjectParser::repairTruncated($json);
    }

    protected function fixJsonSyntax(string $json): string
    {
        return JsonObjectParser::fixSyntax($json);
    }

    protected function escapeRawNewlinesInJsonStrings(string $json): string
    {
        return JsonObjectParser::escapeRawNewlines($json);
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
