<?php

namespace App\Services\Storefront;

use App\Models\Theme;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DesignZipExporter
{
    public function __construct(
        protected DesignThemeService $themes
    ) {}

    /**
     * Genera un ZIP compatible con DesignZipImporter.
     *
     * @return string|null Ruta absoluta al archivo temporal
     */
    public function exportTheme(Theme $theme): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $design = $this->themes->forDisplay(
            $this->themes->normalizeDesign(
                is_array($theme->design) ? $theme->design : [],
                $theme->name
            )
        );

        return $this->buildZip($design, (string) $theme->slug);
    }

    /**
     * @param  array<string, mixed>  $design
     */
    protected function buildZip(array $design, string $folder): ?string
    {
        $folder = Str::slug($folder) ?: 'plantilla';
        $tmpZip = storage_path('app/tmp/theme-export-'.Str::lower(Str::random(10)).'.zip');

        File::ensureDirectoryExists(dirname($tmpZip));

        $zip = new ZipArchive;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $urlMap = [];
        $filesAdded = 0;
        $usedAssetNames = [];

        $assets = is_array($design['assets'] ?? null) ? $design['assets'] : [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $path = trim((string) ($asset['path'] ?? ''));
            if ($path === '' || ! Storage::disk('public')->exists($path)) {
                continue;
            }
            $basename = $this->uniqueAssetName(
                $usedAssetNames,
                $this->safeAssetName((string) ($asset['name'] ?? basename($path)), $path)
            );
            $rel = 'assets/'.$basename;
            $zip->addFile(Storage::disk('public')->path($path), $folder.'/'.$rel);
            $filesAdded++;
            $this->registerAssetAliases($urlMap, $asset, $rel);
        }

        $globalCss = $this->rewriteToRelative((string) ($design['global_css'] ?? ''), $urlMap);
        if (trim($globalCss) !== '') {
            $zip->addFromString($folder.'/theme.css', $globalCss);
            $filesAdded++;
        }

        $modulesCss = $this->rewriteToRelative((string) ($design['modules_css'] ?? ''), $urlMap);
        if (trim($modulesCss) !== '') {
            $zip->addFromString($folder.'/modules.css', $modulesCss);
            $filesAdded++;
        }

        $mobileCss = $this->rewriteToRelative((string) ($design['mobile_css'] ?? ''), $urlMap);
        if (trim($mobileCss) !== '') {
            $zip->addFromString($folder.'/mobile.css', $mobileCss);
            $filesAdded++;
        }

        $globalJs = (string) ($design['global_js'] ?? '');
        if (trim($globalJs) !== '') {
            $zip->addFromString($folder.'/theme.js', $globalJs);
            $filesAdded++;
        }

        $layout = $this->layoutFromDesign($design);
        if ($layout !== []) {
            $zip->addFromString(
                $folder.'/layout.json',
                json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            );
            $filesAdded++;
        }

        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $type = (string) ($page['type'] ?? 'page');
            $handle = Str::slug((string) ($page['handle'] ?? ''));
            if ($handle === '') {
                continue;
            }
            if ($type !== 'page') {
                continue;
            }
            $html = $this->rewriteToRelative((string) ($page['html'] ?? ''), $urlMap);
            if (trim($html) === '') {
                continue;
            }
            $zip->addFromString($folder.'/pages/'.$handle.'.twig', $html);
            $filesAdded++;
            $pageCss = $this->rewriteToRelative((string) ($page['css'] ?? ''), $urlMap);
            if (trim($pageCss) !== '') {
                $zip->addFromString($folder.'/pages/'.$handle.'.css', $pageCss);
                $filesAdded++;
            }
        }

        if ($filesAdded === 0) {
            $zip->addFromString(
                $folder.'/README.txt',
                "Plantilla exportada desde Multidrop.\nNo había CSS, assets ni páginas con contenido exportable.\n"
            );
            $filesAdded++;
        }

        $zip->close();

        return $filesAdded > 0 && is_file($tmpZip) ? $tmpZip : null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array<string, list<mixed>>
     */
    protected function layoutFromDesign(array $design): array
    {
        $layout = [];
        $pages = is_array($design['pages'] ?? null) ? $design['pages'] : [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $handle = Str::slug((string) ($page['handle'] ?? ''));
            $modules = $page['modules'] ?? null;
            if ($handle === '' || ! is_array($modules) || $modules === []) {
                continue;
            }
            $layout[$handle] = array_values($modules);
        }

        return $layout;
    }

    /**
     * @param  array<string, string>  $urlMap
     * @param  array<string, mixed>  $asset
     */
    protected function registerAssetAliases(array &$urlMap, array $asset, string $rel): void
    {
        $path = trim((string) ($asset['path'] ?? ''));
        $url = trim((string) ($asset['url'] ?? ''));
        $basename = basename($rel);

        $aliases = array_filter([
            $url,
            $path,
            '/storage/'.$path,
            'storage/'.$path,
            asset('storage/'.$path),
            url('storage/'.$path),
            '/'.$path,
            $basename,
            'assets/'.$basename,
        ]);

        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $urlMap[$alias] = $rel;
            }
        }
    }

    /**
     * @param  array<string, string>  $urlMap
     */
    protected function rewriteToRelative(string $content, array $urlMap): string
    {
        if ($content === '' || $urlMap === []) {
            return $content;
        }

        uksort($urlMap, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($urlMap as $from => $to) {
            $content = str_replace($from, $to, $content);
        }

        return $content;
    }

    protected function safeAssetName(string $name, string $storagePath): string
    {
        $name = trim($name) !== '' ? $name : basename($storagePath);
        $name = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: pathinfo($storagePath, PATHINFO_EXTENSION) ?: 'bin');
        $base = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'asset';

        return $base.'.'.$ext;
    }

    /**
     * @param  array<string, true>  $used
     */
    protected function uniqueAssetName(array &$used, string $name): string
    {
        if (! isset($used[$name])) {
            $used[$name] = true;

            return $name;
        }
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME) ?: 'asset';
        $i = 2;
        do {
            $candidate = $ext !== '' ? $base.'-'.$i.'.'.$ext : $base.'-'.$i;
            $i++;
        } while (isset($used[$candidate]));
        $used[$candidate] = true;

        return $candidate;
    }
}
