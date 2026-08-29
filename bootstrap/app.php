<?php

use App\Http\Middleware\CloudflareAccess;
use App\Http\Middleware\EnsureAdminActive;
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
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cloudflare Tunnel / proxy termina TLS; Apache ve HTTP en 127.0.0.1
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'cloudflare.access' => CloudflareAccess::class,
            'admin.active' => EnsureAdminActive::class,
            'permission' => EnsurePermission::class,
            'admin.store' => ShareAdminStoreContext::class,
            'store.service' => EnsureStoreService::class,
            'store.plugin' => EnsureStorePlugin::class,
            'sandbox.buyer' => EnsureSandboxBuyer::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'admin/lab/cj/plugin-capture',
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
        //
    })->create();
