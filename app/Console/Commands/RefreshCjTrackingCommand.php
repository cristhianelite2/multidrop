<?php

namespace App\Console\Commands;

use App\Domain\Suppliers\Cj\CjConnector;
use App\Models\Fulfillment;
use Illuminate\Console\Command;

class RefreshCjTrackingCommand extends Command
{
    protected $signature = 'cj:refresh-tracking {--limit=40}';

    protected $description = 'Actualiza tracking de fulfillments CJ pendientes';

    public function handle(CjConnector $cj): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = Fulfillment::query()
            ->whereNotNull('external_order_id')
            ->where('external_order_id', 'not like', 'pending-%')
            ->whereIn('status', ['submitted', 'processing', 'shipped', 'in_transit'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $updated = 0;
        foreach ($rows as $row) {
            $result = $cj->getTracking((string) $row->external_order_id);
            if (! ($result['success'] ?? false)) {
                continue;
            }
            $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
            $tracking = (string) (
                data_get($data, 'trackingNumber')
                ?? data_get($data, 'trackNumber')
                ?? data_get($data, 'logisticTrackingNumber')
                ?? ''
            );
            $carrier = (string) (data_get($data, 'logisticName') ?? data_get($data, 'carrier') ?? '');
            $status = (string) (data_get($data, 'status') ?? data_get($data, 'logisticStatus') ?? $row->status);

            $row->tracking_number = $tracking !== '' ? $tracking : $row->tracking_number;
            $row->carrier = $carrier !== '' ? $carrier : $row->carrier;
            $row->status = $status !== '' ? $status : $row->status;
            $raw = is_array($row->raw) ? $row->raw : [];
            $raw['tracking'] = $result;
            $row->raw = $raw;
            $row->save();

            if ($row->order && $tracking !== '') {
                $row->order->fulfillment_status = 'shipped';
                $row->order->status = 'fulfilled';
                $row->order->save();
            }
            $updated++;
        }

        $this->info("Tracking actualizado: {$updated}");

        return self::SUCCESS;
    }
}
