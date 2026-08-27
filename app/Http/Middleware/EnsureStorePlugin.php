<?php

namespace App\Http\Middleware;

use App\Services\Admin\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorePlugin
{
    public function __construct(private StoreContext $storeContext)
    {
    }

    public function handle(Request $request, Closure $next, string $plugin): Response
    {
        $store = $this->storeContext->current();
        abort_unless($store, 404);

        if (! $store->pluginEnabled($plugin)) {
            if ($request->routeIs('admin.*')) {
                return $next($request);
            }
            abort(404, 'Este plugin no está habilitado en la tienda.');
        }

        return $next($request);
    }
}
