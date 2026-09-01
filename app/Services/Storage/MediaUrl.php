<?php

namespace App\Services\Storage;

/**
 * URLs públicas enmascaradas para media en R2 (/f/...).
 */
class MediaUrl
{
    public static function prefix(): string
    {
        $prefix = trim((string) config('r2.public_path_prefix', 'f'), '/');

        return $prefix !== '' ? $prefix : 'f';
    }

    public static function fromStoragePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        $relative = self::prefix().'/'.$path;

        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = request();
            if ($request->getHost() !== '') {
                return rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/').'/'.ltrim($relative, '/');
            }
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($relative, '/');
    }

    public static function isMaskedUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $prefix = '/'.self::prefix().'/';
        if (str_contains($url, $prefix)) {
            return true;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        return str_starts_with($path, $prefix);
    }

    public static function storagePathFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $path = ltrim($path, '/');
        $needle = self::prefix().'/';
        if (! str_starts_with($path, $needle)) {
            return null;
        }

        $storage = ltrim(substr($path, strlen($needle)), '/');

        return $storage !== '' ? $storage : null;
    }

    public static function localize(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $current = rtrim(self::baseUrl(), '/');
        $configured = rtrim((string) config('app.url'), '/').'/'.self::prefix();
        if ($configured !== '' && strcasecmp($configured, $current) !== 0) {
            $content = str_replace($configured, $current, $content);
        }

        $rewritten = preg_replace(
            '#https?://[^/"\'\\s)]+(?:/[^/"\'\\s)]+)*/'.preg_quote(self::prefix(), '#').'#i',
            $current,
            $content
        );

        return is_string($rewritten) ? $rewritten : $content;
    }

    public static function baseUrl(): string
    {
        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = request();
            if ($request->getHost() !== '') {
                return rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/').'/'.self::prefix();
            }
        }

        return rtrim((string) config('app.url'), '/').'/'.self::prefix();
    }
}
