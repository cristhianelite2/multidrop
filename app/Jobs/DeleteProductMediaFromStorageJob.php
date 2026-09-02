<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Storage\ProductMediaMirrorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeleteProductMediaFromStorageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $productId,
        public string $url
    ) {}

    public function handle(ProductMediaMirrorService $mirror): void
    {
        $product = Product::query()->find($this->productId);
        if (! $product) {
            return;
        }

        if ($mirror->isUrlReferencedByProduct($product, $this->url)) {
            return;
        }

        if (! $mirror->deleteStoredMediaFile($product, $this->url)) {
            Log::info('Product media storage delete skipped or failed', [
                'product_id' => $this->productId,
                'url' => $this->url,
            ]);
        }
    }
}
