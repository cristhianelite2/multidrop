<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StoreDesign;
use App\Models\Theme;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ThemeLibraryService
{
    public function __construct(
        protected DesignThemeService $themes
    ) {}

    public function ensureActiveDesign(Store $store): StoreDesign
    {
        $active = StoreDesign::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->first();

        if ($active) {
            return $active;
        }

        $legacy = data_get($store->settings, 'design', []);
        $legacy = is_array($legacy) ? $legacy : [];

        $active = StoreDesign::create([
            'store_id' => $store->id,
            'name' => 'Diseño actual',
            'is_active' => true,
            'design' => $legacy !== [] ? $legacy : $this->themes->defaults(),
        ]);

        return $active;
    }

    public function activate(StoreDesign $design): void
    {
        StoreDesign::query()
            ->where('store_id', $design->store_id)
            ->where('id', '!=', $design->id)
            ->update(['is_active' => false]);

        $design->is_active = true;
        $design->save();

        $store = $design->store;
        $payload = is_array($design->design) ? $design->design : $this->themes->defaults();
        $this->themes->save($store, $payload, syncStoreDesign: false);
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function createStoreDesign(Store $store, string $name, array $design, ?int $themeId = null, bool $activate = false): StoreDesign
    {
        $row = StoreDesign::create([
            'store_id' => $store->id,
            'theme_id' => $themeId,
            'name' => $name,
            'is_active' => false,
            'design' => $design,
        ]);

        if ($activate || ! StoreDesign::query()->where('store_id', $store->id)->where('is_active', true)->exists()) {
            $this->activate($row->fresh());
        }

        return $row->fresh();
    }

    public function duplicate(StoreDesign $source, ?string $name = null): StoreDesign
    {
        $copy = StoreDesign::create([
            'store_id' => $source->store_id,
            'theme_id' => $source->theme_id,
            'name' => $name ?: ($source->name.' (copia)'),
            'is_active' => false,
            'design' => [],
        ]);

        $design = $this->copyDesignAssets(
            is_array($source->design) ? $source->design : [],
            'store-designs/'.$source->id,
            'store-designs/'.$copy->id
        );
        $copy->design = $design;
        $copy->save();

        return $copy;
    }

    public function saveAsLibrary(StoreDesign $storeDesign, string $name, ?string $description = null): Theme
    {
        $theme = Theme::create([
            'name' => $name,
            'slug' => $this->uniqueThemeSlug($name),
            'description' => $description,
            'source' => 'clone',
            'design' => [],
            'created_from_store_id' => $storeDesign->store_id,
        ]);

        $design = $this->copyDesignAssets(
            is_array($storeDesign->design) ? $storeDesign->design : [],
            'store-designs/'.$storeDesign->id,
            'themes/'.$theme->id
        );
        $theme->design = $design;
        $theme->save();

        $storeDesign->theme_id = $theme->id;
        $storeDesign->save();

        return $theme;
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function createLibraryTheme(string $name, array $design, string $source = 'zip', ?int $fromStoreId = null): Theme
    {
        $theme = Theme::create([
            'name' => $name,
            'slug' => $this->uniqueThemeSlug($name),
            'source' => $source,
            'design' => [],
            'created_from_store_id' => $fromStoreId,
        ]);

        $rewritten = $this->copyDesignAssets($design, null, 'themes/'.$theme->id);
        $theme->design = $rewritten;
        $theme->save();

        return $theme;
    }

    /**
     * Asigna una plantilla global a la tienda como copia editable.
     * Nunca muta ni borra el Theme. Si ya hay copia de esa plantilla, la reutiliza (no duplica).
     * Una sola queda activa: las demás copias de la tienda se pueden eliminar, la global no.
     */
    public function applyThemeToStore(Theme $theme, Store $store, ?string $name = null, bool $activate = true): StoreDesign
    {
        $existing = StoreDesign::query()
            ->where('store_id', $store->id)
            ->where('theme_id', $theme->id)
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existingDesign = is_array($existing->design) ? $existing->design : [];
            $pageCount = is_array($existingDesign['pages'] ?? null) ? count($existingDesign['pages']) : 0;
            $hasHtml = false;
            foreach (($existingDesign['pages'] ?? []) as $page) {
                if (is_array($page) && trim((string) ($page['html'] ?? '')) !== '') {
                    $hasHtml = true;
                    break;
                }
            }
            // Copia vacía o sin HTML: restablecer desde la plantilla global
            if ($pageCount === 0 || ! $hasHtml) {
                $design = $this->publishDesignForStore($this->copyDesignAssets(
                    is_array($theme->design) ? $theme->design : [],
                    'themes/'.$theme->id,
                    'store-designs/'.$existing->id
                ));
                $existing->design = $design;
                $existing->name = $name ?: ($existing->name ?: $theme->name);
                $existing->save();
                if ($activate) {
                    $this->activate($existing->fresh());
                }

                return $existing->fresh();
            }

            $payload = $this->publishDesignForStore($existingDesign);
            $existing->design = $payload;
            $existing->save();
            if ($activate) {
                $this->activate($existing->fresh());
            }

            return $existing->fresh();
        }

        $row = StoreDesign::create([
            'store_id' => $store->id,
            'theme_id' => $theme->id,
            'name' => $name ?: $theme->name,
            'is_active' => false,
            'design' => [],
        ]);

        $design = $this->copyDesignAssets(
            is_array($theme->design) ? $theme->design : [],
            'themes/'.$theme->id,
            'store-designs/'.$row->id
        );
        $design = $this->publishDesignForStore($design);
        $row->design = $design;
        $row->save();

        if ($activate) {
            $this->activate($row->fresh());
        }

        return $row->fresh();
    }

    /**
     * Al asignar una plantilla, el diseño queda usable en /s/{slug} (enabled + páginas live).
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function publishDesignForStore(array $design): array
    {
        $design['enabled'] = true;
        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            if (trim((string) ($page['html'] ?? '')) === '') {
                continue;
            }
            $pages[$i]['status'] = 'live';
        }
        $design['pages'] = $pages;

        return $design;
    }

    /**
     * Reemplaza la copia de la tienda con el contenido actual de la plantilla global.
     * La global no se toca.
     */
    public function resetStoreDesignFromTheme(StoreDesign $design): StoreDesign
    {
        $theme = $design->theme;
        if (! $theme) {
            abort(422, 'Esta copia no proviene de una plantilla de biblioteca. No hay global que restablecer.');
        }

        $dir = 'store-designs/'.$design->id;
        if (Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->deleteDirectory($dir);
        }

        $copied = $this->publishDesignForStore($this->copyDesignAssets(
            is_array($theme->design) ? $theme->design : [],
            'themes/'.$theme->id,
            $dir
        ));
        $design->design = $copied;
        $design->save();

        if ($design->is_active) {
            $this->activate($design->fresh());
        }

        return $design->fresh();
    }

    public function deleteStoreDesign(StoreDesign $design): void
    {
        if ($design->is_active) {
            abort(422, 'No puedes eliminar la plantilla asignada (activa). Asigna otra primero.');
        }

        $dir = 'store-designs/'.$design->id;
        if (Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->deleteDirectory($dir);
        }
        $design->delete();
    }

    /**
     * Copia archivos de assets y reescribe URLs en HTML/CSS.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public function copyDesignAssets(array $design, ?string $fromDir, string $toDir): array
    {
        $assets = is_array($design['assets'] ?? null) ? $design['assets'] : [];
        $urlMap = [];
        $newAssets = [];

        Storage::disk('public')->makeDirectory($toDir);

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $oldPath = (string) ($asset['path'] ?? '');
            $oldUrl = (string) ($asset['url'] ?? '');
            $name = (string) ($asset['name'] ?? basename($oldPath) ?: 'asset');
            $ext = strtolower(pathinfo($oldPath !== '' ? $oldPath : $name, PATHINFO_EXTENSION) ?: 'bin');
            $filename = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'asset';
            $filename .= '-'.Str::lower(Str::random(6)).'.'.$ext;
            $newPath = $toDir.'/'.$filename;

            $copied = false;
            $fullOld = $oldPath !== '' ? storage_path('app/public/'.ltrim(str_replace('\\', '/', $oldPath), '/')) : '';
            $fullNew = storage_path('app/public/'.ltrim(str_replace('\\', '/', $newPath), '/'));
            \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($fullNew));

            if ($fullOld !== '' && is_file($fullOld)) {
                \Illuminate\Support\Facades\File::copy($fullOld, $fullNew);
                $copied = true;
            } elseif ($oldPath !== '' && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->copy($oldPath, $newPath);
                $copied = true;
            }

            if (! $copied && $oldUrl !== '') {
                $asset['url'] = DesignAssetUrl::localize($oldUrl);
                $newAssets[] = $asset;

                continue;
            }

            if (! $copied) {
                continue;
            }

            $newUrl = DesignAssetUrl::fromPath($newPath);
            if ($oldUrl !== '') {
                $urlMap[$oldUrl] = $newUrl;
            }
            $newAssets[] = array_merge($asset, [
                'id' => (string) Str::uuid(),
                'path' => $newPath,
                'url' => $newUrl,
            ]);
        }

        $design['assets'] = $newAssets;
        $design['global_css'] = $this->rewriteUrls((string) ($design['global_css'] ?? ''), $urlMap);
        $design['modules_css'] = $this->rewriteUrls((string) ($design['modules_css'] ?? ''), $urlMap);
        $design['mobile_css'] = $this->rewriteUrls((string) ($design['mobile_css'] ?? ''), $urlMap);
        $design['global_js'] = $this->rewriteUrls((string) ($design['global_js'] ?? ''), $urlMap);

        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $pages[$i]['html'] = $this->rewriteUrls((string) ($page['html'] ?? ''), $urlMap);
            $pages[$i]['css'] = $this->rewriteUrls((string) ($page['css'] ?? ''), $urlMap);
            $pages[$i]['js'] = $this->rewriteUrls((string) ($page['js'] ?? ''), $urlMap);
        }
        $design['pages'] = $pages;

        return $design;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    public function rewriteUrls(string $content, array $urlMap): string
    {
        if ($content === '' || $urlMap === []) {
            return $content;
        }

        return strtr($content, $urlMap);
    }

    /**
     * @param  array<string, mixed>  $design
     */
    public function saveTheme(Theme $theme, array $design): void
    {
        $theme->design = $this->themes->normalizeDesign($design, $theme->name);
        $theme->save();
    }

    /**
     * Elimina una plantilla global de la biblioteca de plataforma.
     * Las copias ya asignadas a tiendas se conservan (theme_id queda null).
     */
    public function deleteTheme(Theme $theme): void
    {
        $dir = 'themes/'.$theme->id;
        if (Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->deleteDirectory($dir);
        }
        $theme->delete();
    }

    public function uniqueThemeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $i = 2;
        while (Theme::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
