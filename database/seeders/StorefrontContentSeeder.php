<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorefrontContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('roulette_slides')) {
            return;
        }

        $bazaId = DB::table('stores')->where('slug', 'baza')->where('store_type', 'mega')->value('id');
        if (! $bazaId) {
            return;
        }

        if (DB::table('roulette_slides')->where('store_id', $bazaId)->exists()) {
            return;
        }

        $slides = [
            ['kicker' => 'Oferta relámpago', 'title' => 'Hasta 40% en esenciales', 'text' => 'Iluminación, energía y hogar. Cupón activo por tiempo limitado.', 'cta_label' => 'Ver ofertas', 'theme_class' => 's1'],
            ['kicker' => 'Energía portátil', 'title' => 'Power banks listos para todo', 'text' => 'Carga rápida y gran capacidad para no quedarte sin batería.', 'cta_label' => 'Explorar energía', 'theme_class' => 's2'],
            ['kicker' => 'Hogar & clima', 'title' => 'Ventilación al instante', 'text' => 'Mini ventiladores USB y portátiles para el día a día.', 'cta_label' => 'Ver hogar', 'theme_class' => 's3'],
        ];

        foreach ($slides as $i => $slide) {
            DB::table('roulette_slides')->insert([
                'store_id' => $bazaId,
                'kicker' => $slide['kicker'],
                'title' => $slide['title'],
                'text' => $slide['text'],
                'cta_label' => $slide['cta_label'],
                'cta_url' => '#shop',
                'image_url' => null,
                'theme_class' => $slide['theme_class'],
                'sort_order' => $i,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('offers')->where('store_id', $bazaId)->where('type', 'flash')->exists()) {
            DB::table('offers')->insert([
                'store_id' => $bazaId,
                'type' => 'flash',
                'name' => 'Oferta relámpago BAZA',
                'starts_at' => now(),
                'ends_at' => now()->addDays(2),
                'stock_threshold' => 12,
                'rules' => json_encode(['bar_text' => 'Oferta por tiempo limitado', 'show_stock' => true]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
