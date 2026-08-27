<?php

namespace App\Services\Storefront;

use App\Models\Store;
use App\Models\StoreDesign;
use App\Models\Theme;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DesignZipImporter
{
    public function __construct(
        protected DesignThemeService $themes,
        protected ThemeLibraryService $library
    ) {}

    /**
     * @param  array{
     *   name?: string,
     *   save_to_library?: bool,
     *   library_name?: string,
     *   activate?: bool
     * }  $options
     * @return array{success: bool, message?: string, error?: string, pages?: int, assets?: int, globals?: array{css: bool, js: bool}, store_design_id?: int, theme_id?: int}
     */
    public function import(Store $store, UploadedFile $zipFile, array $options = []): array
    {
        if (! class_exists(ZipArchive::class)) {
            return ['success' => false, 'error' => 'La extensión PHP zip no está habilitada.'];
        }

        $tmpRoot = storage_path('app/tmp/design-zip-'.$store->id.'-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($tmpRoot);
        $zipPath = $tmpRoot.DIRECTORY_SEPARATOR.'upload.zip';

        try {
            // Copiar en vez de move(): más fiable con el tmp de PHP/Laravel
            if (! @copy($zipFile->getRealPath(), $zipPath)) {
                $zipFile->storeAs(
                    'tmp/'.basename($tmpRoot),
                    'upload.zip',
                    'local'
                );
                $zipPath = storage_path('app/tmp/'.basename($tmpRoot).'/upload.zip');
            }

            if (! is_file($zipPath)) {
                return ['success' => false, 'error' => 'No se pudo guardar el ZIP temporal.'];
            }

            $zip = new ZipArchive;
            $openCode = $zip->open($zipPath);
            if ($openCode !== true) {
                return ['success' => false, 'error' => 'No se pudo abrir el ZIP (código '.$openCode.').'];
            }

            // Seguridad básica: evitar path traversal
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $name);
                if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
                    $zip->close();

                    return ['success' => false, 'error' => 'ZIP inválido (rutas no permitidas).'];
                }
            }

            $zip->extractTo($tmpRoot.'/extracted');
            $zip->close();

            $extractRoot = $this->resolveContentRoot($tmpRoot.'/extracted');
            $name = trim((string) ($options['name'] ?? '')) ?: ($zipFile->getClientOriginalName() ?: 'Theme ZIP');
            $name = preg_replace('/\.zip$/i', '', $name) ?: 'Theme ZIP';

            $row = $this->library->createStoreDesign($store, $name, [], null, false);
            $parsed = $this->parseExtractedZip($extractRoot, 'store-designs/'.$row->id);
            $design = $parsed['design'];
            $pagesTouched = $parsed['pages'];
            $assetsTouched = $parsed['assets'];
            $globals = $parsed['globals'];

            if ($pagesTouched === 0 && ! $globals['css'] && ! $globals['js'] && $assetsTouched === 0) {
                $row->delete();

                return ['success' => false, 'error' => 'El ZIP no contenía HTML/CSS/JS/assets reconocibles.'];
            }

            $row->design = $design;
            $row->save();

            $themeId = null;
            if (! empty($options['save_to_library'])) {
                $libraryName = trim((string) ($options['library_name'] ?? $name)) ?: $name;
                $theme = $this->library->saveAsLibrary($row->fresh(), $libraryName);
                $themeId = $theme->id;
            }

            $activate = array_key_exists('activate', $options)
                ? (bool) $options['activate']
                : true;
            if ($activate || ! StoreDesign::query()->where('store_id', $store->id)->where('is_active', true)->where('id', '!=', $row->id)->exists()) {
                $this->library->activate($row->fresh());
            }

            $bits = [];
            if ($pagesTouched) {
                $bits[] = "{$pagesTouched} página(s)";
            }
            if ($assetsTouched) {
                $bits[] = "{$assetsTouched} asset(s)";
            }
            if ($globals['css']) {
                $bits[] = 'CSS global';
            }
            if ($globals['js']) {
                $bits[] = 'JS global';
            }
            if ($themeId) {
                $bits[] = 'guardado en biblioteca';
            }

            return [
                'success' => true,
                'message' => 'ZIP importado: '.implode(', ', $bits).'. Revisa y marca páginas como live.',
                'pages' => $pagesTouched,
                'assets' => $assetsTouched,
                'globals' => $globals,
                'store_design_id' => $row->id,
                'theme_id' => $themeId,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al importar ZIP: '.$e->getMessage()];
        } finally {
            if (is_dir($tmpRoot)) {
                File::deleteDirectory($tmpRoot);
            }
        }
    }

    /**
     * Importa un ZIP directo a la biblioteca de plataforma (sin tienda).
     *
     * @param  array{name?: string, description?: string}  $options
     * @return array{success: bool, message?: string, error?: string, pages?: int, assets?: int, globals?: array{css: bool, js: bool}, theme_id?: int}
     */
    public function importToLibrary(UploadedFile $zipFile, array $options = []): array
    {
        if (! class_exists(ZipArchive::class)) {
            return ['success' => false, 'error' => 'La extensión PHP zip no está habilitada.'];
        }

        $tmpRoot = storage_path('app/tmp/theme-lib-zip-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($tmpRoot);
        $zipPath = $tmpRoot.DIRECTORY_SEPARATOR.'upload.zip';

        try {
            if (! @copy($zipFile->getRealPath(), $zipPath)) {
                $zipFile->storeAs('tmp/'.basename($tmpRoot), 'upload.zip', 'local');
                $zipPath = storage_path('app/tmp/'.basename($tmpRoot).'/upload.zip');
            }
            if (! is_file($zipPath)) {
                return ['success' => false, 'error' => 'No se pudo guardar el ZIP temporal.'];
            }

            $zip = new ZipArchive;
            $openCode = $zip->open($zipPath);
            if ($openCode !== true) {
                return ['success' => false, 'error' => 'No se pudo abrir el ZIP (código '.$openCode.').'];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $name);
                if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
                    $zip->close();

                    return ['success' => false, 'error' => 'ZIP inválido (rutas no permitidas).'];
                }
            }
            $zip->extractTo($tmpRoot.'/extracted');
            $zip->close();

            $extractRoot = $this->resolveContentRoot($tmpRoot.'/extracted');
            $name = trim((string) ($options['name'] ?? '')) ?: ($zipFile->getClientOriginalName() ?: 'Plantilla');
            $name = preg_replace('/\.zip$/i', '', $name) ?: 'Plantilla';

            $theme = Theme::create([
                'name' => $name,
                'slug' => $this->library->uniqueThemeSlug($name),
                'description' => isset($options['description']) ? trim((string) $options['description']) ?: null : null,
                'source' => 'zip',
                'design' => $this->themes->defaults(),
            ]);
            Storage::disk('public')->makeDirectory('themes/'.$theme->id);

            $parsed = $this->parseExtractedZip($extractRoot, 'themes/'.$theme->id);
            if ($parsed['pages'] === 0 && ! $parsed['globals']['css'] && ! $parsed['globals']['js'] && $parsed['assets'] === 0) {
                Storage::disk('public')->deleteDirectory('themes/'.$theme->id);
                $theme->delete();

                return ['success' => false, 'error' => 'El ZIP no contenía HTML/CSS/JS/assets reconocibles.'];
            }

            $theme->design = $parsed['design'];
            $theme->save();

            $bits = [];
            if ($parsed['pages']) {
                $bits[] = $parsed['pages'].' página(s)';
            }
            if ($parsed['assets']) {
                $bits[] = $parsed['assets'].' asset(s)';
            }
            if ($parsed['globals']['css']) {
                $bits[] = 'CSS global';
            }
            if ($parsed['globals']['js']) {
                $bits[] = 'JS global';
            }

            return [
                'success' => true,
                'message' => 'Plantilla «'.$theme->name.'» importada: '.implode(', ', $bits).'.',
                'pages' => $parsed['pages'],
                'assets' => $parsed['assets'],
                'globals' => $parsed['globals'],
                'theme_id' => $theme->id,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error al importar ZIP: '.$e->getMessage()];
        } finally {
            if (is_dir($tmpRoot)) {
                File::deleteDirectory($tmpRoot);
            }
        }
    }

    /**
     * @return array{design: array<string, mixed>, pages: int, assets: int, globals: array{css: bool, js: bool}}
     */
    public function parseExtractedZip(string $extractRoot, string $assetDir): array
    {
        $design = $this->themes->defaults();
        $design['pages'] = [];
        $design['assets'] = [];
        $design['enabled'] = false;

            $pagesTouched = 0;
            $assetsTouched = 0;
            $globals = ['css' => false, 'js' => false];
            $urlMap = [];

            foreach ($this->iterateFiles($extractRoot) as $file) {
                $rel = $this->relPath($extractRoot, $file);
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (! $this->isBinaryAssetExt($ext)) {
                    continue;
                }
            $stored = $this->storeAssetFile($assetDir, $file, $rel);
                if ($stored) {
                    $design['assets'][] = $stored;
                    $assetsTouched++;
                $this->registerUrlAliases($urlMap, $rel, $stored['url']);
            }
        }

            foreach ($this->findFiles($extractRoot, ['theme.css', 'global.css', 'styles.css']) as $file) {
                $design['global_css'] = $this->rewriteCssUrls(File::get($file), $urlMap);
                $globals['css'] = true;
                break;
            }
            foreach ($this->findFiles($extractRoot, ['theme.js', 'global.js', 'main.js']) as $file) {
            $js = File::get($file);
            if ($this->jsLooksLikeCartHijack($js)) {
                $js = "/* theme.js recortado: el carrito y los grids los renderiza Multidrop */\n";
            }
            $design['global_js'] = $js;
                $globals['js'] = true;
                break;
        }
        foreach ($this->findFiles($extractRoot, ['modules.css', 'module.css']) as $file) {
            $design['modules_css'] = $this->rewriteCssUrls(File::get($file), $urlMap);
            break;
        }
        foreach ($this->findFiles($extractRoot, ['mobile.css', 'axiom-mobile.css']) as $file) {
            $design['mobile_css'] = $this->rewriteCssUrls(File::get($file), $urlMap);
            break;
        }

        $layoutMap = [];
        foreach ($this->findFiles($extractRoot, ['layout.json']) as $file) {
            $decoded = json_decode((string) File::get($file), true);
            if (is_array($decoded)) {
                $layoutMap = is_array($decoded['pages'] ?? null) ? $decoded['pages'] : $decoded;
            }
            break;
        }

            $htmlFiles = [];
            foreach ($this->iterateFiles($extractRoot) as $file) {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'html') {
                    continue;
                }
                $htmlFiles[] = $file;
            }

            foreach ($htmlFiles as $htmlFile) {
                $base = pathinfo($htmlFile, PATHINFO_FILENAME);
                $handle = Str::slug($base) ?: 'page';
                if (in_array(strtolower($base), ['index', 'home', 'inicio'], true)) {
                    $handle = 'index';
                }
                $type = $this->typeForHandle($handle);
            if (in_array($handle, ['theme', 'global'], true)) {
                continue;
            }

                $html = File::get($htmlFile);
            if ($handle === 'page' && str_contains($html, '{{page.')) {
                continue;
            }
                $html = $this->rewriteAssetUrls($html, $urlMap, $htmlFile, $extractRoot);
                $html = $this->themes->extractBodyHtml($html);
            $html = $this->rewriteAssetUrls($html, $urlMap, $htmlFile, $extractRoot);

                $dir = dirname($htmlFile);
                $pageCss = '';
                $pageJs = '';
                foreach (["{$dir}/{$base}.css", "{$dir}/{$handle}.css"] as $candidate) {
                    if (is_file($candidate)) {
                        $pageCss = $this->rewriteCssUrls(File::get($candidate), $urlMap);
                        break;
                    }
                }
                foreach (["{$dir}/{$base}.js", "{$dir}/{$handle}.js"] as $candidate) {
                    if (is_file($candidate)) {
                        $pageJs = File::get($candidate);
                        break;
                    }
                }

                $existingIdx = null;
                foreach ($design['pages'] as $i => $p) {
                    if (($p['handle'] ?? '') === $handle || (($type !== 'page') && ($p['type'] ?? '') === $type && in_array($type, ['landing', 'catalog', 'product', 'cart', 'checkout'], true))) {
                        $existingIdx = $i;
                        break;
                    }
                }

                $title = $this->titleFromHtml($html) ?: (DesignThemeService::PAGE_TYPES[$type] ?? Str::headline($handle));
            $modules = $this->modulesFromLayoutMap($layoutMap, $handle, $type);
                $page = $this->themes->makePage([
                    'id' => $existingIdx !== null ? ($design['pages'][$existingIdx]['id'] ?? null) : null,
                    'type' => $type,
                    'handle' => $handle,
                    'title' => $title,
                'html' => $type === 'page' ? $html : '',
                    'css' => $pageCss,
                'js' => $this->jsLooksLikeCartHijack($pageJs) ? '' : $pageJs,
                'modules' => $modules,
                    'status' => $existingIdx !== null
                        ? ($design['pages'][$existingIdx]['status'] ?? 'draft')
                        : 'draft',
                ]);

                if ($existingIdx !== null) {
                    $design['pages'][$existingIdx] = $page;
                } else {
                    $design['pages'][] = $page;
                }
                $pagesTouched++;
            }

        foreach ($this->iterateFiles($extractRoot) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (! in_array($ext, ['twig', 'md'], true)) {
                continue;
            }
            $base = pathinfo($file, PATHINFO_FILENAME);
            $handle = Str::slug($base) ?: 'page';
            if (in_array($handle, ['index', 'catalog', 'product', 'cart', 'checkout'], true)) {
                continue;
            }
            $html = File::get($file);
            $existingIdx = null;
            foreach ($design['pages'] as $i => $p) {
                if (($p['handle'] ?? '') === $handle) {
                    $existingIdx = $i;
                    break;
                }
            }
            $page = $this->themes->makePage([
                'id' => $existingIdx !== null ? ($design['pages'][$existingIdx]['id'] ?? null) : null,
                'type' => 'page',
                'handle' => $handle,
                'title' => Str::headline($handle),
                'html' => $html,
                'modules' => $this->modulesFromLayoutMap($layoutMap, $handle, 'page'),
                'status' => 'live',
            ]);
            if ($existingIdx !== null) {
                $design['pages'][$existingIdx] = $page;
            } else {
                $design['pages'][] = $page;
            }
            $pagesTouched++;
        }

        foreach ($layoutMap as $handle => $mods) {
            if (! is_string($handle) || $handle === '') {
                continue;
            }
            $handle = Str::slug($handle) ?: $handle;
            $exists = false;
            foreach ($design['pages'] as $p) {
                if (($p['handle'] ?? '') === $handle) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }
            $type = $this->typeForHandle($handle);
            $design['pages'][] = $this->themes->makePage([
                'type' => $type,
                'handle' => $handle === 'home' ? 'index' : $handle,
                'title' => DesignThemeService::PAGE_TYPES[$type] ?? Str::headline($handle),
                'html' => '',
                'modules' => $this->modulesFromLayoutMap($layoutMap, $handle, $type),
                'status' => 'live',
            ]);
            $pagesTouched++;
        }

        if ($pagesTouched === 0) {
            foreach (['landing', 'catalog', 'product', 'cart', 'checkout'] as $type) {
                $handle = $type === 'landing' ? 'index' : $type;
                $design['pages'][] = $this->themes->makePage([
                    'type' => $type,
                    'handle' => $handle,
                    'title' => DesignThemeService::PAGE_TYPES[$type],
                    'html' => '',
                    'modules' => app(\App\Services\Storefront\Modules\ModuleRegistry::class)->defaultLayout($type),
                    'status' => 'live',
                ]);
                $pagesTouched++;
            }
        }

            foreach ($this->iterateFiles($extractRoot) as $file) {
                $rel = $this->relPath($extractRoot, $file);
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $base = strtolower(pathinfo($file, PATHINFO_FILENAME));
                if (! in_array($ext, ['css', 'js'], true)) {
                    continue;
                }
                if (in_array($base, ['theme', 'global', 'styles', 'main'], true)) {
                    continue;
                }
                $siblingHtml = dirname($file).DIRECTORY_SEPARATOR.pathinfo($file, PATHINFO_FILENAME).'.html';
                if (is_file($siblingHtml)) {
                    continue;
                }
            $relFwd = str_replace('\\', '/', strtolower($rel));
            $inAssetFolder = (bool) preg_match('#(^|/)(assets|images|img|media|static|fonts|css|js)/#', $relFwd);
            if (! $inAssetFolder) {
                    continue;
                }
            $stored = $this->storeAssetFile($assetDir, $file, $rel);
                if ($stored) {
                    $design['assets'][] = $stored;
                    $assetsTouched++;
                $this->registerUrlAliases($urlMap, $rel, $stored['url']);
            }
        }

        $design['global_css'] = $this->rewriteCssUrls((string) ($design['global_css'] ?? ''), $urlMap);
        foreach ($design['pages'] as $i => $page) {
            if (! is_array($page)) {
                continue;
            }
            $design['pages'][$i]['html'] = $this->rewriteAssetUrls((string) ($page['html'] ?? ''), $urlMap, '', $extractRoot);
            $design['pages'][$i]['css'] = $this->rewriteCssUrls((string) ($page['css'] ?? ''), $urlMap);
            }

            return [
            'design' => $design,
                'pages' => $pagesTouched,
                'assets' => $assetsTouched,
                'globals' => $globals,
            ];
    }

    protected function resolveContentRoot(string $extracted): string
    {
        if (! is_dir($extracted)) {
            return $extracted;
        }
        $entries = array_values(array_filter(scandir($extracted) ?: [], fn ($e) => $e !== '.' && $e !== '..'));
        // Si el ZIP trae una sola carpeta raíz, entrar
        if (count($entries) === 1 && is_dir($extracted.DIRECTORY_SEPARATOR.$entries[0])) {
            return $extracted.DIRECTORY_SEPARATOR.$entries[0];
        }

        return $extracted;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    protected function findFiles(string $root, array $names): array
    {
        $out = [];
        $want = array_map('strtolower', $names);
        foreach ($this->iterateFiles($root) as $file) {
            if (in_array(strtolower(basename($file)), $want, true)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * @return \Generator<int, string>
     */
    protected function iterateFiles(string $root): \Generator
    {
        if (! is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    protected function relPath(string $root, string $file): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, $root)) {
            return substr($file, strlen($root));
        }

        return basename($file);
    }

    protected function typeForHandle(string $handle): string
    {
        return match ($handle) {
            'index', 'home', 'inicio' => 'landing',
            'catalog', 'catalogue', 'shop', 'tienda', 'products' => 'catalog',
            'product', 'pdp', 'item' => 'product',
            'cart', 'carrito', 'basket' => 'cart',
            'checkout', 'pago' => 'checkout',
            default => 'page',
        };
    }

    protected function titleFromHtml(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '' && ! str_contains($t, '{{') && ! str_contains($t, '{%')) {
                return mb_substr($t, 0, 120);
            }
        }
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '' && ! str_contains($t, '{{') && ! str_contains($t, '{%')) {
                return mb_substr($t, 0, 120);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    protected function registerUrlAliases(array &$urlMap, string $rel, string $url): void
    {
        $rel = str_replace('\\', '/', $rel);
        $base = basename($rel);
        $aliases = [
            $rel,
            ltrim($rel, './'),
            ltrim($rel, '/'),
            $base,
            'assets/'.$base,
            'images/'.$base,
            'img/'.$base,
            'fonts/'.$base,
            './'.$rel,
            '/'.$rel,
        ];
        $parts = explode('/', $rel);
        if (count($parts) >= 2) {
            $aliases[] = $parts[count($parts) - 2].'/'.$base;
        }
        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $urlMap[$alias] = $url;
            }
        }
    }

    protected function isBinaryAssetExt(string $ext): bool
    {
        return in_array($ext, [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'mp4', 'webm', 'mov',
        ], true);
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    protected function lookupMappedUrl(string $raw, array $urlMap, string $htmlFile = '', string $extractRoot = ''): ?string
    {
        if ($raw === '' || preg_match('{^(?:https?:)?//|^data:|^#|^mailto:|^tel:}i', $raw)) {
            return null;
        }
        $clean = strtok($raw, '?#') ?: $raw;
        $clean = str_replace('\\', '/', trim($clean));
        $candidates = [
            $clean,
            ltrim($clean, './'),
            ltrim($clean, '/'),
            basename($clean),
            'assets/'.basename($clean),
            'images/'.basename($clean),
            'img/'.basename($clean),
            'fonts/'.basename($clean),
        ];
        foreach ($candidates as $c) {
            if ($c !== '' && isset($urlMap[$c])) {
                return $urlMap[$c];
            }
        }
        if ($htmlFile !== '' && $extractRoot !== '' && is_file($htmlFile)) {
            $abs = realpath(dirname($htmlFile).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($clean, './')));
            if ($abs && is_file($abs)) {
                $rel = str_replace('\\', '/', $this->relPath($extractRoot, $abs));
                if (isset($urlMap[$rel])) {
                    return $urlMap[$rel];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    protected function rewriteCssUrls(string $css, array $urlMap): string
    {
        if ($urlMap === [] || trim($css) === '') {
            return $css;
        }

        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function ($m) use ($urlMap) {
                $mapped = $this->lookupMappedUrl(trim($m[2]), $urlMap);

                return $mapped ? 'url("'.$mapped.'")' : $m[0];
            },
            $css
        );
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    protected function rewriteAssetUrls(string $html, array $urlMap, string $htmlFile, string $extractRoot): string
    {
        if ($urlMap === [] || $html === '') {
            return $html;
        }

        $html = (string) preg_replace_callback(
            '/\b(src|href|poster)=["\']([^"\']+)["\']/i',
            function ($m) use ($urlMap, $htmlFile, $extractRoot) {
                $mapped = $this->lookupMappedUrl($m[2], $urlMap, $htmlFile, $extractRoot);

                return $mapped ? $m[1].'="'.e($mapped).'"' : $m[0];
            },
            $html
        );

        $html = (string) preg_replace_callback(
            '/\bsrcset=["\']([^"\']+)["\']/i',
            function ($m) use ($urlMap, $htmlFile, $extractRoot) {
                $parts = preg_split('/\s*,\s*/', $m[1]) ?: [];
                $out = [];
                foreach ($parts as $part) {
                    $bit = preg_split('/\s+/', trim($part), 2) ?: [];
                    $u = $bit[0] ?? '';
                    $desc = $bit[1] ?? '';
                    $mapped = $this->lookupMappedUrl($u, $urlMap, $htmlFile, $extractRoot);
                    $out[] = trim(($mapped ?: $u).($desc !== '' ? ' '.$desc : ''));
                }

                return 'srcset="'.e(implode(', ', $out)).'"';
            },
            $html
        );

        return $this->rewriteCssUrls($html, $urlMap);
    }

    /**
     * @param  array<string, mixed>  $layoutMap
     * @return list<array{key: string, desktop: bool, mobile: bool}|string>
     */
    protected function modulesFromLayoutMap(array $layoutMap, string $handle, string $type): array
    {
        $raw = $layoutMap[$handle] ?? $layoutMap[$type] ?? null;
        if (is_array($raw) && isset($raw['modules']) && is_array($raw['modules'])) {
            $raw = $raw['modules'];
        }
        $registry = app(\App\Services\Storefront\Modules\ModuleRegistry::class);
        if (is_array($raw) && $raw !== [] && array_is_list($raw)) {
            $entries = $registry->normalizeEntries($raw);

            return $entries !== [] ? $entries : $registry->defaultLayout($type);
        }

        return $registry->defaultLayout($type);
    }

    protected function jsLooksLikeCartHijack(string $js): bool
    {
        if (trim($js) === '') {
            return false;
        }

        return (bool) preg_match('/\b(renderGrids|bindAddToCart|renderSummary|localStorage\s*\.\s*(setItem|getItem).*cart|MD\.Cart\s*=|Multidrop\.Cart\s*=)/i', $js)
            || str_contains($js, 'md-checkout-summary__line-qty');
    }

    /**
     * @return array{id: string, name: string, path: string, url: string, mime: ?string, size: int, uploaded_at: string}|null
     */
    protected function storeAssetFile(string $dir, string $absolutePath, string $rel): ?array
    {
        if (! is_file($absolutePath)) {
            return null;
        }
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'bin');
        $name = Str::slug(pathinfo($absolutePath, PATHINFO_FILENAME)) ?: 'asset';
        $sub = trim(str_replace('\\', '/', dirname($rel)), '.');
        $sub = $sub === '' || $sub === '/' ? '' : Str::slug(str_replace('/', '-', $sub));
        $filename = ($sub !== '' ? $sub.'-' : '').$name.'-'.Str::lower(Str::random(6)).'.'.$ext;
        $path = rtrim($dir, '/').'/'.$filename;
        File::ensureDirectoryExists(storage_path('app/public/'.dirname($path)));
        Storage::disk('public')->put($path, File::get($absolutePath));

        return [
            'id' => (string) Str::uuid(),
            'name' => basename($rel) ?: basename($absolutePath),
            'path' => $path,
            'url' => DesignAssetUrl::fromPath($path),
            'mime' => File::mimeType($absolutePath) ?: null,
            'size' => (int) filesize($absolutePath),
            'uploaded_at' => now()->toIso8601String(),
        ];
    }
}
