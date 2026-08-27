<?php

namespace App\Domain\Payments\Contracts;

interface PaymentProviderInterface
{
    public function code(): string;

    /**
     * Create a checkout / payment intent for an order.
     *
     * @return array{success: bool, checkout_url?: string, provider_ref?: string, error?: string}
     */
    public function createCheckout(array $orderPayload): array;

    public function verifyWebhook(array $headers, string $payload): bool;

    /**
     * @return array{status: string, provider_ref?: string, raw?: mixed}
     */
    public function parseWebhook(string $payload): array;
}
