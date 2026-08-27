<?php

namespace App\Http\Middleware;

use App\Services\Auth\CloudflareAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CloudflareAccess
{
    public function __construct(private CloudflareAccessService $cloudflare)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cloudflare->isEnabled()) {
            return $next($request);
        }

        $user = $this->cloudflare->resolveUser($request);

        if ($user) {
            if (! Auth::check() || Auth::id() !== $user->id) {
                Auth::login($user);
                $user->markLogin($request->ip());
            }

            return $next($request);
        }

        if ($this->cloudflare->isRequired()) {
            abort(403, 'Se requiere autenticación Cloudflare Access para el panel admin.');
        }

        return $next($request);
    }
}
