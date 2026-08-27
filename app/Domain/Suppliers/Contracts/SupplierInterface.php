<?php

namespace App\Domain\Suppliers\Contracts;

interface SupplierInterface
{
    public function code(): string;

    public function searchProducts(array $filters): array;

    public function getProduct(string $externalId): array;

    public function getVariants(string $productId): array;

    public function getStock(string $variantId): array;

    public function calculateFreight(array $payload): array;

    public function createOrder(array $payload): array;

    public function getOrder(string $orderId): array;

    public function getTracking(string $orderId): array;
}
