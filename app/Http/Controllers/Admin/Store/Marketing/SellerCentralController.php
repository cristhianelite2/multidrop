<?php

namespace App\Http\Controllers\Admin\Store\Marketing;

use App\Http\Controllers\Admin\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePublication;
use App\Models\StorePublicationPlan;
use App\Services\Admin\StoreContext;
use App\Services\SellerCentral\PublicationPlannerService;
use App\Services\SellerCentral\SellerCentralApi;
use App\Services\SellerCentral\SellerCentralException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SellerCentralController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(protected SellerCentralApi $api)
    {
    }

    public function index(StoreContext $storeContext)
    {
        $store = $this->currentStoreOrFail($storeContext);

        $connection = $this->connection($store);
        $state = $this->api->connectionState($store);

        $plans = StorePublicationPlan::query()
            ->where('store_id', $store->id)
            ->with(['publications.product'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit(250)
            ->get(['id', 'name', 'slug', 'status', 'image_url']);

        $calendar = [];
        if ($connection['ok']) {
            $calendar = $this->remoteCalendar($store);
        }

        return view('admin.store.marketing.sellercentral.index', [
            'store' => $store,
            'connection' => $connection,
            'state' => $state,
            'plans' => $plans,
            'products' => $products,
            'calendar' => $calendar,
            'embedUrl' => $this->embedUrl($store),
            'connectionErrors' => $connection['ok'] ? [] : [$connection['error']],
        ]);
    }

    public function test(Request $request, StoreContext $storeContext): JsonResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:200'],
            'base_url' => ['nullable', 'url', 'max:500'],
        ]);

        if (! empty($data['api_key']) || $request->exists('base_url')) {
            $this->saveCredentials($store, (string) ($data['base_url'] ?? ''), (string) ($data['api_key'] ?? ''));
        }

        if (! $this->api->hasConnection($store)) {
            return response()->json(['ok' => false, 'error' => 'Falta la API key del proyecto.'], 422);
        }

        try {
            $info = $this->api->connect($store);
        } catch (SellerCentralException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo conectar: '.$e->getMessage()], 502);
        }

        $this->forgetConnectionCache($store);

        return response()->json([
            'ok' => true,
            'project' => data_get($info, 'project.name'),
            'networks' => array_keys(array_filter(data_get($info, 'networks', []), fn ($n) => ! empty($n['connected']))),
        ]);
    }

    public function saveSettings(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $data = $request->validate([
            'base_url' => ['nullable', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:200'],
            'embed_url' => ['nullable', 'url', 'max:500'],
        ]);

        $this->saveCredentials($store, (string) ($data['base_url'] ?? ''), (string) ($data['api_key'] ?? ''));
        $this->saveEmbedUrl($store, (string) ($data['embed_url'] ?? ''));

        return redirect()->route('admin.store.marketing.sellercentral.index')
            ->with('success', 'Conexión con Seller Central guardada.');
    }

    public function generate(Request $request, StoreContext $storeContext, PublicationPlannerService $planner): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('multidrop.marketing.sellercentral.max_days', 60)],
            'per_day' => ['required', 'integer', 'min:1', 'max:'.(int) config('multidrop.marketing.sellercentral.max_per_day', 10)],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'in:'.implode(',', SellerCentralApi::NETWORKS)],
            'products' => ['nullable', 'array'],
            'products.*' => ['integer', 'exists:products,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'format' => ['nullable', 'in:image,text'],
        ]);

        if (! empty($data['products'])) {
            $owned = Product::query()
                ->where('store_id', $store->id)
                ->whereIn('id', array_map('intval', $data['products']))
                ->pluck('id')
                ->all();
            $data['products'] = $owned;
        }

        try {
            $result = $planner->buildPlan($store, [
                'days' => (int) $data['days'],
                'per_day' => (int) $data['per_day'],
                'channels' => (array) $data['channels'],
                'product_ids' => array_map('intval', (array) ($data['products'] ?? [])),
                'start_date' => (string) ($data['start_date'] ?? ''),
                'format' => (string) ($data['format'] ?? 'image'),
            ]);
        } catch (SellerCentralException $e) {
            return back()->with('error', $e->getMessage());
        }

        $plan = StorePublicationPlan::create([
            'store_id' => $store->id,
            'batch_uid' => Str::random(16),
            'days' => (int) $data['days'],
            'per_day' => (int) $data['per_day'],
            'start_date' => $result['slots'][0]['date'] ?? now(),
            'channels' => array_values((array) $data['channels']),
            'product_ids' => array_map('intval', (array) ($data['products'] ?? [])),
            'format' => (string) ($data['format'] ?? 'image'),
            'status' => 'draft',
            'generated_at' => now(),
        ]);

        foreach ($result['slots'] as $slot) {
            StorePublication::create([
                'store_id' => $store->id,
                'plan_id' => $plan->id,
                'product_id' => $slot['product_id'] ?? null,
                'network' => $slot['network'],
                'format' => $slot['format'],
                'content' => $slot['content'],
                'topic' => $slot['topic'] ?? null,
                'media_urls' => $slot['media_urls'] ?? [],
                'scheduled_at' => $slot['scheduled_at'] ?? null,
                'status' => StorePublication::STATUS_DRAFT,
                'day_offset' => (int) $slot['day_offset'],
                'slot_index' => (int) $slot['slot_index'],
            ]);
        }

        $total = count($result['slots']);
        $flash = 'Plan generado: '.$total.' publicación(es). Revísalas y aprobalas para programarlas.';
        if ($result['errors'] !== []) {
            $flash .= ' · '.count($result['errors']).' día(s) usaron copy de respaldo.';
        }

        return redirect()->route('admin.store.marketing.sellercentral.index')->with('success', $flash);
    }

    public function sendPlan(Request $request, StoreContext $storeContext, StorePublicationPlan $plan): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $plan->store_id);

        if (! $this->api->hasConnection($store)) {
            return back()->with('error', 'Configura la API key del proyecto en Seller Central.');
        }

        $publications = $plan->publications()
            ->whereIn('status', [StorePublication::STATUS_DRAFT, StorePublication::STATUS_APPROVED, StorePublication::STATUS_ERROR])
            ->orderBy('scheduled_at')
            ->get();

        if ($publications->isEmpty()) {
            return back()->with('info', 'No hay publicaciones pendientes en este plan.');
        }

        $sent = 0;
        $failed = 0;
        foreach ($publications as $publication) {
            if ($this->pushToSellerCentral($store, $publication)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $this->forgetConnectionCache($store);
        $plan->status = $failed > 0 && $sent > 0 ? 'partial' : ($failed > 0 ? 'error' : 'sent');
        $plan->save();

        return redirect()->route('admin.store.marketing.sellercentral.index')
            ->with('success', "Plan enviado: {$sent} programada(s) · {$failed} con error.");
    }

    public function approve(Request $request, StoreContext $storeContext, StorePublication $publication): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $publication->store_id);

        if (! in_array($publication->status, [StorePublication::STATUS_DRAFT, StorePublication::STATUS_ERROR], true)) {
            return back()->with('info', 'La publicación ya no es un borrador.');
        }

        if ($this->pushToSellerCentral($store, $publication)) {
            return back()->with('success', 'Publicación programada en Seller Central.');
        }

        return back()->with('error', 'No se pudo programar la publicación.');
    }

    public function updatePublication(Request $request, StoreContext $storeContext, StorePublication $publication): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $publication->store_id);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
            'topic' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'string', 'max:32'],
            'network' => ['nullable', 'in:'.implode(',', SellerCentralApi::NETWORKS)],
            'format' => ['nullable', 'in:text,image,carousel,video'],
        ]);

        $scheduledAt = null;
        if (! empty($data['scheduled_at'])) {
            try {
                $scheduledAt = Carbon::parse(str_replace('T', ' ', (string) $data['scheduled_at']))
                    ->format('Y-m-d H:i:00');
            } catch (\Throwable) {
                return back()->with('error', 'Fecha de programación inválida.');
            }
        }

        $payload = [
            'content' => trim((string) $data['content']),
            'topic' => ! empty($data['topic']) ? trim((string) $data['topic']) : null,
            'scheduled_at' => $scheduledAt,
        ];
        if (! empty($data['network'])) {
            $payload['network'] = $data['network'];
        }
        if (! empty($data['format'])) {
            $payload['format'] = $data['format'];
        }

        $publication->fill($payload);
        $publication->save();

        if ($publication->sellercentral_post_id && $this->api->hasConnection($store)) {
            try {
                $remotePayload = $payload;
                if ($scheduledAt) {
                    $remotePayload['scheduled_at'] = Carbon::parse($scheduledAt)->format('Y-m-d\TH:i:s');
                    $remotePayload['status'] = 'scheduled';
                }
                $this->api->updatePost($store, $publication->sellercentral_post_id, $remotePayload);
            } catch (SellerCentralException $e) {
                return back()->with('error', 'Guardado local, pero Seller Central rechazó la actualización: '.$e->getMessage());
            }
        }

        return back()->with('success', 'Publicación actualizada.');
    }

    public function cancel(Request $request, StoreContext $storeContext, StorePublication $publication): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $publication->store_id);

        $remote = false;
        if ($publication->sellercentral_post_id && $this->api->hasConnection($store)) {
            try {
                $this->api->cancelPost($store, $publication->sellercentral_post_id);
                $remote = true;
            } catch (SellerCentralException $e) {
                return back()->with('error', 'Seller Central rechazó la cancelación: '.$e->getMessage());
            }
        }

        $publication->update([
            'status' => StorePublication::STATUS_CANCELLED,
            'scheduled_at' => null,
            'sellercentral_post_id' => $remote ? $publication->sellercentral_post_id : null,
            'error_message' => null,
        ]);

        return back()->with('success', 'Publicación cancelada.');
    }

    public function destroyPublication(Request $request, StoreContext $storeContext, StorePublication $publication): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $publication->store_id);

        if ($publication->sellercentral_post_id && $this->api->hasConnection($store)) {
            try {
                $this->api->deletePost($store, $publication->sellercentral_post_id);
            } catch (SellerCentralException) {
                // se sigue eliminando local
            }
        }

        $publication->delete();

        return back()->with('success', 'Publicación eliminada.');
    }

    public function destroyPlan(Request $request, StoreContext $storeContext, StorePublicationPlan $plan): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);
        $this->assertStore($store->id, $plan->store_id);

        if ($this->api->hasConnection($store)) {
            foreach ($plan->publications()->whereNotNull('sellercentral_post_id')->get() as $publication) {
                try {
                    $this->api->deletePost($store, $publication->sellercentral_post_id);
                } catch (SellerCentralException) {
                    // se sigue eliminando local
                }
            }
        }

        $plan->delete();

        return redirect()->route('admin.store.marketing.sellercentral.index')
            ->with('success', 'Plan eliminado.');
    }

    public function sync(Request $request, StoreContext $storeContext): RedirectResponse
    {
        $store = $this->currentStoreOrFail($storeContext);

        if (! $this->api->hasConnection($store)) {
            return back()->with('error', 'Configura la API key del proyecto en Seller Central.');
        }

        try {
            $remote = $this->api->calendar(
                $store,
                now()->subDays(30)->format('Y-m-d'),
                now()->addDays(30)->format('Y-m-d')
            );
        } catch (SellerCentralException $e) {
            return back()->with('error', 'No se pudo sincronizar: '.$e->getMessage());
        }

        $posts = $remote['posts'] ?? [];
        $updated = 0;
        $touched = [];

        foreach ($posts as $post) {
            if (! is_array($post) || empty($post['id'])) {
                continue;
            }
            $touched[(int) $post['id']] = true;
            $local = StorePublication::where('store_id', $store->id)
                ->where('sellercentral_post_id', (string) $post['id'])
                ->first();
            if (! $local) {
                continue;
            }
            $changes = [
                'status' => (string) ($post['status'] ?? $local->status),
                'scheduled_at' => ! empty($post['scheduled_at']) ? $post['scheduled_at'] : $local->scheduled_at,
                'error_message' => ! empty($post['error_message']) ? $post['error_message'] : null,
            ];
            if ($local->status !== $changes['status'] || (string) $local->scheduled_at !== (string) $changes['scheduled_at']) {
                $local->update($changes);
                $updated++;
            }
        }

        $this->forgetConnectionCache($store);

        return back()->with('success', $updated > 0
            ? "Sincronizado: {$updated} publicación(es) actualizada(s)."
            : 'Sincronizado: sin cambios.');
    }

    protected function pushToSellerCentral(Store $store, StorePublication $publication): bool
    {
        try {
            $media = is_array($publication->media_urls) ? array_values(array_filter($publication->media_urls, function ($row) {
                return is_array($row) && ! empty($row['url']);
            })) : [];
            $format = (string) ($publication->format ?: 'text');
            if ($format === 'image' && $media === []) {
                $format = 'text';
            }

            $payload = [
                'network' => $publication->network,
                'content' => $publication->content,
                'topic' => $publication->topic ?: null,
                'format' => $format,
                'media_urls' => $media !== [] ? $media : null,
            ];

            if ($publication->scheduled_at) {
                $payload['scheduled_at'] = $publication->scheduled_at->format('Y-m-d\TH:i:s');
                $payload['status'] = 'scheduled';
            } else {
                $payload['status'] = 'draft';
            }

            if ($publication->sellercentral_post_id) {
                $remote = $this->api->updatePost($store, $publication->sellercentral_post_id, $payload);
            } else {
                $remote = $this->api->createPost($store, $payload);
            }

            $publication->update([
                'sellercentral_post_id' => (string) (data_get($remote, 'id') ?: $publication->sellercentral_post_id),
                'status' => $publication->scheduled_at ? StorePublication::STATUS_SCHEDULED : StorePublication::STATUS_APPROVED,
                'error_message' => null,
                'format' => $format,
            ]);

            return true;
        } catch (SellerCentralException $e) {
            $publication->update([
                'status' => StorePublication::STATUS_ERROR,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sellercentral push failed', ['publication' => $publication->id, 'error' => $e->getMessage()]);
            $publication->update([
                'status' => StorePublication::STATUS_ERROR,
                'error_message' => mb_substr('Error de conexión: '.$e->getMessage(), 0, 2000),
            ]);

            return false;
        }
    }

    protected function connection(Store $store): array
    {
        $state = $this->api->connectionState($store);
        if (! $state['has_api_key']) {
            return ['ok' => false, 'error' => 'Falta la API key del proyecto.'];
        }

        $cacheKey = $this->connectionCacheKey($store);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $info = $this->api->connect($store);
        } catch (SellerCentralException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo conectar: '.$e->getMessage()];
        }

        $enabled = is_array(data_get($info, 'project.enabled_networks'))
            ? array_values(array_filter(array_map('strval', data_get($info, 'project.enabled_networks', []))))
            : [];
        $connected = [];
        foreach (data_get($info, 'networks', []) as $network => $row) {
            if (is_array($row) && ! empty($row['connected'])) {
                $connected[] = $network;
            }
        }

        $result = [
            'ok' => true,
            'project_name' => (string) data_get($info, 'project.name', ''),
            'enabled_networks' => $enabled,
            'connected_networks' => $connected,
        ];
        Cache::put($cacheKey, $result, 300);

        return $result;
    }

    protected function remoteCalendar(Store $store): array
    {
        $cacheKey = $this->connectionCacheKey($store).'.calendar';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $remote = $this->api->calendar(
                $store,
                now()->startOfMonth()->format('Y-m-d'),
                now()->endOfMonth()->format('Y-m-d')
            );
        } catch (\Throwable) {
            return [];
        }

        $posts = $remote['posts'] ?? [];
        usort($posts, function ($a, $b) {
            return strcmp((string) ($a['scheduled_at'] ?? $a['created_at'] ?? ''), (string) ($b['scheduled_at'] ?? $b['created_at'] ?? ''));
        });

        Cache::put($cacheKey, $posts, 300);

        return $posts;
    }

    protected function connectionCacheKey(Store $store): string
    {
        return 'sc.connect.'.$store->id.'.'.substr(md5($this->api->apiKey($store)), 0, 10);
    }

    protected function forgetConnectionCache(Store $store): void
    {
        Cache::forget($this->connectionCacheKey($store));
        Cache::forget($this->connectionCacheKey($store).'.calendar');
    }

    protected function saveCredentials(Store $store, string $baseUrl, string $apiKey): void
    {
        $settings = is_array($store->settings) ? $store->settings : [];
        $marketing = is_array($settings['marketing'] ?? null) ? $settings['marketing'] : [];

        $baseUrl = trim($baseUrl);
        if ($baseUrl !== '') {
            $marketing['sellercentral_base_url'] = $baseUrl;
        } else {
            unset($marketing['sellercentral_base_url']);
        }

        $apiKey = trim($apiKey);
        if ($apiKey !== '') {
            $marketing['sellercentral_api_key'] = $apiKey;
        } else {
            unset($marketing['sellercentral_api_key']);
        }

        $settings['marketing'] = $marketing;
        $store->settings = $settings;
        $store->save();
    }

    protected function saveEmbedUrl(Store $store, string $url): void
    {
        $url = trim($url);
        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $settings = is_array($store->settings) ? $store->settings : [];
        $marketing = is_array($settings['marketing'] ?? null) ? $settings['marketing'] : [];
        if ($url === '' || $url === (string) config('multidrop.marketing.sellercentral.embed_url', '')) {
            unset($marketing['sellercentral_embed_url']);
        } else {
            $marketing['sellercentral_embed_url'] = $url;
        }
        $settings['marketing'] = $marketing;
        $store->settings = $settings;
        $store->save();
    }

    protected function embedUrl(Store $store): string
    {
        $fromStore = trim((string) data_get($store->settings, 'marketing.sellercentral_embed_url', ''));
        if ($fromStore !== '') {
            return $fromStore;
        }

        return trim((string) config('multidrop.marketing.sellercentral.embed_url', ''));
    }

    protected function assertStore(int $current, int $owner): void
    {
        abort_unless($current === $owner, 404);
    }
}