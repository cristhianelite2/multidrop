<?php

namespace App\Services\SellerCentral;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SellerCentralException extends \RuntimeException
{
}

class SellerCentralApi
{
    public const NETWORKS = ['facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'youtube', 'gmail'];

    public function __construct(protected ?PendingRequest $client = null)
    {
        $this->client ??= Http::timeout(30)
            ->retry(1, 400)
            ->acceptJson();
    }

    /**
     * URL base del proyecto Seller Central (sin /api/v1/key).
     */
    public function baseUrl(?Store $store = null): string
    {
        if ($store) {
            $fromStore = trim((string) data_get($store->settings, 'marketing.sellercentral_base_url', ''));
            if ($fromStore !== '') {
                return $this->normalizeBase($fromStore);
            }
        }

        return $this->normalizeBase((string) config('multidrop.marketing.sellercentral.base_url', 'https://sellercentral.ceballosleon.com'));
    }

    /**
     * API key del proyecto (Authorization Bearer).
     */
    public function apiKey(?Store $store = null): string
    {
        return trim((string) data_get($store?->settings ?? [], 'marketing.sellercentral_api_key', ''));
    }

    public function hasConnection(Store $store): bool
    {
        return $this->baseUrl($store) !== '' && $this->apiKey($store) !== '';
    }

    public function connectionState(Store $store): array
    {
        $apiKey = $this->apiKey($store);

        return [
            'base_url' => $this->baseUrl($store),
            'has_api_key' => $apiKey !== '',
            'api_key_masked' => $apiKey === '' ? '' : '••••'.mb_substr($apiKey, -4),
        ];
    }

    /**
     * Prueba la llave. Prefiere /connect; si no existe, lista posts.
     *
     * @return array<string, mixed>
     */
    public function connect(Store $store): array
    {
        try {
            return $this->get($store, '/connect');
        } catch (SellerCentralException $e) {
            if (! in_array($e->getCode(), [404, 405], true)) {
                throw $e;
            }
        }

        $posts = $this->listPosts($store, ['per_page' => 1]);

        return [
            'project' => [
                'name' => 'Proyecto conectado',
                'enabled_networks' => self::NETWORKS,
            ],
            'networks' => [],
            'posts_probe' => $posts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calendar(Store $store, string $from, string $to): array
    {
        $query = http_build_query([
            'from' => $from,
            'to' => $to,
        ]);

        return $this->get($store, '/calendar?'.$query);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listPosts(Store $store, array $filters = []): array
    {
        $query = http_build_query(array_filter($filters, fn ($v) => $v !== null && $v !== ''));

        return $this->get($store, '/posts'.($query !== '' ? '?'.$query : ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPost(Store $store, array $payload): array
    {
        return $this->post($store, '/posts', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updatePost(Store $store, int|string $postId, array $payload): array
    {
        return $this->put($store, '/posts/'.(string) $postId, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePost(Store $store, int|string $postId): array
    {
        return $this->send($store, 'DELETE', '/posts/'.(string) $postId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelPost(Store $store, int|string $postId): array
    {
        return $this->post($store, '/posts/'.(string) $postId.'/cancel');
    }

    /**
     * @return array<string, mixed>
     */
    public function publishState(Store $store, int|string $projectId): array
    {
        return $this->get($store, '/projects/'.(string) $projectId.'/publish-state');
    }

    /**
     * Sube archivos a Seller Central. $files: list de UploadedFile.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function uploadMedia(Store $store, array $files): array
    {
        $file = $files[0] ?? null;
        if (! $file) {
            throw new SellerCentralException('No hay archivo para subir.');
        }

        $response = Http::withToken($this->apiKey($store))
            ->timeout(120)
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($this->endpoint($store, '/media'));

        return $this->decode($response);
    }

    protected function get(Store $store, string $path): array
    {
        return $this->decode($this->client->withToken($this->apiKey($store))->get($this->endpoint($store, $path)));
    }

    protected function post(Store $store, string $path, array $payload = []): array
    {
        $res = $this->client->withToken($this->apiKey($store))->post($this->endpoint($store, $path), $payload);

        return $this->decode($res);
    }

    protected function put(Store $store, string $path, array $payload = []): array
    {
        $res = $this->client->withToken($this->apiKey($store))->put($this->endpoint($store, $path), $payload);

        return $this->decode($res);
    }

    protected function send(Store $store, string $method, string $path): array
    {
        $res = $this->client->withToken($this->apiKey($store))->send($method, $this->endpoint($store, $path));

        return $this->decode($res);
    }

    protected function endpoint(Store $store, string $path): string
    {
        return $this->baseUrl($store).'/api/v1/key'.(str_starts_with($path, '/') ? '' : '/').$path;
    }

    protected function decode(Response $response): array
    {
        $body = $response->json();

        if (! $response->successful()) {
            $message = 'Seller Central HTTP '.$response->status();
            if (is_array($body) && isset($body['message'])) {
                $message = (string) $body['message'];
            } elseif (is_array($body) && isset($body['error'])) {
                $message = (string) $body['error'];
            } elseif ($response->status() === 401) {
                $message = 'Llave de proyecto inválida o deshabilitada en Seller Central.';
            }

            throw new SellerCentralException($message, $response->status());
        }

        return is_array($body) ? $body : [];
    }

    protected function normalizeBase(string $url): string
    {
        $url = trim($url);
        foreach (['/api/v1/key', '/api/v1', '/'] as $suffix) {
            if ($suffix !== '/' && str_ends_with($url, $suffix)) {
                $url = mb_substr($url, 0, -mb_strlen($suffix));
                break;
            }
            if ($suffix === '/' && str_ends_with($url, '/')) {
                $url = rtrim($url, '/');
            }
        }

        return rtrim($url, '/');
    }
}