<?php

namespace App\Services\Buyer;

use App\Models\Store;
use App\Models\Theme;
use App\Models\ThemeSandboxOrder;

class BuyerPortalLocale
{
    /**
     * Idioma del sandbox de plantilla (sin tienda): lang del diseño HTML.
     */
    public function forTheme(Theme $theme): string
    {
        return $this->localeFromDesign(is_array($theme->design) ? $theme->design : []) ?? 'es';
    }

    public function applyForTheme(Theme $theme): string
    {
        $locale = $this->forTheme($theme);
        app()->setLocale($locale);

        return $locale;
    }

    public function normalize(string $raw): string
    {
        $raw = strtolower(str_replace('-', '_', trim($raw)));
        if ($raw === '') {
            return 'es';
        }

        $short = explode('_', $raw)[0];

        return match ($short) {
            'en' => 'en',
            'pt' => 'pt',
            'es' => 'es',
            default => preg_match('/^[a-z]{2}$/', $short) ? $short : 'en',
        };
    }

    /**
     * Idioma del storefront / preview de tienda.
     * Manda siempre el locale de Admin → General; la plantilla solo es fallback.
     */
    public function forStore(?Store $store, ?array $design = null): string
    {
        if ($store) {
            $fromStore = trim((string) $store->defaultLocale());
            if ($fromStore !== '') {
                return $this->normalize($fromStore);
            }
        }

        if (is_array($design)) {
            $fromDesign = $this->localeFromDesign($design);
            if ($fromDesign !== null) {
                return $fromDesign;
            }
        }

        return 'es';
    }

    /**
     * @param  array<string, mixed>  $design
     */
    protected function localeFromDesign(array $design): ?string
    {
        foreach (['locale', 'default_locale', 'lang', 'language'] as $key) {
            $raw = (string) data_get($design, $key, '');
            if ($raw !== '') {
                return $this->normalize($raw);
            }
        }

        $chunks = [];
        $chunks[] = (string) ($design['html'] ?? '');
        $chunks[] = (string) data_get($design, 'checkout.html', '');
        foreach (($design['pages'] ?? []) as $page) {
            if (! is_array($page)) {
                continue;
            }
            $chunks[] = (string) ($page['html'] ?? '');
        }

        foreach ($chunks as $html) {
            if ($html !== '' && preg_match('/\blang\s*=\s*["\']([a-zA-Z_-]+)/i', $html, $m)) {
                return $this->normalize($m[1]);
            }
        }

        return null;
    }

    public function applyForStore(?Store $store, ?array $design = null): string
    {
        $locale = $this->forStore($store, $design);
        app()->setLocale($locale);

        return $locale;
    }

    /**
     * @return list<string>
     */
    public function pipelineKeys(): array
    {
        return ['confirmed', 'preparing', 'warehouse', 'shipped', 'delivered'];
    }

    /**
     * Índice 0..4 del paso actual (o -1 si error especial).
     */
    public function currentStepIndex(ThemeSandboxOrder $order): int
    {
        $fulfillment = strtolower((string) $order->fulfillment_status);
        $paid = strtolower((string) $order->payment_status) === 'paid';

        if (in_array($fulfillment, ['delivered', 'completed'], true)) {
            return 4;
        }
        if ($fulfillment === 'shipped') {
            return 3;
        }
        if ($fulfillment === 'submitted') {
            return 2;
        }
        if (in_array($fulfillment, ['unfulfilled', 'skipped', 'manual', 'processing'], true)) {
            return $paid ? 1 : 0;
        }
        if ($fulfillment === 'error') {
            return $paid ? 1 : 0;
        }

        return $paid ? 1 : 0;
    }

    /**
     * @return list<array{key:string,label:string,hint:string,state:string,icon:string,date:?string,date_iso:?string}>
     */
    public function pipelineFor(ThemeSandboxOrder $order): array
    {
        $current = $this->currentStepIndex($order);
        $fulfillment = strtolower((string) $order->fulfillment_status);
        $isError = $fulfillment === 'error';
        $isSkipped = $fulfillment === 'skipped';
        $keys = $this->pipelineKeys();
        $icons = [
            'confirmed' => 'check',
            'preparing' => 'box',
            'warehouse' => 'warehouse',
            'shipped' => 'truck',
            'delivered' => 'home',
        ];
        $dates = $this->stepDates($order);

        $steps = [];
        foreach ($keys as $i => $key) {
            if ($i < $current) {
                $state = 'done';
            } elseif ($i === $current) {
                $state = $isError ? 'error' : ($isSkipped && $key === 'preparing' ? 'warn' : 'current');
            } else {
                $state = 'todo';
            }

            $rawDate = $dates[$key] ?? null;
            $steps[] = [
                'key' => $key,
                'label' => __('buyer.status.'.$key.'.label'),
                'hint' => __('buyer.status.'.$key.'.hint'),
                'state' => $state,
                'icon' => $icons[$key],
                'date' => $rawDate ? $this->formatStepDate($rawDate) : null,
                'date_iso' => $rawDate,
            ];
        }

        return $steps;
    }

    /**
     * Fechas estimadas/reales por paso a partir del pedido y datos CJ.
     *
     * @return array<string, ?string> ISO datetimes
     */
    protected function stepDates(ThemeSandboxOrder $order): array
    {
        $created = $order->created_at;
        $updated = $order->updated_at;
        $detail = is_array($order->cj_order_detail) ? $order->cj_order_detail : [];
        $data = is_array(data_get($detail, 'data')) ? data_get($detail, 'data') : $detail;
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        $tracking = is_array($order->cj_tracking) ? $order->cj_tracking : [];
        $trackRows = $tracking['data'] ?? $tracking;
        $trackRow = [];
        if (is_array($trackRows) && array_is_list($trackRows) && isset($trackRows[0]) && is_array($trackRows[0])) {
            $trackRow = $trackRows[0];
        } elseif (is_array($trackRows) && ! array_is_list($trackRows)) {
            $trackRow = $trackRows;
        }

        $cjCreate = $this->parseDate(data_get($data, 'createDate'))
            ?: $this->parseDate(data_get($data, 'storeCreateDate'));
        $cjPayment = $this->parseDate(data_get($data, 'paymentDate'));
        $outWarehouse = $this->parseDate(data_get($data, 'outWarehouseTime'));
        $deliveryTime = $this->parseDate(data_get($trackRow, 'deliveryTime'))
            ?: $this->parseDate(data_get($data, 'deliveredDate'));
        $shippedAt = $outWarehouse
            ?: $this->parseDate(data_get($tracking, 'shipped_at'))
            ?: $this->parseDate(data_get($trackRow, 'lastEventTime'))
            ?: ($order->tracking_number ? ($updated?->toIso8601String()) : null);

        $current = $this->currentStepIndex($order);
        $paid = strtolower((string) $order->payment_status) === 'paid';

        $confirmed = $paid ? ($cjPayment ?: ($created?->toIso8601String())) : null;
        $preparing = $current >= 1 && $created
            ? ($created->copy()->addMinutes(1)->toIso8601String())
            : null;
        $warehouse = $current >= 2
            ? ($cjCreate ?: ($updated?->toIso8601String()))
            : null;
        $shipped = $current >= 3
            ? ($shippedAt ?: ($updated?->toIso8601String()))
            : null;
        $delivered = $current >= 4
            ? ($deliveryTime ?: ($updated?->toIso8601String()))
            : null;

        if ($current === 0 && $confirmed === null && $created) {
            $confirmed = $created->toIso8601String();
        }
        if ($current === 1 && $preparing === null && $updated) {
            $preparing = $updated->toIso8601String();
        }
        if ($current === 2 && $warehouse === null && $updated) {
            $warehouse = $updated->toIso8601String();
        }
        if ($current === 3 && $shipped === null && $updated) {
            $shipped = $updated->toIso8601String();
        }
        if ($current === 4 && $delivered === null && $updated) {
            $delivered = $updated->toIso8601String();
        }

        if ($current < 1) {
            $preparing = null;
        }
        if ($current < 2) {
            $warehouse = null;
        }
        if ($current < 3) {
            $shipped = null;
        }
        if ($current < 4) {
            $delivered = null;
        }

        return [
            'confirmed' => $confirmed,
            'preparing' => $preparing,
            'warehouse' => $warehouse,
            'shipped' => $shipped,
            'delivered' => $delivered,
        ];
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function formatStepDate(string $iso): string
    {
        try {
            $dt = \Illuminate\Support\Carbon::parse($iso)->locale(app()->getLocale());

            return $dt->isoFormat('D MMM YYYY');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
