<?php

namespace App\Http\Controllers;

use App\Services\Storage\R2StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaFileController extends Controller
{
    public function show(Request $request, string $path, R2StorageManager $r2): StreamedResponse|\Illuminate\Http\Response
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }

        if (! $r2->enabled() || ! $r2->disk()->exists($path)) {
            abort(404);
        }

        $mime = $this->mimeFromPath($path);
        $size = (int) $r2->disk()->size($path);
        $stream = $r2->disk()->readStream($path);
        if (! is_resource($stream)) {
            abort(404);
        }

        $response = Response::stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($request->boolean('download')) {
            $response->headers->set('Content-Disposition', 'attachment; filename="'.basename($path).'"');
        }

        return $response;
    }

    protected function mimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            default => 'application/octet-stream',
        };
    }
}
