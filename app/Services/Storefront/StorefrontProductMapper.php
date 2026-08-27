<?php

namespace App\Services\Storefront;

use App\Domain\Suppliers\Cj\CjVideoProxy;
use App\Models\Product;
use App\Models\Store;

class StorefrontProductMapper
{
    public function __construct(
        protected ProductDescriptionHtml $descriptions,
        protected CjVideoProxy $videoProxy
    ) {}

    /**
     * @param  array{full?: bool, url?: ?string, featured?: bool, is_star?: bool}  $options
     * @return array<string, mixed>
     */
    public function fromProduct(Product $p, ?Store $store = null, array $options = []): array
    {
        $full = (bool) ($options['full'] ?? false);
        $verified = is_array($p->verified_data) ? $p->verified_data : [];
        $creative = is_array($p->creative_data) ? $p->creative_data : [];

        $img = (string) ($p->image_url ?: '');
        if ($img !== '' && str_starts_with($img, '/media/')) {
            $img = asset(ltrim($img, '/'));
        }

        $images = [];
        foreach ($p->galleryImages() as $url) {
            $url = (string) $url;
            if ($url !== '' && str_starts_with($url, '/media/')) {
                $url = asset(ltrim($url, '/'));
            }
            if ($url !== '') {
                $images[] = $url;
            }
        }
        if ($images === [] && $img !== '') {
            $images = [$img];
        }
        if ($img === '' && ! empty($images[0])) {
            $img = $images[0];
        }

        $localized = (string) ($p->localizedDescription() ?: $p->description ?: '');
        $html = trim((string) ($verified['description_html'] ?? $verified['description_long'] ?? ''));
        $copy = $this->descriptions->present(
            $localized,
            $html,
            trim((string) ($verified['description_short'] ?? ''))
        );
        $short = $copy['short'];
        $plain = $copy['plain'];
        $longHtml = $copy['html'] !== '' ? $copy['html'] : ($plain !== '' ? nl2br(e($plain), false) : '');
        if ($this->isRedundantShort($short, $plain)) {
            $short = '';
        }

        $storeCurrency = $store ? $store->currency() : strtoupper((string) ($p->currency ?? 'MXN'));
        $quote = $p->quoteIn($storeCurrency);
        $quote = $this->quoteWithComboPrices($store, $quote, $creative);

        $featured = (bool) ($options['featured'] ?? $p->is_featured);
        $isStar = (bool) ($options['is_star'] ?? ($store?->isStarProduct((int) $p->id) ?? false));
        if ($isStar) {
            $featured = true;
        }

        $url = $options['url'] ?? null;
        if ($url === null && $store) {
            $url = route('store.design.page', ['slug' => $store->slug, 'handle' => $p->slug]);
        }

        $reviews = $p->reviews();
        $comments = $this->commentsDistinctFromReviews($reviews, $p->comments());
        $videos = $this->videos($verified, $creative);
        $variants = $this->variants($p, $full);

        $payload = [
            'id' => (int) $p->id,
            'name' => $p->localizedName(),
            'title' => $p->localizedName(),
            'slug' => $p->slug,
            'handle' => $p->slug,
            'price' => (float) $quote['price'],
            'price_formatted' => $this->money((float) $quote['price']),
            'compare_at_price' => $quote['compare_at_price'] !== null ? (float) $quote['compare_at_price'] : null,
            'compare_at_formatted' => null,
            'on_sale' => false,
            'save_amount' => 0.0,
            'save_percent' => null,
            'currency' => $quote['currency'],
            'badge' => $p->badge,
            'stock' => (int) ($p->stock ?? 0),
            'image' => $img,
            'images' => $full ? $images : array_slice($images, 0, 16),
            'url' => $url,
            'is_featured' => $featured,
            'featured' => $featured,
            'is_star' => $isStar,
            'star' => $isStar,
            'description' => $short !== '' ? $short : mb_substr($plain, 0, 280),
            'description_short' => $short,
            'description_long' => $longHtml ?: $plain,
            'description_html' => $longHtml,
            'summary' => mb_substr($short !== '' ? $short : $plain, 0, 160),
            'status' => (string) ($p->status ?? 'live'),
            'rating_avg' => $p->ratingAvg(),
            'review_count' => $p->reviewCount(),
            'comment_count' => $p->commentCount(),
            'reviews' => $full ? $reviews : array_slice($reviews, 0, 4),
            'comments' => $full ? $comments : array_slice($comments, 0, 4),
            'variants' => $variants,
            'variant_count' => count($variants),
            'has_video' => $videos['has_video'],
            'video_ids' => $videos['video_ids'],
            'videos' => $videos['videos'],
            'video_url' => $videos['video_url'],
            'video_poster' => $videos['video_poster'],
            'combo_prices' => $quote['combo_prices'] ?? data_get($creative, 'combo_prices'),
            'is_combo' => (bool) data_get($creative, 'is_combo', false),
        ];

        return $this->withSaleFields($payload);
    }

    /**
     * @param  array<string, mixed>  $creative
     * @param  array{currency: string, price: float, compare_at_price: float|null, from?: string, converted?: bool, source?: string}  $quote
     * @return array<string, mixed>
     */
    protected function quoteWithComboPrices(?Store $store, array $quote, array $creative): array
    {
        if (! data_get($creative, 'is_combo')) {
            return $quote;
        }

        $summary = data_get($creative, 'combo_prices');
        $comboId = (int) data_get($creative, 'combo_id');
        if ($store && $comboId > 0) {
            $combo = \App\Models\Combo::query()
                ->with(['items.product'])
                ->where('store_id', $store->id)
                ->where('id', $comboId)
                ->first();
            if ($combo) {
                $live = app(\App\Services\Commerce\ComboService::class)->priceSummary($combo, $store);
                if (is_array($live)) {
                    $summary = $live;
                }
            }
        }

        if (! is_array($summary) || (float) ($summary['discounted'] ?? 0) <= 0) {
            $quote['combo_prices'] = is_array($summary) ? $summary : data_get($creative, 'combo_prices');

            return $quote;
        }

        $discounted = (float) $summary['discounted'];
        $compare = (float) ($summary['compare'] ?? $summary['normal'] ?? 0);

        return [
            'currency' => strtoupper((string) ($summary['currency'] ?? $quote['currency'])),
            'price' => $discounted,
            'compare_at_price' => $compare > $discounted ? $compare : ($quote['compare_at_price'] ?? null),
            'from' => $quote['from'] ?? $quote['currency'],
            'converted' => (bool) ($quote['converted'] ?? false),
            'source' => 'combo',
            'combo_prices' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withSaleFields(array $payload): array
    {
        $price = (float) ($payload['price'] ?? 0);
        $compare = isset($payload['compare_at_price']) ? (float) $payload['compare_at_price'] : 0.0;
        $onSale = $compare > $price && $price > 0;
        $payload['on_sale'] = $onSale;
        $payload['compare_at_formatted'] = $compare > 0 ? $this->money($compare) : null;
        $payload['save_amount'] = $onSale ? round($compare - $price, 2) : 0.0;
        $payload['save_percent'] = $onSale ? (int) round((($compare - $price) / $compare) * 100) : null;

        return $payload;
    }

    protected function money(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    /**
     * @param  array<string, mixed>  $verified
     * @param  array<string, mixed>  $creative
     * @return array{has_video: bool, video_ids: list<string>, videos: list<array<string, mixed>>, video_url: ?string, video_poster: ?string}
     */
    protected function videos(array $verified, array $creative): array
    {
        $raw = $verified['videos'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }
        $videos = [];
        $ids = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? $row['play_url'] ?? $row['source_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
            $play = $this->videoProxy->playableUrl($url);
            $videos[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? 'Video'),
                'url' => $play,
                'poster' => (string) ($row['cover'] ?? $row['poster'] ?? ''),
            ];
        }

        return [
            'has_video' => (bool) ($creative['has_video'] ?? false) || $videos !== [],
            'video_ids' => $ids,
            'videos' => $videos,
            'video_url' => $videos[0]['url'] ?? null,
            'video_poster' => $videos[0]['poster'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function variants(Product $p, bool $full): array
    {
        $out = [];
        $variants = $p->relationLoaded('variants') ? $p->variants : $p->variants()->get();
        foreach ($variants as $variant) {
            $opt = is_array($variant->options) ? $variant->options : [];
            $image = trim((string) ($opt['image'] ?? ''));
            $row = [
                'id' => (int) $variant->id,
                'sku' => (string) $variant->sku,
                'name' => (string) $variant->name,
                'vid' => (string) ($opt['vid'] ?? ''),
                'image' => $image,
                'stock' => isset($opt['stock']) ? (int) $opt['stock'] : null,
                'price' => $variant->price !== null ? (float) $variant->price : null,
            ];
            if ($full) {
                $row['key'] = (string) ($opt['key'] ?? '');
                $row['weight_g'] = $opt['weight_g'] ?? null;
            }
            $out[] = $row;
        }

        if ($out === []) {
            $list = data_get($p->verified_data, 'variants', []);
            if (is_array($list)) {
                foreach ($list as $i => $v) {
                    if (! is_array($v)) {
                        continue;
                    }
                    $out[] = [
                        'id' => $i + 1,
                        'sku' => (string) ($v['sku'] ?? ''),
                        'name' => (string) ($v['name'] ?? $v['key'] ?? ''),
                        'vid' => (string) ($v['vid'] ?? ''),
                        'image' => (string) ($v['image'] ?? ''),
                        'stock' => isset($v['stock']) ? (int) $v['stock'] : null,
                        'price' => isset($v['price_usd']) ? (float) $v['price_usd'] : null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * El lede no debe repetir el overview de la descripción larga ni ser una etiqueta de spec ("Color").
     */
    protected function isRedundantShort(string $short, string $plain): bool
    {
        $short = trim($short);
        if ($short === '') {
            return true;
        }
        if (mb_strlen($short) < 24) {
            return true;
        }
        $plain = trim($plain);
        if ($plain === '') {
            return false;
        }

        return str_contains($plain, $short);
    }

    /**
     * @param  list<array<string, mixed>>  $reviews
     * @param  list<array<string, mixed>>  $comments
     * @return list<array<string, mixed>>
     */
    protected function commentsDistinctFromReviews(array $reviews, array $comments): array
    {
        if ($comments === []) {
            return [];
        }
        if ($reviews === []) {
            return array_values(array_filter($comments, fn ($row) => is_array($row)));
        }
        $ids = [];
        foreach ($reviews as $row) {
            $id = trim((string) ($row['comment_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $out = [];
        foreach ($comments as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['comment_id'] ?? ''));
            if ($id !== '' && isset($ids[$id])) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
