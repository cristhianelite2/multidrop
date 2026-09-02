<?php

namespace App\Domain\AI;

class ProductDescriptionGenerationService
{
    public function __construct(protected AiTaskRouter $ai) {}

    /**
     * @param  list<array{name?: string, value?: string}>|array<int, mixed>  $details
     * @return array{success: bool, description?: string, error?: string, provider?: string}
     */
    public function generate(string $name, string $slug = '', array $details = []): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA (ia.ceballosleon.com) en General.',
            ];
        }

        $name = $this->ensureUtf8(trim($name));
        if ($name === '') {
            return ['success' => false, 'error' => 'Escribe un nombre de producto primero.'];
        }

        $slug = $this->ensureUtf8(trim($slug));
        $detailLines = $this->formatDetails($details);

        $system = <<<'TXT'
Eres copywriter ecommerce especializado en fichas de producto que convierten.
Redacta una descripción de venta clara, persuasiva y natural en el mismo idioma del nombre del producto.
Usa el nombre, el slug (pistas de keywords) y los detalles/especificaciones como fuente de verdad.
Estructura sugerida (sin títulos markdown):
1) Gancho de 1-2 frases con beneficio principal.
2) 3-6 beneficios concretos (puedes usar viñetas con •).
3) Cierre breve con confianza / uso / para quién es.
Reglas:
- No inventes certificaciones, garantías ni cifras que no estén en los datos.
- No uses HTML, markdown (#, **, ```) ni JSON.
- No digas "descripción:", "aquí tienes" ni meta-comentarios.
- Máximo ~1200 caracteres. Solo el texto de la descripción.
TXT;

        $userParts = ["Nombre del producto:\n{$name}"];
        if ($slug !== '') {
            $userParts[] = "Slug / URL:\n{$slug}";
        }
        if ($detailLines !== '') {
            $userParts[] = "Detalles / especificaciones:\n{$detailLines}";
        } else {
            $userParts[] = "Detalles / especificaciones:\n(sin filas; redacta a partir del nombre y el slug)";
        }
        $userParts[] = 'Genera la descripción de venta del producto.';

        $result = $this->ai->chat('product_generate_description', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => implode("\n\n", $userParts)],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'MIIA no pudo generar la descripción',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $description = $this->sanitizeDescription((string) ($result['content'] ?? ''));
        if ($description === '') {
            return ['success' => false, 'error' => 'MIIA devolvió una descripción vacía.'];
        }

        return [
            'success' => true,
            'description' => mb_substr($description, 0, 5000, 'UTF-8'),
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    /**
     * @param  list<array{name?: string, value?: string}>|array<int, mixed>  $details
     */
    protected function formatDetails(array $details): string
    {
        $lines = [];
        foreach ($details as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = $this->ensureUtf8(trim((string) ($row['name'] ?? $row['label'] ?? $row['key'] ?? '')));
            $value = $this->ensureUtf8(trim((string) ($row['value'] ?? '')));
            if ($label === '' && $value === '') {
                continue;
            }
            if ($label === '') {
                $lines[] = '• '.$value;
            } elseif ($value === '') {
                $lines[] = '• '.$label;
            } else {
                $lines[] = '• '.$label.': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    protected function sanitizeDescription(string $text): string
    {
        $text = $this->ensureUtf8($text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^```[\w]*\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
        $text = preg_replace('/^(?:descripci[oó]n|description|texto)\s*:\s*/iu', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $this->ensureUtf8(trim($text, " \t\n\r\0\x0B\"'«»"));
    }

    protected function ensureUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($clean)) {
            $text = $clean;
        }

        // Último recurso: json encode/decode sustituye bytes inválidos
        $encoded = json_encode($text, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $decoded = json_decode($encoded, true);
            if (is_string($decoded)) {
                $text = $decoded;
            }
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    }
}
