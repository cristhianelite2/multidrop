<?php

namespace App\Console\Commands;

use App\Domain\Suppliers\Cj\CjProductSyncService;
use App\Models\Product;
use App\Services\Storefront\DesignThemeService;
use Illuminate\Console\Command;

class EnrichCjProductsCommand extends Command
{
    protected $signature = 'cj:enrich-products {--store= : ID de tienda} {--limit=80} {--skip-themes}';

    protected $description = 'Completa productos CJ ya importados (galería, variantes, descripciones, reseñas, comentarios) y actualiza hooks PDP de plantillas';

    public function handle(CjProductSyncService $sync, DesignThemeService $themes): int
    {
        set_time_limit(0);
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        $limit = max(1, (int) $this->option('limit'));

        $query = Product::query()->where('verified_data->source', 'cj');
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        $total = (clone $query)->count();
        $products = $query->orderBy('id')->limit($limit)->get();
        $this->info('Productos CJ a completar: '.$products->count().' / '.$total.' (límite '.$limit.')');

        $ok = 0;
        $fail = 0;
        $skip = 0;
        foreach ($products as $product) {
            if (! $product->cjPid()) {
                $skip++;
                $this->line("  skip #{$product->id} sin PID");

                continue;
            }
            $this->line("  sync #{$product->id} {$product->name} ({$product->cjPid()})");
            try {
                $fresh = $sync->enrichFromCj($product);
                $images = count(data_get($fresh->verified_data, 'images', []));
                $reviews = count(data_get($fresh->verified_data, 'reviews', []));
                $comments = count(data_get($fresh->verified_data, 'comments', []));
                $variants = $fresh->variants()->count();
                $this->info("    OK imgs={$images} vars={$variants} reseñas={$reviews} comentarios={$comments}");
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->error('    FAIL '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Productos: ok={$ok} fail={$fail} skip={$skip}");

        if (! $this->option('skip-themes')) {
            $up = $themes->upgradeStoredProductPages();
            $this->info('Plantillas actualizadas: themes='.$up['themes'].' store_designs='.$up['stores']);
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
