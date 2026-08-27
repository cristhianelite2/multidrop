<?php

namespace App\Services\Combo;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class ComboPromoStyleLibrary
{
    /** @var list<string> */
    protected array $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    public function basePath(): string
    {
        return base_path('files/scripts');
    }

    /**
     * @return list<array{slug: string, label: string, template_count: int}>
     */
    public function listStyles(): array
    {
        $base = $this->basePath();
        if (! is_dir($base)) {
            return [];
        }

        $styles = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $base.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($path)) {
                continue;
            }
            if (! $this->isValidStyleSlug($entry)) {
                continue;
            }

            $styles[] = [
                'slug' => $entry,
                'label' => $this->styleLabel($entry),
                'template_count' => count($this->listTemplateFilenames($entry)),
            ];
        }

        usort($styles, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $styles;
    }

    /**
     * @return list<array{file: string, label: string, size: int, thumb_url: string}>
     */
    public function listTemplates(string $style): array
    {
        $style = $this->assertStyle($style);
        $dir = $this->styleDirectory($style);
        $out = [];

        foreach ($this->listTemplateFilenames($style) as $file) {
            $full = $dir.DIRECTORY_SEPARATOR.$file;
            if (! is_file($full)) {
                continue;
            }
            $out[] = [
                'file' => $file,
                'label' => pathinfo($file, PATHINFO_FILENAME),
                'size' => (int) filesize($full),
                'thumb_url' => route('admin.store.combos.promo-styles.thumb', [
                    'style' => $style,
                    'file' => $this->encodeThumbFilename($file),
                ]),
            ];
        }

        usort($out, fn (array $a, array $b) => strnatcasecmp($a['file'], $b['file']));

        return $out;
    }

    public function resolveTemplatePath(string $style, string $file): string
    {
        $style = $this->assertStyle($style);
        $file = $this->assertTemplateFilename($file);
        $path = $this->styleDirectory($style).DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            throw new FileException('Plantilla no encontrada.');
        }

        return $path;
    }

    public function encodeThumbFilename(string $file): string
    {
        return rtrim(strtr(base64_encode($file), '+/', '-_'), '=');
    }

    public function decodeThumbFilename(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode($padded, true);
        if ($decoded === false || $decoded === '') {
            throw new FileException('Nombre de plantilla inválido.');
        }

        return $this->assertTemplateFilename($decoded);
    }

    public function styleLabel(string $slug): string
    {
        return Str::title(str_replace('-', ' ', $slug));
    }

    protected function assertStyle(string $style): string
    {
        $style = trim($style);
        if (! $this->isValidStyleSlug($style)) {
            throw new FileException('Estilo inválido.');
        }

        $dir = $this->styleDirectory($style);
        if (! is_dir($dir)) {
            throw new FileException('Estilo no encontrado.');
        }

        return $style;
    }

    protected function isValidStyleSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    protected function styleDirectory(string $style): string
    {
        $dir = realpath($this->basePath().DIRECTORY_SEPARATOR.$style);
        $base = realpath($this->basePath());
        if ($dir === false || $base === false || ! str_starts_with($dir, $base)) {
            throw new FileException('Ruta de estilo inválida.');
        }

        return $dir;
    }

    protected function assertTemplateFilename(string $file): string
    {
        $file = trim(str_replace('\\', '/', $file));
        if ($file === '' || str_contains($file, '/') || str_contains($file, '..')) {
            throw new FileException('Nombre de plantilla inválido.');
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (! in_array($ext, $this->imageExtensions, true)) {
            throw new FileException('Extensión de plantilla no permitida.');
        }

        return $file;
    }

    /**
     * @return list<string>
     */
    protected function listTemplateFilenames(string $style): array
    {
        $dir = $this->styleDirectory($style);
        $files = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (in_array($ext, $this->imageExtensions, true)) {
                $files[] = $entry;
            }
        }

        return $files;
    }
}
