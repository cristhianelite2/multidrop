<?php

namespace App\Domain\AI;

use App\Services\Storefront\DesignAssetUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenAiComboImageService
{
    protected ?string $activeImageApiKey = null;

    public function __construct(protected AiTaskRouter $router) {}

    /**
     * @param  list<string>  $styleTemplatePaths
     * @param  list<string>  $productImageUrls
     * @param  list<string>  $productNames
     * @param  array{currency?: string, regular?: float, final?: float, regular_label?: string, final_label?: string}  $pricing
     * @return array{success: bool, url?: string, error?: string, provider: string}
     */
    public function generateFromStyleTemplates(
        string $prompt,
        array $styleTemplatePaths,
        array $productImageUrls,
        int $storeId,
        string $styleSlug,
        string $size = '1024x1536',
        array $productNames = [],
        array $pricing = []
    ): array {
        $context = $this->imageContext();
        if (! ($context['success'] ?? false)) {
            return $context;
        }
        $this->activeImageApiKey = (string) ($context['api_key'] ?? '');

        $styleTemplatePaths = array_values(array_filter($styleTemplatePaths));
        if ($styleTemplatePaths === []) {
            return [
                'success' => false,
                'error' => 'No hay plantillas de estilo para generar.',
                'provider' => 'miia',
            ];
        }

        // Image 1 = layout. Extra plantillas diluyen los SKUs reales: 1 template + hasta 3 productos.
        $templateRefs = [];
        $firstTemplate = $styleTemplatePaths[0] ?? null;
        if (is_string($firstTemplate) && $firstTemplate !== '') {
            $ref = $this->loadLocalReferenceImage($firstTemplate, 'template-1');
            if ($ref !== null) {
                $ref['caption'] = 'AD LAYOUT TEMPLATE only. Clone composition, type hierarchy, badges, CTA and chrome. Discard this template\'s original product, people, room and story.';
                $templateRefs[] = $ref;
            }
        }

        if ($templateRefs === []) {
            return [
                'success' => false,
                'error' => 'No se pudieron leer las plantillas de estilo.',
                'provider' => 'miia',
            ];
        }

        $productLimit = 3;
        $productRefs = $this->downloadReferenceImages($productImageUrls, $productLimit);
        foreach ($productRefs as $i => $ref) {
            $label = trim((string) ($productNames[$i] ?? ''));
            $label = $label !== '' ? $label : ('Product '.($i + 1));
            $productRefs[$i]['caption'] = 'REAL PRODUCT PHOTO of "'.$label.'". Copy this object EXACTLY: same silhouette, colors, materials, buttons, logos and proportions. Do not redesign or swap it.';
        }
        $compactedTemplates = array_map(
            fn (array $ref) => $this->compactReference($ref, 1024, 80),
            $templateRefs
        );
        $preservedProducts = array_map(
            fn (array $ref) => $this->preserveProductReference($ref),
            $productRefs
        );
        $references = array_merge($compactedTemplates, $preservedProducts);
        $editPrompt = $this->buildStyleTemplatePrompt(
            $prompt,
            $styleSlug,
            count($templateRefs),
            count($productRefs),
            $productNames,
            $pricing
        );
        $profile = $this->imageModelProfile((string) ($context['model'] ?? ''));
        $size = $profile['size'];
        $timeout = max(180, (int) ($context['timeout'] ?? 180));

        try {
            // Nunca mandar `images` como array JSON: MIIA/Cloudflare lo usa como $prompt y truena.
            $result = $this->callEdits(
                $context['base_url'],
                $context['api_key'],
                $context['model'],
                $editPrompt,
                $references,
                $timeout,
                $size,
                [],
                $profile
            );

            if (! ($result['success'] ?? false)) {
                Log::warning('MIIA combo edits failed', [
                    'style' => $styleSlug,
                    'error' => $result['error'] ?? null,
                    'status' => $result['status'] ?? null,
                    'model' => $context['model'] ?? null,
                    'refs' => count($references),
                ]);
            }

            if ($this->isFatalImageError($result)) {
                return $result;
            }

            if (
                ! ($result['success'] ?? false)
                && ! $this->isTimeoutError($result)
                && ! $this->isBillingError($result)
            ) {
                $result = $this->callGenerationsWithRefs(
                    $context['base_url'],
                    $context['api_key'],
                    $context['model'],
                    $editPrompt,
                    $references,
                    $timeout,
                    $size,
                    [],
                    $profile
                );
            }

            if ($this->isFatalImageError($result)) {
                return $result;
            }

            if (
                ! ($result['success'] ?? false)
                && ! $this->isTimeoutError($result)
                && ! $this->isCloudflarePromptArrayError($result)
                && ! $this->isBillingError($result)
            ) {
                $result = $this->callChatImageWithRefs(
                    $context['base_url'],
                    $context['api_key'],
                    $context['model'],
                    $editPrompt,
                    $references,
                    $timeout,
                    $size,
                    [],
                    $profile
                );
            }

            if (
                ! ($result['success'] ?? false)
                && ! $this->isTimeoutError($result)
                && ! $this->isFatalImageError($result)
            ) {
                $promptOnlyModel = ($this->isBillingError($result) || $this->isCloudflarePromptArrayError($result))
                    ? 'auto'
                    : (string) ($context['model'] ?? 'gpt-image-1.5');
                Log::warning('MIIA combo i2i failed; prompt-only generations', [
                    'style' => $styleSlug,
                    'error' => $result['error'] ?? null,
                    'status' => $result['status'] ?? null,
                    'fallback_model' => $promptOnlyModel,
                ]);
                $result = $this->callGenerations(
                    $context['base_url'],
                    $context['api_key'],
                    $promptOnlyModel,
                    $editPrompt,
                    $timeout,
                    $promptOnlyModel === 'auto' ? '1024x1024' : $size,
                    [],
                    $this->plainGenerationProfile($profile)
                );
            }

            if (! ($result['success'] ?? false) && $this->isTimeoutError($result)) {
                $result['error'] = 'MIIA tardó demasiado generando la imagen con plantillas (suele tomar 1–3 min). Vuelve a intentar.';
            }

            if (! ($result['success'] ?? false)) {
                return $result;
            }

            $filename = Str::slug($styleSlug).'-'.Str::lower(Str::random(6)).'.png';
            $stored = $this->storeGeneratedImage($result['bytes'], $storeId, $filename);
            if (! ($stored['success'] ?? false)) {
                return $stored + ['provider' => 'miia'];
            }

            return [
                'success' => true,
                'url' => $stored['url'],
                'provider' => 'miia',
            ];
        } catch (\Throwable $e) {
            Log::error('MIIA combo style promo image failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'miia',
            ];
        }
    }

    /**
     * @param  list<string>  $productImageUrls
     * @return array{success: bool, url?: string, error?: string, provider: string}
     */
    public function generateFromStyleTemplate(
        string $prompt,
        string $styleTemplatePath,
        array $productImageUrls,
        int $storeId,
        string $styleSlug,
        string $templateFilename,
        string $size = '1024x1536'
    ): array {
        return $this->generateFromStyleTemplates(
            $prompt,
            [$styleTemplatePath],
            $productImageUrls,
            $storeId,
            $styleSlug,
            $size
        );
    }

    /**
     * @param  list<string>  $referenceImageUrls
     * @return array{success: bool, url?: string, error?: string, provider: string}
     */
    public function generatePromoImage(string $prompt, array $referenceImageUrls, int $storeId): array
    {
        $context = $this->imageContext();
        if (! ($context['success'] ?? false)) {
            return $context;
        }
        $this->activeImageApiKey = (string) ($context['api_key'] ?? '');

        $size = $this->imageModelProfile((string) ($context['model'] ?? ''))['size'];
        $references = $this->downloadReferenceImages($referenceImageUrls);
        $editPrompt = $this->buildEditPrompt($prompt, count($references));
        $profile = $this->imageModelProfile((string) ($context['model'] ?? ''));

        try {
            if ($references !== []) {
                $timeout = max(180, (int) ($context['timeout'] ?? 180));
                $result = $this->callEdits(
                    $context['base_url'],
                    $context['api_key'],
                    $context['model'],
                    $editPrompt,
                    $references,
                    $timeout,
                    $size,
                    [],
                    $profile
                );
                if (
                    ! ($result['success'] ?? false)
                    && ! $this->isTimeoutError($result)
                    && ! $this->isFatalImageError($result)
                    && ! $this->isBillingError($result)
                ) {
                    $result = $this->callGenerationsWithRefs(
                        $context['base_url'],
                        $context['api_key'],
                        $context['model'],
                        $editPrompt,
                        $references,
                        $timeout,
                        $size,
                        [],
                        $profile
                    );
                }
                if (
                    ! ($result['success'] ?? false)
                    && ! $this->isTimeoutError($result)
                    && ! $this->isFatalImageError($result)
                    && ! $this->isCloudflarePromptArrayError($result)
                    && ! $this->isBillingError($result)
                ) {
                    $result = $this->callChatImageWithRefs(
                        $context['base_url'],
                        $context['api_key'],
                        $context['model'],
                        $editPrompt,
                        $references,
                        $timeout,
                        $size,
                        [],
                        $profile
                    );
                }
                if (! ($result['success'] ?? false) && ! $this->isTimeoutError($result) && ! $this->isFatalImageError($result)) {
                    $promptOnlyModel = ($this->isBillingError($result) || $this->isCloudflarePromptArrayError($result))
                        ? 'auto'
                        : (string) ($context['model'] ?? 'gpt-image-1.5');
                    $result = $this->callGenerations(
                        $context['base_url'],
                        $context['api_key'],
                        $promptOnlyModel,
                        $editPrompt,
                        $timeout,
                        $promptOnlyModel === 'auto' ? '1024x1024' : $size,
                        [],
                        $this->plainGenerationProfile($profile)
                    );
                }
            } else {
                $result = $this->callGenerations(
                    $context['base_url'],
                    $context['api_key'],
                    $context['model'],
                    $editPrompt,
                    $context['timeout'],
                    $size,
                    $context['services'] ?? [],
                    $profile
                );
            }

            if (! ($result['success'] ?? false)) {
                return $result;
            }

            $stored = $this->storeGeneratedImage($result['bytes'], $storeId);
            if (! ($stored['success'] ?? false)) {
                return $stored + ['provider' => 'miia'];
            }

            return [
                'success' => true,
                'url' => $stored['url'],
                'provider' => 'miia',
            ];
        } catch (\Throwable $e) {
            Log::error('MIIA combo promo image failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'miia',
            ];
        }
    }

    /**
     * @return array{success: bool, api_key?: string, base_url?: string, model?: string, services?: list<string>, timeout?: int, error?: string, provider?: string}
     */
    protected function imageContext(): array
    {
        return $this->router->imageContext('combo_image');
    }

    protected function buildStyleTemplatePrompt(
        string $prompt,
        string $styleSlug,
        int $templateCount,
        int $productCount,
        array $productNames = [],
        array $pricing = []
    ): string {
        $identity = $this->productIdentityFromBrief($prompt);
        $styleLabel = Str::title(str_replace('-', ' ', $styleSlug));
        $templateCount = max(1, $templateCount);
        $productStart = $templateCount + 1;
        $productEnd = $productStart + max(0, $productCount) - 1;
        $namesLine = $this->productNamesLine($productNames);
        $priceLine = trim($this->priceLockLine($styleSlug, $pricing));

        $productLock = $productCount > 0
            ? 'PRODUCT LOCK (highest priority): Images '.$productStart.'–'.$productEnd.' are the REAL SKUs. Reproduce each unit EXACTLY as photographed — same silhouette, color, materials, logos, buttons, proportions. Do not redesign, restyle, morph, recolor, or swap for a similar gadget. If unsure, copy the photo.'
            : 'Do not invent a new hero product.';

        $layoutLock = 'LAYOUT: Image 1 is the '.$styleLabel.' ad template. Clone its grid, type hierarchy, badges, CTA bar and crop. Discard its original product, people, room and story.';

        $scene = $identity !== ''
            ? 'SCENE: '.$identity
            : 'SCENE: one realistic place where these exact products are used together.';

        return implode("\n", array_filter([
            'OUTPUT: one finished vertical 9:16 (1024x1536) advertisement image. Not a text description.',
            $productLock,
            $namesLine !== '' ? trim($namesLine) : '',
            $layoutLock,
            $this->stylePlaybook($styleSlug),
            $priceLine,
            $scene,
            'COPY: Spanish in the template type positions. No misspellings, no watermarks, no collage borders.',
        ]));
    }

    protected function buildEditPrompt(string $prompt, int $referenceCount): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            $prompt = 'Professional e-commerce catalog photo combining the products from the reference images.';
        }

        if ($referenceCount > 0) {
            $refs = [];
            for ($i = 1; $i <= $referenceCount; $i++) {
                $refs[] = 'Image '.$i;
            }

            return 'Create one promotional e-commerce product photo that combines the products shown in '
                .implode(', ', $refs).'. '
                .$prompt
                .' Realistic catalog photography, clean neutral background, balanced composition, soft studio lighting, no text, no logos, no watermarks, no collage borders.';
        }

        return $prompt.' Realistic catalog photography, clean neutral background, no text, no logos, no watermarks.';
    }

    protected function buildDropshippingFallbackPrompt(string $prompt, string $styleSlug, array $productNames = [], array $pricing = []): string
    {
        return $this->buildStyleTemplatePrompt($prompt, $styleSlug, 1, count($productNames), $productNames, $pricing);
    }

    protected function buildGenerationPrompt(string $prompt, string $styleSlug): string
    {
        return $this->buildDropshippingFallbackPrompt($prompt, $styleSlug);
    }

    /**
     * @param  list<string>  $productNames
     */
    protected function productNamesLine(array $productNames): string
    {
        $names = array_values(array_filter(array_map('trim', $productNames)));
        if ($names === []) {
            return '';
        }

        $parts = [];
        foreach ($names as $i => $name) {
            $parts[] = ($i + 1).') '.$name;
        }

        return 'COMBO SKUs in order: '.implode(' · ', $parts).". Every SKU must appear and stay true to its photo.\n";
    }

    /**
     * @param  array{currency?: string, regular?: float, final?: float, regular_label?: string, final_label?: string}  $pricing
     */
    protected function priceLockLine(string $styleSlug, array $pricing): string
    {
        $final = trim((string) ($pricing['final_label'] ?? ''));
        $regular = trim((string) ($pricing['regular_label'] ?? ''));
        if ($final === '') {
            return '';
        }

        $needsPrice = in_array($styleSlug, ['imagenes', 'oferta'], true);
        if (! $needsPrice) {
            return '';
        }

        $regularBit = $regular !== '' && $regular !== $final
            ? '- Regular / list price (strikethrough or "Antes"): '.$regular."\n"
            : '';

        return "\nPRICE LOCK — copy these amounts EXACTLY. Do not invent, round, convert or change currency:\n"
            .$regularBit
            .'- Final / offer price (what the customer pays, the large badge): '.$final."\n"
            .'- Keep the template price lockup position. Show both prices when the template has a price area: regular crossed out, final big and readable. Same currency. No extra digits, no "$99.900" leftovers from the template.'."\n";
    }

    protected function productIdentityFromBrief(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return '';
        }

        $cleaned = preg_replace(
            '/\b(top-?down|flat[\s-]?lay|wooden table|neutral-?toned|catalog photo|studio lighting|soft natural lighting|minimal shadows|clean and functional composition|no text(?: on image)?|fondo neutro|luz suave)\b/iu',
            '',
            $prompt
        );

        $cleaned = is_string($cleaned) ? trim(preg_replace('/\s{2,}/', ' ', $cleaned) ?? $prompt) : $prompt;

        return $cleaned !== '' ? $cleaned : $prompt;
    }

    protected function stylePlaybook(string $slug): string
    {
        return match ($slug) {
            'antes-despues' => 'ANTES/DESPUÉS storyboard: two beats of THE SAME scene and people. WORST CASE (ANTES) = the realistic painful day without these products (hot, tired, messy, failing). BEST CASE (DESPUÉS) = the same people in the same place after using THESE exact products correctly (neck fan on the neck, handheld fan in hand, portable AC on the floor/desk — never used as decoration only). Hero products overlap the split in the foreground. Pain → relief. Do not mix a gym story with a nursery story.',
            'beneficios' => 'BENEFITS ad: one BEST-CASE lifestyle scene of these products doing their job, plus 3–5 benefit bullets that are true for THIS combo (not the template\'s old claims). WORST CASE is only implied in the headline (the pain they remove). Product hero matches the photos 1:1.',
            'oferta' => 'OFFER ad: BEST-CASE product hero + urgency chrome. PRICE: regular (Antes) struck through and FINAL offer price large — use PRICE LOCK amounts exactly. CTA from the template.',
            'imagenes' => 'Premium hero ad: people using THESE exact products. Keep the template price badge; regular struck through, final price large and exact.',
            'testimonios' => 'TESTIMONIAL ad: quote from a buyer who had the WORST CASE and now lives the BEST CASE with these products. Avatar and quote must match the product category. Product hero 1:1 from photos.',
            'caracteristicas' => 'FEATURES ad: callouts that describe THESE SKUs (size, power, how you wear/place them). Do not copy the template\'s old feature list. Product shape/color locked to photos.',
            'casos-de-uso' => 'USE-CASES montage: 3–4 BEST-CASE situations where someone would actually use this combo (e.g. home, office, commute, night). Each panel is the same products, used correctly. No unrelated hobbies from the template.',
            'comparativa' => 'COMPARISON: BEST CASE = these exact SKUs (checkmarks, bright). WORST CASE = generic cheap alternatives (X marks, dull). Compare attributes that matter for THIS category, not the template\'s old serum/protein claims.',
            'autoridad' => 'AUTHORITY ad: expert/trust badges that fit this product category. BEST CASE = recommended, proven. Product 1:1 from photos.',
            'comunidad' => 'COMMUNITY / UGC wall: several buyers in BEST-CASE moments with these products. Same category, same SKUs, true colors/shapes.',
            'faq' => 'FAQ cards answering real objections for THESE products (use, noise, battery, who it is for). Scene stays in one coherent setting.',
            'ingredientes' => 'If the products are ingestible/cosmetic, call out real ingredients. If they are gadgets, convert this layout into MATERIALS / SPECS / WHAT\'S INSIDE THE BOX — still one coherent scene. Never keep food props for electronics.',
            'logistica' => 'TRUST / SHIPPING ad: BEST CASE = easy delivery, guarantee, unboxing these exact SKUs. Do not keep the template\'s old product.',
            'modo-de-uso' => 'HOW-TO storyboard: numbered steps of actually using THESE products (unbox → place/wear → power on → enjoy BEST CASE). Steps must be physically possible. WORST CASE is the confusion before reading the steps.',
            default => 'Keep the template chrome. Rebuild the story as WORST CASE vs BEST CASE for THESE products in one coherent setting. Exact product shape and color.',
        };
    }

    /**
     * @return array{name: string, bytes: string, mime: string}|null
     */
    protected function loadLocalReferenceImage(string $path, string $prefix): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 8 * 1024 * 1024) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return [
            'name' => $prefix.'.'.($ext ?: 'jpg'),
            'bytes' => $bytes,
            'mime' => $mime,
        ];
    }

    /**
     * @param  array{name: string, bytes: string, mime: string}  $ref
     * @return array{name: string, bytes: string, mime: string}
     */
    protected function compactReference(array $ref, int $maxEdge = 1024, int $quality = 82): array
    {
        if (! function_exists('imagecreatefromstring') || ($ref['bytes'] ?? '') === '') {
            return $ref;
        }

        $src = @imagecreatefromstring($ref['bytes']);
        if ($src === false) {
            return $ref;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = max($w, $h) > $maxEdge ? ($maxEdge / max($w, $h)) : 1.0;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, $quality);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        if (! is_string($bytes) || $bytes === '') {
            return $ref;
        }

        return [
            'name' => pathinfo($ref['name'], PATHINFO_FILENAME).'.jpg',
            'bytes' => $bytes,
            'mime' => 'image/jpeg',
            'caption' => (string) ($ref['caption'] ?? ''),
        ];
    }

    /**
     * @param  array{name: string, bytes: string, mime: string, caption?: string}  $ref
     * @return array{name: string, bytes: string, mime: string, caption?: string}
     */
    protected function preserveProductReference(array $ref): array
    {
        $bytes = (string) ($ref['bytes'] ?? '');
        if ($bytes === '' || strlen($bytes) <= 1_800_000) {
            return $ref;
        }

        return $this->compactReference($ref, 1400, 90);
    }

    /**
     * @param  list<string>  $urls
     * @return list<array{name: string, bytes: string, mime: string}>
     */
    protected function downloadReferenceImages(array $urls, int $limit = 4): array
    {
        $out = [];
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

        foreach (array_slice($urls, 0, $limit) as $i => $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                $response = Http::timeout(25)->get($url);
                if (! $response->successful()) {
                    continue;
                }

                $bytes = $response->body();
                if ($bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
                    continue;
                }

                $mime = $this->normalizeImageMime((string) ($response->header('Content-Type') ?? 'image/jpeg'));
                if (! str_starts_with($mime, 'image/')) {
                    continue;
                }

                $ext = match ($mime) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };

                $out[] = [
                    'name' => 'product-'.($i + 1).'.'.$ext,
                    'bytes' => $bytes,
                    'mime' => $mime,
                ];
            } catch (\Throwable $e) {
                Log::warning('Combo promo image reference download failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $out;
    }

    /**
     * @return array{
     *     family: string,
     *     size: string,
     *     quality: ?string,
     *     output_format: ?string,
     *     input_fidelity: ?string,
     *     style: ?string,
     *     background: ?string
     * }
     */
    protected function imageModelProfile(string $model): array
    {
        $m = strtolower(trim($model));
        if (str_contains($m, 'gpt-image')) {
            return [
                'family' => 'gpt-image',
                'size' => '1024x1536',
                'quality' => (string) config('ai.providers.miia.image_quality', 'high'),
                'output_format' => 'png',
                'input_fidelity' => 'high',
                'style' => null,
                'background' => 'opaque',
            ];
        }
        if (str_contains($m, 'dall-e-3') || str_contains($m, 'dalle-3')) {
            return [
                'family' => 'dall-e-3',
                'size' => '1024x1792',
                'quality' => 'hd',
                'output_format' => null,
                'input_fidelity' => null,
                'style' => 'natural',
                'background' => null,
            ];
        }
        if (str_contains($m, 'dall-e-2') || str_contains($m, 'dalle-2')) {
            return [
                'family' => 'dall-e-2',
                'size' => '1024x1024',
                'quality' => null,
                'output_format' => null,
                'input_fidelity' => null,
                'style' => null,
                'background' => null,
            ];
        }

        return [
            'family' => 'other',
            'size' => '1024x1536',
            'quality' => 'high',
            'output_format' => 'png',
            'input_fidelity' => null,
            'style' => null,
            'background' => null,
        ];
    }

    protected function clipImagePrompt(string $prompt): string
    {
        $max = (int) config('ai.providers.miia.image_prompt_max', 4000);
        $prompt = trim($prompt);
        if ($max < 1 || mb_strlen($prompt) <= $max) {
            return $prompt;
        }

        return mb_substr($prompt, 0, $max);
    }

    /**
     * @param  array{success?: bool, error?: string}  $result
     */
    protected function isCloudflarePromptArrayError(array $result): bool
    {
        $err = mb_strtolower((string) ($result['error'] ?? ''));

        return str_contains($err, 'generateimagewithcloudflare')
            || (str_contains($err, 'must be of type string') && str_contains($err, 'array given'));
    }

    /**
     * @param  array{success?: bool, error?: string, status?: int}  $result
     */
    protected function isBillingError(array $result): bool
    {
        $err = mb_strtolower((string) ($result['error'] ?? ''));
        $status = (int) ($result['status'] ?? 0);

        return $status === 429
            || str_contains($err, 'no credits')
            || str_contains($err, 'insufficient_quota')
            || str_contains($err, 'insufficient quota')
            || (str_contains($err, 'billing') && str_contains($err, 'credit'));
    }

    protected function normalizeImageMime(string $mime): string
    {
        $mime = strtolower(trim(strtok($mime, ';') ?: $mime));

        return match ($mime) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            '' => 'image/jpeg',
            default => $mime,
        };
    }

    protected function referenceFilename(string $name, string $mime): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME) ?: 'image';
        $ext = match ($this->normalizeImageMime($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        return $base.'.'.$ext;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function plainGenerationProfile(array $profile): array
    {
        return [
            'family' => $profile['family'] ?? 'other',
            'size' => $profile['size'] ?? '1024x1536',
            'quality' => null,
            'output_format' => null,
            'input_fidelity' => null,
            'style' => null,
            'background' => null,
        ];
    }

    /**
     * @param  array{success?: bool, error?: string}  $result
     */
    protected function isFatalImageError(array $result): bool
    {
        if ($result['success'] ?? false) {
            return false;
        }

        $err = mb_strtolower((string) ($result['error'] ?? ''));

        return str_contains($err, 'no tiene imágenes')
            || str_contains($err, 'no tiene imagenes')
            || str_contains($err, 'generación de imágenes')
            || str_contains($err, 'generacion de imagenes');
    }

    /**
     * @param  array{success?: bool, error?: string}  $result
     */
    protected function isTimeoutError(array $result): bool
    {
        $err = mb_strtolower((string) ($result['error'] ?? ''));

        return str_contains($err, 'timed out')
            || str_contains($err, 'curl error 28')
            || str_contains($err, 'operation timed out');
    }

    /**
     * @param  array<int, mixed>|list<array{name: string, contents: mixed}>  $payload
     * @return array{success: bool, bytes?: string, error?: string, status?: int, provider: string}
     */
    protected function postImageRequest(
        string $url,
        string $apiKey,
        int $timeout,
        array $payload,
        bool $multipart = false
    ): array {
        if (! $multipart) {
            if (isset($payload['prompt']) && ! is_string($payload['prompt'])) {
                $payload['prompt'] = is_scalar($payload['prompt'])
                    ? (string) $payload['prompt']
                    : $this->clipImagePrompt(json_encode($payload['prompt'], JSON_UNESCAPED_UNICODE) ?: '');
            }
            unset($payload['images']);
        }

        try {
            $pending = Http::timeout(max(120, $timeout))
                ->connectTimeout(30)
                ->withToken($apiKey)
                ->acceptJson();

            $response = $multipart
                ? $pending->asMultipart()->post($url, $payload)
                : $pending->post($url, $payload);

            return $this->parseImageResponse($response);
        } catch (ConnectionException $e) {
            Log::warning('MIIA image HTTP connection failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 0,
                'provider' => 'miia',
            ];
        }
    }

    /**
     * @param  array{quality?: ?string, output_format?: ?string, input_fidelity?: ?string, style?: ?string}  $profile
     * @return list<array{name: string, contents: string}>
     */
    protected function qualityMultipartFields(array $profile): array
    {
        $fields = [];
        if (! empty($profile['quality'])) {
            $fields[] = ['name' => 'quality', 'contents' => (string) $profile['quality']];
        }
        if (! empty($profile['output_format'])) {
            $fields[] = ['name' => 'output_format', 'contents' => (string) $profile['output_format']];
        }
        if (! empty($profile['input_fidelity'])) {
            $fields[] = ['name' => 'input_fidelity', 'contents' => (string) $profile['input_fidelity']];
        }
        if (! empty($profile['style'])) {
            $fields[] = ['name' => 'style', 'contents' => (string) $profile['style']];
        }
        if (! empty($profile['background'])) {
            $fields[] = ['name' => 'background', 'contents' => (string) $profile['background']];
        }

        return $fields;
    }

    /**
     * @param  list<array{name: string, bytes: string, mime: string}>  $references
     * @param  list<string>  $services
     * @param  array{family?: string, quality?: ?string, output_format?: ?string, input_fidelity?: ?string, style?: ?string}  $profile
     * @return array{success: bool, bytes?: string, error?: string, provider: string}
     */
    protected function callEdits(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $prompt,
        array $references,
        int $timeout,
        string $size = '1024x1536',
        array $services = [],
        array $profile = []
    ): array {
        if ($profile === []) {
            $profile = $this->imageModelProfile($model);
        }

        $multipart = [
            ['name' => 'model', 'contents' => (string) ($model !== '' ? $model : 'gpt-image-1.5')],
            ['name' => 'prompt', 'contents' => $this->clipImagePrompt($prompt)],
            ['name' => 'size', 'contents' => (string) $size],
            ['name' => 'n', 'contents' => '1'],
        ];

        $qualityFields = $this->qualityMultipartFields($profile);
        foreach ($qualityFields as $field) {
            $multipart[] = $field;
        }

        foreach ($references as $ref) {
            $mime = $this->normalizeImageMime((string) ($ref['mime'] ?? 'image/jpeg'));
            $filename = $this->referenceFilename($ref['name'] ?? 'image.jpg', $mime);
            $multipart[] = [
                'name' => 'image[]',
                'contents' => $ref['bytes'],
                'filename' => $filename,
                'headers' => ['Content-Type' => $mime],
            ];
        }

        $parsed = $this->postImageRequest($baseUrl.'/images/edits', $apiKey, $timeout, $multipart, true);
        if (($parsed['success'] ?? false) || $qualityFields === []) {
            return $parsed;
        }

        $err = strtolower((string) ($parsed['error'] ?? ''));
        if (! str_contains($err, 'input_fidelity') && ! str_contains($err, 'unknown') && ! str_contains($err, 'quality') && ! str_contains($err, 'output_format') && ! str_contains($err, 'style') && ! str_contains($err, 'background')) {
            return $parsed;
        }

        $retryNames = ['input_fidelity', 'quality', 'output_format', 'style', 'background'];
        $retry = array_values(array_filter(
            $multipart,
            fn ($part) => ! in_array($part['name'] ?? '', $retryNames, true)
        ));

        return $this->postImageRequest($baseUrl.'/images/edits', $apiKey, $timeout, $retry, true);
    }

    /**
     * Image-to-image vía chat (MIIA no expone /v1/images/edits).
     *
     * @param  list<array{name: string, bytes: string, mime: string}>  $references
     * @param  list<string>  $services
     * @param  array{quality?: ?string, size?: string}  $profile
     * @return array{success: bool, bytes?: string, error?: string, status?: int, provider: string}
     */
    protected function callChatImageWithRefs(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $prompt,
        array $references,
        int $timeout,
        string $size = '1024x1536',
        array $services = [],
        array $profile = []
    ): array {
        $content = [
            ['type' => 'text', 'text' => $this->clipImagePrompt($prompt)."\n\nReturn the finished advertisement as a single 9:16 PNG (".$size.'). Do not describe it in text.'],
        ];
        foreach (array_slice($references, 0, 4) as $i => $ref) {
            $n = $i + 1;
            $caption = trim((string) ($ref['caption'] ?? ''));
            if ($caption === '') {
                $caption = str_starts_with((string) ($ref['name'] ?? ''), 'template')
                    ? ($n === 1 ? 'LAYOUT TEMPLATE — clone chrome, not the original story' : 'EXTRA STYLE REF')
                    : 'REAL PRODUCT PHOTO — exact shape and color';
            }
            $content[] = ['type' => 'text', 'text' => 'IMAGE '.$n.': '.$caption];
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$ref['mime'].';base64,'.base64_encode($ref['bytes']),
                ],
            ];
        }

        $payload = [
            'model' => $model !== '' ? $model : 'gpt-image-1.5',
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'modalities' => ['text', 'image'],
        ];
        if ($services !== []) {
            $payload['services'] = array_values($services);
        }
        if (! empty($profile['quality'])) {
            $payload['quality'] = $profile['quality'];
        }
        $payload['size'] = $size;

        try {
            $response = Http::timeout(max(120, $timeout))
                ->connectTimeout(30)
                ->withToken($apiKey)
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => 0,
                'provider' => 'miia',
            ];
        }

        $parsed = $this->parseImageResponse($response);
        if ($parsed['success'] ?? false) {
            return $parsed;
        }

        $fromChat = $this->extractImageBytesFromJson($response->json());
        if ($fromChat !== null) {
            return [
                'success' => true,
                'bytes' => $fromChat,
                'provider' => 'miia',
            ];
        }

        return $parsed;
    }

    /**
     * Fotos de referencia en multipart. Nunca JSON `images` (MIIA lo manda a Cloudflare como $prompt).
     *
     * @param  list<array{name: string, bytes: string, mime: string}>  $references
     * @param  list<string>  $services
     * @return array{success: bool, bytes?: string, error?: string, status?: int, provider: string}
     */
    protected function callGenerationsWithRefs(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $prompt,
        array $references,
        int $timeout,
        string $size = '1024x1536',
        array $services = [],
        array $profile = []
    ): array {
        if ($profile === []) {
            $profile = $this->imageModelProfile($model);
        }

        $multipart = [
            ['name' => 'model', 'contents' => (string) ($model !== '' ? $model : 'gpt-image-1.5')],
            ['name' => 'prompt', 'contents' => $this->clipImagePrompt($prompt)],
            ['name' => 'size', 'contents' => (string) $size],
            ['name' => 'n', 'contents' => '1'],
            ['name' => 'response_format', 'contents' => 'b64_json'],
        ];
        unset($services);

        $qualityFields = $this->qualityMultipartFields($profile);
        foreach ($qualityFields as $field) {
            $multipart[] = $field;
        }

        foreach ($references as $i => $ref) {
            $mime = $this->normalizeImageMime((string) ($ref['mime'] ?? 'image/jpeg'));
            $multipart[] = [
                'name' => $i === 0 ? 'image' : 'image[]',
                'contents' => $ref['bytes'],
                'filename' => $this->referenceFilename($ref['name'] ?? 'image.jpg', $mime),
                'headers' => ['Content-Type' => $mime],
            ];
        }

        $parsed = $this->postImageRequest($baseUrl.'/images/generations', $apiKey, $timeout, $multipart, true);
        if ($parsed['success'] ?? false) {
            return $parsed;
        }

        $err = strtolower((string) ($parsed['error'] ?? ''));
        if ($this->isCloudflarePromptArrayError($parsed) || str_contains($err, 'input_fidelity') || str_contains($err, 'response_format') || str_contains($err, 'quality')) {
            $retry = array_values(array_filter(
                $multipart,
                fn ($part) => ! in_array($part['name'] ?? '', ['input_fidelity', 'quality', 'output_format', 'style', 'background', 'response_format'], true)
            ));
            $parsedRetry = $this->postImageRequest($baseUrl.'/images/generations', $apiKey, $timeout, $retry, true);
            if ($parsedRetry['success'] ?? false) {
                return $parsedRetry;
            }
            $parsed = $parsedRetry;
        }

        return $parsed;
    }

    protected function modelSupportsInputFidelity(string $model): bool
    {
        return ($this->imageModelProfile($model)['input_fidelity'] ?? null) !== null;
    }

    /**
     * @param  list<string>  $services
     * @param  array{quality?: ?string, output_format?: ?string, style?: ?string}  $profile
     * @return array{success: bool, bytes?: string, error?: string, provider: string}
     */
    protected function callGenerations(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $prompt,
        int $timeout,
        string $size = '1024x1536',
        array $services = [],
        array $profile = []
    ): array {
        if ($profile === []) {
            $profile = $this->imageModelProfile($model);
        }

        $payload = [
            'model' => $model !== '' ? $model : 'gpt-image-1.5',
            'prompt' => $this->clipImagePrompt($prompt),
            'size' => $size,
            'n' => 1,
            'response_format' => 'b64_json',
        ];
        if ($services !== []) {
            $payload['services'] = array_values($services);
        }
        if (! empty($profile['quality'])) {
            $payload['quality'] = $profile['quality'];
        }
        if (! empty($profile['output_format'])) {
            $payload['output_format'] = $profile['output_format'];
        }
        if (! empty($profile['style'])) {
            $payload['style'] = $profile['style'];
        }
        if (! empty($profile['background'])) {
            $payload['background'] = $profile['background'];
        }

        $parsed = $this->postImageRequest($baseUrl.'/images/generations', $apiKey, $timeout, $payload);
        if (($parsed['success'] ?? false) || (empty($profile['quality']) && empty($profile['output_format']) && empty($profile['style']) && empty($profile['background']))) {
            return $parsed;
        }

        unset($payload['quality'], $payload['output_format'], $payload['style'], $payload['background'], $payload['response_format']);

        return $this->postImageRequest($baseUrl.'/images/generations', $apiKey, $timeout, $payload);
    }

    /**
     * @param  mixed  $json
     */
    protected function extractImageBytesFromJson($json): ?string
    {
        foreach ($this->collectImageCandidates($json) as $candidate) {
            $bytes = $this->bytesFromImagePayload($candidate);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $node
     * @return list<string>
     */
    protected function collectImageCandidates($node, ?string $key = null): array
    {
        $out = [];
        if (is_string($node) && $node !== '') {
            $isHttp = str_starts_with($node, 'http://') || str_starts_with($node, 'https://');
            $isImagePath = (bool) preg_match('/\.(png|jpe?g|webp|gif)(\?|$)/i', $node);
            $looksUseful = in_array($key, ['b64_json', 'url', 'image_url'], true)
                || str_starts_with($node, 'data:image')
                || ($isHttp && ($isImagePath || in_array($key, ['url', 'image_url', 'output_url'], true)))
                || ($key === 'b64_json');
            if ($looksUseful && ! str_contains($node, '/var/www/') && ! str_ends_with(strtolower($node), '.php')) {
                $out[] = $node;
            }

            return $out;
        }

        if (! is_array($node)) {
            return $out;
        }

        foreach ($node as $childKey => $child) {
            $nextKey = is_string($childKey) ? $childKey : $key;
            foreach ($this->collectImageCandidates($child, $nextKey) as $candidate) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    protected function looksLikeImageBytes(string $bytes): bool
    {
        if (strlen($bytes) < 12) {
            return false;
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return true;
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return true;
        }
        if (strncmp($bytes, 'GIF8', 4) === 0) {
            return true;
        }
        if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        return false;
    }

    protected function rewriteMiiaAssetUrl(string $url): string
    {
        $base = rtrim((string) config('ai.providers.miia.base_url', 'https://ia.ceballosleon.com'), '/');
        $rewritten = preg_replace('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i', $base, $url);

        return is_string($rewritten) && $rewritten !== '' ? $rewritten : $url;
    }

    protected function bytesFromImagePayload(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/data:image\/[a-zA-Z0-9.+-]+;base64,([A-Za-z0-9+\/=]+)/', $value, $m)) {
            $bytes = base64_decode($m[1], true);
            if (is_string($bytes) && $this->looksLikeImageBytes($bytes)) {
                return $bytes;
            }
        }
        if (str_starts_with($value, '/')) {
            $base = rtrim((string) config('ai.providers.miia.base_url', 'https://ia.ceballosleon.com'), '/');
            $value = $base.$value;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $url = $this->rewriteMiiaAssetUrl($value);
            try {
                $pending = Http::timeout(60)
                    ->withHeaders(['Accept' => 'image/*,application/octet-stream']);
                if (is_string($this->activeImageApiKey) && $this->activeImageApiKey !== '') {
                    $pending = $pending->withToken($this->activeImageApiKey);
                }
                $download = $pending->get($url);
                $body = $download->body();
                if ($download->successful() && $this->looksLikeImageBytes($body)) {
                    return $body;
                }
                Log::warning('MIIA image URL did not return image bytes', [
                    'url' => $url,
                    'status' => $download->status(),
                    'prefix' => substr($body, 0, 40),
                ]);
            } catch (\Throwable $e) {
                Log::warning('MIIA image URL download failed', ['url' => $url, 'error' => $e->getMessage()]);
            }

            return null;
        }
        $compact = preg_replace('/\s+/', '', $value) ?? $value;
        if (! str_contains($compact, '{') && strlen($compact) > 200) {
            $bytes = base64_decode($compact, true);
            if (is_string($bytes) && $this->looksLikeImageBytes($bytes)) {
                return $bytes;
            }
        }

        return null;
    }

    protected function sanitizeMiiaError(int $status, mixed $json, string $raw): string
    {
        if ($status === 404 || str_contains($raw, 'Página No Encontrada')) {
            return 'MIIA no tiene este endpoint de imagen (HTTP '.$status.').';
        }
        if (is_array($json)) {
            $nested = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
            if (is_string($nested) && $nested !== '' && ! str_contains($nested, '<html')) {
                return self::explainImagePermissionError($nested);
            }
        }
        if (str_contains($raw, '<!DOCTYPE html>') || str_contains($raw, '<html')) {
            return 'MIIA devolvió HTML (HTTP '.$status.'), no una imagen.';
        }
        $trim = trim($raw);

        return self::explainImagePermissionError(
            $trim !== '' ? mb_substr($trim, 0, 280) : ('MIIA no pudo generar la imagen (HTTP '.$status.').')
        );
    }

    public static function explainImagePermissionError(string $message): string
    {
        $hay = mb_strtolower($message);
        if (str_contains($hay, 'no credits')
            || str_contains($hay, 'insufficient_quota')
            || str_contains($hay, 'insufficient quota')
            || (str_contains($hay, 'billing') && (str_contains($hay, 'credit') || str_contains($hay, 'platform.openai.com')))) {
            return 'MIIA/OpenAI no tiene créditos para gpt-image-1.5. Recarga créditos en https://platform.openai.com/settings/organization/billing o en ia.ceballosleon.com y vuelve a generar.';
        }
        if (str_contains($hay, 'generateimagewithcloudflare')
            || (str_contains($hay, 'must be of type string') && str_contains($hay, 'array given'))) {
            return 'MIIA no pudo adjuntar las fotos de referencia (el motor gratis no acepta ese formato). Se intenta generar solo con el prompt.';
        }
        if (! str_contains($hay, 'generación de imágenes') && ! str_contains($hay, 'generacion de imagenes')) {
            return $message;
        }

        return 'Tu API key de MIIA no tiene imágenes activadas. Entra a https://ia.ceballosleon.com → inicia sesión → API Keys → edita esa clave → activa «Generación de imágenes» → guarda. Luego vuelve a intentar aquí.';
    }

    /**
     * @return array{success: bool, bytes?: string, error?: string, status?: int, provider: string}
     */
    protected function parseImageResponse(\Illuminate\Http\Client\Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => $this->sanitizeMiiaError($status, $json, $response->body()),
                'status' => $status,
                'provider' => 'miia',
            ];
        }

        $body = $response->body();
        if ($this->looksLikeImageBytes($body)) {
            return [
                'success' => true,
                'bytes' => $body,
                'provider' => 'miia',
            ];
        }

        $fromAny = $this->extractImageBytesFromJson($json);
        if ($fromAny !== null) {
            return [
                'success' => true,
                'bytes' => $fromAny,
                'provider' => 'miia',
            ];
        }

        if (preg_match('/data:image\/[a-zA-Z0-9.+-]+;base64,([A-Za-z0-9+\/=]+)/', $body, $m)) {
            $bytes = base64_decode($m[1], true);
            if (is_string($bytes) && $this->looksLikeImageBytes($bytes)) {
                return [
                    'success' => true,
                    'bytes' => $bytes,
                    'provider' => 'miia',
                ];
            }
        }

        if (preg_match_all('/"(?:url|image_url|file|file_url|output_url|src)"\s*:\s*"(https?:\/\/[^"]+|\/[^"]+)"/i', $body, $matches)) {
            foreach ($matches[1] as $url) {
                $bytes = $this->bytesFromImagePayload($url);
                if ($bytes !== null) {
                    return [
                        'success' => true,
                        'bytes' => $bytes,
                        'provider' => 'miia',
                    ];
                }
            }
        }

        Log::warning('MIIA image JSON without bytes', [
            'status' => $status,
            'keys' => is_array($json) ? array_keys($json) : gettype($json),
            'data0_keys' => (is_array($json) && is_array($json['data'][0] ?? null))
                ? array_keys($json['data'][0])
                : (is_array($json) ? gettype($json['data'] ?? null) : gettype($json)),
            'snippet' => mb_substr(preg_replace('/(data:image\/[a-zA-Z0-9.+-]+;base64,)[A-Za-z0-9+\/=]{20,}/', '$1…', $body) ?? $body, 0, 500),
        ]);

        return [
            'success' => false,
            'error' => 'MIIA no incluyó datos de imagen en la respuesta.',
            'status' => $status,
            'provider' => 'miia',
        ];
    }

    /**
     * @return array{success: bool, url?: string, error?: string}
     */
    protected function storeGeneratedImage(string $bytes, int $storeId, ?string $filename = null): array
    {
        $filename = $filename ?: 'combo-ai-'.Str::lower(Str::random(8)).'.png';
        $path = 'combos/'.$storeId.'/'.$filename;

        if (! $this->looksLikeImageBytes($bytes)) {
            return [
                'success' => false,
                'error' => 'MIIA no devolvió una imagen (llegó HTML o un archivo inválido).',
            ];
        }

        if (! Storage::disk('public')->put($path, $bytes)) {
            return [
                'success' => false,
                'error' => 'No se pudo guardar la imagen generada.',
            ];
        }

        return [
            'success' => true,
            'url' => DesignAssetUrl::fromPath($path),
        ];
    }
}
