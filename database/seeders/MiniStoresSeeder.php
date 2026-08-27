<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiniStoresSeeder extends Seeder
{
    public function run(): void
    {
        $marketId = DB::table('markets')->where('code', 'MX')->value('id');
        $brandId = DB::table('brands')->orderBy('id')->value('id');

        if (! $marketId || ! $brandId) {
            return;
        }

        $hasParent = Schema::hasColumn('stores', 'parent_id');

        // 1) BAZA = mega pública (antes estaba como mini emergency-power)
        $baza = DB::table('stores')
            ->where(function ($q) {
                $q->where('slug', 'baza')
                    ->orWhere(function ($q2) {
                        $q2->where('slug', 'emergency-power')->where('name', 'BAZA');
                    })
                    ->orWhere(function ($q3) {
                        $q3->where('store_type', 'mega')->where('slug', 'mega');
                    });
            })
            ->orderByRaw("CASE WHEN slug = 'baza' THEN 0 WHEN name = 'BAZA' THEN 1 ELSE 2 END")
            ->first();

        if ($baza && $baza->slug === 'mega') {
            // Convertir el mega genérico a BAZA si no hay otro BAZA
            $existingBaza = DB::table('stores')->where('slug', 'baza')->first();
            if (! $existingBaza) {
                DB::table('stores')->where('id', $baza->id)->update([
                    'name' => 'BAZA',
                    'slug' => 'baza',
                    'store_type' => 'mega',
                    'sector' => 'all',
                    'status' => 'live',
                    'parent_id' => $hasParent ? null : null,
                    'updated_at' => now(),
                ]);
                $baza = DB::table('stores')->where('id', $baza->id)->first();
            }
        }

        if ($baza && in_array($baza->slug, ['emergency-power'], true) && $baza->name === 'BAZA') {
            DB::table('stores')->where('id', $baza->id)->update([
                'name' => 'BAZA',
                'slug' => 'baza',
                'store_type' => 'mega',
                'sector' => 'all',
                'status' => 'live',
                'theme' => 'default',
                'settings' => json_encode(['group_by' => ['sector', 'locale', 'problem']]),
                'updated_at' => now(),
            ]);
            if ($hasParent) {
                DB::table('stores')->where('id', $baza->id)->update(['parent_id' => null]);
            }
            $baza = DB::table('stores')->where('id', $baza->id)->first();
        }

        if (! $baza || $baza->slug !== 'baza') {
            $bazaId = DB::table('stores')->insertGetId([
                'brand_id' => $brandId,
                'parent_id' => $hasParent ? null : null,
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
            $baza = DB::table('stores')->where('id', $bazaId)->first();
        } else {
            DB::table('stores')->where('id', $baza->id)->update([
                'name' => 'BAZA',
                'store_type' => 'mega',
                'sector' => 'all',
                'status' => 'live',
                'updated_at' => now(),
            ]);
            if ($hasParent) {
                DB::table('stores')->where('id', $baza->id)->update(['parent_id' => null]);
            }
            $baza = DB::table('stores')->where('id', $baza->id)->first();
        }

        $bazaId = (int) $baza->id;

        // Dominio raíz de BAZA
        $bazaDomain = DB::table('domains')->where('store_id', $bazaId)->whereNull('path_prefix')->first();
        if (! $bazaDomain) {
            // Liberar path_prefix null si lo tiene otro store
            DB::table('domains')
                ->where('host', 'localhost')
                ->whereNull('path_prefix')
                ->where('store_id', '!=', $bazaId)
                ->delete();

            $existingAny = DB::table('domains')->where('store_id', $bazaId)->first();
            if ($existingAny) {
                DB::table('domains')->where('id', $existingAny->id)->update([
                    'path_prefix' => null,
                    'is_primary' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
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
            }
        }

        // Archivar mega genérico viejo (slug mega) si no es BAZA
        DB::table('stores')
            ->where('slug', 'mega')
            ->where('id', '!=', $bazaId)
            ->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);

        // 2) Mini viva bajo BAZA: Emergency Power
        $emergencyId = $this->upsertMini($marketId, $brandId, $bazaId, [
            'name' => 'Emergency Power',
            'slug' => 'emergency-power',
            'sector' => 'emergencia',
            'theme' => 'urgency',
            'status' => 'live',
            'settings' => ['problem_code' => 'power_outage'],
            'path' => '/emergency-power',
        ], $hasParent);

        // 3) Otras minis (Hogar bajo BAZA; Belleza anidada dentro de Hogar)
        $hogarId = $this->upsertMini($marketId, $brandId, $bazaId, [
            'name' => 'Hogar Agil',
            'slug' => 'hogar-agil',
            'sector' => 'hogar',
            'theme' => 'calm',
            'status' => 'draft',
            'settings' => ['problem_code' => 'home_organization'],
            'path' => '/hogar-agil',
        ], $hasParent);

        $this->upsertMini($marketId, $brandId, $hogarId ?: $bazaId, [
            'name' => 'Belleza Express',
            'slug' => 'belleza-express',
            'sector' => 'belleza',
            'theme' => 'glow',
            'status' => 'draft',
            'settings' => ['problem_code' => 'beauty_routine'],
            'path' => '/belleza-express',
        ], $hasParent);

        $this->upsertMini($marketId, $brandId, $bazaId, [
            'name' => 'Fit Pocket',
            'slug' => 'fit-pocket',
            'sector' => 'fitness',
            'theme' => 'energy',
            'status' => 'paused',
            'settings' => ['problem_code' => 'home_fitness'],
            'path' => '/fit-pocket',
        ], $hasParent);

        // Mini anidada extra bajo Emergency Power (demo mini→mini)
        $this->upsertMini($marketId, $brandId, $emergencyId ?: $bazaId, [
            'name' => 'Power Kits',
            'slug' => 'power-kits',
            'sector' => 'emergencia',
            'theme' => 'urgency',
            'status' => 'live',
            'settings' => ['problem_code' => 'power_outage', 'nested_under' => 'emergency-power'],
            'path' => '/power-kits',
        ], $hasParent);

        // Colecciones / productos siguen en BAZA mega
        DB::table('collections')
            ->whereIn('store_id', function ($q) {
                $q->select('id')->from('stores')->where('slug', 'emergency-power');
            })
            ->update(['store_id' => $bazaId, 'updated_at' => now()]);

        // Brand name
        DB::table('brands')->where('id', $brandId)->update([
            'name' => 'BAZA',
            'slug' => 'baza',
            'tagline' => 'Mega-tienda con mini-tiendas por necesidad',
            'updated_at' => now(),
        ]);
    }

    protected function upsertMini(int $marketId, int $brandId, int $parentId, array $mini, bool $hasParent): ?int
    {
        $row = DB::table('stores')
            ->where('market_id', $marketId)
            ->where('slug', $mini['slug'])
            ->first();

        $payload = [
            'brand_id' => $brandId,
            'market_id' => $marketId,
            'name' => $mini['name'],
            'slug' => $mini['slug'],
            'sector' => $mini['sector'],
            'store_type' => 'mini',
            'status' => $mini['status'],
            'theme' => $mini['theme'],
            'settings' => json_encode($mini['settings']),
            'updated_at' => now(),
        ];

        if ($hasParent) {
            $payload['parent_id'] = $parentId;
        }

        if ($row) {
            // No convertir BAZA mega si slug chocara (no aplica aquí)
            if ($row->store_type === 'mega') {
                return (int) $row->id;
            }
            DB::table('stores')->where('id', $row->id)->update($payload);
            $storeId = (int) $row->id;
        } else {
            $payload['created_at'] = now();
            $storeId = (int) DB::table('stores')->insertGetId($payload);
        }

        $domain = DB::table('domains')->where('store_id', $storeId)->first();
        if ($domain) {
            DB::table('domains')->where('id', $domain->id)->update([
                'path_prefix' => $mini['path'],
                'is_primary' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('domains')->insert([
                'store_id' => $storeId,
                'host' => 'localhost',
                'path_prefix' => $mini['path'],
                'type' => 'path',
                'is_primary' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $storeId;
    }
}
