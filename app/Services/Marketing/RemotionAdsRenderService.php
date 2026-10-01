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
use Illuminate\Support\Facades\Http;
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
        protected RemotionStyleCatalog $styles,
    ) {}

    public function root(): string
    {
        return rtrim((string) config('multidrop.marketing.remotion.root', base_path('tools/remotion-ads')), DIRECTORY_SEPARATOR);
    }

    public function pythonBinary(): string
    {
        $root = $this->root();
        $candidates = [
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'Scripts'.DIRECTORY_SEPARATOR.'python.exe',
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python3',
            $root.DIRECTORY_SEPARATOR.'.venv'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'python',
        ];
        $configured = trim((string) config('multidrop.marketing.remotion.python', ''));
        // Nunca aceptar un directorio como binario (p. ej. tools/remotion-ads/python/).
        // Con cwd=tools/remotion-ads, `exec python` resolvía al directorio y fallaba
        // con "exec: python: cannot execute: Is a directory" en producción.
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

    public function timeoutSeconds(): int
    {
        return max(120, (int) config('multidrop.marketing.remotion.timeout_seconds', 1800));
    }

    public function mode(): string
    {
        return (string) config('multidrop.marketing.remotion.mode', 'local');
    }

    public function isRemote(): bool
    {
        return $this->mode() === 'remote' && $this->remoteBaseUrl() !== '' && $this->remoteToken() !== '';
    }

    public function remoteBaseUrl(): string
    {
        return rtrim((string) config('multidrop.marketing.remotion.remote_url', ''), '/\\');
    }

    public function remoteToken(): string
    {
        return trim((string) config('multidrop.marketing.remotion.remote_token', ''));
    }

    /**
     * Entorno para subprocess Python: hereda el del PHP y sobrescribe claves.
     *
     * @return array<string, string>
     */
    protected function pythonProcessEnv(): array
    {
        $hfCacheDir = storage_path('app/cache/huggingface');
        if (! is_dir($hfCacheDir)) {
            @mkdir($hfCacheDir, 0775, true);
        }

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
            // PHP-FPM corre como www-data y no puede crear /var/www/.cache.
            // Mantener los modelos descargados en storage, que sí pertenece a Laravel.
            'HF_HOME' => $hfCacheDir,
            'HF_HUB_CACHE' => $hfCacheDir.DIRECTORY_SEPARATOR.'hub',
            'XDG_CACHE_HOME' => storage_path('app/cache'),
        ]);
    }

    public function configured(): bool
    {
        if ($this->mode() !== 'local') {
            // mode=remote (o desconocido) se considera instalado si hay bridge configurado;
            // el pipeline corre en la máquina remota que tiene tools/remotion-ads.
            return $this->remoteBaseUrl() !== '' && $this->remoteToken() !== '';
        }

        $root = $this->root();

        return is_dir($root)
            && is_file($root.DIRECTORY_SEPARATOR.'package.json')
            && is_file($root.DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'run_pipeline.py');
    }

    public function cacheKey(string $jobId): string
    {
        return 'remotion_ads:'.$jobId;
    }

    public function activeProductKey(int $storeId, int $campaignId, int $productId): string
    {
        return "remotion_ads:active:{$storeId}:{$campaignId}:{$productId}";
    }

    /** @return array{job_id?: string, state: string, message?: string, style_label?: string} */
    public function activeJob(int $storeId, int $campaignId, int $productId): array
    {
        $activeKey = $this->activeProductKey($storeId, $campaignId, $productId);
        $jobId = (string) Cache::get($activeKey, '');
        if ($jobId === '') {
            return ['state' => 'idle'];
        }
        if ($jobId === 'starting') {
            return ['state' => 'starting', 'message' => 'Iniciando generación…'];
        }

        $status = $this->status($jobId);
        $state = (string) ($status['state'] ?? 'unknown');
        if (! in_array($state, ['queued', 'running', 'prepared', 'props_ready'], true)) {
            Cache::forget($activeKey);

            return ['state' => 'idle'];
        }

        $label = trim((string) ($status['style_label'] ?? ''));

        return [
            'job_id' => $jobId,
            'state' => $state,
            'message' => (string) ($status['message'] ?? 'Generación en curso…'),
            'style_label' => $label !== '' ? $label : null,
        ];
    }

    /**
     * @return array{ok: bool, job_id?: string, message?: string}
     */
    public function enqueue(
        Store $store,
        MarketingCampaign $campaign,
        MarketingPrompt $prompt,
        ?UploadedFile $voice = null,
        string $preset = 'random',
        bool $sync = false,
        ?int $productId = null,
        string $musicId = 'random',
        float $musicVolume = 0.3
    ): array {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Remotion Ads no está instalado (tools/remotion-ads).'];
        }

        // "random" (o un preset desconocido) se resuelve a un estilo concreto
        // del catálogo para que todo el pipeline use el mismo durante el job.
        $estilo = $this->styles->resolve($preset);
        $preset = $estilo['id'];

        $productId = $productId ?: (int) $prompt->product_id;
        if ($productId < 1 || (int) $prompt->product_id !== $productId || ! $campaign->products()->whereKey($productId)->exists()) {
            return ['ok' => false, 'message' => 'El producto no pertenece al prompt y campaña seleccionados.'];
        }

        $activeKey = $this->activeProductKey((int) $store->id, (int) $campaign->id, $productId);
        if (! Cache::add($activeKey, 'starting', now()->addHours(6))) {
            $activeJobId = (string) Cache::get($activeKey, '');
            $activeStatus = $activeJobId !== '' && $activeJobId !== 'starting' ? $this->status($activeJobId) : [];
            if ($activeJobId !== '' && in_array($activeStatus['state'] ?? '', ['queued', 'running', 'prepared', 'props_ready'], true)) {
                return ['ok' => true, 'job_id' => $activeJobId, 'existing' => true];
            }
            if ($activeJobId === 'starting') {
                return ['ok' => false, 'message' => 'Ya se está iniciando una generación para este producto.'];
            }
            Cache::forget($activeKey);
            if (! Cache::add($activeKey, 'starting', now()->addHours(6))) {
                return ['ok' => false, 'message' => 'Ya se está iniciando una generación para este producto.'];
            }
        }

        $jobId = (string) Str::uuid();
        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;

        try {
            // Solo carpeta + voz: la descarga de medios va en el worker para no bloquear HTTP.
            $this->bootstrapJobDirectory($jobDir, $voice);
            file_put_contents($jobDir.DIRECTORY_SEPARATOR.'music.json', json_encode([
                'music_id' => $musicId,
                'music_volume' => max(0.0, min(0.6, $musicVolume)),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            Cache::forget($activeKey);
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        Cache::put($this->cacheKey($jobId), [
            'state' => 'queued',
            'message' => '0/6 Arrancando… · Estilo: '.$estilo['label'],
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'prompt_id' => $prompt->id,
            'product_id' => $productId,
            'preset' => $preset,
            'style_id' => $estilo['id'],
            'style_label' => $estilo['label'],
            'job_dir' => $jobDir,
            'video_id' => null,
            'error' => null,
            'step' => 0,
            'steps' => 6,
        ], now()->addHours(6));
        $this->writeJobStatusFile($jobDir, 'queued', '0/6 Arrancando… · Estilo: '.$estilo['label'], 0, 6);
        Cache::put($activeKey, $jobId, now()->addHours(6));

        try {
            if ($sync) {
                // artisan serve es single-thread: afterResponse bloquearía el poll.
                // Lanzamos un PHP aparte para que el UI pueda consultar el progreso.
                $this->spawnBackgroundProcess($jobId, $store->id, $campaign->id, $prompt->id, $preset, $productId);
            } else {
                \App\Jobs\RenderRemotionAdJob::dispatch(
                    $store->id,
                    $campaign->id,
                    $prompt->id,
                    $jobId,
                    $preset,
                    $productId
                );
            }
        } catch (\Throwable $e) {
            Cache::forget($activeKey);
            Cache::forget($this->cacheKey($jobId));
            return ['ok' => false, 'message' => 'No se pudo iniciar el render Remotion: '.$e->getMessage()];
        }

        return ['ok' => true, 'job_id' => $jobId, 'style_id' => $estilo['id'], 'style_label' => $estilo['label']];
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

        // En mode=remote el progreso real vive en el bridge (job remoto). Lo
        // fusionamos mientras el job local no llegó a estado terminal.
        $cacheStateAfter = (string) ($cached['state'] ?? '');
        if (in_array($cacheStateAfter, ['queued', 'running', 'prepared', 'props_ready'], true) && $this->isRemote()) {
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
                    $cached['pipeline_message'] = $remoteMsg;
                    $cached['message'] = $remoteMsg;
                }
                if (in_array($remoteState, ['queued', 'prepared', 'props_ready', 'running'], true)) {
                    $cached['state'] = 'running';
                    if ($remoteState === 'props_ready') {
                        $cached['message'] = $remoteMsg !== '' ? $remoteMsg : 'Props listos; render en curso…';
                    }
                }
                if ($remoteState === 'done') {
                    $cached['state'] = 'running'; // el ingest local puede estar en curso
                    if ($remoteMsg === '') {
                        $cached['message'] = '6/6 Importando video a la campaña…';
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

    /**
     * Consulta el progreso real del pipeline al bridge remoto.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchRemoteStatus(string $jobId): ?array
    {
        try {
            $key = 'remotion_ads:remote_status:'.$jobId;

            return Cache::remember($key, 2, function () use ($jobId) {
                $resp = Http::timeout(10)
                    ->withHeaders(['X-Remotion-Token' => $this->remoteToken()])
                    ->get($this->remoteBaseUrl().'/api/jobs/'.$jobId.'/status');
                if (! $resp->ok()) {
                    return null;
                }
                $data = $resp->json();

                return is_array($data) ? $data : null;
            });
        } catch (\Throwable $e) {
            Log::warning('Remotion: no se pudo consultar el status remoto', [
                'job' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * En mode=remote: empaqueta el job (zip), lo envía al bridge, espera el
     * render y baja out/final.mp4 al job local para su ingest.
     */
    protected function renderRemoteJob(string $jobId, string $preset): string
    {
        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;
        if (! is_dir($jobDir)) {
            throw new \RuntimeException('Carpeta del job Remotion no existe (remote).');
        }

        $base = $this->remoteBaseUrl();
        $token = $this->remoteToken();

        $this->patchStatusCache($jobId, 'running', '1/6 Subiendo job al motor Remotion…', 1, 6);
        $this->writeJobStatusFile($jobDir, 'running', '1/6 Subiendo job al motor Remotion…', 1, 6);

        $zipPath = $this->buildJobZip($jobId, $jobDir);

        try {
            // Body como string (no stream): evita cURL error 65 al reintentar
            // (Expect: 100-continue / rewind de resource no seekable).
            $zipBody = @file_get_contents($zipPath);
            if ($zipBody === false || $zipBody === '') {
                throw new \RuntimeException('No se pudo leer el zip del job Remotion.');
            }

            $resp = Http::timeout(300)
                ->connectTimeout(15)
                ->withHeaders([
                    'X-Remotion-Token' => $token,
                    'Content-Type' => 'application/zip',
                    'Expect' => '',
                ])
                ->withOptions([
                    'version' => 1.1,
                    'curl' => [
                        \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                    ],
                ])
                ->withBody($zipBody, 'application/zip')
                ->post($base.'/api/render?job='.rawurlencode($jobId).'&preset='.rawurlencode($preset));

            if ($resp->status() !== 202) {
                throw new \RuntimeException('El bridge rechazó el job: '.mb_substr((string) $resp->body(), 0, 600));
            }

            $this->patchStatusCache($jobId, 'running', '1/6 Render remoto en curso…', 1, 6);
            $this->writeJobStatusFile($jobDir, 'running', '1/6 Render remoto en curso…', 1, 6);

            $this->waitRemoteDone($jobId, $base, $token);

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
            throw new \RuntimeException('Falta la extensión PHP zip para el transporte del job.');
        }

        $root = $this->root();
        $transport = $root.DIRECTORY_SEPARATOR.'transport';
        if (! is_dir($transport) && ! mkdir($transport, 0775, true) && ! is_dir($transport)) {
            throw new \RuntimeException('No se pudo crear '.$transport);
        }
        $zipPath = $transport.DIRECTORY_SEPARATOR.'job-'.$jobId.'.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el zip del job en '.$zipPath);
        }

        $add = function (string $abs, string $entry) use ($zip): void {
            if (is_file($abs) && filesize($abs) > 0) {
                $zip->addFile($abs, $entry);
            }
        };

        $add($jobDir.DIRECTORY_SEPARATOR.'prompt.json', 'prompt.json');
        $add($jobDir.DIRECTORY_SEPARATOR.'music.json', 'music.json');
        $add($this->root().DIRECTORY_SEPARATOR.'music_catalog.json', 'music_catalog.json');
        // El pipeline remoto resuelve el estilo desde styles.json.
        $add($this->styles->path(), 'styles.json');
        $musicSelectionPath = $jobDir.DIRECTORY_SEPARATOR.'music.json';
        $musicSelection = is_file($musicSelectionPath) ? json_decode((string) file_get_contents($musicSelectionPath), true) : null;
        $musicId = is_array($musicSelection) ? (string) ($musicSelection['music_id'] ?? 'none') : 'none';
        $musicTrack = app(RemotionMusicCatalog::class)->find($musicId);
        if ($musicTrack && is_file($this->root().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.$musicTrack['file'])) {
            $add($this->root().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.$musicTrack['file'], 'music.mp3');
        }
        foreach (['images', 'videos'] as $folder) {
            $dir = $jobDir.DIRECTORY_SEPARATOR.$folder;
            if (! is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $add($dir.DIRECTORY_SEPARATOR.$name, $folder.'/'.$name);
            }
        }
        foreach (['voice.mp3', 'voice.wav', 'voice.m4a'] as $voiceName) {
            $add($jobDir.DIRECTORY_SEPARATOR.$voiceName, $voiceName);
        }

        $zip->close();
        if (! is_file($zipPath) || filesize($zipPath) < 10) {
            throw new \RuntimeException('El zip del job quedó vacío (sin medios exportables).');
        }

        return $zipPath;
    }

    protected function waitRemoteDone(string $jobId, string $base, string $token): void
    {
        $deadline = time() + $this->timeoutSeconds();
        $lastMessage = '';

        while (true) {
            try {
                $resp = Http::timeout(15)
                    ->withHeaders(['X-Remotion-Token' => $token])
                    ->get($base.'/api/jobs/'.$jobId.'/status');
            } catch (\Throwable $e) {
                if (time() >= $deadline) {
                    throw new \RuntimeException('Timeout esperando el render remoto: '.$e->getMessage());
                }
                sleep(3);

                continue;
            }

            $state = 'unknown';
            if ($resp->ok()) {
                $data = $resp->json();
                if (is_array($data)) {
                    $state = (string) ($data['state'] ?? 'unknown');
                    $lastMessage = trim((string) ($data['message'] ?? ''));
                }
            }

            if ($state === 'done') {
                return;
            }
            if ($state === 'failed') {
                throw new \RuntimeException($lastMessage !== '' ? $lastMessage : 'El pipeline remoto falló.');
            }
            if (time() >= $deadline) {
                throw new \RuntimeException('Timeout esperando el render remoto (job '.$jobId.').');
            }
            sleep(3);
        }
    }

    protected function downloadRemoteMp4(string $jobId, string $base, string $token, string $dest): void
    {
        $resp = Http::timeout(600)
            ->withHeaders(['X-Remotion-Token' => $token])
            ->withOptions(['stream' => true])
            ->get($base.'/api/jobs/'.$jobId.'/out/final.mp4');

        if (! $resp->ok()) {
            throw new \RuntimeException('El bridge no entregó final.mp4: '.mb_substr((string) $resp->body(), 0, 400));
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

    protected function cleanupRemoteWorkspace(string $jobId): void
    {
        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;
        if (! is_dir($jobDir)) {
            return;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($jobDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($jobDir);
        } catch (\Throwable $e) {
            Log::warning('Remotion: no se pudo limpiar el workspace del job', [
                'job' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function runAndIngest(
        Store $store,
        MarketingCampaign $campaign,
        MarketingPrompt $prompt,
        string $jobId,
        string $preset,
        ?int $productId = null
    ): MarketingVideo {
        ignore_user_abort(true);
        @set_time_limit(0);

        $jobDir = $this->root().DIRECTORY_SEPARATOR.'jobs'.DIRECTORY_SEPARATOR.$jobId;
        if (! is_dir($jobDir)) {
            throw new \RuntimeException('Carpeta del job Remotion no existe.');
        }

        $this->patchStatusCache($jobId, 'running', '0/6 Descargando imágenes y video del producto…', 0, 6);
        $this->writeJobStatusFile($jobDir, 'running', '0/6 Descargando imágenes y video del producto…', 0, 6);
        $productId = $productId ?: (int) $prompt->product_id;
        $product = Product::query()->where('store_id', $store->id)->whereKey($productId)->firstOrFail();
        $this->prepareJobDirectory($store, $prompt, $jobDir, null, $product);

        $mp4 = $jobDir.DIRECTORY_SEPARATOR.'out'.DIRECTORY_SEPARATOR.'final.mp4';

        if ($this->isRemote()) {
            // El pipeline (TTS + Whisper + MIIA + Remotion) corre en la máquina
            // del bridge (túnel). Aquí solo preparamos, subimos y bajamos el MP4.
            $mp4 = $this->renderRemoteJob($jobId, $preset);
        } else {
            $this->patchStatusCache($jobId, 'running', '1/6 Iniciando pipeline Python…', 1, 6);
            $this->writeJobStatusFile($jobDir, 'running', '1/6 Iniciando pipeline Python…', 1, 6);

            $python = $this->pythonBinary();
            $script = $this->root().DIRECTORY_SEPARATOR.'python'.DIRECTORY_SEPARATOR.'run_pipeline.py';
            if (is_dir($python) || (str_contains($python, DIRECTORY_SEPARATOR) && ! is_file($python))) {
                throw new \RuntimeException(
                    'No se encontró el binario Python para Remotion ('.$python.'). '
                    .'Configura REMOTION_ADS_PYTHON con la ruta absoluta al intérprete.'
                );
            }
            if (! is_file($script)) {
                throw new \RuntimeException('Falta el pipeline Remotion en '.$script);
            }
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
        }

        if (! is_file($mp4) || filesize($mp4) < 1024) {
            $this->writeJobStatusFile($jobDir, 'failed', 'No se generó out/final.mp4', null, 6);
            throw new \RuntimeException('No se generó out/final.mp4');
        }

        $this->patchStatusCache($jobId, 'running', '6/6 Importando video a la campaña…', 6, 6);
        $this->writeJobStatusFile($jobDir, 'running', '6/6 Importando video a la campaña…', 6, 6);

        try {
            return $this->ingest->ingestFromLocalPath(
                $store,
                $campaign,
                $mp4,
                $prompt,
                'remotion',
                $jobId,
                $productId,
                $this->musicAttributionForJob($jobDir)
            );
        } finally {
            if ($this->isRemote()) {
                // Liberar disco en el droplet: el job completo ya viajó y el MP4 se importó.
                $this->cleanupRemoteWorkspace($jobId);
            }
        }
    }

    protected function musicAttributionForJob(string $jobDir): ?string
    {
        $selectionPath = $jobDir.DIRECTORY_SEPARATOR.'music.json';
        if (! is_file($selectionPath)) {
            return null;
        }
        $selection = json_decode((string) file_get_contents($selectionPath), true);
        $catalog = app(RemotionMusicCatalog::class);
        $track = is_array($selection) ? $catalog->find((string) ($selection['music_id'] ?? '')) : null;

        return $track['credit'] ?? null;
    }

    public function failJob(string $jobId, string $message): void
    {
        $this->patchStatusCache($jobId, 'failed', $message);
        $cached = Cache::get($this->cacheKey($jobId));
        $jobDir = is_array($cached) ? (string) ($cached['job_dir'] ?? '') : '';
        if ($jobDir !== '') {
            $this->writeJobStatusFile($jobDir, 'failed', $message);
        }
        if (is_array($cached) && ! empty($cached['product_id'])) {
            Cache::forget($this->activeProductKey((int) $cached['store_id'], (int) $cached['campaign_id'], (int) $cached['product_id']));
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
        string $preset,
        int $productId
    ): void {
        // Bajo PHP-FPM, PHP_BINARY suele apuntar al binario php-fpm. Este
        // proceso necesita el ejecutable CLI para correr Artisan.
        $php = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (! is_file($php) || ! is_executable($php)) {
            $php = PHP_BINARY ?: 'php';
        }
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
            '--product='.$productId,
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
    public function writeEditPlanFromMiia(string $jobDir, string $preset = 'random'): array
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
        if (is_array($plan)) {
            $estilo = $this->styles->resolve($preset);
            $plan['preset'] = $estilo['id'];
            $plan['style'] = $estilo['id'];
        }
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
        ?UploadedFile $voice,
        ?Product $selectedProduct = null
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
            'product' => $selectedProduct ? [
                'id' => $selectedProduct->id,
                'name' => $selectedProduct->name,
                'description' => $selectedProduct->description,
                'price' => $selectedProduct->price,
                'compare_at_price' => $selectedProduct->compare_at_price,
                'currency' => $selectedProduct->currency,
                'sku' => $selectedProduct->sku,
                'images' => $selectedProduct->galleryImages(),
                'variants' => $selectedProduct->variants->map(fn ($variant) => [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'options' => $variant->options,
                ])->values()->all(),
            ] : null,
        ];
        file_put_contents(
            $jobDir.DIRECTORY_SEPARATOR.'prompt.json',
            json_encode($promptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
        $this->writeJobStatusFile($jobDir, 'prepared', '0/6 Job preparado; esperando pipeline…', 0, 6);

        $this->exportMediaToJob($store, $prompt, $jobDir, $selectedProduct);

        if ($voice instanceof UploadedFile && $voice->isValid()) {
            $dest = $jobDir.DIRECTORY_SEPARATOR.'voice.mp3';
            if (! is_file($dest)) {
                $voice->move($jobDir, 'voice.mp3');
            }
        }

        $media = $this->scanMedia($jobDir);
        if (! array_filter($media, fn (array $item) => ($item['media_type'] ?? '') === 'image')) {
            throw new \RuntimeException('El producto no tiene imágenes exportables para el video.');
        }
    }

    protected function exportMediaToJob(Store $store, MarketingPrompt $prompt, string $jobDir, ?Product $selectedProduct = null): void
    {
        $ids = $selectedProduct ? [(int) $selectedProduct->id] : $prompt->linkedProductIds();
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
