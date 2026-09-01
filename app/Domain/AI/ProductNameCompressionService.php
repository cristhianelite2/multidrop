<?php

namespace App\Domain\AI;

class ProductNameCompressionService
{
    public function __construct(protected AiTaskRouter $ai) {}

    /**
     * @return array{success: bool, name?: string, error?: string, provider?: string}
     */
    public function compress(string $name): array
    {
        if (! $this->ai->hasMiia()) {
            return [
                'success' => false,
                'error' => 'Configura la API Key de MIIA (ia.ceballosleon.com) en General.',
            ];
        }

        $name = $this->sanitizeTitle($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Escribe un nombre para acortar.'];
        }

        if (mb_strlen($name) <= 80) {
            return [
                'success' => true,
                'name' => $name,
                'provider' => 'miia',
            ];
        }

        $system = <<<'TXT'
Eres copywriter ecommerce. Acorta títulos de producto para tienda online.
Conserva el tipo de producto, marca (si hay) y 2-4 palabras clave de búsqueda.
Elimina relleno, emojis redundantes y repeticiones.
Máximo 80 caracteres. Un solo título, sin comillas ni explicaciones.
PROHIBIDO: contar caracteres, notas entre paréntesis, markdown, JSON o texto extra.
TXT;

        $user = "Acorta este título de producto:\n\n{$name}";

        $result = $this->ai->chat('product_compress_name', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'MIIA no pudo acortar el nombre',
                'provider' => $result['provider'] ?? 'miia',
            ];
        }

        $compressed = $this->sanitizeTitle((string) ($result['content'] ?? ''));
        if ($compressed === '') {
            return ['success' => false, 'error' => 'MIIA devolvió un nombre vacío.'];
        }

        return [
            'success' => true,
            'name' => mb_substr($compressed, 0, 190),
            'provider' => $result['provider'] ?? 'miia',
        ];
    }

    protected function sanitizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (preg_match('/^\{.*"name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $title, $json)) {
            $title = stripcslashes($json[1]);
        }

        $title = trim(explode("\n", $title)[0]);
        $title = preg_replace('/^```[\w]*\s*/', '', $title) ?? $title;
        $title = preg_replace('/\s*```$/', '', $title) ?? $title;
        $title = preg_replace('/^(?:t[ií]tulo|nombre|title)\s*:\s*/iu', '', $title) ?? $title;

        // Quitar anotaciones de longitud: (70 caracteres), *(70 caracteres)*, [80 chars]
        $title = preg_replace('/[\s*_[\]]+[\(\[]\s*\d+\s*(?:car[aá]cter(?:es)?|chars?|characters?)\s*[\)\]]\s*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s*[\(\[]\s*\d+\s*(?:car[aá]cter(?:es)?|chars?|characters?)\s*[\)\]]\s*$/iu', '', $title) ?? $title;

        // Markdown residual al final o envolviendo todo el texto
        $title = preg_replace('/^\*+(.+?)\*+$/u', '$1', $title) ?? $title;
        $title = preg_replace('/\*+([^*]+)\*+$/u', '$1', $title) ?? $title;
        $title = str_replace(['**', '__', '`'], '', $title);

        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return trim($title, " \t\n\r\0\x0B\"'«»");
    }
}
