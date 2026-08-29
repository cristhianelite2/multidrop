<?php

namespace App\Services\Storefront;

/**
 * Convierte el HTML de ficha CJ (Overview + fotos de catálogo) en descripción de vitrina.
 */
class ProductDescriptionHtml
{
    /**
     * @return array{short: string, plain: string, html: string}
     */
    public function present(string $localized, string $supplierHtml, string $short = ''): array
    {
        $fromJson = $this->fromEmbeddedJson($localized);
        if ($fromJson === null) {
            $fromJson = $this->fromEmbeddedJson($supplierHtml);
        }
        $source = $this->pickSource($localized, $supplierHtml);
        if ($fromJson !== null) {
            $html = $fromJson['html'];
            $plain = $fromJson['plain'];
            $jsonShort = $fromJson['short'];
        } else {
            if ($this->isGarbageCopy($source) && $supplierHtml !== '' && $supplierHtml !== $source) {
                $source = $supplierHtml;
            }
            $html = $this->looksLikeHtml($source) ? $this->clean($source) : $this->plainToHtml($this->fullyDecode($source));
            $plain = $this->toPlain($html);
            $jsonShort = '';
        }

        $short = $this->fullyDecode($short);
        $short = trim(strip_tags($short));
        if ($short === '' || $this->isGarbageCopy($short)) {
            $short = $jsonShort !== '' ? $jsonShort : $this->firstSentences($plain, 220);
        }
        if ($this->isGarbageCopy($short)) {
            $short = $this->firstSentences($plain, 220);
        }
        $short = $this->firstSentences($short, 280);

        return [
            'short' => $short,
            'plain' => $plain,
            'html' => $html,
        ];
    }

    /**
     * Texto de vitrina (prosa). Convierte JSON de specs / HTML escapado en oraciones.
     */
    public function prose(string $value, int $max = 2000): string
    {
        $parsed = $this->fromEmbeddedJson($value);
        if ($parsed !== null) {
            $text = $parsed['short'] !== '' ? $parsed['short'] : $parsed['plain'];

            return $this->firstSentences($this->normalizeSpaces($text), $max);
        }
        $plain = $this->normalizeSpaces($value);
        if ($plain === '' || str_starts_with($plain, '{')) {
            return '';
        }

        return $this->firstSentences($plain, $max);
    }

    /**
     * Quita HTML/entidades y deja como máximo un espacio entre palabras.
     */
    public function normalizeSpaces(string $value): string
    {
        $value = $this->fullyDecode($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = str_replace(["\u{00A0}", "\xC2\xA0", "\t", "\r", "\n"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $this->stripSupplierGallery($html);
        $html = preg_replace('/<(script|style|iframe|object|embed|form)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        $html = preg_replace('/\s(?:contenteditable|onclick|onload|onerror)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        $blocks = $this->splitBlocks($html);
        $out = [];
        foreach ($blocks as $block) {
            $converted = $this->convertBlock($block);
            if ($converted !== '') {
                $out[] = $converted;
            }
        }

        $joined = implode("\n", $out);
        $joined = $this->stripEmptyTags($joined);
        $joined = strip_tags($joined, '<p><br><strong><b><em><i><ul><ol><li><h3><h4><dl><dt><dd>');
        $joined = $this->stripEmptyTags($joined);

        return trim($joined);
    }

    protected function pickSource(string $localized, string $supplierHtml): string
    {
        $localized = trim($localized);
        $supplierHtml = trim($supplierHtml);

        if ($localized !== '' && ! $this->isSupplierDump($localized)) {
            return $localized;
        }
        if ($supplierHtml !== '') {
            return $supplierHtml;
        }

        return $localized;
    }

    public function isSupplierDump(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if ($this->isGarbageCopy($value)) {
            return true;
        }
        if (stripos($value, 'cjdropshipping.com') !== false) {
            return true;
        }
        if (preg_match('/product\s*image\s*:/i', $value)) {
            return true;
        }
        if (preg_match('/packing\s*list\s*:/i', $value) && preg_match('/overview\s*:/i', $value)) {
            return true;
        }

        return false;
    }

    public function isGarbageCopy(string $value): bool
    {
        $d = $this->fullyDecode($value);
        $plain = trim(strip_tags($d));

        return $plain !== '' && (
            str_starts_with($plain, '{')
            || str_contains($plain, '"overview"')
            || str_contains($plain, '&quot;overview')
            || preg_match('/^\s*<p>\s*\{/i', $d) === 1
        );
    }

    public function fullyDecode(string $value): string
    {
        $out = $value;
        for ($i = 0; $i < 4; $i++) {
            $next = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $next = str_replace(["\u{00A0}", '&nbsp;'], ' ', $next);
            if ($next === $out) {
                break;
            }
            $out = $next;
        }

        return trim($out);
    }

    /**
     * @return array{short: string, plain: string, html: string}|null
     */
    public function fromEmbeddedJson(string $source): ?array
    {
        $decoded = $this->fullyDecode($source);
        $stripped = trim(strip_tags($decoded));
        $stripped = $this->fullyDecode($stripped);
        if ($stripped === '') {
            return null;
        }
        if ($stripped[0] !== '{') {
            if (! preg_match('/\{[\s\S]*\}/', $stripped, $m)) {
                return null;
            }
            $stripped = $m[0];
        }
        $json = json_decode($stripped, true);
        if (! is_array($json) || $json === []) {
            return null;
        }
        $keys = array_map(fn ($k) => strtolower((string) $k), array_keys($json));
        $known = ['overview', 'description', 'power_type', 'motor_type', 'packing', 'packing_list', 'fan_speed', 'specs', 'blades', 'modes'];
        if (count(array_intersect($keys, $known)) === 0 && count($json) < 2) {
            return null;
        }
        if (isset($json['description']) && is_array($json['description'])) {
            return $this->specMapToCopy($json['description']);
        }
        if (isset($json['description']) && is_string($json['description']) && isset($json['name']) && count($json) <= 6) {
            $inner = $this->fromEmbeddedJson((string) $json['description']);
            if ($inner !== null) {
                return $inner;
            }
        }

        return $this->specMapToCopy($json);
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array{short: string, plain: string, html: string}
     */
    public function specMapToCopy(array $map): array
    {
        $overviewKeys = ['overview', 'description', 'summary', 'about', 'intro', 'resumen'];
        $skip = ['product_image', 'images', 'image', 'productimage'];
        $html = '';
        $short = '';
        $specs = [];
        foreach ($map as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, fn ($v) => is_scalar($v)));
            }
            $value = trim((string) $value);
            if ($value === '' || ! is_string($key)) {
                continue;
            }
            $lk = strtolower($key);
            if (in_array($lk, $skip, true)) {
                continue;
            }
            if (in_array($lk, $overviewKeys, true)) {
                $short = $short !== '' ? $short : $value;
                $html .= '<p>'.e($value).'</p>';
                continue;
            }
            $specs[] = [$this->humanizeSpecKey($key), $value];
        }
        if ($specs !== []) {
            $html .= '<h3>Ficha técnica</h3><dl>';
            foreach ($specs as [$k, $v]) {
                $html .= '<dt>'.e($k).'</dt><dd>'.e($v).'</dd>';
            }
            $html .= '</dl>';
        }
        $plain = $this->toPlain($html);
        if ($short === '') {
            $short = $this->firstSentences($plain, 220);
        }

        return [
            'short' => $short,
            'plain' => $plain,
            'html' => trim($html),
        ];
    }

    public function humanizeSpecKey(string $key): string
    {
        $key = trim($key);
        $map = [
            'power_type' => 'Alimentación',
            'motor_type' => 'Motor',
            'additional_functions' => 'Funciones',
            'blades' => 'Aspas',
            'specs' => 'Medidas',
            'specification' => 'Medidas',
            'modes' => 'Modo',
            'operation_mode' => 'Modo',
            'fan_speed' => 'Velocidades',
            'packing' => 'Incluye',
            'packing_list' => 'Incluye',
            'weight' => 'Peso',
        ];
        $lk = strtolower($key);
        if (isset($map[$lk])) {
            return $map[$lk];
        }

        $label = str_replace(['_', '-'], ' ', $key);

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }

    protected function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<\s*(p|div|br|b|strong|ul|li|h[1-4]|img|span)\b/i', $value);
    }

    protected function stripSupplierGallery(string $html): string
    {
        $html = preg_replace(
            '/<(?:p|div|b|strong|h[1-6])[^>]*>\s*(?:product\s*image|packaging\s*display|product\s*show|图片展示|产品图片)\s*:?\s*<\/(?:p|div|b|strong|h[1-6])>.*$/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/(?:product\s*image|packaging\s*display)\s*:?\s*<div\b[^>]*>.*$/is',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * @return list<string>
     */
    protected function splitBlocks(string $html): array
    {
        $html = preg_replace('/<\/?(div|span)[^>]*>/i', '', $html) ?? $html;
        $parts = preg_split('/(<\/p>|<p\b[^>]*>)/i', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $blocks = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strcasecmp($part, '<p>') === 0 || strcasecmp($part, '</p>') === 0) {
                continue;
            }
            $blocks[] = $part;
        }
        if ($blocks === []) {
            $blocks[] = $html;
        }

        return $blocks;
    }

    protected function convertBlock(string $block): string
    {
        $block = trim($block);
        if ($block === '' || preg_match('/^<(?:b|strong)>\s*<br\s*\/?>\s*<\/(?:b|strong)>$/i', $block)) {
            return '';
        }

        $heading = $this->asHeading($block);
        if ($heading !== null) {
            return $heading;
        }

        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $block) ?? $block;
        $plain = trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace("/[ \t]+/u", ' ', $plain) ?? $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }

        $lines = preg_split("/\n+/", $plain) ?: [$plain];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && $l !== '*' && $l !== '•'));

        $prefix = '';
        if ($lines !== [] && $this->isSectionLabel(trim((string) $lines[0], " \t:"))) {
            $prefix = '<h3>'.e($this->prettyHeading((string) $lines[0])).'</h3>';
            array_shift($lines);
        }
        if ($lines === []) {
            return $prefix;
        }

        $bulletLines = [];
        $specLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?:\*|•|-)\s*(.+)$/u', $line, $m)) {
                $bulletLines[] = trim($m[1]);
                continue;
            }
            if (preg_match('/^([^:]{2,48}):\s*(.+)$/u', $line, $m)) {
                $specLines[] = [trim($m[1]), trim($m[2])];
                continue;
            }
            $bulletLines[] = $line;
        }

        if (count($specLines) >= 2 && $bulletLines === []) {
            $html = $prefix.'<dl>';
            foreach ($specLines as [$k, $v]) {
                $html .= '<dt>'.e($k).'</dt><dd>'.e($v).'</dd>';
            }

            return $html.'</dl>';
        }

        if (count($bulletLines) >= 2 && $specLines === []) {
            $html = $prefix.'<ul>';
            foreach ($bulletLines as $item) {
                $html .= '<li>'.e($item).'</li>';
            }

            return $html.'</ul>';
        }

        if (count($specLines) === 1 && $bulletLines === []) {
            [$k, $v] = $specLines[0];
            if ($this->isSectionLabel($k)) {
                return '<p><strong>'.e($this->prettyHeading($k)).'</strong> — '.e($v).'</p>';
            }

            return $prefix.'<p><strong>'.e($k).':</strong> '.e($v).'</p>';
        }

        if ($prefix !== '' && count($bulletLines) === 1 && $specLines === []) {
            return $prefix.'<p>'.e($bulletLines[0]).'</p>';
        }

        return $prefix.'<p>'.e(implode(' ', $lines)).'</p>';
    }

    protected function asHeading(string $block): ?string
    {
        $plain = trim(html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = trim($plain, " \t\n\r\0\x0B:");
        if ($plain === '') {
            return null;
        }
        if ($this->isSectionLabel($plain)) {
            return '<h3>'.e($this->prettyHeading($plain)).'</h3>';
        }

        return null;
    }

    protected function isSectionLabel(string $label): bool
    {
        $n = strtolower(trim($label, " \t:"));

        return in_array($n, [
            'overview', 'features', 'highlights', 'description',
            'product information', 'product info', 'specifications', 'specification',
            'packing list', 'package', 'what\'s in the box', 'whats in the box',
            'note', 'notes', 'warning',
        ], true);
    }

    protected function prettyHeading(string $label): string
    {
        $label = trim($label, " \t:");
        $map = [
            'overview' => 'Overview',
            'product information' => 'Product information',
            'product info' => 'Product information',
            'packing list' => 'Packing list',
            'specifications' => 'Specifications',
            'specification' => 'Specifications',
        ];

        return $map[strtolower($label)] ?? $label;
    }

    protected function plainToHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $lines = preg_split("/\n+/", str_replace(["\r\n", "\r"], "\n", $text)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines)));
        $bullets = [];
        foreach ($lines as $line) {
            if (preg_match('/^(?:\*|•|-)\s*(.+)$/u', $line, $m)) {
                $bullets[] = trim($m[1]);
            }
        }
        if (count($bullets) === count($lines) && count($bullets) >= 2) {
            $html = '<ul>';
            foreach ($bullets as $item) {
                $html .= '<li>'.e($item).'</li>';
            }

            return $html.'</ul>';
        }

        return '<p>'.nl2br(e($text), false).'</p>';
    }

    protected function toPlain(string $html): string
    {
        $plain = trim(html_entity_decode(strip_tags(str_replace(['</li>', '</p>', '</h3>', '</dt>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace("/[ \t]+/u", ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{3,}/u", "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    protected function firstSentences(string $plain, int $max): string
    {
        $plain = trim(preg_replace('/^(overview|product information|packing list)\s*:?\s*/im', '', $plain) ?? $plain);
        if (mb_strlen($plain) <= $max) {
            return $plain;
        }
        $cut = mb_substr($plain, 0, $max);
        $dot = mb_strrpos($cut, '.');
        if ($dot !== false && $dot > 80) {
            return trim(mb_substr($cut, 0, $dot + 1));
        }

        return rtrim($cut).'…';
    }

    protected function stripEmptyTags(string $html): string
    {
        $prev = null;
        $out = $html;
        while ($prev !== $out) {
            $prev = $out;
            $out = preg_replace('/<(p|b|strong|div|span|h[1-6])>\s*(?:<br\s*\/?>)?\s*<\/\1>/i', '', $out) ?? $out;
            $out = preg_replace('/(<br\s*\/?>\s*){2,}/i', '<br>', $out) ?? $out;
        }

        return trim($out);
    }
}
