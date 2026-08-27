<?php

namespace App\Domain\AI\Contracts;

interface AiProviderInterface
{
    public function name(): string;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{success: bool, content?: string, raw?: mixed, error?: string, provider: string}
     */
    public function chat(array $messages, array $options = []): array;
}
