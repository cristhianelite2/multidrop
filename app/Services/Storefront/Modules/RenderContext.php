<?php

namespace App\Services\Storefront\Modules;

/**
 * Contexto de render: el JSON completo de la tienda más la página activa.
 *
 * @phpstan-type Payload array<string, mixed>
 */
class RenderContext
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $page
     */
    public function __construct(
        public array $payload,
        public array $design,
        public array $page,
        public string $staticHtml = '',
        public string $visit = 'desktop',
    ) {}

    public function visit(): string
    {
        return $this->visit === 'mobile' ? 'mobile' : 'desktop';
    }

    public function pluginOn(string $key): bool
    {
        $mods = is_array($this->payload['modules'] ?? null) ? $this->payload['modules'] : [];

        return ($mods[$key] ?? false) === true
            || ($mods[$key] ?? null) === 1
            || ($mods[$key] ?? null) === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public function store(): array
    {
        return is_array($this->payload['store'] ?? null) ? $this->payload['store'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function urls(): array
    {
        return is_array($this->payload['urls'] ?? null) ? $this->payload['urls'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function i18n(): array
    {
        return is_array($this->payload['i18n'] ?? null) ? $this->payload['i18n'] : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function star(): ?array
    {
        $star = $this->payload['star_product'] ?? null;

        return is_array($star) ? $star : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function product(): ?array
    {
        $p = $this->payload['product'] ?? null;

        return is_array($p) ? $p : ($this->star());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        $list = $this->payload['products'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    public function cart(): array
    {
        return is_array($this->payload['cart'] ?? null) ? $this->payload['cart'] : [];
    }
}
