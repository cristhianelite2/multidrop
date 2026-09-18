<?php

namespace App\Services\SellerCentral;

use App\Domain\AI\AiTaskRouter;
use App\Models\Product;
use App\Models\Store;
use App\Services\Marketing\ProductMarketingMediaService;
use Carbon\Carbon;

class PublicationPlannerService
{
    /** Zona horaria de Seller Central (APP_TIMEZONE): las fechas son naive. */
    public const TZ = 'America/Mexico_City';

    /** Horas fijas por slot del día (10:00, 12:00, 14:00, …). */
    public const SLOT_HOURS = [10, 12, 14, 16, 18, 20];

    public const MAX_LENGTHS = [
        'facebook' => 2000,
        'instagram' => 2000,
        'twitter' => 280,
        'linkedin' => 1500,
        'tiktok' => 2000,
        'youtube' => 5000,
        'gmail' => 3000,
    ];

    public const NETWORK_LABELS = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'twitter' => 'Twitter / X',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'gmail' => 'Gmail',
    ];

    public function __construct(
        protected AiTaskRouter $ai,
        protected ProductMarketingMediaService $media,
    ) {
    }

    /**
     * Construye el calendario de publicaciones y genera el copy con MIIA (una llamada por día).
     *
     * @param  array{days: int, per_day: int, channels: list<string>, product_ids: array<int, int>, start_date: string, format: string}  $config
     * @return array{slots: list<array<mixed>>, errors: list<string>}
     */
    public function buildPlan(Store $store, array $config): array
    {
        $days = max(1, min((int) ($config['days'] ?? 1), (int) config('multidrop.marketing.sellercentral.max_days', 60)));
        $perDay = max(1, min((int) ($config['per_day'] ?? 1), (int) config('multidrop.marketing.sellercentral.max_per_day', 10)));
        $maxPosts = (int) config('multidrop.marketing.sellercentral.max_posts_per_plan', 100);

        $channels = array_values(array_intersect(
            array_unique(array_filter(array_map('strtolower', (array) ($config['channels'] ?? [])))),
            SellerCentralApi::NETWORKS
        ));
        if ($channels === []) {
            $channels = ['instagram', 'facebook', 'tiktok'];
        }

        $productIds = array_values(array_unique(array_map('intval', (array) ($config['product_ids'] ?? []))));
        $productIds = array_filter($productIds, fn ($id) => $id > 0);

        $start = $this->startDate($config['start_date'] ?? '');

        $total = $days * $perDay;
        if ($total > $maxPosts) {
            throw new SellerCentralException("Demasiadas publicaciones: $total (máximo $maxPosts). Reduce días o publicaciones por día.");
        }

        $products = $this->catalog($store, $productIds);
        if ($products === []) {
            throw new SellerCentralException('No hay productos válidos para planificar publicaciones.');
        }

        $format = in_array((string) ($config['format'] ?? 'image'), ['image', 'text'], true) ? $config['format'] : 'image';
        $slots = $this->slots($store, $start, $days, $perDay, $channels, $products, $format);
        $errors = $this->generateCopy($store, $slots);

        return ['slots' => $slots, 'errors' => $errors];
    }

    /**
     * @param  list<array<mixed>>  $slots
     * @return list<array<mixed>>
     */
    protected function slots(Store $store, Carbon $start, int $days, int $perDay, array $channels, array $products, string $format): array
    {
        $slots = [];
        $count = count($products);

        for ($d = 0; $d < $days; $d++) {
            $date = $start->copy()->addDays($d);
            for ($s = 0; $s < $perDay; $s++) {
                $idx = $d * $perDay + $s;
                $product = $products[$idx % $count];
                $network = $channels[$idx % count($channels)];
                $hour = self::SLOT_HOURS[$s % count(self::SLOT_HOURS)];
                $scheduled = Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d').sprintf(' %02d:00', $hour), self::TZ);

                $slot = [
                    'day_offset' => $d,
                    'slot_index' => $s,
                    'date' => $date->format('Y-m-d'),
                    'scheduled_at' => $scheduled->format('Y-m-d H:i:s'),
                    'network' => $network,
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'product_description' => $product['description'] ?? '',
                    'product_price' => $product['price'] ?? '',
                    'product_url' => $product['url'] ?? '',
                    'format' => $format,
                    'topic' => '',
                    'content' => '',
                    'media_urls' => $this->mediaFor($product, $format),
                ];

                if ($slot['media_urls'] === []) {
                    $slot['format'] = 'text';
                }

                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @param  list<array<mixed>>  $slots
     * @return list<string>
     */
    protected function generateCopy(Store $store, array &$slots): array
    {
        $errors = [];
        $byDay = [];
        foreach ($slots as $i => $slot) {
            $byDay[(int) $slot['day_offset']][] = $i;
        }

        $locale = $store->defaultLocale();
        $currency = $store->currency();

        foreach ($byDay as $day => $indexes) {
            $date = ($slots[$indexes[0]]['date'] ?? '');
            $context = $this->dayContext($store, $day, $date, $locale, $currency, collect($indexes)->map(fn ($i) => $slots[$i])->all());
            $result = $this->ai->chat('seller_publication', [
                ['role' => 'system', 'content' => $this->systemPrompt($locale)],
                ['role' => 'user', 'content' => $context],
            ]);

            if (! ($result['success'] ?? false)) {
                $errors[] = 'Día '.($day + 1).': '.($result['error'] ?? 'MIIA no respondió.');
                continue;
            }

            $decoded = $this->extractPosts((string) ($result['content'] ?? ''));
            if ($decoded === null || $decoded === []) {
                $errors[] = 'Día '.($day + 1).': la respuesta de MIIA no fue un JSON válido. Se usó copy de respaldo.';
                continue;
            }

            foreach ($decoded as $i => $post) {
                $slotIndex = $indexes[$i] ?? null;
                if ($slotIndex === null || ! is_array($post)) {
                    continue;
                }
                $slots[$slotIndex]['network'] = (string) ($post['canal'] ?? $post['network'] ?? $slots[$slotIndex]['network']);
                $slots[$slotIndex]['topic'] = $this->clip((string) ($post['topic'] ?? ''), 255);
                $slots[$slotIndex]['content'] = $this->withRules($slots[$slotIndex]['network'], (string) ($post['content'] ?? ''));
            }
        }

        foreach ($slots as &$slot) {
            if (trim((string) $slot['content']) === '') {
                $slot['content'] = $this->fallbackContent($slot);
            }
        }
        unset($slot);

        return $errors;
    }

    /**
     * @param  list<array<mixed>>  $daySlots
     */
    protected function dayContext(Store $store, int $day, string $date, string $locale, string $currency, array $daySlots): string
    {
        $lines = [];
        $lines[] = "Tienda: {$store->name} · Idioma: {$locale} · Moneda: {$currency} · Fecha del día: {$date}";
        $lines[] = 'Programa estas publicaciones para HOY, una por cada slot (respeta el orden y el canal):';
        foreach ($daySlots as $slot) {
            $head = "Slot {$slot['slot_index']} · Canal: {$slot['network']}";
            if (isset($slot['product_name']) && $slot['product_name'] !== '') {
                $head .= " · Producto: {$slot['product_name']}";
            }
            if (! empty($slot['product_price'])) {
                $head .= " · Precio: {$slot['product_price']}";
            }
            if (! empty($slot['product_url'])) {
                $head .= " · URL: {$slot['product_url']}";
            }
            $lines[] = $head;
            if (! empty($slot['product_description'])) {
                $lines[] = '  Descripción: '.$slot['product_description'];
            }
        }
        $lines[] = 'Cada contenido debe girar en torno al producto indicado (beneficios, uso, precio si viene en el contexto, CTA con enlace si hay URL). Varía ángulos y redacción entre slots del mismo día; no inventes precios ni datos.';

        return implode("\n", $lines);
    }

    protected function systemPrompt(string $locale): string
    {
        $localeLabel = $this->localeLabel($locale);

        return <<<PROMPT
Eres un estratega de redes sociales. Generas publicaciones de venta en {$localeLabel} para productos de tienda.

- Regla de oro: contenido breve, con gancho, beneficios concretos y una sola llamada a la acción.
- Respeta los límites de caracteres por canal: Twitter/X ~280, Instagram 2000, TikTok 2000, Facebook 2000, LinkedIn 1500, YouTube 5000, Gmail 3000.
- Usa 2-4 hashtags solo en Instagram/TikTok. Sin emojis en LinkedIn.
- Evita: "descubre", "no te pierdas", "ideal para", "revolucionario", tono de anuncio de IA, mayoristas de características sin beneficio.
- NO inventes precios, garantías, reviews ni datos que no vengan en el contexto del producto.
- Responde ÚNICAMENTE con un arreglo JSON UTF-8 (sin markdown) con la misma cantidad de elementos que slots:
[{"canal":"instagram","topic":"Tema corto","content":"Texto de la publicación con saltos de línea \\n"}]
- Usa la clave "canal" con el valor exacto del canal. topic: máx 100 caracteres.
PROMPT;
    }

    /**
     * @return list<array<string, string>>|null
     */
    protected function extractPosts(string $raw): ?array
    {
        $raw = trim((string) preg_replace('/^\s*```(?:json)?\s*/i', '', $raw));
        $raw = trim((string) preg_replace('/\s*```\s*$/', '', $raw));

        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = mb_substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'canal' => strtolower(trim((string) ($row['canal'] ?? $row['network'] ?? ''))),
                'topic' => trim((string) ($row['topic'] ?? '')),
                'content' => trim((string) ($row['content'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $slot
     */
    protected function fallbackContent(array $slot): string
    {
        $name = (string) ($slot['product_name'] ?? '');
        $head = $name !== '' ? $name : 'Producto destacado';

        return match ($slot['network']) {
            'twitter' => $head.' disponible. Conoce más en la tienda. #Nuevo #Oferta',
            'linkedin' => $head.' — conoce nuestra oferta en la tienda.',
            default => '✨ '.$head."\n\n¡Consigue el tuyo hoy! 🛍️",
        };
    }

    protected function withRules(string $network, string $text): string
    {
        $text = trim($text);
        $max = self::MAX_LENGTHS[strtolower($network)] ?? 280;
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : $cut)).'…';
    }

    /**
     * @param  list<mixed>  $product
     * @return list<array{url: string, type: string}>
     */
    protected function mediaFor(array $product, string $format): array
    {
        if ($format !== 'image') {
            return [];
        }
        $urls = array_values(array_filter($product['images'] ?? [], 'is_string'));
        $media = [];
        foreach (array_slice($urls, 0, 3) as $url) {
            $media[] = ['url' => trim($url), 'type' => 'image'];
        }

        return $media;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return list<array{id: int, name: string, description: string, price: string, url: string, images: list<string>}>
     */
    protected function catalog(Store $store, array $productIds): array
    {
        $query = Product::query()
            ->with('variants')
            ->where('store_id', $store->id);

        if ($productIds !== []) {
            $query->whereIn('id', $productIds);
        } else {
            $query->where('status', '!=', 'archived');
        }

        $rows = $query->orderByDesc('is_featured')->orderByDesc('updated_at')->limit(60)->get();
        $currency = strtoupper((string) $store->currency());

        return array_values($rows->map(function (Product $p) use ($store, $currency) {
            $desc = trim(strip_tags((string) ($p->localizedDescription() ?: '')));
            $price = $p->price !== null ? number_format((float) $p->price, 2).' '.$currency : '';

            return [
                'id' => $p->id,
                'name' => $p->localizedName(),
                'description' => $this->clip($desc, 500),
                'price' => $price,
                'url' => $this->media->productPageUrl($store, $p),
                'images' => $this->media->publicImageUrls($p, 4, $store),
            ];
        })->all());
    }

    protected function startDate(string $value): Carbon
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $value, self::TZ);
        if ($parsed === false) {
            return Carbon::tomorrow(self::TZ)->startOfDay();
        }
        $tomorrow = Carbon::tomorrow(self::TZ)->startOfDay();
        if ($parsed->lt($tomorrow)) {
            return $tomorrow;
        }

        return $parsed;
    }

    protected function clip(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }

    protected function localeLabel(string $locale): string
    {
        $labels = [
            'es' => 'español',
            'es_MX' => 'español de México',
            'en' => 'inglés',
            'en_US' => 'inglés de EE.UU.',
            'pt' => 'portugués',
            'pt_BR' => 'portugués de Brasil',
        ];
        $short = explode('_', $locale)[0];

        return $labels[$locale] ?? $labels[$short] ?? $locale;
    }
}