<?php

use App\Http\Middleware\CloudflareAccess;
use App\Http\Middleware\EnsureAdminActive;
use App\Http\Middleware\EnsureApiToken;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSandboxBuyer;
use App\Http\Middleware\EnsureStorePlugin;
use App\Http\Middleware\EnsureStoreService;
use App\Http\Middleware\ShareAdminStoreContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cloudflare Tunnel / proxy termina TLS; Apache ve HTTP en 127.0.0.1
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'cloudflare.access' => CloudflareAccess::class,
            'admin.active' => EnsureAdminActive::class,
            'api.token' => EnsureApiToken::class,
            'permission' => EnsurePermission::class,
            'admin.store' => ShareAdminStoreContext::class,
            'store.service' => EnsureStoreService::class,
            'store.plugin' => EnsureStorePlugin::class,
            'sandbox.buyer' => EnsureSandboxBuyer::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'admin/lab/cj/plugin-capture',
            'admin/lab/cj/plugin-extract',
            'admin/lab/cj/plugin-import-image',
            'admin/lab/cj/plugin-product-search',
            'admin/lab/cj/plugin-bootstrap',
        ]);

        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('cuenta') || $request->is('cuenta/*')) {
                return route('buyer.login');
            }

            return route('admin.login');
        });
        $middleware->redirectUsersTo(function ($request) {
            if ($request->is('cuenta') || $request->is('cuenta/*') || auth()->guard('buyer')->check()) {
                return route('buyer.dashboard');
            }

            return route('admin.dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recurso no encontrado.',
                ], 404);
            }

            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if ($status === 500) {
                return null;
            }

            $message = match (true) {
                $status === 403 => 'Acción no permitida.',
                $status === 404 => 'Recurso no encontrado.',
                $status === 409 => 'Conflicto con el estado actual del recurso.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Error en la petición.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        });
    })->create();
