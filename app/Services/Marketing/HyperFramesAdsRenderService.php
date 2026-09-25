<?php

namespace App\Services\Marketing;

use App\Domain\AI\ProductVideoPromptService;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\MarketingVideo;
use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\VideoPlan\VideoPlanOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class HyperFramesAdsRenderService
{
    public function __construct(
        protected VideoIngestService $ingest,
        protected ProductMarketingMediaService $media,
        protected ProductVideoPromptService $promptGenerator,
        protected ProductPageScrapeService $pageScrape,
        protected VideoPlanOrchestrator $videoPlan,
    ) {}

    public function root(): string
    {
        return rtrim((string) config('multidrop.marketing.hyperframes.root', base_path('tools/hyperframes-ads')), DIRECTORY_SEPARATOR);
    }

    /**
     * Workspace del lado Multidrop. Separado de bridge/jobs para no chocar
     * cuando Docker y el bridge local montan el mismo tools/hyperframes-ads.
     */
    public function clientJobDir(string $jobId): string
    {
        return $this->root().DIRECTORY_SEPARATOR.'client-jobs'.DIRECTORY_SEPARATOR.$jobId;
    }

    public function timeoutSeconds(): int
    {
        return max(120, (int) config('multidrop.marketing.hyperframes.timeout_seconds', 2400));
    }

    public function mode(): string
    {
        return (string) config('multidrop.marketing.hyperframes.mode', 'remote');
    }

    public function isRemote(): bool
    {
        return $this->mode() === 'remote' && $this->remoteBaseUrl() !== '';
    }

    public function remoteBaseUrl(): string
    {
        return rtrim((string) config('multidrop.marketing.hyperframes.remote_url', ''), '/\\');
    }

    public function remoteToken(): string
    {
        return trim((string) config('multidrop.marketing.hyperframes.remote_token', ''));
    }

    public function configured(): bool
    {
        if ($this->mode() === 'remote') {
            // Bridge local :9014 puede correr sin token (token.txt vacío = abierto).
            return $this->remoteBaseUrl() !== '';
        }

        $root = $this->root();

        return is_dir($root)
            && is_file($root.DIRECTORY_SEPARATOR.'pipeline'.DIRECTORY_SEPARATOR.'run_pipeline.py');
    }

    public function cacheKey(string $jobId): string
    {
        return 'hyperframes_ads:'.$jobId;
    }

    /**
     * Metadatos crudos del job (cache). null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function statusMeta(string $jobId): ?array
    {
        $cached = Cache::get($this->cacheKey($jobId));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array{visual_style?: string, use_miia?: bool, preview?: bool}  $options
     * @return array{ok: bool, job_id?: string, message?: string}
     */
    public function enqueue(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        ?MarketingPrompt $prompt = null,
        bool $sync = false,
        array $options = []
    ): array {
        if (! $this->configured()) {
            return [
                'ok' => false,
                'message' => 'HyperFrames no está configurado (HYPERFRAMES_ADS_URL + HYPERFRAMES_ADS_TOKEN).',
            ];
        }

        $jobId = (string) Str::uuid();
        $jobDir = $this->clientJobDir($jobId);

        try {
            $this->bootstrapJobDirectory($jobDir);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $defaultStyle = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');
        $styleKeys = array_keys((array) config('multidrop.marketing.hyperframes.visual_styles', []));
        $visualStyle = trim((string) ($options['visual_style'] ?? $defaultStyle)) ?: $defaultStyle;
        if ($styleKeys !== [] && ! in_array($visualStyle, $styleKeys, true)) {
            $visualStyle = $defaultStyle;
        }

        $bootMsg = $prompt
            ? '0/8 Arrancando HyperFrames (estilo '.$visualStyle.')…'
            : '0/8 MIIA + catálogo · estilo '.$visualStyle.'…';

        $optionsPayload = [
            'visual_style' => $visualStyle,
            'use_miia' => $prompt === null || ! empty($options['use_miia']),
        ];
        file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'options.json',
            json_encode($optionsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        Cache::put($this->cacheKey($jobId), [
            'state' => 'queued',
            'message' => $bootMsg,
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'prompt_id' => $prompt?->id,
            'visual_style' => $visualStyle,
            'job_dir' => $jobDir,
            'video_id' => null,
            'error' => null,
            'step' => 0,
            'steps' => 8,
            'auto_prompt' => $prompt === null,
            'preview_mode' => ! empty($options['preview']),
        ], now()->addHours(8));
        $this->writeJobStatusFile($jobDir, 'queued', $bootMsg, 0, 8);

        if ($sync) {
            $this->spawnBackgroundProcess($jobId, $store->id, $campaign->id, $product->id, $prompt?->id);
        } else {
            \App\Jobs\RenderHyperFramesAdJob::dispatch(
                $store->id,
                $campaign->id,
                $product->id,
                $jobId,
                $prompt?->id
            );
        }

        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $jobId): array
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return ['state' => 'unknown', 'message' => 'Job no encontrado'];
        }

        $jobDir = (string) ($cached['job_dir'] ?? '');
        $statusFile = $jobDir !== '' ? $jobDir.DIRECTORY_SEPARATOR.'status.json' : '';
        if ($statusFile !== '' && is_file($statusFile)) {
            $disk = json_decode((string) file_get_contents($statusFile), true);
            if (is_array($disk)) {
                $diskState = (string) ($disk['state'] ?? '');
                $diskMsg = trim((string) ($disk['message'] ?? ''));
                if (isset($disk['step'])) {
                    $cached['step'] = (int) $disk['step'];
                }
                if (isset($disk['steps'])) {
                    $cached['steps'] = (int) $disk['steps'];
                }
                if (isset($disk['scrape']) && is_array($disk['scrape'])) {
                    $cached['scrape'] = $disk['scrape'];
                }
                if (isset($disk['creative']) && is_array($disk['creative'])) {
                    $cached['creative'] = $disk['creative'];
                }
                if (isset($disk['prompt_id'])) {
                    $cached['prompt_id'] = $disk['prompt_id'];
                }
                $cacheState = (string) ($cached['state'] ?? '');
                if (! in_array($cacheState, ['done', 'failed'], true)) {
                    if ($diskMsg !== '') {
                        $cached['message'] = $diskMsg;
                    }
                    if (in_array($diskState, ['running', 'prepared', 'queued'], true)) {
                        $cached['state'] = 'running';
                    }
                    if ($diskState === 'done') {
                        $cached['state'] = 'running';
                        if ($diskMsg === '' || ! str_contains(mb_strtolower($diskMsg), 'import')) {
                            $cached['message'] = $diskMsg !== ''
                                ? $diskMsg
                                : '8/8 Importando video a la campaña…';
                        }
                    }
                    if ($diskState === 'failed') {
                        $cached['state'] = 'failed';
                        $cached['error'] = $diskMsg !== '' ? $diskMsg : 'Pipeline falló';
                        $cached['message'] = $cached['error'];
                    }
                }
            }
        }

        $cacheStateAfter = (string) ($cached['state'] ?? '');
        if ($cacheStateAfter === 'cancelled' || ! empty($cached['cancelled'])) {
            $cached['state'] = 'cancelled';
            $cached['message'] = $cached['message'] ?? 'Cancelado por el usuario';

            return $cached;
        }

        // Fallback: scrape.json en disco si el cache aún no lo tiene (race web/CLI).
        if ($jobDir !== '' && (! isset($cached['scrape']) || ! is_array($cached['scrape']))) {
            $scrapeFile = $jobDir.DIRECTORY_SEPARATOR.'scrape.json';
            if (is_file($scrapeFile)) {
                $diskScrape = json_decode((string) file_get_contents($scrapeFile), true);
                if (is_array($diskScrape)) {
                    $angle = is_array($diskScrape['angle'] ?? null) ? $diskScrape['angle'] : null;
                    unset($diskScrape['angle']);
                    $cached['scrape'] = [
                        'url' => $diskScrape['url'] ?? null,
                        'title' => $diskScrape['title'] ?? null,
                        'description' => $diskScrape['description'] ?? null,
                        'bullets' => is_array($diskScrape['bullets'] ?? null) ? $diskScrape['bullets'] : [],
                        'plain_excerpt' => $diskScrape['plain_excerpt'] ?? null,
                    ];
                    if ($angle !== null && ! isset($cached['creative'])) {
                        $cached['creative'] = [
                            'angle_type' => $angle['type'] ?? null,
                            'angle_label' => $angle['label'] ?? null,
                            'problem' => $angle['problem'] ?? null,
                            'value' => $angle['value'] ?? null,
                            'hook' => $angle['hook'] ?? null,
                            'beats' => $angle['beats'] ?? [],
                        ];
                    }
                }
            }
        }

        if (in_array($cacheStateAfter, ['queued', 'running', 'prepared'], true) && $this->isRemote()) {
            $remote = $this->fetchRemoteStatus($jobId);
            if (is_array($remote)) {
                $remoteState = (string) ($remote['state'] ?? 'unknown');
                $remoteMsg = trim((string) ($remote['message'] ?? ''));
                if (isset($remote['step'])) {
                    $cached['step'] = (int) $remote['step'];
                }
                if (isset($remote['steps'])) {
                    $cached['steps'] = (int) $remote['steps'];
                }
                if ($remoteMsg !== '') {
                    $cached['message'] = $remoteMsg;
                }
                if (in_array($remoteState, ['queued', 'prepared', 'running'], true)) {
                    $cached['state'] = 'running';
                }
                if ($remoteState === 'done') {
                    $cached['state'] = 'running';
                    if ($remoteMsg === '') {
                        $cached['message'] = '8/8 Importando video a la campaña…';
                    }
                }
                if ($remoteState === 'failed') {
                    $cached['state'] = 'failed';
                    $cached['error'] = $remoteMsg !== '' ? $remoteMsg : 'El pipeline remoto falló';
                    $cached['message'] = $cached['error'];
                }
            }
        }

        return $cached;
    }

    public function runAndIngest(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        string $jobId,
        ?MarketingPrompt $prompt = null
    ): ?MarketingVideo {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobDir = $this->clientJobDir($jobId);
        if (! is_dir($jobDir)) {
            throw new \RuntimeException('Carpeta del job HyperFrames no existe.');
        }

        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        $prompt = $this->ensureCreativePrompt($store, $campaign, $product, $prompt, $jobId);

        $this->patchStatusCache($jobId, 'running', '0/8 Descargando medios del producto…', 0, 8);
        $this->writeJobStatusFile($jobDir, 'running', '0/8 Descargando medios del producto…', 0, 8);
        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }
        $this->prepareJobDirectory($store, $campaign, $product, $prompt, $jobDir);

        $planMeta = null;
        if ($this->videoPlan->enabled()) {
            $this->patchStatusCache($jobId, 'running', '0/8 Miia planificando escenas y copy…', 0, 8);
            $this->writeJobStatusFile($jobDir, 'running', '0/8 Miia planificando escenas y copy…', 0, 8);
            try {
                $planMeta = $this->videoPlan->process($store, $campaign, $product, $prompt, $jobDir, $jobId, [
                    'language' => $prompt?->language ?: 'es',
                    'visual_style' => $this->resolveJobVisualStyle($jobDir),
                ]);
            } catch (\Throwable $e) {
                Log::warning('HyperFrames: planificación Miia falló; se sigue con el flujo anterior', [
                    'job_id' => $jobId,
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                $planMeta = null;
            }
        }

        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        if ($this->isPreviewPaused($jobId)) {
            $this->pauseForReview($jobId);

            return null;
        }

        return $this->renderAndIngestTail($store, $campaign, $product, $prompt, $jobId, $planMeta);
    }

    /**
     * Render (remoto o local) + importación. Reutilizado por el flujo normal
     * (sin revisión) y por la reanudación post-modal.
     *
     * @param  array<string, mixed>|null  $planMeta
     */
    protected function renderAndIngestTail(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        ?MarketingPrompt $prompt,
        string $jobId,
        ?array $planMeta
    ): MarketingVideo {
        $jobDir = $this->clientJobDir($jobId);
        $mp4 = $jobDir.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'final.mp4';

        if ($this->isRemote()) {
            $mp4 = $this->renderRemoteJob($jobId);
        } else {
            $this->patchStatusCache($jobId, 'running', '1/8 Iniciando pipeline HyperFrames local…', 1, 8);
            $this->writeJobStatusFile($jobDir, 'running', '1/8 Iniciando pipeline HyperFrames local…', 1, 8);
            $script = $this->root().DIRECTORY_SEPARATOR.'pipeline'.DIRECTORY_SEPARATOR.'run_pipeline.py';
            $python = $this->pythonBinary();
            $result = Process::timeout($this->timeoutSeconds())
                ->path($this->root())
                ->env($this->pythonProcessEnv())
                ->run([$python, $script, $jobDir]);

            if ((! $result->successful()) && (! is_file($mp4) || filesize($mp4) < 1024)) {
                $err = trim($result->errorOutput() ?: $result->output());
                $err = preg_replace('/\s+/', ' ', $err) ?? $err;
                $failMsg = mb_substr($err, -800) !== '' ? mb_substr($err, -800) : 'El pipeline HyperFrames falló.';
                $this->writeJobStatusFile($jobDir, 'failed', $failMsg, null, 8);
                throw new \RuntimeException($failMsg);
            }
        }

        if (! is_file($mp4) || filesize($mp4) < 1024) {
            $this->writeJobStatusFile($jobDir, 'failed', 'No se generó out/final.mp4', null, 8);
            throw new \RuntimeException('No se generó out/final.mp4');
        }

        $this->patchStatusCache($jobId, 'running', '8/8 Importando video a la campaña…', 8, 8);
        $this->writeJobStatusFile($jobDir, 'running', '8/8 Importando video a la campaña…', 8, 8);

        try {
            $video = $this->ingest->ingestFromLocalPath(
                $store,
                $campaign,
                $mp4,
                $prompt,
                'hyperframes',
                $jobId
            );
            if ((int) ($video->product_id ?: 0) !== (int) $product->id) {
                $video->product_id = $product->id;
                $video->save();
            }

            if (is_array($planMeta)) {
                $this->videoPlan->persistMeta($planMeta, (string) $video->id);
            }

            return $video;
        } finally {
            if ($this->isRemote()) {
                $this->cleanupWorkspace($jobId);
            }
        }
    }

    /**
     * Modo revisión: el pipeline se detiene antes de renderizar para que el
     * usuario personalice guion, plan y archivos desde un modal.
     */
    protected function isPreviewPaused(string $jobId): bool
    {
        $cached = Cache::get($this->cacheKey($jobId));

        return is_array($cached)
            && ! empty($cached['preview_mode'])
            && (string) ($cached['state'] ?? '') !== 'cancelled';
    }

    protected function pauseForReview(string $jobId): void
    {
        $cached = Cache::get($this->cacheKey($jobId));
        $jobDir = is_array($cached) ? (string) ($cached['job_dir'] ?? '') : '';

        $payload = $this->reviewPayload($jobId);
        if ($payload !== null && $jobDir !== '' && is_dir($jobDir)) {
            @file_put_contents(
                $jobDir.DIRECTORY_SEPARATOR.'review.json',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
            );
        }

        $msg = 'Guion y medios listos. Revisa y personaliza antes de renderizar.';
        $this->patchStatusCache($jobId, 'awaiting_review', $msg, 2, 8);
        if ($jobDir !== '' && is_dir($jobDir)) {
            $this->writeJobStatusFile($jobDir, 'awaiting_review', $msg, 2, 8);
        }
    }

    /**
     * Payload para el modal de revisión: textos editables por path,
     * archivos generados y estilo visual. null si el job no se puede revisar.
     *
     * @return array<string, mixed>|null
     */
    public function reviewPayload(string $jobId): ?array
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return null;
        }
        $jobDir = (string) ($cached['job_dir'] ?? '');
        if ($jobDir === '' || ! is_dir($jobDir)) {
            return null;
        }

        $read = function (string $name) use ($jobDir): array {
            $path = $jobDir.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path)) {
                return [];
            }
            $decoded = json_decode((string) file_get_contents($path), true);

            return is_array($decoded) ? $decoded : [];
        };

        $product = $read('product.json');
        $prompt = $read('prompt.json');
        $options = $read('options.json');
        $plan = $read('video_plan.json');
        $hasPlan = $plan !== [];

        $styles = (array) config('multidrop.marketing.hyperframes.visual_styles', []);
        $styleKeys = array_keys($styles);
        $defaultStyle = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');
        $curStyle = trim((string) ($options['visual_style'] ?? $cached['visual_style'] ?? '')) ?: $defaultStyle;
        if ($styleKeys !== [] && ! in_array($curStyle, $styleKeys, true)) {
            $curStyle = $defaultStyle;
        }

        $creative = $hasPlan && is_array($plan['creative'] ?? null) ? $plan['creative'] : [];

        $brief = [
            'hook' => (string) (data_get($prompt, 'hook') ?: data_get($creative, 'hook') ?: ''),
            'cta' => (string) ($prompt['cta'] ?? data_get($creative, 'cta') ?? $product['cta'] ?? 'Compra ahora'),
            'script' => (string) ($prompt['script'] ?? ''),
            'language' => (string) ($prompt['language'] ?? 'es'),
            'style' => $curStyle,
            'style_label' => (string) (data_get($styles, $curStyle.'.label') ?: $curStyle),
            'audience' => (string) ($prompt['audience'] ?? data_get($creative, 'audience') ?? ''),
            'target_platform' => (string) ($prompt['target_platform'] ?? 'Tiktok'),
        ];

        $scenes = [];
        if ($hasPlan && is_array($plan['scenes'] ?? null)) {
            foreach ($plan['scenes'] as $i => $scene) {
                if (! is_array($scene)) {
                    continue;
                }
                $nodes = [];
                $elements = is_array($scene['elements'] ?? null) ? $scene['elements'] : [];
                foreach ($elements as $j => $el) {
                    if (! is_array($el)) {
                        continue;
                    }
                    $type = (string) ($el['type'] ?? 'text');
                    if (array_key_exists('text', $el)) {
                        $nodes[] = [
                            'path' => 'plan.scenes.'.$i.'.elements.'.$j.'.text',
                            'label' => $type,
                            'value' => (string) ($el['text'] ?? ''),
                        ];
                    }
                    if (in_array($type, ['feature_list', 'feature-list'], true) && is_array($el['items'] ?? null)) {
                        foreach ($el['items'] as $k => $item) {
                            $nodes[] = [
                                'path' => 'plan.scenes.'.$i.'.elements.'.$j.'.items.'.$k,
                                'label' => $type.' · ítem '.($k + 1),
                                'value' => is_scalar($item) ? (string) $item : '',
                            ];
                        }
                    }
                }
                $scenes[] = [
                    'purpose' => (string) ($scene['purpose'] ?? 'escena '.($i + 1)),
                    'nodes' => $nodes,
                ];
            }
        }

        $dialogue = [];
        if ($hasPlan && is_array($plan['dialogue'] ?? null)) {
            foreach ($plan['dialogue'] as $i => $d) {
                if (! is_array($d)) {
                    continue;
                }
                $dialogue[] = [
                    'path' => 'plan.dialogue.'.$i.'.text',
                    'label' => (string) ($d['speaker'] ?? 'narrador'),
                    'value' => (string) ($d['text'] ?? ''),
                ];
            }
        }

        $extraCreative = [];
        if ($hasPlan) {
            foreach (['hook', 'mainBenefit', 'cta', 'tone', 'audience'] as $key) {
                if (array_key_exists($key, $creative)) {
                    $extraCreative[] = [
                        'path' => 'plan.creative.'.$key,
                        'label' => $key,
                        'value' => is_scalar($creative[$key]) ? (string) $creative[$key] : '',
                    ];
                }
            }
        }

        $niceStyles = [];
        foreach ($styles as $key => $meta) {
            $niceStyles[$key] = [
                'label' => is_array($meta) ? ((string) ($meta['label'] ?? $key)) : (string) $key,
                'hint' => is_array($meta) ? (($meta['hint'] ?? null) !== null ? (string) $meta['hint'] : null) : null,
            ];
        }

        $payload = [
            'job_id' => $jobId,
            'prompt_id' => (int) ($cached['prompt_id'] ?? 0),
            'product' => [
                'name' => (string) ($product['name'] ?? ''),
                'price' => $product['price'] ?? null,
                'compare_at_price' => $product['compare_at_price'] ?? null,
                'currency' => (string) ($product['currency'] ?? 'USD'),
                'sku' => (string) ($product['sku'] ?? ''),
                'badge' => (string) ($product['badge'] ?? ''),
                'description' => (string) ($product['description'] ?? ''),
                'image_url' => (string) ($product['image_url'] ?? ''),
                'product_url' => (string) ($product['product_url'] ?? ''),
            ],
            'brief' => $brief,
            'creative' => $extraCreative,
            'has_plan' => $hasPlan,
            'scenes' => $scenes,
            'dialogue' => $dialogue,
            'files' => [
                'images' => $this->listDirFiles($jobDir.DIRECTORY_SEPARATOR.'images'),
                'videos' => $this->listDirFiles($jobDir.DIRECTORY_SEPARATOR.'videos'),
            ],
            'styles' => $niceStyles,
            'options' => [
                'visual_style' => $curStyle,
                'use_miia' => ! empty($cached['auto_prompt']),
            ],
        ];

        $payload['files']['images'] = $this->withImageUrls($payload['files']['images'], $jobDir);

        return $payload;
    }

    /**
     * Adjunta la URL de origen (manifest de descarga) a cada imagen de la lista,
     * para mostrar miniaturas reales en el modal de revisión.
     *
     * @param  list<array{name: string, size: int}>  $images
     * @return list<array{name: string, size: int, url: string}>
     */
    protected function withImageUrls(array $images, string $jobDir): array
    {
        $map = [];
        $manifestPath = $jobDir.DIRECTORY_SEPARATOR.'media_manifest.json';
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            foreach (is_array($decoded['images'] ?? null) ? $decoded['images'] : [] as $row) {
                if (is_array($row) && ! empty($row['file'])) {
                    $map[(string) $row['file']] = (string) ($row['url'] ?? '');
                }
            }
        }
        foreach ($images as &$img) {
            $img['url'] = $map[$img['name']] ?? '';
        }
        unset($img);

        return $images;
    }

    /**
     * Carga los datos para el modal de revisión de un video ya generado
     * («Regenerar»): guion del prompt ligado + medios del producto.
     * Sin plan de escenas: al regenerar se vuelve a planificar desde el brief.
     */
    public function reviewPayloadFromVideo(int $videoId, int $storeId): ?array
    {
        $video = MarketingVideo::query()
            ->with(['prompt', 'product', 'campaign.store'])
            ->where('id', $videoId)
            ->first();

        if (! $video || (int) ($video->campaign?->store_id ?? 0) !== $storeId) {
            return null;
        }

        $product = $video->product;
        $campaign = $video->campaign;
        $prompt = $video->prompt;
        if (! $product || ! $prompt) {
            return null;
        }

        $analysis = is_array($prompt->analysis) ? $prompt->analysis : [];
        $creativeDir = is_array($analysis['creative_direction'] ?? null) ? $analysis['creative_direction'] : [];
        $branding = is_array($creativeDir['branding'] ?? null) ? $creativeDir['branding'] : [];

        $styles = (array) config('multidrop.marketing.hyperframes.visual_styles', []);
        $defaultStyle = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');

        $brief = [
            'hook' => (string) ($prompt->hook ?? ''),
            'cta' => (string) ($analysis['cta'] ?? data_get($creativeDir, 'brand.cta') ?? ''),
            'script' => (string) ($prompt->script ?? ''),
            'language' => (string) ($analysis['language'] ?? $prompt->language ?? 'es'),
            'style' => $defaultStyle,
            'style_label' => (string) (data_get($styles, $defaultStyle.'.label') ?: $defaultStyle),
            'audience' => (string) ($analysis['audience'] ?? ''),
            'target_platform' => (string) ($analysis['target_platform'] ?? 'Tiktok'),
        ];

        $spokes = [];
        foreach (is_array($analysis['spokes'] ?? null) ? $analysis['spokes'] : [] as $i => $spoke) {
            if (! is_array($spoke)) {
                continue;
            }
            $name = trim((string) ($spoke['name'] ?? ''));
            $role = trim((string) ($spoke['role'] ?? ''));
            if ($name === '' && $role === '') {
                continue;
            }
            $spokes[] = [
                'path' => 'prompt.spokes.'.$i.'.desc',
                'label' => (string) ($spoke['name'] ?? 'spoke '.($i + 1)),
                'value' => trim($name.' — '.$role, " \t\n\r\0\x0B—"),
            ];
        }

        $channel = is_array($creativeDir['channel'] ?? null) ? $creativeDir['channel'] : [];
        $extraCreative = [];
        foreach (['tone' => 'Tono', 'audience' => 'Audiencia'] as $key => $label) {
            $value = is_string($channel[$key] ?? null) ? trim((string) $channel[$key]) : '';
            if ($value !== '') {
                $extraCreative[] = ['path' => 'plan.creative.'.$key, 'label' => $label, 'value' => $value];
            }
        }

        $images = [];
        $urls = [];
        if (is_string($product->image_url) && $product->image_url !== '') {
            $urls[] = $product->image_url;
        }
        foreach ($product->galleryImages() as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        foreach (array_slice(array_values(array_unique($urls)), 0, 4) as $i => $url) {
            $images[] = ['name' => sprintf('product_%02d.jpg', $i + 1), 'size' => 0, 'url' => $url];
        }

        $videos = [];
        foreach (array_slice($this->media->exportVideoEntries($product), 0, 2) as $i => $entry) {
            $videos[] = [
                'name' => sprintf('product_%02d.mp4', $i + 1),
                'size' => 0,
                'url' => (string) ($entry['url'] ?? ''),
            ];
        }

        $niceStyles = [];
        foreach ($styles as $key => $meta) {
            $niceStyles[$key] = [
                'label' => is_array($meta) ? ((string) ($meta['label'] ?? $key)) : (string) $key,
                'hint' => is_array($meta) ? (($meta['hint'] ?? null) !== null ? (string) $meta['hint'] : null) : null,
            ];
        }

        return [
            'job_id' => null,
            'video_id' => (int) $video->id,
            'prompt_id' => (int) $prompt->id,
            'campaign_id' => (int) $campaign->id,
            'product_id' => (int) $product->id,
            'product' => [
                'name' => $product->localizedName() ?: $product->name,
                'description' => $product->localizedDescription() ?: (string) $product->description,
                'price' => $product->price,
                'compare_at_price' => $product->compare_at_price,
                'currency' => $product->currency ?: 'USD',
                'sku' => $product->sku,
                'badge' => $product->badge,
                'image_url' => $product->image_url,
                'product_url' => rtrim((string) ($campaign->store?->publicUrl() ?? ''), '/').'/pages/'.$product->slug,
            ],
            'brief' => $brief,
            'creative' => $extraCreative,
            'spokes' => $spokes,
            'has_plan' => false,
            'scenes' => [],
            'dialogue' => [],
            'files' => ['images' => $images, 'videos' => $videos],
            'styles' => $niceStyles,
            'options' => [
                'visual_style' => $defaultStyle,
                'use_miia' => false,
            ],
        ];
    }

    /**
     * Re-genera un video HyperFrames partiendo del guion del video existente:
     * clona el prompt (sin tocar el original), aplica las ediciones de texto
     * top-level y encola un nuevo job en modo preview (revisión → confirm).
     *
     * @param  array{texts?: array<string, string>}  $edits
     * @return array{ok: bool, job_id?: string, message?: string}
     */
    public function regenerate(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        int $videoId,
        array $edits,
        ?string $visualStyle = null
    ): array {
        $video = MarketingVideo::query()
            ->where('id', $videoId)
            ->where('campaign_id', $campaign->id)
            ->first();

        if (! $video || ! $video->prompt) {
            return ['ok' => false, 'message' => 'El video no tiene guion para regenerar.'];
        }

        $source = $video->prompt;

        $cleanTexts = [];
        foreach ((is_array($edits['texts'] ?? null) ? $edits['texts'] : []) as $path => $value) {
            if (! is_string($path) || ! preg_match('/^prompt(\.[a-zA-Z0-9_]+)+$/', $path)) {
                continue;
            }
            $cleanTexts[$path] = is_scalar($value) ? (string) $value : '';
        }

        $clone = $source->replicate();
        if (array_key_exists('prompt.hook', $cleanTexts)) {
            $clone->hook = $cleanTexts['prompt.hook'];
        }
        if (array_key_exists('prompt.script', $cleanTexts)) {
            $clone->script = $cleanTexts['prompt.script'];
        }
        $clone->save();

        if (array_key_exists('prompt.cta', $cleanTexts)) {
            $analysis = is_array($clone->analysis) ? $clone->analysis : [];
            if (mb_strlen($cleanTexts['prompt.cta']) > 80) {
                return ['ok' => false, 'message' => 'El CTA supera los 80 caracteres.'];
            }
            $analysis['cta'] = $cleanTexts['prompt.cta'];
            $clone->analysis = $analysis;
            $clone->save();
        }

        $sync = (bool) config('multidrop.marketing.hyperframes.sync', true);
        $result = $this->enqueue($store, $campaign, $product, $clone, $sync, [
            'visual_style' => trim((string) $visualStyle) ?: (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal'),
            'use_miia' => false,
            'preview' => true,
        ]);

        return $result;
    }

    /**
     * Aplica las ediciones del modal al workspace del job y guarda un snapshot
     * (preview_edits.json) que la reanudación aplica sobre los JSON finales.
     *
     * @param  array{texts?: array<string, string>, visual_style?: string, exclude_images?: array<int, string>, exclude_videos?: array<int, string>}  $edits
     * @return array{ok: bool, message?: string}
     */
    public function applyReviewEdits(string $jobId, array $edits, int $storeId): array
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return ['ok' => false, 'message' => 'Job no encontrado'];
        }
        if ((int) ($cached['store_id'] ?? 0) !== $storeId) {
            return ['ok' => false, 'message' => 'Job de otra tienda'];
        }
        $jobDir = (string) ($cached['job_dir'] ?? '');
        if ($jobDir === '' || ! is_dir($jobDir)) {
            return ['ok' => false, 'message' => 'Workspace del job no existe'];
        }

        $cleanTexts = [];
        foreach ((is_array($edits['texts'] ?? null) ? $edits['texts'] : []) as $path => $value) {
            if (! is_string($path) || ! preg_match('/^(prompt|plan)(\.[a-zA-Z0-9_]+)+$/', $path)) {
                continue;
            }
            $cleanTexts[$path] = is_scalar($value) ? (string) $value : '';
        }

        $style = trim((string) ($edits['visual_style'] ?? ''));
        $styleKeys = array_keys((array) config('multidrop.marketing.hyperframes.visual_styles', []));
        if ($styleKeys !== [] && $style !== '' && ! in_array($style, $styleKeys, true)) {
            $style = '';
        }

        $excludeImages = $this->sanitizeAssetNames($edits['exclude_images'] ?? null);
        $excludeVideos = $this->sanitizeAssetNames($edits['exclude_videos'] ?? null);

        if ($cleanTexts === [] && $style === '' && $excludeImages === [] && $excludeVideos === []) {
            return ['ok' => true, 'message' => 'Sin cambios'];
        }

        $promptId = (int) ($cached['prompt_id'] ?? 0);
        if ($promptId !== 0) {
            $prompt = MarketingPrompt::query()
                ->where('store_id', $storeId)
                ->where('id', $promptId)
                ->first();
            if ($prompt) {
                $changed = false;
                if (array_key_exists('prompt.hook', $cleanTexts)) {
                    $prompt->hook = $cleanTexts['prompt.hook'];
                    $changed = true;
                }
                if (array_key_exists('prompt.script', $cleanTexts)) {
                    $prompt->script = $cleanTexts['prompt.script'];
                    $changed = true;
                }
                if (array_key_exists('prompt.cta', $cleanTexts)) {
                    $analysis = is_array($prompt->analysis) ? $prompt->analysis : [];
                    $analysis['cta'] = $cleanTexts['prompt.cta'];
                    $prompt->analysis = $analysis;
                    $changed = true;
                }
                if ($changed) {
                    $prompt->save();
                }
            }
        }

        if ($style !== '') {
            file_put_contents(
                $jobDir.DIRECTORY_SEPARATOR.'options.json',
                json_encode([
                    'visual_style' => $style,
                    'use_miia' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
            );
            $cached['visual_style'] = $style;
            Cache::put($this->cacheKey($jobId), $cached, now()->addHours(8));
        }

        file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'preview_edits.json',
            json_encode([
                'visual_style' => $style,
                'texts' => $cleanTexts,
                'exclude_images' => $excludeImages,
                'exclude_videos' => $excludeVideos,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        return ['ok' => true];
    }

    /**
     * Reanuda un job pausado en revisión: despacha el render con las ediciones.
     *
     * @return array{ok: bool, job_id?: string, message?: string}
     */
    public function resume(string $jobId, int $storeId, int $campaignId, int $productId, bool $sync): array
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return ['ok' => false, 'message' => 'Job no encontrado'];
        }
        if ((int) ($cached['store_id'] ?? 0) !== $storeId) {
            return ['ok' => false, 'message' => 'Job de otra tienda'];
        }

        $state = (string) ($cached['state'] ?? '');
        if (in_array($state, ['done', 'failed', 'cancelled'], true)) {
            return ['ok' => true, 'job_id' => $jobId, 'message' => 'El job ya terminó ('.$state.').'];
        }
        if (in_array($state, ['running', 'prepared', 'queued'], true)) {
            return ['ok' => true, 'job_id' => $jobId, 'message' => 'El render ya está en curso.'];
        }

        $jobDir = (string) ($cached['job_dir'] ?? '');
        $cached['preview_mode'] = false;
        $cached['resumed'] = true;
        $cached['state'] = 'running';
        $cached['step'] = 1;
        $cached['steps'] = 8;
        $cached['message'] = '1/8 Aplicando tu revisión y enviando a HyperFrames…';
        Cache::put($this->cacheKey($jobId), $cached, now()->addHours(8));
        if ($jobDir !== '' && is_dir($jobDir)) {
            $this->writeJobStatusFile($jobDir, 'running', '1/8 Aplicando tu revisión y enviando a HyperFrames…', 1, 8);
        }

        $promptId = (int) ($cached['prompt_id'] ?? 0);
        if ($sync) {
            $this->spawnBackgroundProcess($jobId, $storeId, $campaignId, $productId, $promptId ?: null, true);
        } else {
            \App\Jobs\RenderHyperFramesAdJob::dispatch($storeId, $campaignId, $productId, $jobId, $promptId ?: null, true);
        }

        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Continuación del pipeline post-revisión: reusa el prompt y los medios ya
     * preparados, aplica el snapshot de ediciones y renderiza.
     */
    public function resumeAndIngest(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        string $jobId
    ): MarketingVideo {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobDir = $this->clientJobDir($jobId);
        if (! is_dir($jobDir)) {
            throw new \RuntimeException('Carpeta del job HyperFrames no existe.');
        }
        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        $cached = Cache::get($this->cacheKey($jobId));
        $promptId = is_array($cached) ? (int) ($cached['prompt_id'] ?? 0) : 0;
        $prompt = $promptId !== 0
            ? MarketingPrompt::query()->where('store_id', $store->id)->where('id', $promptId)->first()
            : null;

        $this->patchStatusCache($jobId, 'running', '0/8 Preparando medios con tus cambios…', 0, 8);
        $this->writeJobStatusFile($jobDir, 'running', '0/8 Preparando medios con tus cambios…', 0, 8);
        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        $this->prepareJobDirectory($store, $campaign, $product, $prompt, $jobDir);
        $this->applyEditsOnDisk($jobId, $jobDir);

        return $this->renderAndIngestTail($store, $campaign, $product, $prompt, $jobId, null);
    }

    /**
     * Aplica preview_edits.json sobre prompt.json / video_plan.json y elimina
     * los archivos excluidos del modal. Borra el snapshot al terminar.
     *
     * @param  string  $jobId
     */
    protected function applyEditsOnDisk(string $jobId, string $jobDir): void
    {
        $editsPath = $jobDir.DIRECTORY_SEPARATOR.'preview_edits.json';
        if (! is_file($editsPath)) {
            return;
        }
        $edits = json_decode((string) file_get_contents($editsPath), true);
        if (! is_array($edits)) {
            @unlink($editsPath);

            return;
        }

        $texts = is_array($edits['texts'] ?? null) ? $edits['texts'] : [];
        if ($texts !== []) {
            $promptTexts = [];
            $planTexts = [];
            foreach ($texts as $path => $value) {
                if (! is_string($path) || ! is_scalar($value)) {
                    continue;
                }
                if (str_starts_with($path, 'prompt.')) {
                    $promptTexts[substr($path, 7)] = (string) $value;
                } elseif (str_starts_with($path, 'plan.')) {
                    $planTexts[substr($path, 5)] = (string) $value;
                }
            }

            if ($promptTexts !== []) {
                $p = $jobDir.DIRECTORY_SEPARATOR.'prompt.json';
                if (is_file($p)) {
                    $doc = json_decode((string) file_get_contents($p), true);
                    if (is_array($doc)) {
                        file_put_contents(
                            $p,
                            json_encode(self::applyTextEditsToDoc($doc, $promptTexts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
                        );
                    }
                }
            }
            if ($planTexts !== []) {
                $vp = $jobDir.DIRECTORY_SEPARATOR.'video_plan.json';
                if (is_file($vp)) {
                    $doc = json_decode((string) file_get_contents($vp), true);
                    if (is_array($doc)) {
                        file_put_contents(
                            $vp,
                            json_encode(self::applyTextEditsToDoc($doc, $planTexts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
                        );
                    }
                }
            }
        }

        foreach (['exclude_images' => 'images', 'exclude_videos' => 'videos'] as $key => $sub) {
            foreach ((array) ($edits[$key] ?? []) as $name) {
                if (! is_string($name) || $name !== basename($name)) {
                    continue;
                }
                $full = $jobDir.DIRECTORY_SEPARATOR.$sub.DIRECTORY_SEPARATOR.$name;
                if (is_file($full)) {
                    @unlink($full);
                }
            }
        }

        @unlink($editsPath);
    }

    /**
     * Merge de ediciones por path (ej. "scenes.0.elements.0.text") sobre un doc
     * JSON. Helper puro para poder testear.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, string>  $texts
     * @return array<string, mixed>
     */
    public static function applyTextEditsToDoc(array $doc, array $texts): array
    {
        foreach ($texts as $path => $value) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $segments = array_values(array_filter(explode('.', $path), fn ($s) => $s !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $s)));
            $segments = array_map(fn ($s) => ctype_digit($s) ? (int) $s : $s, $segments);
            if ($segments === []) {
                continue;
            }
            data_set($doc, $segments, $value);
        }

        return $doc;
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    protected function listDirFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            if (! is_file($full)) {
                continue;
            }
            $out[] = ['name' => $name, 'size' => (int) (filesize($full) ?: 0)];
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function sanitizeAssetNames(mixed $names): array
    {
        $out = [];
        if (! is_array($names)) {
            return $out;
        }
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = trim($name);
            if ($name === '' || $name !== basename($name) || str_contains($name, "\0")) {
                continue;
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    public function failJob(string $jobId, string $message): void
    {
        if ($this->isCancelled($jobId)) {
            return;
        }
        $this->patchStatusCache($jobId, 'failed', $message);
        $cached = Cache::get($this->cacheKey($jobId));
        $jobDir = is_array($cached) ? (string) ($cached['job_dir'] ?? '') : '';
        if ($jobDir !== '') {
            $this->writeJobStatusFile($jobDir, 'failed', $message);
        }
    }

    /**
     * Asegura un brief creativo.
     * Sin prompt: scrapea la ficha con Cloudflare (texto) y arma el guion con MIIA texto
     * o fallback local si MIIA falla. No usa visión de imágenes.
     */
    public function ensureCreativePrompt(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        ?MarketingPrompt $prompt,
        string $jobId
    ): MarketingPrompt {
        if ($prompt) {
            $existingScrape = data_get($prompt->analysis, 'scrape');
            $existingCreative = null;
            $analysis = is_array($prompt->analysis) ? $prompt->analysis : [];
            if (! empty($analysis['problem']) || ! empty($analysis['angle_type'])) {
                $existingCreative = [
                    'type' => $analysis['angle_type'] ?? null,
                    'label' => $analysis['angle_label'] ?? null,
                    'problem' => $analysis['problem'] ?? null,
                    'value' => $analysis['value_prop'] ?? null,
                    'hook' => $prompt->hook,
                    'beats' => is_array($analysis['beats'] ?? null) ? $analysis['beats'] : [],
                ];
            }
            if (is_array($existingScrape)) {
                $this->rememberScrapeSummary($jobId, $existingScrape, $existingCreative);
            }

            return $prompt;
        }

        $jobDir = $this->clientJobDir($jobId);
        $language = $store->configuredLocale() ?: 'es';

        $this->patchStatusCache(
            $jobId,
            'running',
            '0/8 Cloudflare: scrapeando ficha del producto…',
            0,
            8
        );
        $this->writeJobStatusFile(
            $jobDir,
            'running',
            '0/8 Cloudflare: scrapeando ficha del producto…',
            0,
            8
        );

        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        $scrape = $this->pageScrape->scrape($store, $product);
        if (! ($scrape['success'] ?? false) || $this->pageScrape->isWeakAgainstProduct($product, $scrape)) {
            Log::info('HyperFrames: scrape débil/basura → brief desde catálogo', [
                'job_id' => $jobId,
                'product_id' => $product->id,
                'error' => $scrape['error'] ?? null,
                'title' => $scrape['title'] ?? null,
            ]);
            $scrape = $this->pageScrape->fromCatalog($product);
        }

        $scrapeSummary = $this->pageScrape->publicSummary($scrape);
        $creative = $this->pageScrape->lightCreativeAngle($product, $scrape);
        $this->rememberScrapeSummary($jobId, $scrapeSummary, $creative);
        @file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'scrape.json',
            json_encode(array_merge($scrapeSummary, [
                'angle' => $creative,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        $this->patchStatusCache(
            $jobId,
            'running',
            '0/8 Ficha leída. Ángulo '.$creative['label'].'. Armando brief ligero…',
            0,
            8
        );
        $this->writeJobStatusFile(
            $jobDir,
            'running',
            '0/8 Ficha leída. Armando brief creativo ligero…',
            0,
            8
        );

        if ($this->isCancelled($jobId)) {
            throw new \RuntimeException('Generación cancelada por el usuario.');
        }

        $result = null;
        $usedMiia = false;
        if ($this->promptGenerator->hasMiiaAvailable()) {
            $hasImages = method_exists($product, 'galleryImages')
                ? count($product->galleryImages()) > 0
                : trim((string) ($product->image_url ?? '')) !== '';
            $result = $this->promptGenerator->generate($store, $product, [
                'video_length' => 28,
                'language' => $language,
                'target_platform' => 'Tiktok',
                'campaign_id' => $campaign->id,
                // MIIA ve fotos reales del producto cuando hay galería.
                'skip_vision' => ! $hasImages,
                'page_scrape' => $scrape,
                'creative_angle' => $creative,
            ]);
            $usedMiia = is_array($result) && ($result['success'] ?? false);
        }

        if (! is_array($result) || ! ($result['success'] ?? false)) {
            Log::info('HyperFrames: MIIA texto no disponible o falló; fallback desde catálogo', [
                'job_id' => $jobId,
                'error' => is_array($result) ? ($result['error'] ?? null) : 'miia_unavailable',
            ]);
            $fallback = $this->pageScrape->buildFallbackBrief($product, $scrape, $language);
            $result = [
                'success' => true,
                'prompt' => $fallback['prompt'],
                'segments' => $fallback['segments'],
                'analysis' => $fallback['analysis'],
            ];
        }

        $miiaAnalysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        // No pisar beats/problem/value de MIIA con el ángulo scrape.
        $analysis = array_merge($miiaAnalysis, [
            'script_style' => $result['prompt']['script_style'] ?? ($miiaAnalysis['script_style'] ?? null),
            'source' => $usedMiia
                ? (string) ($miiaAnalysis['source'] ?? 'miia')
                : (string) ($miiaAnalysis['source'] ?? 'catalog_fallback'),
            'scraped_url' => $scrape['url'] ?? ($miiaAnalysis['scraped_url'] ?? null),
            'scrape' => $scrapeSummary,
            'scrape_angle' => [
                'type' => $creative['type'] ?? null,
                'label' => $creative['label'] ?? null,
                'problem' => $creative['problem'] ?? null,
                'value' => $creative['value'] ?? null,
                'hook' => $creative['hook'] ?? null,
                'beats' => $creative['beats'] ?? [],
            ],
        ]);
        if (empty($analysis['problem'])) {
            $analysis['problem'] = $creative['problem'] ?? null;
        }
        if (empty($analysis['angle_type'])) {
            $analysis['angle_type'] = $creative['type'] ?? null;
            $analysis['angle_label'] = $creative['label'] ?? null;
        }
        if (empty($analysis['value_prop'])) {
            $analysis['value_prop'] = $creative['value'] ?? null;
        }
        if (empty($analysis['beats']) || ! is_array($analysis['beats']) || count($analysis['beats']) < 2) {
            $analysis['beats'] = $creative['beats'] ?? [];
        }

        $cta = trim((string) ($result['prompt']['cta'] ?? data_get($analysis, 'cta', '')));
        if ($cta === '') {
            $cta = 'Compra ahora';
        }
        $analysis['cta'] = $cta;

        $row = MarketingPrompt::create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'name' => $result['prompt']['name'] ?? ('HyperFrames · '.mb_substr($product->localizedName(), 0, 60)),
            'hook' => $result['prompt']['hook'] ?? $creative['hook'],
            'script' => $result['prompt']['script'] ?? '',
            'segments' => $result['segments'] ?? [],
            'analysis' => $analysis,
            'audience' => $result['prompt']['audience'] ?? null,
            'language' => $result['prompt']['language'] ?? $language,
            'style' => $result['prompt']['style'] ?? 'CatalogPopTemplate',
            'target_platform' => $result['prompt']['target_platform'] ?? 'Tiktok',
        ]);

        $campaign->products()->syncWithoutDetaching([$product->id]);
        $this->rememberPromptId($jobId, (int) $row->id);
        $uiCreative = [
            'type' => $analysis['angle_type'] ?? $creative['type'],
            'label' => $analysis['angle_label'] ?? $creative['label'],
            'problem' => $analysis['problem'] ?? $creative['problem'],
            'value' => $analysis['value_prop'] ?? $creative['value'],
            'hook' => $row->hook,
            'beats' => is_array($analysis['beats'] ?? null) ? $analysis['beats'] : ($creative['beats'] ?? []),
        ];
        $this->rememberScrapeSummary($jobId, $scrapeSummary, $uiCreative);
        $this->patchStatusCache(
            $jobId,
            'running',
            '0/8 Brief '.($usedMiia ? 'MIIA' : 'local').' (#'.$row->id.'). Preparando medios…',
            0,
            8
        );
        $this->writeJobStatusFile(
            $jobDir,
            'running',
            '0/8 Brief '.($usedMiia ? 'MIIA' : 'local').' (#'.$row->id.'). Preparando medios…',
            0,
            8
        );

        Log::info('HyperFrames: prompt creativo listo', [
            'job_id' => $jobId,
            'prompt_id' => $row->id,
            'product_id' => $product->id,
            'scraped_url' => $scrape['url'] ?? null,
            'angle' => $analysis['angle_type'] ?? $creative['type'],
            'source' => $analysis['source'] ?? null,
            'used_miia' => $usedMiia,
            'segments' => count($result['segments'] ?? []),
        ]);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>|null  $creative
     */
    protected function rememberScrapeSummary(string $jobId, array $summary, ?array $creative = null): void
    {
        $key = $this->cacheKey($jobId);
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = [];
        }
        $cached['scrape'] = $summary;
        $creativePayload = null;
        if ($creative !== null) {
            $creativePayload = [
                'angle_type' => $creative['type'] ?? null,
                'angle_label' => $creative['label'] ?? null,
                'problem' => $creative['problem'] ?? null,
                'value' => $creative['value'] ?? null,
                'hook' => $creative['hook'] ?? null,
                'beats' => $creative['beats'] ?? [],
            ];
            $cached['creative'] = $creativePayload;
        }
        Cache::put($key, $cached, now()->addHours(8));

        $jobDir = (string) ($cached['job_dir'] ?? $this->clientJobDir($jobId));
        if ($jobDir !== '' && is_dir($jobDir)) {
            $this->writeJobStatusFile(
                $jobDir,
                (string) ($cached['state'] ?? 'running'),
                (string) ($cached['message'] ?? 'Scrape listo'),
                isset($cached['step']) ? (int) $cached['step'] : null,
                isset($cached['steps']) ? (int) $cached['steps'] : null,
                array_filter([
                    'scrape' => $summary,
                    'creative' => $creativePayload,
                ], fn ($v) => $v !== null)
            );
        }
    }

    protected function rememberPromptId(string $jobId, int $promptId): void
    {
        $key = $this->cacheKey($jobId);
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = [];
        }
        $cached['prompt_id'] = $promptId;
        Cache::put($key, $cached, now()->addHours(8));
    }

    protected function pythonBinary(): string
    {
        $root = $this->root();
        $candidates = [
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe',
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python3',
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python',
        ];
        $configured = trim((string) config('multidrop.marketing.hyperframes.python', ''));
        if ($configured !== '' && ! is_dir($configured)) {
            array_unshift($candidates, $configured);
        }
        foreach ($candidates as $bin) {
            if ($bin !== '' && is_file($bin) && ! is_dir($bin)) {
                return $bin;
            }
        }
        foreach (['python3', 'python'] as $name) {
            $resolved = $this->resolveExecutableOnPath($name);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        foreach (['/usr/bin/python3', '/usr/local/bin/python3', '/usr/bin/python'] as $abs) {
            if (is_file($abs) && ! is_dir($abs)) {
                return $abs;
            }
        }

        return 'python3';
    }

    protected function resolveExecutableOnPath(string $name): ?string
    {
        $pathEnv = (string) (getenv('PATH') ?: getenv('Path') ?: '');
        if ($pathEnv === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            $dir = trim($dir);
            if ($dir === '' || $dir === '.' || $dir === './') {
                continue;
            }
            $base = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
            if (is_file($base) && ! is_dir($base)) {
                return $base;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                foreach (['.exe', '.bat', '.cmd'] as $ext) {
                    $cand = $base.$ext;
                    if (is_file($cand) && ! is_dir($cand)) {
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function pythonProcessEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }
        foreach (['SystemRoot', 'SYSTEMROOT', 'windir', 'PATH', 'Path', 'TEMP', 'TMP', 'USERPROFILE', 'ComSpec', 'HOME'] as $key) {
            if (! isset($env[$key]) || $env[$key] === '') {
                $v = getenv($key);
                if ($v !== false && $v !== '') {
                    $env[$key] = $v;
                }
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && empty($env['SystemRoot']) && empty($env['SYSTEMROOT'])) {
            $env['SystemRoot'] = 'C:\\Windows';
            $env['windir'] = $env['windir'] ?? 'C:\\Windows';
        }

        return array_merge($env, [
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONUTF8' => '1',
        ]);
    }

    protected function bootstrapJobDirectory(string $jobDir): void
    {
        foreach (['', 'images', 'videos', 'out', 'project'] as $sub) {
            $path = $sub === '' ? $jobDir : $jobDir.DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new \RuntimeException('No se pudo crear '.$path);
            }
        }
        $this->writeJobStatusFile($jobDir, 'queued', '0/8 Arrancando HyperFrames…', 0, 8);
    }

    protected function prepareJobDirectory(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        ?MarketingPrompt $prompt,
        string $jobDir
    ): void {
        foreach (['', 'images', 'videos', 'out'] as $sub) {
            $path = $sub === '' ? $jobDir : $jobDir.DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new \RuntimeException('No se pudo crear '.$path);
            }
        }

        $videoFiles = $this->downloadProductVideos($store, $product, $jobDir.DIRECTORY_SEPARATOR.'videos');
        $imageManifest = $this->downloadProductImages($product, $jobDir.DIRECTORY_SEPARATOR.'images');
        @file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'media_manifest.json',
            json_encode(['images' => $imageManifest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        $visualStyle = $this->resolveJobVisualStyle($jobDir);
        $styleMeta = (array) config('multidrop.marketing.hyperframes.visual_styles.'.$visualStyle, []);

        $productPayload = [
            'id' => $product->id,
            'name' => $product->localizedName() ?: $product->name,
            'description' => $product->localizedDescription() ?: (string) $product->description,
            'price' => $product->price,
            'compare_at_price' => $product->compare_at_price,
            'currency' => $product->currency ?: $store->currency ?: 'USD',
            'sku' => $product->sku,
            'badge' => $product->badge,
            'image_url' => $product->image_url,
            'details' => method_exists($product, 'details') ? $product->details() : [],
            'rating' => method_exists($product, 'ratingAvg') ? $product->ratingAvg() : null,
            'store_name' => $store->name,
            'store_slug' => $store->slug,
            'product_url' => rtrim((string) $store->publicUrl(), '/').'/pages/'.$product->slug,
            'cta' => trim((string) (
                data_get($prompt?->analysis, 'cta')
                ?: data_get($prompt?->analysis, 'creative_direction.brand.cta')
                ?: 'Compra ahora'
            )) ?: 'Compra ahora',
            'language' => $prompt?->language ?: 'es',
            'visual_style' => $visualStyle,
            'style_label' => (string) ($styleMeta['label'] ?? $visualStyle),
            'has_product_video' => $videoFiles !== [],
            'product_videos' => $videoFiles,
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
            ],
        ];
        file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'product.json',
            json_encode($productPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        if ($prompt) {
            $promptPayload = [
                'id' => $prompt->id,
                'name' => $prompt->name,
                'hook' => $prompt->hook,
                'script' => $prompt->script,
                'segments' => $prompt->segments ?? [],
                'analysis' => $prompt->analysis ?? [],
                'audience' => $prompt->audience,
                'language' => $prompt->language ?: 'es',
                'style' => $prompt->style,
                'script_style' => data_get($prompt->analysis, 'script_style'),
                'target_platform' => $prompt->target_platform,
                'cta' => $productPayload['cta'],
                'creative_direction' => data_get($prompt->analysis, 'creative_direction', []),
                'visual_style' => $visualStyle,
            ];
            file_put_contents(
                $jobDir.DIRECTORY_SEPARATOR.'prompt.json',
                json_encode($promptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
            );
        }

        $this->writeJobStatusFile(
            $jobDir,
            'prepared',
            $videoFiles !== []
                ? '0/8 Medios listos (video de producto incluido); enviando a HyperFrames…'
                : '0/8 Medios listos; enviando a HyperFrames…',
            0,
            8
        );
    }

    /**
     * Si el producto tiene video(s), SIEMPRE se descargan para el job.
     *
     * @return list<string> nombres relativos dentro de videos/ (ej. product_01.mp4)
     */
    /**
     * Estilo visual elegido en el admin (options.json / cache).
     */
    protected function resolveJobVisualStyle(string $jobDir): string
    {
        $default = (string) config('multidrop.marketing.hyperframes.default_visual_style', 'signal');
        $keys = array_keys((array) config('multidrop.marketing.hyperframes.visual_styles', []));
        $fromFile = '';
        $optionsPath = $jobDir.DIRECTORY_SEPARATOR.'options.json';
        if (is_file($optionsPath)) {
            $decoded = json_decode((string) file_get_contents($optionsPath), true);
            if (is_array($decoded)) {
                $fromFile = trim((string) ($decoded['visual_style'] ?? ''));
            }
        }
        $jobId = basename($jobDir);
        $cached = Cache::get($this->cacheKey($jobId));
        $fromCache = is_array($cached) ? trim((string) ($cached['visual_style'] ?? '')) : '';
        $style = $fromFile !== '' ? $fromFile : ($fromCache !== '' ? $fromCache : $default);
        if ($keys !== [] && ! in_array($style, $keys, true)) {
            return $default;
        }

        return $style !== '' ? $style : $default;
    }

    protected function downloadProductVideos(Store $store, Product $product, string $videosDir): array
    {
        if (! is_dir($videosDir) && ! mkdir($videosDir, 0775, true) && ! is_dir($videosDir)) {
            throw new \RuntimeException('No se pudo crear '.$videosDir);
        }

        $entries = $this->media->exportVideoEntries($product);
        if ($entries === []) {
            return [];
        }

        $saved = [];
        $failed = [];
        $index = 0;
        foreach ($entries as $video) {
            if ($index >= 2) {
                break;
            }
            $url = (string) ($video['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $file = $this->media->fetchMediaBytes($store, $product, $url, 'video', $index + 1);
            if (! $file || strlen((string) ($file['body'] ?? '')) < 2048) {
                $failed[] = $url;
                Log::warning('HyperFrames: no se pudo descargar video de producto', [
                    'product_id' => $product->id,
                    'url' => $url,
                ]);

                continue;
            }
            $index++;
            $filename = (string) ($file['filename'] ?? ('product_'.$index.'.mp4'));
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (! in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
                $filename = pathinfo($filename, PATHINFO_FILENAME).'.mp4';
            }
            $safe = sprintf('product_%02d.%s', $index, pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp4');
            file_put_contents($videosDir.DIRECTORY_SEPARATOR.$safe, $file['body']);
            $saved[] = $safe;
        }

        if ($saved === [] && $entries !== []) {
            throw new \RuntimeException(
                'El producto tiene video(s) pero no se pudieron descargar. HyperFrames requiere usar el video del producto.'
            );
        }

        return $saved;
    }

    protected function downloadProductImages(Product $product, string $imagesDir): array
    {
        if (! is_dir($imagesDir) && ! mkdir($imagesDir, 0775, true) && ! is_dir($imagesDir)) {
            throw new \RuntimeException('No se pudo crear '.$imagesDir);
        }

        $urls = [];
        if (is_string($product->image_url) && $product->image_url !== '') {
            $urls[] = $product->image_url;
        }
        foreach ($product->galleryImages() as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        $urls = array_values(array_unique($urls));
        $i = 0;
        $manifest = [];
        foreach ($urls as $url) {
            if ($i >= 4) {
                break;
            }
            try {
                $resp = Http::timeout(30)->withOptions(['allow_redirects' => true])->get($url);
                if (! $resp->ok()) {
                    continue;
                }
                $body = $resp->body();
                if ($body === '' || strlen($body) < 200) {
                    continue;
                }
                $ext = 'jpg';
                $ct = strtolower((string) $resp->header('Content-Type'));
                if (str_contains($ct, 'png')) {
                    $ext = 'png';
                } elseif (str_contains($ct, 'webp')) {
                    $ext = 'webp';
                } elseif (str_contains($ct, 'gif')) {
                    $ext = 'gif';
                } else {
                    $pathExt = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                    if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $ext = $pathExt === 'jpeg' ? 'jpg' : $pathExt;
                    }
                }
                $i++;
                $file = sprintf('product_%02d.%s', $i, $ext);
                file_put_contents($imagesDir.DIRECTORY_SEPARATOR.$file, $body);
                $manifest[] = ['file' => $file, 'url' => $url];
            } catch (\Throwable $e) {
                Log::warning('HyperFrames: no se pudo bajar imagen', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return $manifest;
    }

    protected function renderRemoteJob(string $jobId): string
    {
        $jobDir = $this->clientJobDir($jobId);
        $base = $this->remoteBaseUrl();
        $token = $this->remoteToken();

        $this->patchStatusCache($jobId, 'running', '1/8 Subiendo job a HyperFrames…', 1, 8);
        $this->writeJobStatusFile($jobDir, 'running', '1/8 Subiendo job a HyperFrames…', 1, 8);

        $zipPath = $this->buildJobZip($jobId, $jobDir);

        try {
            $handle = @fopen($zipPath, 'rb');
            if (! is_resource($handle)) {
                throw new \RuntimeException('No se pudo abrir el zip del job HyperFrames.');
            }

            $resp = Http::timeout(300)
                ->withHeaders([
                    'X-HyperFrames-Token' => $token,
                    'Content-Type' => 'application/zip',
                ])
                ->withOptions(['body' => $handle])
                ->send('POST', $base.'/api/render?job='.rawurlencode($jobId));
            @fclose($handle);

            if ($resp->status() !== 202) {
                throw new \RuntimeException('El bridge HyperFrames rechazó el job: '.mb_substr((string) $resp->body(), 0, 600));
            }

            $this->patchStatusCache($jobId, 'running', '1/8 Render remoto HyperFrames en curso…', 1, 8);
            $this->writeJobStatusFile($jobDir, 'running', '1/8 Render remoto HyperFrames en curso…', 1, 8);
            $this->waitRemoteDone($jobId, $base, $token);

            if ($this->isCancelled($jobId)) {
                throw new \RuntimeException('Generación cancelada por el usuario.');
            }

            $outDir = $jobDir.DIRECTORY_SEPARATOR.'out';
            if (! is_dir($outDir) && ! mkdir($outDir, 0775, true) && ! is_dir($outDir)) {
                throw new \RuntimeException('No se pudo crear '.$outDir);
            }
            $mp4 = $outDir.DIRECTORY_SEPARATOR.'final.mp4';
            $this->downloadRemoteMp4($jobId, $base, $token, $mp4);

            return $mp4;
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    protected function buildJobZip(string $jobId, string $jobDir): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Falta la extensión PHP zip.');
        }

        // Usar storage (escribible por www-data). tools/*/transport suele
        // quedar root:root desde el CLI y ZipArchive falla al cerrar.
        $transport = storage_path('app/hyperframes-transport');
        if (! is_dir($transport) && ! mkdir($transport, 0775, true) && ! is_dir($transport)) {
            throw new \RuntimeException('No se pudo crear '.$transport);
        }
        @chmod($transport, 0775);

        $zipPath = $transport.DIRECTORY_SEPARATOR.'job-'.$jobId.'.zip';
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('No se pudo crear el zip del job (código '.$opened.').');
        }

        $add = function (string $abs, string $entry) use ($zip): void {
            if (is_file($abs) && filesize($abs) > 0) {
                $zip->addFile($abs, $entry);
            }
        };

        $add($jobDir.DIRECTORY_SEPARATOR.'product.json', 'product.json');
        $add($jobDir.DIRECTORY_SEPARATOR.'prompt.json', 'prompt.json');
        $add($jobDir.DIRECTORY_SEPARATOR.'options.json', 'options.json');
        $add($jobDir.DIRECTORY_SEPARATOR.'video_plan.json', 'video_plan.json');
        $images = $jobDir.DIRECTORY_SEPARATOR.'images';
        if (is_dir($images)) {
            foreach (scandir($images) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $add($images.DIRECTORY_SEPARATOR.$name, 'images/'.$name);
            }
        }
        $videos = $jobDir.DIRECTORY_SEPARATOR.'videos';
        if (is_dir($videos)) {
            foreach (scandir($videos) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $add($videos.DIRECTORY_SEPARATOR.$name, 'videos/'.$name);
            }
        }

        if (! @$zip->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('ZipArchive::close falló (permiso o disco). Se usa storage/app/hyperframes-transport.');
        }

        if (! is_file($zipPath) || filesize($zipPath) < 10) {
            throw new \RuntimeException('El zip del job HyperFrames quedó vacío.');
        }

        return $zipPath;
    }

    protected function waitRemoteDone(string $jobId, string $base, string $token): void
    {
        $deadline = time() + $this->timeoutSeconds();
        $lastMessage = '';

        while (true) {
            if ($this->isCancelled($jobId)) {
                $this->requestRemoteCancel($jobId, $base, $token);
                throw new \RuntimeException('Generación cancelada por el usuario.');
            }

            try {
                $resp = Http::timeout(15)
                    ->withHeaders(['X-HyperFrames-Token' => $token])
                    ->get($base.'/api/jobs/'.$jobId.'/status');
            } catch (\Throwable $e) {
                if (time() >= $deadline) {
                    throw new \RuntimeException('Timeout esperando HyperFrames remoto: '.$e->getMessage());
                }
                sleep(2);

                continue;
            }

            $state = 'unknown';
            if ($resp->ok()) {
                $data = $resp->json();
                if (is_array($data)) {
                    $state = (string) ($data['state'] ?? 'unknown');
                    $lastMessage = trim((string) ($data['message'] ?? ''));
                    $step = isset($data['step']) ? (int) $data['step'] : null;
                    $steps = isset($data['steps']) ? (int) $data['steps'] : 8;
                    if ($lastMessage !== '' && ! $this->isCancelled($jobId)) {
                        $this->patchStatusCache($jobId, 'running', $lastMessage, $step, $steps);
                        $jobDir = $this->clientJobDir($jobId);
                        $this->writeJobStatusFile($jobDir, 'running', $lastMessage, $step, $steps);
                    }
                }
            }

            if ($state === 'done') {
                return;
            }
            if ($state === 'cancelled') {
                throw new \RuntimeException('Generación cancelada por el usuario.');
            }
            if ($state === 'failed') {
                throw new \RuntimeException($lastMessage !== '' ? $lastMessage : 'El pipeline HyperFrames remoto falló.');
            }
            if (time() >= $deadline) {
                throw new \RuntimeException('Timeout esperando el render HyperFrames (job '.$jobId.').');
            }
            sleep(2);
        }
    }

    public function isCancelled(string $jobId): bool
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return false;
        }

        return ! empty($cached['cancelled']) || ($cached['state'] ?? '') === 'cancelled';
    }

    /**
     * Marca el job como cancelado y pide al bridge que detenga el render.
     *
     * @return array{ok: bool, message: string}
     */
    public function cancel(string $jobId, int $storeId): array
    {
        $cached = Cache::get($this->cacheKey($jobId));
        if (! is_array($cached)) {
            return ['ok' => false, 'message' => 'Job no encontrado'];
        }
        if ((int) ($cached['store_id'] ?? 0) !== $storeId) {
            return ['ok' => false, 'message' => 'Job de otra tienda'];
        }

        $state = (string) ($cached['state'] ?? '');
        if (in_array($state, ['done', 'failed', 'cancelled'], true)) {
            return ['ok' => true, 'message' => 'El job ya terminó ('.$state.').'];
        }

        $cached['cancelled'] = true;
        $cached['state'] = 'cancelled';
        $cached['message'] = 'Cancelado por el usuario';
        $cached['error'] = 'Cancelado por el usuario';
        Cache::put($this->cacheKey($jobId), $cached, now()->addHours(8));

        $jobDir = (string) ($cached['job_dir'] ?? $this->clientJobDir($jobId));
        $this->writeJobStatusFile($jobDir, 'cancelled', 'Cancelado por el usuario', null, 8);
        @file_put_contents($jobDir.DIRECTORY_SEPARATOR.'cancel.flag', '1');

        if ($this->isRemote()) {
            $this->requestRemoteCancel($jobId, $this->remoteBaseUrl(), $this->remoteToken());
        }

        return ['ok' => true, 'message' => 'Generación detenida'];
    }

    protected function requestRemoteCancel(string $jobId, string $base, string $token): void
    {
        if ($base === '' || $token === '') {
            return;
        }
        try {
            Http::timeout(10)
                ->withHeaders(['X-HyperFrames-Token' => $token])
                ->post($base.'/api/jobs/'.$jobId.'/cancel');
        } catch (\Throwable $e) {
            Log::warning('HyperFrames: no se pudo cancelar en el bridge', [
                'job' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function downloadRemoteMp4(string $jobId, string $base, string $token, string $dest): void
    {
        $resp = Http::timeout(600)
            ->withHeaders(['X-HyperFrames-Token' => $token])
            ->withOptions(['stream' => true])
            ->get($base.'/api/jobs/'.$jobId.'/out/final.mp4');

        if (! $resp->ok()) {
            throw new \RuntimeException('El bridge HyperFrames no entregó final.mp4: '.mb_substr((string) $resp->body(), 0, 400));
        }

        $body = $resp->toPsrResponse()->getBody();
        $fh = @fopen($dest, 'wb');
        if (! is_resource($fh)) {
            throw new \RuntimeException('No se pudo escribir '.$dest);
        }
        try {
            while (! $body->eof()) {
                $chunk = $body->read(1048576);
                if ($chunk === '' || $chunk === false) {
                    if ($body->eof()) {
                        break;
                    }
                    continue;
                }
                fwrite($fh, $chunk);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchRemoteStatus(string $jobId): ?array
    {
        try {
            $key = 'hyperframes_ads:remote_status:'.$jobId;

            return Cache::remember($key, 2, function () use ($jobId) {
                $resp = Http::timeout(10)
                    ->withHeaders(['X-HyperFrames-Token' => $this->remoteToken()])
                    ->get($this->remoteBaseUrl().'/api/jobs/'.$jobId.'/status');
                if (! $resp->ok()) {
                    return null;
                }
                $data = $resp->json();

                return is_array($data) ? $data : null;
            });
        } catch (\Throwable $e) {
            Log::warning('HyperFrames: no se pudo consultar status remoto', [
                'job' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function spawnBackgroundProcess(
        string $jobId,
        int $storeId,
        int $campaignId,
        int $productId,
        ?int $promptId,
        bool $resume = false
    ): void {
        $php = $this->phpCliBinary();
        $artisan = base_path('artisan');
        $logDir = storage_path('logs');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir.DIRECTORY_SEPARATOR.'hyperframes-'.$jobId.'.log';

        $args = [
            $php,
            $artisan,
            'marketing:hyperframes-process',
            $jobId,
            '--store='.$storeId,
            '--campaign='.$campaignId,
            '--product='.$productId,
        ];
        if ($promptId) {
            $args[] = '--prompt='.$promptId;
        }
        if ($resume) {
            $args[] = '--resume';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" '
                .implode(' ', array_map([$this, 'winEscape'], $args))
                .' >> '.escapeshellarg($logFile).' 2>&1';
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = implode(' ', array_map('escapeshellarg', $args))
                .' >> '.escapeshellarg($logFile).' 2>&1 &';
            exec($cmd);
        }
    }

    /**
     * En FPM/Docker, PHP_BINARY suele ser php-fpm (no sirve para artisan).
     */
    protected function phpCliBinary(): string
    {
        $configured = trim((string) env('PHP_CLI_BINARY', ''));
        if ($configured !== '' && (is_file($configured) || $configured === 'php')) {
            return $configured;
        }

        $bin = (string) (PHP_BINARY ?: '');
        if ($bin !== '' && ! preg_match('/php-fpm|php-cgi/i', $bin) && is_file($bin)) {
            return $bin;
        }

        foreach (['/usr/local/bin/php', '/usr/bin/php', 'php'] as $candidate) {
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    protected function winEscape(string $value): string
    {
        if ($value === '' || preg_match('/[\s"]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    protected function cleanupWorkspace(string $jobId): void
    {
        $jobDir = $this->clientJobDir($jobId);
        if (! is_dir($jobDir)) {
            return;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($jobDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($jobDir);
        } catch (\Throwable $e) {
            Log::warning('HyperFrames: no se pudo limpiar workspace', [
                'job' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function patchStatusCache(
        string $jobId,
        string $state,
        string $message,
        ?int $step = null,
        ?int $steps = null
    ): void {
        $key = $this->cacheKey($jobId);
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = [];
        }
        $cached['state'] = $state;
        $cached['message'] = $message;
        if ($step !== null) {
            $cached['step'] = $step;
        }
        if ($steps !== null) {
            $cached['steps'] = $steps;
        }
        Cache::put($key, $cached, now()->addHours(8));
    }

    protected function writeJobStatusFile(
        string $jobDir,
        string $state,
        string $message,
        ?int $step = null,
        ?int $steps = null,
        ?array $extra = null
    ): void {
        if ($jobDir === '' || ! is_dir($jobDir)) {
            return;
        }
        $payload = [
            'state' => $state,
            'message' => $message,
        ];
        if ($step !== null) {
            $payload['step'] = $step;
        }
        if ($steps !== null) {
            $payload['steps'] = $steps;
        }
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (in_array($k, ['scrape', 'creative', 'prompt_id'], true)) {
                    $payload[$k] = $v;
                }
            }
        }
        // Conservar scrape/creative previos si el archivo ya los tenía.
        $existingPath = $jobDir.DIRECTORY_SEPARATOR.'status.json';
        if (is_file($existingPath)) {
            $prev = json_decode((string) file_get_contents($existingPath), true);
            if (is_array($prev)) {
                foreach (['scrape', 'creative', 'prompt_id'] as $keep) {
                    if (! array_key_exists($keep, $payload) && array_key_exists($keep, $prev)) {
                        $payload[$keep] = $prev[$keep];
                    }
                }
            }
        }
        @file_put_contents(
            $existingPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }
}
