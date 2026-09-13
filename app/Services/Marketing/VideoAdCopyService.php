<?php

namespace App\Services\Marketing;

use App\Models\MarketingPrompt;

class VideoAdCopyService
{
    /**
     * @return array{ad_headline: string, ad_primary_text: string, ad_cta: string}
     */
    public function fromPrompt(MarketingPrompt $prompt): array
    {
        $analysis = is_array($prompt->analysis) ? $prompt->analysis : [];

        $headline = trim((string) $prompt->hook);
        if ($headline === '') {
            $headline = trim((string) $prompt->name);
        }
        $headline = mb_substr($headline, 0, 120);

        $parts = [];
        foreach (['summary', 'product_angle'] as $key) {
            $value = trim((string) data_get($analysis, $key, ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $scriptExcerpt = $this->scriptExcerpt($prompt);
        if ($scriptExcerpt !== '') {
            $parts[] = $scriptExcerpt;
        }

        $audience = trim((string) $prompt->audience);
        if ($audience !== '' && mb_strlen(implode("\n\n", $parts)) < 320) {
            $parts[] = 'Audiencia: '.$audience;
        }

        $primary = mb_substr(trim(implode("\n\n", array_values(array_unique(array_filter($parts))))), 0, 500);

        return [
            'ad_headline' => $headline,
            'ad_primary_text' => $primary,
            'ad_cta' => $this->resolveCta($analysis, $prompt),
        ];
    }

    protected function scriptExcerpt(MarketingPrompt $prompt): string
    {
        $segments = is_array($prompt->segments) ? $prompt->segments : [];
        $lines = [];
        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $vo = trim((string) ($seg['voiceover'] ?? ''));
            if ($vo !== '') {
                $lines[] = $vo;
            }
        }

        if ($lines !== []) {
            return mb_substr(implode(' ', $lines), 0, 400);
        }

        $script = trim((string) $prompt->script);
        if ($script === '') {
            return '';
        }

        $clean = [];
        foreach (preg_split('/\r\n|\r|\n/', $script) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '=')) {
                continue;
            }
            $line = preg_replace('/^(VOZ|HOOK|CTA|TRANSICIÓN|TALENTO|CÁMARA|VISUAL|AUDIO)\s*:\s*/iu', '', $line) ?? $line;
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return mb_substr(implode(' ', $clean), 0, 400);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    protected function resolveCta(array $analysis, MarketingPrompt $prompt): string
    {
        $candidates = [
            data_get($analysis, 'creative_direction.brand.cta'),
            data_get($analysis, 'cta'),
            data_get($analysis, 'recommended_cta'),
        ];

        foreach ($segments = (is_array($prompt->segments) ? $prompt->segments : []) as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            if (($seg['type'] ?? '') === 'cta') {
                $candidates[] = $seg['voiceover'] ?? null;
                $candidates[] = $seg['text_on_screen'] ?? null;
            }
        }

        foreach ($candidates as $raw) {
            $mapped = $this->mapCta((string) $raw);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return 'SHOP_NOW';
    }

    protected function mapCta(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $upper = strtoupper(str_replace([' ', '-'], '_', $raw));
        $allowed = ['SHOP_NOW', 'LEARN_MORE', 'SIGN_UP', 'ORDER_NOW', 'GET_OFFER'];
        if (in_array($upper, $allowed, true)) {
            return $upper;
        }

        $lower = mb_strtolower($raw);
        if (preg_match('/(compr|shop|tienda|ll[eé]v)/u', $lower)) {
            return 'SHOP_NOW';
        }
        if (preg_match('/(pedir|order|orden)/u', $lower)) {
            return 'ORDER_NOW';
        }
        if (preg_match('/(registr|sign|suscri)/u', $lower)) {
            return 'SIGN_UP';
        }
        if (preg_match('/(oferta|offer|descuent|promo)/u', $lower)) {
            return 'GET_OFFER';
        }
        if (preg_match('/(m[aá]s info|learn|descubr|conoce)/u', $lower)) {
            return 'LEARN_MORE';
        }

        return null;
    }
}
