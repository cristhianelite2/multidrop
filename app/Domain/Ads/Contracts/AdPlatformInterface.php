<?php

namespace App\Domain\Ads\Contracts;

interface AdPlatformInterface
{
    public function code(): string;

    public function createCampaignDraft(array $payload): array;

    public function pause(string $campaignId): array;

    public function activate(string $campaignId): array;

    public function getInsights(string $campaignId, array $params = []): array;
}
