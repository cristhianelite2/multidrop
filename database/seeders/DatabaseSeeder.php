<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MultidropBootstrapSeeder::class,
            MarketsSeeder::class,
            AliExpressDemoCatalogSeeder::class,
            AdminAccessSeeder::class,
            MiniStoresSeeder::class,
            StorefrontContentSeeder::class,
        ]);
    }
}
