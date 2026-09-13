<?php

namespace App\Http\Controllers\Api;

use App\Models\CampaignProductMedia;
use App\Models\MarketingCampaign;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignProductMediaController extends ApiController
{
    protected const MAX_BYTES = 153600 * 1024;

    public function index(MarketingCampaign $campaign, Product $product): JsonResponse
    {
        $this->assertOwned($campaign, $product);

        $media = CampaignProductMedia::query()
            ->where('marketing_campaign_id', $campaign->id)
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CampaignProductMedia $row) => $this->export($row))
            ->values()
            ->all();

        return $this->respond([
            'campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'media' => $media,
        ]);
    }

    public function store(Request $request, MarketingCampaign $campaign, Product $product): JsonResponse
    {
        $this->assertOwned($campaign, $product);

        $data = $request->validate([
            'file' => ['required_without:url', 'file', 'max:153600', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,mov,m4v'],
            'url' => ['required_without:file', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:180'],
            'kind' => ['nullable', Rule::in(['image', 'video'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $media = $this->ingest($request, $campaign, $product, $data);

        return $this->respond($this->export($media), 201, 'Multimedia agregada.');
    }

    public function show(MarketingCampaign $campaign, Product $product, CampaignProductMedia $media): JsonResponse
    {
        $this->assertOwned($campaign, $product);
        $this->assertMedia($campaign, $product, $media);

        return $this->respond($this->export($media));
    }

    public function update(Request $request, MarketingCampaign $campaign, Product $product, CampaignProductMedia $media): JsonResponse
    {
        $this->assertOwned($campaign, $product);
        $this->assertMedia($campaign, $product, $media);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'kind' => ['nullable', Rule::in(['image', 'video'])],
            'metadata' => ['nullable', 'array'],
        ]);

        if (isset($data['name'])) {
            $data['original_name'] = $data['name'];
            unset($data['name']);
        }

        $media->update($data);

        return $this->respond($this->export($media->fresh()), 200, 'Multimedia actualizada.');
    }

    public function destroy(MarketingCampaign $campaign, Product $product, CampaignProductMedia $media): JsonResponse
    {
        $this->assertOwned($campaign, $product);
        $this->assertMedia($campaign, $product, $media);

        if ($media->path) {
            Storage::disk('public')->delete($media->path);
        }
        $media->delete();

        return $this->respond(null, 200, 'Multimedia eliminada.');
    }

    protected function ingest(Request $request, MarketingCampaign $campaign, Product $product, array $data): CampaignProductMedia
    {
        $name = trim((string) ($data['name'] ?? ''));
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : null;

        if ($request->hasFile('file')) {
            $file = $data['file'];
            $mime = (string) ($file->getMimeType() ?: '');
            $kind = $this->kindOverride($data['kind'] ?? null, $mime, (string) $file->getClientOriginalName());
            $contents = $file->getContent();
            $size = (int) $file->getSize();
            $originalName = $name !== '' ? $name : (string) $file->getClientOriginalName();

            return $this->persist($campaign, $product, $kind, $contents, $originalName, $mime, $size, $metadata);
        }

        abort_unless(! empty($data['url']), 422, 'Se requiere un archivo o una URL.');

        $response = Http::timeout(180)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Multidrop/1.0)'])
            ->get($data['url']);

        abort_unless($response->successful(), 422, 'No se pudo descargar el archivo desde la URL.');

        $contents = $response->body();
        if (strlen($contents) > self::MAX_BYTES) {
            abort(422, 'El archivo descargado supera el tamaño máximo.');
        }

        $mime = trim((string) $response->header('Content-Type', ''));
        $mime = preg_replace('/;.*$/', '', $mime) ?: '';
        $kind = $this->kindOverride($data['kind'] ?? null, $mime, (string) $data['url']);
        $originalName = $name !== '' ? $name : basename((string) parse_url($data['url'], PHP_URL_PATH));

        return $this->persist($campaign, $product, $kind, $contents, $originalName, $mime, strlen($contents), $metadata);
    }

    protected function persist(
        MarketingCampaign $campaign,
        Product $product,
        string $kind,
        string $contents,
        string $originalName,
        string $mime,
        int $size,
        ?array $metadata
    ): CampaignProductMedia {
        $path = $this->storeFile($campaign, $contents, $this->extensionFor($kind, $mime, $originalName));

        return CampaignProductMedia::create([
            'store_id' => $campaign->store_id,
            'marketing_campaign_id' => $campaign->id,
            'product_id' => $product->id,
            'kind' => $kind,
            'path' => $path,
            'original_name' => mb_substr($originalName, 0, 180),
            'mime' => $mime !== '' ? $mime : null,
            'size' => $size,
            'metadata' => $metadata,
        ]);
    }

    protected function storeFile(MarketingCampaign $campaign, string $contents, string $ext): string
    {
        $dir = 'campaign-media/'.$campaign->store_id.'/'.$campaign->id;
        Storage::disk('public')->makeDirectory($dir);
        $basename = Str::uuid()->toString().'.'.$ext;
        $rel = $dir.'/'.$basename;
        Storage::disk('public')->put($rel, $contents);

        return $rel;
    }

    protected function kindOverride(string $custom, string $mime, string $fileName): string
    {
        if (in_array($custom, ['image', 'video'], true)) {
            return $custom;
        }

        if ($mime !== '' && str_starts_with($mime, 'video/')) {
            return 'video';
        }

        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) ? 'video' : 'image';
    }

    protected function extensionFor(string $kind, string $mime, string $fileName): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-m4v' => 'm4v',
        ];

        if (isset($map[$mime])) {
            return $map[$mime];
        }

        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return $kind === 'video' ? 'mp4' : 'jpg';
    }

    protected function assertOwned(MarketingCampaign $campaign, Product $product): void
    {
        abort_unless((int) $campaign->store_id === (int) $product->store_id, 404, 'Recurso no encontrado.');
    }

    protected function assertMedia(MarketingCampaign $campaign, Product $product, CampaignProductMedia $media): void
    {
        abort_unless(
            (int) $media->marketing_campaign_id === (int) $campaign->id
                && (int) $media->product_id === (int) $product->id
                && (int) $media->store_id === (int) $campaign->store_id,
            404,
            'Recurso no encontrado.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function export(CampaignProductMedia $media): array
    {
        return [
            'id' => $media->id,
            'store_id' => $media->store_id,
            'campaign_id' => $media->marketing_campaign_id,
            'product_id' => $media->product_id,
            'kind' => $media->kind,
            'url' => $media->url(),
            'original_name' => $media->original_name,
            'mime' => $media->mime,
            'size' => $media->size,
            'metadata' => $media->metadata,
            'created_at' => optional($media->created_at)->toISOString(),
            'updated_at' => optional($media->updated_at)->toISOString(),
        ];
    }
}