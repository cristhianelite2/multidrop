<?php

namespace App\Domain\Suppliers\Cj;

use App\Domain\Scoring\CjPricingEstimator;

class CjProductMatcher
{
    public function __construct(
        protected CjConnector $connector,
        protected CjPricingEstimator $pricing,
    ) {}

    /**
     * @param  array<string, mixed>  $ae  Ficha AliExpress unificada
     * @return list<array<string, mixed>>
     */
    public function match(array $ae, string $countryCode = 'MX'): array
    {
        if (! config('cj.access_token') && config('cj.api_key')) {
            $this->connector->authorizeWithApiKey(config('cj.api_key'));
        }

        $byPid = [];
        $this->mergeSkuHits($byPid, $ae);
        $this->mergeNameHits($byPid, $ae, $countryCode);
        $this->mergeImageHits($byPid, $ae);

        $list = array_values($byPid);
        usort($list, function ($a, $b) {
            $sa = (int) ($a['match_score'] ?? 0);
            $sb = (int) ($b['match_score'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return array_slice($list, 0, 16);
    }

    /**
     * @param  array<string, array<string, mixed>>  $byPid
     * @param  array<string, mixed>  $ae
     */
    protected function mergeSkuHits(array &$byPid, array $ae): void
    {
        $skus = [];
        foreach (($ae['skus'] ?? []) as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '' && ! preg_match('/^\d{10,20}$/', $sku)) {
                $skus[] = $sku;
            }
        }
        $main = trim((string) ($ae['sku'] ?? ''));
        if ($main !== '' && ! preg_match('/^\d{10,20}$/', $main) && ! str_starts_with($main, 'AE-')) {
            $skus[] = $main;
        }
        $skus = array_values(array_unique($skus));

        foreach (array_slice($skus, 0, 8) as $sku) {
            foreach (['productSku', 'variantSku'] as $field) {
                $res = $this->connector->queryProductDetail([$field => $sku]);
                if (! ($res['success'] ?? false)) {
                    continue;
                }
                $data = is_array($res['data'] ?? null) ? $res['data'] : [];
                if ($data === []) {
                    continue;
                }
                $detail = $this->connector->normalizeProductDetailRich($data);
                $item = $this->fromDetail($detail, 'sku', 100);
                if ($item && ($item['pid'] ?? '') !== '') {
                    $this->put($byPid, $item);
                }
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $byPid
     * @param  array<string, mixed>  $ae
     */
    protected function mergeNameHits(array &$byPid, array $ae, string $countryCode): void
    {
        $keyword = $this->keywordFromTitle((string) ($ae['title'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $res = $this->connector->searchProducts([
            'keyword' => $keyword,
            'page' => 1,
            'per_page' => 12,
            'country_code' => $countryCode,
        ]);
        if (! ($res['success'] ?? false)) {
            return;
        }
        $list = is_array(data_get($res, 'data.list')) ? data_get($res, 'data.list') : [];
        $aeTitle = mb_strtolower((string) ($ae['title'] ?? ''));
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = CjConnector::normalizeListItem($row);
            $score = $this->titleScore($aeTitle, mb_strtolower((string) ($item['title'] ?? '')));
            $tagged = $this->withPricing($item, 'name', $score);
            if ($tagged && ($tagged['pid'] ?? '') !== '') {
                $this->put($byPid, $tagged);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $byPid
     * @param  array<string, mixed>  $ae
     */
    protected function mergeImageHits(array &$byPid, array $ae): void
    {
        $image = (string) ($ae['image'] ?? ($ae['images'][0] ?? ''));
        if ($image === '' || ! preg_match('#^https?://#i', $image)) {
            return;
        }

        $hits = $this->connector->searchByImage($image);
        foreach ($hits as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = isset($row['title']) ? $row : CjConnector::normalizeListItem($row);
            $tagged = $this->withPricing($item, 'image', 80);
            if ($tagged && ($tagged['pid'] ?? '') !== '') {
                $this->put($byPid, $tagged);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    protected function fromDetail(array $detail, string $matchBy, int $score): ?array
    {
        $pid = (string) ($detail['pid'] ?? '');
        if ($pid === '') {
            return null;
        }
        $item = [
            'pid' => $pid,
            'sku' => (string) ($detail['sku'] ?? ''),
            'title' => (string) ($detail['title'] ?? 'Producto CJ'),
            'image' => (string) ($detail['image'] ?? ($detail['images'][0] ?? '')),
            'images' => $detail['images'] ?? [],
            'price' => $detail['price'] ?? null,
            'weight' => $detail['weight'] ?? $detail['packed_weight'] ?? null,
            'category' => (string) ($detail['category'] ?? ''),
            'has_video' => (bool) ($detail['has_video'] ?? false),
            'cj_url' => $detail['cj_url'] ?? null,
            'free_shipping' => (bool) ($detail['free_shipping'] ?? false),
        ];

        return $this->withPricing($item, $matchBy, $score);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function withPricing(array $item, string $matchBy, int $score): array
    {
        $item['match_by'] = $matchBy;
        $item['match_score'] = $score;
        $item['pricing'] = $this->pricing->estimate($item);

        return $item;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byPid
     * @param  array<string, mixed>  $item
     */
    protected function put(array &$byPid, array $item): void
    {
        $pid = (string) ($item['pid'] ?? '');
        if ($pid === '') {
            return;
        }
        if (! isset($byPid[$pid])) {
            $byPid[$pid] = $item;

            return;
        }
        $prev = $byPid[$pid];
        $rank = ['sku' => 3, 'image' => 2, 'name' => 1];
        $prevRank = $rank[$prev['match_by'] ?? ''] ?? 0;
        $newRank = $rank[$item['match_by'] ?? ''] ?? 0;
        if ($newRank > $prevRank || ((int) ($item['match_score'] ?? 0) > (int) ($prev['match_score'] ?? 0) && $newRank === $prevRank)) {
            $item['match_by'] = implode('+', array_unique(array_filter([
                (string) ($prev['match_by'] ?? ''),
                (string) ($item['match_by'] ?? ''),
            ])));
            $item['match_score'] = max((int) ($prev['match_score'] ?? 0), (int) ($item['match_score'] ?? 0));
            $byPid[$pid] = $item;
        } else {
            $byPid[$pid]['match_by'] = implode('+', array_unique(array_filter([
                (string) ($prev['match_by'] ?? ''),
                (string) ($item['match_by'] ?? ''),
            ])));
            $byPid[$pid]['match_score'] = max((int) ($prev['match_score'] ?? 0), (int) ($item['match_score'] ?? 0));
        }
    }

    protected function keywordFromTitle(string $title): string
    {
        $title = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? $title);
        $stop = ['para', 'con', 'los', 'las', 'del', 'una', 'the', 'and', 'for', 'with', 'de', 'en', 'el', 'la', 'un'];
        $words = preg_split('/\s+/', mb_strtolower($title)) ?: [];
        $keep = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3 || in_array($w, $stop, true)) {
                continue;
            }
            $keep[] = $w;
            if (count($keep) >= 7) {
                break;
            }
        }

        return trim(implode(' ', $keep));
    }

    protected function titleScore(string $ae, string $cj): int
    {
        if ($ae === '' || $cj === '') {
            return 40;
        }
        similar_text($ae, $cj, $pct);
        $score = (int) round($pct);
        $aeTokens = array_filter(preg_split('/\s+/', $ae) ?: []);
        $hits = 0;
        foreach ($aeTokens as $t) {
            if (mb_strlen($t) >= 4 && str_contains($cj, $t)) {
                $hits++;
            }
        }
        if (count($aeTokens) > 0) {
            $score = max($score, (int) round(55 + (40 * $hits / max(1, min(8, count($aeTokens))))));
        }

        return max(35, min(92, $score));
    }
}
