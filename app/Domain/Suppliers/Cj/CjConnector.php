<?php

namespace App\Domain\Suppliers\Cj;

use App\Domain\Suppliers\Contracts\SupplierInterface;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CjConnector implements SupplierInterface
{
    public function code(): string
    {
        return 'cj';
    }

    public function searchProducts(array $filters): array
    {
        $keyword = $filters['keyword'] ?? $filters['q'] ?? $filters['keyWord'] ?? null;
        $page = max(1, (int) ($filters['page'] ?? $filters['pageNum'] ?? 1));
        $size = min(100, max(1, (int) ($filters['per_page'] ?? $filters['pageSize'] ?? $filters['size'] ?? 20)));
        $country = strtoupper((string) ($filters['country_code'] ?? $filters['countryCode'] ?? ''));
        if ($country === 'UK') {
            $country = 'GB';
        }

        $query = array_filter([
            'keyWord' => $keyword,
            'page' => $page,
            'size' => $size,
            'categoryId' => $filters['category_id'] ?? $filters['categoryId'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // countryCode filtra inventario local. MX (y similares) suelen devolver 0 o basura
        // en /product/list antiguo; en listV2 MX=0. Solo aplicar países con almacén CJ típico.
        if ($country !== '' && $this->supportsInventoryCountry($country)) {
            $query['countryCode'] = $country;
        }

        $result = $this->request('GET', '/product/listV2', $query);

        // Si el filtro de país vació resultados, reintentar búsqueda global por keyword
        if (($result['success'] ?? false) && $this->isEmptyListV2($result) && isset($query['countryCode'])) {
            unset($query['countryCode']);
            $result = $this->request('GET', '/product/listV2', $query);
            $result['country_fallback'] = true;
        }

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $normalized = $this->normalizeListV2Payload($result);

        return array_merge($result, [
            'success' => true,
            'data' => $normalized,
        ]);
    }

    /**
     * Países donde CJ suele tener inventario filtrable; el resto se busca global.
     */
    protected function supportsInventoryCountry(string $code): bool
    {
        return in_array($code, [
            'US', 'CN', 'GB', 'DE', 'FR', 'ES', 'IT', 'AU', 'CA', 'JP',
            'TH', 'VN', 'ID', 'MY', 'SG', 'PH', 'KR', 'PL', 'NL', 'BE',
        ], true);
    }

    protected function isEmptyListV2(array $result): bool
    {
        $total = (int) (data_get($result, 'data.totalRecords')
            ?? data_get($result, 'data.total')
            ?? 0);
        $list = $this->extractListV2Rows($result['data'] ?? []);

        return $total <= 0 && $list === [];
    }

    /**
     * @param  array<string, mixed>  $resultData
     * @return array{list: array<int, array>, total: int, pageNum: int, pageSize: int}
     */
    protected function normalizeListV2Payload(array $result): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $rows = $this->extractListV2Rows($data);
        $list = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapCatalogFields($row);
            $normalized = self::normalizeListItem($mapped);
            if ($this->isJunkCatalogItem($normalized)) {
                continue;
            }
            $list[] = $mapped;
        }

        return [
            'list' => $list,
            'total' => (int) ($data['totalRecords'] ?? $data['total'] ?? count($list)),
            'pageNum' => (int) ($data['pageNumber'] ?? $data['pageNum'] ?? 1),
            'pageSize' => (int) ($data['pageSize'] ?? count($list)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    protected function extractListV2Rows(array $data): array
    {
        if (isset($data['list']) && is_array($data['list'])) {
            return $data['list'];
        }

        $rows = [];
        $content = $data['content'] ?? null;
        if (! is_array($content)) {
            return [];
        }

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (isset($block['productList']) && is_array($block['productList'])) {
                foreach ($block['productList'] as $item) {
                    $rows[] = $item;
                }
            } elseif (isset($block['id']) || isset($block['pid']) || isset($block['nameEn'])) {
                $rows[] = $block;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapCatalogFields(array $row): array
    {
        if (! isset($row['pid']) && isset($row['id'])) {
            $row['pid'] = (string) $row['id'];
        }
        if (! isset($row['productNameEn']) && isset($row['nameEn'])) {
            $row['productNameEn'] = (string) $row['nameEn'];
        }
        if (! isset($row['productSku']) && isset($row['sku'])) {
            $row['productSku'] = (string) $row['sku'];
        }
        if (! isset($row['productImage']) && isset($row['bigImage'])) {
            $row['productImage'] = (string) $row['bigImage'];
        }
        if (! isset($row['categoryName'])) {
            $row['categoryName'] = (string) ($row['threeCategoryName'] ?? $row['twoCategoryName'] ?? $row['oneCategoryName'] ?? '');
        }
        if (isset($row['sellPrice']) && is_string($row['sellPrice']) && str_contains($row['sellPrice'], '--')) {
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $row['sellPrice'], $m)) {
                $row['sellPrice'] = (float) $m[1];
            }
        }
        if (! empty($row['productUrl'])) {
            $row['cj_url'] = (string) $row['productUrl'];
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function isJunkCatalogItem(array $item): bool
    {
        $title = Str::lower((string) ($item['title'] ?? ''));
        if ($title === '' || $title === 'producto cj') {
            return true;
        }

        foreach ([
            'self-pickup',
            'self pickup',
            'only self pickup',
            'for pick-up only',
            'for pickup only',
            'pick-up only',
            'pickup only',
        ] as $needle) {
            if (str_contains($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * URL pública del producto en CJ.
     */
    public static function publicProductUrl(string $pid, ?string $nameEn = null): string
    {
        $slug = self::slugify($nameEn ?: 'product');

        return 'https://cjdropshipping.com/product/'.$slug.'-p-'.$pid.'.html';
    }

    public static function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? $text : 'product';
    }

    /**
     * CJ a veces manda peso como rango "210.00-272.00".
     * Usa el máximo para estimar envío de forma conservadora.
     */
    public static function normalizeWeight(mixed $weight): ?float
    {
        if ($weight === null || $weight === '') {
            return null;
        }
        if (is_int($weight) || is_float($weight)) {
            return (float) $weight;
        }
        if (is_numeric($weight)) {
            return (float) $weight;
        }

        $str = trim((string) $weight);
        if (preg_match('/(\d+(?:\.\d+)?)\s*[-–—]\s*(\d+(?:\.\d+)?)/', $str, $m)) {
            return max((float) $m[1], (float) $m[2]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)/', $str, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * Normaliza un ítem de product/list para la UI.
     */
    public static function normalizeListItem(array $item): array
    {
        $pid = (string) ($item['pid'] ?? '');
        $nameEn = (string) ($item['productNameEn'] ?? '');
        $rawName = $item['productName'] ?? null;
        $nameLocal = null;
        if (is_string($rawName) && str_starts_with(trim($rawName), '[')) {
            $decoded = json_decode($rawName, true);
            if (is_array($decoded) && isset($decoded[0])) {
                $nameLocal = (string) $decoded[0];
            }
        } elseif (is_array($rawName) && isset($rawName[0])) {
            $nameLocal = (string) $rawName[0];
        } elseif (is_string($rawName) && $rawName !== '') {
            $nameLocal = $rawName;
        }

        $title = $nameEn !== '' ? $nameEn : ($nameLocal ?: 'Producto CJ');

        $videoList = $item['videoList'] ?? [];
        if (! is_array($videoList)) {
            $videoList = [];
        }
        $hasVideo = (int) ($item['isVideo'] ?? $item['isVedio'] ?? 0) === 1
            || $videoList !== []
            || ! empty($item['has_video']);

        return [
            'pid' => $pid,
            'sku' => (string) ($item['productSku'] ?? ''),
            'title' => $title,
            'title_local' => $nameLocal,
            'image' => (string) ($item['productImage'] ?? ''),
            'price' => isset($item['sellPrice']) ? (float) $item['sellPrice'] : null,
            'weight' => self::normalizeWeight($item['productWeight'] ?? null),
            'category' => (string) ($item['categoryName'] ?? ''),
            'type' => (string) ($item['productType'] ?? ''),
            'free_shipping' => (bool) ($item['isFreeShipping'] ?? false),
            'listing_count' => (int) ($item['listingCount'] ?? $item['listedNum'] ?? 0),
            'has_video' => $hasVideo,
            'video_ids' => array_values(array_filter(array_map('strval', $videoList))),
            'images' => array_values(array_filter([(string) ($item['productImage'] ?? '')])),
            'image_count' => ((string) ($item['productImage'] ?? '') !== '') ? 1 : 0,
            'cj_url' => ! empty($item['cj_url'])
                ? (string) $item['cj_url']
                : ($pid !== '' ? self::publicProductUrl($pid, $title) : null),
        ];
    }

    public function getProduct(string $externalId): array
    {
        return $this->request('GET', '/product/query', ['pid' => $externalId]);
    }

    /**
     * Detalle CJ por pid / productSku / variantSku.
     *
     * @param  array{pid?: string, productSku?: string, variantSku?: string, countryCode?: string}  $params
     */
    public function queryProductDetail(array $params): array
    {
        $query = array_filter([
            'pid' => $params['pid'] ?? null,
            'productSku' => $params['productSku'] ?? null,
            'variantSku' => $params['variantSku'] ?? null,
            'countryCode' => $params['countryCode'] ?? null,
            'features' => $params['features'] ?? 'enable_video',
        ], fn ($v) => $v !== null && $v !== '');

        if (! isset($query['pid']) && ! isset($query['productSku']) && ! isset($query['variantSku'])) {
            return ['success' => false, 'error' => 'Indica pid, productSku o variantSku'];
        }

        return $this->request('GET', '/product/query', $query);
    }

    /**
     * Extrae pid / SKU desde URL pública CJ, UUID/numérico suelto o SKU.
     * URLs nuevas usan PID numérico: …-p-1669594923043139584.html
     * URLs legacy usan UUID: …-p-000B9312-456A-….html
     *
     * @return array{type: 'pid'|'productSku'|'variantSku', value: string, source: string}|null
     */
    public static function parseProductRef(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $uuid = '([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})';
        $numericPid = '(\d{10,24})';

        if (preg_match('/[?&]pid=([^&#]+)/i', $input, $m)) {
            $pid = trim(urldecode($m[1]));
            if (preg_match('/^'.$uuid.'$/i', $pid, $um)) {
                return ['type' => 'pid', 'value' => $um[1], 'source' => 'query'];
            }
            if (preg_match('/^'.$numericPid.'$/', $pid, $nm)) {
                return ['type' => 'pid', 'value' => $nm[1], 'source' => 'query'];
            }
            if ($pid !== '') {
                return ['type' => 'pid', 'value' => $pid, 'source' => 'query'];
            }
        }

        if (preg_match('/[?&](?:productSku|sku)=([^&#]+)/i', $input, $m)) {
            $sku = trim(urldecode($m[1]));
            if ($sku !== '') {
                return ['type' => 'productSku', 'value' => $sku, 'source' => 'query'];
            }
        }

        if (preg_match('/[?&]variantSku=([^&#]+)/i', $input, $m)) {
            $sku = trim(urldecode($m[1]));
            if ($sku !== '') {
                return ['type' => 'variantSku', 'value' => $sku, 'source' => 'query'];
            }
        }

        // …/product/slug-p-{UUID|NUMERIC}.html
        if (preg_match('/-p-'.$uuid.'(?:\.html)?(?:[?#]|$)/i', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'url_path'];
        }
        if (preg_match('/-p-'.$numericPid.'(?:\.html)?(?:[?#]|$)/i', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'url_path'];
        }

        // …/product/{UUID|NUMERIC}.html
        if (preg_match('/\/product\/'.$uuid.'(?:\.html)?(?:[?#]|$)/i', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'url_path'];
        }
        if (preg_match('/\/product\/'.$numericPid.'(?:\.html)?(?:[?#]|$)/i', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'url_path'];
        }

        if (preg_match('/^'.$uuid.'$/i', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'raw_pid'];
        }
        if (preg_match('/^'.$numericPid.'$/', $input, $m)) {
            return ['type' => 'pid', 'value' => $m[1], 'source' => 'raw_pid'];
        }

        // SKU suelto (sin espacios, no URL)
        if (! preg_match('#^https?://#i', $input) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{2,79}$/', $input)) {
            return ['type' => 'productSku', 'value' => $input, 'source' => 'raw_sku'];
        }

        return null;
    }

    /**
     * Resuelve referencia desde HTML público de CJ si la URL no trae PID explícito.
     *
     * @return array{type: 'pid'|'productSku'|'variantSku', value: string, source: string}|null
     */
    public function resolveProductRefFromHtml(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $allowed = ['cjdropshipping.com', 'cjdropshipping.cn'];
        if (! in_array($host, $allowed, true)) {
            return null;
        }

        try {
            $finalUrl = $url;
            $response = Http::timeout(25)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; MultidropBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'on_stats' => function (\GuzzleHttp\TransferStats $stats) use (&$finalUrl) {
                        $finalUrl = (string) $stats->getEffectiveUri();
                    },
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $fromFinal = self::parseProductRef($finalUrl);
            if ($fromFinal) {
                $fromFinal['source'] = 'html_redirect';

                return $fromFinal;
            }

            $html = $response->body();
            $uuid = '([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})';
            $numericPid = '(\d{10,24})';

            if (preg_match('/"pid"\s*:\s*"'.$uuid.'"/i', $html, $m)
                || preg_match('/"productId"\s*:\s*"'.$uuid.'"/i', $html, $m)
                || preg_match('/-p-'.$uuid.'/i', $html, $m)
                || preg_match('/"pid"\s*:\s*"'.$numericPid.'"/i', $html, $m)
                || preg_match('/"productId"\s*:\s*"'.$numericPid.'"/i', $html, $m)
                || preg_match('/-p-'.$numericPid.'/i', $html, $m)) {
                return ['type' => 'pid', 'value' => $m[1], 'source' => 'html_body'];
            }

            if (preg_match('/"productSku"\s*:\s*"([^"]+)"/i', $html, $m)) {
                $sku = trim($m[1]);
                if ($sku !== '') {
                    return ['type' => 'productSku', 'value' => $sku, 'source' => 'html_body'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CJ HTML crawl failed', ['url' => $url, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Crawl: URL/PID/SKU → detalle normalizado vía API CJ.
     *
     * @return array{success: bool, product?: array<string, mixed>, ref?: array, error?: string}
     */
    public function crawlProductFromInput(string $input, ?string $countryCode = null): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['success' => false, 'error' => 'Pega una URL de CJ, un PID o un SKU'];
        }

        $ref = self::parseProductRef($input);
        $usedHtml = false;

        if (! $ref && preg_match('#^https?://#i', $input)) {
            $ref = $this->resolveProductRefFromHtml($input);
            $usedHtml = (bool) $ref;
        }

        if (! $ref) {
            return [
                'success' => false,
                'error' => 'No pude extraer PID/SKU de esa entrada. Usa una URL tipo …/product/slug-p-{UUID}.html',
            ];
        }

        // No filtrar por countryCode aquí: CJ oculta variantes sin stock en ese país
        // (p.ej. MX → 0 variantes aunque el producto sí las tenga en CN).
        $params = [$ref['type'] => $ref['value']];

        $result = $this->queryProductDetail($params);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? $result['message'] ?? 'CJ no devolvió el producto',
                'ref' => $ref,
                'raw' => $result,
            ];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($data === []) {
            return [
                'success' => false,
                'error' => 'Producto no encontrado en CJ',
                'ref' => $ref,
            ];
        }

        $rawVariants = $data['variants'] ?? null;
        if (! is_array($rawVariants) || $rawVariants === []) {
            $pidForVariants = (string) ($data['pid'] ?? ($ref['type'] === 'pid' ? $ref['value'] : ''));
            if ($pidForVariants !== '') {
                $vr = $this->getVariants($pidForVariants);
                if ($vr['success'] ?? false) {
                    $list = $vr['data']['list'] ?? $vr['data'] ?? [];
                    if (is_array($list) && $list !== []) {
                        $data['variants'] = $list;
                    }
                }
            }
        }

        $product = $this->normalizeProductDetailRich($data);
        $pid = (string) ($product['pid'] ?? ($ref['type'] === 'pid' ? $ref['value'] : ''));
        if ($pid !== '') {
            $product = $this->enrichDetailWithMedia($product, $pid);
        }

        // Si el mercado tiene inventario local, anotar stock filtrado (sin reemplazar la lista completa).
        if ($countryCode && ! empty($product['variants'])) {
            $cc = strtoupper($countryCode === 'UK' ? 'GB' : $countryCode);
            $local = $this->queryProductDetail([
                $ref['type'] => $ref['value'],
                'countryCode' => $cc,
            ]);
            $localRows = is_array($local['data']['variants'] ?? null) ? $local['data']['variants'] : [];
            $stockByVid = [];
            foreach ($localRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $vid = (string) ($row['vid'] ?? '');
                if ($vid === '') {
                    continue;
                }
                $stockByVid[$vid] = isset($row['inventoryNum'])
                    ? (int) $row['inventoryNum']
                    : (isset($row['variantStock']) ? (int) $row['variantStock'] : null);
            }
            if ($stockByVid !== []) {
                foreach ($product['variants'] as &$variant) {
                    $vid = (string) ($variant['vid'] ?? '');
                    if ($vid !== '' && array_key_exists($vid, $stockByVid)) {
                        $variant['stock_local'] = $stockByVid[$vid];
                        $variant['stock'] = $stockByVid[$vid];
                    }
                }
                unset($variant);
            }
            $product['market_country'] = $cc;
            $product['variants_with_local_stock'] = count($stockByVid);
        }

        if ($usedHtml) {
            $product['resolved_via'] = 'html+api';
        } else {
            $product['resolved_via'] = 'api';
        }
        $product['ref'] = $ref;

        return [
            'success' => true,
            'product' => $product,
            'ref' => $ref,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeProductDetail(array $data): array
    {
        $listLike = self::normalizeListItem([
            'pid' => $data['pid'] ?? '',
            'productNameEn' => $data['productNameEn'] ?? $data['productName'] ?? '',
            'productName' => $data['productName'] ?? null,
            'productImage' => $data['productImage'] ?? ($data['productImageSet'][0] ?? ''),
            'sellPrice' => $data['sellPrice'] ?? $data['nowPrice'] ?? null,
            'productWeight' => $data['productWeight'] ?? $data['weight'] ?? null,
            'categoryName' => $data['categoryName'] ?? ($data['categoryList'][0]['categoryName'] ?? ''),
            'productType' => $data['productType'] ?? '',
            'isFreeShipping' => $data['isFreeShipping'] ?? false,
            'listedNum' => $data['listedNum'] ?? $data['listingCount'] ?? 0,
            'videoList' => $data['videoList'] ?? [],
            'productSku' => $data['productSku'] ?? $data['sku'] ?? '',
        ]);

        $descriptions = $this->extractDescriptions($data);
        $images = $this->extractImageGallery($data);
        if ($images === [] && ! empty($listLike['image'])) {
            $images = [(string) $listLike['image']];
        }

        $rawVariants = $data['variants'] ?? [];
        $variants = $this->normalizeVariantRows(is_array($rawVariants) ? $rawVariants : []);
        foreach ($variants as $variant) {
            $vImg = trim((string) ($variant['image'] ?? ''));
            if ($vImg !== '' && ! in_array($vImg, $images, true)) {
                $images[] = $vImg;
            }
        }

        $pid = (string) ($listLike['pid'] ?: ($data['pid'] ?? ''));
        $sku = (string) ($data['productSku'] ?? $listLike['sku'] ?? '');

        return array_merge($listLike, [
            'pid' => $pid,
            'sku' => $sku !== '' ? $sku : ($listLike['sku'] ?? ''),
            'images' => $images,
            'image_count' => count($images),
            'image' => $images[0] ?? ($listLike['image'] ?? ''),
            'description' => $descriptions['plain'],
            'description_short' => $descriptions['short'],
            'description_html' => $descriptions['html'],
            'description_long' => $descriptions['html'] !== '' ? $descriptions['html'] : $descriptions['plain'],
            'variants' => $variants,
            'variant_count' => count($variants),
            'packed_weight' => self::normalizeWeight($data['packingWeight'] ?? $data['packWeight'] ?? $data['productWeight'] ?? $listLike['weight'] ?? null),
            'status' => (string) ($data['productStatus'] ?? $data['status'] ?? ''),
            'add_mark_status' => $data['addMarkStatus'] ?? null,
            'supplier_name' => (string) ($data['supplierName'] ?? ''),
            'cj_url' => $pid !== ''
                ? self::publicProductUrl($pid, (string) ($listLike['title'] ?? 'product'))
                : null,
        ]);
    }

    /**
     * Detalle enriquecido para sync/catálogo (más campos + HTML).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeProductDetailRich(array $data): array
    {
        $base = $this->normalizeProductDetail($data);

        $pickEn = function ($primary, $set = null) {
            $v = is_string($primary) ? trim($primary) : '';
            if ($v !== '') {
                return $v;
            }
            if (is_array($set)) {
                foreach ($set as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                    if (is_array($item)) {
                        $nested = trim((string) ($item['name'] ?? $item['value'] ?? $item[0] ?? ''));
                        if ($nested !== '') {
                            return $nested;
                        }
                    }
                }
            }

            return null;
        };

        $productKey = $pickEn($data['productKeyEn'] ?? null, $data['productKeyEnSet'] ?? null);
        $short = (string) ($base['description_short'] ?? '');
        if ($productKey && ($short === '' || mb_strlen($short) < 40)) {
            $short = mb_strlen($productKey) > 400 ? mb_substr($productKey, 0, 400).'…' : $productKey;
        }

        return array_merge($base, [
            'description_short' => $short,
            'category_id' => $data['categoryId'] ?? null,
            'supplier_id' => $data['supplierId'] ?? null,
            'suggest_sell_price' => isset($data['suggestSellPrice']) ? (float) $data['suggestSellPrice'] : null,
            'material' => $pickEn($data['materialNameEn'] ?? null, $data['materialNameEnSet'] ?? null),
            'packing' => $pickEn($data['packingNameEn'] ?? null, $data['packingNameEnSet'] ?? null),
            'product_key' => $productKey,
            'product_props' => $pickEn($data['productProEn'] ?? null, $data['productProEnSet'] ?? null),
            'entry_name' => $pickEn($data['entryNameEn'] ?? $data['entryName'] ?? null),
            'packed_weight' => self::normalizeWeight($data['packingWeight'] ?? $data['packWeight'] ?? $base['packed_weight'] ?? null),
            'videos' => $base['videos'] ?? [],
            'reviews' => $base['reviews'] ?? [],
            'comments' => $base['comments'] ?? [],
        ]);
    }

    /**
     * Videos, reseñas, comentarios e imágenes extra vía API CJ.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    public function enrichDetailWithMedia(array $product, string $pid): array
    {
        $pid = trim($pid);
        if ($pid === '') {
            return $product;
        }

        $videos = $this->queryVideosByProductId($pid);
        if ($videos['success'] ?? false) {
            $list = array_values($videos['videos'] ?? []);
            if ($list !== []) {
                $product['videos'] = $list;
                $product['has_video'] = true;
            }
        }
        $product['videos'] = array_values($product['videos'] ?? []);
        $product['has_video'] = (bool) ($product['has_video'] ?? false) || ($product['videos'] !== []);

        $comments = $this->queryProductComments($pid);
        if ($comments['success'] ?? false) {
            $product['reviews'] = array_values($comments['reviews'] ?? []);
            $product['comments'] = array_values($comments['comments'] ?? []);
            $product['review_count'] = (int) ($comments['total'] ?? count($product['reviews']));
            $product['comment_count'] = (int) ($comments['comment_count'] ?? count($product['comments']));
            $product['rating_avg'] = $comments['rating_avg'] ?? null;
            $product['rating_breakdown'] = $comments['rating_breakdown'] ?? [];
        } else {
            $product['reviews'] = $product['reviews'] ?? [];
            $product['comments'] = $product['comments'] ?? [];
            $product['review_count'] = (int) ($product['review_count'] ?? count($product['reviews']));
            $product['comment_count'] = (int) ($product['comment_count'] ?? count($product['comments']));
            $product['rating_avg'] = $product['rating_avg'] ?? null;
            $product['rating_breakdown'] = $product['rating_breakdown'] ?? [];
            $product['reviews_error'] = $comments['error'] ?? null;
        }

        $images = array_values(array_filter($product['images'] ?? [], fn ($u) => is_string($u) && trim($u) !== ''));
        if (count($images) <= 1) {
            $extra = $this->queryProductImages($pid);
            if ($extra['success'] ?? false) {
                foreach (($extra['images'] ?? []) as $url) {
                    if (is_string($url) && trim($url) !== '' && ! in_array($url, $images, true)) {
                        $images[] = $url;
                    }
                }
            }
        }
        foreach (($product['variants'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $vImg = trim((string) ($variant['image'] ?? ''));
            if ($vImg !== '' && ! in_array($vImg, $images, true)) {
                $images[] = $vImg;
            }
        }
        $product['images'] = $images;
        $product['image_count'] = count($images);
        if (empty($product['image']) && ! empty($images[0])) {
            $product['image'] = $images[0];
        }

        return $product;
    }

    /**
     * @param  list<mixed>  $rawVariants
     * @return list<array<string, mixed>>
     */
    public function normalizeVariantRows(array $rawVariants): array
    {
        $variants = [];
        foreach ($rawVariants as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $key = trim((string) ($v['variantKey'] ?? ''));
                $nameEn = trim((string) ($v['variantNameEn'] ?? ''));
                $nameLocal = trim((string) ($v['variantName'] ?? ''));
                $name = $key !== '' ? $key : ($nameEn !== '' ? $nameEn : $nameLocal);

                $stock = null;
                if (isset($v['inventoryNum']) && $v['inventoryNum'] !== null && $v['inventoryNum'] !== '') {
                    $stock = (int) $v['inventoryNum'];
                } elseif (isset($v['variantStock']) && $v['variantStock'] !== null && $v['variantStock'] !== '') {
                    $stock = (int) $v['variantStock'];
            } elseif (isset($v['inventory']) && $v['inventory'] !== null && $v['inventory'] !== '') {
                $stock = (int) $v['inventory'];
                }

            $image = trim((string) ($v['variantImage'] ?? $v['image'] ?? $v['variantImg'] ?? ''));

                $variants[] = [
                    'vid' => (string) ($v['vid'] ?? ''),
                'sku' => (string) ($v['variantSku'] ?? $v['sku'] ?? ''),
                    'name' => $name,
                    'key' => $key,
                'price' => isset($v['variantSellPrice']) ? (float) $v['variantSellPrice'] : (isset($v['sellPrice']) ? (float) $v['sellPrice'] : null),
                'weight' => self::normalizeWeight($v['variantWeight'] ?? $v['weight'] ?? null),
                'image' => $image,
                    'stock' => $stock,
                    'length' => isset($v['variantLength']) ? (float) $v['variantLength'] : null,
                    'width' => isset($v['variantWidth']) ? (float) $v['variantWidth'] : null,
                    'height' => isset($v['variantHeight']) ? (float) $v['variantHeight'] : null,
                ];
            }

        return $variants;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{short: string, plain: string, html: string}
     */
    public function extractDescriptions(array $data): array
    {
        $html = (string) ($data['description'] ?? $data['productDescriptionEn'] ?? $data['productDescription'] ?? '');
        if (mb_strlen($html) > 120000) {
            $html = mb_substr($html, 0, 120000).'…';
        }

        $short = '';
        foreach (['productKeyEn', 'entryNameEn', 'entryName', 'sellPoint', 'productSellPoint'] as $field) {
            $candidate = trim((string) ($data[$field] ?? ''));
            if ($candidate !== '') {
                $short = $candidate;
                break;
            }
        }
        if ($short === '') {
            foreach (['productKeyEnSet', 'sellPointSet'] as $setField) {
                $set = $data[$setField] ?? null;
                if (! is_array($set)) {
                    continue;
                }
                $bits = [];
                foreach ($set as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $bits[] = trim($item);
                    }
                }
                if ($bits !== []) {
                    $short = implode(' · ', array_slice($bits, 0, 6));
                    break;
                }
            }
        }
        $copy = app(\App\Services\Storefront\ProductDescriptionHtml::class)->present('', $html, $short);
        $plain = $copy['plain'];
        if (mb_strlen($plain) > 40000) {
            $plain = mb_substr($plain, 0, 40000).'…';
        }
        $short = $copy['short'] !== '' ? $copy['short'] : $short;
        $short = trim(strip_tags(html_entity_decode($short, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($short) > 400) {
            $short = mb_substr($short, 0, 400).'…';
        }

        return [
            'short' => $short,
            'plain' => $plain,
            'html' => $copy['html'],
        ];
    }

    /**
     * Videos del producto (marketing/unboxing) vía API CJ.
     *
     * @return array{success: bool, videos?: list<array<string, mixed>>, error?: string, raw?: mixed}
     */
    public function queryVideosByProductId(string $productId): array
    {
        $result = $this->request('POST', '/product/queryVideosByProductId', [], [
            'productId' => $productId,
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? $result['message'] ?? 'No se pudieron obtener videos',
                'raw' => $result,
            ];
        }

        $rows = $result['data'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $videos = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['videoUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            $videos[] = [
                'id' => (string) ($row['videoId'] ?? $row['id'] ?? ''),
                'name' => (string) ($row['videoName'] ?? 'Video'),
                'url' => $url,
                'cover' => (string) ($row['coverURL'] ?? $row['coverUrl'] ?? ''),
                'duration' => isset($row['duration']) ? (float) $row['duration'] : null,
                'state' => (string) ($row['videoState'] ?? ''),
                'width' => isset($row['width']) ? (int) $row['width'] : null,
                'height' => isset($row['height']) ? (int) $row['height'] : null,
            ];
        }

        return [
            'success' => true,
            'videos' => $videos,
            'raw' => $result,
        ];
    }

    /**
     * Reseñas / comentarios de compradores (API CJ Product Reviews).
     *
     * @param  array{score?: int, page_size?: int, max_pages?: int}  $options
     * @return array{
     *   success: bool,
     *   reviews?: list<array<string, mixed>>,
     *   total?: int,
     *   rating_avg?: float|null,
     *   rating_breakdown?: array<int, int>,
     *   error?: string,
     *   raw?: mixed
     * }
     */
    public function queryProductComments(string $productId, array $options = []): array
    {
        $productId = trim($productId);
        if ($productId === '') {
            return ['success' => false, 'error' => 'PID vacío'];
        }

        $pageSize = max(1, min(50, (int) ($options['page_size'] ?? config('cj.reviews_page_size', 20))));
        $maxPages = max(1, min(15, (int) ($options['max_pages'] ?? config('cj.reviews_max_pages', 5))));
        $scoreFilter = isset($options['score']) ? (int) $options['score'] : null;

        $reviews = [];
        $total = 0;
        $lastRaw = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $query = [
                'pid' => $productId,
                'pageNum' => $page,
                'pageSize' => $pageSize,
            ];
            if ($scoreFilter !== null && $scoreFilter > 0) {
                $query['score'] = $scoreFilter;
            }

            $result = $this->request('GET', '/product/productComments', $query);
            $lastRaw = $result;

            if (! ($result['success'] ?? false)) {
                // Si la 1.ª página falla, error; si ya hay reseñas, devolver parcial
                if ($reviews === []) {
                    return [
                        'success' => false,
                        'error' => $result['error'] ?? $result['message'] ?? 'No se pudieron obtener reseñas',
                        'raw' => $result,
                    ];
                }
                break;
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $total = (int) ($data['total'] ?? $total);
            $list = $data['list'] ?? $data['content'] ?? [];
            if (! is_array($list)) {
                $list = [];
            }

            $pageCount = 0;
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeProductComment($row);
                if ($normalized === null) {
                    continue;
                }
                $reviews[] = $normalized;
                $pageCount++;
            }

            if ($pageCount === 0) {
                break;
            }
            if ($total > 0 && count($reviews) >= $total) {
                break;
            }
            if ($pageCount < $pageSize) {
                break;
            }
        }

        // Deduplicar por comment_id
        $byId = [];
        foreach ($reviews as $r) {
            $key = (string) ($r['comment_id'] ?? '');
            if ($key === '') {
                $key = md5(($r['author'] ?? '').'|'.($r['date'] ?? '').'|'.($r['comment'] ?? ''));
            }
            $byId[$key] = $r;
        }
        $reviews = array_values($byId);

        $breakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $sum = 0;
        $rated = 0;
        foreach ($reviews as $r) {
            $s = (int) ($r['score'] ?? 0);
            if ($s < 1 || $s > 5) {
                continue;
            }
            $breakdown[$s]++;
            $sum += $s;
            $rated++;
        }

        $comments = array_values(array_filter(
            $reviews,
            fn ($r) => trim((string) ($r['comment'] ?? '')) !== '' || ! empty($r['images'])
        ));

        return [
            'success' => true,
            'reviews' => $reviews,
            'comments' => $comments,
            'comment_count' => count($comments),
            'total' => $total > 0 ? $total : count($reviews),
            'rating_avg' => $rated > 0 ? round($sum / $rated, 2) : null,
            'rating_breakdown' => $breakdown,
            'raw' => $lastRaw,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function normalizeProductComment(array $row): ?array
    {
        $comment = trim((string) ($row['comment'] ?? $row['content'] ?? $row['review'] ?? ''));
        $score = (int) ($row['score'] ?? $row['rating'] ?? $row['star'] ?? 0);
        $author = trim((string) ($row['commentUser'] ?? $row['userName'] ?? $row['nick'] ?? 'Comprador'));
        $id = (string) ($row['commentId'] ?? $row['id'] ?? '');

        if ($comment === '' && $score <= 0 && $id === '') {
            return null;
        }

        $urls = $this->normalizeCommentImages($row['commentUrls'] ?? $row['images'] ?? $row['commentImage'] ?? $row['pics'] ?? []);

        return [
            'comment_id' => $id,
            'author' => $author !== '' ? $author : 'Comprador',
            'score' => max(0, min(5, $score)),
            'comment' => $comment,
            'date' => (string) ($row['commentDate'] ?? $row['createTime'] ?? $row['date'] ?? ''),
            'country' => (string) ($row['countryCode'] ?? $row['country'] ?? ''),
            'flag_url' => (string) ($row['flagIconUrl'] ?? ''),
            'images' => $urls,
            'pid' => (string) ($row['pid'] ?? ''),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    protected function normalizeCommentImages(mixed $raw): array
    {
        $urls = [];
        $push = function ($value) use (&$urls, &$push) {
            if (is_string($value)) {
                $trim = trim($value);
                if ($trim === '') {
                    return;
                }
                if (str_starts_with($trim, '[')) {
                    $decoded = json_decode($trim, true);
                    if (is_array($decoded)) {
                        $push($decoded);

                        return;
                    }
                }
                if (preg_match('/https?:\/\//', $trim) && (str_contains($trim, ',') || str_contains($trim, '|') || str_contains($trim, ';'))) {
                    foreach (preg_split('/[,|;]+/', $trim) ?: [] as $part) {
                        $push(trim($part));
                    }

                    return;
                }
                if (filter_var($trim, FILTER_VALIDATE_URL) || str_starts_with($trim, 'http')) {
                    $urls[] = $trim;
                }

                return;
            }
            if (is_array($value)) {
                foreach (['url', 'image', 'src', 'img'] as $key) {
                    if (! empty($value[$key])) {
                        $push($value[$key]);
                    }
                }
                foreach ($value as $item) {
                    if (! is_array($item) && ! is_string($item)) {
                        continue;
                    }
                    $push($item);
                }
            }
        };
        $push($raw);

        $unique = [];
        $seen = [];
        foreach ($urls as $url) {
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $url;
        }

        return $unique;
    }

    /**
     * Galería de imágenes del producto (detalle CJ).
     *
     * @return array{success: bool, images?: list<string>, error?: string, raw?: mixed}
     */
    public function queryProductImages(string $productId): array
    {
        $result = $this->getProduct($productId);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? $result['message'] ?? 'No se pudo obtener el producto',
                'raw' => $result,
            ];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $images = $this->extractImageGallery($data);

        return [
            'success' => true,
            'images' => $images,
            'raw' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function extractImageGallery(array $data): array
    {
        $urls = [];

        $push = function ($value) use (&$urls, &$push) {
            if (is_string($value)) {
                $trim = trim($value);
                if ($trim === '') {
                    return;
                }
                if (str_starts_with($trim, '[')) {
                    $decoded = json_decode($trim, true);
                    if (is_array($decoded)) {
                        $push($decoded);

                        return;
                    }
                }
                if (str_contains($trim, '<')) {
                    foreach ($this->extractImagesFromHtml($trim) as $url) {
                        $urls[] = $url;
                        }

                        return;
                    }
                if (preg_match('/https?:\/\//', $trim) && (str_contains($trim, ',') || str_contains($trim, '|') || str_contains($trim, ';'))) {
                    foreach (preg_split('/[,|;]+/', $trim) ?: [] as $part) {
                        $push(trim($part));
                    }

                    return;
                }
                if (filter_var($trim, FILTER_VALIDATE_URL) || str_starts_with($trim, 'http')) {
                    $urls[] = $trim;
                }

                return;
            }
            if (is_array($value)) {
                foreach (['url', 'image', 'src', 'img', 'productImage', 'bigImage', 'variantImage'] as $key) {
                    if (! empty($value[$key])) {
                        $push($value[$key]);
                    }
                }
                foreach ($value as $item) {
                    if (is_string($item) || is_array($item)) {
                        $push($item);
                    }
                }
            }
        };

        $push($data['productImageSet'] ?? null);
        $push($data['productImage'] ?? null);
        $push($data['bigImage'] ?? null);
        $push($data['imageList'] ?? null);
        $push($data['productImages'] ?? null);
        $push($data['pictures'] ?? null);
        $push($data['description'] ?? $data['productDescriptionEn'] ?? $data['productDescription'] ?? null);

        $variants = $data['variants'] ?? [];
        if (is_array($variants)) {
            foreach ($variants as $variant) {
                if (is_array($variant)) {
                    $push($variant['variantImage'] ?? $variant['image'] ?? $variant['variantImg'] ?? null);
                }
            }
        }

        $unique = [];
        $seen = [];
        foreach ($urls as $url) {
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $url;
        }

        return $unique;
    }

    /**
     * @return list<string>
     */
    public function extractImagesFromHtml(string $html): array
    {
        $urls = [];
        if ($html === '') {
            return $urls;
        }
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $src = trim(html_entity_decode((string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($src !== '' && (str_starts_with($src, 'http') || str_starts_with($src, '//'))) {
                    if (str_starts_with($src, '//')) {
                        $src = 'https:'.$src;
                    }
                    $urls[] = $src;
                }
            }
        }

        return $urls;
    }

    public function getVariants(string $productId): array
    {
        return $this->request('GET', '/product/variant/query', ['pid' => $productId]);
    }

    public function getStock(string $variantId): array
    {
        return $this->request('GET', '/product/stock/queryByVid', ['vid' => $variantId]);
    }

    public function calculateFreight(array $payload): array
    {
        return $this->request('POST', '/logistic/freightCalculate', [], $payload);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('POST', '/shopping/order/createOrderV3', [], $payload);
    }

    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/shopping/order/getOrderDetail', ['orderId' => $orderId]);
    }

    public function getTracking(string $orderId): array
    {
        return $this->request('GET', '/logistic/trackInfo', ['orderId' => $orderId]);
    }

    /**
     * @param  array<string, mixed>  $params  orderId y/o trackNumber
     */
    public function getTrackInfo(array $params): array
    {
        return $this->request('GET', '/logistic/trackInfo', $params);
    }

    public function getCategories(): array
    {
        return $this->request('GET', '/product/getCategory');
    }

    /**
     * Exchange CJ API Key for access/refresh tokens and persist them.
     */
    public function authorizeWithApiKey(?string $apiKey = null): array
    {
        $apiKey = $apiKey ?: config('cj.api_key');
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Falta la API Key de CJ Dropshipping.'];
        }

        $url = rtrim(config('cj.base_url'), '/').'/authentication/getAccessToken';

        try {
            $response = Http::timeout(config('cj.timeout', 30))
                ->acceptJson()
                ->post($url, ['apiKey' => $apiKey]);

            $json = $response->json() ?? [];
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];

            if (! $response->successful() || empty($data['accessToken'] ?? $data['access_token'] ?? null)) {
                // Legacy fallback: email + password(apiKey)
                $legacy = $this->authorizeLegacy($apiKey);
                if ($legacy['success'] ?? false) {
                    return $legacy;
                }

                return [
                    'success' => false,
                    'error' => $json['message'] ?? $response->body() ?: 'No se pudo obtener el access token de CJ.',
                    'raw' => $json,
                ];
            }

            $access = $data['accessToken'] ?? $data['access_token'];
            $refresh = $data['refreshToken'] ?? $data['refresh_token'] ?? null;

            $this->persistTokens($access, $refresh);
            Cache::forget('cj.access_token');

            return [
                'success' => true,
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_at' => $data['accessTokenExpiryDate'] ?? null,
                'open_id' => $data['openId'] ?? null,
                'mcp_url' => $this->mcpServerUrl($access),
            ];
        } catch (\Throwable $e) {
            Log::error('CJ authorize failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * URL del MCP remoto de CJ (ChatGPT / Cursor HTTP).
     */
    public function mcpServerUrl(?string $accessToken = null): ?string
    {
        $token = $accessToken ?: config('cj.access_token');
        if (empty($token)) {
            return null;
        }

        return rtrim((string) config('cj.mcp_base_url'), '/').'/'.$token;
    }

    /**
     * Prueba auth + una llamada ligera a la API (categorías).
     */
    public function testApi(?string $apiKey = null): array
    {
        $auth = $this->authorizeWithApiKey($apiKey ?: config('cj.api_key'));
        if (! ($auth['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $auth['error'] ?? 'No se pudo autenticar con la API Key.',
                'step' => 'auth',
            ];
        }

        $categories = $this->getCategories();
        if (! ($categories['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $categories['error'] ?? 'Auth OK pero falló la llamada de prueba.',
                'step' => 'api',
                'mcp_url' => $auth['mcp_url'] ?? $this->mcpServerUrl(),
            ];
        }

        $data = $categories['data'] ?? null;
        $count = is_array($data) ? count($data) : null;

        return [
            'success' => true,
            'message' => 'API CJ operativa.'.($count !== null ? " Categorías: {$count}." : ''),
            'open_id' => $auth['open_id'] ?? null,
            'expires_at' => $auth['expires_at'] ?? null,
            'mcp_url' => $auth['mcp_url'] ?? $this->mcpServerUrl(),
            'categories_sample' => is_array($data) ? array_slice($data, 0, 3) : null,
        ];
    }

    protected function authorizeLegacy(string $apiKey): array
    {
        $email = config('cj.email');
        if (empty($email)) {
            return ['success' => false, 'error' => 'Auth legacy requiere CJ_EMAIL.'];
        }

        $url = rtrim(config('cj.base_url'), '/').'/authentication/getAccessToken';

        $response = Http::timeout(config('cj.timeout', 30))
            ->acceptJson()
            ->post($url, [
                'email' => $email,
                'password' => $apiKey,
            ]);

        $json = $response->json() ?? [];
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $access = $data['accessToken'] ?? $data['access_token'] ?? null;

        if (! $response->successful() || ! $access) {
            return [
                'success' => false,
                'error' => $json['message'] ?? 'Auth legacy falló.',
                'raw' => $json,
            ];
        }

        $refresh = $data['refreshToken'] ?? $data['refresh_token'] ?? null;
        $this->persistTokens($access, $refresh);
        Cache::forget('cj.access_token');

        return [
            'success' => true,
            'access_token' => $access,
            'refresh_token' => $refresh,
            'legacy' => true,
        ];
    }

    protected function persistTokens(string $access, ?string $refresh): void
    {
        config([
            'cj.access_token' => $access,
            'cj.refresh_token' => $refresh,
        ]);

        try {
            if (Schema::hasTable('platform_settings')) {
                PlatformSetting::put('cj.access_token', $access, 'cj', true);
                if ($refresh) {
                    PlatformSetting::put('cj.refresh_token', $refresh, 'cj', true);
                }
                PlatformSetting::put('cj.authorized_at', now()->toIso8601String(), 'cj');
            }
        } catch (\Throwable $e) {
            Log::warning('CJ tokens not persisted to DB', ['error' => $e->getMessage()]);
        }
    }

    protected function request(string $method, string $path, array $query = [], array $body = [], int $attempt = 1): array
    {
        $token = $this->accessToken();
        if (! $token) {
            return ['success' => false, 'error' => 'CJ access token no disponible. Autoriza la API Key en General → CJ Dropshipping.'];
        }

        $this->throttleRequests();

        $url = rtrim(config('cj.base_url'), '/').$path;

        try {
            $pending = Http::timeout(config('cj.timeout', 30))
                ->withHeaders([
                    'CJ-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ])
                ->acceptJson();

            $response = strtoupper($method) === 'GET'
                ? $pending->get($url, $query)
                : $pending->post($url, $body ?: $query);

            $json = $response->json() ?? [];

            if ($response->status() === 401) {
                Cache::forget('cj.access_token');
                if ($this->refreshAccessToken()) {
                    return $this->request($method, $path, $query, $body, $attempt);
                }
            }

            $cjFailed = ($json['result'] ?? true) === false
                || ($json['success'] ?? true) === false
                || (isset($json['code']) && (int) $json['code'] !== 200 && ($json['data'] ?? null) === null);

            $isQps = str_contains(strtolower((string) ($json['message'] ?? $response->body())), 'too many requests')
                || (int) ($json['code'] ?? 0) === 1600200;

            if ($isQps && $attempt < 3) {
                usleep(1200 * 1000);
                Cache::put('cj.api.last_call_ms', (int) floor(microtime(true) * 1000), 30);

                return $this->request($method, $path, $query, $body, $attempt + 1);
            }

            if (! $response->successful() || $cjFailed) {
                return [
                    'success' => false,
                    'error' => (string) ($json['message'] ?? $response->body() ?: ('HTTP '.$response->status())),
                    'code' => $json['code'] ?? $response->status(),
                    'raw' => $json ?: $response->body(),
                ];
            }

            return array_merge(['success' => true], is_array($json) ? $json : ['raw' => $json]);
        } catch (\Throwable $e) {
            Log::error('CJ API error', ['path' => $path, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * CJ limita ~1 QPS. Espacia llamadas entre sí.
     */
    protected function throttleRequests(): void
    {
        $key = 'cj.api.last_call_ms';
        $last = (int) Cache::get($key, 0);
        $now = (int) floor(microtime(true) * 1000);
        $waitMs = 1250 - ($now - $last);
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
        Cache::put($key, (int) floor(microtime(true) * 1000), 30);
    }

    protected function accessToken(): ?string
    {
        $configured = config('cj.access_token');
        if (! empty($configured)) {
            return $configured;
        }

        return Cache::remember('cj.access_token', 60 * 60 * 12, function () {
            $result = $this->authorizeWithApiKey();

            return ($result['success'] ?? false) ? ($result['access_token'] ?? null) : null;
        });
    }

    protected function refreshAccessToken(): bool
    {
        $refresh = config('cj.refresh_token');
        if (empty($refresh)) {
            $result = $this->authorizeWithApiKey();

            return (bool) ($result['success'] ?? false);
        }

        $url = rtrim(config('cj.base_url'), '/').'/authentication/refreshAccessToken';

        try {
            $response = Http::timeout(config('cj.timeout', 30))
                ->acceptJson()
                ->post($url, ['refreshToken' => $refresh]);

            $data = $response->json('data') ?? [];
            $access = $data['accessToken'] ?? $data['access_token'] ?? null;

            if (! $response->successful() || ! $access) {
                return false;
            }

            $this->persistTokens($access, $data['refreshToken'] ?? $data['refresh_token'] ?? $refresh);
            Cache::forget('cj.access_token');

            return true;
        } catch (\Throwable $e) {
            Log::warning('CJ refresh failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
