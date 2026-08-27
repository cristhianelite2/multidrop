<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function chat(array $messages, array $options = []): array
    {
        $config = config('ai.providers.openai');
        $apiKey = $config['api_key'] ?? null;

        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'OPENAI_API_KEY no configurada',
                'provider' => $this->name(),
            ];
        }

        $model = $options['model'] ?? $config['model'];
        $url = rtrim($config['base_url'], '/').'/chat/completions';

        try {
            $response = Http::timeout((int) ($options['timeout'] ?? $config['timeout'] ?? 60))
                ->withToken($apiKey)
                ->acceptJson()
                ->post($url, [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $options['temperature'] ?? 0.7,
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => $response->body(),
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
            Log::error('OpenAI chat failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => $this->name(),
            ];
        }
    }
}
