<?php

namespace App\Domain\Creatives\Contracts;

interface CreativeProviderInterface
{
    public function code(): string;

    public function generateFromBrief(array $brief): array;

    public function getStatus(string $jobId): array;
}
