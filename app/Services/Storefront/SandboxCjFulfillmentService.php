<?php

namespace App\Services\Storefront;

use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\ThemeSandboxOrder;

class SandboxCjFulfillmentService
{
    public function __construct(protected CjConnector $cj) {}

    public function submit(ThemeSandboxOrder $order): ThemeSandboxOrder
    {
        $addr = is_array($order->address) ? $order->address : [];
        $country = strtoupper((string) ($addr['country'] ?? 'MX')) ?: 'MX';
        $phone = $this->normalizePhone((string) ($order->phone ?: ($addr['phone'] ?? '')), $country);

        $cjItems = [];
        foreach ($order->items ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $vid = (string) ($line['vid'] ?? '');
            if ($vid === '') {
                continue;
            }
            $cjItems[] = [
                'vid' => $vid,
                'quantity' => max(1, (int) ($line['qty'] ?? 1)),
            ];
        }

        $fromCountry = strtoupper((string) config('cj.from_country_code', 'CN')) ?: 'CN';
        $logisticName = $cjItems !== []
            ? $this->resolveLogisticName($cjItems, $fromCountry, $country)
            : (string) config('cj.default_logistic', 'CJPacket Ordinary');

        $payload = [
            'orderNumber' => $order->number,
            'shippingZip' => (string) ($addr['zip'] ?? ''),
            'shippingCountry' => $this->countryName($country),
            'shippingCountryCode' => $country,
            'shippingProvince' => (string) ($addr['state'] ?? ''),
            'shippingCity' => (string) ($addr['city'] ?? ''),
            'shippingPhone' => $phone,
            'shippingCustomerName' => (string) ($addr['name'] ?? $order->name ?? ''),
            'shippingAddress' => (string) ($addr['address'] ?? ''),
            'email' => (string) $order->email,
            'remark' => 'Sandbox Multidrop '.$order->number,
            'logisticName' => $logisticName,
            'fromCountryCode' => $fromCountry,
            'platform' => 'Api',
            'shopLogisticsType' => 2,
            'orderFlow' => 1,
            'isSandbox' => 1,
            'products' => $cjItems,
        ];
        $order->cj_payload = $payload;

        if ($cjItems === []) {
            $order->fulfillment_status = 'skipped';
            $order->cj_error = 'Sin VID de CJ en el carrito. Importa productos CJ al catálogo para probar el envío real.';
            $order->save();

            return $order;
        }

        $result = $this->cj->createOrder($payload);
        $order->cj_response = $result;

        $externalId = (string) (
            data_get($result, 'data.orderId')
            ?? data_get($result, 'data.order_id')
            ?? data_get($result, 'orderId')
            ?? ''
        );

        $ok = (bool) ($result['success'] ?? false) && $externalId !== '';
        $order->cj_order_id = $externalId !== '' ? $externalId : null;
        $order->fulfillment_status = $ok ? 'submitted' : 'error';
        $order->cj_error = $ok ? null : (string) (
            $result['error'] ?? $result['message'] ?? data_get($result, 'data.message') ?? 'CJ no confirmó el pedido.'
        );

        if ($ok) {
            try {
                $order->cj_order_detail = $this->cj->getOrder($externalId);
            } catch (\Throwable) {
                // La confirmación de createOrder basta; el detalle se puede refrescar en admin.
            }
        }

        $order->save();

        return $order;
    }

    public function refresh(ThemeSandboxOrder $order): ThemeSandboxOrder
    {
        if (! $order->cj_order_id) {
            return $this->submit($order);
        }

        return $this->syncStatus($order, true);
    }

    /**
     * Sincroniza fulfillment/tracking con CJ sin reenviar el pedido.
     * Seguro para el portal del comprador.
     */
    public function syncStatus(ThemeSandboxOrder $order, bool $force = false): ThemeSandboxOrder
    {
        if (! $order->cj_order_id) {
            return $order;
        }

        if (in_array($order->fulfillment_status, ['skipped'], true)) {
            return $order;
        }

        $cacheKey = 'sandbox_cj_sync.'.$order->id;
        if (! $force && cache()->has($cacheKey)) {
            return $order->fresh() ?? $order;
        }

        try {
            $detail = $this->cj->getOrder((string) $order->cj_order_id);
            $order->cj_order_detail = $detail;
        } catch (\Throwable $e) {
            $order->cj_error = 'CJ detalle: '.$e->getMessage();
            $order->save();
            cache()->put($cacheKey, 1, now()->addMinutes(2));

            return $order;
        }

        $detailData = $this->unwrapCjData($order->cj_order_detail);
        $trackNumber = $this->firstString($detailData, [
            'trackNumber', 'trackingNumber', 'logisticTrackingNumber',
        ]);
        $carrier = $this->firstString($detailData, [
            'logisticName', 'trackingProvider', 'carrier',
        ]);

        $track = null;
        try {
            $track = $this->cj->getTracking((string) $order->cj_order_id);
            if (! ($track['success'] ?? false) && $trackNumber !== '') {
                $track = $this->cj->getTrackInfo(['trackNumber' => $trackNumber]);
            }
        } catch (\Throwable) {
            if ($trackNumber !== '') {
                try {
                    $track = $this->cj->getTrackInfo(['trackNumber' => $trackNumber]);
                } catch (\Throwable) {
                    $track = null;
                }
            }
        }

        if (is_array($track)) {
            $order->cj_tracking = $track;
        }

        $trackRows = $this->normalizeTrackRows($track);
        $trackRow = $trackRows[0] ?? [];
        if ($trackNumber === '') {
            $trackNumber = $this->firstString($trackRow, [
                'trackingNumber', 'trackNumber', 'logisticTrackingNumber', 'lastTrackNumber',
            ]);
        }
        if ($carrier === '') {
            $carrier = $this->firstString($trackRow, [
                'logisticName', 'lastMileCarrier', 'carrier',
            ]);
        }

        $orderStatus = strtoupper((string) (
            data_get($detailData, 'orderStatus')
            ?? data_get($detailData, 'status')
            ?? ''
        ));
        $subStatus = strtoupper((string) (data_get($detailData, 'subStatus') ?? ''));
        $trackingStatus = strtolower((string) (
            data_get($trackRow, 'trackingStatus')
            ?? data_get($trackRow, 'status')
            ?? ''
        ));

        $mapped = $this->mapCjToFulfillment(
            $orderStatus,
            $subStatus,
            $trackingStatus,
            $trackNumber,
            data_get($detailData, 'outWarehouseTime')
        );

        if ($mapped !== null) {
            $order->fulfillment_status = $mapped;
        } elseif ((data_get($order->cj_order_detail, 'success') ?? false) || (data_get($track, 'success') ?? false)) {
            // Mantener submitted como mínimo si CJ responde OK
            if (! in_array($order->fulfillment_status, ['shipped', 'delivered'], true)) {
                $order->fulfillment_status = 'submitted';
            }
        }

        if ($trackNumber !== '') {
            $order->tracking_number = $trackNumber;
        }
        if ($carrier !== '') {
            $order->carrier = $carrier;
        }

        // Si CJ ya no reporta error operativo, limpiar
        if (in_array($order->fulfillment_status, ['submitted', 'shipped', 'delivered', 'unfulfilled'], true)) {
            $order->cj_error = null;
        } elseif ($order->fulfillment_status === 'error' && $orderStatus !== '') {
            $order->cj_error = 'CJ status: '.$orderStatus.($subStatus !== '' ? ' / '.$subStatus : '');
        }

        $order->save();
        cache()->put($cacheKey, 1, now()->addMinutes(3));

        return $order;
    }

    /**
     * @param  iterable<ThemeSandboxOrder>  $orders
     * @return list<ThemeSandboxOrder>
     */
    public function syncMany(iterable $orders, bool $force = false): array
    {
        $out = [];
        foreach ($orders as $order) {
            if (! $order instanceof ThemeSandboxOrder) {
                continue;
            }
            try {
                $out[] = $this->syncStatus($order, $force);
            } catch (\Throwable) {
                $out[] = $order;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function normalizeTrackRows(?array $track): array
    {
        if (! is_array($track)) {
            return [];
        }
        $data = $track['data'] ?? $track;
        if (! is_array($data)) {
            return [];
        }
        if ($data !== [] && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [is_array($data) ? $data : []];
    }

    /**
     * @return array<string, mixed>
     */
    protected function unwrapCjData(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $data = $payload['data'] ?? $payload;
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    protected function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $val = trim((string) data_get($row, $key, ''));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    }

    protected function mapCjToFulfillment(
        string $orderStatus,
        string $subStatus,
        string $trackingStatus,
        string $trackNumber,
        mixed $outWarehouseTime
    ): ?string {
        if (in_array($orderStatus, ['CANCELLED', 'CANCELED', 'TRASH', 'CLOSED', 'REJECTED'], true)) {
            return 'error';
        }

        if ($orderStatus === 'DELIVERED'
            || str_contains($trackingStatus, 'delivered')
            || str_contains($trackingStatus, 'entregad')
        ) {
            return 'delivered';
        }

        if ($orderStatus === 'SHIPPED'
            || $trackNumber !== ''
            || ($outWarehouseTime !== null && $outWarehouseTime !== '')
            || str_contains($trackingStatus, 'transit')
            || str_contains($trackingStatus, 'shipped')
            || str_contains($trackingStatus, 'dispatched')
        ) {
            return 'shipped';
        }

        if ($orderStatus === 'UNSHIPPED') {
            if ($subStatus === 'PROCESSING') {
                return 'submitted';
            }

            // PENDING u otros: pagado, preparando envío
            return 'unfulfilled';
        }

        if (in_array($orderStatus, ['CREATED', 'IN_CART'], true)) {
            return 'submitted';
        }

        if ($orderStatus === 'UNPAID') {
            return 'unfulfilled';
        }

        if ($orderStatus !== '') {
            return 'submitted';
        }

        return null;
    }

    /**
     * @param  list<array{vid: string, quantity: int}>  $cjItems
     */
    protected function resolveLogisticName(array $cjItems, string $fromCountry, string $toCountry): string
    {
        $default = (string) config('cj.default_logistic', 'CJPacket Ordinary');
        try {
            $result = $this->cj->calculateFreight([
                'startCountryCode' => $fromCountry,
                'endCountryCode' => $toCountry,
                'products' => $cjItems,
            ]);
            if (! ($result['success'] ?? false)) {
                return $default;
            }
            $candidates = $this->flattenFreightRows($result);
            foreach ($candidates as $row) {
                $name = (string) ($row['logisticName'] ?? $row['enName'] ?? $row['logistic'] ?? '');
                if ($name !== '') {
                    return $name;
                }
            }
        } catch (\Throwable) {
            // Usar fallback.
        }

        return $default;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function flattenFreightRows(array $result): array
    {
        $data = $result['data'] ?? $result;
        if (! is_array($data)) {
            return [];
        }
        foreach (['freight', 'freightList', 'freightTrialList', 'logisticList', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }
        if ($data !== [] && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return is_array($data) ? [$data] : [];
    }

    protected function countryName(string $code): string
    {
        return match (strtoupper($code)) {
            'MX' => 'Mexico',
            'US' => 'United States',
            'CA' => 'Canada',
            'ES' => 'Spain',
            'AR' => 'Argentina',
            'CO' => 'Colombia',
            'CL' => 'Chile',
            'PE' => 'Peru',
            'GB', 'UK' => 'United Kingdom',
            'CN' => 'China',
            default => strtoupper($code),
        };
    }

    protected function normalizePhone(string $phone, string $country): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits === '') {
            return $phone;
        }
        if ($country === 'MX' && ! str_starts_with($digits, '52') && strlen($digits) === 10) {
            return '+52'.$digits;
        }
        if (! str_starts_with($phone, '+')) {
            return '+'.$digits;
        }

        return $phone;
    }
}
