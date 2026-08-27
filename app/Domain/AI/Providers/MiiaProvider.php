<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para ia.ceballosleon.com (MIIA).
 * API OpenAI-compatible: POST /v1/chat/completions + Bearer mia_*.
 */
class MiiaProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'miia';
    }

    public function chat(array $messages, array $options = []): array
    {
        $config = config('ai.providers.miia');
        $apiKey = $config['api_key'] ?? null;

        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'MIIA_API_KEY no configurada (ia.ceballosleon.com)',
                'provider' => $this->name(),
            ];
        }

        $model = $options['model'] ?? $config['model'] ?? 'auto';
        $url = rtrim($config['base_url'], '/').'/v1/chat/completions';

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (array_key_exists('temperature', $options)) {
            $body['temperature'] = $options['temperature'];
        }

        if (! empty($options['services'])) {
            $allowed = config('ai.miia_chat_services', []);
            $services = array_values(array_filter(
                array_map('strval', $options['services']),
                fn ($id) => is_array($allowed) && in_array(strtolower($id), $allowed, true)
            ));
            if ($services !== []) {
                $body['services'] = $services;
            }
        }

        if (! empty($options['exclude_services'])) {
            $body['exclude_services'] = $options['exclude_services'];
        }

        if (! empty($options['tools'])) {
            $body['tools'] = $options['tools'];
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        if (! empty($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        try {
            $response = Http::timeout((int) ($options['timeout'] ?? $config['timeout'] ?? 90))
                ->withToken($apiKey)
                ->acceptJson()
                ->post($url, $body);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $this->formatError($response->json(), $response->body()),
                    'provider' => $this->name(),
                    'raw' => $response->json(),
                ];
            }

            $json = $response->json();

            return [
                'success' => true,
                'content' => $json['choices'][0]['message']['content'] ?? '',
                'raw' => $json,
                'provider' => $this->name(),
            ];
        } catch (\Throwable $e) {
            Log::error('MIIA chat failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $this->name(),
            ];
        }
    }

    /**
     * @param  mixed  $json
     */
    protected function formatError($json, string $fallback): string
    {
        if (! is_array($json)) {
            $trim = trim($fallback);

            return $trim !== '' ? $trim : 'MIIA rechazó la petición.';
        }

        $errors = $json['errors'] ?? null;
        if (is_array($errors) && isset($errors['services.0'])) {
            return 'MIIA rechazó el proveedor. Usa free/auto (gratis) o un servicio válido: groq, openai, gemini…';
        }

        $message = trim((string) ($json['message'] ?? $json['error'] ?? ''));
        if ($message === 'validation.in') {
            return 'MIIA rechazó un parámetro (motor o servicio no válido).';
        }
        if ($message !== '') {
            return $message;
        }

        $trim = trim($fallback);

        return $trim !== '' ? $trim : 'MIIA rechazó la petición.';
    }
}
