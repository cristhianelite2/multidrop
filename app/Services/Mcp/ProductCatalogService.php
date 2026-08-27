<?php

namespace App\Services\Mcp;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Str;

/**
 * Lectura de catálogo para el MCP. Usa el modelo Product (misma capa que el admin),
 * sin tocar clientes, pedidos ni credenciales.
 */
class ProductCatalogService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        $limit = max(1, min(20, $limit));
        if ($query === '') {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';

        $products = Product::query()
            ->with(['store'])
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $products->map(fn (Product $p) => $this->toDto($p))->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $productId): ?array
    {
        if ($productId < 1) {
            return null;
        }

        $product = Product::query()
            ->with(['store'])
            ->find($productId);

        return $product ? $this->toDto($product) : null;
    }

    /**
     * Solo campos existentes. Nunca inventa valores.
     *
     * @return array<string, mixed>
     */
    protected function toDto(Product $p): array
    {
        $verified = is_array($p->verified_data) ? $p->verified_data : [];
        $dto = [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
        ];

        $description = $this->firstNonEmpty([
            $p->localizedDescription(),
            $p->description,
            $verified['description_short'] ?? null,
        ]);
        if ($description !== null) {
            $dto['description'] = Str::limit(trim(strip_tags((string) $description)), 2000, '');
        }

        if ($p->price !== null && $p->price !== '') {
            $dto['sale_price'] = (float) $p->price;
            $dto['currency'] = strtoupper((string) ($p->currency ?: 'MXN'));
        }

        $cost = $verified['cost_usd'] ?? $verified['sell_price_usd'] ?? null;
        if ($cost !== null && $cost !== '') {
            $dto['supplier_cost'] = (float) $cost;
            $dto['supplier_cost_currency'] = 'USD';
        }

        $sku = trim((string) ($p->sku ?: ($verified['product_sku'] ?? '')));
        if ($sku !== '') {
            $dto['sku'] = $sku;
        }

        if ($p->stock !== null) {
            $dto['stock'] = (int) $p->stock;
        }

        $category = $this->firstNonEmpty([
            $verified['category'] ?? null,
        ]);
        if ($category !== null) {
            $dto['category'] = $category;
        }

        $url = $this->publicUrl($p);
        if ($url !== null) {
            $dto['public_url'] = $url;
        }

        $image = trim((string) ($p->image_url ?: ''));
        if ($image !== '') {
            if (str_starts_with($image, '/')) {
                $image = asset(ltrim($image, '/'));
            }
            $dto['image_url'] = $image;
        }

        $supplier = $this->firstNonEmpty([
            $verified['supplier_name'] ?? null,
            ($verified['source'] ?? '') === 'cj' ? 'CJ Dropshipping' : null,
        ]);
        if ($supplier !== null) {
            $dto['supplier_name'] = $supplier;
        }

        if ($p->store) {
            $dto['store_id'] = (int) $p->store->id;
            $dto['store_name'] = (string) $p->store->name;
            $dto['store_slug'] = (string) $p->store->slug;
        }

        $status = trim((string) ($p->status ?? ''));
        if ($status !== '') {
            $dto['status'] = $status;
        }

        return $dto;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    protected function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    protected function publicUrl(Product $p): ?string
    {
        $store = $p->store;
        $slug = trim((string) ($p->slug ?? ''));
        if (! $store instanceof Store || $slug === '') {
            return null;
        }

        try {
            return route('store.design.page', ['slug' => $store->slug, 'handle' => $slug]);
        } catch (\Throwable) {
            return null;
        }
    }
}
