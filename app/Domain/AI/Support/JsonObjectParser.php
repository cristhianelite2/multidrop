<?php

namespace App\Domain\AI\Support;

use Illuminate\Support\Facades\Log;

/**
 * Parser tolerante de JSON emitido por modelos (MIIA/OpenAI):
 * markdown fences, comillas tipográficas, truncados y saltos crudos en strings.
 */
class JsonObjectParser
{
    public static function sanitize(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Quitar BOM y bloques markdown ```json ... ```
        $content = preg_replace("/^\xEF\xBB\xBF/", '', $content) ?? $content;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $fence)) {
            $content = trim($fence[1]);
        }

        // Comillas tipográficas → ASCII
        $content = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", '«', '»', '“', '”', '‘', '’'],
            ['"', '"', "'", "'", '"', '"', '"', '"', "'", "'"],
            $content
        );

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return trim($content);
    }

    public static function extractObject(string $content, bool $allowPartial = false): ?string
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $ch = $content[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;

                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;

                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        if ($allowPartial && $depth > 0) {
            return substr($content, $start);
        }

        return null;
    }

    public static function repairTruncated(string $json): ?string
    {
        $json = trim($json);
        if ($json === '' || ! str_starts_with($json, '{')) {
            return null;
        }

        $json = preg_replace('/,\s*"[^"]*"\s*:\s*$/s', '', $json) ?? $json;
        $json = preg_replace('/,\s*$/', '', $json) ?? $json;

        $openBraces = substr_count($json, '{') - substr_count($json, '}');
        $openBrackets = substr_count($json, '[') - substr_count($json, ']');

        if ($openBraces <= 0 && $openBrackets <= 0) {
            return $json;
        }

        if (preg_match('/"[^"\\\\]*$/s', $json)) {
            $json .= '"';
        }

        $json .= str_repeat(']', max(0, $openBrackets));
        $json .= str_repeat('}', max(0, $openBraces));

        return $json;
    }

    public static function fixSyntax(string $json): string
    {
        $json = preg_replace('/\/\/[^\n\r]*/', '', $json) ?? $json;
        $json = preg_replace('/\/\*[\s\S]*?\*\//', '', $json) ?? $json;
        // Patrón típico del modelo: "tipo": "producto": "texto" → coma entre valores
        $json = preg_replace('/"([^"]+)"\s*:\s*"([^"]*)"\s*:\s*"/', '"$1": "$2", "detail": "', $json) ?? $json;
        // Valores sin comillas tras dos puntos (heurística conservadora)
        $json = preg_replace(
            '/:\s*([A-Za-zÁÉÍÓÚáéíóúÑñ][A-Za-zÁÉÍÓÚáéíóúÑñ0-9_\-\s]{0,80})(\s*[,}\]])/u',
            ': "$1"$2',
            $json
        ) ?? $json;
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;

        return trim($json);
    }

    public static function escapeRawNewlines(string $json): string
    {
        return preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"/s',
            function (array $match): string {
                return str_replace(["\r\n", "\n", "\r", "\t"], ['\\n', '\\n', '\\n', '\\t'], $match[0]);
            },
            $json
        ) ?? $json;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $content): ?array
    {
        $content = self::sanitize($content);
        if ($content === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $content,
            self::extractObject($content),
            self::repairTruncated(self::extractObject($content, allowPartial: true) ?? ''),
        ])));

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $candidate = self::fixSyntax($candidate);
            $decoded = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }

            $fixed = self::escapeRawNewlines($candidate);
            if ($fixed !== $candidate) {
                $decoded = json_decode($fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    public static function logFailure(string $context, string $content): void
    {
        Log::warning($context.': JSON no parseable', [
            'len' => strlen($content),
            'preview' => mb_substr($content, 0, 400),
        ]);
    }
}
