<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AliExpressDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/aliexpress_products.json');
        if (! file_exists($path)) {
            $this->command?->error('Falta storage/app/aliexpress_products.json — corre scripts/scrape_aliexpress_mobile.py');

            return;
        }

        $items = json_decode(file_get_contents($path), true) ?: [];
        if (count($items) < 10) {
            $this->command?->error('Se necesitan al menos 10 productos en el JSON.');

            return;
        }

        $store = DB::table('stores')
            ->where('slug', 'baza')
            ->where('store_type', 'mega')
            ->first();

        if (! $store) {
            $store = DB::table('stores')
                ->where('store_type', 'mega')
                ->where('status', 'live')
                ->orderBy('id')
                ->first();
        }

        if (! $store) {
            $this->command?->error('Mega-tienda BAZA no existe.');

            return;
        }

        $collection = DB::table('collections')->where('store_id', $store->id)->first();
        $supplier = DB::table('suppliers')->where('code', 'cj')->first();

        DB::table('stores')->where('id', $store->id)->update([
            'status' => 'live',
            'name' => 'BAZA',
            'store_type' => 'mega',
            'updated_at' => now(),
        ]);

        $existingIds = DB::table('products')->where('store_id', $store->id)->pluck('id');
        if ($existingIds->isNotEmpty()) {
            DB::table('upsell_rules')->where('store_id', $store->id)->delete();
            DB::table('cross_sell_rules')->where('store_id', $store->id)->delete();
            DB::table('product_variants')->whereIn('product_id', $existingIds)->delete();
            DB::table('supplier_products')->whereIn('product_id', $existingIds)->delete();
            DB::table('product_scores')->whereIn('product_id', $existingIds)->delete();
            DB::table('products')->whereIn('id', $existingIds)->delete();
        }

        $badgeByCat = [
            'lighting' => 'Iluminación',
            'powerbank' => 'Energía',
            'fan' => 'Ventilación',
            'flashlight' => 'Portátil',
            'power' => 'Backup',
        ];

        $costRatio = 0.38;
        $ids = [];

        foreach ($items as $i => $item) {
            $title = trim($item['title'] ?? '');
            $slug = Str::slug(Str::limit($title, 60, '')).'-'.substr($item['product_id'], -6);
            $price = round((float) ($item['price_mxn'] ?? 299), 0);
            if ($price < 50) {
                // Precio scrapeado a veces viene en moneda mal parseada; floor comercial
                $price = max(99, (int) round($price * 8));
            }
            $compare = round((float) ($item['compare_at_price'] ?? $price * 1.55), 0);
            $cost = round($price * $costRatio, 2);
            $cat = $item['category'] ?? 'lighting';
            $stock = 20 + (($i * 7) % 80);
            $image = $item['local_image'] ?? $item['image_url'] ?? null;

            $id = DB::table('products')->insertGetId([
                'store_id' => $store->id,
                'collection_id' => $collection?->id,
                'sku' => 'AE-'.$item['product_id'],
                'name' => $title,
                'slug' => $slug,
                'image_url' => $image,
                'description' => $this->buildDescription($title, $cat, $item['url'] ?? null),
                'price' => $price,
                'compare_at_price' => $compare,
                'currency' => 'MXN',
                'status' => 'live',
                'badge' => $badgeByCat[$cat] ?? 'Tendencia',
                'stock' => $stock,
                'is_featured' => $i < 6,
                'verified_data' => json_encode([
                    'source' => 'aliexpress_es',
                    'aliexpress_product_id' => $item['product_id'],
                    'aliexpress_url' => $item['url'] ?? null,
                    'category' => $cat,
                    'scraped_via' => 'playwright_msite',
                ], JSON_UNESCAPED_UNICODE),
                'creative_data' => json_encode([
                    'hook' => 'Listo cuando se va la luz',
                    'cta' => 'Agregar',
                ], JSON_UNESCAPED_UNICODE),
                'score' => 70 + ($i % 15),
                'score_band' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ids[] = ['id' => $id, 'cat' => $cat, 'price' => $price];

            DB::table('product_variants')->insert([
                'product_id' => $id,
                'sku' => 'AE-'.$item['product_id'].'-DEF',
                'name' => 'Default',
                'price' => $price,
                'cost' => $cost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($supplier) {
                DB::table('supplier_products')->insert([
                    'supplier_id' => $supplier->id,
                    'product_id' => $id,
                    'external_product_id' => $item['product_id'],
                    'cost' => $cost,
                    'shipping_cost' => 89,
                    'stock' => $stock,
                    'raw' => json_encode(['marketplace' => 'aliexpress', 'url' => $item['url'] ?? null]),
                    'synced_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('product_scores')->insert([
                'product_id' => $id,
                'score' => 70 + ($i % 15),
                'band' => 'test',
                'breakdown' => json_encode(['source' => 'aliexpress_seed']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('offers')->where('store_id', $store->id)->delete();
        DB::table('offers')->insert([
            'store_id' => $store->id,
            'type' => 'flash',
            'name' => 'Drop de emergencia — 18h',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(18),
            'stock_threshold' => 25,
            'rules' => json_encode(['message' => 'Selección real desde AliExpress ES']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('coupons')->updateOrInsert(
            ['store_id' => $store->id, 'code' => 'VOLT10'],
            [
                'type' => 'percent',
                'value' => 10,
                'min_subtotal' => 200,
                'max_redemptions' => 200,
                'redemptions_count' => 0,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(5),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Upsell: lighting -> powerbank
        $light = collect($ids)->firstWhere('cat', 'lighting');
        $pb = collect($ids)->firstWhere('cat', 'powerbank');
        $fan = collect($ids)->firstWhere('cat', 'fan');
        if ($light && $pb) {
            DB::table('upsell_rules')->insert([
                'store_id' => $store->id,
                'trigger_product_id' => $light['id'],
                'offer_product_id' => $pb['id'],
                'position' => 'pre_pay',
                'discount_percent' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($pb && $fan) {
            DB::table('cross_sell_rules')->insert([
                'store_id' => $store->id,
                'trigger_product_id' => $pb['id'],
                'offer_product_id' => $fan['id'],
                'priority' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info('VOLT: '.count($items).' productos AliExpress importados (imágenes locales).');
    }

    protected function buildDescription(string $title, string $cat, ?string $url): string
    {
        $map = [
            'lighting' => 'Iluminación portátil recargable para cortes de luz, camping y emergencias.',
            'powerbank' => 'Respaldo de energía para mantener celular, lámpara y ventilador USB cargados.',
            'fan' => 'Ventilación USB/portátil para olas de calor cuando no hay corriente estable.',
            'flashlight' => 'Luz compacta de alta potencia para movilidad durante apagones.',
            'power' => 'Backup de energía / mini UPS para equipos esenciales.',
        ];

        $base = $map[$cat] ?? 'Producto de emergencia energética.';

        return $title."\n\n".$base.($url ? "\n\nReferencia marketplace: ".$url : '');
    }
}
