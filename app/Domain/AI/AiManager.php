<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\Providers\MiiaProvider;
use App\Domain\AI\Providers\OpenAiProvider;
use InvalidArgumentException;

class AiManager
{
    public function driver(?string $name = null): AiProviderInterface
    {
        $name = $name ?: config('ai.default', 'miia');

        return match ($name) {
            'openai' => app(OpenAiProvider::class),
            'miia' => app(MiiaProvider::class),
            default => throw new InvalidArgumentException("AI provider [{$name}] no soportado."),
        };
    }

    /**
     * Chat vía MIIA. El segundo argumento de provider se ignora (solo MIIA).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chatWithFallback(array $messages, array $options = [], ?string $preferred = null): array
    {
        return $this->driver('miia')->chat($messages, $options);
    }
}
