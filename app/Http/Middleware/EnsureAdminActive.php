<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->withErrors(['email' => 'Tu cuenta está desactivada o no existe.']);
        }

        if ($user->must_change_password
            && ! $request->routeIs('admin.profile.*')
            && ! $request->routeIs('admin.logout')
        ) {
            return redirect()
                ->route('admin.profile.edit')
                ->with('warning', 'Debes actualizar tu contraseña antes de continuar.');
        }

        return $next($request);
    }
}
