<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MultidropBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'MX',
            'name' => 'México',
            'locale' => 'es_MX',
            'currency' => 'MXN',
            'timezone' => 'America/Mexico_City',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('markets')->insert([
            [
                'code' => 'US',
                'name' => 'United States',
                'locale' => 'en_US',
                'currency' => 'USD',
                'timezone' => 'America/New_York',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'UK',
                'name' => 'United Kingdom',
                'locale' => 'en_GB',
                'currency' => 'GBP',
                'timezone' => 'Europe/London',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $brandId = DB::table('brands')->insertGetId([
            'name' => 'BAZA',
            'slug' => 'baza',
            'tagline' => 'Mega-tienda con mini-tiendas por necesidad',
            'identity' => json_encode(['primary' => '#0f766e']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bazaId = DB::table('stores')->insertGetId([
            'brand_id' => $brandId,
            'parent_id' => null,
            'market_id' => $marketId,
            'name' => 'BAZA',
            'slug' => 'baza',
            'sector' => 'all',
            'store_type' => 'mega',
            'status' => 'live',
            'theme' => 'default',
            'settings' => json_encode(['group_by' => ['sector', 'locale', 'problem']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('domains')->insert([
            'store_id' => $bazaId,
            'host' => 'localhost',
            'path_prefix' => null,
            'type' => 'path',
            'is_primary' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $problemId = DB::table('problems')->insertGetId([
            'code' => 'power_outage',
            'title' => 'Apagones / cortes eléctricos',
            'description' => 'Necesidad de iluminación, ventilación y energía de respaldo durante cortes.',
            'sector' => 'emergencia',
            'keywords' => json_encode(['apagón', 'lámpara recargable', 'power bank', 'ventilador USB', 'UPS']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('problem_market')->insert([
            'problem_id' => $problemId,
            'market_id' => $marketId,
            'severity' => 80,
            'local_hooks' => json_encode(['calor extremo', 'suministro eléctrico inestable']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emergencyId = DB::table('stores')->insertGetId([
            'brand_id' => $brandId,
            'parent_id' => $bazaId,
            'market_id' => $marketId,
            'name' => 'Emergency Power',
            'slug' => 'emergency-power',
            'sector' => 'emergencia',
            'store_type' => 'mini',
            'status' => 'live',
            'theme' => 'urgency',
            'settings' => json_encode(['problem_code' => 'power_outage']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('domains')->insert([
            'store_id' => $emergencyId,
            'host' => 'localhost',
            'path_prefix' => '/emergency-power',
            'type' => 'path',
            'is_primary' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('collections')->insert([
            'store_id' => $bazaId,
            'problem_id' => $problemId,
            'name' => 'Kit de emergencia energética',
            'slug' => 'kit-emergencia',
            'description' => 'Gama para cortes de luz y calor.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suppliers')->insert([
            'code' => 'cj',
            'name' => 'CJ Dropshipping',
            'credentials' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
