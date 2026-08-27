<?php

namespace App\Http\Middleware;

use App\Services\Admin\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreService
{
    public function __construct(private StoreContext $storeContext)
    {
    }

    public function handle(Request $request, Closure $next, string $service = 'commerce'): Response
    {
        $store = $this->storeContext->current();
        abort_unless($store, 404);

        if (! $store->serviceEnabled($service)) {
            abort(404, 'Este servicio no está habilitado en la tienda.');
        }

        return $next($request);
    }
}
