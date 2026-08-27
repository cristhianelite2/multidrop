<?php

namespace App\Services\Storefront;

/**
 * URLs de assets de diseño independientes de APP_URL.
 * Storage::url() usa APP_URL (XAMPP); el admin suele verse en artisan serve (:8003).
 */
class DesignAssetUrl
{
    public static function fromPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        $relative = str_starts_with($path, 'storage/') ? $path : 'storage/'.$path;

        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = request();
            if ($request->getHost() !== '') {
                return rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/').'/'.ltrim($relative, '/');
            }
        }

        return asset($relative);
    }

    public static function localize(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $current = rtrim(asset('storage'), '/');
        $configured = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($configured !== '' && strcasecmp($configured, $current) !== 0) {
            $content = str_replace($configured, $current, $content);
        }

        $rewritten = preg_replace(
            '#https?://[^/"\'\\s)]+(?:/[^/"\'\\s)]+)*/storage#i',
            $current,
            $content
        );

        return is_string($rewritten) ? $rewritten : $content;
    }
}
