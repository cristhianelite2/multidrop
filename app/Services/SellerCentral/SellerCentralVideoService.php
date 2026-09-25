<?php

namespace App\Services\SellerCentral;

use App\Models\MarketingCampaign;
use App\Models\MarketingVideo;
use App\Models\Product;
use App\Models\SellerCentralVideoJob;
use App\Models\Store;
use App\Services\Marketing\VideoIngestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SellerCentralVideoService
{
    public function __construct(
        protected SellerCentralApi $api,
        protected VideoIngestService $ingest,
    ) {}

    public function hasConnection(Store $store): bool
    {
        return $this->api->hasConnection($store);
    }

    /**
     * @param  'assets'|'url_only'  $mode
     */
    public function start(
        Store $store,
        MarketingCampaign $campaign,
        Product $product,
        string $mode = 'assets',
        ?string $instructions = null,
        string $videoFormat = 'short'
    ): SellerCentralVideoJob {
        if (! $this->api->hasConnection($store)) {
            throw new SellerCentralException('Configura la API key de Seller Central en Marketing → Publicaciones.');
        }

        $mode = $mode === 'url_only' ? 'url_only' : 'assets';
        $active = SellerCentralVideoJob::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('status', ['completed', 'error'])
            ->exists();
        if ($active) {
            throw new SellerCentralException('Ya hay una generación NotebookLM en curso para este producto.');
        }

        $productUrl = $this->productPublicUrl($store, $product);
        $documents = $mode === 'url_only'
            ? $this->documentsUrlOnly($product, $productUrl)
            : $this->documentsFromProduct($product, $productUrl);

        if ($documents === []) {
            throw new SellerCentralException('No hay contenido para enviar (imágenes, videos, textos o URL).');
        }

        $title = mb_substr('MD · '.$product->localizedName(), 0, 240);
        $token = Str::random(48);
        $callbackUrl = route('webhooks.sellercentral.videos', ['token' => $token], true);

        $payload = [
            'title' => $title,
            'video_format' => in_array($videoFormat, ['short', 'explainer', 'cinematic'], true)
                ? $videoFormat
                : (string) config('multidrop.marketing.sellercentral.video_format', 'short'),
            'instructions' => $instructions !== null && trim($instructions) !== ''
                ? trim($instructions)
                : $this->defaultInstructions($product, $mode),
            'documents' => $documents,
            'callback_url' => $callbackUrl,
            'multidrop_product_id' => (string) $product->id,
            'multidrop_product_name' => mb_substr($product->localizedName(), 0, 250),
            'multidrop_product_url' => $productUrl,
        ];

        $job = SellerCentralVideoJob::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'callback_token' => $token,
            'mode' => $mode,
            'status' => 'pending',
            'title' => $title,
            'payload_snapshot' => [
                'documents_count' => count($documents),
                'mode' => $mode,
                'product_url' => $productUrl,
            ],
            'video_log' => [[
                'step' => 'multidrop',
                'ok' => true,
                'message' => 'Enviando tarea a Seller Central (modo '.($mode === 'url_only' ? 'solo URL' : 'contenidos').')',
                'at' => now()->toIso8601String(),
            ]],
        ]);

        try {
            $remote = $this->api->createVideo($store, $payload);
            $taskId = (int) ($remote['id'] ?? 0);
            if ($taskId < 1) {
                throw new SellerCentralException('Seller Central no devolvió id de tarea.');
            }

            $status = (string) ($remote['status'] ?? 'draft');
            $job->fill([
                'sellercentral_task_id' => $taskId,
                'status' => $this->normalizeStatus($status),
                'notebook_id' => $remote['notebook_id'] ?? null,
                'video_log' => $this->mergeLogs(
                    $job->video_log,
                    is_array($remote['video_log'] ?? null) ? $remote['video_log'] : [],
                    'Tarea creada en Seller Central (#'.$taskId.')'
                ),
                'queued_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            if (! in_array($job->status, ['queued', 'scheduled', 'uploading', 'generating', 'completed'], true)) {
                $gen = $this->api->generateVideo($store, $taskId);
                $job->fill([
                    'status' => $this->normalizeStatus((string) ($gen['status'] ?? 'queued')),
                    'notebook_id' => $gen['notebook_id'] ?? $job->notebook_id,
                    'video_log' => $this->mergeLogs(
                        $job->video_log,
                        is_array($gen['video_log'] ?? null) ? $gen['video_log'] : [],
                        'Encolada para el agente NotebookLM'
                    ),
                    'last_synced_at' => now(),
                ])->save();
            }
        } catch (\Throwable $e) {
            $job->fill([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'video_log' => $this->mergeLogs($job->video_log, [], 'Error: '.$e->getMessage(), false),
                'completed_at' => now(),
                'last_synced_at' => now(),
            ])->save();
            throw $e instanceof SellerCentralException
                ? $e
                : new SellerCentralException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return $job->fresh();
    }

    public function syncFromRemote(SellerCentralVideoJob $job): SellerCentralVideoJob
    {
        if (! $job->sellercentral_task_id) {
            return $job;
        }
        $store = $job->store ?? Store::query()->find($job->store_id);
        if (! $store || ! $this->api->hasConnection($store)) {
            return $job;
        }

        try {
            $remote = $this->api->getVideo($store, $job->sellercentral_task_id);
        } catch (SellerCentralException $e) {
            Log::warning('sellercentral video sync failed', [
                'job' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return $job;
        }

        $status = $this->normalizeStatus((string) ($remote['status'] ?? $job->status));
        $log = $this->mergeLogs(
            $job->video_log,
            is_array($remote['video_log'] ?? null) ? $remote['video_log'] : []
        );

        $job->fill([
            'status' => $status,
            'notebook_id' => $remote['notebook_id'] ?? $job->notebook_id,
            'error_message' => $status === 'error'
                ? (string) ($remote['error_message'] ?? $job->error_message)
                : null,
            'video_log' => $log,
            'remote_video_url' => (string) ($remote['video_url'] ?? $job->remote_video_url ?: ''),
            'last_synced_at' => now(),
        ]);

        if ($status === 'completed' && ! $job->marketing_video_id) {
            $job->save();
            $this->tryIngestFromRemoteUrl($job, $store);
        } elseif (in_array($status, ['completed', 'error'], true) && ! $job->completed_at) {
            $job->completed_at = now();
            $job->save();
        } else {
            $job->save();
        }

        return $job->fresh();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function handleCallback(SellerCentralVideoJob $job, array $fields, ?UploadedFile $videoFile = null, array $extraVideos = []): SellerCentralVideoJob
    {
        if ($job->isTerminal() && $job->marketing_video_id) {
            return $job;
        }

        $status = $this->normalizeStatus((string) ($fields['status'] ?? 'completed'));
        $remoteLog = [];
        if (! empty($fields['video_log'])) {
            $raw = $fields['video_log'];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $remoteLog = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $remoteLog = $raw;
            }
        }

        $job->fill([
            'status' => $status === 'completed' ? 'completed' : $status,
            'notebook_id' => $fields['notebook_id'] ?? $job->notebook_id,
            'remote_video_url' => (string) ($fields['video_url'] ?? $job->remote_video_url ?: ''),
            'error_message' => $status === 'error' ? (string) ($fields['error_message'] ?? 'Error en Seller Central') : null,
            'video_log' => $this->mergeLogs($job->video_log, $remoteLog, 'Callback recibido desde Seller Central'),
            'last_synced_at' => now(),
        ])->save();

        if ($status === 'completed') {
            $store = $job->store ?? Store::query()->findOrFail($job->store_id);
            $campaign = $job->campaign ?? MarketingCampaign::query()->findOrFail($job->campaign_id);
            $video = null;

            if ($videoFile) {
                $video = $this->ingest->ingestUpload(
                    $store,
                    $campaign,
                    $videoFile,
                    null,
                    (int) $job->product_id,
                    'notebooklm',
                    $job->sellercentral_task_id ? (string) $job->sellercentral_task_id : null
                );
            } elseif (! empty($job->remote_video_url)) {
                $video = $this->ingest->ingestFromUrl(
                    $store,
                    $campaign,
                    $job->remote_video_url,
                    null,
                    $job->sellercentral_task_id ? (string) $job->sellercentral_task_id : null,
                    'notebooklm',
                    (int) $job->product_id
                );
            }

            foreach ($extraVideos as $extra) {
                if ($extra instanceof UploadedFile) {
                    $this->ingest->ingestUpload(
                        $store,
                        $campaign,
                        $extra,
                        null,
                        (int) $job->product_id,
                        'notebooklm',
                        $job->sellercentral_task_id ? (string) $job->sellercentral_task_id : null
                    );
                }
            }

            if ($video) {
                $job->marketing_video_id = $video->id;
            }
            $job->status = 'completed';
            $job->completed_at = now();
            $job->video_log = $this->mergeLogs($job->video_log, [], $video
                ? 'Video guardado en Multidrop (#'.$video->id.')'
                : 'Callback completed sin archivo MP4 adjunto');
            $job->save();
        } elseif ($status === 'error') {
            $job->completed_at = now();
            $job->save();
        }

        return $job->fresh();
    }

    public function stop(SellerCentralVideoJob $job): SellerCentralVideoJob
    {
        $store = $job->store ?? Store::query()->find($job->store_id);
        if ($store && $job->sellercentral_task_id && $this->api->hasConnection($store)) {
            try {
                $this->api->stopVideo($store, $job->sellercentral_task_id);
            } catch (SellerCentralException $e) {
                Log::info('sellercentral stop video', ['job' => $job->id, 'error' => $e->getMessage()]);
            }
        }
        $job->fill([
            'status' => 'error',
            'error_message' => 'Detenido desde Multidrop',
            'completed_at' => now(),
            'video_log' => $this->mergeLogs($job->video_log, [], 'Detenido desde Multidrop', false),
            'last_synced_at' => now(),
        ])->save();

        return $job->fresh();
    }

    protected function tryIngestFromRemoteUrl(SellerCentralVideoJob $job, Store $store): void
    {
        $url = trim((string) $job->remote_video_url);
        if ($url === '' || $job->marketing_video_id) {
            return;
        }
        $campaign = $job->campaign ?? MarketingCampaign::query()->find($job->campaign_id);
        if (! $campaign) {
            return;
        }
        try {
            $video = $this->ingest->ingestFromUrl(
                $store,
                $campaign,
                $url,
                null,
                $job->sellercentral_task_id ? (string) $job->sellercentral_task_id : null,
                'notebooklm',
                (int) $job->product_id
            );
            $job->marketing_video_id = $video->id;
            $job->completed_at = $job->completed_at ?: now();
            $job->video_log = $this->mergeLogs($job->video_log, [], 'Video descargado por URL a Multidrop (#'.$video->id.')');
            $job->save();
        } catch (\Throwable $e) {
            Log::warning('notebooklm ingest url failed', ['job' => $job->id, 'error' => $e->getMessage()]);
        }
    }

    protected function productPublicUrl(Store $store, Product $product): string
    {
        try {
            return route('store.design.page', ['slug' => $store->slug, 'handle' => $product->slug], true);
        } catch (\Throwable) {
            return url('/s/'.$store->slug.'/pages/'.$product->slug);
        }
    }

    /**
     * @return list<array{type: string, name: string, url?: ?string, content?: ?string}>
     */
    protected function documentsUrlOnly(Product $product, string $productUrl): array
    {
        return [[
            'type' => 'text',
            'name' => 'URL del producto Multidrop',
            'url' => null,
            'content' => "Producto: {$product->localizedName()}\nURL: {$productUrl}\n\nUsa esta ficha pública para crear un video promocional corto.",
        ]];
    }

    /**
     * @return list<array{type: string, name: string, url?: ?string, content?: ?string}>
     */
    protected function documentsFromProduct(Product $product, string $productUrl): array
    {
        $docs = [];
        $images = $product->galleryImages();
        if ($images === [] && $product->image_url) {
            $images = [(string) $product->image_url];
        }
        foreach (array_slice($images, 0, 12) as $i => $url) {
            $url = $this->absoluteMediaUrl((string) $url);
            if ($url === '') {
                continue;
            }
            $docs[] = [
                'type' => 'image',
                'name' => 'Imagen '.($i + 1),
                'url' => $url,
            ];
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        foreach (array_slice((array) ($verified['videos'] ?? []), 0, 3) as $i => $row) {
            $url = is_array($row) ? (string) ($row['url'] ?? '') : (string) $row;
            $url = $this->absoluteMediaUrl($url);
            if ($url === '' || ! preg_match('/\.(mp4|webm|mov)(\?|$)/i', $url)) {
                continue;
            }
            $docs[] = [
                'type' => 'video',
                'name' => 'Video producto '.($i + 1),
                'url' => $url,
            ];
        }

        $textBits = array_filter([
            $product->localizedName(),
            $product->localizedDescription() ?: null,
            trim((string) ($verified['description_short'] ?? '')),
            $productUrl !== '' ? 'URL: '.$productUrl : null,
        ]);
        if ($textBits !== []) {
            $docs[] = [
                'type' => 'text',
                'name' => 'Ficha del producto',
                'url' => null,
                'content' => mb_substr(implode("\n\n", $textBits), 0, 12000),
            ];
        }

        return $docs;
    }

    protected function absoluteMediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, '/')) {
            return url($url);
        }

        return $url;
    }

    protected function defaultInstructions(Product $product, string $mode): string
    {
        $name = $product->localizedName();
        if ($mode === 'url_only') {
            return "Crea un video corto vertical (9:16) promocionando «{$name}». Usa la URL/ficha del producto como fuente. Beneficios claros, ritmo dinámico y CTA de compra.";
        }

        return "Crea un video corto vertical (9:16) promocionando «{$name}». Usa las imágenes y textos enviados. Destaca beneficios reales, ritmo dinámico y cierra con CTA de compra.";
    }

    protected function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, SellerCentralVideoJob::STATUSES, true)) {
            return $status;
        }

        return 'pending';
    }

    /**
     * @param  list<mixed>|null  $current
     * @param  list<mixed>  $incoming
     * @return list<array<string, mixed>>
     */
    protected function mergeLogs(?array $current, array $incoming, ?string $note = null, ?bool $ok = true): array
    {
        $out = [];
        $seen = [];
        foreach (array_merge(is_array($current) ? $current : [], $incoming) as $row) {
            if (is_string($row)) {
                $row = ['step' => 'log', 'ok' => true, 'message' => $row, 'at' => null];
            }
            if (! is_array($row)) {
                continue;
            }
            $key = ($row['step'] ?? '').'|'.($row['message'] ?? '').'|'.($row['at'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'step' => (string) ($row['step'] ?? 'step'),
                'ok' => array_key_exists('ok', $row) ? (bool) $row['ok'] : null,
                'message' => (string) ($row['message'] ?? ''),
                'at' => isset($row['at']) ? (string) $row['at'] : null,
            ];
        }
        if ($note) {
            $noteKey = 'multidrop|'.$note;
            if (! isset($seen[$noteKey])) {
                $out[] = [
                    'step' => 'multidrop',
                    'ok' => $ok,
                    'message' => $note,
                    'at' => now()->toIso8601String(),
                ];
            }
        }

        return array_slice($out, -80);
    }
}
