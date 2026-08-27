<?php

namespace App\Jobs;

use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FulfillOrderWithCjJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId)
    {
    }

    public function handle(CjConnector $cj): void
    {
        $order = Order::query()->with(['items.product.variants', 'store'])->find($this->orderId);
        if (! $order || ! $order->isPaid()) {
            return;
        }

        $cjItems = [];
        foreach ($order->items as $line) {
            $product = $line->product;
            if (! $product instanceof Product || ! $product->isFromCj()) {
                continue;
            }
            $vid = $this->resolveVid($product, $line->product_variant_id);
            if ($vid === '') {
                Log::warning('CJ fulfill missing vid', ['order' => $order->number, 'product' => $product->id]);

                continue;
            }
            $cjItems[] = [
                'vid' => $vid,
                'quantity' => max(1, (int) $line->qty),
            ];
        }

        if ($cjItems === []) {
            $order->fulfillment_status = 'manual';
            $order->save();

            return;
        }

        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        $supplierId = DB::table('suppliers')->where('code', 'cj')->value('id');
        $country = strtoupper((string) ($addr['country'] ?? $order->store?->market?->code ?? 'MX')) ?: 'MX';
        $fromCountry = strtoupper((string) config('cj.from_country_code', 'CN')) ?: 'CN';
        $logisticName = (string) config('cj.default_logistic', 'CJPacket Ordinary');
        $freight = $cj->calculateFreight([
            'startCountryCode' => $fromCountry,
            'endCountryCode' => $country,
            'products' => $cjItems,
        ]);
        if ($freight['success'] ?? false) {
            $rows = data_get($freight, 'data.freight')
                ?? data_get($freight, 'data.freightList')
                ?? data_get($freight, 'data');
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $name = (string) ($row['logisticName'] ?? $row['enName'] ?? '');
                    if ($name !== '') {
                        $logisticName = $name;
                        break;
                    }
                }
            }
        }

        $payload = [
            'orderNumber' => $order->number,
            'shippingZip' => (string) ($addr['zip'] ?? ''),
            'shippingCountry' => match ($country) {
                'MX' => 'Mexico',
                'US' => 'United States',
                'CA' => 'Canada',
                'ES' => 'Spain',
                default => $country,
            },
            'shippingCountryCode' => $country,
            'shippingProvince' => (string) ($addr['state'] ?? ''),
            'shippingCity' => (string) ($addr['city'] ?? ''),
            'shippingAddress' => (string) ($addr['address'] ?? ''),
            'shippingCustomerName' => (string) ($addr['name'] ?? $order->customer_name),
            'shippingPhone' => (string) ($addr['phone'] ?? $order->customer_phone ?? ''),
            'email' => (string) $order->customer_email,
            'logisticName' => $logisticName,
            'fromCountryCode' => $fromCountry,
            'platform' => 'Api',
            'shopLogisticsType' => 2,
            'orderFlow' => 1,
            'products' => $cjItems,
        ];

        $result = $cj->createOrder($payload);
        $externalId = (string) (
            data_get($result, 'data.orderId')
            ?? data_get($result, 'data.order_id')
            ?? data_get($result, 'orderId')
            ?? ''
        );

        Fulfillment::query()->updateOrCreate(
            ['order_id' => $order->id, 'external_order_id' => $externalId !== '' ? $externalId : 'pending-'.$order->id],
            [
                'supplier_id' => $supplierId,
                'status' => ($result['success'] ?? false) && $externalId !== '' ? 'submitted' : 'error',
                'raw' => $result,
            ]
        );

        $order->fulfillment_status = ($result['success'] ?? false) && $externalId !== '' ? 'submitted' : 'error';
        $meta = is_array($order->meta) ? $order->meta : [];
        $meta['cj_create'] = $result;
        $order->meta = $meta;
        $order->save();
    }

    protected function resolveVid(Product $product, ?int $variantId): string
    {
        if ($variantId) {
            $variant = $product->variants->firstWhere('id', $variantId);
            $vid = (string) data_get($variant?->options, 'vid', '');
            if ($vid !== '') {
                return $vid;
            }
        }

        foreach ($product->variants as $variant) {
            $vid = (string) data_get($variant->options, 'vid', '');
            if ($vid !== '') {
                return $vid;
            }
        }

        $list = data_get($product->verified_data, 'variants', []);
        if (is_array($list)) {
            foreach ($list as $row) {
                $vid = (string) ($row['vid'] ?? '');
                if ($vid !== '') {
                    return $vid;
                }
            }
        }

        return '';
    }
}
