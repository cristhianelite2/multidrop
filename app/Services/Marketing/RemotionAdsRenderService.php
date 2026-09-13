<?php

namespace App\Services\Marketing;

use App\Domain\AI\RemotionEditPlanService;
use App\Models\MarketingCampaign;
use App\Models\MarketingPrompt;
use App\Models\MarketingVideo;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class RemotionAdsRenderService
{
    public function __construct(
        protected ProductMarketingMediaService $media,
        protected PromptExportZipService $zipExport,
        protected VideoIngestService $ingest,
        protected RemotionEditPlanService $editPlan,
    ) {}

    public function root(): string
    {
        return rtrim((string) config('multidrop.marketing.remotion.root', base_path('tools/remotion-ads')), DIRECTORY_SEPARATOR);
    }

    public function pythonBinary(): string
    {
        $root = $this->root();
        $venvWin = $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe';
        $venvUnix = $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python';
        if (is_file($venvWin)) {
            return $venvWin;
        }
        if (is_file($venvUnix)) {
            return $venvUnix;
        }
        $bin = trim((string) config('multidrop.marketing.remotion.python', 'python'));

        return $bin !== '' ? $bin : 'python';
    }

    public function timeoutSeconds(): int
    {
        return max(120, (int) config('multidrop.marketing.remotion.timeout_seconds', 1800));
    }

    /**
     * Entorno para subprocess Python: hereda el del PHP y sobrescribe claves.
     *
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
        // $_ENV a veces viene vacío según variables_order; completar con getenv.
        foreach (['SystemRoot', 'SYSTEMROOT', 'windir', 'WINDIR', 'SystemDrive', 'PATH', 'Path', 'PATHEXT', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'ComSpec', 'HOME', 'USERNAME', 'NUMBER_OF_PROCESSORS'] as $key) {
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
            'HF_HUB_DISABLE_SYMLINKS_WARNING' => '1',
        ]);
    }

    public function configured(): bool
    {
        $root = $this->root();

        return is_dir($root)
            && is_file($root.DIRECTORY_SEPARATOR.'package.json')
            && is_file($root.DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'run_pipeline.py');
    }

    public function cacheKey(string $jobId): string
    {
        return 'remotion_ads:'.$jobId;
    }

    /**
     * @return array{ok: bool, job_id?: string, message?: string}
     */
    public function enqueue(
        Store $store,
        MarketingCampaign $campaign,
        MarketingPrompt $prompt,
        ?UploadedFile $voice = null,
        string $preset = 'product_presenter',
        bool $sync = false
    ): array {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Remotion Ads no está instalado (tools/remotion-ads).'];
        }

        if (! in_array($preset, ['product_presenter', 'quick_transition'], true)) {
            $preset = (string) config('multidrop.marketing.remotion.default_preset', 'product_presenter');
        }

        $jobId = (string) Str::uuid();
        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;

        try {
            // Solo carpeta + voz: la descarga de medios va en el worker para no bloquear HTTP.
            $this->bootstrapJobDirectory($jobDir, $voice);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        Cache::put($this->cacheKey($jobId), [
            'state' => 'queued',
            'message' => '0/6 Arrancando…',
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'prompt_id' => $prompt->id,
            'preset' => $preset,
            'job_dir' => $jobDir,
            'video_id' => null,
            'error' => null,
            'step' => 0,
            'steps' => 6,
        ], now()->addHours(6));
        $this->writeJobStatusFile($jobDir, 'queued', '0/6 Arrancando…', 0, 6);

        if ($sync) {
            // artisan serve es single-thread: afterResponse bloquearía el poll.
            // Lanzamos un PHP aparte para que el UI pueda consultar el progreso.
            $this->spawnBackgroundProcess($jobId, $store->id, $campaign->id, $prompt->id, $preset);
        } else {
            \App\Jobs\RenderRemotionAdJob::dispatch(
                $store->id,
                $campaign->id,
                $prompt->id,
                $jobId,
                $preset
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
                $cached['pipeline_state'] = $diskState !== '' ? $diskState : null;
                $cached['pipeline_message'] = $diskMsg !== '' ? $diskMsg : null;
                if (isset($disk['step'])) {
                    $cached['step'] = (int) $disk['step'];
                }
                if (isset($disk['steps'])) {
                    $cached['steps'] = (int) $disk['steps'];
                }

                $cacheState = (string) ($cached['state'] ?? '');
                // Mientras Laravel no cerró el job, el progreso real vive en status.json
                if (! in_array($cacheState, ['done', 'failed'], true)) {
                    if ($diskMsg !== '') {
                        $cached['message'] = $diskMsg;
                    }
                    if ($diskState === 'running' || $diskState === 'prepared' || $diskState === 'props_ready') {
                        $cached['state'] = 'running';
                    }
                    // Python marca "done" al terminar el MP4; el ingest aún puede estar en curso.
                    if ($diskState === 'done') {
                        $cached['state'] = 'running';
                        if ($diskMsg === '' || ! str_contains(mb_strtolower($diskMsg), 'import')) {
                            $cached['message'] = $diskMsg !== ''
                                ? $diskMsg
                                : '6/6 Importando video a la campaña…';
                        }
                    }
                    if ($diskState === 'failed') {
                        $cached['state'] = 'failed';
                        $cached['error'] = $diskMsg !== '' ? $diskMsg : ($cached['error'] ?? 'Pipeline falló');
                        $cached['message'] = $cached['error'];
                    }
                }
            }
        }

        return $cached;
    }

    public function runAndIngest(
        Store $store,
        MarketingCampaign $campaign,
        MarketingPrompt $prompt,
        string $jobId,
        string $preset
    ): MarketingVideo {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;
        if (! is_dir($jobDir)) {
            throw new \RuntimeException('Carpeta del job Remotion no existe.');
        }

        $this->patchStatusCache($jobId, 'running', '0/6 Descargando imágenes y video del producto…', 0, 6);
        $this->writeJobStatusFile($jobDir, 'running', '0/6 Descargando imágenes y video del producto…', 0, 6);
        $this->prepareJobDirectory($store, $prompt, $jobDir, null);

        $this->patchStatusCache($jobId, 'running', '1/6 Iniciando pipeline Python…', 1, 6);
        $this->writeJobStatusFile($jobDir, 'running', '1/6 Iniciando pipeline Python…', 1, 6);

        $python = $this->pythonBinary();
        $script = $this->root().DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'run_pipeline.py';
        // Process::env() reemplaza el entorno completo. En Windows hace falta
        // SystemRoot/windir o asyncio falla con WinError 10106 al cargar Winsock.
        $result = Process::timeout($this->timeoutSeconds())
            ->path($this->root())
            ->env($this->pythonProcessEnv())
            ->run([
                $python,
                $script,
                $jobDir,
                '--preset='.$preset,
            ]);

        $mp4 = $jobDir.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'final.mp4';
        if ((! $result->successful()) && (! is_file($mp4) || filesize($mp4) < 1024)) {
            $err = trim($result->errorOutput() ?: $result->output());
            // Evitar volcar warnings enormes de huggingface
            $err = preg_replace('/\s+/', ' ', $err) ?? $err;
            Log::error('Remotion pipeline failed', [
                'job' => $jobId,
                'error' => mb_substr($err, -1500),
            ]);
            $failMsg = mb_substr($err, -800) !== '' ? mb_substr($err, -800) : 'El pipeline Remotion falló.';
            $this->writeJobStatusFile($jobDir, 'failed', $failMsg, null, 6);
            throw new \RuntimeException($failMsg);
        }

        if (! is_file($mp4) || filesize($mp4) < 1024) {
            $this->writeJobStatusFile($jobDir, 'failed', 'No se generó out/final.mp4', null, 6);
            throw new \RuntimeException('No se generó out/final.mp4');
        }

        $this->patchStatusCache($jobId, 'running', '6/6 Importando video a la campaña…', 6, 6);
        $this->writeJobStatusFile($jobDir, 'running', '6/6 Importando video a la campaña…', 6, 6);

        return $this->ingest->ingestFromLocalPath(
            $store,
            $campaign,
            $mp4,
            $prompt,
            'remotion',
            $jobId
        );
    }

    public function failJob(string $jobId, string $message): void
    {
        $this->patchStatusCache($jobId, 'failed', $message);
        $cached = Cache::get($this->cacheKey($jobId));
        $jobDir = is_array($cached) ? (string) ($cached['job_dir'] ?? '') : '';
        if ($jobDir !== '') {
            $this->writeJobStatusFile($jobDir, 'failed', $message);
        }
    }

    public function writePublicJobStatus(
        string $jobDir,
        string $state,
        string $message,
        ?int $step = null,
        ?int $steps = null
    ): void {
        $this->writeJobStatusFile($jobDir, $state, $message, $step, $steps);
    }

    /**
     * Crea carpetas del job y guarda voice.mp3 si viene en el upload (rápido).
     */
    protected function bootstrapJobDirectory(string $jobDir, ?UploadedFile $voice): void
    {
        foreach (['', 'images', 'videos', 'trabajo', 'out'] as $sub) {
            $path = $sub === '' ? $jobDir : $jobDir.DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new \RuntimeException('No se pudo crear '.$path);
            }
        }

        if ($voice instanceof UploadedFile && $voice->isValid()) {
            $voice->move($jobDir, 'voice.mp3');
        }

        $this->writeJobStatusFile($jobDir, 'queued', '0/6 Arrancando…', 0, 6);
    }

    /**
     * Lanza marketing:remotion-process en background (Windows/Unix).
     */
    protected function spawnBackgroundProcess(
        string $jobId,
        int $storeId,
        int $campaignId,
        int $promptId,
        string $preset
    ): void {
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $logDir = storage_path('logs');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir.DIRECTORY_SEPARATOR.'remotion-'.$jobId.'.log';

        $args = [
            $php,
            $artisan,
            'marketing:remotion-process',
            $jobId,
            '--store='.$storeId,
            '--campaign='.$campaignId,
            '--prompt='.$promptId,
            '--preset='.$preset,
        ];

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

        Log::info('Remotion background process spawned', [
            'job_id' => $jobId,
            'log' => $logFile,
        ]);
    }

    protected function winEscape(string $value): string
    {
        if ($value === '' || preg_match('/[\s"]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Usado por artisan marketing:remotion-edit-plan.
     */
    public function writeEditPlanFromMiia(string $jobDir, string $preset = 'product_presenter'): array
    {
        $jobDir = rtrim($jobDir, DIRECTORY_SEPARATOR);
        $promptPath = $jobDir.DIRECTORY_SEPARATOR.'prompt.json';
        $transPath = $jobDir.DIRECTORY_SEPARATOR.'trabajo'.DIRECTORY_SEPARATOR.'transcripcion.json';
        if (! is_file($promptPath) || ! is_file($transPath)) {
            throw new \RuntimeException('Faltan prompt.json o trabajo/transcripcion.json');
        }

        $prompt = json_decode((string) file_get_contents($promptPath), true);
        $transcript = json_decode((string) file_get_contents($transPath), true);
        if (! is_array($prompt) || ! is_array($transcript)) {
            throw new \RuntimeException('JSON inválido en el job.');
        }

        $media = $this->scanMedia($jobDir);
        $result = $this->editPlan->generate($prompt, $media, $transcript, $preset);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException((string) ($result['error'] ?? 'MIIA falló'));
        }

        $plan = $result['plan'];
        $outDir = $jobDir.DIRECTORY_SEPARATOR.'trabajo';
        if (! is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        file_put_contents(
            $outDir.DIRECTORY_SEPARATOR.'edit_plan.json',
            json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        return $plan;
    }

    /**
     * @return list<array{path: string, rel: string, media_type: string, name: string}>
     */
    public function scanMedia(string $jobDir): array
    {
        $items = [];
        foreach (['images' => 'image', 'videos' => 'video'] as $folder => $type) {
            $base = $jobDir.DIRECTORY_SEPARATOR.$folder;
            if (! is_dir($base)) {
                continue;
            }
            $files = scandir($base) ?: [];
            sort($files);
            foreach ($files as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $base.DIRECTORY_SEPARATOR.$name;
                if (! is_file($path)) {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $ok = $type === 'image'
                    ? in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
                    : in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true);
                if (! $ok) {
                    continue;
                }
                $items[] = [
                    'path' => $path,
                    'rel' => $folder.'/'.$name,
                    'media_type' => $type,
                    'name' => $name,
                ];
            }
        }

        return $items;
    }

    protected function prepareJobDirectory(
        Store $store,
        MarketingPrompt $prompt,
        string $jobDir,
        ?UploadedFile $voice
    ): void {
        foreach (['', 'images', 'videos', 'trabajo', 'out'] as $sub) {
            $path = $sub === '' ? $jobDir : $jobDir.DIRECTORY_SEPARATOR.$sub;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
                throw new \RuntimeException('No se pudo crear '.$path);
            }
        }

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
            'target_platform' => $prompt->target_platform,
        ];
        file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'prompt.json',
            json_encode($promptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
        $this->writeJobStatusFile($jobDir, 'prepared', '0/6 Job preparado; esperando pipeline…', 0, 6);

        $this->exportMediaToJob($store, $prompt, $jobDir);

        if ($voice instanceof UploadedFile && $voice->isValid()) {
            $dest = $jobDir.DIRECTORY_SEPARATOR.'voice.mp3';
            if (! is_file($dest)) {
                $voice->move($jobDir, 'voice.mp3');
            }
        }

        $media = $this->scanMedia($jobDir);
        if ($media === []) {
            throw new \RuntimeException('El prompt no tiene imágenes/videos de producto exportables.');
        }
    }

    protected function exportMediaToJob(Store $store, MarketingPrompt $prompt, string $jobDir): void
    {
        $ids = $prompt->linkedProductIds();
        if ($ids === []) {
            return;
        }

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $ids)
            ->with('variants')
            ->orderBy('id')
            ->get();

        $imgIndex = 0;
        $vidIndex = 0;
        $expectedVideos = 0;
        $failedVideos = [];
        $maxImages = 24;

        foreach ($products as $product) {
            if ($imgIndex < $maxImages) {
                foreach ($this->media->exportImageUrls($product) as $rawUrl) {
                    if ($imgIndex >= $maxImages) {
                        break;
                    }
                    $file = $this->media->fetchMediaBytes($store, $product, $rawUrl, 'image', $imgIndex + 1);
                    if (! $file) {
                        continue;
                    }
                    $imgIndex++;
                    $name = sprintf('%03d-%s', $imgIndex, $this->safeName($file['filename'], 'img-'.$imgIndex.'.jpg'));
                    file_put_contents($jobDir.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.$name, $file['body']);
                }
            }

            foreach ($this->media->exportVideoEntries($product) as $video) {
                $expectedVideos++;
                if ($vidIndex >= 4) {
                    break;
                }
                $file = $this->media->fetchMediaBytes($store, $product, $video['url'], 'video', $vidIndex + 1);
                if (! $file || strlen((string) ($file['body'] ?? '')) < 2048) {
                    $failedVideos[] = $video['url'];
                    Log::warning('Remotion: no se pudo descargar video de producto', [
                        'product_id' => $product->id,
                        'url' => $video['url'],
                    ]);

                    continue;
                }
                $vidIndex++;
                $filename = $this->safeName($file['filename'], 'vid-'.$vidIndex.'.mp4');
                if (! str_contains(strtolower($filename), '.mp4')) {
                    $filename .= '.mp4';
                }
                $name = sprintf('%03d-%s', $vidIndex, $filename);
                $dest = $jobDir.DIRECTORY_SEPARATOR.'videos'.DIRECTORY_SEPARATOR.$name;
                file_put_contents($dest, $file['body']);
                $this->normalizeProductVideoForRemotion($dest);
            }
        }

        if ($expectedVideos > 0 && $vidIndex === 0) {
            throw new \RuntimeException(
                'El producto tiene video(s) pero no se pudieron descargar (AliExpress). URLs: '
                .implode(', ', array_slice($failedVideos, 0, 3))
            );
        }
    }

    protected function safeName(string $filename, string $fallback): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: $fallback;
        $safe = trim($safe, '-.');

        return $safe !== '' ? $safe : $fallback;
    }

    /**
     * Re-encode a H.264 yuv420p para que Remotion OffthreadVideo no renderice negro.
     */
    protected function normalizeProductVideoForRemotion(string $path): void
    {
        if (! is_file($path) || filesize($path) < 2048) {
            return;
        }
        if (! $this->ingest->ffmpegAvailable()) {
            return;
        }
        $bin = $this->ingest->ffmpegBinary();
        $tmp = $path.'.norm.mp4';
        $result = Process::timeout(180)
            ->env($this->pythonProcessEnv())
            ->run([
                $bin,
                '-y',
                '-i', $path,
                '-an',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-preset', 'veryfast',
                '-crf', '23',
                '-movflags', '+faststart',
                $tmp,
            ]);
        if ($result->successful() && is_file($tmp) && filesize($tmp) > 2048) {
            @unlink($path);
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
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
        Cache::put($key, $cached, now()->addHours(6));
    }

    protected function writeJobStatusFile(
        string $jobDir,
        string $state,
        string $message,
        ?int $step = null,
        ?int $steps = null
    ): void {
        $payload = [
            'state' => $state,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ];
        if ($step !== null) {
            $payload['step'] = $step;
        }
        if ($steps !== null) {
            $payload['steps'] = $steps;
        }
        @file_put_contents(
            rtrim($jobDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'status.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }
}
