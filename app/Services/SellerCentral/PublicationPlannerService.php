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

    /** Canales marcados por defecto en el formulario (sin Twitter, LinkedIn ni Gmail). */
    public const DEFAULT_CHANNELS = ['facebook', 'instagram', 'tiktok', 'youtube'];

    /**
     * Ángulos editoriales para variar el tipo de publicación.
     *
     * @var array<string, array{label: string, brief: string}>
     */
    public const THEMES = [
        'mix' => [
            'label' => 'Mix automático (rota ángulos)',
            'brief' => 'Rota ángulos entre problema/solución, beneficio cotidiano, prueba social, tip de uso, storytelling y urgencia suave.',
        ],
        'problema_solucion' => [
            'label' => 'Problema → solución',
            'brief' => 'Abre con un dolor concreto y cotidiano; presenta el producto como la solución clara, con beneficio tangible y CTA.',
        ],
        'beneficio_diario' => [
            'label' => 'Beneficio del día a día',
            'brief' => 'Muestra cómo el producto mejora una rutina real (mañana, trabajo, viaje, hogar). Escena concreta, no lista de specs.',
        ],
        'prueba_social' => [
            'label' => 'Prueba social / confianza',
            'brief' => 'Enfócate en confianza, calidad percibida y por qué la gente lo elige. Sin inventar reviews ni cifras.',
        ],
        'tutorial_tip' => [
            'label' => 'Tip / cómo usarlo',
            'brief' => 'Comparte un tip práctico de uso o setup en 3 pasos cortos. Útil, accionable y con CTA al final.',
        ],
        'storytelling' => [
            'label' => 'Mini historia',
            'brief' => 'Cuenta una mini escena (antes/después o momento clave) donde el producto encaja de forma natural.',
        ],
        'urgencia_suave' => [
            'label' => 'Urgencia / oferta suave',
            'brief' => 'Motiva a actuar hoy con urgencia suave (disponibilidad, momento ideal). Sin mentiras ni “última pieza” inventada.',
        ],
        'lifestyle' => [
            'label' => 'Lifestyle / vibra',
            'brief' => 'Tone aspiracional o cozy: vibra, estética y sensación de uso. Menos features, más sensación.',
        ],
    ];

    public function __construct(
        protected AiTaskRouter $ai,
        protected ProductMarketingMediaService $media,
    ) {
    }

    /**
     * Construye el calendario de publicaciones y genera el copy con MIIA (una llamada por día).
     *
     * @param  array{days: int, per_day: int, channels: list<string>, product_ids: array<int, int>, start_date: string, format: string, theme?: string, theme_notes?: string}  $config
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
            $channels = self::DEFAULT_CHANNELS;
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
        $theme = $this->normalizeTheme((string) ($config['theme'] ?? 'mix'));
        $themeNotes = $this->clip(trim((string) ($config['theme_notes'] ?? '')), 400);
        $slots = $this->slots($store, $start, $days, $perDay, $channels, $products, $format, $theme, $themeNotes);
        $errors = $this->generateCopy($store, $slots, $theme, $themeNotes);

        return ['slots' => $slots, 'errors' => $errors];
    }

    protected function normalizeTheme(string $theme): string
    {
        $theme = trim($theme);

        return isset(self::THEMES[$theme]) ? $theme : 'mix';
    }

    /**
     * @param  list<array<mixed>>  $slots
     * @return list<array<mixed>>
     */
    protected function slots(
        Store $store,
        Carbon $start,
        int $days,
        int $perDay,
        array $channels,
        array $products,
        string $format,
        string $theme,
        string $themeNotes
    ): array {
        $slots = [];
        $count = count($products);
        $themeKeys = array_keys(array_diff_key(self::THEMES, ['mix' => true]));

        for ($d = 0; $d < $days; $d++) {
            $date = $start->copy()->addDays($d);
            for ($s = 0; $s < $perDay; $s++) {
                $idx = $d * $perDay + $s;
                $product = $products[$idx % $count];
                $network = $channels[$idx % count($channels)];
                $hour = self::SLOT_HOURS[$s % count(self::SLOT_HOURS)];
                $scheduled = Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d').sprintf(' %02d:00', $hour), self::TZ);
                $slotTheme = $theme === 'mix'
                    ? $themeKeys[$idx % count($themeKeys)]
                    : $theme;

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
                    'theme' => $slotTheme,
                    'theme_label' => self::THEMES[$slotTheme]['label'] ?? $slotTheme,
                    'theme_brief' => self::THEMES[$slotTheme]['brief'] ?? '',
                    'theme_notes' => $themeNotes,
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
    protected function generateCopy(Store $store, array &$slots, string $theme, string $themeNotes): array
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
            $context = $this->dayContext(
                $store,
                $day,
                $date,
                $locale,
                $currency,
                collect($indexes)->map(fn ($i) => $slots[$i])->all(),
                $theme,
                $themeNotes
            );
            $result = $this->ai->chat('seller_publication', [
                ['role' => 'system', 'content' => $this->systemPrompt($locale)],
                ['role' => 'user', 'content' => $context],
            ], [
                'temperature' => 0.75,
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
                $topic = $this->clip((string) ($post['topic'] ?? ''), 255);
                if ($topic === '') {
                    $topic = (string) ($slots[$slotIndex]['theme_label'] ?? '');
                }
                $slots[$slotIndex]['topic'] = $topic;
                $slots[$slotIndex]['content'] = $this->withRules($slots[$slotIndex]['network'], (string) ($post['content'] ?? ''));
            }
        }

        foreach ($slots as &$slot) {
            if (trim((string) $slot['content']) === '') {
                $slot['content'] = $this->fallbackContent($slot);
            }
            if (trim((string) ($slot['topic'] ?? '')) === '') {
                $slot['topic'] = (string) ($slot['theme_label'] ?? 'Publicación de producto');
            }
        }
        unset($slot);

        return $errors;
    }

    /**
     * @param  list<array<mixed>>  $daySlots
     */
    protected function dayContext(
        Store $store,
        int $day,
        string $date,
        string $locale,
        string $currency,
        array $daySlots,
        string $theme,
        string $themeNotes
    ): string {
        $lines = [];
        $lines[] = "Tienda: {$store->name} · Idioma: {$locale} · Moneda: {$currency} · Fecha del día: {$date}";
        $themeMeta = self::THEMES[$theme] ?? self::THEMES['mix'];
        $lines[] = 'Tema editorial del plan: '.$themeMeta['label'].' — '.$themeMeta['brief'];
        if ($themeNotes !== '') {
            $lines[] = 'Notas del operador (respétalas): '.$themeNotes;
        }
        $lines[] = 'Genera UNA publicación real de red social por slot (no un titular pobre ni un anuncio genérico). Orden y canal obligatorios:';
        foreach ($daySlots as $slot) {
            $head = "Slot {$slot['slot_index']} · Canal: {$slot['network']}";
            $head .= ' · Ángulo: '.($slot['theme_label'] ?? '').' — '.($slot['theme_brief'] ?? '');
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
        $lines[] = 'Requisitos de copy: gancho fuerte en la 1ª línea; 2–4 líneas de desarrollo con beneficio concreto o escena; CTA claro (y URL si existe). Varía redacción entre slots. topic = nombre corto del ángulo (no vacío). No inventes precios, reviews ni datos.';

        return implode("\n", $lines);
    }

    protected function systemPrompt(string $locale): string
    {
        $localeLabel = $this->localeLabel($locale);

        return <<<PROMPT
Eres copywriter senior de e-commerce y community manager. Escribes publicaciones listas para publicar en {$localeLabel}.

Objetivo: cada post debe parecer escrito por un humano experto (UGC / social commerce), NO un resumen pobre del nombre del producto.

Estructura mínima por post:
1) Gancho (pregunta, escena o contraste) en la primera línea.
2) Desarrollo: 2–5 líneas con beneficio tangible, uso concreto o mini-historia según el ángulo del slot.
3) CTA natural (comprar, ver, guardar). Si hay URL del producto, inclúyela al final.

Reglas:
- Respeta el ÁNGULO/TEMA de cada slot; el "topic" resume ese ángulo en ≤80 caracteres.
- Longitudes: Twitter/X ~220–280, Instagram/TikTok/Facebook 350–900 (máx 2000), LinkedIn 400–900 (máx 1500), YouTube 500–1500, Gmail 400–1200.
- Instagram/TikTok: 2–5 hashtags relevantes al final. LinkedIn: sin emojis. Twitter: máx 1–2 emojis.
- Prohibido: solo pegar el nombre del producto + “¡Consigue el tuyo hoy!”; tono de anuncio de IA; “revolucionario”, “descubre”, “no te pierdas”, “ideal para” sin sustancia; inventar reviews, descuentos o specs.
- Usa solo datos del contexto (nombre, descripción, precio, URL).
- Responde ÚNICAMENTE con un arreglo JSON UTF-8 (sin markdown), misma cantidad de elementos que slots:
[{"canal":"instagram","topic":"Ángulo corto","content":"Texto completo con saltos \\n"}]
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
        $name = trim((string) ($slot['product_name'] ?? ''));
        $desc = trim((string) ($slot['product_description'] ?? ''));
        $url = trim((string) ($slot['product_url'] ?? ''));
        $theme = (string) ($slot['theme'] ?? 'beneficio_diario');
        $hook = match ($theme) {
            'problema_solucion' => $name !== ''
                ? "¿Te suena familiar ese momento en que {$name} habría cambiado todo?"
                : '¿Te pasa esto a diario y ya te cansaste?',
            'tutorial_tip' => $name !== ''
                ? "3 formas simples de aprovechar tu {$name}:"
                : 'Guarda este tip para usarlo hoy:',
            'storytelling' => $name !== ''
                ? "Había un día normal… hasta que entró {$name}."
                : 'Pequeño cambio, gran diferencia en la rutina.',
            'urgencia_suave' => $name !== ''
                ? "Si {$name} ya estaba en tu lista, este es buen momento."
                : 'Si lo estabas pensando, hoy tiene más sentido.',
            'lifestyle' => $name !== ''
                ? "Esa vibra de casa/oficina ordenada empieza con detalles como {$name}."
                : 'Detalles que se sienten bien todos los días.',
            'prueba_social' => $name !== ''
                ? "La gente no busca mil gadgets: busca algo que cumpla. {$name} va a eso."
                : 'Menos promesas, más producto que sí se usa.',
            default => $name !== ''
                ? "Esto es lo que cambia cuando tienes {$name} a la mano:"
                : 'Un detalle que simplifica el día:',
        };

        $body = $desc !== ''
            ? $this->clip($desc, 180)
            : ($name !== '' ? "Diseñado para usarse de verdad, no para quedar en el cajón." : 'Beneficio concreto, sin relleno.');

        $cta = $url !== '' ? "Míralo aquí: {$url}" : 'Encuéntralo en la tienda.';

        $text = $hook."\n\n".$body."\n\n".$cta;
        if (in_array($slot['network'], ['instagram', 'tiktok'], true)) {
            $text .= "\n\n#producto #tienda #oferta";
        }

        return $this->withRules((string) $slot['network'], $text);
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