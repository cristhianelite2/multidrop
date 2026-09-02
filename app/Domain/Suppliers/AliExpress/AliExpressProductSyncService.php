<?php

namespace App\Domain\Suppliers\AliExpress;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Currency\CurrencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AliExpressProductSyncService
{
    /**
     * @param  array<string, mixed>  $detail  Ficha unificada del fetcher
     * @param  array<string, mixed>  $hints
     * @return array{success: bool, product?: Product, created?: bool, error?: string}
     */
    public function syncToStore(Store $store, array $detail, array $hints = []): array
    {
        $aeId = (string) ($detail['product_id'] ?? '');
        if ($aeId === '') {
            return ['success' => false, 'error' => 'Falta product_id de AliExpress'];
        }

        $currency = strtoupper((string) ($store->market?->currency ?: ($detail['currency'] ?? 'MXN')));
        $marketPrice = isset($detail['price']) ? (float) $detail['price'] : 0;
        $price = isset($hints['sell']) ? (float) $hints['sell'] : 0.0;
        $compare = isset($detail['compare_at_price']) ? (float) $detail['compare_at_price'] : null;

        $fx = app(CurrencyService::class);
        $srcCurrency = strtoupper((string) ($detail['currency'] ?? 'USD'));
        $purchaseLocal = $marketPrice > 0
            ? $fx->roundAmount($fx->convert($marketPrice, $srcCurrency, $currency, false), $currency)
            : null;

        $verified = $this->buildVerifiedPayload($detail);
        $existing = Product::query()
            ->where('store_id', $store->id)
            ->where('verified_data->aliexpress_product_id', $aeId)
            ->first();

        $result = DB::transaction(function () use ($store, $aeId, $detail, $hints, $verified, $existing, $currency, $price, $compare, $purchaseLocal) {
            $title = (string) ($hints['title'] ?? $detail['title'] ?? 'Producto AliExpress');
            $title = mb_substr($title, 0, 190);
            $sku = trim((string) ($hints['sku'] ?? $detail['sku'] ?? ''));
            if ($sku === '') {
                $sku = 'AE-'.$aeId;
            }
            $image = (string) ($hints['image'] ?? $detail['image'] ?? '');
            if ($image === '' && ! empty($detail['images'][0])) {
                $image = (string) $detail['images'][0];
            }
            $description = app(\App\Services\Storefront\ProductDescriptionHtml::class)->normalizeSpaces(
                (string) ($detail['description_html'] ?? $detail['description'] ?? '')
            );
            $creativeBase = [
                'has_video' => (bool) ($detail['has_video'] ?? false),
                'translations' => [],
                'default_locale' => (string) ($store->market?->locale ?: 'es_MX'),
                'fulfillment' => 'manual',
            ];

            if ($existing) {
                $creative = is_array($existing->creative_data) ? $existing->creative_data : [];
                $creative['has_video'] = $creativeBase['has_video'];
                $creative['fulfillment'] = 'manual';
                if (! empty(data_get($existing->verified_data, 'imported_at'))) {
                    $verified['imported_at'] = data_get($existing->verified_data, 'imported_at');
                }
                $existing->fill([
                    'sku' => $existing->sku ?: $sku,
                    'image_url' => $image !== '' ? mb_substr($image, 0, 500) : $existing->image_url,
                    'description' => $existing->description ?: mb_substr($description, 0, 20000),
                    'badge' => $existing->badge ?: (! empty($detail['has_video']) ? 'Video' : 'AliExpress'),
                    'purchase_price' => $purchaseLocal,
                    'verified_data' => $verified,
                    'creative_data' => $creative,
                ]);
                if ($existing->status === 'draft' && (float) $existing->price <= 0 && $price > 0) {
                    $existing->price = $price;
                    $existing->currency = $currency;
                    if ($compare && $compare > $price) {
                        $existing->compare_at_price = $compare;
                    }
                }
                $existing->save();
                $product = $existing;
                $created = false;
            } else {
                $product = Product::create([
                    'store_id' => $store->id,
                    'sku' => mb_substr($sku, 0, 80),
                    'name' => $title,
                    'slug' => $this->uniqueSlug($store->id, Str::slug($title) ?: 'ae-'.$aeId),
                    'image_url' => $image !== '' ? mb_substr($image, 0, 500) : null,
                    'description' => mb_substr($description, 0, 20000) ?: null,
                    'price' => $price > 0 ? $price : 0,
                    'compare_at_price' => ($price > 0 && $compare && $compare > $price) ? $compare : null,
                    'purchase_price' => $purchaseLocal,
                    'currency' => $currency,
                    'status' => 'draft',
                    'badge' => ! empty($detail['has_video']) ? 'Video' : 'AliExpress',
                    'stock' => $this->sumVariantStock($detail['variants'] ?? []) ?? 99,
                    'is_featured' => false,
                    'verified_data' => $verified,
                    'creative_data' => $creativeBase,
                ]);
                $created = true;
            }

            $this->syncVariants($product, is_array($detail['variants'] ?? null) ? $detail['variants'] : []);

            return [
                'success' => true,
                'product' => $product->fresh(['variants']),
                'created' => $created,
            ];
        });

        if (($result['success'] ?? false) && isset($result['product'])) {
            $result['product'] = app(\App\Services\Storage\ProductMediaMirrorService::class)
                ->mirrorProduct($result['product']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public function buildVerifiedPayload(array $detail): array
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
                'price' => $v['price'] ?? null,
                'image' => (string) ($v['image'] ?? ''),
                'stock' => $v['stock'] ?? null,
            ];
        }

        return [
            'source' => 'aliexpress',
            'aliexpress_product_id' => (string) ($detail['product_id'] ?? ''),
            'aliexpress_url' => $this->normalizeAliExpressUrl(
                (string) ($detail['url'] ?? ''),
                (string) ($detail['product_id'] ?? '')
            ),
            'product_sku' => (string) ($detail['sku'] ?? ''),
            'skus' => array_values($detail['skus'] ?? []),
            'category' => (string) ($detail['category'] ?? ''),
            'images' => array_values($detail['images'] ?? []),
            'videos' => array_values($detail['videos'] ?? []),
            'variants' => $variants,
            'description_short' => $detail['description_short'] ?? null,
            'description_html' => $detail['description_html'] ?? null,
            'source_mode' => $detail['source_mode'] ?? null,
            'source_note' => $detail['source_note'] ?? null,
            'shop_name' => $detail['shop_name'] ?? null,
            'rating' => $detail['rating'] ?? null,
            'rating_avg' => isset($detail['rating']) ? (float) $detail['rating'] : (isset($detail['rating_avg']) ? (float) $detail['rating_avg'] : null),
            'review_count' => (int) ($detail['review_count'] ?? count($detail['reviews'] ?? [])),
            'reviews' => array_values(is_array($detail['reviews'] ?? null) ? $detail['reviews'] : []),
            'comments' => array_values(array_filter(
                is_array($detail['reviews'] ?? null) ? $detail['reviews'] : [],
                fn ($r) => is_array($r) && (trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images']))
            )),
            'comment_count' => count(array_filter(
                is_array($detail['reviews'] ?? null) ? $detail['reviews'] : [],
                fn ($r) => is_array($r) && (trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images']))
            )),
            'details' => array_values(is_array($detail['details'] ?? null) ? $detail['details'] : []),
            'currency' => $detail['currency'] ?? null,
            'price' => $detail['price'] ?? null,
            'compare_at_price' => $detail['compare_at_price'] ?? null,
            'fulfillment' => 'manual',
            'synced_at' => now()->toIso8601String(),
            'imported_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    protected function syncVariants(Product $product, array $variants): void
    {
        ProductVariant::query()->where('product_id', $product->id)->delete();
        foreach ($variants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $sku = (string) ($v['sku'] ?? $v['vid'] ?? '');
            if ($sku === '') {
                continue;
            }
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => mb_substr($sku, 0, 80),
                'name' => mb_substr((string) ($v['name'] ?: $v['key'] ?: $sku), 0, 190),
                'options' => [
                    'vid' => (string) ($v['vid'] ?? ''),
                    'key' => (string) ($v['key'] ?? ''),
                    'image' => (string) ($v['image'] ?? ''),
                    'stock' => $v['stock'] ?? null,
                    'source' => 'aliexpress',
                ],
                'price' => isset($v['price']) ? (float) $v['price'] : null,
                'cost' => isset($v['price']) ? (float) $v['price'] : null,
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

    protected function normalizeAliExpressUrl(string $url, string $productId): ?string
    {
        $url = trim($url);
        $productId = preg_replace('/\D+/', '', $productId) ?? '';
        if (AliExpressProductFetcher::isProductPageUrl($url)) {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'www.aliexpress.com');

            return AliExpressProductFetcher::canonicalProductUrl(
                AliExpressProductFetcher::parseProductId($url) ?: $productId,
                $host
            );
        }
        if ($productId !== '' && preg_match('/^\d{10,20}$/', $productId)) {
            return AliExpressProductFetcher::canonicalProductUrl($productId);
        }

        return $url !== '' ? $url : null;
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
