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

        $name = trim($name);
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

        $compressed = trim((string) ($result['content'] ?? ''));
        $compressed = trim($compressed, " \t\n\r\0\x0B\"'«»");
        if ($compressed === '') {
            return ['success' => false, 'error' => 'MIIA devolvió un nombre vacío.'];
        }

        return [
            'success' => true,
            'name' => mb_substr($compressed, 0, 190),
            'provider' => $result['provider'] ?? 'miia',
        ];
    }
}
