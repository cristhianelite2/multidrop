<?php

namespace App\Domain\Suppliers\Cj;

use App\Domain\Scoring\CjPricingEstimator;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CjProductSyncService
{
    public function __construct(
        protected CjConnector $connector,
        protected CjPricingEstimator $pricing,
    ) {}

    /**
     * Trae el detalle completo de CJ (sin country filter) + videos.
     *
     * @return array{success: bool, detail?: array<string, mixed>, error?: string}
     */
    public function fetchFullDetail(string $pid): array
    {
        $pid = trim($pid);
        if ($pid === '') {
            return ['success' => false, 'error' => 'PID vacío'];
        }

        if (! config('cj.access_token') && config('cj.api_key')) {
            $this->connector->authorizeWithApiKey(config('cj.api_key'));
        }

        $result = $this->connector->queryProductDetail(['pid' => $pid]);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? $result['message'] ?? 'CJ no devolvió el producto',
            ];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($data === []) {
            return ['success' => false, 'error' => 'Producto no encontrado en CJ'];
        }

        $rawVariants = $data['variants'] ?? null;
        if (! is_array($rawVariants) || $rawVariants === []) {
            $vr = $this->connector->getVariants($pid);
            if ($vr['success'] ?? false) {
                $list = $vr['data']['list'] ?? $vr['data'] ?? [];
                if (is_array($list) && $list !== []) {
                    $data['variants'] = $list;
                }
            }
        }

        $detail = $this->connector->normalizeProductDetailRich($data);
        $detail = $this->connector->enrichDetailWithMedia($detail, $pid);

        return ['success' => true, 'detail' => $detail, 'raw' => $data];
    }

    /**
     * Completa videos, galería, descripciones, reseñas y comentarios desde CJ.
     */
    public function ensureMedia(Product $product): Product
    {
        $pid = $product->cjPid();
        if (! $pid) {
            return $product;
        }

        if (! config('cj.access_token') && config('cj.api_key')) {
            $this->connector->authorizeWithApiKey(config('cj.api_key'));
        }

        $verified = is_array($product->verified_data) ? $product->verified_data : [];
        $changed = false;

        $existingVideos = is_array($verified['videos'] ?? null) ? $verified['videos'] : [];
        $needsVideos = $existingVideos === []
            || ! collect($existingVideos)->contains(fn ($v) => is_array($v) && ! empty($v['url']));

        if ($needsVideos) {
            $videos = $this->connector->queryVideosByProductId($pid);
            if ($videos['success'] ?? false) {
                $list = array_values($videos['videos'] ?? []);
                if ($list !== []) {
                    $verified['videos'] = $list;
                    $changed = true;
                }
            }
        }

        $existingImages = is_array($verified['images'] ?? null) ? $verified['images'] : [];
        if (count($existingImages) <= 1) {
            $imgs = $this->connector->queryProductImages($pid);
            if ($imgs['success'] ?? false) {
                $list = array_values($imgs['images'] ?? []);
                if (count($list) > count($existingImages)) {
                    $verified['images'] = $list;
                    $changed = true;
                    if (empty($product->image_url) && ! empty($list[0])) {
                        $product->image_url = mb_substr((string) $list[0], 0, 500);
                    }
                }
            }
        }

        // Si product/query trae productVideo IDs pero no resolvimos URLs, reintentar
        if (($verified['videos'] ?? []) === []) {
            $detail = $this->connector->queryProductDetail(['pid' => $pid, 'features' => 'enable_video']);
            $raw = is_array($detail['data'] ?? null) ? $detail['data'] : [];
            $videoIds = $raw['productVideo'] ?? $raw['videoList'] ?? [];
            if (is_array($videoIds) && $videoIds !== []) {
                $videos = $this->connector->queryVideosByProductId($pid);
                if (($videos['success'] ?? false) && ! empty($videos['videos'])) {
                    $verified['videos'] = array_values($videos['videos']);
                    $changed = true;
                }
            }
        }

        $existingReviews = is_array($verified['reviews'] ?? null) ? $verified['reviews'] : [];
        $existingHtml = trim((string) ($verified['description_html'] ?? ''));
        $existingImages = is_array($verified['images'] ?? null) ? $verified['images'] : [];
        $needsRich = empty($verified['content_enriched_at'])
            && (
                $existingHtml === ''
                || count($existingImages) <= 1
                || $existingReviews === []
                || $product->variants()->count() === 0
            );

        if ($needsRich) {
            return $this->enrichFromCj($product);
        }

        if ($changed) {
            $verified['synced_at'] = now()->toIso8601String();
            $product->verified_data = $verified;
            $creative = is_array($product->creative_data) ? $product->creative_data : [];
            $creative['has_video'] = ! empty($verified['videos']);
            $product->creative_data = $creative;
            $product->save();
        }

        return $product->fresh(['variants']) ?? $product;
    }

    /**
     * Re-extrae de CJ el detalle rico (sin resetear precio ni traducciones).
     */
    public function enrichFromCj(Product $product): Product
    {
        $pid = $product->cjPid();
        if (! $pid) {
            return $product;
        }

        $store = $product->store;
        if (! $store) {
            return $product;
        }

        $out = $this->syncToStore($store, $pid, [
            'title' => $product->name,
            'sku' => $product->sku,
            'image' => $product->image_url,
        ]);

        if (! ($out['success'] ?? false) || empty($out['product'])) {
            return $product->fresh(['variants']) ?? $product;
        }

        return $out['product']->load('variants');
    }

    /**
     * Completa productos CJ ya importados que aún no tienen galería/reseñas/descripciones.
     *
     * @return array{ok: int, fail: int, skip: int, errors: list<string>}
     */
    public function enrichExistingCatalog(?int $storeId = null, int $limit = 80): array
    {
        $query = Product::query()
            ->where('verified_data->source', 'cj')
            ->orderBy('id');
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $ok = 0;
        $fail = 0;
        $skip = 0;
        $errors = [];

        $query->limit(max(1, $limit))->each(function (Product $product) use (&$ok, &$fail, &$skip, &$errors) {
            if (! $product->cjPid()) {
                $skip++;

                return;
            }
            try {
                $this->enrichFromCj($product);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $errors[] = '#'.$product->id.' '.$e->getMessage();
            }
        });

        return compact('ok', 'fail', 'skip', 'errors');
    }

    /**
     * Importa o re-sincroniza un producto desde CJ.
     *
     * @param  array<string, mixed>  $hints  Datos opcionales del listado (pricing UI)
     * @return array{success: bool, product?: Product, created?: bool, error?: string}
     */
    public function syncToStore(Store $store, string $pid, array $hints = []): array
    {
        $fetched = $this->fetchFullDetail($pid);
        if (! ($fetched['success'] ?? false)) {
            return ['success' => false, 'error' => $fetched['error'] ?? 'Error CJ'];
        }

        /** @var array<string, mixed> $detail */
        $detail = $fetched['detail'];

        $currency = strtoupper((string) ($store->market?->currency ?: 'MXN'));
        $rates = $this->pricing->rates();
        if (! isset($rates[$currency])) {
            $currency = 'USD';
        }

        $costUsd = isset($hints['cost_usd'])
            ? (float) $hints['cost_usd']
            : (isset($detail['price']) ? (float) $detail['price'] : null);

        $est = $this->pricing->estimate([
            'price' => $costUsd,
            'weight' => $detail['weight'] ?? $detail['packed_weight'] ?? ($hints['weight'] ?? null),
            'free_shipping' => (bool) ($detail['free_shipping'] ?? false),
        ]);

        $sellUsd = isset($hints['sell_usd']) ? (float) $hints['sell_usd'] : ($est['sell_usd'] ?? null);
        $shipUsd = isset($hints['ship_usd']) ? (float) $hints['ship_usd'] : ($est['ship_usd'] ?? null);
        $sellLocal = $sellUsd !== null ? $this->pricing->convert((float) $sellUsd, $currency) : 0;
        $costLocal = $costUsd !== null ? $this->pricing->convert((float) $costUsd, $currency) : null;

        $verified = $this->buildVerifiedPayload($detail, [
            'cost_usd' => $costUsd,
            'ship_usd' => $shipUsd,
            'cost_local' => $costLocal,
            'sell_usd' => $sellUsd,
            'pricing' => $est,
        ]);

        $existing = Product::query()
            ->where('store_id', $store->id)
            ->where('verified_data->cj_pid', $pid)
            ->first();

        return DB::transaction(function () use ($store, $pid, $detail, $hints, $verified, $existing, $currency, $sellLocal, $costUsd) {
            $title = (string) ($hints['title'] ?? $detail['title'] ?? 'Producto CJ');
            $title = mb_substr($title, 0, 190);
            $sku = trim((string) ($hints['sku'] ?? $detail['sku'] ?? ''));
            if ($sku === '') {
                $sku = 'CJ-'.$pid;
            }
            $image = (string) ($hints['image'] ?? $detail['image'] ?? '');
            if ($image === '' && ! empty($detail['images'][0])) {
                $image = (string) $detail['images'][0];
            }

            $description = (string) ($detail['description_html'] ?? $detail['description'] ?? '');
            if ($description === '' && ! empty($detail['description_short'])) {
                $description = (string) $detail['description_short'];
            }

            $creativeBase = [
                'has_video' => (bool) ($detail['has_video'] ?? false),
                'translations' => [],
                'default_locale' => (string) ($store->market?->locale ?: 'es_MX'),
            ];

            if ($existing) {
                $creative = is_array($existing->creative_data) ? $existing->creative_data : [];
                $creative['has_video'] = $creativeBase['has_video'];
                if (! isset($creative['translations']) || ! is_array($creative['translations'])) {
                    $creative['translations'] = [];
                }
                if (empty($creative['default_locale'])) {
                    $creative['default_locale'] = $creativeBase['default_locale'];
                }

                // Preservar imported_at original
                if (! empty(data_get($existing->verified_data, 'imported_at'))) {
                    $verified['imported_at'] = data_get($existing->verified_data, 'imported_at');
                }

                // Si CJ no devolvió reseñas/comentarios, conservar las ya guardadas
                $prevReviews = data_get($existing->verified_data, 'reviews', []);
                if (
                    (empty($verified['reviews']) || ! is_array($verified['reviews']))
                    && is_array($prevReviews)
                    && $prevReviews !== []
                ) {
                    $verified['reviews'] = $prevReviews;
                    $verified['review_count'] = (int) (data_get($existing->verified_data, 'review_count') ?: count($prevReviews));
                    $verified['rating_avg'] = data_get($existing->verified_data, 'rating_avg');
                    $verified['rating_breakdown'] = data_get($existing->verified_data, 'rating_breakdown', []);
                    $verified['reviews_synced_at'] = data_get($existing->verified_data, 'reviews_synced_at');
                }
                $prevComments = data_get($existing->verified_data, 'comments', []);
                if (
                    (empty($verified['comments']) || ! is_array($verified['comments']))
                    && is_array($prevComments)
                    && $prevComments !== []
                ) {
                    $verified['comments'] = $prevComments;
                    $verified['comment_count'] = (int) (data_get($existing->verified_data, 'comment_count') ?: count($prevComments));
                }

                $existing->fill([
                    'sku' => $existing->sku ?: $sku,
                    'image_url' => $image !== '' ? mb_substr($image, 0, 500) : $existing->image_url,
                    'description' => $existing->description ?: mb_substr($description, 0, 20000),
                    'badge' => $existing->badge ?: (! empty($detail['has_video']) ? 'Video' : null),
                    'verified_data' => $verified,
                    'creative_data' => $creative,
                ]);
                // Solo actualizar precio si sigue en draft y precio vacío/cero
                if ($existing->status === 'draft' && (float) $existing->price <= 0 && $sellLocal > 0) {
                    $existing->price = $sellLocal;
                    $existing->currency = $currency;
                }
                $existing->save();
                $product = $existing;
                $created = false;
            } else {
                $slug = $this->uniqueSlug($store->id, Str::slug($title) ?: 'cj-'.$pid);
                $product = Product::create([
                    'store_id' => $store->id,
                    'sku' => $sku,
                    'name' => $title,
                    'slug' => $slug,
                    'image_url' => $image !== '' ? mb_substr($image, 0, 500) : null,
                    'description' => mb_substr($description, 0, 20000) ?: null,
                    'price' => $sellLocal,
                    'compare_at_price' => null,
                    'currency' => $currency,
                    'status' => 'draft',
                    'badge' => ! empty($detail['has_video']) ? 'Video' : null,
                    'stock' => $this->sumVariantStock($detail['variants'] ?? []) ?? 99,
                    'is_featured' => false,
                    'verified_data' => $verified,
                    'creative_data' => $creativeBase,
                ]);
                $created = true;
            }

            $this->syncVariants($product, $detail['variants'] ?? [], $costUsd, $hints['exclude_vids'] ?? []);

            return [
                'success' => true,
                'product' => $product->fresh(['variants']),
                'created' => $created,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $pricingBits
     * @return array<string, mixed>
     */
    public function buildVerifiedPayload(array $detail, array $pricingBits = []): array
    {
        $variants = [];
        foreach (($detail['variants'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $variants[] = [
                'vid' => (string) ($v['vid'] ?? ''),
                'sku' => (string) ($v['sku'] ?? ''),
                'name' => (string) ($v['name'] ?? ''),
                'key' => (string) ($v['key'] ?? ''),
                'price_usd' => $v['price'] ?? null,
                'weight_g' => $v['weight'] ?? null,
                'image' => (string) ($v['image'] ?? ''),
                'stock' => $v['stock'] ?? null,
                'length' => $v['length'] ?? null,
                'width' => $v['width'] ?? null,
                'height' => $v['height'] ?? null,
            ];
        }

        return [
            'source' => 'cj',
            'cj_pid' => (string) ($detail['pid'] ?? ''),
            'cj_url' => $detail['cj_url'] ?? null,
            'product_sku' => (string) ($detail['sku'] ?? ''),
            'category' => (string) ($detail['category'] ?? ''),
            'category_id' => $detail['category_id'] ?? null,
            'product_type' => (string) ($detail['type'] ?? ''),
            'supplier_name' => (string) ($detail['supplier_name'] ?? ''),
            'supplier_id' => $detail['supplier_id'] ?? null,
            'status_cj' => (string) ($detail['status'] ?? ''),
            'listed_num' => $detail['listing_count'] ?? null,
            'sell_price_usd' => $detail['price'] ?? null,
            'suggest_sell_price_usd' => $detail['suggest_sell_price'] ?? null,
            'weight_g' => $detail['weight'] ?? null,
            'packed_weight_g' => $detail['packed_weight'] ?? null,
            'material' => $detail['material'] ?? null,
            'packing' => $detail['packing'] ?? null,
            'product_key' => $detail['product_key'] ?? null,
            'product_props' => $detail['product_props'] ?? null,
            'entry_name' => $detail['entry_name'] ?? null,
            'images' => array_values($detail['images'] ?? []),
            'videos' => array_values($detail['videos'] ?? []),
            'variants' => $variants,
            'reviews' => array_values($detail['reviews'] ?? []),
            'comments' => array_values($detail['comments'] ?? []),
            'review_count' => (int) ($detail['review_count'] ?? count($detail['reviews'] ?? [])),
            'comment_count' => (int) ($detail['comment_count'] ?? count($detail['comments'] ?? [])),
            'rating_avg' => isset($detail['rating_avg']) && $detail['rating_avg'] !== null
                ? (float) $detail['rating_avg']
                : null,
            'rating_breakdown' => is_array($detail['rating_breakdown'] ?? null)
                ? $detail['rating_breakdown']
                : [],
            'reviews_synced_at' => now()->toIso8601String(),
            'content_enriched_at' => now()->toIso8601String(),
            'description_en' => $detail['description'] ?? null,
            'description_short' => $detail['description_short'] ?? null,
            'description_html' => $detail['description_html'] ?? null,
            'description_long' => $detail['description_long'] ?? ($detail['description_html'] ?? null),
            'cost_usd' => $pricingBits['cost_usd'] ?? null,
            'ship_usd' => $pricingBits['ship_usd'] ?? null,
            'cost_local' => $pricingBits['cost_local'] ?? null,
            'sell_usd' => $pricingBits['sell_usd'] ?? null,
            'pricing' => $pricingBits['pricing'] ?? null,
            'synced_at' => now()->toIso8601String(),
            'imported_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  list<string|int>  $hintExclude
     */
    protected function syncVariants(Product $product, array $variants, ?float $fallbackCostUsd, array $hintExclude = []): void
    {
        $excluded = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                data_get($product->creative_data, 'excluded_variant_vids', []) ?: [],
                $hintExclude
            )
        ))));
        if ($hintExclude !== []) {
            $creative = is_array($product->creative_data) ? $product->creative_data : [];
            $creative['excluded_variant_vids'] = $excluded;
            $product->creative_data = $creative;
            $product->save();
        }

        ProductVariant::query()->where('product_id', $product->id)->delete();

        foreach ($variants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $vid = (string) ($v['vid'] ?? '');
            if ($vid !== '' && in_array($vid, $excluded, true)) {
                continue;
            }
            $sku = (string) ($v['sku'] ?? '');
            if ($sku === '' && $vid !== '') {
                $sku = 'VID-'.$vid;
            }
            if ($sku === '') {
                continue;
            }

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => mb_substr($sku, 0, 80),
                'name' => mb_substr((string) ($v['name'] ?: $v['key'] ?: $sku), 0, 190),
                'options' => [
                    'vid' => $vid,
                    'key' => (string) ($v['key'] ?? ''),
                    'image' => (string) ($v['image'] ?? ''),
                    'weight_g' => $v['weight'] ?? null,
                    'stock' => $v['stock'] ?? null,
                    'length' => $v['length'] ?? null,
                    'width' => $v['width'] ?? null,
                    'height' => $v['height'] ?? null,
                    'price_usd' => $v['price'] ?? null,
                ],
                'price' => isset($v['price']) ? (float) $v['price'] : null,
                'cost' => isset($v['price']) ? (float) $v['price'] : $fallbackCostUsd,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    protected function sumVariantStock(array $variants): ?int
    {
        $sum = 0;
        $any = false;
        foreach ($variants as $v) {
            if (! is_array($v) || ! isset($v['stock']) || $v['stock'] === null || $v['stock'] === '') {
                continue;
            }
            $any = true;
            $sum += (int) $v['stock'];
        }

        return $any ? $sum : null;
    }

    protected function uniqueSlug(int $storeId, string $slug): string
    {
        $base = Str::slug($slug) ?: 'producto';
        $candidate = $base;
        $i = 2;
        while (
            Product::query()
                ->where('store_id', $storeId)
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
