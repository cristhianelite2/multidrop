<?php

namespace App\Domain\AI;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ProductTranslationService
{
    public function __construct(protected AiTaskRouter $ai) {}

    /**
     * Traduce name/description/badge al locale pedido vía MIIA.
     *
     * @return array{success: bool, translation?: array{name: string, description: string, badge: string}, error?: string, provider?: string}
     */
    public function translate(Product $product, string $locale, ?string $sourceLocale = null): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA (ia.ceballosleon.com) en General.',
            ];
        }

        $locale = trim($locale);
        if ($locale === '') {
            return ['success' => false, 'error' => 'Locale requerido'];
        }

        $sourceName = (string) $product->name;
        $sourceDesc = (string) ($product->description ?: data_get($product->verified_data, 'description_en', ''));
        $sourceBadge = (string) ($product->badge ?? '');

        if ($sourceLocale) {
            $from = $product->translation($sourceLocale);
            if (! empty($from['name'])) {
                $sourceName = (string) $from['name'];
            }
            if (! empty($from['description'])) {
                $sourceDesc = (string) $from['description'];
            }
            if (! empty($from['badge'])) {
                $sourceBadge = (string) $from['badge'];
            }
        }

        // Fallback: si el locale activo ya tiene texto, úsalo como fuente cuando el principal está vacío
        if ($sourceName === '' || $sourceDesc === '') {
            foreach ($product->translations() as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ($sourceName === '' && ! empty($row['name'])) {
                    $sourceName = (string) $row['name'];
                }
                if ($sourceDesc === '' && ! empty($row['description'])) {
                    $sourceDesc = (string) $row['description'];
                }
                if ($sourceBadge === '' && ! empty($row['badge'])) {
                    $sourceBadge = (string) $row['badge'];
                }
            }
        }

        if (trim($sourceName) === '' && trim($sourceDesc) === '') {
            return ['success' => false, 'error' => 'No hay nombre ni descripción para traducir.'];
        }

        $langLabel = $this->localeLabel($locale);
        $copy = app(\App\Services\Storefront\ProductDescriptionHtml::class);
        if ($copy->isGarbageCopy($sourceDesc) || $copy->fromEmbeddedJson($sourceDesc) !== null) {
            $parsedSrc = $copy->fromEmbeddedJson($sourceDesc);
            $sourceDesc = $parsedSrc['plain'] ?? $copy->prose($sourceDesc);
        }
        $sourceDesc = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', $sourceDesc) ?? $sourceDesc), 0, 2800);

        $system = <<<TXT
Eres un copywriter ecommerce. Traduce al idioma destino conservando hechos del producto.
Idioma destino: {$langLabel} (locale {$locale}).

Responde SOLO con este formato exacto (sin markdown, sin explicaciones):

===NAME===
(título comercial, máx 120 caracteres)
===BADGE===
(badge corto máx 40, o vacío)
===DESCRIPTION===
(prosa de tienda, 2 a 5 oraciones, máx 2000 caracteres. NUNCA un objeto JSON ni HTML de CJ)
===END===
TXT;

        $user = "Traduce este producto:\n\n"
            ."NAME: {$sourceName}\n"
            .'BADGE: '.($sourceBadge !== '' ? $sourceBadge : '(vacío)')."\n"
            ."DESCRIPTION:\n{$sourceDesc}\n";

        $result = $this->ai->chat('product_translate', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'MIIA no pudo traducir',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $content = trim((string) ($result['content'] ?? ''));
        $parsed = $this->parseTranslationPayload($content);

        // Reintento corto solo JSON si el formato delimitado falló
        if ($parsed === null) {
            $retry = $this->ai->chat('product_translate', [
                ['role' => 'system', 'content' => 'Devuelve ÚNICAMENTE un JSON en una sola línea: {"name":"...","badge":"...","description":"..."} sin markdown. description debe ser prosa de tienda (2 a 5 oraciones), nunca un objeto JSON ni HTML de proveedor.'],
                ['role' => 'user', 'content' => "Traduce a {$langLabel}.\nname: {$sourceName}\nbadge: {$sourceBadge}\ndescription: ".mb_substr($sourceDesc, 0, 1200)],
            ]);
            if ($retry['success'] ?? false) {
                $parsed = $this->parseTranslationPayload((string) ($retry['content'] ?? ''));
            }
        }

        if ($parsed === null) {
            Log::warning('MIIA translation parse failed', [
                'locale' => $locale,
                'preview' => mb_substr($content, 0, 500),
            ]);

            return [
                'success' => false,
                'error' => 'MIIA no devolvió una traducción usable. Reintenta en unos segundos.',
                'provider' => 'miia',
                'raw_preview' => mb_substr($content, 0, 280),
            ];
        }

        $copy = app(\App\Services\Storefront\ProductDescriptionHtml::class);
        $translation = [
            'name' => mb_substr(trim((string) ($parsed['name'] ?? $sourceName)), 0, 190),
            'description' => $copy->prose((string) ($parsed['description'] ?? $sourceDesc)) ?: $copy->prose($sourceDesc),
            'badge' => mb_substr(trim((string) ($parsed['badge'] ?? $sourceBadge)), 0, 80),
            'translated_at' => now()->toIso8601String(),
            'provider' => 'miia',
        ];

        if ($translation['name'] === '' && $translation['description'] === '') {
            return [
                'success' => false,
                'error' => 'La traducción de MIIA llegó vacía.',
                'provider' => 'miia',
            ];
        }

        $creative = is_array($product->creative_data) ? $product->creative_data : [];
        $translations = is_array($creative['translations'] ?? null) ? $creative['translations'] : [];
        $translations[$locale] = $translation;
        $creative['translations'] = $translations;
        if (empty($creative['default_locale'])) {
            $creative['default_locale'] = $locale;
        }
        $product->creative_data = $creative;
        $product->save();

        return [
            'success' => true,
            'translation' => $translation,
            'locale' => $locale,
            'provider' => 'miia',
        ];
    }

    /**
     * @return array{name?: string, badge?: string, description?: string}|null
     */
    public function parseTranslationPayload(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Quitar fences markdown
        $content = preg_replace('/^```(?:json|text|txt)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        $content = trim($content);

        // 1) Formato delimitado
        if (preg_match('/===NAME===\s*(.*?)\s*===BADGE===\s*(.*?)\s*===DESCRIPTION===\s*(.*?)\s*===END===/is', $content, $m)) {
            return [
                'name' => trim($m[1]),
                'badge' => trim($m[2]),
                'description' => trim($m[3]),
            ];
        }

        // Variante sin ===END===
        if (preg_match('/===NAME===\s*(.*?)\s*===BADGE===\s*(.*?)\s*===DESCRIPTION===\s*(.*)$/is', $content, $m)) {
            return [
                'name' => trim($m[1]),
                'badge' => trim($m[2]),
                'description' => trim($m[3]),
            ];
        }

        // 2) JSON (directo o embebido)
        $decoded = $this->decodeJsonObject($content);
        if (is_array($decoded)) {
            return $this->normalizeTranslationKeys($decoded);
        }

        // 3) Etiquetas sueltas NAME:/BADGE:/DESCRIPTION:
        if (preg_match('/(?:^|\n)\s*(?:NAME|Título|Titulo)\s*:\s*(.+?)(?=\n\s*(?:BADGE|DESCRIPTION|DESCRIPCIÓN|Descripcion)\s*:|\z)/is', $content, $mName)) {
            $name = trim($mName[1]);
            $badge = '';
            $desc = '';
            if (preg_match('/(?:^|\n)\s*BADGE\s*:\s*(.+?)(?=\n\s*(?:DESCRIPTION|DESCRIPCIÓN|Descripcion)\s*:|\z)/is', $content, $mBadge)) {
                $badge = trim($mBadge[1]);
            }
            if (preg_match('/(?:^|\n)\s*(?:DESCRIPTION|DESCRIPCIÓN|Descripcion)\s*:\s*(.+)$/is', $content, $mDesc)) {
                $desc = trim($mDesc[1]);
            }
            if ($name !== '' || $desc !== '') {
                return compact('name', 'badge') + ['description' => $desc];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $content): ?array
    {
        $candidates = [$content];

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $m)) {
            $candidates[] = $m[0];
        } elseif (preg_match('/\{.*\}/s', $content, $m)) {
            $candidates[] = $m[0];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            // comillas tipográficas → normales
            $candidate = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $candidate);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // intentar escapar saltos de línea crudos dentro de strings (heurística suave)
            $fixed = preg_replace_callback(
                '/"(?:\\\\.|[^"\\\\])*"/s',
                function ($mm) {
                    $s = $mm[0];

                    return str_replace(["\r\n", "\n", "\r"], ['\\n', '\\n', '\\n'], $s);
                },
                $candidate
            );
            if (is_string($fixed)) {
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{name: string, badge: string, description: string}
     */
    protected function normalizeTranslationKeys(array $decoded): array
    {
        $name = $decoded['name']
            ?? $decoded['title']
            ?? $decoded['nombre']
            ?? $decoded['titulo']
            ?? '';
        $badge = $decoded['badge']
            ?? $decoded['etiqueta']
            ?? $decoded['tag']
            ?? '';
        $description = $decoded['description']
            ?? $decoded['descripcion']
            ?? $decoded['descripción']
            ?? $decoded['desc']
            ?? '';
        if (is_array($description)) {
            $description = app(\App\Services\Storefront\ProductDescriptionHtml::class)->specMapToCopy($description)['short'];
        } elseif (is_string($description)) {
            $copy = app(\App\Services\Storefront\ProductDescriptionHtml::class);
            if ($copy->isGarbageCopy($description) || $copy->fromEmbeddedJson($description) !== null) {
                $description = $copy->prose($description);
            }
        }

        return [
            'name' => is_string($name) ? $name : '',
            'badge' => is_string($badge) ? $badge : '',
            'description' => is_string($description) ? $description : '',
        ];
    }

    public function localeLabel(string $locale): string
    {
        return match (true) {
            str_starts_with($locale, 'es') => 'español',
            str_starts_with($locale, 'en') => 'inglés',
            str_starts_with($locale, 'fr') => 'francés',
            str_starts_with($locale, 'de') => 'alemán',
            str_starts_with($locale, 'it') => 'italiano',
            str_starts_with($locale, 'pt') => 'portugués',
            str_starts_with($locale, 'nl') => 'neerlandés',
            str_starts_with($locale, 'pl') => 'polaco',
            str_starts_with($locale, 'sv') => 'sueco',
            str_starts_with($locale, 'da') => 'danés',
            str_starts_with($locale, 'nb'), str_starts_with($locale, 'no') => 'noruego',
            str_starts_with($locale, 'fi') => 'finlandés',
            str_starts_with($locale, 'hu') => 'húngaro',
            str_starts_with($locale, 'cs') => 'checo',
            str_starts_with($locale, 'ro') => 'rumano',
            str_starts_with($locale, 'el') => 'griego',
            default => $locale,
        };
    }
}
